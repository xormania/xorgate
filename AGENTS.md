# Agent instructions

Xor is the project owner and final decision maker. Current explicit direction controls scope and decisions. The design documents describe the intended system; their proposed domain categories are not a finalized schema or authoritative lexicon.

## Scope and architecture

- Xorgate manages testing and CI for any language or framework, including mixed-language projects. PHP and Symfony belong to Xorgate's implementation rather than managed-project requirements.
- Keep application services shared by web and MCP interfaces. Put durable work behind Messenger and return stable identifiers rather than keeping requests open for worker completion.
- Keep observed evidence distinct from analysis. Record relevant source revision, environment, producer and timestamps as domain integrations are introduced. Do not turn passing runtime checks into system-coverage claims.
- The current foundation contains readiness checks and a persistent asynchronous probe. Add domain-specific capabilities deliberately; do not infer that unimplemented adapters or analyses are already available.
- Never fabricate projects, test counts, coverage percentages or diagnostic results to populate UI screens. Show actual stored or observed state and explicit empty states.

## Development

Read `README.md` and `docs/development.md` before changing startup or dependency handling. Use the container runtime for PHP commands:

```bash
./bin/setup
./bin/check
docker compose exec -T php php bin/console about
```

Use committed dependency locks for normal installs. `./bin/setup --resolve` is the explicit initial-resolution/update path. Commit generated locks and relevant installed UXor recipe files after review. Keep command documentation aligned with the implemented scripts.

## UXor UI

- Installed UXor recipes are application-owned files. Extend `templates/layouts/app.html.twig` for the application shell and use the documented component contracts.
- Use AssetMapper/importmap, Stimulus, Turbo and TailwindBundle. Keep application behaviour in Stimulus controllers and preserve Turbo navigation and reconnect behaviour.
- Use UXor theme roles and component recipes. Install only recipes that current screens require; apply the dependencies printed by `ux:install`.
- Preserve strict CSP. Do not add inline event handlers, style attributes or unnonced inline scripts. Request-aware CSP nonces must remain distinct for scripts and styles and must not persist across FrankenPHP worker requests.
- Treat UXor's demo as a UI/development reference. Its gallery, lab routes, kit-source autoloading and parent-directory asset paths do not belong in a consuming application.

## Verification and repository changes

Verify concrete behaviour: application boot, HTTP readiness, real MCP exchange, durable work completion and artifact retrieval. Prefer a small meaningful verification set over tests that repeat the implementation.

Use pull requests for repository changes. State what was checked and what could not be run. Do not claim a Docker build, browser interaction or live test passed without observing it. Keep credentials, connection strings and raw internal exceptions out of health/MCP status responses and logs intended for broad diagnostic retrieval.
