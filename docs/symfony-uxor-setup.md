# Symfony and UXor development setup

This is the setup reference for the implemented Xorgate application. Start an existing checkout with `./bin/setup`; the daily commands and tool configuration are in [Developing Xorgate](development.md). Xorgate manages projects independently of their languages and runtimes. The Symfony tools described here develop Xorgate itself.

## Application runtime

Xorgate runs Symfony 8.1 in the PHP 8.5 FrankenPHP image pinned by [Dockerfile](../Dockerfile) and [config/resources.json](../config/resources.json). [compose.yaml](../compose.yaml) sets the application root to `/app`, mounts this checkout there and provides PostgreSQL, Messenger and persistent artifact storage.

| Service | Purpose | Application resources |
| --- | --- | --- |
| `php` | Web application, Symfony Console, Composer, Makers and Mate | `/app`, installed `vendor`, `var` cache/log volume, PostgreSQL network and artifact volume |
| `worker` | Durable Messenger consumer | Same application image, source, dependencies, cache, database and artifact storage |
| `database` | PostgreSQL records and Doctrine Messenger transport | Persistent database volume |
| `serena` development profile | Source navigation and opt-in edits | Checkout at `/workspace`, application dependencies mounted read-only, private index cache |

Run application commands in the `php` service so dependency resolution, diagnostics and tests observe the installed application runtime:

```bash
./bin/setup
docker compose exec -T php php --version
docker compose exec -T php composer check-platform-reqs
docker compose exec -T php php bin/console about
./bin/check
```

The scripts need Docker with Compose and Bash. Checks also need Python 3 on the host to drive HTTP/MCP smoke clients; PHP and Composer remain inside the application container.

Setup installs committed dependencies, applies migrations, prepares the selected UI assets and starts the application/worker. `./bin/setup --resolve` is the explicit dependency-update path. Review changed `composer.lock`, `symfony.lock`, importmap entries and generated instructions after a dependency update. Restart the worker after changes to handlers or service definitions.

The development ports are loopback HTTP 8088 and optional HTTPS 8448. Caddy's `SERVER_NAME` and Symfony's `XORGATE_TRUSTED_HOSTS` serve different purposes; the [development guide](development.md) records the host configuration. Keep Compose project names and ports consistent across terminals so helpers and tests use the intended stack.

## Installed framework and source capabilities

Symfony Console discovers the application's actual commands. Makers generate code using their installed command metadata and framework conventions. Mate exposes the installed development diagnostics and Symfony extension; discovery describes the available tools and profiler resources. Serena supplies source operations in a checkout-bound container. Each tool has a concrete scope:

| Question or action | Tool |
| --- | --- |
| What installed command or Maker is available, and which arguments does it accept? | Symfony Console `list` and `help --format=json` |
| Generate framework code | Installed `make:*` command, followed by review and behaviour checks |
| What does this configured Symfony application report about its runtime, container or routes? | Installed Mate diagnostics or Symfony Console introspection |
| Locate a symbol, inspect its references, or edit source | Serena's advertised source APIs |
| Verify application behaviour and persistent services | Repository checks and live HTTP/MCP/worker tests |
| Query registered projects or immutable execution context | Xorgate's public MCP/CLI services |

Makers, Mate and Toolkit are development dependencies. Their installed versions come from Xorgate's Composer lock. Run them through the documented container commands/helpers rather than resolving a different PHP installation or copying a reference application's dependency graph. Read Mate's generated project instructions and Serena's initial session instructions before using their advertised tools.

The local tooling provides installed framework capabilities, a correctly bound workspace, explicit process results and retained evidence. Use it directly through the documented CLI or MCP client configuration. A source query or successful diagnostic call establishes the facts it returned; application execution and browser behaviour require their own checks.

Mate's configured invocation is `./bin/mate`, its allowed environments are dev/test and its PHP guard matches the 8.5 application image. Symfony cache and profiler paths live under the application root, and the Monolog extension reads the shared development log directory. HTTP profiling is opt-in with `?profile=1`; the toolbar remains disabled. Discovery regenerates the installed tool instructions while preserving manual project guidance outside its managed block.

Serena uses the exact published image digest recorded in `config/resources.json`, with PHPactor and TypeScript language servers. `./bin/serena pull` downloads that image explicitly. The helper starts an MCP stdio connection bound to this checkout, read-only by default; `--write` enables source edits while the application dependency mount stays read-only. Each worktree and image digest owns a private persisted index under `.serena/cache/`; connection state remains temporary. Start with the server's initial instructions and inspect its advertised facades before using them. Twig/YAML operations are textual rather than language-server reference queries.

## UXor as a consuming application

UXor is a Symfony UX Toolkit kit. Xorgate uses Toolkit 3.5.1 to install selected recipes at immutable revision `9b2fa7309786cee435503de87cf0cd5f0a9d9782`. The installed files belong to this application. [bin/install-ui](../bin/install-ui) restores missing `theme`, `layouts`, `form-theme`, `table`, `empty-state` and `card` recipes with their dependencies and prepares importmap, icons and Tailwind.

| Installed content | Application destination |
| --- | --- |
| Twig components | `templates/components/` |
| Page layouts | `templates/layouts/` |
| Native Symfony form theme | `templates/form/flowbite_layout.html.twig` |
| Behaviour | `assets/controllers/` and installed shared modules |
| Theme roles | `assets/styles/uxor.css` |
| Downloaded icons | `assets/icons/` |

Install only the recipes a screen needs, read the pinned recipe README and apply the Composer/importmap commands printed by Toolkit. Review the generated sources and dependency locks. Existing application-owned recipes are preserved by normal setup. An intentional update uses the chosen immutable revision and a reviewed diff; the demo's synchronization command is for maintaining the kit demo.

The application asset entrypoint imports the Stimulus bootstrap and local CSS. The stylesheet's import order is:

```css
@import 'tailwindcss';
@import '../vendor/flowbite/dist/flowbite.min.css';
@import './uxor.css';
```

AssetMapper/importmap loads browser assets without a bundler. TailwindBundle runs the standalone Tailwind CLI, currently pinned to `v4.3.3`; its application sources include `templates/` and `src/`. Use theme roles for colours and the installed Twig components for supported UI elements. Behaviour belongs to Stimulus controllers and must survive Turbo visits and component reconnection. UXor's components use their own controllers and shared modules.

```bash
docker compose exec -T php php bin/console importmap:install
docker compose exec -T php php bin/console ux:icons:lock
docker compose exec -T php php bin/console tailwind:build
docker compose exec -T php php bin/console lint:twig templates
docker compose exec -T php php bin/console lint:container
```

Use `tailwind:build --watch` in a separate terminal while editing templates. Run Toolkit in the development environment, where its bundle is registered. Icon names must be complete literals so `ux:icons:lock` finds and downloads them.

## Native forms, Turbo and CSP

Native Symfony forms render through `form/flowbite_layout.html.twig`. The theme composes UXor's FormField and controls, connecting labels, help, errors and accessible descriptions. Apply it locally with `form_theme` or globally through `twig.form_themes`.

Keep CSRF enabled and validate on the server. Successful writes redirect with HTTP 303; invalid forms return HTTP 422 with the entered values and field errors. Render explicit form IDs and give stateful components stable identities. Use the installed toast streams for flashes, including after Turbo navigation. Decide whether Turbo or a Live Component owns a rendered region before adding partial updates.

Xorgate's CSP services retain separate script/style nonce values on the current main request. The installed base layout uses those hooks for its theme/importmap bootstrap and Turbo progress styling. This is compatible with persistent FrankenPHP workers. Keep additional application behaviour in asset files and retain the response policy; inline event handlers and style attributes are outside the nonce contract.

Check affected pages through ordinary loads and Turbo visits. Useful UI evidence includes actual empty/unknown states, escaped stored metadata, CSRF rejection, invalid form retention, correctly connected labels/errors, keyboard focus, theme changes and narrow-screen navigation. Passing configuration lint alone does not exercise these behaviours.

## What the public demo demonstrates

The current public UXor `dev` reference was inspected on 2026-10-14 at `7e482b3090a9c989183bb0cdefd326876f707e94`; its default branch is `main`, at the installed recipe revision above. Reading the current demo informs the workflow without changing Xorgate's reviewed kit pin.

| Reference | Reusable lesson | Xorgate adaptation |
| --- | --- | --- |
| [Demo AGENTS](https://github.com/xormania/uxor/blob/7e482b3090a9c989183bb0cdefd326876f707e94/demo/AGENTS.md) and [Composer manifest](https://github.com/xormania/uxor/blob/7e482b3090a9c989183bb0cdefd326876f707e94/demo/composer.json) | Mate is installed and its tools are discovered from the application | Invoke Xorgate's installed Mate through `./bin/mate` in `php` |
| [Demo container setup](https://github.com/xormania/uxor/tree/7e482b3090a9c989183bb0cdefd326876f707e94/demo) | Run PHP commands in the application's service and prepare assets before browser checks | Xorgate works in `/app`; the demo works in `/app/demo` and serves `/app/demo/public` |
| [Form controller](https://github.com/xormania/uxor/blob/7e482b3090a9c989183bb0cdefd326876f707e94/demo/src/Controller/FormsController.php), [form type](https://github.com/xormania/uxor/blob/7e482b3090a9c989183bb0cdefd326876f707e94/demo/src/Form/DemoType.php) and [form browser tests](https://github.com/xormania/uxor/blob/7e482b3090a9c989183bb0cdefd326876f707e94/tests/e2e/forms.spec.ts) | Native constraints, form-theme accessibility, 303/422 responses and light/dark form rendering | Verify Xorgate's own registration/edit/context forms and actual stored data |
| [Framework configuration](https://github.com/xormania/uxor/blob/7e482b3090a9c989183bb0cdefd326876f707e94/demo/config/packages/framework.yaml) | Profiler collection can be opt-in; tests collect when explicitly enabled | Collect profiles deliberately when an HTTP diagnostic needs request evidence |
| [Consumer guide](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/docs/PROJECT-AGENTS-SNIPPET.md) and [installation guide](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/INSTALL.md) | Install recipes and use their actual contracts and dependencies | Keep Xorgate's installed files, source roots and command scripts owned here |

The demo's kit-source autoloading, parent-directory stylesheet imports, gallery renderer, lab routes and `tools/sync-demo` support development of the kit itself. Xorgate uses its own application routes, source roots and recipe installations. The demo's PHP minimum/Composer platform is 8.4 while its Docker image runs 8.5; its Mate configuration therefore does not enforce one PHP minor version. Xorgate's runtime and Mate configuration must be checked against this application's chosen container.

## Verification and persistent data

`./bin/check` validates installed dependencies, PHP/configuration/container/templates and the application test suite against a separate migrated test database, then runs `./bin/check-dev`. The focused helper verifies Mate environment/container facts and an opt-in HTTP profile against the same running application, using host Python 3 and retaining observations in `var/ci/development.json`.

Serena acceptance is a separate `python3 tests/smoke/development.py serena` invocation after `./bin/serena pull`. Docker CI runs the development consumer checks and also reproduces Toolkit installation, compares generated sources, and exercises actual public HTTP/MCP/CLI, worker completion/logs, artifact bytes and restart persistence. Refer to a recorded result for the exact revision when stating that a check passed.

Keep measured process results associated with their command, source revision, dirty workspace state and relevant runtime facts. Preserve original report/log artifacts where needed, with their identity and integrity metadata. Successful native generation or runtime readiness provides evidence for that capability; managed-project test outcomes and coverage require their own imported evidence.

PostgreSQL and artifact volumes contain persistent development data. Keep them together in backups. Ordinary container restart retains them; removing volumes deletes their contents. Report ingestion, remote execution, CI-provider adapters and assessments remain separate implementation work under the [system design](system-design.md) and [project registry contract](project-registry.md).
