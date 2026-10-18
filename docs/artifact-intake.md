# Artifact and report intake

Xorgate imports existing report bytes for a registered project and immutable execution. Each submission requires at least one known, non-null source assertion matching that execution. Intake preserves the original bytes and returns a durable operation handle. Messenger parses the report after the client disconnects. Submitting a report does not run tests or change an execution's source context.

The web upload, MCP tools and Console commands use `ReportIntake`. Managed projects need no PHP runtime, Docker image or report producer installed inside Xorgate. Installed adapters read the documented JUnit subset, Playwright JSON reports, UXor's derived Playwright files, existing Jev assessments and plain text logs; original bytes retain native extensions that an adapter cannot normalize.

## Request identity and source context

Every submission includes a caller-supplied `request_id` UUID and `source` assertions. `schema_version` is 1 when supplied. Identity is scoped to project UUID, execution UUID, report role and request UUID. Repository URLs and client filenames are metadata rather than storage paths or identity keys.

An identical replay returns the current operation state with the same operation and artifact IDs. Changed bytes or changed normalized request metadata under that identity return a conflict. Equal digests alone do not authorize attaching another project's or execution's artifact. Supplied source fields are assertions against `ProjectRegistry::assertExecutionContext()` and must match the execution's immutable source snapshot. At least one source field must be known and non-null; explicit null alongside known fields asserts an unknown value. An execution whose entire source context is unknown cannot accept a report through this contract and receives a validation error. Every normalized observation remains scoped to the execution and traceable to its original artifact.

The request's format selects the adapter. Report role distinguishes multiple intended reports for the same execution; it is not a test outcome. Producer and environment facts already belong to the immutable execution. Intake preserves unknown producer metadata rather than guessing it from a filename.

| Metadata field | Contract |
| --- | --- |
| `schema_version` | Optional integer 1 |
| `request_id` | Required UUID idempotency key |
| `report_role` | Optional string, at most 80 characters; default `junit` |
| `format` | Optional string, at most 80 characters; default `junit` |
| `filename` | Optional display string, at most 255 characters, or null |
| `media_type` | Optional display string, at most 120 characters, or null |
| `source` | Required object with at least one known matching field from the execution's source snapshot |

Allowed source fields are `repository_identity` (160 characters), `repository_url` (2,048), `tested_revision`, `workspace_fingerprint`, `pr_head_revision`, `pr_base_revision` and `pr_merge_revision` (256 each). These assertions do not modify the execution. Request objects reject unknown fields.

## Durable operation and parser outcome

Admission checks persistent operation, artifact byte, queue and rolling-minute budgets atomically across interfaces. The shared HTTP token identifies an installation client, not a separate person. Existing retries remain inspectable at capacity. A capacity rejection returns safe structured data without admitting new durable work.

Original bytes are staged in owned storage and finalized with immutable digest and size metadata. Database commits and file writes are separate failure boundaries. Recovery reconciles those states before reporting successful intake. Messenger delivery may repeat; one intended import must produce one set of observations. Queued or running operations are protected from retention cleanup.

Operation progress and parser outcome are separate fields. A parser can observe passed, failed, errored or skipped cases without establishing an execution verdict. `parsed`, `empty` and `partial` describe the adapter's evidence result. Missing bytes, malformed XML, truncated XML and unsupported input remain distinguishable. A valid empty suite is an empty observation, not proof that verification completed. Parser name/version, observed case counts, native declared counts and bounded limitations accompany normalized evidence.

An operation contains `schema_version`, `operation_id`, `correlation_id`, `project_id`, `execution_id`, `request_id`, `report_role`, `format`, `status`, `parser_outcome`, `valid_document`, `artifact`, `parser`, `source_context`, `created_at`, `started_at`, `completed_at`, `summary`, `limitations` and `error`. `correlation_id` equals the operation ID. `source_context` is the immutable execution record, including its source, environment, producer and native identity. Timestamps are explicit, with null before a stage occurs.

The operation also contains `timings`, `attempt_count`, `annotation_count` and `links`. `timings` holds `queued_seconds` (created to the latest claim), `processing_seconds` (latest claim to completion) and `parse_ms` (worker wall time from claim to the completing statement, covering parsing and observation writes, stored as `processing_ms`). Each value is null until its stage has happened or when it was not measured; redelivery after an expired lease restarts the processing clock and adds the earlier attempt to the queued time. `attempt_count` and `annotation_count` are null before parsing. `links` is null unless the import produced annotations, and otherwise counts them as `{linked, unmatched, ambiguous, not_applicable}`.

`status` is `queued`, `running`, `completed` or `failed`. `parser_outcome` is null before parsing, or `parsed`, `empty`, `partial`, `malformed`, `truncated`, `unsupported`, `oversized`, `missing` or `corrupt`. `valid_document` distinguishes a valid empty suite from an input containing no XML document. `summary` contains cases, suites, observed counts and native declared counts. `error` is null or a bounded `{code, message}` diagnostic, never a filesystem path or raw XML parser exception.

Original artifact availability is checked independently of stored observations. Missing bytes, digest mismatch and deliberate retention expiry stay visible after parsing; none becomes a passing or empty test result. Source provenance and normalized observations remain inspectable through their durable identifiers after a client disconnect or container restart.

An artifact contains `schema_version`, `artifact_id`, `project_id`, `execution_id`, `filename`, `media_type`, `sha256`, `bytes`, `created_at`, `expired_at` and `availability`. Availability contains `status`, `available` and `integrity_checked`. Direct artifact and operation retrieval verifies original bytes, returning `available` with both flags true only after digest verification. Listing performs bounded metadata checks and can return `present` with both flags false: bytes appear present, but their integrity has not been checked by that listing. Other statuses are `missing`, `corrupt`, `expired` and `unavailable`. Metadata exposes no storage path. A chunk read adds `content_base64`, `offset`, `returned_bytes`, `next_offset` and `eof`; reads, parsing and downloads consume verified descriptors.

## Bounded transport

MCP accepts a small report as base64, never a server filesystem path. Larger reports use the web upload or a CLI byte stream and return opaque artifact and operation handles. Artifact queries return metadata; bounded reads return base64 so binary bytes cannot corrupt JSON. Caller-selected offsets and lengths are validated, and responses contain only the requested bounded chunk.

CLI submission accepts bounded JSON metadata separately from report bytes. Use `--input=metadata.json` and `--file=report.xml`, or `--file=-` to stream report bytes through standard input. Local files can use absolute or relative paths; stream-wrapper addresses, symlink paths and nonregular files are rejected. Standard input may be a pipe. Do not select standard input for both metadata and bytes. Symfony Console requires `--input=-` or `--file=-` as a single argument when selecting a dash value.

Lists use UUID keyset cursors and explicit record limits. Operation status does not inline an entire report or every parsed case. Artifact IDs are opaque UUIDs, not paths. Retrieval is scoped to the project and execution and does not follow caller-supplied storage locations.

| Boundary | Default or fixed limit |
| --- | --- |
| CLI metadata | Fixed 65,536 bytes, JSON object, nesting depth 16 |
| MCP report content | Fixed 65,536 decoded bytes; at most 87,384 base64 characters |
| Artifact read | Fixed maximum 32,768 bytes per chunk; byte offset 0 through 2,147,483,647 |
| Log window | Fixed maximum 32,768 bytes per window; same offset range |
| Attempt annotation references | At most 20 per attempt or case, with the total count |
| Query page | Default 25 records, minimum 1, maximum 100 |
| Stream/upload | Configurable, default 8,388,608 bytes |
| Retained artifact storage | Configurable, default 536,870,912 bytes |
| Pending operations | Configurable, default 100 |
| Retained operations | Configurable, default 1,000 |
| New admissions per rolling minute | Configurable, default 30 |
| Artifact retention | Configurable, default 604,800 seconds (seven days) |
| Operation metadata retention | Configurable, default 1,209,600 seconds (fourteen days) |

Capacity exposes the active limits and measured usage. Cleanup is bounded and preserves active references; deliberate artifact expiration remains visible until eligible metadata is pruned.

An identical replay remains historical while its operation record is retained, including after deliberate byte expiration. Once eligible operation metadata is pruned, its request identity is no longer remembered; submitting it again admits new work under the current limits. Durable request identity is therefore bounded by record retention.

Configure positive `XORGATE_INTAKE_MAX_PENDING`, `XORGATE_INTAKE_MAX_RETAINED`, `XORGATE_INTAKE_MAX_PER_MINUTE`, `XORGATE_INTAKE_MAX_UPLOAD_BYTES` and `XORGATE_INTAKE_MAX_STORAGE_BYTES` in `.env.local`. Artifact and record lifetimes use `XORGATE_INTAKE_ARTIFACT_RETENTION_SECONDS` and `XORGATE_INTAKE_RECORD_RETENTION_SECONDS`; records must outlive artifacts. Restart `php` and `worker` after changes. The web upload is also bounded by PHP's `upload_max_filesize` and `post_max_size` in `frankenphp/conf.d/10-app.ini`; raise those deliberately and rebuild the image when increasing the application limit.

Retained-reference bytes, complete blob bytes and private staging bytes are measured separately. `physical_bytes` and `physical_files` count distinct inodes across staging and blobs, so publishing a hardlink does not count its bytes twice. The physical byte limit and the `blob_files` inode limit apply to all staging and complete files, including leftovers from interrupted database commits. A private filesystem lock serializes each quota check and flushed write; waiting for incoming bytes holds no database transaction or filesystem admission lock. Active uploads hold a separate staging-file lease so cleanup cannot unlink them while they wait.

Identical bytes can share a blob while keeping distinct artifact identities and execution associations. Seekable uploads are checksummed before copying and can reuse a verified complete orphan at capacity. Retained request replays checksum incoming bytes without writing a new spool; changed bytes still conflict. Nonseekable streams stage within the same physical quota. Bounded cleanup removes unreferenced blobs and abandoned, unlocked staging files after a grace period. Expiration metadata and eligible operation pruning commit before independent byte deletion, which rechecks active references; failed deletion remains counted and is retried by later sweeps. Cleanup defers while the shared async or failed transport contains messages. Back up PostgreSQL and the artifact volume together while writes are stopped.

Messenger's report redelivery timeout is 300 seconds. The worker refreshes live delivery every five seconds; an operation's fresh processing lease yields a bounded recoverable retry instead of duplicate normalization. Interrupted work becomes eligible for recovery after its lease expires.

## Public interfaces

| MCP tool | Arguments and result |
| --- | --- |
| `xorgate_report_submit` | `projectId`, `executionId`, `input` metadata, `contentBase64`; returns the durable operation |
| `xorgate_report_submit_artifact` | `projectId`, `executionId`, `input`, `artifactId`; queues from a verified artifact already owned by the same scope |
| `xorgate_operation_get` | `projectId`, `executionId`, `operationId`; returns current operation and artifact state |
| `xorgate_evidence_list` | `projectId`, `executionId`, optional `limit` and `cursor`; returns operation summaries, including queued and failed imports |
| `xorgate_observations_list` | `projectId`, `executionId`, `operationId`, optional `limit` and `cursor`; returns normalized case observations |
| `xorgate_attempts_list` | `projectId`, `executionId`, `operationId`, optional `limit` and `cursor`; returns native attempts in report order with linked annotation references |
| `xorgate_annotations_list` | Same arguments; returns annotations produced by that import or linked to it as native evidence, with match status |
| `xorgate_artifact_get` | `projectId`, `executionId`, `artifactId`; returns artifact metadata |
| `xorgate_artifact_read` | Same scope and artifact ID, optional `offset` and `limit`; returns a verified base64 chunk |
| `xorgate_log_read` | Same scope and artifact ID, optional `offset` and `limit`; returns a verified bounded UTF-8 text window |
| `xorgate_intake_capacity` | No arguments; returns installation-wide limits and usage |

MCP tool discovery supplies current argument schemas. No intake tool accepts a filesystem path or fetches a remote URL. Larger source bytes enter through a CLI stream or manual upload; the returned artifact handle can then be used by an agent. A handle cannot be moved between executions.

CLI commands use the same services and JSON results:

```bash
docker compose exec -T php php bin/console xorgate:report:submit PROJECT_UUID EXECUTION_UUID --input=metadata.json --file=report.xml
docker compose exec -T php php bin/console xorgate:report:submit PROJECT_UUID EXECUTION_UUID --input=metadata.json --artifact-id=ARTIFACT_UUID
docker compose exec -T php php bin/console xorgate:operation:get PROJECT_UUID EXECUTION_UUID OPERATION_UUID
docker compose exec -T php php bin/console xorgate:evidence:list PROJECT_UUID EXECUTION_UUID --limit=25
docker compose exec -T php php bin/console xorgate:observations:list PROJECT_UUID EXECUTION_UUID OPERATION_UUID --limit=25
docker compose exec -T php php bin/console xorgate:attempts:list PROJECT_UUID EXECUTION_UUID OPERATION_UUID --limit=25
docker compose exec -T php php bin/console xorgate:annotations:list PROJECT_UUID EXECUTION_UUID OPERATION_UUID --limit=25
docker compose exec -T php php bin/console xorgate:log:read PROJECT_UUID EXECUTION_UUID ARTIFACT_UUID --offset=0 --limit=32768
docker compose exec -T php php bin/console xorgate:artifact:get PROJECT_UUID EXECUTION_UUID ARTIFACT_UUID
docker compose exec -T php php bin/console xorgate:artifact:read PROJECT_UUID EXECUTION_UUID ARTIFACT_UUID --offset=0 --limit=32768
docker compose exec -T php php bin/console xorgate:intake:capacity
docker compose exec -T php php bin/console xorgate:intake:cleanup
```

Paths in CLI commands belong to the application container; files in the checkout are mounted at `/app`. For a host report, pipe its bytes to `docker compose exec -T php php bin/console xorgate:report:submit PROJECT_UUID EXECUTION_UUID --input=/app/metadata.json --file=-`. No report bytes are printed as raw console text.

Query pages contain `{schema_version, items, next_cursor, limit}`. Every case observation includes `observation_id`, `operation_id`, `artifact_id`, `project_id` and `execution_id`, plus native case fields and parser version. Source and producer provenance can be retrieved from the operation's `source_context`.

Service errors return `{schema_version: 1, accepted: false, error: {code, message}}`, with code `invalid_input`, `not_found`, `conflict`, `capacity` or `unavailable`. Capacity errors also include `capacity`. CLI validation errors exit 2, other service errors exit 1, successful calls exit 0. A failed parser outcome remains an inspectable operation result rather than a transport error. MCP schema validation failures use the protocol's invalid-parameters error.

## JUnit adapter boundaries

The adapter preserves native case outcomes and suite declarations separately from observed cases. Duplicate case names stay distinct. It does not infer case retries or provider run attempts that the report does not supply. Provider run ID, run attempt, job and shard remain separate execution metadata from WO-001.

Adapter `junit`, version `1`, accepts unnamespaced `testsuites` and `testsuite` roots, nested suites and `testcase` elements. It reads native names, class/file/line metadata, time and direct `failure`, `error` or `skipped` children. Structural case keys keep duplicate names distinct. Native outcomes are `passed`, `failure`, `error`, `skipped` or `unknown` when conflicting or unsupported outcome structure prevents a single observation. Testcase `status`, `result` and `outcome` attributes fall outside version 1's subset: their presence yields unknown outcome and partial evidence with `native_outcome_attributes_not_normalized`; original values remain in the artifact. Suite declarations retain tests/failures/errors/skipped separately from observed counts; a mismatch makes the evidence partial.

Parsing disables external entities, DTD loading and network access. Parser defaults are 10,000 cases, 1,000 suites, 200,000 XML nodes, depth 64, eight diagnostic elements per case, 1,024 bytes per normalized attribute field, 4,096 bytes per diagnostic text field, 1,048,576 total normalized text bytes and 32 distinct limitation codes. Structural or aggregate limits fail explicitly as oversized. Native field truncation, ignored unknown outcome extensions and declaration/count mismatches produce partial evidence. Native `properties`, `system-out` and `system-err` remain in retained bytes and are not normalized; their omission is recorded as a limitation. No XML field becomes an executable path, HTML or external request.

Libxml can report the same document-end code for truncation and extra content. The adapter compares EOF coordinates for UTF-8 and UTF-16 inputs, reading at most 8 MiB for that diagnostic. Unknown encodings or unavailable coordinates produce invalid `malformed` evidence with `report_xml_end_ambiguous`, explicitly leaving the narrower classification unresolved. Invalid documents commit no case observations.

Fixture producer versions and source provenance are recorded in [the JUnit fixture guide](../tests/Fixtures/JUnit/README.md) and neighboring provenance JSON. The retained UXor JavaScript report comes from an actual execution with Node's built-in JUnit reporter; it is not seed data. The live acceptance also imports PHPUnit's report generated by the application's real check run. Synthetic malformed, truncated, empty and hostile XML edge fixtures are labelled as synthetic.

## Formats

| `format` | Reads | Produces |
| --- | --- | --- |
| `junit` | JUnit XML subset described above | Cases and suites |
| `playwright-json` | Playwright JSON reporter output (`results.json`) | One case per spec and project, one attempt per native result, suites, global errors |
| `playwright-failed-attempts` | UXor `tools/ci/playwright-summary.mjs` `failed-attempts.json`, schema 1 | `failed_attempt`, `global_error` and `producer_status` annotations |
| `playwright-durations` | UXor `durations.json`, schema 1 | `test_duration` annotations |
| `jev-assessment` | One UXor `tools/ci/jev-diagnosis.mjs` `assessment.json`, version 1 | One `jev_assessment` annotation |
| `text-log` | Any text log | No cases; byte, line, line-ending and UTF-8 measurements for bounded reading |

Every adapter is version `1`. The format and installed parser version are recorded at admission; a format with no installed adapter is admitted and finishes as `unsupported`. JSON inputs share the outcome vocabulary: whitespace-only input is `empty`, invalid JSON is `malformed`, valid JSON of another shape or schema/version is `unsupported`, input over limits is `oversized`. A valid document with nothing to record, such as an all-green failed-attempts file, an empty log or a Playwright report holding only global errors, is `empty` and is still committed with its summary.

The upload form offers the installed formats. The web form, MCP and CLI use the same adapters.

## Playwright attempts

A Playwright case keeps `spec_id`, `test_id` (`<spec_id>@<project>`), `project`, `file`, `line`, `title_path`, `full_title`, `expected_status`, Playwright's own `native_outcome` (`expected`, `unexpected`, `flaky`, `skipped` or `unknown`), `expected_failure` and `attempt_count`. Each native result becomes an attempt with `index`, `retry`, `status`, `matches_expected`, timing, worker indexes, a bounded ANSI-stripped error excerpt with its location, bounded stdout/stderr excerpts with their original byte totals, and attachment metadata without bodies. A failed first attempt and its passing retry are both retained. An expected failure (`test.fail()`) is distinguishable from an unexpected one by `expected_status` and `matches_expected`. Neither changes an execution verdict.

The summary keeps declared and observed counts, attempt counts, `retry_history: native`, Playwright version, projects, `shard` (`{current, total}` or null), worker counts and `global_errors` (errors outside tests, at most 16 bounded items with the total). `shard_completeness` is `{status: unknown, reason: no_execution_expectation}`: Xorgate does not claim that every shard was imported without an authoritative expectation. The producer `rootDir` is never stored. Attempt lists use UUID keyset pages in report order; each attempt carries its case context and at most 20 linked annotation references (`annotation_id`, `operation_id`, `kind`, `match_status`) plus `annotation_count`.

## Derived annotations and linking

UXor's failed-attempts, durations and Jev assessment files are derived from native evidence. Their imports create annotations, never attempts or cases, so the same failure is not counted twice. Each annotation has `kind`, the `target` the file names (`spec_id`, `test_id`, `project`, `retry`, `file`, `line`, `title`), bounded `data` and a `match` object: `status`, `reason`, `target_operation_id`, `target_case_key`, `target_attempt_key` and `linked_at`.

| Kind | Links to | Rule |
| --- | --- | --- |
| `failed_attempt`, `jev_assessment` | One native attempt | Case `spec_id` (or `test_id`), `project` and attempt `retry` |
| `test_duration` | One native case | `project`, `file`, `line` and the full title path |
| `global_error`, `producer_status` | Nothing | Always `not_applicable` |

Candidates are completed `playwright-json` imports with parsed or partial evidence in the same project and execution. Exactly one match is `linked`. No completed native import is `unmatched` with reason `native_report_not_imported`; no matching attempt or case is `unmatched` with `attempt_not_found` or `case_not_found`; a target missing its identifying fields is `unmatched` with `target_incomplete`. Matches in several native imports are `ambiguous` with `multiple_native_imports`; several matches inside one import are `ambiguous` with `multiple_attempts` or `multiple_cases`. When retention prunes a native import, cleanup re-evaluates that execution's annotations against the remaining native imports; with none left they become `unmatched` with `native_report_pruned`.

Linking runs in the transaction that completes an import, under an execution-scoped lock. Completing an annotation import links its own annotations. Completing a native import re-evaluates the execution's attempt- and case-scoped annotations, so an annotation imported first becomes linked, and a second matching native import turns a link into an explicit ambiguity. Completed operations are final, so redelivery cannot insert attempts or annotations again; keys are unique per import.

Annotation lists for an import return the annotations it produced (`relation: produced`) and, for a native import, the annotations linked to it (`relation: linked_to_native`). Annotation import summaries contain `producer {status, reason}` (`valid`, `missing`, `invalid` or `not-reached` for UXor summary files), the file's own counts, run context, `shard_label`, runtime, `retry_history: derived` and annotation counts. A not-reached or missing producer status yields one visible `producer_status` annotation, so a setup failure that prevented tests is not an empty pass.

A Jev assessment is read from its retained file: Jev is never called and no credential is needed. Its annotation keeps version, status (`assessed`, `unavailable`, `disabled` or `not_assessed`), reason (for example `missing_credential`), method, likely cause, answer confidences, the selected bounded excerpt, run context and input digest, without request or response bodies. An assessment is advisory and never rewrites native attempt or case outcomes. A missing or malformed diagnosis file finishes with its own parser outcome and leaves native evidence unchanged.

## Bounded log windows

`xorgate_log_read`, `xorgate:log:read` and the log page read a verified window of any retained artifact, typically a `text-log` import. The result contains `schema_version`, `artifact`, `offset`, `returned_bytes`, `next_offset` (null at the end), `previous_offset` (null at offset 0), `eof`, `starts_mid_line`, `ends_mid_line`, `utf8_lossy` and `content_text`. Offsets are byte offsets into the original. A window does not end inside a UTF-8 sequence; it may be shorter than requested so the next window starts at a character boundary. Invalid UTF-8 is replaced with U+FFFD and flagged by `utf8_lossy`; terminal escapes are returned unchanged. The web page escapes the text and shows control characters as Unicode control pictures. Use `xorgate_artifact_read` for exact bytes. Routine operation, evidence and attempt results carry only bounded excerpts and counts, never a whole log.

Web pages: `/projects/{project}/executions/{execution}/intake/{operation}/attempts`, `…/intake/{operation}/annotations` and `…/artifacts/{artifact}/log?offset=N&limit=M`. The operation page shows timings, attempt and annotation counts, shard, shard completeness, producer status, link counts and errors outside tests, with links to these pages.

The Playwright fixtures in [tests/Fixtures/Playwright](../tests/Fixtures/Playwright/README.md) are controlled producer output and outputs of UXor's real tools on that output. Genuine UXor CI artifacts from a retry-recovered and a red run have not yet been imported; that acceptance remains open.
