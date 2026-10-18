## Playwright, shard chromium 3/4: 1 flaky (passed on retry), 426 passed

| Passed | Failed | Flaky (passed on retry) | Skipped |
|---:|---:|---:|---:|
| 426 | 0 | 1 | 0 |

- Tested commit: `9b2fa7309786cee435503de87cf0cd5f0a9d9782` (main)
- Shard: chromium 3/4; projects: smoke, examples; 375.4 s
- Runtime: Node v22.23.3, PHP 8.5.11, Symfony 8.1.8, Turbo 8.0.23, Playwright 1.58.2
- Run: https://github.com/xormania/uxor/actions/runs/38195645868 (attempt 1)

## Flaky: failed, then passed on retry (1)

- **[smoke] tests/e2e/editor.spec.ts:105 › the link dialog sets allowed links, refuses other schemes, and removes links** (failed 1 of 2 attempts, passed on retry 1)

  Attempt 1 (retry 0), failed at tests/e2e/editor.spec.ts:122, 1.8 s:

  ```
  Error: expect(received).toBe(expected) // Object.is equality
  
  Expected: "<p><a rel=\"noopener noreferrer nofollow\" href=\"/pricing\">See pricing</a></p>"
  Received: "<p>See pricing<a rel=\"noopener noreferrer nofollow\" href=\"/pricing\">/pricing</a></p>"
  ```

Every failed attempt, retry-recovered ones included: `failed-attempts.json` in the `playwright-results-chromium-3` artifact; traces and the HTML report in `playwright-report-chromium-3`.

## Durations (report only)

- Wall time: 375.4 s, 2 workers
- Summed test time: 725.6 s over 428 attempts; workers run tests side by side, so it is not the wall time

### Slowest 10 tests

| Time | Attempts | Test |
|---:|---:|---|
| 8.6 s | 1 | [smoke] tests/e2e/forms.spec.ts:205 › every Input pads its text the same, with or without a type |
| 7.8 s | 1 | [smoke] tests/e2e/hostile-props.spec.ts:244 › components drop attributes named on…, check URL attributes in any case, and render the others |
| 6.3 s | 1 | [smoke] tests/e2e/lab.autocomplete.spec.ts:189 › without the layouts' form-reset controller, a reset cancelled in a capture listener changes nothing; the next one, from form.reset(), puts back the values |
| 5.6 s | 1 | [smoke] tests/e2e/lab.autocomplete.spec.ts:189 › with the layouts' form-reset controller, a reset cancelled in a capture listener changes nothing; the next one, from form.reset(), puts back the values |
| 5.4 s | 1 | [smoke] tests/e2e/a11y.spec.ts:30 › a11y /r/editor |
| 5.3 s | 1 | [smoke] tests/e2e/a11y.spec.ts:30 › a11y /r/spinner |
| 5.0 s | 1 | [smoke] tests/e2e/a11y.spec.ts:30 › a11y /r/avatar |
| 4.6 s | 1 | [smoke] tests/e2e/a11y.spec.ts:30 › a11y /lab/live-values |
| 4.6 s | 1 | [smoke] tests/e2e/demo-app.spec.ts:298 › every page of the gallery and of the demo names an icon, so a browser asks for no /favicon.ico |
| 4.2 s | 1 | [smoke] tests/e2e/a11y.spec.ts:30 › a11y /r/sidebar |

### Per file

| Time | Tests | File |
|---:|---:|---|
| 237.9 s | 114 | tests/e2e/a11y.spec.ts |
| 91.3 s | 74 | tests/e2e/examples/examples.spec.ts |
| 84.8 s | 53 | tests/e2e/lab.value-matrix.spec.ts |
| 52.7 s | 24 | tests/e2e/demo-app.spec.ts |
| 45.7 s | 41 | tests/e2e/examples/fit.spec.ts |
| 18.7 s | 6 | tests/e2e/forms.spec.ts |
| 15.5 s | 4 | tests/e2e/lab.autocomplete.spec.ts |
| 12.1 s | 6 | tests/e2e/lab.overlays.spec.ts |
| 10.9 s | 10 | tests/e2e/csp.spec.ts |
| 10.6 s | 5 | tests/e2e/lab.section-nav.spec.ts |
| 9.9 s | 8 | tests/e2e/motion.spec.ts |
| 8.5 s | 7 | tests/e2e/examples/fixtures.ts |
| 8.2 s | 5 | tests/e2e/theme-toggle.spec.ts |
| 7.9 s | 2 | tests/e2e/lab.data-table-interrupt.spec.ts |
| 7.9 s | 2 | tests/e2e/hostile-props.spec.ts |
| 7.3 s | 4 | tests/e2e/lab.nav-menu.spec.ts |
| 7.2 s | 5 | tests/e2e/lab.turbo-restore.spec.ts |
| 6.9 s | 3 | tests/e2e/lab.editor.spec.ts |
| 6.5 s | 3 | tests/e2e/lab.side-nav.spec.ts |
| 5.5 s | 2 | tests/e2e/editor.spec.ts |
| 5.0 s | 4 | tests/e2e/smoke.spec.ts |
| 4.9 s | 3 | tests/e2e/lab.mobile-nav.spec.ts |
| 4.5 s | 2 | tests/e2e/lab.turbo-stream-toast.spec.ts |
| 3.8 s | 3 | tests/e2e/calendar.spec.ts |
| 3.8 s | 2 | tests/e2e/lab.tooltip.spec.ts |
| 3.7 s | 2 | tests/e2e/lab.dropzone.spec.ts |
| 3.4 s | 2 | tests/e2e/lab.date-picker.spec.ts |
| 3.4 s | 3 | modal/tests/modal.spec.ts |
| 3.3 s | 3 | tests/e2e/examples/pages.spec.ts |
| 3.2 s | 2 | tests/e2e/counts.spec.ts |
| 3.0 s | 2 | tests/e2e/lab.data-table-back.spec.ts |
| 2.7 s | 2 | tests/e2e/lab.chart.spec.ts |
| 2.5 s | 2 | tests/e2e/dropdown.spec.ts |
| 2.4 s | 2 | tests/e2e/contrast.spec.ts |
| 2.2 s | 2 | tabs/tests/tabs.spec.ts |
| 2.2 s | 2 | tests/e2e/lab.form-back.spec.ts |
| 1.9 s | 1 | tests/e2e/lab.data-table-frame.spec.ts |
| 1.8 s | 1 | tests/e2e/lab.data-table-live.spec.ts |
| 1.7 s | 1 | tests/e2e/lab.permanent-plus-live.spec.ts |
| 1.7 s | 1 | tests/e2e/lab.calendar.spec.ts |
| 1.5 s | 1 | tests/e2e/lab.live-form.spec.ts |
| 1.4 s | 1 | tests/e2e/date-picker.spec.ts |
| 1.3 s | 1 | tests/e2e/markdown-editor.spec.ts |
| 1.2 s | 1 | drawer/tests/drawer.spec.ts |
| 1.2 s | 1 | tests/e2e/lab.popover.spec.ts |
| 1.1 s | 1 | tests/e2e/popover.spec.ts |
| 1.1 s | 1 | tests/e2e/dropzone.spec.ts |

The same numbers, each test included: `durations.json` in the `playwright-results-chromium-3` artifact.
