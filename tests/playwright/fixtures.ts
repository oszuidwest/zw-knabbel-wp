import {
    test as base,
    type ConsoleMessage,
    expect,
    type Page,
} from '@playwright/test';

interface BrowserErrorFixtures {
    browserErrors: string[];
}

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
            expect(errors, 'Browser JavaScript errors must be empty.').toEqual(
                [],
            );
        },
        { auto: true },
    ],
});
