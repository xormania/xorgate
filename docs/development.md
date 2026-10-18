# Developing Xorgate

This repository is Xorgate's own Symfony 8.1 application, running PHP 8.5 in Docker. Managed projects can use any language or framework. Symfony Console, Maker, Mate and Serena support development of this application; Xorgate's public MCP tools expose its project-management services.

Use Docker with Compose and Bash for the application scripts, plus host Python 3 for live development smoke checks. PHP and Composer execute in the application container.

## Run and inspect the application

```bash
./bin/setup
docker compose exec -T php php --version
docker compose exec -T php php bin/console about
docker compose exec -T php php bin/console debug:router
docker compose exec -T php php bin/console doctrine:migrations:status
./bin/check
```

Use `./bin/setup --resolve` when initially generating absent locks or intentionally updating dependencies. Normal setup installs the reviewed lockfiles. Composer runs inside the PHP container; keep dependency resolution failures visible rather than silently selecting another installation mode.

The development default is [HTTP port 8088](http://localhost:8088). Enable HTTPS with `SERVER_NAME='localhost, php:80' ./bin/setup`, then use [HTTPS port 8448](https://localhost:8448) with Caddy's local certificate trusted. Keep the chosen Compose project name and port settings consistent across terminals.

Run application PHP commands in `php`, with `/app` as the working directory. The web process, Symfony Console and Mate then use the same installed `vendor` dependencies, application configuration, `var` cache volume and PostgreSQL network. `worker` uses the same image, dependency/cache volumes, artifact storage and database. This keeps a diagnostic tied to the application that serves requests and consumes messages.

The framework rejects HTTP requests whose Host does not match `XORGATE_TRUSTED_HOSTS`, including reads and CSRF-protected writes. Its default anchored regex covers localhost, IPv4/IPv6 loopback and `php` for Compose health checks. For a custom development name, set `XORGATE_TRUSTED_HOSTS='^(localhost|127\.0\.0\.1|\[::1\]|php|xorgate\.test)$'` in `.env.local` and restart `php` and `worker`. Retain the internal health hostname. Missing or blank overrides use the default allowlist. `SERVER_NAME` configures Caddy separately; it does not authorize another application host. The live registry smoke tests both hostile-host reads and an otherwise-valid form POST under a hostile Host.

```bash
docker compose logs -f php worker
docker compose exec php php bin/console tailwind:build --watch
docker compose exec -T php php bin/console messenger:failed:show
```

Run logs and the Tailwind watcher in separate terminals. Restart the worker after changes to PHP handlers or service definitions so it executes the changed code:

```bash
docker compose restart worker
```

## Framework and source tools

| Tool | Responsibility | Execution context |
| --- | --- | --- |
| Symfony Console and Maker | Discover installed commands and generate code with their native conventions | `php` application service, `/app` |
| Symfony AI Mate | Installed application diagnostics, tool/resource discovery and Symfony introspection | `./bin/mate`, using the same `php` service |
| Serena | Source navigation, language-server queries and explicitly enabled source edits | `./bin/serena`, separate pinned container bound to this checkout |
| Xorgate MCP | Runtime status, project/execution records, report intake and retained evidence | `/mcp` or `mcp:server xorgate` in the application |

Discover the installed Makers and inspect their current arguments before generating code:

```bash
docker compose exec -T php php bin/console list make
docker compose exec -T php php bin/console help make:command --format=json
docker compose exec -T php php bin/console help make:test --format=json
```

Run the chosen `make:*` command in that same container. Review the generated changes and extend them for the application's behaviour. Installed command metadata determines the available arguments and options; a hand-written replacement generator can drift from the framework's attributes, autoconfiguration and test conventions.

Mate is a development Composer dependency with the installed Symfony and Monolog extensions. `./bin/mate` runs `php vendor/bin/mate` through the application's `php` service and passes arguments to the native CLI. Its project configuration permits `APP_ENV=dev` or `test`, checks PHP 8.5 and binds cache/profiler/log paths to this application.

```bash
./bin/mate tools:list --format=json
./bin/mate tools:inspect server-info
./bin/mate tools:call server-info --format=json
```

Inspect a tool's actual parameters before calling it. Prefer a diagnostic's observed container, route or runtime facts when those are the question being investigated. Source navigation explains implementation; application diagnostics establish what the configured application reports. Mate 0.14 exposes these tools through its CLI and generated project instructions. The [example MCP configuration](../config/mcp.example.json) connects Serena and Xorgate's public MCP server.

Keep Mate's initialized bootstrap and generated `mate/AGENT_INSTRUCTIONS.md` plus the managed `AI_MATE_INSTRUCTIONS` block in `AGENTS.md` under version control. Normal setup runs Composer install with scripts enabled; Mate's allowed Composer plugin runs discovery when `mate/extensions.php` is present. It does not initialize missing bootstrap files. After changing Mate extensions or project instructions, run native discovery again:

```bash
docker compose exec -T php composer dump-autoload
./bin/mate discover --composer --no-interaction
```

Review the generated diff and keep manual project guidance outside that block. For first-time initialization of missing Mate bootstrap files, the native command is `./bin/mate init --no-interaction`.

HTTP profiler collection is opt-in: visit an application route with `?profile=1`, then inspect its observed `X-Debug-Token` with the native profiler tools or `/_profiler` development routes. The toolbar stays disabled to preserve the application's CSP and page layout. Tests collect a profile only when the test enables it. Development logs remain on stderr and also rotate as JSON files in `var/log/`, retaining three daily files for Mate's Monolog diagnostics; `php` and `worker` share that volume.

Read a profiler resource URI returned by a Mate diagnostic with `./bin/mate resources:read <returned-uri> --format=json`. Keep the observed token and URI associated with the request that produced the profile.

Serena starts through an MCP client using `./bin/serena`. Run `./bin/setup` to install application dependencies, then pull the pinned Linux amd64 image with `./bin/serena pull`. Server startup uses the installed digest and never pulls implicitly. The default session mounts source read-only; configure `./bin/serena --write` when source editing is needed. Application `vendor` dependencies remain read-only in both modes. Its checkout binding and private index cache are described in the [setup reference](symfony-uxor-setup.md).

Start with `initial_instructions`, then pass its returned `session_id` as `session` on each `serena_repl` call. Inspect `s.info('lsp', 'edit')` before calling the advertised facades. Each connection owns its REPL state; the per-worktree index survives connections in `.serena/cache/<image-digest>/`. PHP and TypeScript have semantic language-server support; Twig and YAML use text operations. A text search establishes its matches, while a PHP/TypeScript reference query covers the corresponding language-server scope. Use Mate or Symfony Console for application runtime facts.

## Checks and evidence

`./bin/check` runs Composer validation/platform checks, PHP and configuration/template/container lint, PHPUnit against a migrated test database, then `./bin/check-dev`. The test suffix keeps fixtures out of the development database.

For focused development diagnostics after `./bin/setup`, run:

```bash
./bin/check-dev
```

This warms the application's development cache and uses host Python 3 to verify Mate environment/container facts and an opt-in HTTP profile from the running application. Results are retained in `var/ci/development.json`. For a nondefault application address, set `XORGATE_URL` to the URL used by the checks.

Serena's real MCP/session acceptance check runs separately, after pulling its image:

```bash
./bin/serena pull
python3 tests/smoke/development.py serena
```

It verifies the mounted checkout/dependencies, advertised source API and persistent REPL session. Docker CI additionally exercises shared worker logs, live public HTTP/MCP/CLI, worker/artifact completion, restart persistence and Toolkit installation reproduction. Inspect the recorded workflow results for the revision being assessed.

For a changed behaviour, run the smallest check that actually exercises it, then the required repository checks. Keep command, exit status, source revision, dirty workspace state and relevant runtime facts with the result. Retain useful report/log artifacts when a check fails. A passing lint, generated class or Mate call establishes its own measured condition; it does not establish unrelated browser behaviour or managed-project coverage.

## Application structure

| Path | Current responsibility |
| --- | --- |
| `src/Controller/DashboardController.php` | Render the observed foundation status |
| `src/Service/FoundationStatus.php` | Shared web/MCP runtime snapshot |
| `src/Service/ProjectRegistry.php` | Shared project commands, queries and immutable execution context |
| `src/Service/ReportIntake.php`, `src/Service/ReportArtifactStore.php` | Durable report admission, retained bytes, operation/evidence queries and recovery |
| `src/Report/`, `src/MessageHandler/` | Bounded JUnit, Playwright, UXor-derived, Jev assessment and text-log adapters and Messenger normalization |
| `src/Controller/Artifact/`, `templates/reports/` | Native upload forms and UXor evidence, attempt, annotation and log-window inspection |
| `src/Controller/ProjectController.php`, `templates/projects/` | UXor operator registry and native Symfony forms |
| `src/Security/CspNonce.php` | Main-request script/style nonces compatible with persistent workers |
| `src/EventListener/SecurityHeadersListener.php` | Browser response headers and CSP |
| `templates/dashboard/` | Actual foundation readiness and implementation boundaries |
| `templates/layouts/`, `templates/components/` | Application-owned installed UXor recipes |
| `assets/` | AssetMapper, Stimulus and Tailwind sources |
| `migrations/` | Persistent database changes |
| `bin/setup`, `bin/install-ui`, `bin/check`, `bin/check-dev` | Reproducible setup, UI installation and focused/full verification |
| `bin/mate`, `mate/` | Native Mate CLI wrapper, project configuration and generated instructions |
| `bin/serena`, `.serena/` | Checkout-bound source tooling and private index cache |
| `tests/smoke/playwright.py` | Live Playwright attempt, annotation-link and log-window agreement across UI, MCP and CLI, including after restart |
| `tests/Fixtures/Playwright/` | Controlled Playwright producer output and UXor tool-derived files with provenance |
| `tests/smoke/development.py` | Observed Mate, profiler, shared worker-log and Serena consumer checks |

`FoundationStatus::snapshot()` returns the application identity, timestamp and readiness checks for the database, artifact storage and queue. The dashboard and `xorgate_runtime_status` tool consume that result. A check's failure detail should describe the capability that is unavailable without returning credentials or exception text.

The runtime probe supplies a stable record that can be queued, processed and inspected after a client disconnects. Extend this service pattern when introducing actual execution records; the probe's schema is not the final managed-test domain model.

`FoundationProbeLimits` serializes admission and retention with a PostgreSQL transaction advisory lock. Repeated IDs bypass new-work admission; new IDs are checked against pending, total retained and rolling-minute limits before record/queue insertion. The shared runtime snapshot exposes the same capacity across UI, MCP and CLI. The idle worker runs bounded retention automatically; `xorgate:probe:cleanup` runs the same service explicitly. Only completed probes are eligible, and any async/failed message defers cleanup to preserve redelivery. Artifact expiration is recorded before eventual metadata pruning. If file deletion succeeds but the database transaction fails, the next sweep repeats the idempotent deletion and completes the metadata update.

## UXor assets and layouts

`bin/install-ui` installs missing `theme`, `layouts`, `form-theme`, `table`, `empty-state` and `card` recipes from immutable kit revision `9b2fa7309786cee435503de87cf0cd5f0a9d9782`, plus their recipe dependencies. Existing installed sources belong to the application and are preserved. The new recipes' printed Composer requirements are already regular locked dependencies. Docker CI runs `bin/check-ui-install` to reproduce the selected new recipes with Toolkit in a separate directory and compare all generated sources. The application stylesheet imports local installed assets:

```css
@import 'tailwindcss';
@import '../vendor/flowbite/dist/flowbite.min.css';
@import './uxor.css';
```

This is a consuming application: do not copy the demo's `../../../kit.css` import, `/app/demo` working directory, kit-discovery services or gallery routes. The relevant pinned references are:

- [UXor installation guide](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/INSTALL.md)
- [Layouts and CSP contract](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/layouts/README.md)
- [Demo asset setup](https://github.com/xormania/uxor/tree/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/assets)
- [Fresh Symfony Docker installation test](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/tools/tests/docker-install.sh)
- [Consumer agent guidance](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/docs/PROJECT-AGENTS-SNIPPET.md)
- [Native Symfony form controller](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/src/Controller/FormsController.php) and [form template](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/templates/forms/index.html.twig)

Install additional recipes when implementing a screen, execute the dependency instructions printed by the installer, and lock any new icons before committing:

```bash
docker compose exec -T php php bin/console ux:install badge --kit=https://github.com/xormania/uxor:9b2fa7309786cee435503de87cf0cd5f0a9d9782
docker compose exec -T php php bin/console ux:icons:lock
docker compose exec -T php php bin/console importmap:install
docker compose exec -T php php bin/console tailwind:build
docker compose exec -T php php bin/console lint:twig templates
docker compose exec -T php php bin/console lint:container
```

The `badge` command illustrates adding one recipe when a screen needs it. Read that pinned recipe's README, apply its printed Composer/importmap instructions, inspect the installed diff and record the selection. Run Toolkit commands in `APP_ENV=dev`, where ToolkitBundle is registered. Keep the existing kit pin unless an intentional recipe update has been reviewed.

Native Symfony forms use `form/flowbite_layout.html.twig`, either through `{% form_theme form 'form/flowbite_layout.html.twig' %}` or the application's `twig.form_themes` setting. Keep CSRF protection enabled, render validation errors with HTTP 422, and redirect successful writes with HTTP 303. The theme renders fields through UXor components and connects labels, help and errors. Give forms explicit IDs; `form_start` does not automatically render the FormView ID.

Retain Turbo navigation. Keep interactive behaviour in Stimulus controllers, give stateful regions stable identities and decide whether Turbo or a Live Component owns a rendered region before adding partial updates. Use theme roles and the installed component contracts, including toast streams for flash messages. For affected UI paths, check empty and unknown states, safe metadata display, invalid form retention, direct and Turbo visits, keyboard use and narrow-screen navigation under CSP.

## CSP and FrankenPHP workers

The two explicit service instances `app.csp_nonce.script` and `app.csp_nonce.style` are exposed to Twig as `csp_script_nonce` and `csp_style_nonce`. Their values live on the current main request. Twig may retain the Stringable services in a worker; it must not retain a generated nonce string between requests.

UXor's base layout prints the script nonce on its theme/importmap scripts and the style nonce in the metadata used by Turbo's progress bar. Application pages use `script-src` with the request nonce and `strict-dynamic`, and local/nonced styles with no inline style attributes. Symfony's generated diagnostic pages have a development-only exception; production error responses retain the strict policy.

When adding browser checks, inspect real responses and verify normal loads, Turbo visits, theme switching and narrow-screen navigation. Do not add a Flowbite JavaScript initializer to handle components already owned by Stimulus.

## Extending the foundation

The intended domain connects system definitions, verification definitions, executions, observations, artifacts, assessments and gates. These are design categories that require more detailed modelling before becoming permanent entity names or public tool contracts.

Keep provider/tool adapters capability-driven and language-neutral. Preserve native report meaning and provenance while extracting common queryable fields. Keep large original logs and artifacts outside routine MCP responses; expose bounded retrieval with evidence identifiers when the domain adds it.

The local artifact backend provides a starting point for storage adapters. A future remote backend should preserve artifact identity and integrity metadata. A future external queue or remote worker should preserve durable execution identity and progress independently of the original MCP request.

MCP foundation entrypoint:

```bash
docker compose exec -T php php bin/console mcp:server xorgate
```

The HTTP transport is served from `/mcp` with a Bearer token configured by `XORGATE_MCP_TOKEN` in the untracked `.env.local`. STDIO runs as a local process and does not require that HTTP header. Use tool discovery for current schemas, dispatch a probe with `xorgate_probe_dispatch`, then retrieve its stored outcome with `xorgate_probe_get` and `{"probeId":"<returned probe_id>"}`. Dispatch accepts an optional UUID under the same `probeId` key for idempotent retries. The record's `correlation_id` matches its `probe_id` and the structured worker log context; completion includes artifact path, SHA-256, size and availability metadata.

## Data and release boundaries

Database data and `/var/lib/xorgate/artifacts` are persistent. Restarting or stopping containers retains these volumes. Removing volumes deletes their development data, so backup/export work must account for both stores.

Project registration and immutable execution provenance use the [shared registry contract](project-registry.md). Report ingestion uses the [artifact intake contract](artifact-intake.md): preserve bytes before publishing a committed operation, enqueue through the same DBAL connection, parse outside the admission transaction, and commit normalized observations atomically. Filesystem publication and database commit remain separate boundaries; bounded cleanup reconciles abandoned staged/orphan bytes. Back up the database and artifact volume together while writes are stopped.

Third-party test execution, external CI adapters, coverage calculations, diagnostics orchestration, multi-user permissions and a published installable image remain subsequent work. Docker packaging for Xorgate's own app does not require a managed project to use Docker. Deployment configuration and an authorization design must be completed before offering the system as a shared remote service.
