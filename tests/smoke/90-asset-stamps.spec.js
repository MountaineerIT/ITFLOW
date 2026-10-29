// Version stamps on ITFlow's own scripts and styles (functions/mit_common.php mitAssetStamp, started in includes/header.php): every local
// script / stylesheet the page links gets ?v=<file time>, so a browser fetches the new file after an update instead of keeping the old one
// (26.10.196's @mentions sat unused in browsers that kept the old js/app.js). The preload of itflow_select.js carries the same stamp as its
// script tag, so the preload is still used; libraries are stamped too; links that had a version keep theirs.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

test('ITFlow\'s scripts and styles carry a version stamp', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    const unstamped = await page.evaluate(() => [...document.querySelectorAll('script[src^="/"], link[href^="/"][rel="stylesheet"], link[rel="preload"][href^="/"]')]
        .map((el) => el.getAttribute('src') || el.getAttribute('href'))
        .filter((url) => /\.(js|css)$/.test(url)));
    expect(unstamped, 'no local script or stylesheet without a version').toEqual([]);
    await expect(page.locator('script[src^="/js/app.js?v="]'), 'ITFlow\'s app.js').toHaveCount(1);
    await expect(page.locator('link[href^="/css/itflow_custom.css?v="]'), 'ITFlow\'s own stylesheet').toHaveCount(1);
    await expect(page.locator('script[src^="/libs/bootstrap/js/bootstrap.bundle.min.js?v="]'), 'a library').toHaveCount(1);
    const preload = await page.locator('link[rel="preload"][href*="itflow_select.js"]').getAttribute('href');
    await expect(page.locator(`script[src="${preload}"]`), 'the preload matches its script, stamp and all').toHaveCount(1);
    expect(await page.evaluate(() => typeof window.itflowMentionAutocompleter), 'and the stamped app.js runs').toBe('function');
    await expectClean(page, errors);
});
