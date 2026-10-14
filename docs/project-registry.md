# Project registry and execution provenance

WO-001 introduces stored project metadata and immutable imported execution context. It does not run the managed project, fetch its repository or ingest a report. Xorgate runs in Docker; the registered project can use any language, framework or deployment environment.

The operator UI starts at `/projects`. Registration, editing, execution recording and queries all use `App\Service\ProjectRegistry`, also exposed by MCP and CLI. Native Symfony forms use UXor's installed `form-theme` recipe, with successful submissions redirecting with HTTP 303 and invalid forms rendering with HTTP 422. Tables use stored rows and bounded cursor navigation; an empty registry has an explicit empty state.

## Commands, queries and identity

Every mutation requires a caller-owned UUID `request_id`. A project receives a separate generated UUID `project_id`; an execution receives an `execution_id`. Display names and repository URLs are editable metadata, and two registrations can legitimately have the same URL. Editing requires the current positive `expected_version` and preserves project identity.

Replaying an identical command returns its original response, even after a later project edit. Reusing the request UUID with different inputs, another action or another project reports `conflict`. Query the project separately to obtain its current version. Request UUIDs belong to one installation-wide namespace. Omitted and explicitly null execution repository fields remain distinct command inputs: omission copies the project metadata when the execution is first created, while null records unknown metadata.

| MCP tool | CLI command | Purpose |
| --- | --- | --- |
| `xorgate_project_register` | `xorgate:project:register` | Register project metadata |
| `xorgate_project_update` | `xorgate:project:update` | Replace metadata at an expected version |
| `xorgate_project_get` | `xorgate:project:get` | Read the current project |
| `xorgate_project_list` | `xorgate:project:list` | Read a bounded project page |
| `xorgate_execution_record` | `xorgate:execution:record` | Store immutable execution provenance |
| `xorgate_execution_get` | `xorgate:execution:get` | Read an execution within its project |
| `xorgate_execution_list` | `xorgate:execution:list` | Read a bounded execution page within a project |

Ask MCP `tools/list` for the current argument schemas. CLI mutations accept a JSON object through `--input <file>` or standard input (`--input=-`, also the default when omitted), limited to 64 KiB and nesting depth 16. Queries accept UUID arguments; list commands accept `--limit` and `--cursor`. Service errors return bounded JSON with `accepted: false` and an `error.code` of `invalid_input`, `not_found`, `conflict`, `capacity` or `unavailable`; CLI errors have a nonzero exit status. MCP schema validation failures use the protocol's invalid-parameters error.

Project input contains `request_id`, `name`, nullable `repository_url`, nullable `repository_identity` and a `runtimes` array. `schema_version` is optional and, when supplied, must be 1. Names have a maximum of 120 characters, repository identity 160, repository URL 2,048 and runtime declarations eight strings of at most 80 characters. Unknown fields are rejected. Repository identity is an opaque, caller-declared identity; it is not remotely verified. Repository URLs cannot contain embedded passwords, query strings or fragments.

## Execution context

An execution belongs to exactly one project. Its context is stored at creation and is unaffected by later project edits. Optional metadata is represented as null, and unknown runtimes as an empty array. Revisions and workspace fingerprints are opaque values; Xorgate does not invent hashes or infer a tested revision from a PR head.

Prefer JSON objects for provenance groups. The pinned MCP SDK decodes an empty object as an empty PHP array, so schemas also accept empty arrays as equivalent unknown groups. Nonempty arrays are rejected.

| Object | Fields | Maximum string lengths |
| --- | --- | --- |
| `source` | `repository_identity`, `repository_url`, `tested_revision`, `workspace_fingerprint`, `pr_head_revision`, `pr_base_revision`, `pr_merge_revision` | Identity 160, URL 2,048, remaining fields 256 |
| `environment` | `name`, `runtimes`, `configuration_ref` | Name 120, configuration reference 256; runtimes eight × 80 |
| `producer` | `name`, `version` | 120 each |
| `native_identity` | `provider`, `run_id`, `run_attempt`, `job_id`, `shard` | Provider 80, other strings 160; attempt a positive integer or null |

`observed_at` is an optional ISO 8601 timestamp with a timezone; `created_at` is Xorgate's receipt timestamp. Provider run ID and provider attempt are separate, so attempts at the same tested revision receive distinct execution IDs. Job and shard also remain separate. Case retries belong to future imported observations; they are not represented as provider attempts.

## WO-002 intake handoff

The migration owns `managed_project`, `execution_record` and `registry_request`; they are excluded from ORM schema generation. The ledger stores a command hash and its original response in the same database transaction as the mutation. All public responses use `schema_version: 1`.

`getExecution(projectId, executionId)` performs a project-scoped lookup. Intake must additionally call `assertExecutionContext(projectId, executionId, expectedSource)` with the report's declared source fields before attaching evidence. At least one supplied source field must remain non-null after normalization; null-only or blank assertions cannot satisfy the guard. Any mismatch, including a supplied revision against unknown stored metadata, reports a conflict. A request-id replay with a different tested revision also conflicts. Do not attach by repository URL or revision alone, rewrite the execution snapshot or flatten provider attempts and case retries into one value.

Project and execution lists return `{schema_version, items, next_cursor, limit}`. The limit is 1–100 (default 25); rows are ordered by UUID and the cursor is the last UUID on the previous page. Execution cursors must belong to that project. There is no invented total, coverage or test count. A cursor denotes position in the current registry, not a frozen snapshot.

## Admission and verification

Installation-wide defaults allow 1,000 projects, 10,000 executions, 20,000 successful command ledger entries and 120 new commands per rolling minute. A transactional advisory lock serializes admission; replays remain available at capacity. Configure positive `XORGATE_REGISTRY_MAX_PROJECTS`, `XORGATE_REGISTRY_MAX_EXECUTIONS`, `XORGATE_REGISTRY_MAX_REQUESTS` and `XORGATE_REGISTRY_MAX_PER_MINUTE` values and restart application processes after changing them. Capacity responses include the current usage and limits. This slice retains project, execution and idempotency records; broader retention, retirement and caller-specific policy remain WO-005.

`bin/check` runs real database contract tests. Docker CI additionally reproduces the pinned Toolkit installation, exercises UI/MCP/CLI agreement and verifies records after a container restart. The smoke state contains only disposable test identifiers and observed responses under `var/ci/`.
