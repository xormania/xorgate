# Xorgate — Symfony 8.1 web application and UXor development setup

Research and planning reference prepared before the application foundation. The implemented startup path is documented in [README](../README.md) and [development](development.md); use those commands for this checkout.

Date: 2026-10-12 (America/New_York)  
Owner: xor  
Status: researched implementation guide; commands and proposed application configuration have not been executed as a new xorgate application in this session.

Companion: [Xorgate system design and scope](system-design.md).

## 1. Goal and scope

Bring up xorgate's own Symfony 8.1 web application with UXor UI, a repeatable Docker development environment, and foundations for persistence, asynchronous work and MCP. The complete product remains the language-neutral testing and CI management system described in the companion document.

This guide distinguishes three things:

1. **Observed reference implementation:** what the current UXor demo and its fresh-install checks actually contain.
2. **Bootstrap instructions:** how to construct a standalone xorgate web application using those references.
3. **Proposed xorgate services:** database, evidence storage, workers, execution adapters and management interfaces that still require implementation and validation.

At inspection, `xormania/xorgate` contains `LICENSE` only. There is no existing xorgate installer, application image or management implementation to claim as ready. This session creates documentation and changes no repository. [R0]

## 2. Sources and reproducible reference points

| Source | Inspected revision | Use |
| --- | --- | --- |
| UXor `main` | `9b2fa7309786cee435503de87cf0cd5f0a9d9782` | Released UI reference, demo setup and fresh-install procedure |
| UXor `dev` | `0f1a9585eeea89017544ce2639b676b38d393e22` | Checked for more recent development changes; six commits ahead of inspected `main` |
| Symfony Docker template used by UXor's fresh-install test | `422756611d61e0108600ed7ec1370ec677d0e8d0` | Container scaffold and first-start Symfony installation |
| Xorder `master` | `e81396fb53b51b110542b0500978880bcabf27b9` | Available resources, exact image identities and readiness guidance |

The inspected `dev` comparison changes CI, test-tier documentation and related tools; it does not change the demo files used below. Use the immutable UXor `main` SHA above for this reproducible starting point. Consult the current branch before intentionally adopting newer changes. [R1]

### Observed versions

- Symfony 8.1 requires PHP 8.4 or later. The official release page and UXor demo lock both identify 8.1.8 at inspection. [S1, R2]
- UXor's demo runs PHP 8.5 through FrankenPHP. Its Composer constraints target Symfony `8.1.*` and PHP `>=8.4`. [R2, R3]
- Its lock records UX Toolkit, Twig Components, Live Components, Turbo, Icons and StimulusBundle at 3.5.1; TailwindBundle at 1.0.0. [R2]
- The demo explicitly selects Tailwind CLI `v4.3.3`. Its browser-test package uses Playwright 1.58.2. [R4, R5]

These are source observations, not a reason to copy the demo's whole dependency graph. Xorgate must generate and own its own `composer.lock`, `symfony.lock`, importmap and any test-tool lockfiles.

## 3. What to reuse from the demo

| Reference | Reuse in xorgate | Adaptation |
| --- | --- | --- |
| Symfony Docker / FrankenPHP | Docker-based PHP runtime, development worker reload, HTTP/HTTPS, development and production build stages | Application root is `/app`; document root is `/app/public` |
| AssetMapper + Stimulus + Turbo | Importmap assets and navigation | Install into xorgate's own application |
| UXor recipes | Theme, application layouts and selected components | Install pinned recipes with `ux:install`; xorgate owns the installed copies |
| TailwindBundle | Standalone Tailwind build and watch commands | Scan xorgate's templates and controllers |
| Live Components | Interactive filters, selections and other suitable regions | Keep each region's rendering ownership explicit |
| Request-aware CSP nonces | Nonce generation compatible with persistent PHP workers | Adapt the relevant services and response policy to xorgate |
| Browser-test conventions | Exercise navigation, rerenders, keyboard behaviour and real HTTP responses | Create tests for xorgate's own behaviours |

The demo mounts the entire UXor repository at `/app`, serves `/app/demo/public`, and reads kit source from parent directories. Its Composer autoload rules and services map some PHP classes to recipes outside `demo/`; its stylesheet imports `../../../kit.css`; `tools/sync-demo` regenerates installed copies. Those are kit-development arrangements. A consuming application uses its own `src/`, `templates/` and `assets/`, populated through recipe installation. [R2–R4, R6]

The gallery renderer, `KitReader`, static-export commands, lab routes, demo branding and demo-specific production boot guard belong to the reference application. Build xorgate's application routes and lifecycle separately.

## 4. Recommended development environment

Use Symfony Docker and FrankenPHP as the first reproducible web-development path. It is the path UXor's `tools/tests/docker-install.sh` exercises for a fresh Symfony 8.1 consumer. PHP and Composer run inside the container. [R7]

Prerequisites for the documented Linux/WSL shell commands:

- A functioning Docker engine and Compose plugin; confirm both `docker info` and `docker compose version`.
- Git, Bash and ordinary file-copy tools.
- Access to the selected container registries, Composer package archives, GitHub kit archives and frontend asset sources.
- Available local ports and sufficient space for images, dependencies, browser binaries and evidence.

Node is a development/test requirement when adding the Playwright harness. The AssetMapper/TailwindBundle application asset path itself uses Symfony's importmap and the standalone Tailwind binary. The UXor repository's own test setup uses Node 22. [R6]

### Xorder availability at inspection

Xorder's current capability guide lists `profile/uxor` and `profile/php-http` as `missing_required_releases`. Its project-specific UXor group has no verified available release. The existence of an image definition or a detailed usage page does not make an `uxor-dev` image pullable. [X1]

A verified PHP CLI development resource is available:

```text
ghcr.io/xormania/php-dev@sha256:cd670b7c7b9624ce7dc7ee6b7b2a4d5b19ce36d6226f077a417667ae8505e000
```

This is the inspected PHP 8.5 development image, revision 1.2.1, verified for `linux/amd64`. It contains Composer, Symfony CLI, PostgreSQL client/extensions and PHP test tooling. It is useful for CLI/bootstrap/test work; the Docker web path below supplies FrankenPHP separately. Follow its linked readiness recipe and platform requirements before selecting it. [X2]

When an appropriate xorder HTTP or project profile becomes verified and available, it can replace repeated toolchain builds. Keep application configuration and project dependency locks owned by xorgate. Do not invent an xorgate image tag before it is built, tested and published.

## 5. Bootstrap the standalone application

### 5.1 Assemble the container scaffold

This sequence assumes the inspected LICENSE-only xorgate checkout. If application files have appeared since inspection, reconcile with that checkout instead of overwriting it.

Prepare two reference checkouts outside the xorgate repository:

```bash
git clone https://github.com/dunglas/symfony-docker.git xorgate-docker-reference
git -C xorgate-docker-reference checkout 422756611d61e0108600ed7ec1370ec677d0e8d0

git clone https://github.com/xormania/uxor.git xorgate-uxor-reference
git -C xorgate-uxor-reference checkout 9b2fa7309786cee435503de87cf0cd5f0a9d9782
```

From the xorgate checkout, set the paths to those reference directories:

```bash
export XORGATE_DOCKER_REF=/absolute/path/to/xorgate-docker-reference
export XORGATE_UXOR_REF=/absolute/path/to/xorgate-uxor-reference

cp "$XORGATE_DOCKER_REF/Dockerfile" Dockerfile
cp "$XORGATE_DOCKER_REF/.dockerignore" .dockerignore
cp "$XORGATE_DOCKER_REF/compose.override.yaml" compose.override.yaml
cp "$XORGATE_DOCKER_REF/composer.json" composer.json
cp -R "$XORGATE_DOCKER_REF/frankenphp" frankenphp
cp "$XORGATE_UXOR_REF/demo/compose.yaml" compose.yaml
cp "$XORGATE_UXOR_REF/demo/frankenphp/Caddyfile" frankenphp/Caddyfile
```

The template's `composer.json` is initially `{}`. Its entrypoint detects that placeholder and creates the Symfony project. Copy only the named scaffold files; retain xorgate's Git history, license and repository identity. Keep the existing license available while generating the skeleton and verify it is retained afterward. Preserve the upstream template's applicable license notices when incorporating its files. [R7, D1]

Make these explicit adaptations before startup:

1. In `compose.override.yaml`, remove the `MERCURE_EXTRA_DIRECTIVES: demo` entry. UXor's fresh-install check does this because the current Mercure module needs updated configuration. The copied UXor Caddyfile supplies the corresponding issuer configuration. [R7, R8]
2. Retain the template's `./:/app` mount. Use `/app` as the working directory and `/app/public` as the document root. Do not transplant the demo override's `/app/demo` paths.
3. Give `php` an explicit `working_dir: /app` if it is not already set.
4. Pin the template's two upstream build images to the identities recorded in the inspected UXor Dockerfile:

```dockerfile
FROM dunglas/frankenphp:1-php8.5@sha256:a052ae7cccd4a025eca17767e7acac57a4fc660a4f3dfc790be9b9cd5182d6e6 AS frankenphp_upstream
```

```dockerfile
FROM debian:13-slim@sha256:a29215f6a35e51e22adffa17f89e9d2ef06214e64a2bad10d765c46aea49f11f AS frankenphp_prod
```

Use the upstream template Dockerfile with these pins, rather than copying the demo Dockerfile's demo-specific production-build exception. The immutable source file is the reference if either pin is intentionally upgraded. [R3]

### 5.2 First start

Choose a distinct Compose project and available loopback ports. The following are proposed local defaults:

```bash
export COMPOSE_PROJECT_NAME=xorgate-dev
export IMAGES_PREFIX=xorgate-
export SYMFONY_VERSION='8.1.*'
export HTTP_PORT=8088
export HTTPS_PORT=8448
export HTTP3_PORT=8448
export XDEBUG_MODE=off

docker compose config --quiet
docker compose build --pull
docker compose up --wait --wait-timeout 600
docker compose exec -T php php bin/console about
```

The first start creates the Symfony skeleton, installs dependencies and removes the template's one-time installation blocks. The proposed browser address is `https://localhost:8448`; configure trust for Caddy's local development certificate. [D1]

Keep these Compose settings consistent between terminals. After bootstrap, document/store them in an appropriate local Compose environment file or launcher. Symfony's `.env.local` is not automatically the same thing as Compose's interpolation input; explicitly pass a Compose `--env-file` when using a separate file.

### 5.3 Expand to a web application

```bash
docker compose exec -T php composer config extra.symfony.require '8.1.*'
docker compose exec -T php composer config extra.symfony.allow-contrib true
docker compose exec -T php composer require webapp
```

Symfony documents the `webapp` pack as the web-application expansion of its skeleton. [S2]

Review the Flex changes before restarting. With the template's Docker integration enabled, database recipes can add a `database` service and PHP extensions. Align `DATABASE_URL`, the PostgreSQL service's database/user/password, and its `serverVersion`; then rebuild as needed:

```bash
docker compose config --quiet
docker compose up --build --wait --wait-timeout 600
docker compose exec -T php php -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
docker compose exec -T php php bin/console dbal:run-sql 'SELECT 1'
```

The initial UXor-derived base Compose file contains a database URL but no database service. The new application's Doctrine/Flex setup must provide the actual database and driver. Do not interpret an environment variable alone as working persistence. Inspect the resulting Dockerfile for `pdo_pgsql`; if the recipe did not add it, add it through the existing `install-php-extensions` step and rebuild. [R3, S3]

Before sharing the environment, record the selected PostgreSQL version and immutable image identity along with the application image pins.

## 6. Install UXor as an application dependency

UXor is a Symfony UX Toolkit kit. `ux:install` copies selected recipe files into the application; the project owns those copies. Follow the dependency order that UXor's README and fresh-install tests establish. [R9, R10]

### 6.1 Packages

```bash
docker compose exec -T php composer require symfony/twig-bundle 'symfony/ux-twig-component:^3.5' symfony/http-client
docker compose exec -T php composer require --dev 'symfony/ux-toolkit:3.5.1'
docker compose exec -T php composer require symfony/asset-mapper symfony/stimulus-bundle symfonycasts/tailwind-bundle
docker compose exec -T php php bin/console tailwind:init
```

Choose Tailwind major version 4. The explicit Toolkit 3.5.1 matches the inspected fresh-install test; change it deliberately if adopting a newer tested version. `symfony/http-client` is a runtime dependency here because xorgate's provider integrations will use HTTP, even though UXor's installation-only example groups it with the development Toolkit package.

Keep Twig and Twig Components as runtime dependencies. Allow contrib recipes before installing recipe-suggested packages so that the bundle supplying `tailwind_classes` is registered. PHP needs `zip` for kit archive extraction and `intl` for the relevant localized components. [R9, R10]

### 6.2 Styles and theme

```bash
docker compose exec -T php php bin/console importmap:require 'flowbite/dist/flowbite.min.css@^4.0.2'
docker compose exec -T php php bin/console ux:install theme --kit=https://github.com/xormania/uxor:9b2fa7309786cee435503de87cf0cd5f0a9d9782
```

Review and execute any dependency commands printed by the recipe installer, inside the same application container. Do this after each recipe and before installing another recipe that depends on its PHP services.

Make the beginning of `assets/styles/app.css`:

```css
@import 'tailwindcss';
@import '../vendor/flowbite/dist/flowbite.min.css';
@import './uxor.css';
```

Ensure `assets/app.js` imports the Stimulus bootstrap and this stylesheet. Use local application paths, not the demo's parent-directory imports. [R4, R9]

For the inspected Tailwind baseline, configure `config/packages/symfonycasts_tailwind.yaml`:

```yaml
symfonycasts_tailwind:
    binary_version: 'v4.3.3'
```

This records the application's Tailwind choice. Xorder's cache may satisfy it when the matching resource is available; the cache does not choose the application's version. [R4, X3]

### 6.3 Layouts and first page

```bash
docker compose exec -T php php bin/console ux:install layouts --kit=https://github.com/xormania/uxor:9b2fa7309786cee435503de87cf0cd5f0a9d9782
```

Execute the printed Composer dependency command before continuing. The layouts recipe includes navigation, toast and other recipe dependencies and requires Turbo. [R11]

Create `src/Controller/DashboardController.php`:

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }
}
```

Create `templates/dashboard/index.html.twig` using the installed layout:

```twig
{% extends 'layouts/app.html.twig' %}

{% block title %}Xorgate{% endblock %}
{% block brand %}<a href="{{ path('app_dashboard') }}">Xorgate</a>{% endblock %}
{% block sidebar %}
    <twig:Sidebar:Group label="Management">
        <twig:Sidebar:Item href="{{ path('app_dashboard') }}" route="app_dashboard">Overview</twig:Sidebar:Item>
    </twig:Sidebar:Group>
{% endblock %}
{% block page_title %}Xorgate{% endblock %}
{% block content %}
    <p class="text-body">The application foundation is running. No project evidence has been imported yet.</p>
{% endblock %}
```

This is foundation content, not a fabricated dashboard of completed tests. Add views backed by real application queries as the domain is implemented.

Useful subsequent recipes include `data-table`, `data-table-live`, `stat-card`, `chart`, `tabs`, `progress`, `empty-state` and `form-theme`. Install each when its screen requires it, read that recipe's README, and apply its printed Composer/importmap instructions. `dashboard-home` is also available as a layout/content reference. [R12]

### 6.4 Build and verify the page

```bash
docker compose exec -T php php bin/console importmap:install
docker compose exec -T php php bin/console ux:icons:lock
docker compose exec -T php php bin/console tailwind:build
docker compose exec -T php php bin/console lint:twig templates
docker compose exec -T php php bin/console lint:container
docker compose exec -T php php bin/console debug:router
```

Open the page and check that theme switching, sidebar navigation and responsive layout work. Run `ux:icons:lock` again after adding icon names and commit the downloaded icon assets. Keep `tailwind:build --watch` running in a separate development terminal. [R9, R12]

## 7. Development conventions from UXor

### Application assets and behaviour

- Use UXor theme roles for colours and the installed recipes for their supported UI elements.
- Put behaviour in Stimulus controllers. UXor's components do not need Flowbite's JavaScript initializer.
- Retain Turbo's navigation model. Successful form submissions should use the appropriate redirect response; validation responses should expose the invalid form with the expected status.
- Use stable element identities where Live Components or Turbo rerender stateful components.
- Give each rendered region an explicit owner; coordinate Live and Turbo updates instead of independently replacing the same region.
- Keep application-specific scripts and styles in the asset pipeline.

The source for these conventions is UXor's `FOR-AGENTS.md` and its individual recipe READMEs. Adapt the project-agent snippet to xorgate; it is not the domain model for managed projects. [R12, R13]

### CSP and persistent workers

Use the installed layouts' `csp_script_nonce` and `csp_style_nonce` hooks when setting the application's content policy. UXor's demo `CspNonce` stores generated values on the current main request, which avoids retaining one nonce through a long-lived worker's Twig globals. Its security-header listener is a concrete reference for integrating those values into responses. [R14]

Adapt the nonce services and Twig globals selectively. The demo's complete `services.yaml` includes kit-source discovery and exclusions that do not belong in xorgate. Test ordinary navigation and Turbo visits under the resulting policy. The layouts have a documented nonced theme bootstrap; do not add new inline application behaviour casually. [R11, R14]

### Daily commands

```bash
docker compose up --wait
docker compose exec php php bin/console tailwind:build --watch
docker compose logs -f php
docker compose exec -T php composer validate --strict
docker compose exec -T php composer check-platform-reqs
docker compose down
```

The watch and log commands run in separate terminals. Keep project names and port variables consistent with bootstrap. For normal dependency installation, use the committed lockfiles. Treat a blocked package download as a specific environment issue; xorder's usage guidance includes controlled dependency reuse and dist-only installation practices. [X3]

When adding browser tests, give xorgate its own npm manifest and lock. Match the browser image to that lock's Playwright version. UXor's inspected reference is Playwright 1.58.2 with a digest recorded in `tools/playwright-image.json`; it is a reference pin, not evidence that xorgate's future tests have run. [R5]

Symfony AI Mate is an optional diagnostic tool for developing xorgate's Symfony application. The demo installs it as a development dependency and discovers it through `vendor/bin/mate`. Keep that development tooling distinct from xorgate's public MCP interface for managing arbitrary projects. [R6, R15]

## 8. Add xorgate's management foundations

The following is a proposed implementation mapping. It develops the agreed system scope; it does not finalize the data model.

| Concern | Initial implementation proposal |
| --- | --- |
| Domain records and relationships | Doctrine with PostgreSQL; explicit schema migrations |
| Raw logs and artifacts | Persistent evidence volume, accessed through an artifact-storage service |
| Background orchestration and ingestion | Symfony Messenger with a Doctrine transport initially |
| Project execution | Runner adapter contract with local/container/remote implementations |
| Web screens | Symfony controllers, Twig, UXor, Turbo and selected Live Components |
| Agent interface | Symfony MCP bundle calling the same application services |
| Telemetry | Structured application/runner events and trace correlation; exporter/backend chosen separately |
| Live status delivery | Application queries initially; Mercure/Turbo integration where live updates are useful |

Possible package additions, after the webapp pack and its recipes have been reviewed:

```bash
docker compose exec -T php composer require symfony/orm-pack symfony/messenger symfony/doctrine-messenger symfony/process symfony/uid symfony/lock symfony/mcp-bundle
docker compose exec -T php composer require --dev symfony/maker-bundle
```

These packages provide infrastructure. Coverage semantics, evidence relationships, recovery, ingestion and diagnostic insight still require xorgate application code. [S3–S5]

### Persistence

Place source-independent operational data in named persistent volumes. Mount the evidence store at a dedicated path such as `/var/lib/xorgate/evidence`, shared only by the services that need it. Keep it separate from disposable Symfony cache directories.

Use explicit migrations for the evolving domain schema. Once migrations exist:

```bash
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php php bin/console doctrine:schema:validate
```

Make the startup/migration owner explicit when adding multiple processes. The inspected Symfony Docker entrypoint runs migrations when it finds them; spawning the same entrypoint for every worker would otherwise create competing migration attempts. Adapt that responsibility before scaling workers. [D1]

### Asynchronous work

Messenger's Doctrine transport can store queued messages in the database. A first setup can use `doctrine://default` and an explicitly configured `async` transport, with a separate failed transport. Add routing when concrete message classes exist. [S4]

For local development, `messenger:setup-transports` can initialize configured transport storage. For repeatable deployment, record transport schema in migrations and disable automatic table creation. Once the transport is configured:

```bash
docker compose exec -T php php bin/console messenger:setup-transports
docker compose exec php php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M
```

Move the consumer into its own Compose service for normal use. Reuse the application image, source and relevant environment configuration; give it the same database connection and required artifact access. Use a worker entrypoint with a defined startup path, and replace the web image's inherited HTTP healthcheck with a worker-appropriate readiness/liveness check.

Set retry, redelivery, keepalive and execution-lease behaviour against the actual jobs being managed. A queue retry must not accidentally create an unrelated duplicate test execution. Those semantics follow the companion design and require validation beyond bringing up a consumer.

### Runner boundary

Keep orchestration workers and project execution as separate responsibilities. A runner needs project-specific runtime access; the Symfony web application needs persistent management state. Design the adapter contract before choosing how runners reach host processes, Docker or remote agents. Record the source/environment context of every execution.

This boundary is what allows the same Symfony management application to manage Python, Rust, JavaScript and other projects. Source bind mounts are a local execution option, not a requirement in the public run contract.

## 9. Add MCP to the same application

The current Symfony MCP bundle exposes tools, resources and prompts through HTTP and STDIO using the official PHP SDK. Pin the resolved dependency versions in xorgate's Composer lock. [S5]

For an initial real smoke capability, create `src/Mcp/RuntimeStatus.php`. This example reports runtime health and a real database query; it makes no claim that test management or coverage analysis is implemented:

```php
<?php

namespace App\Mcp;

use Doctrine\DBAL\Connection;
use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\HttpKernel\Kernel;

final class RuntimeStatus
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[McpTool(name: 'xorgate_runtime_status')]
    public function status(): array
    {
        return [
            'application' => 'xorgate',
            'framework_version' => Kernel::VERSION,
            'database_reachable' => 1 === (int) $this->connection->fetchOne('SELECT 1'),
            'scope' => 'runtime health',
        ];
    }
}
```

The skeleton's normal `App\` service registration must include this class with autowiring and autoconfiguration enabled. Keep the operation's name and schema provisional until the public MCP contract is designed.

After that service carries an MCP tool attribute, the documented configuration shape is:

```yaml
# config/packages/mcp.yaml
mcp:
    servers:
        xorgate:
            name: xorgate
            transports:
                http: true
                stdio: true
            http:
                path: /mcp
            registry:
                tools:
                    - 'App\Mcp\'
```

```yaml
# config/routes/mcp.yaml
mcp:
    resource: .
    type: mcp
```

The registry must match at least one actual registered capability; do not add this configuration as a substitute for implementing the smoke tool. The installed bundle's versioned schema is authoritative if its configuration changes. [S5]

Inspect and start it through the application container:

```bash
docker compose exec -T php php bin/console debug:mcp
docker compose exec -T php php bin/console mcp:server xorgate
```

The second command is the STDIO server process. Use it through a client's command configuration, with the correct Compose working directory/project selection; keep protocol output clean. HTTP clients use the reachable `/mcp` endpoint. A remote client cannot reach a developer's loopback address without an appropriate connection arrangement.

As domain work lands, add MCP tools for project discovery, run control, CI operations, selective evidence retrieval, coverage queries and diagnostics. They should dispatch/query common application services and return persistent operation identifiers for asynchronous work. Apply the same project/operation authorization as the web interface.

## 10. Development acceptance and delivery sequence

### Application foundation

Verify the actual built environment, not only the configuration files:

1. The container runs Symfony 8.1 with its required PHP extensions.
2. An xorgate route renders the installed UXor layout through HTTP.
3. CSS, importmap modules, icons, theme changes and Turbo navigation work.
4. A real database operation succeeds and persistent data survives an ordinary stack restart.
5. A concrete background message can be consumed and its failure path is observable once handlers exist.
6. An MCP client calls the runtime smoke tool successfully through the chosen transport.

Caddy's metrics endpoint is not sufficient proof that the application can render its page. UXor's xorder guidance explicitly tests a served application URL; follow that principle for xorgate readiness. [X3]

### First useful xorgate capability

The next delivery should exercise the domain: register a project, ingest or execute one native check, retain its logs/artifact, map it to a system verification target, and expose the resulting history and coverage gap through both UI and MCP.

Validate at least one non-PHP case early. A Symfony-only demonstration does not establish a language-neutral integration contract.

### Later installation into managed projects

Once the application and runner contracts work, publish a versioned application image and Compose distribution. A project initializer can then produce a language-neutral manifest, environment configuration and agent connection details. Keep database/artifact identities stable as users move toward shared services and remote runners.

This delivery order provides a runnable foundation while retaining the full scope of data, logs, insight, overall-system coverage and CI management.

## 11. Packaging and deployment considerations

- Development binds xorgate source into its own containers; a distributable runtime image contains the built application and dependencies.
- Build Tailwind output before compiling AssetMapper assets in the production build. The inspected template only explicitly runs `asset-map:compile`; add the application-specific Tailwind build at the correct point. [R3]
- Separate migration execution from ordinary worker startup, and retain database/artifact volumes during routine upgrades.
- Configure real application secrets, project authorization and the intended Mercure/MCP access before external exposure. The demo's loopback development defaults are a reference for local work, not deployment credentials.
- Use xorder's current discovery index and release evidence for image selection. Record exact identities; refresh them intentionally after validation. [X1]

## 12. References

Repository links below identify inspected files at immutable revisions. Official documentation links describe upstream mechanisms; recheck them when selecting newer versions.

- **[R0]** [Xorgate repository contents](https://github.com/xormania/xorgate) — observed LICENSE-only on 2026-10-12; branch content can change.
- **[R1]** [UXor main-to-dev comparison](https://github.com/xormania/uxor/compare/9b2fa7309786cee435503de87cf0cd5f0a9d9782...0f1a9585eeea89017544ce2639b676b38d393e22).
- **[R2]** [Demo composer.json](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/composer.json) and [composer.lock](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/composer.lock).
- **[R3]** [Demo Dockerfile](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/Dockerfile), [base Compose](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/compose.yaml) and [development override](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/compose.override.yaml).
- **[R4]** [Demo CSS](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/assets/styles/app.css), [app.js](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/assets/app.js) and [Tailwind configuration](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/config/packages/symfonycasts_tailwind.yaml).
- **[R5]** [Browser-test package](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/package.json) and [browser image pin](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/tools/playwright-image.json).
- **[R6]** [UXor contribution guide — Setup](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/CONTRIBUTING.md#setup) and [demo service configuration](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/config/services.yaml).
- **[R7]** [Fresh Symfony Docker installation test](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/tools/tests/docker-install.sh).
- **[R8]** [Demo Docker provenance and compatibility notes](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/docs/NOTES.md#symfony-docker-demo-only) and [Caddyfile](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/frankenphp/Caddyfile).
- **[R9]** [UXor README](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/README.md) and [installation guide](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/INSTALL.md).
- **[R10]** [Shared fresh-install scenario](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/tools/tests/install-scenario.sh).
- **[R11]** [Layouts README](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/layouts/README.md) and [recipe manifest](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/layouts/manifest.json).
- **[R12]** [UXor guidance for agents and recipe catalogue](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/FOR-AGENTS.md).
- **[R13]** [Consumer project-agent guidance](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/docs/PROJECT-AGENTS-SNIPPET.md).
- **[R14]** [Request-aware nonce service](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/src/Security/CspNonce.php), [security-header listener](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/src/EventListener/SecurityHeadersListener.php), and [Twig globals](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/config/packages/twig.yaml).
- **[R15]** [Demo agent instructions](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/demo/AGENTS.md).
- **[D1]** [Symfony Docker template](https://github.com/dunglas/symfony-docker/tree/422756611d61e0108600ed7ec1370ec677d0e8d0), [Dockerfile](https://github.com/dunglas/symfony-docker/blob/422756611d61e0108600ed7ec1370ec677d0e8d0/Dockerfile) and [entrypoint](https://github.com/dunglas/symfony-docker/blob/422756611d61e0108600ed7ec1370ec677d0e8d0/frankenphp/docker-entrypoint.sh).
- **[X1]** [Xorder README](https://github.com/xormania/xorder/blob/e81396fb53b51b110542b0500978880bcabf27b9/README.md) and [capability guide](https://github.com/xormania/xorder/blob/e81396fb53b51b110542b0500978880bcabf27b9/docs/capabilities.md).
- **[X2]** [Verified PHP 8.5 CLI development image](https://github.com/xormania/xorder/blob/e81396fb53b51b110542b0500978880bcabf27b9/docs/images/php-dev-8.5-trixie.md).
- **[X3]** [Xorder UXor environment usage and readiness guidance](https://github.com/xormania/xorder/blob/e81396fb53b51b110542b0500978880bcabf27b9/docs/uxor.md).
- **[S1]** [Symfony 8.1 release information](https://symfony.com/releases/8.1).
- **[S2]** [Creating a Symfony web application](https://symfony.com/doc/current/setup.html#creating-symfony-applications).
- **[S3]** [Doctrine setup and migrations](https://symfony.com/doc/current/doctrine.html).
- **[S4]** [Messenger transports, consumers and failure handling](https://symfony.com/doc/current/messenger.html).
- **[S5]** [Symfony MCP bundle](https://symfony.com/doc/current/ai/bundles/mcp-bundle.html).

## 13. Validation status of this document

Repository files, release information and upstream setup documentation were inspected. The source revisions and example commands were cross-checked for consistency. No container was built, no dependencies were installed and no xorgate application tests were executed during this documentation task. The guide therefore supplies a grounded implementation path and explicit acceptance checks, not a report of a completed deployment.
