# Playwright producer fixtures

This directory holds two kinds of fixture, kept apart:

- `uxor-ci/`: genuine UXor GitHub Actions artifacts, retained byte for byte
  (described below). Each shard directory has one `provenance.json`.
- The `controlled-*`, `global-error-*` and `not-reached-*` files: output from
  controlled local Playwright runs and from UXor's real CI tools run locally on
  that output. Each has a neighboring `<stem>.provenance.json` that records the
  exact command, environment, versions, exit status, timestamps, original and
  retained SHA-256 and byte counts, and any redaction. Missing provider, run,
  attempt, job or commit metadata stays null.

**The controlled files are controlled local producer runs, not UXor CI run
artifacts.** Their outcomes are deliberately induced by the specs in
`producer/`; they say nothing about UXor, Xorgate or any managed project.
No fixture here is application seed data, and none makes a coverage claim.

## Genuine UXor CI artifacts: `uxor-ci/`

Three demo shards of UXor's `.github/workflows/ci.yml` (job `demo`, named
`Demo + Playwright (<browser> <shard>/<shards>)`), retrieved on 2026-10-18
through the GitHub REST API for `xormania/uxor`:

| Directory | Run / attempt | Branch, head SHA | Job | Run and job conclusion |
| --- | --- | --- | --- | --- |
| `38195645868-1-chromium-3` (retry-recovered) | [38195645868](https://github.com/xormania/uxor/actions/runs/38195645868) / 1 | `main`, `9b2fa7309786cee435503de87cf0cd5f0a9d9782` | `114637652679` `Demo + Playwright (chromium 3/4)` | success, success |
| `38135099353-1-webkit-3` (red) | [38135099353](https://github.com/xormania/uxor/actions/runs/38135099353) / 1 | `dev`, `71f7dffe771b932e3158026146db093f7bc9bb50` | `114457320257` `Demo + Playwright (webkit 3/3)` | failure, failure (step `Playwright (smoke + every README example + upstream recipe specs)`) |
| `38122728519-1-chromium-3` (setup failure) | [38122728519](https://github.com/xormania/uxor/actions/runs/38122728519) / 1 | `claude/release-security`, `f932602c4bde5c02c2295826c1fb121d2d67dbd8` | `114420959828` `Demo + Playwright (chromium 3/4)` | failure, failure (step `Plan the shard's tests`; the Playwright step was skipped) |

Artifacts, each ZIP's SHA-256 computed after download and equal to the
artifact `digest` GitHub reports:

| Directory | Artifact id, name | ZIP bytes | ZIP SHA-256 | Expires |
| --- | --- | --- | --- | --- |
| `38195645868-1-chromium-3` | `11723661692` `playwright-results-chromium-3` | 74,272 | `5d8bf8d1dccefc50606fdac474e61154d82e0ea3f87b604efe3d3b27da1909be` | 2026-11-11 |
| `38195645868-1-chromium-3/jev` | `11723223196` `jev-chromium-3-38195645868-1` | 3,400 | `fa8471c710bd247d821fc345c6801aa88db9e95324c8611ad9a89039af1ea1a6` | 2026-11-11 |
| `38135099353-1-webkit-3` | `11700763009` `playwright-results-webkit-3` | 52,733 | `89280abc29024210276d96929d853fbe669cba4f4f85120d3b13bce3b8ebe477` | 2026-11-10 |
| `38135099353-1-webkit-3/jev` | `11700967630` `jev-webkit-3-38135099353-1` | 6,559 | `9a7fa8047c98aa5c74cadda61c51264127ad59146c7dda4968bfb1531099bd49` | 2026-11-10 |
| `38122728519-1-chromium-3` | `11696026781` `playwright-results-chromium-3` | 1,805 | `1cf29d079272600efbceeb0800b4f88d98887d75e37a5d522eed27995d58c5a4` | 2026-11-10 |

From each `playwright-results-*` artifact, `results.json`,
`failed-attempts.json`, `durations.json` and `summary.md` are retained; from
each `jev-*` artifact, every `attempts/<NN>-<slug>/assessment.json` and its
`summary.md` (under `jev/`, same relative paths). The setup-failure artifact
has no `results.json` and no Jev artifact exists for it. Retained bytes equal the
extracted files: no redaction, no reformatting, runner paths such as
`/home/runner/work/uxor/uxor` included. `job-metrics.json` and
`shard-plan.json` (where present) were not retained; their bytes and SHA-256 are recorded in
`provenance.json` with every other file's. A case-insensitive search for
`TYPESAFE`, `token`, `ghp_`, `Bearer`, `password`, `secret`, `api_key`,
`authorization` and `credential` found no credential: the assessments'
`policy.connection.credential` reads `[REDACTED]` as UXor's tool writes it,
`input_tokens`/`output_tokens` are usage counts, and the other matches are
test titles (`forgot-password`) and rendered documentation text.

**Retry-recovered shard, chromium 3/4.** Playwright `1.58.2`, workers 2. The
report lists six configured projects; this shard ran tests of `smoke` (296)
and `examples` (131). 427 tests and 428 results; `stats` expected 426, flaky 1,
unexpected 0, skipped 0; no global errors. The flaky test is
`[smoke] tests/e2e/editor.spec.ts:105 › the link dialog sets allowed links,
refuses other schemes, and removes links`: retry 0 `failed` (an
`expect(...).toBe` mismatch, two attachments), retry 1 `passed`.
`failed-attempts.json` (schema 1, status `valid`, shard `chromium 3/4`) has
counts passed 426, failed 0, flaky 1, skipped 0 and lists that one retry-0
attempt. Its Jev assessment (model `jev-1.13.0`, status `assessed`) answers
`product_defect` with confidence 0.60, below the 0.65 threshold, so
`likely_cause` is `unknown`. The retry is Playwright's in-job retry: the
workflow run has one attempt and concluded success.

**Red shard, webkit 3/3.** Playwright `1.58.2`, workers 2. Projects
`smoke-webkit` (243) and `examples-webkit` (8); 251 tests, 252 results;
`stats` expected 250, unexpected 1, flaky 0, skipped 0; no global errors. The
unexpected test is `[smoke-webkit] tests/e2e/demo-app.spec.ts:298 › every page
of the gallery and of the demo names an icon, so a browser asks for no
/favicon.ico`: retries 0 and 1 both `failed` (failed requests reported by the
page). `failed-attempts.json` (status `valid`, shard `webkit 3/3`) has counts
passed 250, failed 1, flaky 0, skipped 0 and lists both attempts; there is
one Jev assessment per attempt, both `assessed` with `likely_cause` `unknown`.
No not-reached reason applies: this is a test failure, not a setup failure.

**Setup failure, chromium 3/4.** Every demo shard of run `38122728519`
failed at `Plan the shard's tests`, so Playwright never ran and wrote no
`results.json`. `failed-attempts.json` and `durations.json` (schema 1) have
status `not-reached`; the former's reason is `tests not reached: setup failed
at Plan the shard's tests`, `counts` null, no tests or attempts; the latter
names `unavailable` `no report`. No Jev step ran (nothing failed in a test).
This is a feature-branch merge commit, not `dev` or `main`.

Neither retained `results.json` has `config.shard`: UXor ran each shard from its own
timing plan (`shard-plan.json` mode `timings`, Playwright `--test-list`), so
only the UXor summaries name the shard label.

How they were found: completed `ci.yml` runs were listed newest first and
every non-cancelled run's artifacts listed (226 runs). Retry-recovered
candidates were runs that uploaded a `jev-*` or `playwright-report-*` artifact
and concluded success; their `failed-attempts.json` counts were read. Run
`38195645868` was chosen because it is on `main`, at the UXor revision the
controlled tool outputs used, and carries its Jev assessment. Two other
genuine flaky-only shards were inspected and not retained: run `38208156925`
(dev) chromium 4/4, and run `39115566914` (dev) attempt 2 webkit 3/3, whose
`jev-input-ci-*` artifact holds only Jev input (no assessment: the
`jev.yml` consumer is not on UXor's default branch). Both attempts of
`39115566914` concluded success; it is a re-run, not a red first attempt.
Within the 30-day window no demo shard with status `not-reached`, `missing`
or `invalid` was found on `dev` or `main`. Of the failed runs, `39125095760`,
`38667295346` and `38666790905` failed outside the demo job and
`39116712390` and `38343316481` only in `CI result`; `38633205140` and
`38121815655`, `38097472226` and `38135099353` (above) failed in the
Playwright step; `38122728519` (above) was the only failed run whose demo
shards failed before the tests (failing steps read from the jobs API).

What these files support: a genuine green run whose first attempt of a test
failed keeps both attempts and the `flaky` verdict; a genuine red run keeps
its unexpected failure distinct from expected results; UXor's genuine
`failed-attempts.json` and Jev assessments link to exact native attempts
without adding attempts; a genuine Jev assessment is readable without calling
Jev; no shard number or completeness is claimed when the native report names
none; a genuine setup failure is visible as a `not-reached` producer status
with no counts and no attempts. What they do not support: a genuine missing
or invalid report status, a missing shard, global errors, the HTML report, traces or
server logs (not retained), the other shards of either run (only one shard
of ten is retained per run; each retained run uploaded all ten
`playwright-results-*` artifacts), or any claim about UXor's overall test coverage.
Those remain covered only by the controlled fixtures, or not at all.

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
