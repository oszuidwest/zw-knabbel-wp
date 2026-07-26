import { test as base, expect } from '@playwright/test';

const allowedBrowserErrors = [
    // WordPress admin navigation can skip Chrome View Transitions without affecting the page update.
    /^pageerror: Transition was skipped$/,
];

export { expect };

export const test = base.extend<{ assertNoBrowserErrors: undefined }>({
    assertNoBrowserErrors: [
        async ({ page }, use) => {
            const errors: string[] = [];
            page.on('pageerror', (error) =>
                errors.push(`pageerror: ${error.message}`),
            );
            page.on('console', (message) => {
                if (message.type() === 'error') {
                    errors.push(`console.error: ${message.text()}`);
                }
            });

            await use(undefined);

            expect(
                errors.filter(
                    (error) =>
                        !allowedBrowserErrors.some((pattern) =>
                            pattern.test(error),
                        ),
                ),
                'Unexpected browser JavaScript errors must be empty.',
            ).toEqual([]);
        },
        { auto: true },
    ],
});
