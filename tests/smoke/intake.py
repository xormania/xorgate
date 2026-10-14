#!/usr/bin/env python3
"""Verify native producer evidence and the running durable report-intake interfaces."""

import argparse
import base64
import hashlib
import json
import os
import subprocess
import time
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urljoin
from urllib.request import Request
from xml.etree import ElementTree

from foundation import McpClient, read_response, require
from registry import BrowserSession, RegistryPage, cli, require_error


INTAKE_TOOLS = {"xorgate_report_submit", "xorgate_report_submit_artifact", "xorgate_operation_get",
                "xorgate_evidence_list", "xorgate_observations_list", "xorgate_artifact_get",
                "xorgate_artifact_read", "xorgate_intake_capacity"}


def observed_cases(content):
    cases = []
    for case in ElementTree.fromstring(content).iter("testcase"):
        outcome = "error" if case.find("error") is not None else "failure" if case.find("failure") is not None else "skipped" if case.find("skipped") is not None else "passed"
        cases.append({"name": case.get("name"), "native_outcome": outcome})
    return cases


def producer(report, evidence):
    content = report.read_bytes()
    cases = observed_cases(content)
    expected = {"testControlledPass": "passed", "testControlledFailure": "failure",
                "testControlledError": "error", "testControlledSkip": "skipped"}
    require(len(cases) == 4 and {case["name"]: case["native_outcome"] for case in cases} == expected,
            "Controlled PHPUnit did not emit the four intended native outcomes")
    runtime = subprocess.run(["docker", "compose", "exec", "-T", "php", "php", "-r",
                              'require "vendor/autoload.php"; echo json_encode(["php" => PHP_VERSION, "phpunit" => Composer\\InstalledVersions::getPrettyVersion("phpunit/phpunit")], JSON_THROW_ON_ERROR);'],
                             check=True, capture_output=True, text=True, timeout=20)
    measured = json.loads(runtime.stdout)
    require(str(measured.get("php", "")).startswith("8.5."), "Producer did not run in the selected PHP application runtime")
    require(measured.get("phpunit"), "Installed PHPUnit producer version was not measured")
    revision = subprocess.run(["git", "rev-parse", "HEAD"], check=True, capture_output=True, text=True).stdout.strip()
    dirty = subprocess.run(["git", "status", "--porcelain"], check=True, capture_output=True, text=True).stdout != ""
    value = {"schema_version": 1, "classification": "controlled-native-producer-report", "runtime": measured,
             "source_revision": revision, "workspace_dirty": dirty,
             "bytes": len(content), "sha256": hashlib.sha256(content).hexdigest(), "cases": cases,
             "expected_producer_exit_status": 2}
    evidence.parent.mkdir(parents=True, exist_ok=True)
    evidence.write_text(json.dumps(value, indent=2) + "\n")
    print("Controlled PHPUnit emitted four measured native outcomes; original XML retained")


class IntakePage(RegistryPage):
    def __init__(self):
        super().__init__()
        self.state = {}
        self.observations = {}

    def handle_starttag(self, tag, attributes):
        super().handle_starttag(tag, attributes)
        attrs = dict(attributes)
        self.state.update({key: value for key, value in attrs.items() if key.startswith("data-xorgate-")})
        if attrs.get("data-xorgate-observation-id"):
            self.observations[attrs["data-xorgate-observation-id"]] = attrs.get("data-xorgate-native-outcome")


class IntakeBrowser(BrowserSession):
    def raw(self, path, data=None, headers=None):
        request = Request(urljoin(self.base_url + "/", path), data=data, headers=headers or {"Accept": "text/html"})
        try:
            response = self.opener.open(request, timeout=20)
        except HTTPError as error:
            response = error
        with response:
            return response.code, response.headers, read_response(response)

    def page(self, path):
        status, headers, content = self.raw(path)
        parsed = IntakePage()
        parsed.feed(content.decode("utf-8"))
        return status, headers, parsed

    def upload(self, path, fields, content):
        boundary = "xorgate-smoke-" + uuid.uuid4().hex
        body = bytearray()
        for name, value in fields.items():
            if name == "report_upload[report]":
                continue
            body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n').encode())
        body.extend((f'--{boundary}\r\nContent-Disposition: form-data; name="report_upload[report]"; filename="controlled-producer-report.xml"\r\nContent-Type: application/xml\r\n\r\n').encode())
        body.extend(content)
        body.extend((f"\r\n--{boundary}--\r\n").encode())
        return self.raw(path, bytes(body), {"Accept": "text/html", "Content-Type": "multipart/form-data; boundary=" + boundary})


def scope(state):
    return {"projectId": state["project"]["project_id"], "executionId": state["execution"]["execution_id"]}


def operation(client, state):
    return client.tool("xorgate_operation_get", scope(state) | {"operationId": state["operation"]["operation_id"]})


def check_discovery(client):
    names = set()
    cursor = None
    for _ in range(10):
        listing = client.request("tools/list", {"cursor": cursor} if cursor else {})
        names.update(tool["name"] for tool in listing.get("tools", []))
        cursor = listing.get("nextCursor")
        if not cursor:
            break
    require(not cursor and INTAKE_TOOLS <= names, "MCP durable intake tools were not all discovered")


def check_original(client, browser, state, content):
    artifact = state["operation"]["artifact"]
    arguments = scope(state) | {"artifactId": artifact["artifact_id"]}
    metadata = client.tool("xorgate_artifact_get", arguments)
    require(metadata == artifact, "Operation and artifact metadata disagree")
    require(metadata["sha256"] == hashlib.sha256(content).hexdigest() and metadata["bytes"] == len(content),
            "Original digest/size do not describe the actual producer bytes")
    require(metadata["availability"] == {"status": "available", "available": True, "integrity_checked": True}, "Retained original is not verified")
    require(cli("xorgate:artifact:get", arguments["projectId"], arguments["executionId"], arguments["artifactId"]) == metadata,
            "CLI and MCP artifact metadata disagree")
    reconstructed = bytearray()
    offset = 0
    for _ in range(100):
        chunk = client.tool("xorgate_artifact_read", arguments | {"offset": offset, "limit": 4096})
        decoded = base64.b64decode(chunk["content_base64"], validate=True)
        require(chunk["offset"] == offset and chunk["returned_bytes"] == len(decoded) <= 4096,
                "Artifact read violated its bounded chunk contract")
        reconstructed.extend(decoded)
        if chunk["eof"]:
            require(chunk["next_offset"] is None, "Final artifact chunk retained a cursor")
            break
        require(chunk["next_offset"] == offset + len(decoded) and decoded, "Artifact read did not advance")
        offset = chunk["next_offset"]
    else:
        raise RuntimeError("Artifact retrieval exceeded the smoke's bounded page count")
    require(bytes(reconstructed) == content, "Bounded MCP retrieval changed the original bytes")
    path = f'/projects/{arguments["projectId"]}/executions/{arguments["executionId"]}/artifacts/{arguments["artifactId"]}'
    status, _, page = browser.page(path)
    require(status == 200 and page.state.get("data-xorgate-artifact-id") == artifact["artifact_id"], "Native artifact detail does not describe the same original")
    status, headers, download = browser.raw(path + "/download")
    require(status == 200 and download == content, "Native attachment download changed the original bytes")
    require("attachment" in headers.get("Content-Disposition", "") and artifact["artifact_id"] in headers.get("Content-Disposition", ""),
            "Original download omitted its safe attachment identity")
    require(headers.get("X-Content-Type-Options") == "nosniff" and "no-store" in headers.get("Cache-Control", ""),
            "Original download lost its response protections")


def check_views(client, browser, state, completed=False):
    current = operation(client, state)
    require(current == state["operation"], "Durable operation changed unexpectedly")
    require(current["source_context"] == state["execution"], "Import lost its immutable source context")
    ids = scope(state)
    require(cli("xorgate:operation:get", ids["projectId"], ids["executionId"], current["operation_id"]) == current,
            "MCP and CLI operation state disagree")
    base = f'/projects/{ids["projectId"]}/executions/{ids["executionId"]}'
    status, headers, page = browser.page(base + "/intake/" + current["operation_id"])
    require(status == 200 and page.state.get("data-xorgate-intake-operation-id") == current["operation_id"], "Native operation page lost its identity")
    require(page.state.get("data-xorgate-intake-status") == current["status"], "UI and durable operation status disagree")
    require("Content-Security-Policy" in headers, "Native intake page lost the application CSP")
    evidence = client.tool("xorgate_evidence_list", ids | {"limit": 1})
    require(len(evidence["items"]) == 1 and evidence["next_cursor"] is None, "Rejected submissions or redelivery created extra imports")
    listed = evidence["items"][0]
    require(listed["artifact"]["availability"] == {"status": "present", "available": False, "integrity_checked": False},
            "Evidence listing must report cheap presence without claiming verified original bytes")
    listed_artifact = {key: value for key, value in listed["artifact"].items() if key != "availability"}
    current_artifact = {key: value for key, value in current["artifact"].items() if key != "availability"}
    require(listed_artifact == current_artifact and {key: value for key, value in listed.items() if key != "artifact"} ==
            {key: value for key, value in current.items() if key != "artifact"},
            "Evidence list changed operation identity, parser state or original metadata")
    status, _, evidence_page = browser.page(base + "/evidence")
    require(status == 200 and evidence_page.state.get("data-xorgate-evidence-state") == "populated", "UI evidence list does not show the stored import")
    if completed:
        expected = {case["observation_id"]: case["native_outcome"] for case in state["observations"]}
        require(page.observations == expected, "Native UI and normalized case observations disagree")
        require(page.state.get("data-xorgate-parser-outcome") == current["parser_outcome"], "UI and parser outcome disagree")
    else:
        require(page.state.get("data-xorgate-observations-state") == "empty", "Queued operation displayed case observations")


def queued(client, browser, report, proof):
    content = report.read_bytes()
    require(hashlib.sha256(content).hexdigest() == proof["sha256"], "The measured producer original changed before intake")
    repository = os.environ.get("GITHUB_REPOSITORY")
    project_input = {"request_id": str(uuid.uuid4()), "name": "Xorgate controlled producer acceptance", "runtimes": ["PHP " + proof["runtime"]["php"]]}
    if repository:
        project_input.update({"repository_identity": "github:" + repository, "repository_url": "https://github.com/" + repository})
    project = client.tool("xorgate_project_register", {"input": project_input})
    execution = client.tool("xorgate_execution_record", {"projectId": project["project_id"], "input": {
        "request_id": str(uuid.uuid4()), "source": {"tested_revision": proof["source_revision"]},
        "environment": {"name": "Docker acceptance", "runtimes": project["runtimes"]},
        "producer": {"name": "PHPUnit", "version": proof["runtime"]["phpunit"]}}})
    state = {"schema_version": 1, "project": project, "execution": execution, "producer": proof}
    ids = scope(state)
    base = f'/projects/{ids["projectId"]}/executions/{ids["executionId"]}'
    require(client.tool("xorgate_evidence_list", ids)["items"] == [], "A fresh measured execution already contains evidence")
    status, _, empty = browser.page(base + "/evidence")
    require(status == 200 and empty.state.get("data-xorgate-evidence-state") == "empty", "UI did not show actual empty evidence")
    upload_path = base + "/reports/new"
    status, _, form = browser.page(upload_path)
    require(status == 200 and form.form_id == "report_upload", "Native upload form did not render with its stable identity")
    require(form.state.get("data-xorgate-intake-capacity") == "available" and form.state.get("data-xorgate-intake-pending") == "0",
            "Native upload page did not show measured empty intake capacity")
    fields = dict(form.form_values)
    require(fields.get("report_upload[_token]") and fields.get("report_upload[request_id]"), "Upload form omitted CSRF or request identity")
    fields.update({"report_upload[report_role]": "controlled-phpunit", "report_upload[format]": "junit", "report_upload[confirm_context]": "1"})
    forged = dict(fields); forged["report_upload[_token]"] = "forged"
    status, _, _ = browser.upload(upload_path, forged, content)
    require(status == 422, "Forged native upload did not return HTTP 422")
    require(client.tool("xorgate_evidence_list", ids)["items"] == [], "Rejected native upload admitted work")
    status, headers, _ = browser.upload(upload_path, fields, content)
    require(status == 303 and headers.get("Location"), "Valid native upload did not redirect after asynchronous acceptance")
    operation_id = headers["Location"].rstrip("/").split("/")[-1]
    state["operation"] = client.tool("xorgate_operation_get", ids | {"operationId": operation_id})
    current = state["operation"]
    require(current["status"] == "queued" and current["parser_outcome"] is None and current["summary"]["cases"] is None,
            "Stopped worker did not leave an explicit unparsed durable operation")
    metadata = {"request_id": fields["report_upload[request_id]"], "report_role": "controlled-phpunit", "format": "junit",
                "filename": "controlled-producer-report.xml", "media_type": "application/xml", "source": execution["source"]}
    state["input"] = metadata
    require(client.tool("xorgate_report_submit", ids | {"input": metadata, "contentBase64": base64.b64encode(content).decode()}) == current,
            "MCP retry did not reuse the native form's intended operation")
    require(cli("xorgate:report:submit", ids["projectId"], ids["executionId"], "--input=-", "--file=var/ci/controlled-producer-report.xml", input_value=metadata) == current,
            "CLI retry did not reuse the native form's intended operation")
    require_error(client.tool("xorgate_report_submit", ids | {"input": metadata, "contentBase64": base64.b64encode(content + b" ").decode()}), "conflict")
    capacity = client.tool("xorgate_intake_capacity")
    require(capacity["usage"]["pending"] == 1 and capacity["reason"] == "intake_pending_limit", "Live intake did not enforce the one-pending admission budget")
    new_input = metadata | {"request_id": str(uuid.uuid4())}
    rejected = client.tool("xorgate_report_submit", ids | {"input": new_input, "contentBase64": base64.b64encode(content).decode()})
    require_error(rejected, "capacity")
    require(rejected["capacity"] == capacity, "MCP rejection does not describe actual intake capacity")
    require(cli("xorgate:report:submit", ids["projectId"], ids["executionId"], "--input=-", "--file=var/ci/controlled-producer-report.xml", input_value=new_input, success=False) == rejected,
            "CLI and MCP admission limits disagree")
    limited_fields = fields | {"report_upload[request_id]": new_input["request_id"]}
    status, _, limited_content = browser.upload(upload_path, limited_fields, content)
    require(status == 429, "Native form did not show the same capacity rejection")
    limited_page = IntakePage()
    limited_page.feed(limited_content.decode("utf-8"))
    require(limited_page.state.get("data-xorgate-intake-capacity") == "limited" and limited_page.state.get("data-xorgate-intake-pending") == "1",
            "Native rejection did not retain the same measured pending capacity")
    oversized = cli("xorgate:artifact:read", ids["projectId"], ids["executionId"], current["artifact"]["artifact_id"], "--limit=32769", success=False)
    require_error(oversized, "invalid_input")
    other = client.tool("xorgate_execution_record", {"projectId": ids["projectId"], "input": {"request_id": str(uuid.uuid4()), "source": {"tested_revision": proof["source_revision"]}}})
    require_error(client.tool("xorgate_artifact_get", ids | {"executionId": other["execution_id"], "artifactId": current["artifact"]["artifact_id"]}), "not_found")
    check_views(client, browser, state)
    check_original(client, browser, state, content)
    return state


def complete(client, browser, state, content):
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        current = operation(client, state)
        if current["status"] == "completed":
            break
        require(current["status"] != "failed", "Native report worker could not normalize the original")
        time.sleep(0.5)
    else:
        raise RuntimeError("Native report worker did not complete within its acceptance deadline")
    state["operation"] = current
    require(current["parser_outcome"] == "parsed" and current["valid_document"] is True and current["summary"]["cases"] == 4,
            "Native worker did not parse the real producer report")
    require(current["summary"]["observed_counts"] == {"tests": 4, "passed": 1, "failures": 1, "errors": 1, "skipped": 1, "unknown": 0},
            "Parser counts disagree with the four actual native producer outcomes")
    cases = []
    cursor = None
    for _ in range(4):
        args = scope(state) | {"operationId": current["operation_id"], "limit": 2}
        if cursor:
            args["cursor"] = cursor
        page = client.tool("xorgate_observations_list", args)
        require(len(page["items"]) <= 2, "Observation list ignored its page bound")
        cases.extend(page["items"])
        cursor = page["next_cursor"]
        if not cursor:
            break
    require(not cursor and len(cases) == len({case["observation_id"] for case in cases}) == 4, "Observation pagination lost or duplicated cases")
    expected = {case["name"]: case["native_outcome"] for case in state["producer"]["cases"]}
    require({case["name"]: case["native_outcome"] for case in cases} == expected, "Normalized cases changed native outcomes")
    for case in cases:
        require(case["artifact_id"] == current["artifact"]["artifact_id"] and case["execution_id"] == state["execution"]["execution_id"],
                "Case observations lost original/execution provenance")
    state["observations"] = cases
    require(cli("xorgate:observations:list", scope(state)["projectId"], scope(state)["executionId"], current["operation_id"])["items"] == cases,
            "CLI and MCP normalized case pages disagree")
    require(client.tool("xorgate_intake_capacity")["usage"]["pending"] == 0, "Completed parsing retained pending admission capacity")
    check_views(client, browser, state, completed=True)
    check_original(client, browser, state, content)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("producer", "queued", "complete", "persist"))
    parser.add_argument("--report", type=Path, default=Path("var/ci/controlled-producer-report.xml"))
    parser.add_argument("--evidence", type=Path, default=Path("var/ci/controlled-producer.json"))
    parser.add_argument("--state", type=Path, default=Path("var/ci/intake.json"))
    options = parser.parse_args()
    if options.phase == "producer":
        producer(options.report, options.evidence)
        return
    started = time.monotonic()
    base_url = os.environ.get("XORGATE_URL", "http://127.0.0.1:8088").rstrip("/")
    client = McpClient(base_url + "/mcp", os.environ["XORGATE_MCP_TOKEN"])
    client.initialize()
    check_discovery(client)
    browser = IntakeBrowser(base_url)
    if options.phase == "queued":
        state = queued(client, browser, options.report, json.loads(options.evidence.read_text()))
    else:
        state = json.loads(options.state.read_text())
        if options.phase == "complete":
            complete(client, browser, state, options.report.read_bytes())
        else:
            check_views(client, browser, state, completed=True)
            require(client.tool("xorgate_observations_list", scope(state) | {"operationId": state["operation"]["operation_id"]})["items"] == state["observations"],
                    "Container restart changed normalized observation identities")
            check_original(client, browser, state, options.report.read_bytes())
    state.setdefault("timings_seconds", {})[options.phase] = round(time.monotonic() - started, 3)
    options.state.parent.mkdir(parents=True, exist_ok=True)
    options.state.write_text(json.dumps(state, indent=2) + "\n")
    print(f"Report intake {options.phase}: native UI, MCP and CLI agree; actual original bytes verified")


if __name__ == "__main__":
    main()
