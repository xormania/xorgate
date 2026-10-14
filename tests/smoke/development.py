#!/usr/bin/env python3
"""Observe opt-in development profiling, worker logs and Serena consumer readiness."""

import argparse
import json
import os
import queue
import re
import subprocess
import threading
import time
from pathlib import Path
from urllib.request import urlopen

from foundation import read_response, require


class StdioMcp:
    def __init__(self, command):
        self.process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.messages = queue.Queue()
        self.ident = 0
        threading.Thread(target=self._read, daemon=True).start()
        # Drain startup diagnostics without copying credentials or arbitrary tool output to CI.
        threading.Thread(target=self._drain_errors, daemon=True).start()

    def _read(self):
        while line := self.process.stdout.readline(131073):
            if len(line) > 131072:
                self.messages.put(RuntimeError("MCP response exceeded the smoke-client frame limit"))
                return
            try:
                self.messages.put(json.loads(line))
            except (ValueError, UnicodeError):
                self.messages.put(RuntimeError("MCP process emitted a non-JSON stdout frame"))
                return
        self.messages.put(RuntimeError("MCP process closed stdout before completing the exchange"))

    def _drain_errors(self):
        while self.process.stderr.read(4096):
            pass

    def request(self, method, parameters=None, notification=False, timeout=180):
        self.ident += 1
        packet = {"jsonrpc": "2.0", "method": method}
        if parameters is not None:
            packet["params"] = parameters
        if not notification:
            packet["id"] = self.ident
        self.process.stdin.write((json.dumps(packet) + "\n").encode())
        self.process.stdin.flush()
        if notification:
            return None
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            try:
                response = self.messages.get(timeout=min(1, max(0.01, deadline - time.monotonic())))
            except queue.Empty:
                continue
            if isinstance(response, Exception):
                raise response
            require(isinstance(response, dict), "MCP response is not an object")
            if response.get("id") != self.ident:
                continue
            require("error" not in response and "result" in response, f"MCP {method} failed its protocol exchange")
            return response["result"]
        raise RuntimeError(f"MCP {method} did not complete within its readiness deadline")

    def initialize(self):
        result = self.request("initialize", {"protocolVersion": "2025-06-18", "capabilities": {},
                              "clientInfo": {"name": "xorgate-development-smoke", "version": "1"}})
        require(result.get("protocolVersion") in {"2025-03-26", "2025-06-18", "2025-11-25"},
                "Development MCP negotiated an unsupported protocol version")
        require("tools" in result.get("capabilities", {}), "Development MCP did not advertise tools")
        self.request("notifications/initialized", notification=True)
        tools, cursor = [], None
        for _ in range(10):
            page = self.request("tools/list", {"cursor": cursor} if cursor else {})
            tools.extend(page.get("tools", []))
            cursor = page.get("nextCursor")
            if not cursor:
                break
        require(not cursor, "Development MCP catalogue exceeded the smoke-client page limit")
        return tools

    def tool(self, name, arguments=None):
        result = self.request("tools/call", {"name": name, "arguments": arguments or {}})
        require(not result.get("isError", False), f"Development MCP tool {name} failed")
        return result

    def close(self):
        self.process.stdin.close()
        try:
            self.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.process.terminate()
            try:
                self.process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=5)


def tool_text(result):
    texts = []

    def collect(value):
        if isinstance(value, str):
            texts.append(value)
        elif isinstance(value, dict):
            for nested in value.values():
                collect(nested)
        elif isinstance(value, list):
            for nested in value:
                collect(nested)

    collect(result.get("structuredContent"))
    for item in result.get("content", []):
        if item.get("type") != "text":
            continue
        text = item.get("text", "")
        try:
            collect(json.loads(text))
        except ValueError:
            collect(text)
    return "\n".join(texts)


def mate(*arguments):
    result = subprocess.run(["bin/mate", *arguments], capture_output=True, text=True, timeout=60)
    require(result.returncode == 0, f"Native Mate {arguments[0]} did not complete")
    require(len(result.stdout.encode()) <= 4 * 1024 * 1024, "Mate response exceeded the smoke-client limit")
    try:
        return json.loads(result.stdout)
    except ValueError as error:
        raise RuntimeError("Native Mate did not return its documented JSON output") from error


def mate_tool(name, **arguments):
    return mate("tools:call", name, "--format=json", "--json=" + json.dumps(arguments))


def mate_environment():
    catalogue = mate("tools:list", "--format=json")
    required = {"server-info", "symfony-service-detail", "symfony-profiler-get", "monolog-context-search"}
    require(required <= set(catalogue.get("tools", {})), "Mate did not discover its PHP/Symfony/Monolog capabilities")
    runtime = mate_tool("server-info")
    require(str(runtime.get("php_version", "")).startswith("8.5."), "Mate measured a different PHP runtime")
    service_id = "App\\Service\\FoundationProbe"
    service = mate_tool("symfony-service-detail", id=service_id).get("untrusted_data", {})
    require(service.get("id") == service_id and service.get("class") == service_id and service.get("arguments"),
            "Mate did not inspect the real compiled foundation service definition")
    return {"native_mate": True, "php_version": runtime["php_version"], "compiled_service": service_id,
            "discovered_tools": sorted(required)}


def profiler(base_url):
    with urlopen(base_url + "/projects", timeout=20) as response:
        require(response.status == 200, "Normal application page did not render")
        require(not response.headers.get("X-Debug-Token"), "An ordinary request enabled development profiling")
        policy = response.headers.get("Content-Security-Policy", "")
        require("script-src 'nonce-" in policy and "style-src-attr 'none'" in policy,
                "Development tooling weakened an ordinary page's CSP")
        require("sfwdt" not in read_response(response).decode(), "The profiler toolbar changed the application page")
    with urlopen(base_url + "/projects?profile=1", timeout=20) as response:
        require(response.status == 200, "Manually profiled application request failed")
        token = response.headers.get("X-Debug-Token", "")
        require(re.fullmatch(r"[a-f0-9]{6,64}", token), "Opt-in request did not produce a real profiler token")
        read_response(response)
    with urlopen(base_url + "/_profiler/" + token, timeout=20) as response:
        require(response.status == 200, "Stored request profile could not be opened")
        content = read_response(response).decode()
        require("/projects" in content and "app_projects" in content,
                "Profiler did not retain the measured application request")
    profile = mate_tool("symfony-profiler-get", token=token).get("untrusted_data", {})
    require(profile.get("token") == token and profile.get("status_code") == 200 and "/projects" in profile.get("url", ""),
            "Mate did not inspect the profile of the actual HTTP request")
    require(profile.get("collectors"), "Mate did not expose the stored request profile's collectors")
    return {"manual_request_profile": True, "profile_inspected_by_mate": True, "route": "app_projects",
            "ordinary_request_profile": False, "ordinary_page_csp": "strict"}


def worker_log(state_path, log_path):
    state = json.loads(state_path.read_text())
    probe_id = state["probe_id"]
    require(state.get("completed", {}).get("status") == "completed", "Verify the real foundation worker before inspecting its log")
    code = r"""
    $paths = glob($argv[1]);
    if (!$paths) { fwrite(STDERR, 'Development log files are unavailable.'); exit(1); }
    sort($paths);
    $remaining = 8 * 1024 * 1024;
    $matched = [];
    foreach (array_reverse(array_slice($paths, -3)) as $path) {
        $stream = @fopen($path, 'rb');
        if (false === $stream) { fwrite(STDERR, 'Development log file is unreadable.'); exit(1); }
        $size = fstat($stream)['size'];
        if ($size > $remaining) { fseek($stream, $size - $remaining); fgets($stream); }
        $bytes = stream_get_contents($stream, $remaining);
        fclose($stream);
        $remaining -= strlen($bytes);
        foreach (explode("\n", $bytes) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && ($row['context']['correlation_id'] ?? null) === $argv[2]) {
                $matched[] = ['message' => $row['message'] ?? null, 'level' => $row['level_name'] ?? null,
                    'probe_id' => $row['context']['probe_id'] ?? null, 'correlation_id' => $row['context']['correlation_id']];
            }
        }
        if ($remaining <= 0) { break; }
    }
    echo json_encode($matched, JSON_THROW_ON_ERROR);
    """
    deadline = time.monotonic() + 10
    while True:
        result = subprocess.run(["docker", "compose", "exec", "-T", "php", "php", "-r", code, "--", log_path, probe_id],
                                check=True, capture_output=True, text=True, timeout=20)
        rows = json.loads(result.stdout)
        messages = {row["message"] for row in rows}
        if {"foundation.probe.queued", "foundation.probe.completed"} <= messages:
            break
        require(time.monotonic() < deadline, "Shared JSON log did not retain the correlated worker completion")
        time.sleep(0.2)
    require(all(row["probe_id"] == probe_id and row["correlation_id"] == probe_id for row in rows),
            "Worker log events lost the durable probe identity")
    retained = mate_tool("monolog-context-search", key="correlation_id", value=probe_id, environment="dev", limit=100).get("untrusted_data", {})
    entries = retained.get("entries", [])
    require({"foundation.probe.queued", "foundation.probe.completed"} <= {row.get("message") for row in entries},
            "Mate's native Monolog tool did not retain the correlated queued/worker events")
    require(all(row.get("context", {}).get("correlation_id") == probe_id for row in entries),
            "Mate returned log evidence for another operation")
    return {"shared_json_worker_log": True, "inspected_by_mate": True, "probe_id": probe_id, "events": sorted(messages)}


def serena():
    client = StdioMcp(["bin/serena"])
    try:
        names = {tool["name"] for tool in client.initialize()}
        require({"initial_instructions", "serena_repl"} <= names, "Serena did not expose its current session/REPL tools")
        instructions = client.tool("initial_instructions")
        session = instructions.get("structuredContent", {}).get("session_id")
        if not session:
            for item in instructions.get("content", []):
                if item.get("type") != "text":
                    continue
                try:
                    value = json.loads(item.get("text", ""))
                    if isinstance(value, dict):
                        session = value.get("session_id")
                except ValueError:
                    continue
                if session:
                    break
        require(isinstance(session, str) and session, "Serena did not establish a bound REPL session")
        code = """from pathlib import Path
import json
xorgate_consumer_value = 40
json.dumps({'xorgate_consumer_mount': all(Path(p).is_file() for p in ['/workspace/composer.json', '/workspace/vendor/autoload.php', '/workspace/templates/layouts/app.html.twig', '/workspace/assets/app.js']), 'lsp_edit_info': str(s.info('lsp', 'edit'))})
"""
        first = client.tool("serena_repl", {"session": session, "code": code})
        text = tool_text(first)
        require('"xorgate_consumer_mount": true' in text, "Serena could not inspect the actual mounted Symfony/vendor/UI project")
        require("edit" in text.lower(), "Serena did not expose its language-server API information")
        second = client.tool("serena_repl", {"session": session,
                             "code": "'xorgate_consumer_persistent=' + str(xorgate_consumer_value + 2)"})
        require("xorgate_consumer_persistent=42" in tool_text(second), "Serena REPL did not preserve the real session state")
        return {"serena_mcp": True, "session_repl": True, "mounted_project_and_vendor": True, "language_server_api_discovered": True}
    finally:
        client.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("mate", "profiler", "worker-log", "serena"))
    parser.add_argument("--url", default=os.environ.get("XORGATE_URL", "http://127.0.0.1:8088"))
    parser.add_argument("--foundation-state", type=Path, default=Path("var/ci/smoke-state.json"))
    parser.add_argument("--log-file", default="var/log/dev*.log")
    parser.add_argument("--evidence", type=Path, default=Path("var/ci/development.json"))
    options = parser.parse_args()
    if options.phase == "mate":
        observed = mate_environment()
    elif options.phase == "profiler":
        observed = profiler(options.url.rstrip("/"))
    elif options.phase == "worker-log":
        observed = worker_log(options.foundation_state, options.log_file)
    else:
        observed = serena()
    evidence = json.loads(options.evidence.read_text()) if options.evidence.is_file() else {}
    evidence[options.phase] = observed
    options.evidence.parent.mkdir(parents=True, exist_ok=True)
    options.evidence.write_text(json.dumps(evidence, indent=2) + "\n")
    print(f"Development {options.phase} observed in the running consumer")


if __name__ == "__main__":
    main()
