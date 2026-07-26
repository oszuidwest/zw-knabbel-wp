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
    generated_speech_text?: string;
}

export interface ControlResult {
    pending: number;
    processed: number;
    state: StoryState;
}

export interface BabbelStory {
    id: string;
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

export async function login(page: Page): Promise<void> {
    await page.goto('/wp-login.php');
    await page.getByLabel('Username or Email Address').fill(WP_ADMIN.username);
    await page.getByLabel('Password', { exact: true }).fill(WP_ADMIN.password);
    await page.getByRole('button', { name: 'Log In' }).click();
    await expect(page).toHaveURL(/\/wp-admin\//);
}

export async function savePost(page: Page): Promise<void> {
    const postResponse = page.waitForResponse(
        (response) =>
            response.request().method() === 'POST' &&
            new URL(response.url()).pathname === '/wp-admin/post.php',
    );
    const navigation = page.waitForEvent(
        'framenavigated',
        (frame) => frame === page.mainFrame(),
    );

    const [response] = await Promise.all([
        postResponse,
        navigation,
        page.locator('#publish').evaluate((button: HTMLInputElement) => {
            if (!button.form) {
                throw new Error(
                    'The publish button is not associated with a form.',
                );
            }
            button.form.requestSubmit(button);
        }),
    ]);
    const status = response.status();
    let failureMessage = 'Post save failed.';
    if (status >= 400) {
        try {
            failureMessage = await response.text();
        } catch {
            failureMessage =
                'Post save failed and its response body is unavailable.';
        }
    }
    expect(status, failureMessage).toBeLessThan(400);
    await expect(page).toHaveURL(/\/wp-admin\/post\.php\?post=\d+&action=edit/);
    await expect(page.locator('#publish')).toBeVisible();
}

export function currentPostID(page: Page): number {
    const postID = Number(new URL(page.url()).searchParams.get('post'));
    expect(postID).toBeGreaterThan(0);

    return postID;
}

export async function setBabbelEnabled(
    page: Page,
    enabled: boolean,
): Promise<void> {
    const checkbox = page.locator(
        '.knabbel-radionieuws-injected #knabbel_send_to_babbel',
    );
    await expect(checkbox).toBeVisible();
    await checkbox.setChecked(enabled);
}

export async function controlStory(
    page: Page,
    postID: number,
    operation: 'inspect' | 'run',
): Promise<ControlResult> {
    const nonce = await page.locator('#knabbel_nonce').inputValue();
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

export async function getBabbelStory(
    storyID: string,
): Promise<{ response: APIResponse; story?: BabbelStory }> {
    const response = await babbelRequest(
        `/stories/${encodeURIComponent(storyID)}`,
    );

    if (response.status() !== 200) {
        return { response };
    }

    return {
        response,
        story: (await response.json()) as BabbelStory,
    };
}

export async function updateBabbelStory(
    storyID: string,
    data: { text: string; status: string },
): Promise<APIResponse> {
    return babbelRequest(`/stories/${encodeURIComponent(storyID)}`, {
        method: 'PUT',
        data,
    });
}

export async function countBabbelStoriesByTitle(
    title: string,
): Promise<number> {
    const query = new URLSearchParams({
        'filter[title]': title,
        limit: '100',
    });
    const response = await babbelRequest(`/stories?${query.toString()}`);
    const rawBody = await response.text();
    expect(response.status(), rawBody).toBe(200);
    const body = JSON.parse(rawBody) as {
        data?: unknown;
        limit?: unknown;
        offset?: unknown;
        total?: unknown;
    };
    expect(Array.isArray(body.data), rawBody).toBe(true);
    const stories = body.data as BabbelStory[];
    expect(body.limit, rawBody).toBe(100);
    expect(body.offset, rawBody).toBe(0);
    expect(body.total, rawBody).toBe(stories.length);
    expect(stories.length, rawBody).toBeLessThan(100);

    return stories.filter((story) => story.title === title).length;
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

    if (!babbelContextPromise) {
        babbelContextPromise = (async () => {
            const context = await request.newContext();
            try {
                const session = await context.post(`${babbelURL}/sessions`, {
                    data: BABBEL_ADMIN,
                });
                const body = await session.text();
                expect(session.status(), body).toBe(201);

                return context;
            } catch (error) {
                await context.dispose();
                throw error;
            }
        })();
    }

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
