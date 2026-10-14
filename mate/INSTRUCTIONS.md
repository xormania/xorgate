# Developing Xorgate with Symfony

Use the installed PHP and Symfony capabilities before adding infrastructure.
Prefer native attributes, autowiring, autoconfiguration, validation, forms,
Doctrine transactions and Messenger where they implement the needed behavior.
Inspect existing services and installed Maker commands before generating code.

Run Mate through `./bin/mate`, which uses the application's PHP 8.5 container,
Composer dependencies and shared cache/log volume. Discover native tools with
`./bin/mate tools:list`, inspect their inputs with `tools:inspect`, and call them
with `tools:call` and `--format=json` for measured diagnostics.

Service inspection reads the compiled development container. Refresh it with
`docker compose exec -T php php bin/console cache:clear --env=dev` after wiring
changes. Opt into a real HTTP profile with `?profile=1`; profiler inspection reads
those request profiles. Monolog inspection
reads the retained development JSON logs, including the worker's correlated events.
Missing profiles or logs mean missing observations, rather than successful checks.

Read `AGENTS.md` and `docs/development.md` for the application boundaries and
verification workflow. Keep development diagnostics within the local environment.
