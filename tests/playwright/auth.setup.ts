import { STORAGE_STATE } from '../../playwright.config';
import { test as setup } from './fixtures';
import { login } from './utils';

setup('log in als beheerder', async ({ page }) => {
    await login(page);
    await page.context().storageState({ path: STORAGE_STATE });
});
