// Controlled Playwright producer for Xorgate fixtures (not a UXor configuration).
// Shard 1/2 holds controlled.spec.js in both `controlled` projects; the `companion` project fills shard 2.
const { defineConfig } = require('@playwright/test');

const use = { launchOptions: { executablePath: '/opt/pw-browsers/chromium' } };

module.exports = defineConfig({
    retries: 1,
    workers: 1,
    fullyParallel: false,
    reporter: [['list'], ['json', { outputFile: 'playwright-results/results.json' }]],
    projects: [
        { name: 'controlled', testDir: 'tests', use: { ...use, colorScheme: 'light' } },
        { name: 'controlled-dark', testDir: 'tests', use: { ...use, colorScheme: 'dark' } },
        { name: 'companion', testDir: 'companion', use },
    ],
});
