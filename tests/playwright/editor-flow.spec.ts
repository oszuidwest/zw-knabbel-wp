import { expect, test } from './fixtures';
import {
    BABBEL_ADMIN,
    babbelStoryStatus,
    controlStory,
    countBabbelStoriesByTitle,
    currentPostID,
    disposeBabbelContext,
    getBabbelStory,
    savePost,
    setBabbelEnabled,
    updateBabbelStory,
} from './utils';

const runID = Date.now().toString();
const originalTitle = `Browser E2E bericht ${runID}`;
const updatedTitle = `${originalTitle} bijgewerkt`;
const originalContent =
    'Dit artikel wordt volledig via de WordPress-editor gepubliceerd en naar Babbel gestuurd.';
const updatedContent =
    'De redacteur heeft de inhoud via de WordPress-editor aangepast.';
// Keep in sync with the AI Client MU plugin and tests/e2e/suite.php.
const generatedText = 'Deterministische E2E-radiospreektekst.';
const editedText =
    'Dit is een handmatig aangepaste E2E-radiospreektekst die behouden moet blijven.';

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
                few_shot_count: '1',
                start_days_offset: '1',
                end_days_offset: '2',
            };
            for (const [key, value] of Object.entries(settings)) {
                await page
                    .locator(`[name="knabbel_settings[${key}]"]`)
                    .fill(value);
            }

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
            await expect(
                page.getByLabel('Speech Text Generation Prompt'),
            ).toBeVisible();
            await expect(page.getByLabel('Few-shot Examples')).toBeVisible();

            // The button's `transition: all` keeps Playwright's stability check waiting.
            await page.locator('#test-babbel-api').click({ force: true });
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
            await page.goto('/wp-admin/post-new.php');
            await page.locator('#title').fill(originalTitle);
            await page.locator('#content-html').click();
            await page.locator('#content').fill(originalContent);
            await setBabbelEnabled(page, true);

            await savePost(page);
            postID = currentPostID(page);
            await expect(
                page.locator('.knabbel-status-badge.scheduled'),
            ).toBeVisible();

            const result = await controlStory(page, postID, 'run');
            expect(result.state.story_id).toBeTruthy();
            storyID = result.state.story_id || '';

            await page.reload();
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeVisible();
            await expect(page.locator('#knabbel_send_to_babbel')).toBeChecked();

            const story = await getBabbelStory(storyID);
            expect(story.title).toBe(originalTitle);
            expect(story.text).toBe(generatedText);
            expect(story.metadata?.wordpress_id).toBe(postID);
        });

        test('redacteur bewerkt titel en inhoud zonder bestaande spreektekst te overschrijven', async ({
            page,
        }) => {
            const before = await getBabbelStory(storyID);
            await updateBabbelStory(storyID, {
                text: editedText,
                status: before.status || 'draft',
            });

            await page.goto(`/wp-admin/post.php?post=${postID}&action=edit`);
            await page.locator('#title').fill(updatedTitle);
            await page.locator('#content-html').click();
            await page.locator('#content').fill(updatedContent);
            await savePost(page);

            const story = await getBabbelStory(storyID);
            expect(story.title).toBe(updatedTitle);
            expect(story.text).toBe(editedText);
        });

        test('redacteur schakelt Babbel uit en herstelt daarna hetzelfde verhaal', async ({
            page,
        }) => {
            await page.goto(`/wp-admin/post.php?post=${postID}&action=edit`);
            await setBabbelEnabled(page, false);
            await savePost(page);
            await expect(
                page.locator('.knabbel-status-badge.deleted'),
            ).toBeVisible();

            expect(await babbelStoryStatus(storyID)).toBe(404);

            await page.reload();
            await setBabbelEnabled(page, true);
            await savePost(page);
            await expect(
                page.locator('.knabbel-status-badge.sent'),
            ).toBeVisible();

            await getBabbelStory(storyID);
        });

        test('redacteur plant een bericht en annuleert het voor verwerking', async ({
            page,
        }) => {
            const scheduledTitle = `Browser E2E planning ${runID}`;
            const publication = new Date();
            publication.setDate(publication.getDate() + 10);
            publication.setHours(12, 0, 0, 0);

            await page.goto('/wp-admin/post-new.php');
            await page.locator('#title').fill(scheduledTitle);
            await page.locator('#content-html').click();
            await page.locator('#content').fill(originalContent);
            await setBabbelEnabled(page, true);
            await page.locator('.edit-timestamp').click();
            await page
                .locator('#mm')
                .selectOption(
                    String(publication.getMonth() + 1).padStart(2, '0'),
                );
            await page
                .locator('#jj')
                .fill(String(publication.getDate()).padStart(2, '0'));
            await page.locator('#aa').fill(String(publication.getFullYear()));
            await page.locator('#hh').fill('12');
            await page.locator('#mn').fill('00');
            await page.locator('.save-timestamp').click();
            await expect(page.locator('#publish')).toHaveValue('Schedule');

            await savePost(page);
            const scheduledPostID = currentPostID(page);
            let result = await controlStory(page, scheduledPostID, 'inspect');
            expect(result.pending).toBe(1);
            expect(result.state.status).toBe('scheduled');

            // WordPress uses jQuery slide animations for these classic-editor
            // controls. Chromium can leave them at a fractional pixel in CI.
            await page.evaluate('jQuery.fx.off = true');
            // WordPress hides #post_status behind the Edit link whenever JS is on.
            // These jQuery-animated links never settle into Playwright's stable
            // state, so dispatch the click instead of waiting for actionability.
            const postStatus = page.locator('#post_status');
            await page.locator('.edit-post-status').dispatchEvent('click');
            await postStatus.selectOption('draft');
            await page.locator('.save-post-status').dispatchEvent('click');
            await savePost(page);

            result = await controlStory(page, scheduledPostID, 'inspect');
            expect(result.pending).toBe(0);
            expect(result.state).toEqual({});

            expect(await countBabbelStoriesByTitle(scheduledTitle)).toBe(0);
        });

        test('redacteur verplaatst het bericht naar de prullenbak en herstelt het', async ({
            page,
        }) => {
            await page.goto(`/wp-admin/post.php?post=${postID}&action=edit`);
            await Promise.all([
                page.waitForURL(/\/wp-admin\/edit\.php/),
                page
                    .locator('#delete-action .submitdelete')
                    .dispatchEvent('click'),
            ]);

            expect(await babbelStoryStatus(storyID)).toBe(404);

            await page.goto(
                '/wp-admin/edit.php?post_status=trash&post_type=post',
            );
            const row = page.locator('tr', {
                has: page.getByText(updatedTitle, { exact: true }),
            });
            // Row actions stay hidden until the row is hovered, and the trash list is
            // already on edit.php, so follow the restore URL as its own navigation.
            const restoreURL = await row
                .getByRole('link', { name: /^Restore .* from the Trash$/ })
                .getAttribute('href');
            expect(restoreURL).toBeTruthy();
            await page.goto(restoreURL || '');

            const story = await getBabbelStory(storyID);
            expect(story.title).toBe(updatedTitle);
        });
    });
