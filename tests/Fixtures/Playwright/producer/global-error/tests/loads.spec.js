// Loads and passes; the run still fails because another file throws while loading.
const { test, expect } = require('@playwright/test');

test('loads and passes', async () => {
    expect(true).toBe(true);
});
