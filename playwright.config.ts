import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;
if (!baseURL) {
    throw new Error('PLAYWRIGHT_BASE_URL is required.');
}

export const STORAGE_STATE = 'playwright/.auth/admin.json';

export default defineConfig({
    testDir: './tests/playwright',
    // The serial editor flow shares one WordPress post and Babbel story.
    workers: 1,
    forbidOnly: Boolean(process.env.CI),
    // No retries: the suite is deterministic, and a serial retry would re-run the
    // whole group against state the failed attempt already mutated.
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 15_000 },
    reporter: process.env.CI ? [['github'], ['html']] : 'list',
    use: {
        baseURL,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
        },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], storageState: STORAGE_STATE },
            dependencies: ['setup'],
        },
    ],
});
