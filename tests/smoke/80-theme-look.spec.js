// The modern look's hooks (functions/mit_theme.php, functions/mit_client_tiles.php): Account > Preferences offers the look and saves
// it (modern loads css/mit_theme.css on every agent page, classic does not), both sign-in screens load the backdrop's styles, and a
// client's Assets and Licenses pages open with their tiles (numbers from known data). Puts the admin's look back and removes its client.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { look: null, clientId: 0 };
const ME = () => Number(db.val(`SELECT user_id FROM users WHERE user_email = ${db.q(process.env.ITFLOW_EMAIL || '')}`) || 1);

async function saveLook(page, look) {
    await visit(page, 'agent/user/user_preferences.php');
    await page.locator('select[name=mit_look]').selectOption(look);
    await Promise.all([page.waitForNavigation(), page.locator('button[name=edit_your_user_preferences]').click()]);
}

test.afterAll(async ({ browser }) => {
    if (made.look !== null) {
        db.exec(made.look === undefined ? `DELETE FROM mit_user_preferences WHERE user_id = ${ME()} AND preference = 'ui.look'`
            : `UPDATE mit_user_preferences SET value = ${db.q(made.look)} WHERE user_id = ${ME()} AND preference = 'ui.look'`);
    }
    if (made.clientId) {
        db.exec(`DELETE FROM assets WHERE asset_client_id = ${made.clientId}; DELETE FROM software WHERE software_client_id = ${made.clientId};
            DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
});

test('Account > Preferences: the look is offered and saved, and every agent page follows it', async ({ page }) => {
    const errors = watch(page);
    made.look = db.val(`SELECT value FROM mit_user_preferences WHERE user_id = ${ME()} AND preference = 'ui.look'`) ?? undefined;
    await visit(page, 'agent/user/user_preferences.php', errors);
    await expect(page.locator('select[name=mit_look]'), 'the Look field').toHaveCount(1);

    await saveLook(page, 'classic');
    expect(db.val(`SELECT value FROM mit_user_preferences WHERE user_id = ${ME()} AND preference = 'ui.look'`), 'saved classic').toBe('classic');
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('link[href*="/css/mit_theme.css"]'), 'classic: no modern styles').toHaveCount(0);

    await saveLook(page, 'modern');
    expect(db.val(`SELECT value FROM mit_user_preferences WHERE user_id = ${ME()} AND preference = 'ui.look'`), 'saved modern').toBe('modern');
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('link[href*="/css/mit_theme.css"]'), 'modern: our styles on the page').toHaveCount(1);
    await expectClean(page, errors);
});

test('the sign-in screens load the backdrop', async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    for (const url of ['login.php', 'client/login_reset.php']) {
        await page.goto(url);
        await expect(page.locator('link[href*="/css/mit_login.css"]'), `${url}: our sign-in styles`).toHaveCount(1);
    }
    await context.close();
});

test("a client's Assets and Licenses pages open with their tiles", async ({ page }) => {
    const errors = watch(page);
    const name = smokeName('theme tiles client');
    made.clientId = db.insert(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    db.exec(`INSERT INTO assets SET asset_type = 'Desktop', asset_name = 'Tiles PC 1', asset_make = 'Smoke', asset_client_id = ${made.clientId},
        asset_warranty_expire = CURDATE() - INTERVAL 10 DAY;
        INSERT INTO assets SET asset_type = 'Desktop', asset_name = 'Tiles PC 2', asset_make = 'Smoke', asset_client_id = ${made.clientId},
        asset_warranty_expire = CURDATE() + INTERVAL 30 DAY;
        INSERT INTO software SET software_name = 'Tiles Suite', software_type = 'Other', software_license_type = 'Device', software_seats = 5,
        software_expire = CURDATE() - INTERVAL 1 DAY, software_client_id = ${made.clientId}`);
    const tiles = async () => page.locator('.mit-tiles .mit-tile').evaluateAll((t) => Object.fromEntries(t.map((x) => [x.querySelector('.mit-tile-label').textContent.trim(),
        x.querySelector('.mit-tile-value').textContent.trim()])));
    await visit(page, `agent/assets.php?client_id=${made.clientId}`, errors);
    expect(await tiles(), 'asset tiles').toMatchObject({ Assets: '2', 'Warranty expired': '1', 'Warranty ends in 90 days': '1' });
    await visit(page, `agent/software.php?client_id=${made.clientId}`, errors);
    expect(await tiles(), 'license tiles').toMatchObject({ Licenses: '1', Seats: '5', Expired: '1' });
    await expectClean(page, errors);
});
