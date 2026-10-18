// Deliberately induced outcomes for a controlled reporter run. None of these is a defect in any project.
const { test, expect } = require('@playwright/test');

test('passes', async ({ page }) => {
    await page.setContent('<main><h1>Controlled</h1></main>');
    await expect(page.getByRole('heading', { name: 'Controlled' })).toBeVisible();
});

test('flaky: fails on the first attempt, passes on retry', async ({}, testInfo) => {
    expect(testInfo.retry, 'controlled first-attempt failure').toBeGreaterThan(0);
});

test('always fails', async () => {
    expect(1 + 1, 'controlled persistent failure').toBe(3);
});

test('expected failure', async () => {
    test.fail(true, 'controlled expected failure');
    expect('actual', 'controlled expected-failure assertion').toBe('expected');
});

test('skipped', async () => {
    test.skip(true, 'controlled skip');
});

test('writes stdout and stderr', async () => {
    console.log('controlled stdout line 1');
    console.log('controlled stdout line 2');
    console.error('controlled stderr line 1');
    process.stderr.write('controlled stderr raw write\n');
});

test.describe('outer describe', () => {
    test.describe('inner describe', () => {
        test('nested passes', async () => {
            expect(['a', 'b']).toHaveLength(2);
        });
    });
});
