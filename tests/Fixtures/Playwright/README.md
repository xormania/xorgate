# Playwright producer fixtures

These fixtures preserve output from controlled local Playwright runs and from
UXor's real CI tools run locally on that output. Each fixture has a neighboring
`<stem>.provenance.json` that records the exact command, environment, versions,
exit status, timestamps, original and retained SHA-256 and byte counts, and any
redaction. Missing provider, run, attempt, job or commit metadata stays null.

**These are controlled local producer runs, not UXor CI run artifacts.** The
outcomes are deliberately induced by the specs in `producer/`; they say nothing
about UXor, Xorgate or any managed project. Fixture reports are not application
seed data and make no coverage claim.

## Still required: genuine UXor CI artifacts

Acceptance item 1 of issue #4 (WO-003) needs imports of a genuine UXor
retry-recovered (green, with a failed first attempt) run and a genuine red run.
Those artifacts could not be downloaded in this session: GitHub API access to
`xormania/uxor` artifacts was not granted. Nothing here stands in for them.

To obtain them later, from the `demo` job (`Demo + Playwright (<browser>
<shard>/<shards>)`) of UXor's `.github/workflows/ci.yml`:

- `playwright-results-<browser>-<shard>`: the shard's `playwright-results/`
  directory, uploaded green or red, retained 30 days. It holds `results.json`,
  `failed-attempts.json`, `durations.json`, `job-metrics.json`,
  `shard-plan.json` and `summary.md`.
- `jev-<browser>-<shard>-<run_id>-<run_attempt>`: uploaded when the shard had a
  failed or flaky test, retained 30 days. It holds
  `attempts/<NN>-<slug>/assessment.json` per failed attempt and `summary.md`.
- `playwright-report-<browser>-<shard>` (HTML report and traces, 7 days) is
  optional context, not an input of these adapters.

Pick one run whose shard was green with `flaky > 0` and one red run. Record, per
artifact: repository, run id, run attempt, job id and name, head SHA and branch,
workflow path at that SHA, the artifact id, name, `archive_download_url` and
the ZIP SHA-256 verified against GitHub's artifact `digest`, `created_at` and
`expires_at`, each extracted file's bytes and SHA-256, and any redaction (as
the JUnit fixtures do). Keep `config.rootDir` and other runner paths only in a
symbolic form, and check that no credential or token is present.

## Controlled run: `controlled-results.json`

`producer/playwright.config.js` and `producer/tests/controlled.spec.js` ran with
`@playwright/test` `1.58.2` (UXor's pinned version, installed from the npm
registry with the same lock integrity as UXor), Node `v22.22.0`, npm `10.9.4`,
on Linux, using the preinstalled Chromium build `chromium-1194` through
`launchOptions.executablePath`. The command was
`npx playwright test --shard=1/2` with `retries: 1`, one worker and the
reporters `[['list'], ['json', { outputFile: 'playwright-results/results.json' }]]`.
It exited 1 because the always-failing test is an unexpected failure.

The config has three projects. Sharding puts `controlled.spec.js` for projects
`controlled` and `controlled-dark` in shard 1/2 and the 14-test `companion`
project in shard 2/2, which was not run. `config.projects` therefore lists
three projects while the suites contain tests of two. The report has
`config.shard` `{current: 1, total: 2}` and `config.version` `1.58.2`.

Per project, seven tests: `passes` (uses `page.setContent`),
`flaky: fails on the first attempt, passes on retry` (retry 0 failed, retry 1
passed; native status `flaky`), `always fails` (two failed results; native
`unexpected`), `expected failure` (`test.fail()`, `expectedStatus` `failed`, one
failed result; native `expected`), `skipped` (`test.skip()`), `writes stdout and
stderr` (two stdout and two stderr chunks) and
`outer describe › inner describe › nested passes`. Totals: 14 tests
(8 expected, 2 unexpected, 2 flaky, 2 skipped, matching `stats`), 18 results
(8 passed, 8 failed, 2 skipped), no attachments, no global errors. Error
messages and snippets retain Playwright's ANSI colour escapes. The file has no
trailing newline, as written.

The original report had 44,880 bytes and SHA-256
`06d1bca8045f3fed5937af90caa5272d7aeaf59454bcb458dd29d04b2d617ced`. The
retained file replaces only the transient absolute scratch directory prefix
(56 occurrences: rootDir, configFile, testDir, outputDir, error stacks and
locations) with `/workspace/controlled-playwright`. All other bytes are
unchanged.

## `controlled-server.log`

The combined stdout/stderr of that same run (Playwright's list reporter,
redirected to a file): 147 LF-terminated lines, 7,214 retained bytes, with the
tests' stdout/stderr lines and ANSI escapes. The name follows the WO-003
contract; it is not a web server log, and no server ran. Only the scratch path
prefix was replaced (6 occurrences).

## `global-error-results.json`

`producer/global-error/` has one passing spec and one spec that throws while
Playwright loads it. `npx playwright test` exited 1. Playwright 1.58.2 then runs
no test: `suites` is empty, every `stats` count is 0, and `errors[]` holds one
error with message `Error: controlled error while loading the spec file` and an
absolute (here symbolic) location, line 4, column 7. The run is not sharded.

## UXor tool outputs

UXor was checked out read-only at
`9b2fa7309786cee435503de87cf0cd5f0a9d9782`; its tracked and untracked state was
clean before and after. Its tools ran under `env -i` with only `PATH`, `HOME`
and the documented variables, so all `GITHUB_*` values are unset and
`sha`, `ref`, `runUrl` and `runAttempt` are null.

- `controlled-failed-attempts.json`, `controlled-durations.json`,
  `controlled-summary.md`: `tools/ci/playwright-summary.mjs` on the unredacted
  `results.json` with `SHARD=1/2 BROWSER=chromium` (exit 0). The shard label is
  `chromium 1/2`. Failed attempts (schema 1, status `valid`) lists 4 tests and
  6 attempts: the flaky test's retry 0 in both projects and both retries of the
  always-failing test in both projects. Expected failures are not listed.
  Durations (schema 1) counts 18 attempts, 0 untimed; its `tests[]`,
  `slowest[]` and `files[]` carry project/file/line/title but no spec id.
  These files contain no absolute paths and are byte-identical to the output.
- `not-reached-failed-attempts.json`: the same tool, pointed at a nonexistent
  report in a separate directory with `SETUP_STEPS='Start the demo=failure'`
  (exit 4). Status `not-reached`, reason
  `tests not reached: setup failed at Start the demo`, `counts` null. No setup
  step actually ran; the outcome is declared exactly as UXor CI passes it.
- `controlled-jev-assessment.json`: `tools/ci/jev-diagnosis.mjs --attempts
  playwright-results/failed-attempts.json --output jev --job 'Controlled
  producer'` with `SHARD='chromium 1/2'` and no `TYPESAFE_API_KEY` (exit 0).
  It wrote six assessments, all `unavailable` / `missing_credential`, with no
  request or response. The retained one is the first: the flaky test's retry-0
  attempt in project `controlled`. The other five and the run's `summary.md` are
  recorded by digest in its provenance. The policy's `credential` field reads
  `[REDACTED]` because the tool's own credential filter writes it so.

These derived files annotate the native report; they are not native evidence
and were not uploaded by UXor CI.
