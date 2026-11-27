import type { Locator, Page } from '@playwright/test';
import { expect, test } from './fixtures';
import {
    BABBEL_ADMIN,
    babbelStoryStatus,
    controlStory,
    currentPostID,
    deleteBabbelStory,
    disposeBabbelContext,
    getBabbelStory,
    openEditor,
    radioNewsButton,
    savePost,
    setBabbelEnabled,
    setSidebar,
} from './utils';

const runID = Date.now().toString();
const originalTitle = `Browser E2E bericht ${runID}`;
const originalContent =
    'Dit artikel wordt volledig via de WordPress-editor gepubliceerd en naar Babbel gestuurd.';
// Keep in sync with the AI Client MU plugin and tests/e2e/suite.php.
const generatedText = 'Deterministische E2E-radiospreektekst.';
const retryHint = 'Save the post to try again.';

const settingsURL = '/wp-admin/options-general.php?page=zw-knabbel-wp-settings';

async function submitSettings(page: Page): Promise<void> {
    await Promise.all([
        page.waitForURL(
            /\/wp-admin\/options-general\.php\?page=zw-knabbel-wp-settings/,
        ),
        page.locator('button[form="knabbel-settings-form"]').click(),
    ]);
}

async function setBabbelPassword(page: Page, password: string): Promise<void> {
    await page.goto(settingsURL);
    await page
        .locator('[name="knabbel_settings[api_password]"]')
        .fill(password);
    await submitSettings(page);
}

function syncNotices(page: Page): { inline: Locator; top: Locator } {
    return {
        inline: page.locator('.knabbel-sync-notice'),
        // Core's notice at the top of the editor, outside the sidebar.
        top: page
            .locator('.components-notice:not(.knabbel-sync-notice)')
            .filter({ hasText: 'Babbel was not updated' }),
    };
}

test.describe
    .serial('WordPress redacteursflow', () => {
        let postID = 0;
        let storyID = '';

        test.afterAll(async () => {
            await disposeBabbelContext();
        });

        test('beheerder configureert en test de integratie via Instellingen', async ({
            page,
        }) => {
            await page.goto(settingsURL);

            const settings = {
                api_base_url: 'http://babbel:8080/api/v1',
                api_username: BABBEL_ADMIN.username,
                api_password: BABBEL_ADMIN.password,
                start_days_offset: '1',
                end_days_offset: '2',
            };
            for (const [key, value] of Object.entries(settings)) {
                await page
                    .locator(`[name="knabbel_settings[${key}]"]`)
                    .fill(value);
            }
            await page.getByLabel('AI Model').selectOption('openai/gpt-4.1');

            const debugMode = page.locator(
                '[name="knabbel_settings[debug_mode]"]',
            );
            if (!(await debugMode.isChecked())) {
                await page
                    .getByText('Enable Debug Mode', { exact: true })
                    .click();
            }

            await submitSettings(page);
            await expect(
                page.locator('[name="knabbel_settings[api_base_url]"]'),
            ).toHaveValue('http://babbel:8080/api/v1');
            await expect(debugMode).toBeChecked();
            await expect(
                page.getByRole('link', { name: 'Manage AI providers' }),
            ).toHaveAttribute('href', /options-connectors\.php$/);
            await expect(page.getByLabel('AI Model')).toHaveValue(
                'openai/gpt-4.1',
            );
            await expect(
                page.getByLabel('Speech Text Generation Prompt'),
            ).toBeVisible();
            await expect(
                page.locator('[name="knabbel_settings[few_shot_count]"]'),
            ).toHaveCount(0);

            await page.locator('#test-babbel-api').click();
            await expect(page.locator('#api-test-result')).toHaveClass(
                /success/,
            );
            await expect(page.locator('#api-test-result')).toContainText(
                /admin/i,
            );
        });

        test('redacteur publiceert een bericht en laat de wachtrij het naar Babbel sturen', async ({
            page,
        }) => {
            await openEditor(page);
            await page.evaluate(
                ({ title, content }) => {
                    const { blocks, data } = window.wp;
                    data.dispatch('core/editor').editPost({
                        title,
                        status: 'publish',
                    });
                    data.dispatch('core/block-editor').insertBlocks(
                        blocks.createBlock('core/paragraph', { content }),
                        undefined,
                        undefined,
                        // Keep the Post tab open in the sidebar.
                        false,
                    );
                },
                { title: originalTitle, content: originalContent },
            );
            await setBabbelEnabled(page, true);

            await savePost(page);
            postID = await currentPostID(page);
            expect(
                (await controlStory(page, postID, 'inspect')).state.status,
            ).toBe('scheduled');

            const result = await controlStory(page, postID, 'run');
            expect(result.state.story_id).toBeTruthy();
            storyID = result.state.story_id || '';

            await openEditor(page, postID);
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeAttached();
            await expect(radioNewsButton(page)).toHaveAttribute(
                'aria-pressed',
                'true',
            );

            const story = await getBabbelStory(storyID);
            expect(story.title).toBe(originalTitle);
            expect(story.text).toBe(generatedText);
            expect(story.metadata?.wordpress_id).toBe(postID);
        });

        test('redacteur schakelt Babbel uit en herstelt daarna hetzelfde verhaal', async ({
            page,
        }) => {
            await openEditor(page, postID);
            await setBabbelEnabled(page, false);
            await savePost(page);
            expect(
                (await controlStory(page, postID, 'inspect')).state.status,
            ).toBe('deleted');

            expect(await babbelStoryStatus(storyID)).toBe(404);

            await openEditor(page, postID);
            await expect(radioNewsButton(page)).toHaveAttribute(
                'aria-pressed',
                'false',
            );
            await setBabbelEnabled(page, true);
            await savePost(page);
            expect(
                (await controlStory(page, postID, 'inspect')).state.status,
            ).toBe('sent');

            await getBabbelStory(storyID);
        });

        test('redacteur ziet een Babbel-fout direct na opslaan en herstelt het verhaal', async ({
            page,
        }) => {
            const { inline, top } = syncNotices(page);

            await openEditor(page, postID);
            await expect(radioNewsButton(page)).toBeVisible();
            await expect(inline).toHaveCount(0);
            await expect(top).toHaveCount(0);

            // The story disappears in Babbel; the next update fails in this
            // save. With the sidebar closed only the top notice can show it.
            await setSidebar(page, false);
            await expect(radioNewsButton(page)).toHaveCount(0);
            await deleteBabbelStory(storyID);
            await page.evaluate(() =>
                window.wp.data
                    .dispatch('core/editor')
                    .editPost({ title: 'Browser E2E bericht bijgewerkt' }),
            );
            await savePost(page);
            await expect(top).toBeVisible();
            await expect(top).toContainText(
                'This story was deleted in Babbel.',
            );
            // The message says what to do, and saving again would not help.
            await expect(top).not.toContainText(retryHint);

            // A stored error shows when the editor opens, in both places.
            await openEditor(page, postID);
            await setSidebar(page, true);
            await expect(inline).toContainText('Babbel was not updated');
            await expect(inline).toContainText(
                'This story was deleted in Babbel.',
            );
            await expect(inline).not.toContainText(retryHint);
            await expect(top).toBeVisible();

            // The way back the warning describes.
            await setBabbelEnabled(page, false);
            await savePost(page);
            await setBabbelEnabled(page, true);
            await savePost(page);
            await expect(inline).toHaveCount(0);
            await expect(top).toHaveCount(0);
            expect(
                (await controlStory(page, postID, 'inspect')).state.status,
            ).toBe('sent');
            await getBabbelStory(storyID);
        });

        test('redacteur leest bij een gewone Babbel-fout dat opslaan het opnieuw probeert', async ({
            page,
        }) => {
            const { inline, top } = syncNotices(page);
            const message =
                'API connection failed: Login failed: API error: HTTP 401 - Invalid username or password';

            await setBabbelPassword(page, 'definitely-wrong-password');
            try {
                await openEditor(page, postID);
                await setSidebar(page, true);
                await page.evaluate(() =>
                    window.wp.data
                        .dispatch('core/editor')
                        .editPost({ title: 'Browser E2E titel tijdens fout' }),
                );
                await savePost(page);
                await expect(inline).toContainText(message);
                await expect(inline).toContainText(retryHint);
                // The message has no full stop of its own, so the notice adds one.
                await expect(top).toContainText(`${message}. ${retryHint}`);
            } finally {
                await setBabbelPassword(page, BABBEL_ADMIN.password);
            }

            // The retry the hint promises: any save resends the failed title.
            await openEditor(page, postID);
            await page.evaluate(() =>
                window.wp.data
                    .dispatch('core/editor')
                    .editPost({ excerpt: 'Een samenvatting na de fout.' }),
            );
            await savePost(page);
            await expect(inline).toHaveCount(0);
            await expect(top).toHaveCount(0);
            expect((await getBabbelStory(storyID)).title).toBe(
                'Browser E2E titel tijdens fout',
            );
        });

        test('de Radionieuws-regel staat alleen bij berichten', async ({
            page,
        }) => {
            await page.goto('/wp-admin/post-new.php?post_type=page');
            await page.waitForFunction(() =>
                window.wp?.data?.select('core/editor')?.getCurrentPostId(),
            );
            await setSidebar(page, true);
            // Core's own Summary rows are drawn by then.
            await expect(
                page.locator('.editor-post-panel__row').first(),
            ).toBeVisible();
            // Not by role: a welcome guide can hide the sidebar from the
            // accessibility tree, which would make this pass without checking.
            await expect(page.locator('.knabbel-post-status')).toHaveCount(0);
        });
    });
