# Xorgate

Xorgate is a testing and CI management system for projects in any language or framework. Its intended scope includes persistent execution data, logs, verification evidence, system coverage, diagnostics and operational insight. People interact through the web application; agents use MCP. Both interfaces use the same application services and records.

This first foundation provides a Symfony 8.1 application in Symfony Docker, a UXor application layout, PostgreSQL persistence, a durable Messenger queue, an asynchronous worker, local artifact storage and MCP runtime tools. A persistent runtime probe demonstrates that these services work together. Project registration, test execution adapters, CI-provider integrations and coverage assessments remain subsequent domain work.

Symfony and PHP are Xorgate's implementation runtime. Managed projects can use Rust, Python, TypeScript, JVM, .NET, Go, native code or mixed-language environments.

## Start the development application

Requirements: Docker with the Compose plugin, Bash and access to the pinned container images, Composer package archives, UXor recipes and importmap assets. PHP and Composer run in containers; a host PHP installation is unnecessary.

From the repository root:

```bash
./bin/setup
```

The setup builds the development image, installs locked dependencies, creates the database schema, prepares the committed UXor assets and starts the worker. Open [http://localhost:8088](http://localhost:8088). To enable local HTTPS, start with `SERVER_NAME='localhost, php:80' ./bin/setup`, then open [https://localhost:8448](https://localhost:8448) and trust Caddy's development certificate.

Use `./bin/setup --resolve` only when intentionally updating dependencies. Normal setup uses the committed lockfiles and installed UI recipes; review and commit changed locks and assets after an update.

```bash
./bin/check
docker compose logs -f php worker
docker compose down
```

The dashboard displays current database, artifact-directory and queue readiness. It reports application foundation health; it does not claim coverage or correctness of any managed project.

## Connect an agent

The Symfony application exposes a Streamable HTTP MCP endpoint at `/mcp`. HTTP requests require `Authorization: Bearer <XORGATE_MCP_TOKEN>`; setup generates this token in the local, untracked `.env.local`. Configure the token through your client's secret/header settings. Local clients can instead use the named STDIO server through the running PHP container without an HTTP token header:

```bash
docker compose exec -T php php bin/console mcp:server xorgate
```

Example STDIO client configuration, replacing the working directory with the absolute checkout path:

```json
{
  "mcpServers": {
    "xorgate": {
      "command": "docker",
      "args": ["compose", "exec", "-T", "php", "php", "bin/console", "mcp:server", "xorgate"],
      "cwd": "/absolute/path/to/xorgate"
    }
  }
}
```

Some clients configure their process working directory outside this object. Use the client's equivalent setting, or provide an absolute Compose file path with `docker compose -f /absolute/path/to/xorgate/compose.yaml …`.

| MCP tool | Foundation capability |
| --- | --- |
| `xorgate_runtime_status` | Read the same runtime snapshot displayed by the web application |
| `xorgate_probe_dispatch` | Queue a persistent runtime probe; accepts an optional `probeId` UUID for idempotent dispatch |
| `xorgate_probe_get` | Retrieve the probe's stored state using the required `probeId` UUID |

Call `xorgate_probe_dispatch` with `{}` to receive a generated `probe_id`. Call `xorgate_probe_get` with `{"probeId":"<returned probe_id>"}` until its status is `completed`. The response includes a matching `correlation_id` and artifact integrity metadata, including `artifact.available`. For a repeated dispatch, provide the same `probeId` to reuse the existing record.

The probe exercises Xorgate's own persistence, queue, worker and artifact path. It is a foundation diagnostic rather than a managed-project test runner. Ask the server for its tool schemas when integrating a client.

The default web UI is for loopback development. HTTP MCP has token authentication; broader web-user and project permissions are subsequent implementation work.

## Probe capacity and retention

New probes share one installation-wide budget across HTTP MCP, STDIO and CLI. The shared MCP token is not an individual caller identity. Defaults allow 100 pending/failed probes, 1,000 retained records and 30 new probes per rolling minute. Repeating an existing ID remains idempotent at capacity. A rejected MCP request returns `accepted: false`, an explicit limit reason and capacity data without creating a record, message or artifact; CLI dispatch returns the same data with a failing exit code. Inspect `probe_capacity` through runtime status or the dashboard.

The worker automatically sweeps completed probes once per minute when idle, expiring artifacts after seven days and pruning their metadata after fourteen days. Until metadata is pruned, `artifact.expired_at` identifies deliberate expiration separately from an unexpectedly missing file; repeating its ID returns the historical record. After pruning, that ID no longer has an idempotency record. Queued and failed probes are retained for recovery and count against the budget. Cleanup defers while the async or failed queue has messages so it cannot remove records needed by redelivery.

Override the positive `XORGATE_PROBE_MAX_PENDING`, `XORGATE_PROBE_MAX_RETAINED`, `XORGATE_PROBE_MAX_PER_MINUTE`, `XORGATE_PROBE_ARTIFACT_RETENTION_SECONDS` and `XORGATE_PROBE_RECORD_RETENTION_SECONDS` values in `.env.local`; restart `php` and `worker` after changes. Record retention must cover artifact retention, which must be at least 60 seconds. Each sweep handles at most 100 expirations and 100 metadata removals. Run a sweep explicitly with:

```bash
docker compose exec -T php php bin/console xorgate:probe:cleanup
```

If recovery work fills the pending budget, inspect the failed transport and recover or retire that work before admitting more probes. These limits govern foundation probes; the managed-project operation policy remains later domain work.

## Services and data

| Compose service | Responsibility |
| --- | --- |
| `php` | FrankenPHP, Symfony web application and MCP |
| `worker` | Symfony Messenger consumer using the same application image |
| `database` | PostgreSQL records and durable Messenger transport |

Artifact storage is mounted at `/var/lib/xorgate/artifacts`. Keep the database and artifact volumes together when backing up the application. `docker compose down` retains volumes; `docker compose down --volumes` removes persistent development data.

The local foundation does not yet provide the full managed-project lifecycle, remote execution, CI integrations, log-search interface, coverage model or assessment workflows described in the design. The initial probe and correlated application logs make the asynchronous path available for extending those capabilities.

## Development and design

- [Development workflow](docs/development.md)
- [Implementation plan and work orders](docs/implementation-plan.md)
- [System design and scope](docs/system-design.md)
- [Symfony and UXor setup reference](docs/symfony-uxor-setup.md)
- [Agent instructions](AGENTS.md)

The UXor recipes are installed from immutable revision [`9b2fa7309786cee435503de87cf0cd5f0a9d9782`](https://github.com/xormania/uxor/tree/9b2fa7309786cee435503de87cf0cd5f0a9d9782). [UXor's demo](https://github.com/xormania/uxor/tree/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo) supplies development examples; Xorgate uses its own application root and installed recipe files.

[Xorder](https://github.com/xormania/xorder) provides image/resource distribution and development-environment guidance. Consult its README and verified capability listings before selecting a resource. This foundation uses Symfony Docker for its web runtime and makes no claim that a Xorgate application image has been published.
