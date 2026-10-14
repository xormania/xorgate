# Artifact and report intake

Xorgate imports existing report bytes for a registered project and immutable execution. Each submission requires at least one known, non-null source assertion matching that execution. Intake preserves the original bytes and returns a durable operation handle. Messenger parses the report after the client disconnects. Submitting a report does not run tests or change an execution's source context.

The web upload, MCP tools and Console commands use `ReportIntake`. Managed projects need no PHP runtime, Docker image or report producer installed inside Xorgate. The first adapter supports the documented JUnit subset below; original bytes retain native extensions that the adapter cannot normalize.

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

An operation contains `schema_version`, `operation_id`, `project_id`, `execution_id`, `request_id`, `report_role`, `format`, `status`, `parser_outcome`, `valid_document`, `artifact`, `parser`, `source_context`, `created_at`, `started_at`, `completed_at`, `summary`, `limitations` and `error`. `source_context` is the immutable execution record, including its source, environment, producer and native identity. Timestamps are explicit, with null before a stage occurs.

`status` is `queued`, `running`, `completed` or `failed`. `parser_outcome` is null before parsing, or `parsed`, `empty`, `partial`, `malformed`, `truncated`, `unsupported`, `oversized`, `missing` or `corrupt`. `valid_document` distinguishes a valid empty suite from an input containing no XML document. `summary` contains cases, suites, observed counts and native declared counts. `error` is null or a bounded `{code, message}` diagnostic, never a filesystem path or raw XML parser exception.

Original artifact availability is checked independently of stored observations. Missing bytes, digest mismatch and deliberate retention expiry stay visible after parsing; none becomes a passing or empty test result. Source provenance and normalized observations remain inspectable through their durable identifiers after a client disconnect or container restart.

An artifact contains `schema_version`, `artifact_id`, `project_id`, `execution_id`, `filename`, `media_type`, `sha256`, `bytes`, `created_at`, `expired_at` and `availability`. Availability is `{status, available}`, where status is `available`, `missing`, `corrupt`, `expired` or `unavailable`. Metadata exposes no storage path. A chunk read adds `content_base64`, `offset`, `returned_bytes`, `next_offset` and `eof`.

## Bounded transport

MCP accepts a small report as base64, never a server filesystem path. Larger reports use the web upload or a CLI byte stream and return opaque artifact and operation handles. Artifact queries return metadata; bounded reads return base64 so binary bytes cannot corrupt JSON. Caller-selected offsets and lengths are validated, and responses contain only the requested bounded chunk.

CLI submission accepts bounded JSON metadata separately from report bytes. Use `--input=metadata.json` and `--file=report.xml`, or `--file=-` to stream report bytes through standard input. Local files can use absolute or relative paths; stream-wrapper addresses, symlink paths and nonregular files are rejected. Standard input may be a pipe. Do not select standard input for both metadata and bytes. Symfony Console requires `--input=-` or `--file=-` as a single argument when selecting a dash value.

Lists use UUID keyset cursors and explicit record limits. Operation status does not inline an entire report or every parsed case. Artifact IDs are opaque UUIDs, not paths. Retrieval is scoped to the project and execution and does not follow caller-supplied storage locations.

| Boundary | Default or fixed limit |
| --- | --- |
| CLI metadata | Fixed 65,536 bytes, JSON object, nesting depth 16 |
| MCP report content | Fixed 65,536 decoded bytes; at most 87,384 base64 characters |
| Artifact read | Fixed maximum 32,768 bytes per chunk; byte offset 0 through 2,147,483,647 |
| Query page | Default 25 records, minimum 1, maximum 100 |
| Stream/upload | Configurable, default 8,388,608 bytes |
| Retained artifact storage | Configurable, default 536,870,912 bytes |
| Pending operations | Configurable, default 100 |
| Retained operations | Configurable, default 1,000 |
| New admissions per rolling minute | Configurable, default 30 |
| Artifact retention | Configurable, default 604,800 seconds (seven days) |
| Operation metadata retention | Configurable, default 1,209,600 seconds (fourteen days) |

Capacity exposes the active limits and measured usage. Cleanup is bounded and preserves active references; deliberate artifact expiration remains visible until eligible metadata is pruned.

Configure positive `XORGATE_INTAKE_MAX_PENDING`, `XORGATE_INTAKE_MAX_RETAINED`, `XORGATE_INTAKE_MAX_PER_MINUTE`, `XORGATE_INTAKE_MAX_UPLOAD_BYTES` and `XORGATE_INTAKE_MAX_STORAGE_BYTES` in `.env.local`. Artifact and record lifetimes use `XORGATE_INTAKE_ARTIFACT_RETENTION_SECONDS` and `XORGATE_INTAKE_RECORD_RETENTION_SECONDS`; records must outlive artifacts. Restart `php` and `worker` after changes. The web upload is also bounded by PHP's `upload_max_filesize` and `post_max_size` in `frankenphp/conf.d/10-app.ini`; raise those deliberately and rebuild the image when increasing the application limit.

Retained-reference bytes and complete physical blob bytes are measured separately, including unreferenced blobs left by an interrupted database commit. Identical bytes can share a blob while keeping distinct artifact identities and execution associations. A complete orphan can be reused on replay; bounded cleanup removes unreferenced blobs and abandoned staging files after a grace period. Cleanup defers while the shared async or failed transport contains messages. Back up PostgreSQL and the artifact volume together while writes are stopped.

## Public interfaces

| MCP tool | Arguments and result |
| --- | --- |
| `xorgate_report_submit` | `projectId`, `executionId`, `input` metadata, `contentBase64`; returns the durable operation |
| `xorgate_report_submit_artifact` | `projectId`, `executionId`, `input`, `artifactId`; queues from a verified artifact already owned by the same scope |
| `xorgate_operation_get` | `projectId`, `executionId`, `operationId`; returns current operation and artifact state |
| `xorgate_evidence_list` | `projectId`, `executionId`, optional `limit` and `cursor`; returns operation summaries, including queued and failed imports |
| `xorgate_observations_list` | `projectId`, `executionId`, `operationId`, optional `limit` and `cursor`; returns normalized case observations |
| `xorgate_artifact_get` | `projectId`, `executionId`, `artifactId`; returns artifact metadata |
| `xorgate_artifact_read` | Same scope and artifact ID, optional `offset` and `limit`; returns a verified base64 chunk |
| `xorgate_intake_capacity` | No arguments; returns installation-wide limits and usage |

MCP tool discovery supplies current argument schemas. No intake tool accepts a filesystem path or fetches a remote URL. Larger source bytes enter through a CLI stream or manual upload; the returned artifact handle can then be used by an agent. A handle cannot be moved between executions.

CLI commands use the same services and JSON results:

```bash
docker compose exec -T php php bin/console xorgate:report:submit PROJECT_UUID EXECUTION_UUID --input=metadata.json --file=report.xml
docker compose exec -T php php bin/console xorgate:report:submit PROJECT_UUID EXECUTION_UUID --input=metadata.json --artifact-id=ARTIFACT_UUID
docker compose exec -T php php bin/console xorgate:operation:get PROJECT_UUID EXECUTION_UUID OPERATION_UUID
docker compose exec -T php php bin/console xorgate:evidence:list PROJECT_UUID EXECUTION_UUID --limit=25
docker compose exec -T php php bin/console xorgate:observations:list PROJECT_UUID EXECUTION_UUID OPERATION_UUID --limit=25
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

Adapter `junit`, version `1`, accepts unnamespaced `testsuites` and `testsuite` roots, nested suites and `testcase` elements. It reads native names, class/file/line metadata, time and direct `failure`, `error` or `skipped` children. Structural case keys keep duplicate names distinct. Native outcomes are `passed`, `failure`, `error`, `skipped` or `unknown` when conflicting or unsupported outcome structure prevents a single observation. Suite declarations retain tests/failures/errors/skipped separately from observed counts; a mismatch makes the evidence partial.

Parsing disables external entities, DTD loading and network access. Parser defaults are 10,000 cases, 1,000 suites, 200,000 XML nodes, depth 64, eight diagnostic elements per case, 1,024 bytes per normalized attribute field, 4,096 bytes per diagnostic text field, 1,048,576 total normalized text bytes and 32 distinct limitation codes. Structural or aggregate limits fail explicitly as oversized. Native field truncation, ignored unknown outcome extensions and declaration/count mismatches produce partial evidence. Native `properties`, `system-out` and `system-err` remain in retained bytes and are not normalized; their omission is recorded as a limitation. No XML field becomes an executable path, HTML or external request.

Fixture producer versions and source provenance are recorded in [the JUnit fixture guide](../tests/Fixtures/JUnit/README.md) and neighboring provenance JSON. The retained UXor JavaScript report comes from an actual execution with Node's built-in JUnit reporter; it is not seed data. The live acceptance also imports PHPUnit's report generated by the application's real check run. Synthetic malformed, truncated, empty and hostile XML edge fixtures are labelled as synthetic.
