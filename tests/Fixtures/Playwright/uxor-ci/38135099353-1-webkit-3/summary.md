## Playwright, shard webkit 3/3: 1 failed, 250 passed

| Passed | Failed | Flaky (passed on retry) | Skipped |
|---:|---:|---:|---:|
| 250 | 1 | 0 | 0 |

- Tested commit: `71f7dffe771b932e3158026146db093f7bc9bb50` (dev)
- Shard: webkit 3/3; projects: smoke-webkit, examples-webkit; 399.4 s
- Runtime: Node v22.23.3, PHP 8.5.11, Symfony 8.1.8, Turbo 8.0.23, Playwright 1.58.2
- Run: https://github.com/xormania/uxor/actions/runs/38135099353 (attempt 1)

## Failed (1)

- **[smoke-webkit] tests/e2e/demo-app.spec.ts:298 › every page of the gallery and of the demo names an icon, so a browser asks for no /favicon.ico** (2 attempts, all failed)

  Attempt 1 (retry 0), failed at tests/e2e/fixtures.ts:224, 8.8 s:

  ```
  Error: console errors, page errors, CSP violations or failed requests
  
  expect(received).toEqual(expected) // deep equality
  
  - Expected  - 1
  + Received  + 3
  ```

  Attempt 2 (retry 1), failed at tests/e2e/fixtures.ts:224, 10.8 s:

  ```
  Error: console errors, page errors, CSP violations or failed requests
  
  expect(received).toEqual(expected) // deep equality
  
  - Expected  - 1
  + Received  + 8
  ```

Every failed attempt, retry-recovered ones included: `failed-attempts.json` in the `playwright-results-webkit-3` artifact; traces and the HTML report in `playwright-report-webkit-3`.

## Durations (report only)

- Wall time: 399.4 s, 2 workers
- Summed test time: 765.9 s over 252 attempts; workers run tests side by side, so it is not the wall time

### Slowest 10 tests

| Time | Attempts | Test |
|---:|---:|---|
| 19.6 s | 2 | [smoke-webkit] tests/e2e/demo-app.spec.ts:298 › every page of the gallery and of the demo names an icon, so a browser asks for no /favicon.ico |
| 13.9 s | 1 | [smoke-webkit] tests/e2e/forms.spec.ts:205 › every Input pads its text the same, with or without a type |
| 9.7 s | 1 | [smoke-webkit] tests/e2e/counts.spec.ts:77 › data-table-live: sort, page and filter each make one Live request |
| 7.7 s | 1 | [smoke-webkit] tests/e2e/demo-app.spec.ts:65 › on a desktop the navbar menu marks the current page, opens its submenus and visits their links |
| 7.6 s | 1 | [smoke-webkit] tests/e2e/lab.autocomplete.spec.ts:33 › form fields: one choice, several choices and a remote search are submitted |
| 7.0 s | 1 | [smoke-webkit] tests/e2e/lab.overlays.spec.ts:250 › dropdown › replaced or updated by a Turbo Stream while open, the new one starts closed, works, and no listener is left |
| 6.7 s | 1 | [smoke-webkit] tests/e2e/lab.calendar.spec.ts:107 › Live properties follow the picks, and bounds, locale and a clear from the server update the grid |
| 6.6 s | 1 | [smoke-webkit] tests/e2e/lab.data-table-interrupt.spec.ts:325 › page size: Back after history changed, before the frame rendered, leaves a consistent table |
| 6.5 s | 1 | [smoke-webkit] tests/e2e/lab.overlays.spec.ts:250 › drawer › replaced or updated by a Turbo Stream while open, the new one starts closed, works, and no listener is left |
| 6.5 s | 1 | [smoke-webkit] tests/e2e/demo-app.spec.ts:153 › flash messages show on every layout: the logout notice on the auth layout, not later on the dashboard |

### Per file

| Time | Tests | File |
|---:|---:|---|
| 221.7 s | 71 | tests/e2e/lab.value-matrix.spec.ts |
| 97.7 s | 35 | tests/e2e/demo-app.spec.ts |
| 27.9 s | 7 | tests/e2e/lab.overlays.spec.ts |
| 25.9 s | 5 | tests/e2e/lab.data-table-interrupt.spec.ts |
| 25.7 s | 5 | tests/e2e/counts.spec.ts |
| 24.8 s | 14 | tests/e2e/csp.spec.ts |
| 22.8 s | 3 | tests/e2e/forms.spec.ts |
| 21.3 s | 7 | tests/e2e/lab.section-nav.spec.ts |
| 20.2 s | 9 | tests/e2e/motion.spec.ts |
| 18.8 s | 6 | tests/e2e/theme-toggle.spec.ts |
| 18.3 s | 3 | tests/e2e/lab.autocomplete.spec.ts |
| 16.6 s | 5 | tests/e2e/lab.dropzone.spec.ts |
| 13.5 s | 5 | tests/e2e/calendar.spec.ts |
| 12.6 s | 4 | tests/e2e/lab.turbo-nav.spec.ts |
| 11.1 s | 2 | tests/e2e/lab.data-table-live.spec.ts |
| 11.0 s | 4 | tests/e2e/markdown-editor.spec.ts |
| 9.3 s | 2 | tests/e2e/lab.calendar.spec.ts |
| 9.2 s | 3 | tests/e2e/editor.spec.ts |
| 9.0 s | 2 | tests/e2e/lab.editor.spec.ts |
| 8.9 s | 3 | tests/e2e/lab.side-nav.spec.ts |
| 8.9 s | 4 | tests/e2e/lab.turbo-restore.spec.ts |
| 8.4 s | 4 | tests/e2e/smoke.spec.ts |
| 8.1 s | 4 | modal/tests/modal.spec.ts |
| 7.8 s | 3 | tests/e2e/lab.mobile-nav.spec.ts |
| 7.7 s | 2 | tests/e2e/lab.turbo-stream-toast.spec.ts |
| 7.5 s | 4 | tests/e2e/dropdown.spec.ts |
| 6.9 s | 2 | tests/e2e/lab.chart.spec.ts |
| 6.8 s | 2 | tests/e2e/lab.date-picker.spec.ts |
| 6.6 s | 2 | tests/e2e/dropzone.spec.ts |
| 6.1 s | 1 | tests/e2e/lab.markdown-editor.spec.ts |
| 5.7 s | 3 | tests/e2e/lab.popover.spec.ts |
| 5.4 s | 2 | tests/e2e/lab.data-table-back.spec.ts |
| 5.0 s | 1 | tests/e2e/lab.live-modal.spec.ts |
| 4.9 s | 2 | tests/e2e/chart.spec.ts |
| 4.8 s | 2 | tests/e2e/lab.nav-menu.spec.ts |
| 4.8 s | 2 | tests/e2e/lab.tooltip.spec.ts |
| 4.8 s | 2 | tests/e2e/lab.form-back.spec.ts |
| 4.7 s | 3 | tests/e2e/popover.spec.ts |
| 3.9 s | 1 | tests/e2e/button-focus.spec.ts |
| 3.8 s | 2 | drawer/tests/drawer.spec.ts |
| 3.7 s | 1 | tests/e2e/lab.permanent-plus-live.spec.ts |
| 3.6 s | 1 | tests/e2e/date-picker.spec.ts |
| 3.6 s | 1 | tests/e2e/avatar.spec.ts |
| 1.9 s | 1 | tests/e2e/lab.data-table-frame.spec.ts |
| 1.5 s | 1 | alert/tests/alert.spec.ts |
| 1.5 s | 1 | tabs/tests/tabs.spec.ts |
| 1.2 s | 2 | tests/e2e/hostile-props.spec.ts |

The same numbers, each test included: `durations.json` in the `playwright-results-webkit-3` artifact.
