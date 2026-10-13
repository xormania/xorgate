#!/usr/bin/env python3
"""Verify the Docker registry through native forms, HTTP MCP and CLI.

The register/persist phases bracket the application's external container restart.
Only durable records and their public request inputs are saved, never credentials.
"""

import argparse
import json
import os
import subprocess
import uuid
from html.parser import HTMLParser
from http.cookiejar import CookieJar
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode, urljoin
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, Request, build_opener

from foundation import McpClient, read_response, require


REGISTRY_TOOLS = {
    "xorgate_project_register", "xorgate_project_update", "xorgate_project_get", "xorgate_project_list",
    "xorgate_execution_record", "xorgate_execution_get", "xorgate_execution_list",
}


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


class RegistryPage(HTMLParser):
    def __init__(self):
        super().__init__()
        self.registry_state = None
        self.project_id = None
        self.execution_id = None
        self.form_values = {}
        self.form_action = None
        self.form_id = None
        self._in_form = False

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        self.registry_state = attrs.get("data-xorgate-project-registry", self.registry_state)
        self.project_id = attrs.get("data-xorgate-project-id", self.project_id)
        self.execution_id = attrs.get("data-xorgate-execution-id", self.execution_id)
        if tag == "form":
            self._in_form = True
            self.form_action = attrs.get("action", "")
            self.form_id = attrs.get("id")
        if self._in_form and tag == "input" and attrs.get("name"):
            self.form_values[attrs["name"]] = attrs.get("value", "")

    def handle_endtag(self, tag):
        if tag == "form":
            self._in_form = False


class BrowserSession:
    def __init__(self, base_url):
        self.base_url = base_url
        self.opener = build_opener(HTTPCookieProcessor(CookieJar()), NoRedirect())

    def request(self, path, fields=None):
        url = urljoin(self.base_url + "/", path)
        data = urlencode(fields).encode() if fields is not None else None
        request = Request(url, data=data, headers={"Accept": "text/html"})
        try:
            response = self.opener.open(request, timeout=20)
        except HTTPError as error:
            response = error
        with response:
            status = response.code
            headers = response.headers
            content = read_response(response).decode("utf-8")
        parsed = RegistryPage()
        parsed.feed(content)
        return status, headers, content, parsed


def cli(command, *arguments, input_value=None, success=True):
    result = subprocess.run(
        ["docker", "compose", "exec", "-T", "php", "php", "bin/console", command, *arguments],
        input=json.dumps(input_value) if input_value is not None else None,
        capture_output=True, text=True, timeout=30,
    )
    require((result.returncode == 0) is success, f"CLI {command} returned an unexpected exit status")
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError(f"CLI {command} did not return JSON") from error


def check_discovery(client):
    names = set()
    cursor = None
    for _ in range(10):
        listing = client.request("tools/list", {"cursor": cursor} if cursor else {})
        names.update(tool["name"] for tool in listing.get("tools", []))
        cursor = listing.get("nextCursor")
        if not cursor:
            break
    require(not cursor and REGISTRY_TOOLS <= names, "MCP project/execution tools were not all discovered")


def require_error(value, code):
    require(value.get("schema_version") == 1 and value.get("accepted") is False,
            "Registry rejection is not a structured versioned response")
    require(value.get("error", {}).get("code") == code, f"Expected registry error {code}")
    require(value.get("error", {}).get("message"), "Registry rejection omitted a useful message")


def require_schema_rejection(client, name, arguments):
    try:
        client.request("tools/call", {"name": name, "arguments": arguments})
    except RuntimeError as error:
        require("protocol error" in str(error) and "-32602" in str(error),
                "Malformed MCP object did not receive an invalid-parameters response")
    else:
        raise RuntimeError("MCP accepted a nonempty array in place of a metadata object")


def get_project(client, project_id):
    return client.tool("xorgate_project_get", {"projectId": project_id})


def get_execution(client, project_id, execution_id):
    return client.tool("xorgate_execution_get", {"projectId": project_id, "executionId": execution_id})


def check_project_page(browser, project):
    status, headers, content, page = browser.request("/projects/" + project["project_id"])
    require(status == 200, "Project detail did not render")
    require(page.project_id == project["project_id"], "UI displayed a different project identity")
    require(project["name"] in content, "UI did not display the registered project name")
    require("Content-Security-Policy" in headers, "Project page lost the application CSP")


def register(client, browser):
    empty = client.tool("xorgate_project_list", {"limit": 2})
    require(empty.get("items") == [] and empty.get("next_cursor") is None,
            "Registry smoke expects a clean installation with no project fixtures")
    status, _, _, page = browser.request("/projects")
    require(status == 200 and page.registry_state == "empty", "UI did not render the actual empty project state")

    status, _, _, page = browser.request("/projects/new")
    require(status == 200, "Native project form did not render")
    require(page.form_id == "project", "Project form does not have its stable native form identity")
    require(page.form_values.get("project[_token]"), "Project form omitted CSRF protection")
    request_id = page.form_values.get("project[request_id]")
    require(request_id, "Project form omitted its idempotency key")
    fields = dict(page.form_values)
    fields.update({
        "project[name]": "",
        "project[repository_url]": "https://example.test/ci/mixed.git",
        "project[repository_identity]": "example:repo:mixed",
        "project[runtimes]": "Rust\nTypeScript",
    })
    status, _, _, _ = browser.request(page.form_action or "/projects/new", fields)
    require(status == 422, "Invalid native form did not return Turbo-compatible HTTP 422")
    require(client.tool("xorgate_project_list").get("items") == [], "Invalid form wrote a project")

    forged = dict(fields)
    forged["project[name]"] = "Forged request"
    forged["project[_token]"] = "forged"
    status, _, _, _ = browser.request("/projects/new", forged)
    require(status == 422, "A forged CSRF token was not rejected")
    require(client.tool("xorgate_project_list").get("items") == [], "CSRF rejection wrote a project")

    fields["project[name]"] = "Smoke mixed runtime"
    status, headers, _, _ = browser.request("/projects/new", fields)
    require(status == 303 and headers.get("Location"), "Valid native form did not redirect with HTTP 303")
    status, _, _, shown = browser.request(headers["Location"])
    require(status == 200 and shown.project_id, "Created project redirect lost the stored identity")
    mixed = get_project(client, shown.project_id)
    require(mixed.get("runtimes") == ["Rust", "TypeScript"], "Mixed-language form registration changed runtimes")
    mixed_input = {
        "request_id": request_id, "name": "Smoke mixed runtime",
        "repository_url": "https://example.test/ci/mixed.git", "repository_identity": "example:repo:mixed",
        "runtimes": ["Rust", "TypeScript"],
    }
    require(client.tool("xorgate_project_register", {"input": mixed_input}) == mixed,
            "MCP replay disagreed with the native form registration")
    require(cli("xorgate:project:get", mixed["project_id"]) == mixed,
            "CLI and MCP disagreed on the native form project")

    python_input = {
        "request_id": str(uuid.uuid4()), "name": "Smoke Python runtime",
        "repository_url": "https://example.test/ci/python.git", "repository_identity": "example:repo:python",
        "runtimes": ["Python 3.13"],
    }
    python = cli("xorgate:project:register", "--input", "-", input_value=python_input)
    require(get_project(client, python["project_id"]) == python, "MCP could not see the CLI-created project")
    require(client.tool("xorgate_project_register", {"input": python_input}) == python,
            "MCP replay changed the CLI-created project")
    changed = dict(python_input, name="Conflicting registration")
    require_error(client.tool("xorgate_project_register", {"input": changed}), "conflict")
    require_error(cli("xorgate:project:register", "--input", "-", input_value=changed, success=False), "conflict")

    status, _, _, execution_form = browser.request(f"/projects/{mixed['project_id']}/executions/new")
    require(status == 200 and execution_form.form_id == "execution", "Native execution form did not render")
    invalid_execution = dict(execution_form.form_values)
    invalid_execution["execution[native_identity][run_attempt]"] = "1.9"
    status, _, _, _ = browser.request(execution_form.form_action, invalid_execution)
    require(status == 422, "Native execution form accepted a fractional provider run attempt")
    require(client.tool("xorgate_execution_list", {"projectId": mixed["project_id"]}).get("items") == [],
            "Invalid native execution metadata wrote a record")

    first_input = execution_input(1)
    first = client.tool("xorgate_execution_record", {"projectId": mixed["project_id"], "input": first_input})
    require(client.tool("xorgate_execution_record", {"projectId": mixed["project_id"], "input": first_input}) == first,
            "Execution replay changed its stored provenance")
    second = cli("xorgate:execution:record", mixed["project_id"], "--input", "-", input_value=execution_input(2))
    require(first["execution_id"] != second["execution_id"], "Provider attempts collapsed into one execution")
    require(first["source"]["tested_revision"] == second["source"]["tested_revision"], "Attempt test did not use the same tested revision")
    require(first["native_identity"]["run_attempt"] == 1 and second["native_identity"]["run_attempt"] == 2,
            "Provider attempts were not preserved independently")
    require(first["source"]["repository_identity"] == mixed["repository_identity"], "Execution lost its project repository snapshot")
    require(first["source"]["pr_head_revision"] != first["source"]["pr_merge_revision"], "PR revisions were flattened")
    changed_execution = dict(first_input, source=dict(first_input["source"], tested_revision="changed-tested-revision"))
    require_error(client.tool("xorgate_execution_record", {"projectId": mixed["project_id"], "input": changed_execution}), "conflict")
    require_error(get_execution(client, python["project_id"], first["execution_id"]), "not_found")
    require_error(get_project(client, "malformed-project-id"), "invalid_input")

    unknown_input = {"request_id": str(uuid.uuid4()), "source": {}, "environment": {}, "producer": {}, "native_identity": {}}
    unknown = client.tool("xorgate_execution_record", {"projectId": python["project_id"], "input": unknown_input})
    for field in ("tested_revision", "workspace_fingerprint", "pr_head_revision", "pr_base_revision", "pr_merge_revision"):
        require(field in unknown["source"] and unknown["source"][field] is None, "Unknown source metadata was fabricated or omitted")
    require(unknown["environment"] == {"name": None, "runtimes": [], "configuration_ref": None}, "Empty environment object lost explicit unknowns")
    require(unknown["producer"] == {"name": None, "version": None}, "Empty producer object lost explicit unknowns")
    require(all(value is None for value in unknown["native_identity"].values()) and unknown["observed_at"] is None,
            "Empty native identity object fabricated provider or observation metadata")
    require_schema_rejection(client, "xorgate_execution_record", {
        "projectId": python["project_id"], "input": {"request_id": str(uuid.uuid4()), "source": ["unexpected"]},
    })
    require(len(client.tool("xorgate_execution_list", {"projectId": python["project_id"]}).get("items", [])) == 1,
            "Schema-rejected execution input wrote a record")

    first_page = client.tool("xorgate_project_list", {"limit": 1})
    require(first_page.get("limit") == 1 and len(first_page.get("items", [])) == 1 and first_page.get("next_cursor"),
            "Project list did not enforce a bounded first page")
    next_page = client.tool("xorgate_project_list", {"limit": 1, "cursor": first_page["next_cursor"]})
    require(len(next_page.get("items", [])) == 1 and next_page.get("next_cursor") is None,
            "Project keyset page did not reach the remaining project")
    require(cli("xorgate:project:list", "--limit", "1", "--cursor", first_page["next_cursor"]) == next_page,
            "CLI and MCP project pagination disagree")
    first_page = client.tool("xorgate_execution_list", {"projectId": mixed["project_id"], "limit": 1})
    require(len(first_page.get("items", [])) == 1 and first_page.get("next_cursor"), "Execution list is not bounded")
    next_page = client.tool("xorgate_execution_list", {"projectId": mixed["project_id"], "limit": 1, "cursor": first_page["next_cursor"]})
    require(len(next_page.get("items", [])) == 1 and next_page.get("next_cursor") is None, "Execution keyset page lost the other attempt")
    require_error(cli("xorgate:project:list", "--limit", "101", success=False), "invalid_input")
    return {"projects": [mixed, python], "executions": [first, second, unknown]}


def execution_input(attempt):
    return {
        "request_id": str(uuid.uuid4()),
        "source": {"tested_revision": "tested-merge-revision", "workspace_fingerprint": "workspace:smoke",
                   "pr_head_revision": "pr-head-revision", "pr_base_revision": "pr-base-revision", "pr_merge_revision": "pr-merge-revision"},
        "environment": {"name": "smoke-linux", "runtimes": ["Rust", "TypeScript"], "configuration_ref": "ci/smoke"},
        "producer": {"name": "registry-smoke", "version": "1"},
        "native_identity": {"provider": "example-ci", "run_id": "smoke-run", "run_attempt": attempt, "job_id": "linux", "shard": "1"},
        "observed_at": "2026-10-13T12:34:56+00:00",
    }


def verify_records(client, browser, state):
    for project in state["projects"]:
        require(get_project(client, project["project_id"]) == project, "Stored project changed or disappeared")
        require(cli("xorgate:project:get", project["project_id"]) == project, "CLI project lookup changed or disappeared")
        check_project_page(browser, project)
    for execution in state["executions"]:
        project_id, execution_id = execution["project_id"], execution["execution_id"]
        require(get_execution(client, project_id, execution_id) == execution, "Stored execution changed or disappeared")
        require(cli("xorgate:execution:get", project_id, execution_id) == execution, "CLI execution lookup disagrees")
        status, _, content, page = browser.request(f"/projects/{project_id}/executions/{execution_id}")
        require(status == 200 and page.execution_id == execution_id, "UI execution context changed or disappeared")
        if execution["source"]["tested_revision"] is not None:
            require(execution["source"]["tested_revision"] in content, "UI omitted the actual tested revision")
    status, _, _, page = browser.request("/projects")
    require(status == 200 and page.registry_state == "populated", "UI lost the persisted project list")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=("register", "persist"))
    parser.add_argument("--url", default=os.environ.get("XORGATE_URL", "http://127.0.0.1:8088"))
    parser.add_argument("--state", type=Path, default=Path("var/ci/registry.json"))
    options = parser.parse_args()
    token = os.environ.get("XORGATE_MCP_TOKEN", "")
    require(token, "Set XORGATE_MCP_TOKEN to the running application's bearer credential")
    base_url = options.url.rstrip("/")
    client = McpClient(base_url + "/mcp", token)
    client.initialize()
    check_discovery(client)
    browser = BrowserSession(base_url)
    if options.phase == "register":
        state = register(client, browser)
    else:
        state = json.loads(options.state.read_text())
    verify_records(client, browser, state)
    options.state.parent.mkdir(parents=True, exist_ok=True)
    options.state.write_text(json.dumps(state, indent=2) + "\n")
    print(f"Registry {options.phase} verified; two projects and distinct provider attempts")


if __name__ == "__main__":
    main()
