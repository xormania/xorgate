## Playwright, shard chromium 1/2: 2 failed, 2 flaky (passed on retry), 8 passed, 2 skipped

| Passed | Failed | Flaky (passed on retry) | Skipped |
|---:|---:|---:|---:|
| 8 | 2 | 2 | 2 |

- Tested commit: unknown (GITHUB_SHA not set)
- Shard: chromium 1/2; projects: controlled, controlled-dark; 14.7 s
- Runtime: Node v22.22.0, Playwright 1.58.2

## Failed (2)

- **[controlled] tests/controlled.spec.js:13 › always fails** (2 attempts, all failed)

  Attempt 1 (retry 0), failed at tests/controlled.spec.js:14, 0.0 s:

  ```
  Error: controlled persistent failure
  
  expect(received).toBe(expected) // Object.is equality
  
  Expected: 3
  Received: 2
  ```

  Attempt 2 (retry 1), failed at tests/controlled.spec.js:14, 0.0 s: the same error

- **[controlled-dark] tests/controlled.spec.js:13 › always fails** (2 attempts, all failed)

  Attempt 1 (retry 0), failed at tests/controlled.spec.js:14, 0.0 s:

  ```
  Error: controlled persistent failure
  
  expect(received).toBe(expected) // Object.is equality
  
  Expected: 3
  Received: 2
  ```

  Attempt 2 (retry 1), failed at tests/controlled.spec.js:14, 0.0 s: the same error

## Flaky: failed, then passed on retry (2)

- **[controlled] tests/controlled.spec.js:9 › flaky: fails on the first attempt, passes on retry** (failed 1 of 2 attempts, passed on retry 1)

  Attempt 1 (retry 0), failed at tests/controlled.spec.js:10, 0.0 s:

  ```
  Error: controlled first-attempt failure
  
  expect(received).toBeGreaterThan(expected)
  
  Expected: > 0
  Received:   0
  ```

- **[controlled-dark] tests/controlled.spec.js:9 › flaky: fails on the first attempt, passes on retry** (failed 1 of 2 attempts, passed on retry 1)

  Attempt 1 (retry 0), failed at tests/controlled.spec.js:10, 0.0 s:

  ```
  Error: controlled first-attempt failure
  
  expect(received).toBeGreaterThan(expected)
  
  Expected: > 0
  Received:   0
  ```

Every failed attempt, retry-recovered ones included: `failed-attempts.json` next to the report (`playwright-results/`).

## Durations (report only)

- Wall time: 14.7 s, 1 worker
- Summed test time: 1.2 s over 18 attempts; workers run tests side by side, so it is not the wall time

### Slowest 10 tests

| Time | Attempts | Test |
|---:|---:|---|
| 0.8 s | 1 | [controlled] tests/controlled.spec.js:4 › passes |
| 0.3 s | 1 | [controlled-dark] tests/controlled.spec.js:4 › passes |
| 0.0 s | 2 | [controlled] tests/controlled.spec.js:13 › always fails |
| 0.0 s | 2 | [controlled-dark] tests/controlled.spec.js:9 › flaky: fails on the first attempt, passes on retry |
| 0.0 s | 2 | [controlled-dark] tests/controlled.spec.js:13 › always fails |
| 0.0 s | 2 | [controlled] tests/controlled.spec.js:9 › flaky: fails on the first attempt, passes on retry |
| 0.0 s | 1 | [controlled] tests/controlled.spec.js:17 › expected failure |
| 0.0 s | 1 | [controlled-dark] tests/controlled.spec.js:17 › expected failure |
| 0.0 s | 1 | [controlled] tests/controlled.spec.js:26 › writes stdout and stderr |
| 0.0 s | 1 | [controlled] tests/controlled.spec.js:22 › skipped |

### Per file

| Time | Tests | File |
|---:|---:|---|
| 1.2 s | 14 | tests/controlled.spec.js |

The same numbers, each test included: `durations.json` next to the report (`playwright-results/`).
