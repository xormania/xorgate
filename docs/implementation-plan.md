# Xorgate implementation plan

Updated: 2026-10-18 (UTC)

Owner: xor

Purpose: sequence the next implementation work and make its completion reviewable.

Xorgate is a complete testing and CI management system for projects in any language or framework. The full scope includes persistent evidence and logs, system verification, diagnostics, CI control, distributed asynchronous execution, telemetry, easy installation and a migration path for existing projects. Symfony implements the application; UXor supplies its UI; MCP and the UI use the same application services.

This is a delivery plan. Domain names, status enums, schema fields and adapter boundaries below remain implementation proposals. Current explicit direction from xor takes precedence. The earlier kickoff's dedicated TypeScript evidence engine is not a prerequisite: start with shared Symfony services and introduce specialized tooling when a real integration warrants it.

## Current position

The foundation, project registry and durable report intake are merged. [PR #8](https://github.com/xormania/xorgate/pull/8) delivered WO-001, [PR #9](https://github.com/xormania/xorgate/pull/9) the native Symfony AI Mate, profiler/logging and Serena development setup, and [PR #10](https://github.com/xormania/xorgate/pull/10), merged on 2026-10-14, delivered WO-002: retained report bytes, durable intake operations, Messenger normalization, shared operator/agent interfaces and the intake portion of WO-005. The current baseline is `cccc8d6`.

[WO-003](https://github.com/xormania/xorgate/issues/4) is in progress in the current PR: Playwright JSON attempts with retry history, UXor failed-attempts/durations and Jev assessment annotations linked to exact attempts, setup-failure and global-error visibility, bounded log windows and import timings through the shared intake services, UI, MCP and CLI. Its fixtures are controlled Playwright runs and UXor's real summary/diagnosis tools applied to them. Acceptance with genuine UXor CI artifacts from a retry-recovered and a red run remains open; those artifacts could not be retrieved in the implementing session. Docker CI and browser interaction for this PR have not yet been observed.

| Capability | Observed baseline | Active/next extension |
| --- | --- | --- |
| Development installation | Symfony Docker/PHP 8.5, installed UXor recipes, native Mate CLI and pinned Serena | Installable application/worker artifacts and consumer integration |
| Persistence | PostgreSQL registry, immutable execution context, intake operations, artifact catalogue and normalized JUnit observations | Playwright attempts and linked derived annotations (WO-003) |
| Asynchronous work | Transactional probe/report dispatch, actual consumer, redelivery and lease recovery | Annotation linking in the completing transaction |
| Artifacts | Generic report intake, bounded retrieval, byte budgets and retention | Bounded text log windows |
| Human/agent access | Registry and report intake UI/MCP/CLI | Attempt, annotation and log-window pages, tools and commands |
| Operational signal | Correlated logs, intake capacity, opt-in Symfony profiler and Mate diagnostics | Queue/processing timings per import; later distributed telemetry |

The green workflow exercises Docker setup, configuration/platform checks, PostgreSQL integration, actual HTTP/MCP exchanges, worker processing, independently checked artifact bytes and persistence across container restart. Its source is [foundation.yml](../.github/workflows/foundation.yml). It does not establish browser interaction coverage, remote-worker readiness, backup/restore or the managed-project workflow.

The present probe is operational scaffolding. Keep its APIs working while introducing the managed-project domain. Runtime readiness does not assess another project's verification coverage.

## First useful product milestone

An operator or agent can register an existing project, import its real test evidence, inspect every attempt and relevant log, connect observations to a small versioned verification definition, and see applicable evidence and explicit gaps. The same records are available through UXor pages, MCP and CLI, survive restart, and can be exported and restored.

Use UXor for the first substantial evidence integration and at least one non-PHP producer to validate language independence. A mixed-language example checks that project structure and identity do not assume a single runtime. Existing project commands, tests and CI remain usable during adoption.

### Work orders opened for implementation

| Order | Work order | Visible result | Dependency |
| --- | --- | --- | --- |
| 1 | [WO-001: project registration and execution provenance](https://github.com/xormania/xorgate/issues/2) | Persistent project list/detail and honest source/execution context | Foundation |
| 2 | [WO-002: durable artifact intake and JUnit](https://github.com/xormania/xorgate/issues/3) | Asynchronous report import, retained original bytes and normalized observations | WO-001 |
| 3 | [WO-003: Playwright attempts, logs and Jev assessments](https://github.com/xormania/xorgate/issues/4) | Retry history, setup failures, bounded logs and existing diagnostic annotations | WO-002 |
| 4 | [WO-004: verification targets and applicable evidence](https://github.com/xormania/xorgate/issues/5) | Explained verification map, explicit gaps and export/restore proof | WO-001–003 |

[WO-005: admission limits and retention](https://github.com/xormania/xorgate/issues/7) is a cross-cutting operational item. Prefer implementing its admission contract alongside WO-001/002; complete it before shared/remote deployment. A shared bearer token does not identify individual callers.

Each issue contains concrete acceptance checks. WO-001 is merged; WO-002 builds on its actual identity/provenance contract. Subsequent schema-dependent work follows the reviewed intake contract. Keep remaining acceptance items open until observed evidence exists.

### Minimum model to establish in WO-001/002

| Concern | Required distinction |
| --- | --- |
| Project identity | Stable Xorgate identity; display name and repository URL are mutable metadata |
| Source context | Tested revision or workspace fingerprint; PR head/base/merge revisions kept separately when known |
| Execution identity | Local/provider identity, provider run attempt, job/shard and case retry are distinct |
| Environment | Declared and observed tool/runtime/configuration context, with explicit missing metadata |
| Intake operation | Caller identity, request identity, lifecycle, correlation, parser version and recovery state |
| Native observations | Native outcome/expected status, timestamps, duration and original evidence references |
| Artifact | Opaque identity, digest, size, media/report role, availability and retained original bytes |
| Definition/assessment | Immutable definition revision; assessment method/version and exact input references |

Define only the fields needed by a real import and the next work order. Specify public request/response versions and error behavior before exposing contracts. Return stable operation handles promptly; an MCP disconnect cannot determine work lifetime.

Idempotency has two checks: the same request identity with equivalent inputs reuses the operation, while changed inputs under that identity produce a visible conflict. Deduplicating identical bytes must not erase distinct executions. A repeated parser delivery must not duplicate observations.

The database and filesystem do not commit together. Intake must distinguish staged bytes, retained artifacts and committed observations, and reconcile interruption between these steps. Completion cannot be published before required bytes are available and normalization is committed. Represent artifact loss or integrity failure independently of the historical execution outcome.

### Evidence integration inputs

UXor was inspected at `dev` revision [`0f1a9585eeea89017544ce2639b676b38d393e22`](https://github.com/xormania/uxor/tree/0f1a9585eeea89017544ce2639b676b38d393e22). These are existing producers to integrate with:

| Input | Producer/reference | Treatment |
| --- | --- | --- |
| `playwright-results/results.json` | [Playwright configuration](https://github.com/xormania/uxor/blob/0f1a9585eeea89017544ce2639b676b38d393e22/playwright.config.ts) | Preserve native outcomes, expected status and every `results` entry |
| `failed-attempts.json`, `durations.json`, `summary.md` | [Playwright summary tool](https://github.com/xormania/uxor/blob/0f1a9585eeea89017544ce2639b676b38d393e22/tools/ci/playwright-summary.mjs) | Derived annotations linked to native attempts; avoid double counting |
| JUnit and failed-step evidence | [CI workflow](https://github.com/xormania/uxor/blob/0f1a9585eeea89017544ce2639b676b38d393e22/.github/workflows/ci.yml) | Preserve producer-specific limitations and failures outside test cases |
| Existing Jev assessment files | [Diagnosis action](https://github.com/xormania/uxor/blob/0f1a9585eeea89017544ce2639b676b38d393e22/.github/actions/jev-diagnosis/action.yml) | Display advisory findings and availability reasons, tied to original inputs |

Before implementing an adapter, inspect the actual producer schema and minimized files, record versions, and locate qualifying retained runs. Current UXor CI does not retain its original PHPUnit JUnit and its Playwright reporters emit JSON/HTML rather than JUnit. WO-002 retains an authentic JUnit report from UXor's native Node tool tests at the reviewed recipe revision; its origin is a local producer run, with no invented provider IDs. Xorgate Docker CI supplies native PHPUnit reports. See [fixture provenance](../tests/Fixtures/JUnit/README.md). Synthetic edge fixtures are useful boundary checks; they cannot substitute for real producer bytes. Actual UXor Playwright retry/red evidence remains WO-003 acceptance work.

Retain fixture provenance: repository, provider run and attempt, actual tested revision, artifact identity/digest, producer version and any minimization/redaction performed. Source fixtures must not contain credentials. If a historic artifact expired, find another qualifying run and retain its evidence; expiration is an observed availability state.

A generic JUnit adapter supports a documented subset from concrete producers. It cannot invent retry history that a producer omitted. Expected shard completeness comes from an explicit execution expectation or provider plan; it cannot be inferred merely from the shards collected.

### Milestone acceptance

1. Register projects with different runtime structures and persist their source/execution context.
2. Import JUnit from at least two producers, including a non-PHP producer, and actual UXor retry-recovered plus red/partial evidence.
3. Show each attempt, setup/global failures, source context, original artifact integrity and bounded associated logs through UI/MCP/CLI.
4. Recover duplicate delivery and interrupted intake without losing evidence or multiplying observations.
5. Evaluate a small versioned definition containing passing, failing, unmapped, wrong-revision and missing-environment targets. Unknown populations remain explicit.
6. Export and restore the database/artifact relationship into a clean installation, then verify matching identities and digests.

## Following delivery sequence

These stages preserve the complete product scope. Their subdivisions become implementation issues when predecessor contracts are available.

| Stage | Work | Completion evidence |
| --- | --- | --- |
| A — Foundation | Merged foundation and Symfony development tooling | Green Docker CI, completed code/security reviews and actual consumer checks |
| B — Observe and explain | WO-001–004 | First useful product milestone above |
| C — CI integration and existing-command execution | GitHub Actions observation/artifact sync; workspace and execution-provider contracts; bounded local commands and xorder integration; explicit CI dispatch/rerun/cancel | One real pipeline and one existing non-PHP command managed through UI/MCP, with exact source, receipts and preserved evidence; uncertain provider responses reconciled |
| D — Distributed operations and shared deployment | Worker identity/capacity, leases/heartbeats, bounded scheduling, cancellation, remote source/artifact transfer, project/operation access, protected web deployment | Work runs on another host; worker/web restart, duplicate delivery, expired leases and cancellation races preserve an accurate operation history |
| E — Full verification, measurement and improvement | Test inventory, environment/quality obligations, code coverage, mutation, behaviour/performance campaigns, comparable history, flake/diagnosis workflows, explainable selection/shards and release gates | Every measure has scope/denominator/method/evidence; unknown impact widens selection; optimization compared against complete reference execution; advisory diagnosis cannot determine a deterministic gate |
| F — Distribution and operational maturity | Versioned app/worker images, simple Docker adoption, upgrades, retention, backup/restore, export, cloud guides, observability backends and a second real consumer | Clean install, upgrade and restore; multi-project remote workflow; durable evidence independent of telemetry sampling; infrastructure extraction justified by actual shared use |

Stages C and D can be split into narrow PRs and developed in parallel where contracts allow. The order of local execution and provider management can change to serve the next real consumer. Remote worker contracts and permissions should shape execution design from its first PR; the first local demonstration is not a reason to discard distributed scope.

### CI and execution contracts to settle in stage C

- Provider observations retain native workflow/run/attempt/job IDs, status and observation time. Pagination, expired artifacts, missing permissions, stale status and reconciliation errors are queryable.
- Management commands retain actor, target, capability, idempotency/reconciliation identity and transitions. A lost dispatch response is an uncertain result to reconcile before retrying an external effect.
- An executor advertises capabilities and accepts a reviewed execution profile, source context, artifact handoff and operation identity. Separate orchestration workers from project test processes.
- Local source mounts and remote source transfer are different mechanisms. A local path is not remote source identity. Preserve exact/dirty source provenance and isolate writable fixtures/dependencies per execution.
- Reuse verified xorder resources and lifecycle facilities through an adapter. Inspect its current catalog before choosing a digest; the foundation's CLI image is not a published Xorgate application image.
- Cancellation, timeout, runner loss and test failure remain distinguishable. Cleanup removes only resources owned by that operation; interrupted work remains inspectable.

### Coverage and insight work to settle in stage E

Keep behaviour, structure, environment and quality measures separate. A declared check binding describes intended verification; execution observations and a versioned assessment explain what evidence actually supports. A report can be valid while its verification population is incomplete.

Coverage and mutation adapters retain native measurement scope and denominators. PHP and JavaScript measurements cannot be combined into an unexplained percentage. Surviving mutants and unexecuted methods are evidence to investigate, with possible equivalence/measurement limitations recorded.

History includes first-attempt failures, retry recovery, incomparable environments and missing runs. Diagnose with explicit input provenance and revised findings. Start selection in shadow mode, explain omissions and conservative fallback, and measure elapsed time separately from aggregate execution cost. Introduce thresholds through reviewed policy supported by measured populations.

## Full-scope coverage of the plan

| Scope area | Delivery home |
| --- | --- |
| Symfony product, UXor, database and reproducible environment | A, extended in F |
| Distributed asynchronous management, scheduling/capacity and recovery | B intake lifecycle; C execution commands; D full distribution |
| Test inventory, guarantees, supported environments and explicit gaps | B initial mapping; E complete integration |
| Complete CI management and provider controls | C, with D/F operational hardening |
| Execution, workspaces, xorder and local/remote handoff | C/D |
| Original evidence, normalized attempts, coverage/mutation and export | B/E/F |
| Planning, impact selection, sharding and campaigns | C scheduling foundations; E explained optimization |
| Diagnostics, Jev, flake history and investigation feedback | B existing assessments; E active workflows |
| MCP query and management interface | Every stage uses shared application services |
| Human management workflow and live progress | Every stage adds corresponding UXor views |
| Correlated logs, timing, metrics and traces | A logs; B intake instrumentation; C/D cross-service correlation; E/F operations |
| Easy existing-project adoption, shared/cloud deployment, access and recovery | B observe; C manage; D distribute; F package and operate |

## PR and verification workflow

Use one reviewable PR per coherent work order, splitting an issue only when each part has a concrete observable result. Branch from merged `master` without mixing unrelated changes or hiding review findings. Keep issue acceptance checkboxes open until observed proof exists.

Read [AGENTS.md](../AGENTS.md), [development.md](development.md) and the actual predecessor diff before changing contracts. Keep shared implementation services behind UI/MCP/CLI; do not introduce separate verdict implementations. Record schema, migration and adapter decisions in the implementation PR with concrete before/after examples.

For implementation PRs, run the applicable `bin/check` and foundation integration flow plus focused new boundary checks. After CI is green, request one code review and one security review on that head. Resolve findings and verify resulting changes; repeat review only for a practical P1 finding. Owner review/merge remains distinct from green automation.

Verification should concentrate on false-confidence and recovery boundaries: wrong source/revision, missing populations, retry erasure, duplicate intake, interrupted commit, lost artifact, invalid parser input and cross-interface disagreement. Add browser checks for the actual operator flow, including keyboard navigation, CSP, Turbo Back and reconnect behavior; avoid copying the entire UXor component test suite into Xorgate.

Measure CI time, setup/cache cost and test scope before adding more shards, matrices or campaigns. Prefer fixtures and focused integration checks to repeated full-stack boot per assertion. Preserve native failing evidence when checking a red; rerunning alone does not explain the failure.

## Immediate next action

Run the WO-003 PR through Docker CI and fix what it finds. Then import genuine UXor CI `results.json`, `failed-attempts.json`, `durations.json`, step logs and Jev assessments from one retry-recovered green run and one red run, verify attempts, links and log windows against them, and record that evidence in the PR before closing the acceptance items. After CI is green, request one code review and one security review; repeat review only for a practical P1. Leave merge to the owner, then start [WO-004](https://github.com/xormania/xorgate/issues/5).
