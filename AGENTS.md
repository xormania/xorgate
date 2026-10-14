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

The `php` service is the PHP 8.5 application runtime. Run Symfony Console, Makers, Mate, Composer and application checks there so they use the application's installed dependencies, cache, configuration and database network. The Messenger `worker` uses the same application image and shared dependency/cache volumes; restart it after handler or service changes. A separate CLI/tool image does not establish that the application boots.

Host Python 3 drives the live development smoke checks. Use `./bin/check-dev` for focused Mate environment and opt-in profiler checks; the full `./bin/check` includes them. Serena acceptance is separate: pull its pinned image, then run `python3 tests/smoke/development.py serena` when source-tool readiness needs verification.

Use the installed framework capabilities before writing substitutes. Discover Makers with `docker compose exec -T php php bin/console list make`, inspect a command with `docker compose exec -T php php bin/console help make:command --format=json`, then run the appropriate `make:*` command. Use Mate's installed diagnostics for measured Symfony facts, and Serena for source navigation and edits in the checkout. Read their tool descriptions and project instructions; these development tools do not define Xorgate's managed-project domain or public MCP interface.

Use committed dependency locks for normal installs. `./bin/setup --resolve` is the explicit initial-resolution/update path. Commit generated locks and relevant installed UXor recipe files after review. Keep command documentation aligned with the implemented scripts.

## UXor UI

- UXor is a Symfony UX Toolkit kit. Install a missing component with `ux:install` using this application's pinned kit revision, read its recipe README and apply its printed dependencies. Use the installed components rather than recreating their markup or behaviour.
- Installed recipes are application-owned files under `templates/components/`, `templates/layouts/`, `templates/form/`, `assets/controllers/` and `assets/styles/`. Extend `templates/layouts/app.html.twig` for the application shell and use the documented component contracts.
- Use AssetMapper/importmap, Stimulus, Turbo and TailwindBundle. Keep application behaviour in Stimulus controllers and preserve Turbo navigation and reconnect behaviour.
- Use UXor theme roles and component recipes. Install only recipes that current screens require; apply the dependencies printed by `ux:install`.
- Preserve strict CSP. Do not add inline event handlers, style attributes or unnonced inline scripts. Request-aware CSP nonces must remain distinct for scripts and styles and must not persist across FrankenPHP worker requests.
- Render native Symfony forms through `form/flowbite_layout.html.twig`, retain CSRF protection, redirect successful mutations with HTTP 303 and redisplay invalid forms with HTTP 422. Give stateful forms and regions stable IDs. Use the installed toast stream contract for flash messages.
- Verify actual stored/observed state, unknown values and empty states, plus direct and Turbo navigation, invalid form retention, keyboard use and narrow-screen behaviour when a UI change affects them.
- Treat UXor's demo as a UI/development reference. Its gallery, lab routes, kit-source autoloading and parent-directory asset paths do not belong in a consuming application.

## Verification and repository changes

Verify concrete behaviour: application boot, HTTP readiness, real MCP exchange, durable work completion and artifact retrieval. Prefer a small meaningful verification set over tests that repeat the implementation.

Keep check evidence tied to what ran: command, exit status, source revision, dirty workspace state and relevant runtime facts. A passing lint, Maker generation or diagnostic call proves its own measured condition. Retain useful logs or report artifacts when a check fails and describe gaps in what was exercised.

Use pull requests for repository changes. State what was checked and what could not be run. Do not claim a Docker build, browser interaction or live test passed without observing it. Keep credentials, connection strings and raw internal exceptions out of health/MCP status responses and logs intended for broad diagnostic retrieval.

Request one code review and one security review after CI is green. Repeat a review only for a practical P1 finding.

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate: project diagnostic tools, exposed through the `./bin/mate` CLI.

- Provenance: this block is generated by `./bin/mate discover`. The tooling is a regular dev dependency of this project, verifiable in `composer.json` (`symfony/ai-mate`); the binary lives at `vendor/bin/mate`.
- Invocation: always run Mate as `./bin/mate`; another interpreter reports on a runtime that is not this application's, and Mate refuses to start under one.
- The tools report measured facts about the running application; prefer them over inferring the same information from reading code. They are described in `mate/AGENT_INSTRUCTIONS.md`.
- Discover the tool surface with `./bin/mate tools:list`, inspect parameters with `tools:inspect <tool>`, and run a tool with `tools:call <tool> --<param>=<value>` (add `--format=json` for machine-readable output).
- Installed extensions: symfony/ai-mate, symfony/ai-monolog-mate-extension, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->
