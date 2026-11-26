import { expect, test } from './fixtures';
import {
    BABBEL_ADMIN,
    babbelStoryStatus,
    controlStory,
    currentPostID,
    disposeBabbelContext,
    getBabbelStory,
    openEditor,
    savePost,
    setBabbelEnabled,
} from './utils';

const runID = Date.now().toString();
const originalTitle = `Browser E2E bericht ${runID}`;
const originalContent =
    'Dit artikel wordt volledig via de WordPress-editor gepubliceerd en naar Babbel gestuurd.';
// Keep in sync with the AI Client MU plugin and tests/e2e/suite.php.
const generatedText = 'Deterministische E2E-radiospreektekst.';

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
            await page.goto(
                '/wp-admin/options-general.php?page=zw-knabbel-wp-settings',
            );

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

            await Promise.all([
                page.waitForURL(
                    /\/wp-admin\/options-general\.php\?page=zw-knabbel-wp-settings/,
                ),
                page.locator('button[form="knabbel-settings-form"]').click(),
            ]);
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
            await expect(
                page.getByRole('button', { name: 'Radio News: Yes' }),
            ).toBeVisible();

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
            await expect(
                page.getByRole('button', { name: 'Radio News: No' }),
            ).toHaveText('No');
            await setBabbelEnabled(page, true);
            await savePost(page);
            expect(
                (await controlStory(page, postID, 'inspect')).state.status,
            ).toBe('sent');

            await getBabbelStory(storyID);
        });

        test('redacteur ziet na opslaan een Babbel-syncfout onder Radionieuws', async ({
            page,
        }) => {
            await openEditor(page, postID);
            const warning = page.locator(
                '.knabbel-post-status .components-notice.is-warning',
            );
            await expect(warning).toHaveCount(0);

            await controlStory(page, postID, 'sync_error');
            await savePost(page);
            await expect(warning).toContainText('Babbel sync warning');
            await expect(warning).toContainText('E2E sync failure');

            // A successful update clears it, also without a reload.
            await page.evaluate(() =>
                window.wp.data
                    .dispatch('core/editor')
                    .editPost({ title: 'Browser E2E bericht bijgewerkt' }),
            );
            await savePost(page);
            await expect(warning).toHaveCount(0);
        });
    });
