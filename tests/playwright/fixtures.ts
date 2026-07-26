import {
    test as base,
    type ConsoleMessage,
    expect,
    type Page,
} from '@playwright/test';

interface BrowserErrorFixtures {
    browserErrors: string[];
}

const allowedBrowserErrors = [
    // WordPress admin navigation can skip Chrome View Transitions without affecting the page update.
    /^pageerror: Transition was skipped$/,
];

function collectBrowserErrors(page: Page, errors: string[]): void {
    page.on('pageerror', (error) => {
        errors.push(`pageerror: ${error.message}`);
    });
    page.on('console', (message: ConsoleMessage) => {
        if (message.type() === 'error') {
            errors.push(`console.error: ${message.text()}`);
        }
    });
}

export { expect };

export const test = base.extend<BrowserErrorFixtures>({
    browserErrors: [
        async ({ page }, use) => {
            const errors: string[] = [];
            collectBrowserErrors(page, errors);
            await use(errors);
            const unexpectedErrors = errors.filter(
                (error) =>
                    !allowedBrowserErrors.some((pattern) =>
                        pattern.test(error),
                    ),
            );
            expect(
                unexpectedErrors,
                'Unexpected browser JavaScript errors must be empty.',
            ).toEqual([]);
        },
        { auto: true },
    ],
});
