// Version stamps on scripts and styles: ITFlow 26.11.5 adds ?v=<file time> to every script / stylesheet tag (staticFileUrl in
// functions/app.php); ours carry theirs through mitAsset(). Our own output filter for this (26.10.197 - mitAssetStamp) was retired in
// 26.11.5.1 - this test checks that no local script or stylesheet on the page goes without a version, ours or ITFlow's.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

test('every local script and stylesheet carries a version stamp', async ({ page }) => {
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
