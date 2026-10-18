### Jev diagnosis, shard chromium 3/4

Advisory hypothesis from bounded evidence; the test result is unchanged.
Each failed attempt's assessment (category, confidence, the selected excerpt, the request sent) is kept 30 days in the shard's `jev-<browser>-<shard>-<run>-<attempt>` artifact.

- **[smoke] tests/e2e/editor.spec.ts:105 › the link dialog sets allowed links, refuses other schemes, and removes links**, attempt 1 (retry 0, flaky): likely cause **unknown** (classifier confidence 0.60); selected test error lines 1-4 (excerpt confidence 0.98):

  <pre>Error: expect(received).toBe(expected) // Object.is equality&#10;&#10;Expected: "&lt;p&gt;&lt;a rel=\"noopener noreferrer nofollow\" href=\"/pricing\"&gt;See pricing&lt;/a&gt;&lt;/p&gt;"&#10;Received: "&lt;p&gt;See pricing&lt;a rel=\"noopener noreferrer nofollow\" href=\"/pricing\"&gt;/pricing&lt;/a&gt;&lt;/p&gt;"</pre>
