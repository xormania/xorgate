// Controlled Playwright producer: one spec file throws while loading, so the report carries errors outside tests.
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: 'tests',
    retries: 1,
    workers: 1,
    reporter: [['list'], ['json', { outputFile: 'playwright-results/results.json' }]],
    projects: [{ name: 'controlled' }],
});
