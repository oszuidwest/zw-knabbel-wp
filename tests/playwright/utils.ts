import {
    type APIRequestContext,
    type APIResponse,
    expect,
    type Page,
    request,
} from '@playwright/test';

export interface StoryState {
    status?: string;
    story_id?: string;
}

export interface ControlResult {
    pending: number;
    state: StoryState;
}

export interface BabbelStory {
    title: string;
    text: string;
    status?: string;
    metadata?: {
        wordpress_id?: number;
    };
}

// Keep these credentials in sync with tests/e2e/run.sh and suite.php.
export const WP_ADMIN = { username: 'admin', password: 'e2e-admin-password' };
export const BABBEL_ADMIN = { username: 'admin', password: 'admin' };

const babbelURL = process.env.PLAYWRIGHT_BABBEL_URL;
let babbelContextPromise: Promise<APIRequestContext> | undefined;

/**
 * Assert a response succeeded, using its body as the failure message.
 *
 * Redirect responses have no readable body, so fall back to the label.
 */
async function expectResponseOk(
    response: { status(): number; text(): Promise<string> },
    label: string,
): Promise<void> {
    const status = response.status();
    const detail =
        status < 400 ? label : await response.text().catch(() => label);
    expect(status, detail).toBeLessThan(400);
}

export async function login(page: Page): Promise<void> {
    await page.goto('/wp-login.php');
    const loginResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === '/wp-login.php',
    );
    const [response] = await Promise.all([
        loginResponse,
        page
            .locator('#loginform')
            .evaluate((form: HTMLFormElement, credentials) => {
                const username = form.elements.namedItem('log');
                const password = form.elements.namedItem('pwd');
                if (
                    !(username instanceof HTMLInputElement) ||
                    !(password instanceof HTMLInputElement)
                ) {
                    throw new Error('The login fields are unavailable.');
                }

                username.value = credentials.username;
                password.value = credentials.password;
                form.submit();
            }, WP_ADMIN),
    ]);
    await expectResponseOk(response, 'Login failed.');
    await expect(page).toHaveURL(/\/wp-admin\//);
}

declare global {
    interface Window {
        // biome-ignore lint/suspicious/noExplicitAny: WordPress script globals.
        wp: any;
        knabbelE2E: { nonce: string };
    }
}

/**
 * Open a post in the block editor and wait until it is ready.
 */
export async function openEditor(page: Page, path: string): Promise<void> {
    await page.goto(path);
    await page.waitForFunction(() =>
        window.wp?.data?.select('core/editor')?.getCurrentPostId(),
    );
    // The welcome guide covers the editor on the first visit.
    await page.evaluate(() =>
        window.wp.data
            .dispatch('core/preferences')
            .set('core/edit-post', 'welcomeGuide', false),
    );
}

/**
 * Save the post through the editor store, as the Publish and Save buttons do.
 */
export async function savePost(page: Page, status?: 'publish'): Promise<void> {
    const failed = await page.evaluate(async (newStatus) => {
        const { dispatch, select } = window.wp.data;
        if (newStatus) {
            dispatch('core/editor').editPost({ status: newStatus });
        }
        await dispatch('core/editor').savePost();
        return select('core/editor').didPostSaveRequestFail();
    }, status);
    expect(failed, 'Post save failed.').toBe(false);
}

export async function currentPostID(page: Page): Promise<number> {
    const postID = await page.evaluate(() =>
        window.wp.data.select('core/editor').getCurrentPostId(),
    );
    expect(postID).toBeGreaterThan(0);

    return postID;
}

export async function setBabbelEnabled(
    page: Page,
    enabled: boolean,
): Promise<void> {
    const on = page.getByRole('button', { name: 'Turn Radio News on' });
    const off = page.getByRole('button', { name: 'Turn Radio News off' });

    await expect(on.or(off)).toBeVisible();
    if (enabled && (await on.isVisible())) {
        await on.click();
    } else if (!enabled && (await off.isVisible())) {
        await off.click();
    }
    await expect(enabled ? off : on).toHaveText(enabled ? 'Yes' : 'No');
}

export async function controlStory(
    page: Page,
    postID: number,
    operation: 'inspect' | 'run' | 'sync_error',
): Promise<ControlResult> {
    const nonce = await page.evaluate(() => window.knabbelE2E.nonce);
    const response = await page.request.post('/wp-admin/admin-ajax.php', {
        form: {
            action: 'knabbel_e2e_control',
            nonce,
            operation,
            post_id: String(postID),
        },
    });

    expect(response.status(), await response.text()).toBe(200);
    const body = (await response.json()) as {
        success: boolean;
        data: ControlResult;
    };
    expect(body.success).toBe(true);

    return body.data;
}

/**
 * Read a story that must exist. Fails the test when it does not.
 */
export async function getBabbelStory(storyID: string): Promise<BabbelStory> {
    const response = await babbelRequest(
        `/stories/${encodeURIComponent(storyID)}`,
    );
    expect(response.status(), await response.text()).toBe(200);

    return (await response.json()) as BabbelStory;
}

/**
 * Read only the status code, for assertions about a story's absence.
 */
export async function babbelStoryStatus(storyID: string): Promise<number> {
    const response = await babbelRequest(
        `/stories/${encodeURIComponent(storyID)}`,
    );

    return response.status();
}

export async function disposeBabbelContext(): Promise<void> {
    const contextPromise = babbelContextPromise;
    babbelContextPromise = undefined;
    const context = await contextPromise?.catch(() => undefined);
    await context?.dispose();
}

async function babbelRequest(
    path: string,
    options?: Parameters<APIRequestContext['fetch']>[1],
): Promise<APIResponse> {
    if (!babbelURL) {
        throw new Error('PLAYWRIGHT_BABBEL_URL is required.');
    }

    // Memoize the in-flight promise, not the resolved context: a single worker does
    // not stop two concurrent callers from both entering this branch, which would
    // leak a context and let one request run before the shared login completed.
    if (!babbelContextPromise) {
        babbelContextPromise = (async () => {
            const context = await request.newContext();
            try {
                const session = await context.post(`${babbelURL}/sessions`, {
                    data: BABBEL_ADMIN,
                });
                expect(session.status(), await session.text()).toBe(201);

                return context;
            } catch (error) {
                await context.dispose();
                throw error;
            }
        })();
    }

    // Reset on failure so a later call retries the login instead of reusing a
    // rejected promise, but only if nothing else replaced it in the meantime.
    const contextPromise = babbelContextPromise;
    let babbelContext: APIRequestContext;
    try {
        babbelContext = await contextPromise;
    } catch (error) {
        if (babbelContextPromise === contextPromise) {
            babbelContextPromise = undefined;
        }
        throw error;
    }

    return babbelContext.fetch(`${babbelURL}${path}`, options);
}
