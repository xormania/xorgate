# JUnit producer fixtures

These fixtures preserve observed reporter output. Each XML report has a neighboring
provenance JSON file that records the source revision, producer version, exact
origin, original report digest, retained digest, and any minimization or redaction.
Missing provider, run, attempt, job, timestamp, or retry metadata stays null.

## UXor JavaScript tool tests

`uxor-node.xml` preserves all cases from an actual execution of
[UXor's shard-plan tool tests](https://github.com/xormania/uxor/blob/9b2fa7309786cee435503de87cf0cd5f0a9d9782/tools/tests/shard-plan.test.mjs)
in a clean checkout at `9b2fa7309786cee435503de87cf0cd5f0a9d9782`. Node
`v24.19.0` ran the tests with its built-in JUnit reporter and exited 0. The
provenance file records the invocation with its transient output destination
represented symbolically. This was a local execution of actual
UXor tests; it was not downloaded from a GitHub Actions run.

The native `<testsuites>` root contains 15 direct `<testcase>` elements and no
`<testsuite>` elements. All 15 cases passed. Their `time` attributes total
`0.601737` seconds. Native diagnostic comments retain the runner's totals and
overall duration, which are separate from standard suite attributes. The root
does not supply count, timestamp, or time attributes. The report supplies no
case retry identity. Its longest testcase name is 128 characters.

The original report had 3,594 bytes and SHA-256
`18abffcc9def78dbb50374f8fef9010f49584bafe3a1786b66f51756a75638b5`.
The retained report removes only the transient absolute checkout prefix from
the 15 `file` attributes, leaving `tools/tests/shard-plan.test.mjs`. Testcase
names, outcomes, durations, diagnostic comments and other XML bytes are
unchanged. Its separate byte count and SHA-256 appear in the provenance JSON.

See the versioned
[Node JUnit reporter source](https://github.com/nodejs/node/blob/v24.19.0/lib/internal/test_runner/reporter/junit.js)
for its direct testcase layout, nested suites, seconds, failure elements, and
skipped/todo elements. These extension behaviors describe the producer; this
passing fixture does not claim to have exercised failure or skip outcomes.

## Native producer boundaries

Current UXor CI was checked at dev commit
`40d957cd1ea7766ac0dee742816948ce5ae05bc3`, run
[38524244935](https://github.com/xormania/uxor/actions/runs/38524244935), attempt 1.
Its [workflow](https://github.com/xormania/uxor/blob/40d957cd1ea7766ac0dee742816948ce5ae05bc3/.github/workflows/ci.yml)
invokes PHPUnit's native JUnit reporter, but does not preserve the original XML
as an uploaded artifact. Its
[Playwright configuration](https://github.com/xormania/uxor/blob/40d957cd1ea7766ac0dee742816948ce5ae05bc3/playwright.config.ts)
uses list, HTML, and JSON reporters. Uploaded Playwright JSON is therefore not
represented here as an original JUnit report.

PHPUnit `13.4.1` and Playwright `1.58.2` were observed in that revision's dependency
locks. Their native output contracts are documented in the pinned
[PHPUnit JUnit logger](https://github.com/sebastianbergmann/phpunit/blob/ccde40327c501eebff192614b0dcf11806418901/src/Logging/JUnit/JunitXmlLogger.php)
and [Playwright JUnit reporter](https://github.com/microsoft/playwright/blob/v1.58.2/packages/playwright/src/reporters/junit.ts).
Both aggregate case durations; neither report should be assumed to supply a
separate provider run attempt or a complete structured case retry history.

Controlled producer reports or hand-authored edge fixtures must identify that
origin explicitly in their provenance. Fixture reports are not application
seed data and make no claim about managed-project coverage.

## Controlled PHPUnit producer input

`ProducerCases.php` contains four deliberately named cases: pass, assertion
failure, runtime error, and skip. Its filename does not match application
`*Test.php` discovery. The file is invoked explicitly with PHPUnit's native
`--log-junit` reporter, outside the application test suite. The resulting XML
must be captured from that execution and labeled a controlled producer report;
the PHP source alone is not evidence that it ran.

The native PHPUnit execution returns exit status 2 when these cases include
the deliberate error. Validation also finds exactly these four cases and one
outcome of each kind. An exit status by itself cannot distinguish intended
fixture outcomes from a configuration or runtime failure.

`controlled-phpunit.xml` was captured from actual
[Xorgate Docker CI run 38534840861](https://github.com/xormania/xorgate/actions/runs/38534840861),
attempt 1, job `115755100852` (`Docker foundation`). The application container
reported PHP `8.5.11` and PHPUnit `13.4.2`. All four native outcomes were emitted
and the exact-case producer validation passed. The full CI run later failed
when an application integration-test helper collided with an inherited final
method; this controlled report does not claim the application suite passed.

The recorded checkout was the synthetic PR merge revision
`4e6f05f3932c0d2cb618bc5f51ce1d1f11562d20`, combining base
`1b72935bc3a2ff439931337ac34dfd54abc7e717` and PR head
`e55fc794538fa73a0454c3538780488ee4db9f02`. The producer proof recorded a dirty
workspace. These source facts remain separate in the provenance JSON, with no
invented workspace fingerprint.

The original report and producer proof came from evidence artifact
`11879202888`. Its ZIP SHA-256 was verified against GitHub's artifact metadata:
`d9962a688ef146ab23b971b6199d45c8caa8432322dd4eead5b0f708a3f9aa2f`.
Original report SHA-256 is
`8d09a401caa0cb5e6cbcba8b7c2bb6787fee37afddfdb6177a1029241307a256`.
The retained fixture removes only seven `/app/` path prefixes from source
attributes and diagnostics. Its SHA-256 is
`1baecadc766c718d4d1734fe4ba093af629fb67497437020cc0138b77beeae42`.
Names, native child elements, assertion counts, diagnostics and measured times
are otherwise unchanged.

The report has one `<testsuite>` and four cases: one passed, one failure, one
error, and one skipped. Case times total `0.001691` seconds; the suite reports
`0.001690`, preserving the producer's independent rounding. It supplies neither
a producer timestamp nor a structured case retry identity. The configured
image and build target are recorded as source facts; an observed Docker image
ID was not captured.

See the observed version's
[PHPUnit JUnit logger](https://github.com/sebastianbergmann/phpunit/blob/13.4.2/src/Logging/JUnit/JunitXmlLogger.php)
for the native case attributes and outcome elements.
