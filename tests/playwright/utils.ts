import {
	expect,
	request,
	type APIRequestContext,
	type APIResponse,
	type Page,
} from '@playwright/test';

export interface StoryState {
	status?: string;
	story_id?: string;
	generated_speech_text?: string;
}

export interface ControlResult {
	pending: number;
	state: StoryState;
}

export interface BabbelStory {
	id: string;
	title: string;
	text: string;
	metadata?: {
		wordpress_id?: number;
	};
}

export const WP_ADMIN = { username: 'admin', password: 'e2e-admin-password' };
export const BABBEL_ADMIN = { username: 'admin', password: 'admin' };

const babbelURL = process.env.PLAYWRIGHT_BABBEL_URL;
let babbelContext: APIRequestContext | undefined;

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

	await Promise.all([
		postResponse,
		navigation,
		page.locator('#publish').evaluate((button: HTMLInputElement) => {
			button.form?.requestSubmit(button);
		}),
	]);
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
	await checkbox.setChecked(enabled, { force: true });
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
	const response = await babbelRequest(`/stories/${encodeURIComponent(storyID)}`);

	if (response.status() !== 200) {
		return { response };
	}

	return {
		response,
		story: (await response.json()) as BabbelStory,
	};
}

export async function countBabbelStoriesByTitle(
	title: string,
): Promise<number> {
	const query = new URLSearchParams({
		'filter[title]': title,
		limit: '100',
	});
	const response = await babbelRequest(`/stories?${query.toString()}`);
	expect(response.status()).toBe(200);
	const body = (await response.json()) as { data?: BabbelStory[] };

	return (body.data || []).filter((story) => story.title === title).length;
}

async function babbelRequest(
	path: string,
	options?: Parameters<APIRequestContext['fetch']>[1],
): Promise<APIResponse> {
	if (!babbelURL) {
		throw new Error('PLAYWRIGHT_BABBEL_URL is required.');
	}

	if (!babbelContext) {
		babbelContext = await request.newContext();
		const session = await babbelContext.post(`${babbelURL}/sessions`, {
			data: BABBEL_ADMIN,
		});
		expect(session.status()).toBe(201);
	}

	return babbelContext.fetch(`${babbelURL}${path}`, options);
}
