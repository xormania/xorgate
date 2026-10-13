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

The setup builds the development image, installs locked dependencies and the pinned UXor theme/layout recipes, creates the database schema, prepares frontend assets and starts the worker. Open [http://localhost:8088](http://localhost:8088). To enable local HTTPS, start with `SERVER_NAME='localhost, php:80' ./bin/setup`, then open [https://localhost:8448](https://localhost:8448) and trust Caddy's development certificate.

When preparing this repository's initial dependency locks, use `./bin/setup --resolve`. This is a bootstrap/dependency-update operation; normal setup uses the committed lockfiles. Review and commit the generated locks and installed UI assets before relying on that exact dependency set in other environments.

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
- [System design and scope](docs/system-design.md)
- [Symfony and UXor setup reference](docs/symfony-uxor-setup.md)
- [Agent instructions](AGENTS.md)

The UXor recipes are installed from immutable revision [`9b2fa7309786cee435503de87cf0cd5f0a9d9782`](https://github.com/xormania/uxor/tree/9b2fa7309786cee435503de87cf0cd5f0a9d9782). [UXor's demo](https://github.com/xormania/uxor/tree/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo) supplies development examples; Xorgate uses its own application root and installed recipe files.

[Xorder](https://github.com/xormania/xorder) provides image/resource distribution and development-environment guidance. Consult its README and verified capability listings before selecting a resource. This foundation uses Symfony Docker for its web runtime and makes no claim that a Xorgate application image has been published.
