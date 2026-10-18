// Filler for shard 2/2 of the controlled run; shard 1/2 does not run these.
const { test, expect } = require('@playwright/test');

for (let i = 1; i <= 14; i++) {
    test(`companion ${i}`, async () => {
        expect(i).toBeGreaterThan(0);
    });
}
