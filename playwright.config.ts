import { defineConfig, devices } from '@playwright/test';

const baseURL =
	process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8080';

export default defineConfig({
	testDir: './tests/playwright',
	fullyParallel: false,
	workers: 1,
	forbidOnly: Boolean(process.env.CI),
	retries: process.env.CI ? 2 : 0,
	timeout: 60_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [['github'], ['html']] : 'list',
	use: {
		baseURL,
		screenshot: 'only-on-failure',
		trace: 'on-first-retry',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
});
