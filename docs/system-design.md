# Xorgate — system design and scope

Design record prepared before the application foundation. For current implementation and startup commands, see [README](../README.md) and [development](development.md).

Date: 2026-10-12 (America/New_York)  
Owner: xor  
Status: design record for further development; no implementation or release is claimed.

## 1. Purpose

Xorgate is a general testing and CI management system for projects in any language or framework. It stores operational data, logs and verification evidence; connects that evidence to the system being tested; and provides insight into the condition and coverage of the overall system.

It supports people through a web application and agents through MCP and other application interfaces. These interfaces operate on the same persistent records and application services.

The intended system is distributed, asynchronous and suitable for local use and eventual web/cloud deployment. Easy installation into an existing project is a core requirement. Adoption must have a migration path from managing existing tools and workflows to a more explicitly structured verification system.

Symfony 8.1 is the implementation platform for xorgate's web application. UXor supplies its UI. These choices do not impose PHP, Symfony, UXor or a particular application architecture on managed projects.

## 2. Direction established by xor

The following points express the direction established in the conversation:

- Xorgate owns testing and CI management.
- Its scope includes persistent data, logs, telemetry, analysis and coverage of the overall system.
- MCP is a first-class interface for agent interaction with the system.
- The system must work well with third-party test tools.
- Managed projects may use any language or framework, including mixed-language repositories.
- Installation into existing projects should be straightforward; Docker is a preferred packaging option.
- The architecture should support asynchronous and distributed work, with a path to cloud deployment.
- More design is needed before treating a container layout, database schema or MCP tool catalogue as settled.

The domain model, adoption sequence and implementation boundaries below are proposals that develop this direction. They remain open to refinement. This document does not turn earlier assistant suggestions into approved technical decisions.

## 3. Responsibilities and boundaries

| Element | Responsibility |
| --- | --- |
| Xorgate | Test and CI management; orchestration; execution coordination; persistence; evidence relationships; coverage assessment; diagnostics; telemetry; web and agent interfaces |
| Managed project | Its source, behaviours, requirements, environments, test assets and project-specific configuration |
| Third-party test and analysis tools | Their native execution, measurements and report semantics |
| CI providers | Their execution infrastructure and provider-native workflow/job state, integrated through adapters |
| UXor | UI components and development references for xorgate's own Symfony application |
| Xorder | Distribution of images and resources, plus development-environment support |

The managed project's runtime remains independent of xorgate's runtime. A project can execute on a host, in existing containers, on remote workers or through external CI. Containerizing xorgate does not require every managed project to adopt Docker.

UXor is one possible integration example. Domain concepts and public contracts must also make sense for Rust, Python, JavaScript/TypeScript, JVM, .NET, Go, native-code and mixed-language projects.

## 4. The persistent information model

The centre of the design is a set of connected records. The following are conceptual categories, not a final entity schema or authoritative lexicon.

| Category | Information to represent | Important relationships |
| --- | --- | --- |
| System definition | Projects, components, interfaces, dependencies, behaviours, requirements, risks and supported environments | Identifies what is being verified and what is in scope |
| Verification definitions | Tests, suites, checks, workflows, selectors, expected outputs and intended verification targets | Connects checks to components, behaviours and requirements |
| Executions | Runs, attempts, jobs, steps, selected checks, omitted checks, revisions, configuration, environments and timing | Connects a definition to a particular execution context |
| Evidence | Native reports, logs, traces, screenshots, measurements, coverage outputs and artifact metadata | Connects observations to executions and their producers |
| Assessments | Coverage evaluations, comparisons, trends, failure clusters, suspected causes and diagnostic findings | Connects conclusions to evidence and an analysis method/version |
| Gate evaluations | Applicable policy, evaluated conditions, outcome and unresolved conditions | Records why a particular revision or run did or did not satisfy a gate |

Definitions change over time. An old run must remain interpretable using the definition, configuration and policy that applied when it was evaluated.

A useful trace should answer: which part of the system is this about; which check was intended to verify it; which execution actually ran; which evidence was produced; and how was the assessment derived?

## 5. Coverage of the overall system

Coverage is a set of scoped relationships and measurements. The design should preserve multiple dimensions instead of hiding them inside an unexplained percentage.

| Dimension | Question |
| --- | --- |
| Behaviour | Which capabilities, normal paths, edge cases and failure/recovery paths have verification? |
| Structure | Which code, components, interfaces and integration boundaries are exercised? |
| Environment | Which supported operating systems, runtimes, browsers, configurations and deployment combinations have evidence? |
| Quality | What evidence exists for correctness, accessibility, security, performance and reliability? |
| Freshness and applicability | Does the evidence apply to the revision, configuration and system definition under assessment? |

Every reported measure needs a defined scope, denominator, method and evidence links. Examples include branch coverage for one instrumented execution, mapped behaviours within a selected component, or supported environment combinations exercised during a release evaluation. These are different measures and should remain distinguishable.

### 5.1 Intended coverage and observed evidence

A declared mapping says that a check is intended to verify a behaviour or requirement. It does not establish that the check is sufficient, that it ran, or that the system currently satisfies the requirement.

Execution and observation add evidence. Assessment determines what that evidence supports, subject to the check's assertions, the execution conditions and the applicable policy. Test success must not silently become a claim that an entire feature is correct.

The UI and MCP should distinguish situations such as:

- A target has no identified verification.
- Verification is defined but has not run in the relevant context.
- Evidence exists but is stale or applies to another configuration.
- Relevant evidence supports the assessed condition.
- Relevant evidence reports a failure.
- Available evidence is incomplete, conflicting or inconclusive.

These are example distinctions, not a frozen status enum. Run state, coverage state and assessment outcome need separate definitions.

### 5.2 Aggregation

Rollups must explain how individual records contribute to the result. Unknown or missing information must remain visible. An aggregate must identify exclusions and avoid counting the same evidence repeatedly simply because it has multiple mappings.

Risk weighting or numerical confidence may be useful, but any such method needs an explicit rationale and calibration. The system should not manufacture precision from sparse or unvalidated mappings.

### 5.3 Selection and omissions

Change-aware selection should record the inputs and explanation behind the selected checks. Omitted, unsupported, cancelled and skipped checks retain their actual status. A selection decision should state its limitations when dependency or coverage mapping is incomplete.

Reruns remain separate attempts linked to the original failure. A later pass can inform an intermittent-failure investigation while preserving the earlier observation.

## 6. Data, logs and artifact storage

Proposed storage responsibilities:

- A database holds identities, metadata, relationships, execution state, indexed observations and assessments.
- Artifact storage holds original report files, large logs, traces, screenshots and other bulky evidence.
- Parsing and indexing make useful portions searchable while retaining links to the original source.
- Telemetry correlates activity across MCP requests, application operations, queue work, runners and external CI.

The concrete database, artifact backend and indexing technology remain implementation choices. Local persistent storage should have a migration path to remote storage without changing evidence identity.

### 6.1 Provenance

Where available, records should retain project identity, source revision or workspace fingerprint, definition/configuration version, environment identity, tool version, timestamps, producer and artifact integrity information. Missing provenance should be represented explicitly.

Measurements and derived interpretations should be stored distinctly. Re-analysis may create a new assessment without rewriting the underlying observation. Findings need evidence references and enough analysis metadata to explain how they were produced.

### 6.2 Lifecycle

Define retention and deletion by data category. Preserve enough metadata to explain that evidence expired or became unavailable. Coverage or gate views must not continue presenting discarded evidence as retrievable and current.

Backup, export and restore need to preserve the relationship between database records and stored artifacts. An interrupted or partial upload needs a visible state so that a report is not treated as complete before its evidence is available.

## 7. Insight and diagnostics

The accumulated data should help answer operational questions:

- What changed between the last relevant successful execution and the current failure?
- Does the evidence suggest a product regression, an intermittent test or an environment problem?
- Which components or behaviours have no current verification evidence?
- Which expensive checks contribute distinct verification, and where is execution redundant?
- How are durations, queue delays, resource consumption and failure rates changing?
- What further execution would help resolve an uncertainty?

Start with traceable queries and explicit comparisons. Statistical or agent-assisted analysis can extend these capabilities while identifying the evidence, assumptions and uncertainty behind a finding.

Jev and other diagnostic tools fit as integrations into managed workflows. A diagnosis is an assessment linked to evidence. Raw test and CI outcomes remain independently available.

## 8. Asynchronous execution and CI management

Execution should be represented as durable work with stable identifiers. A web or MCP request can schedule work and return promptly; workers and runners continue independently of the caller's connection.

Design the lifecycle explicitly: accepted work, scheduling, execution attempts, progress, cancellation, completion, failure and incomplete or lost execution. Retry, idempotency, timeouts and recovery must have defined semantics.

CI integration should map provider-native identifiers and states into xorgate records while preserving the originals. Record when external status was last observed. Triggering a job, observing its completion and ingesting its evidence are distinct events.

Provider capabilities vary. Adapters should advertise what they can discover, execute, cancel, retrieve and interpret. Unsupported capabilities should be explicit; a generic model must not imply that every provider offers identical control.

## 9. Human and agent interfaces

The web application, MCP server and any CLI/API should use common application services and persistent records.

MCP needs capabilities for discovery, planning, execution, CI management, evidence inspection, coverage queries and diagnostic workflows. The exact tool names and granularity should follow the domain design.

Agent-facing results should offer structured, bounded summaries with stable identifiers, explicit status and links or handles for detailed evidence. Large logs and artifacts need selective retrieval rather than being injected wholesale into every response.

Record who or what initiated an operation and correlate it with its subsequent activity. Apply project and operation permissions consistently across interfaces. Keep submitted logs and repository content identifiable as evidence, separate from instructions governing an agent's work.

MCP request lifetime must not define job lifetime. A reconnecting agent should be able to inspect and continue managing existing work by identifier.

## 10. Tool and language independence

Proposed adapter responsibilities include:

1. Discover native configuration, suites and reports where the tool supports discovery.
2. Describe available capabilities and execution requirements.
3. Invoke existing commands or provider operations in the appropriate environment.
4. Capture native outputs and normalize selected fields without erasing original meaning.
5. Resolve native test identities across runs as reliably as the tool permits.
6. Expose provenance, limitations and errors.

A generic command adapter provides an entry point for tools without dedicated integration. A successful process exit alone supplies only the evidence that this adapter actually observed; richer claims require richer output or interpretation.

A language-neutral project manifest can describe commands, environments, report locations, relationships and integration settings. Its schema and upgrade rules need design. A project's own PHP dependencies or Composer installation must not be prerequisites for management by xorgate.

## 11. Installation and progressive adoption

Proposed adoption stages:

| Stage | Immediate value | Additional structure |
| --- | --- | --- |
| Connect and observe | Import available test reports, logs and CI history | Project identities, native run identities and provenance |
| Manage execution | Start and inspect existing commands and workflows | Runtime definitions, execution lifecycle and artifact capture |
| Model verification | Relate checks to components, behaviours and requirements | Explicit scope, mappings and coverage semantics |
| Improve decisions | Diagnose failures and assess selection, cost and gaps | Versioned analysis and explainable policies |
| Distribute | Coordinate more projects and remote execution capacity | Remote runner contracts, shared services and operational controls |

These stages sequence delivery; they do not narrow the intended complete management system. Existing history and identifiers should survive progression between stages.

Docker packaging should simplify xorgate's installation. Xorder may distribute the relevant images and resources. The companion setup document develops a Symfony 8.1 implementation path without making that implementation the managed-project contract.

## 12. Design validation

Validate the model against materially different examples, including a mixed-language project. Check that:

- Existing tools can be integrated incrementally.
- A report can be traced to an execution and source/environment context.
- Missing verification and stale evidence remain visible.
- UI and MCP retrieve the same underlying history and assessments.
- A caller disconnect does not erase an execution.
- Native measurement semantics survive normalization.
- A diagnostic interpretation cannot silently replace the original result.
- No core concept assumes PHP, Symfony or UXor in a managed project.

## 13. Decisions to resolve next

1. Coverage units, relationships, aggregation rules and applicability/freshness policy.
2. Stable identities and versioning for project definitions, checks, runs and artifacts.
3. Adapter contracts and the boundary between orchestration workers and execution runners.
4. Execution recovery, cancellation and idempotency semantics.
5. Data retention, indexing, artifact storage and migration requirements.
6. Gate policy and the separation of observed results from assessment.
7. Initial integrations and the smallest complete delivery that exercises persistence, coverage and insight.

These are design questions to resolve through concrete cases. They are not restrictions on xor's intended scope.

## 14. Provenance and companion document

This document records the xorgate discussion and xor's explicit corrections on 2026-10-12: general project support, language independence, persistent data and logs, insight, overall system coverage, and MCP as an interface to the management system.

Earlier project documents have not been promoted into authority here. Specific framework packages and development commands are addressed separately in [the Symfony 8.1 and UXor setup guide](symfony-uxor-setup.md), where current implementation sources are identified.
