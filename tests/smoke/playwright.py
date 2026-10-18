#!/usr/bin/env python3
"""Verify Playwright attempts, derived annotations and bounded log windows through the running UI, MCP and CLI.

The fixtures are controlled producer output and UXor tool-derived files (tests/Fixtures/Playwright). They are
not genuine UXor CI artifacts; that acceptance item remains open.
"""

import argparse
import base64
import json
import os
import subprocess
import time
import uuid
from pathlib import Path

from foundation import McpClient, require
from intake import IntakeBrowser, IntakePage, scope
from registry import cli


FIXTURES = Path("tests/Fixtures/Playwright")
INLINE_LIMIT = 65536
NEW_TOOLS = {"xorgate_attempts_list", "xorgate_annotations_list", "xorgate_log_read"}
FLAKY = "flaky: fails on the first attempt, passes on retry"
# Submission order deliberately imports a derived file before its native report.
IMPORTS = [
    ("failed", "controlled-failed-attempts.json", "playwright-failed-attempts", "application/json"),
    ("native", "controlled-results.json", "playwright-json", "application/json"),
    ("jev", "controlled-jev-assessment.json", "jev-assessment", "application/json"),
    ("durations", "controlled-durations.json", "playwright-durations", "application/json"),
    ("not_reached", "not-reached-failed-attempts.json", "playwright-failed-attempts", "application/json"),
    ("log", "controlled-server.log", "text-log", "text/plain"),
]


class RowsPage(IntakePage):
    """Collects each element's stable data attributes, keyed by the identifying attribute."""

    def __init__(self, key):
        super().__init__()
        self.key = key
        self.rows = {}
        self.options = []

    def handle_starttag(self, tag, attributes):
        super().handle_starttag(tag, attributes)
        attrs = dict(attributes)
        if attrs.get(self.key):
            self.rows[attrs[self.key]] = {name: value for name, value in attrs.items() if name.startswith("data-xorgate-")}
        if tag == "option" and attrs.get("value"):
            self.options.append(attrs["value"])


class PlaywrightBrowser(IntakeBrowser):
    def rows(self, path, key):
        status, headers, content = self.raw(path)
        page = RowsPage(key)
        text = content.decode("utf-8")
        page.feed(text)
        return status, headers, page, text

    def upload_file(self, path, fields, name, media_type, content):
        boundary = "xorgate-smoke-" + uuid.uuid4().hex
        body = bytearray()
        for field, value in fields.items():
            if field == "report_upload[report]":
                continue
            body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"\r\n\r\n{value}\r\n').encode())
        body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="report_upload[report]"; filename="{name}"\r\nContent-Type: {media_type}\r\n\r\n').encode())
        body.extend(content)
        body.extend((f"\r\n--{boundary}--\r\n").encode())
        return self.raw(path, bytes(body), {"Accept": "text/html", "Content-Type": "multipart/form-data; boundary=" + boundary})


def fixture(name):
    path = FIXTURES / name
    require(path.is_file(), f"Required Playwright fixture is absent: {path}")
    return path.read_bytes()


def wait(client, ids, operation_id):
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        current = client.tool("xorgate_operation_get", ids | {"operationId": operation_id})
        if current["status"] == "completed":
            return current
        require(current["status"] != "failed", "Report worker could not process a Playwright fixture")
        time.sleep(0.5)
    raise RuntimeError("Report worker did not complete a Playwright fixture within its acceptance deadline")


def paged(client, tool, arguments, limit):
    items, cursor = [], None
    for _ in range(100):
        page = client.tool(tool, arguments | {"limit": limit} | ({"cursor": cursor} if cursor else {}))
        require(len(page["items"]) <= limit, f"{tool} ignored its page bound")
        items.extend(page["items"])
        cursor = page["next_cursor"]
        if not cursor:
            return items
    raise RuntimeError(f"{tool} exceeded the smoke's bounded page count")


def submit(client, browser):
    listing = client.request("tools/list", {})
    names = {tool["name"] for tool in listing.get("tools", [])}
    cursor = listing.get("nextCursor")
    for _ in range(10):
        if not cursor:
            break
        listing = client.request("tools/list", {"cursor": cursor})
        names.update(tool["name"] for tool in listing.get("tools", []))
        cursor = listing.get("nextCursor")
    require(NEW_TOOLS <= names, "MCP attempt, annotation and log tools were not all discovered")
    revision = subprocess.run(["git", "rev-parse", "HEAD"], check=True, capture_output=True, text=True).stdout.strip()
    project = client.tool("xorgate_project_register", {"input": {"request_id": str(uuid.uuid4()), "name": "Xorgate controlled Playwright acceptance", "runtimes": ["Node.js"]}})
    execution = client.tool("xorgate_execution_record", {"projectId": project["project_id"], "input": {
        "request_id": str(uuid.uuid4()), "source": {"tested_revision": revision},
        "environment": {"name": "Controlled Playwright fixtures", "runtimes": ["Node.js"]},
        "producer": {"name": "Playwright (controlled fixture)"}}})
    state = {"schema_version": 1, "project": project, "execution": execution, "operations": {}}
    ids = scope(state)
    base = f'/projects/{ids["projectId"]}/executions/{ids["executionId"]}'
    for slot, name, format_name, media_type in IMPORTS:
        content = fixture(name)
        metadata = {"request_id": str(uuid.uuid4()), "report_role": slot.replace("_", "-"), "format": format_name,
                    "filename": name, "media_type": media_type, "source": execution["source"]}
        if slot == "native":
            # The native report goes through the browser form, proving the installed adapter choice.
            status, _, form, _ = browser.rows(base + "/reports/new", "data-xorgate-none")
            require(status == 200 and form.form_id == "report_upload", "Upload form did not render")
            require({"junit", "playwright-json", "text-log"} <= set(form.options), "Upload form does not offer the installed adapter formats")
            fields = dict(form.form_values)
            fields.update({"report_upload[report_role]": metadata["report_role"], "report_upload[format]": format_name, "report_upload[confirm_context]": "1"})
            metadata["request_id"] = fields["report_upload[request_id]"]
            status, headers, _ = browser.upload_file(base + "/reports/new", fields, name, media_type, content)
            require(status == 303 and headers.get("Location"), "Native Playwright upload did not redirect after acceptance")
            operation_id = headers["Location"].rstrip("/").split("/")[-1]
        elif len(content) <= INLINE_LIMIT:
            operation_id = client.tool("xorgate_report_submit", ids | {"input": metadata, "contentBase64": base64.b64encode(content).decode()})["operation_id"]
        else:
            operation_id = cli("xorgate:report:submit", ids["projectId"], ids["executionId"], "--input=-", f"--file={FIXTURES / name}", input_value=metadata)["operation_id"]
        completed = wait(client, ids, operation_id)
        require(completed["format"] == format_name and completed["parser"]["name"] == format_name, "Import did not record its installed adapter")
        state["operations"][slot] = completed
    # Linking may have changed earlier annotation imports; keep their final state.
    for slot, current in list(state["operations"].items()):
        state["operations"][slot] = client.tool("xorgate_operation_get", ids | {"operationId": current["operation_id"]})
    return state


def verify(client, browser, state, persisted=None):
    ids = scope(state)
    base = f'/projects/{ids["projectId"]}/executions/{ids["executionId"]}'
    operations = state["operations"]
    for slot, stored in operations.items():
        current = client.tool("xorgate_operation_get", ids | {"operationId": stored["operation_id"]})
        require(current == stored, f"Durable {slot} operation changed")
        require(cli("xorgate:operation:get", ids["projectId"], ids["executionId"], stored["operation_id"]) == current, f"MCP and CLI {slot} operation disagree")
    native = operations["native"]
    require(native["parser_outcome"] == "parsed" and native["summary"]["cases"] == 14 and native["attempt_count"] == 18,
            "Native Playwright report did not record 14 tests and 18 attempts")
    require(native["summary"]["shard"] == {"current": 1, "total": 2}, "Native shard context was lost")
    require(native["summary"]["shard_completeness"]["status"] == "unknown", "Shard completeness was claimed without an expectation")
    for key in ("queued_seconds", "processing_seconds", "parse_ms"):
        require(native["timings"][key] is not None, f"Completed import did not expose timing {key}")
    native_args = ids | {"operationId": native["operation_id"]}

    attempts = paged(client, "xorgate_attempts_list", native_args, 7)
    require(len(attempts) == 18 == len({a["attempt_id"] for a in attempts}) == len({a["attempt_key"] for a in attempts}),
            "Derived imports duplicated or lost native attempts")
    full = client.tool("xorgate_attempts_list", native_args | {"limit": 100})
    require(full["items"] == attempts, "Attempt pages disagree with the full listing")
    require(cli("xorgate:attempts:list", ids["projectId"], ids["executionId"], native["operation_id"], "--limit=100") == full, "MCP and CLI attempts disagree")
    for project in ("controlled", "controlled-dark"):
        history = [(a["retry"], a["status"]) for a in attempts if a["case"]["name"] == FLAKY and a["case"]["project"] == project]
        require(history == [(0, "failed"), (1, "passed")], "A failed first attempt did not survive beside its passing retry")
        expected = [a for a in attempts if a["case"]["name"] == "expected failure" and a["case"]["project"] == project]
        require(len(expected) == 1 and expected[0]["status"] == "failed" and expected[0]["matches_expected"] is True and expected[0]["case"]["expected_failure"] is True,
                "Expected failure is not distinguishable from an unexpected one")

    failed = operations["failed"]
    require(failed["links"]["linked"] == 6 and failed["links"]["unmatched"] == 0 and failed["links"]["ambiguous"] == 0,
            "Failed-attempt records imported before the native report were not linked after it")
    failed_args = ids | {"operationId": failed["operation_id"]}
    annotations = paged(client, "xorgate_annotations_list", failed_args, 4)
    require(cli("xorgate:annotations:list", ids["projectId"], ids["executionId"], failed["operation_id"], "--limit=100") == client.tool("xorgate_annotations_list", failed_args | {"limit": 100}),
            "MCP and CLI annotations disagree")
    by_key = {a["attempt_key"]: a for a in attempts}
    for annotation in (a for a in annotations if a["kind"] == "failed_attempt"):
        target = by_key.get(annotation["match"]["target_attempt_key"])
        require(annotation["match"]["status"] == "linked" and target and target["status"] == "failed" and target["retry"] == annotation["target"]["retry"],
                "A failed-attempt record is not linked to its exact native attempt")
        require(annotation["annotation_id"] in {r["annotation_id"] for r in target["annotations"]}, "Attempt listing omitted its linked annotation")
    jev = [a for a in client.tool("xorgate_annotations_list", ids | {"operationId": operations["jev"]["operation_id"]})["items"] if a["kind"] == "jev_assessment"]
    require(len(jev) == 1 and jev[0]["match"]["status"] == "linked" and jev[0]["data"]["status"] == "unavailable" and jev[0]["data"]["reason"] == "missing_credential",
            "Existing Jev assessment is not readable and linked without a credential")
    assessed = by_key[jev[0]["match"]["target_attempt_key"]]
    require((assessed["case"]["name"], assessed["retry"], assessed["status"]) == (FLAKY, 0, "failed"), "Jev assessment linked to the wrong attempt")
    not_reached = client.tool("xorgate_annotations_list", ids | {"operationId": operations["not_reached"]["operation_id"]})["items"]
    require([a["kind"] for a in not_reached].count("producer_status") == 1 and operations["not_reached"]["summary"]["producer"]["status"] == "not-reached",
            "Setup failure (tests not reached) is not visible")

    status, headers, page, _ = browser.rows(base + "/intake/" + native["operation_id"] + "/attempts?limit=100", "data-xorgate-attempt-id")
    require(status == 200 and "Content-Security-Policy" in headers, "Attempts page did not render with the application CSP")
    expected_rows = {a["attempt_id"]: (str(a["retry"]), a["status"]) for a in attempts}
    require({key: (row.get("data-xorgate-attempt-retry"), row.get("data-xorgate-attempt-status")) for key, row in page.rows.items()} == expected_rows,
            "UI attempts disagree with MCP")
    status, _, page, _ = browser.rows(base + "/intake/" + native["operation_id"], "data-xorgate-none")
    require(status == 200 and page.state.get("data-xorgate-attempt-count") == "18" and page.state.get("data-xorgate-shard") == "1/2"
            and page.state.get("data-xorgate-shard-completeness") == "unknown", "Operation page lost attempt count or shard context")
    status, headers, page, _ = browser.rows(base + "/intake/" + failed["operation_id"] + "/annotations?limit=100", "data-xorgate-annotation-id")
    require(status == 200 and "Content-Security-Policy" in headers, "Annotations page did not render with the application CSP")
    require({key: row.get("data-xorgate-annotation-match") for key, row in page.rows.items()} == {a["annotation_id"]: a["match"]["status"] for a in annotations},
            "UI annotations disagree with MCP")

    log = operations["log"]
    content = fixture("controlled-server.log")
    artifact = log["artifact"]["artifact_id"]
    log_args = ids | {"artifactId": artifact}
    text, offset = "", 0
    for _ in range(100):
        window = client.tool("xorgate_log_read", log_args | {"offset": offset, "limit": 2048})
        require(window["offset"] == offset and window["returned_bytes"] <= 2048 and "path" not in window, "Log window violated its bounded contract")
        text += window["content_text"]
        if window["eof"]:
            require(window["next_offset"] is None, "Final log window retained a cursor")
            break
        require(window["next_offset"] == offset + window["returned_bytes"], "Log window offsets are not contiguous")
        offset = window["next_offset"]
    require(text.encode("utf-8") == content, "Bounded log windows changed the original text")
    window = client.tool("xorgate_log_read", log_args | {"offset": 2048, "limit": 2048})
    require(cli("xorgate:log:read", ids["projectId"], ids["executionId"], artifact, "--offset=2048", "--limit=2048") == window, "MCP and CLI log windows disagree")
    status, headers, page, html = browser.rows(f"{base}/artifacts/{artifact}/log?offset=2048&limit=2048", "data-xorgate-none")
    require(status == 200 and "Content-Security-Policy" in headers, "Log page did not render with the application CSP")
    require(page.state.get("data-xorgate-log-offset") == "2048" and page.state.get("data-xorgate-log-next-offset") == str(window["next_offset"] if window["next_offset"] is not None else "none")
            and page.state.get("data-xorgate-log-eof") == ("true" if window["eof"] else "false")
            and page.state.get("data-xorgate-log-ends-mid-line") == ("true" if window["ends_mid_line"] else "false"), "UI log window offsets disagree with MCP")
    require("\x1b" not in html, "Log page passed raw terminal escapes to the browser")

    if persisted is not None:
        require(attempts == persisted["attempts"] and annotations == persisted["annotations"], "Container restart changed attempts or annotations")
    return {"attempts": attempts, "annotations": annotations}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("submit", "verify", "persist"))
    parser.add_argument("--state", type=Path, default=Path("var/ci/playwright.json"))
    options = parser.parse_args()
    started = time.monotonic()
    base_url = os.environ.get("XORGATE_URL", "http://127.0.0.1:8088").rstrip("/")
    client = McpClient(base_url + "/mcp", os.environ["XORGATE_MCP_TOKEN"])
    client.initialize()
    browser = PlaywrightBrowser(base_url)
    if options.phase == "submit":
        state = submit(client, browser)
    else:
        state = json.loads(options.state.read_text())
        state["observed"] = verify(client, browser, state, state.get("observed") if options.phase == "persist" else None)
    state.setdefault("timings_seconds", {})[options.phase] = round(time.monotonic() - started, 3)
    options.state.parent.mkdir(parents=True, exist_ok=True)
    options.state.write_text(json.dumps(state, indent=2) + "\n")
    print(f"Playwright intake {options.phase}: attempts, annotations and log windows agree across UI, MCP and CLI")


if __name__ == "__main__":
    main()
