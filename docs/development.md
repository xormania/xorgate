# Developing Xorgate

This repository is Xorgate's own Symfony 8.1 application. It manages projects independently of their implementation language. The foundation creates an operational path through web/MCP, persistent data, asynchronous work and artifact storage; the domain remains open for further design.

## Run and inspect the application

```bash
./bin/setup
docker compose exec -T php php bin/console about
docker compose exec -T php php bin/console debug:router
docker compose exec -T php php bin/console doctrine:migrations:status
./bin/check
```

Use `./bin/setup --resolve` when initially generating absent locks or intentionally updating dependencies. Normal setup installs the reviewed lockfiles. Composer runs inside the PHP container; keep dependency resolution failures visible rather than silently selecting another installation mode.

The development default is [HTTP port 8088](http://localhost:8088). Enable HTTPS with `SERVER_NAME='localhost, php:80' ./bin/setup`, then use [HTTPS port 8448](https://localhost:8448) with Caddy's local certificate trusted. The `php`, `worker` and `database` services share the application network. Keep the chosen Compose project name and port settings consistent across terminals.

The framework rejects HTTP requests whose Host is outside `XORGATE_TRUSTED_HOSTS`, including reads and CSRF-protected writes. Its default allowlist covers localhost, IPv4/IPv6 loopback and `php` for Compose health checks. For a custom development name, add an anchored pattern such as `^xorgate\.test$` to the comma-separated list in `.env.local`, retain the internal health hostname, and restart `php` and `worker`. `SERVER_NAME` configures Caddy separately; it does not authorize another application host. The live registry smoke tests both hostile-host reads and an otherwise-valid form POST under a hostile Host.

```bash
docker compose logs -f php worker
docker compose exec php php bin/console tailwind:build --watch
docker compose exec -T php php bin/console messenger:failed:show
```

Run logs and the Tailwind watcher in separate terminals. Restart the worker after changes to PHP handlers or service definitions so it executes the changed code:

```bash
docker compose restart worker
```

## Application structure

| Path | Current responsibility |
| --- | --- |
| `src/Controller/DashboardController.php` | Render the observed foundation status |
| `src/Service/FoundationStatus.php` | Shared web/MCP runtime snapshot |
| `src/Service/ProjectRegistry.php` | Shared project commands, queries and immutable execution context |
| `src/Controller/ProjectController.php`, `templates/projects/` | UXor operator registry and native Symfony forms |
| `src/Security/CspNonce.php` | Main-request script/style nonces compatible with persistent workers |
| `src/EventListener/SecurityHeadersListener.php` | Browser response headers and CSP |
| `templates/dashboard/` | Actual foundation readiness and implementation boundaries |
| `templates/layouts/`, `templates/components/` | Application-owned installed UXor recipes |
| `assets/` | AssetMapper, Stimulus and Tailwind sources |
| `migrations/` | Persistent database changes |
| `bin/setup`, `bin/install-ui`, `bin/check` | Reproducible setup, UI installation and verification |

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
docker compose exec -T php php bin/console ux:icons:lock
docker compose exec -T php php bin/console importmap:install
docker compose exec -T php php bin/console tailwind:build
docker compose exec -T php php bin/console lint:twig templates
docker compose exec -T php php bin/console lint:container
```

Retain Turbo navigation. Keep interactive behaviour in Stimulus controllers, give stateful regions stable identities and decide whether Turbo or a Live Component owns a rendered region before adding partial updates.

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

Project registration and immutable execution provenance are implemented through the [shared registry contract](project-registry.md). Third-party test execution, report ingestion, external CI adapters, coverage calculations, diagnostics orchestration, multi-user permissions and a published installable image remain subsequent work. Docker packaging for Xorgate's own app does not require a managed project to use Docker. Deployment configuration and an authorization design must be completed before offering the system as a shared remote service.
