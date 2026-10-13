#!/usr/bin/env python3
"""Exercise the running Docker foundation through its public HTTP/MCP interfaces.

The queued/complete/persist phases bracket external worker and container restarts.
Only the expected probe state is saved; credentials and MCP sessions are not.
"""

import argparse
import json
import os
import subprocess
import time
import uuid
from html.parser import HTMLParser
from pathlib import Path, PurePosixPath
from urllib.error import HTTPError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen


MAX_RESPONSE_BYTES = 4 * 1024 * 1024
TOOL_NAMES = {
    "xorgate_runtime_status",
    "xorgate_probe_dispatch",
    "xorgate_probe_get",
}


def require(condition, detail):
    if not condition:
        raise RuntimeError(detail)


def read_response(response):
    body = response.read(MAX_RESPONSE_BYTES + 1)
    require(len(body) <= MAX_RESPONSE_BYTES, "HTTP response exceeded smoke-client limit")
    return body


class PageAssets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.ready = False
        self.styles = []
        self.modules = []

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if attrs.get("data-xorgate-foundation-status") == "ready":
            self.ready = True
        if tag == "link" and attrs.get("rel") == "stylesheet" and attrs.get("href"):
            self.styles.append(attrs["href"])
        if tag == "link" and attrs.get("rel") == "modulepreload" and attrs.get("href"):
            self.modules.append(attrs["href"])
        if tag == "script" and attrs.get("src"):
            self.modules.append(attrs["src"])


def check_page(base_url):
    with urlopen(base_url + "/", timeout=15) as response:
        require(response.status == 200, "Dashboard did not render")
        content = read_response(response).decode("utf-8")
    page = PageAssets()
    page.feed(content)
    require(page.ready, "Dashboard does not report the same ready foundation")
    require(page.styles, "Dashboard did not render any stylesheet links")
    origin = urlparse(base_url).netloc
    local_assets = [
        urljoin(base_url + "/", path)
        for path in page.styles + page.modules
        if urlparse(urljoin(base_url + "/", path)).netloc == origin
    ]
    require(local_assets, "Dashboard did not reference any local application assets")
    for asset in set(local_assets):
        with urlopen(asset, timeout=15) as response:
            require(response.status == 200, "Application asset could not be fetched")
            require(read_response(response), "Application asset is empty")
            require("text/html" not in response.headers.get("Content-Type", ""),
                    "Application asset resolved to an HTML fallback page")


class McpClient:
    def __init__(self, endpoint, token):
        self.endpoint = endpoint
        self.token = token
        self.session_id = None
        self.version = None
        self.request_id = 0

    def request(self, method, parameters=None, notification=False):
        self.request_id += 1
        payload = {"jsonrpc": "2.0", "method": method}
        if parameters is not None:
            payload["params"] = parameters
        if not notification:
            payload["id"] = self.request_id
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Authorization": "Bearer " + self.token,
        }
        if self.session_id:
            headers["Mcp-Session-Id"] = self.session_id
        if self.version:
            headers["MCP-Protocol-Version"] = self.version
        request = Request(self.endpoint, data=json.dumps(payload).encode(), headers=headers)
        try:
            with urlopen(request, timeout=20) as response:
                body = read_response(response).decode("utf-8")
                if response.headers.get("Mcp-Session-Id"):
                    self.session_id = response.headers["Mcp-Session-Id"]
                content_type = response.headers.get("Content-Type", "")
        except HTTPError as error:
            # Do not echo request headers, sessions or credentials into CI artifacts.
            raise RuntimeError(f"MCP {method} returned HTTP {error.code}") from error
        if notification:
            require(not body.strip(), f"MCP {method} unexpectedly returned a notification body")
            return None
        messages = []
        if "text/event-stream" in content_type:
            for event in body.replace("\r\n", "\n").split("\n\n"):
                data = "\n".join(line[5:].lstrip() for line in event.splitlines()
                                 if line.startswith("data:"))
                if data:
                    messages.append(json.loads(data))
        else:
            value = json.loads(body)
            messages = value if isinstance(value, list) else [value]
        result = next((item for item in messages if item.get("id") == self.request_id), None)
        require(result is not None, f"MCP {method} did not return the matching request ID")
        require("error" not in result, f"MCP {method} returned a protocol error: {result.get('error')}")
        require("result" in result, f"MCP {method} response has no result")
        return result["result"]

    def initialize(self):
        initialized = self.request("initialize", {
            "protocolVersion": "2025-06-18",
            "capabilities": {},
            "clientInfo": {"name": "xorgate-foundation-smoke", "version": "1.0.0"},
        })
        self.version = initialized.get("protocolVersion")
        require(self.version in {"2025-03-26", "2025-06-18", "2025-11-25"},
                "Server negotiated a protocol this handshake client does not implement")
        require("tools" in initialized.get("capabilities", {}), "MCP server did not advertise tools")
        self.request("notifications/initialized", notification=True)
        names = set()
        cursor = None
        for _ in range(10):
            listed = self.request("tools/list", {"cursor": cursor} if cursor else {})
            names.update(item["name"] for item in listed.get("tools", []))
            cursor = listed.get("nextCursor")
            if not cursor:
                break
        require(not cursor, "Unexpectedly large tool catalogue")
        require(TOOL_NAMES <= names, "MCP foundation tools were not all discovered")

    def tool(self, name, arguments=None):
        result = self.request("tools/call", {"name": name, "arguments": arguments or {}})
        require(not result.get("isError", False), f"MCP tool {name} reported an error")
        if isinstance(result.get("structuredContent"), dict):
            return result["structuredContent"]
        for item in result.get("content", []):
            if item.get("type") == "text":
                try:
                    value = json.loads(item["text"])
                except (json.JSONDecodeError, KeyError):
                    continue
                if isinstance(value, dict):
                    return value
        raise RuntimeError(f"MCP tool {name} did not return a structured object")


def check_auth(endpoint):
    request = Request(endpoint, data=b'{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
                      headers={"Content-Type": "application/json", "Accept": "application/json"})
    try:
        with urlopen(request, timeout=15):
            raise RuntimeError("MCP endpoint accepted an unauthenticated request")
    except HTTPError as error:
        require(error.code == 401, "Unauthenticated MCP request was not rejected with HTTP 401")


def check_runtime(client):
    status = client.tool("xorgate_runtime_status")
    require(status.get("ready") is True, "MCP runtime status is not ready")
    for name in ("database", "queue", "artifacts"):
        require(status.get("checks", {}).get(name, {}).get("status") == "ready",
                f"Runtime {name} check is not ready")
    require(str(status.get("application", {}).get("framework", "")).startswith("8.1."),
            "Application is not running Symfony 8.1")
    return status


def check_artifact(probe):
    artifact = probe.get("artifact") or {}
    relative = artifact.get("path", "")
    require(relative == f"foundation/{probe['probe_id']}.json", "Unexpected probe artifact path")
    require(not PurePosixPath(relative).is_absolute() and ".." not in PurePosixPath(relative).parts,
            "Probe artifact is not a safe relative path")
    require(artifact.get("available") is True, "Probe artifact is unavailable")
    # Read independently of the application's MCP metadata to check actual persisted bytes.
    code = """
    $base = getenv('XORGATE_ARTIFACT_DIR') ?: '/var/lib/xorgate/artifacts';
    $path = $base.'/'.$argv[1];
    $bytes = file_get_contents($path);
    if (false === $bytes) { exit(1); }
    echo json_encode(['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes),
        'content' => json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
    """
    read = subprocess.run(["docker", "compose", "exec", "-T", "php", "php", "-r", code,
                           "--", relative], check=True, capture_output=True, text=True)
    observed = json.loads(read.stdout)
    require(observed["sha256"] == artifact.get("sha256"), "Artifact digest does not match stored metadata")
    require(observed["bytes"] == artifact.get("bytes"), "Artifact length does not match stored metadata")
    content = observed["content"]
    require(content.get("kind") == "xorgate_foundation_probe", "Artifact has an unexpected kind")
    require(content.get("probe_id") == probe["probe_id"], "Artifact belongs to a different probe")
    require(content.get("correlation_id") == probe["probe_id"], "Artifact lost telemetry correlation")
    require(content.get("checks") == {"database": "ready", "queue": "consumed", "artifacts": "written"},
            "Artifact does not establish database, worker and storage execution")
    require(content.get("completed_at") == probe.get("completed_at"), "Artifact and record completion differ")
    return observed


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("queued", "complete", "persist"))
    parser.add_argument("--url", default=os.environ.get("XORGATE_URL", "http://127.0.0.1:8088"))
    parser.add_argument("--state", type=Path, default=Path("var/ci/smoke-state.json"))
    options = parser.parse_args()
    token = os.environ.get("XORGATE_MCP_TOKEN", "")
    require(token, "Set XORGATE_MCP_TOKEN to the running application's bearer credential")
    base_url = options.url.rstrip("/")
    client = McpClient(base_url + "/mcp", token)
    client.initialize()
    runtime = check_runtime(client)
    if options.phase == "queued":
        check_auth(client.endpoint)
        check_page(base_url)
        probe_id = str(uuid.uuid4())
        probe = client.tool("xorgate_probe_dispatch", {"probeId": probe_id})
        require(probe.get("probe_id") == probe_id, "Dispatch did not retain the supplied idempotency key")
        require(probe.get("status") == "queued", "Stopped worker did not leave a queued durable probe")
        require(probe.get("artifact") is None, "Queued probe unexpectedly has an artifact")
        repeated = client.tool("xorgate_probe_dispatch", {"probeId": probe_id})
        require(repeated == probe, "Duplicate dispatch did not return the same queued record")
        state = {"probe_id": probe_id, "queued": probe, "runtime": runtime}
    else:
        state = json.loads(options.state.read_text())
        probe_id = state["probe_id"]
        deadline = time.monotonic() + 120
        while True:
            probe = client.tool("xorgate_probe_get", {"probeId": probe_id})
            require(probe.get("probe_id") == probe_id, "Query returned a different durable record")
            if probe.get("status") != "queued":
                break
            require(time.monotonic() < deadline, "Worker did not complete the queued probe within 120 seconds")
            time.sleep(1)
        require(probe.get("status") == "completed", f"Durable probe did not complete: {probe.get('error')}")
        require(probe.get("correlation_id") == probe_id, "Probe lost its telemetry correlation")
        require(probe.get("created_at") == state["queued"].get("created_at"), "Probe creation identity changed")
        artifact = check_artifact(probe)
        if options.phase == "persist":
            require(probe == state["completed"], "Restart changed the completed probe record")
            require(artifact == state["artifact"], "Restart changed the original artifact bytes or content")
            check_page(base_url)
        else:
            state["completed"] = probe
            state["artifact"] = artifact
        state[options.phase + "_runtime"] = runtime
    options.state.parent.mkdir(parents=True, exist_ok=True)
    options.state.write_text(json.dumps(state, indent=2) + "\n")
    print(f"Foundation {options.phase} verified; durable probe {state['probe_id']}")


if __name__ == "__main__":
    main()
