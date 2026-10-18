// Deliberately throws while Playwright loads this file (controlled error outside tests).
const { test } = require('@playwright/test');

throw new Error('controlled error while loading the spec file');

test('never registered', async () => {});
