// Admin > Settings > Mail with our Microsoft 365 app (functions/mit_m365.php, hooks in admin/settings_mail.php and
// admin/post/settings_mail.php): the app card is on the page; saving the OAuth settings for our app keeps its certificate marker in
// place of a typed secret; and Connect Microsoft 365 for our app asks to pick an account (prompt=select_account), not for admin
// consent. The OAuth settings are put back exactly as they were; the trip to Microsoft is stopped in the browser.

const { test, expect } = require('@playwright/test');
const { watch, visit, csrf } = require('./helpers');
const db = require('./mitdb');

const COLS = ['config_mail_oauth_client_id', 'config_mail_oauth_client_secret', 'config_mail_oauth_tenant_id', 'config_mail_oauth_refresh_token',
    'config_mail_oauth_access_token', 'config_mail_oauth_access_token_expires_at'];
const made = { before: null };

test.afterAll(() => {
    if (made.before) {
        db.exec(`UPDATE settings SET ${COLS.map((c) => `${c} = ${made.before[c] === null ? 'NULL' : db.q(made.before[c])}`).join(', ')} WHERE company_id = 1`);
    }
});

test('Admin > Mail: our app card, its certificate marker kept on save, Connect asks to pick an account', async ({ page }) => {
    const errors = watch(page);
    const app = db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'm365_client_id'");
    test.skip(!app, 'no Microsoft 365 app here');
    made.before = db.one(`SELECT ${COLS.join(', ')} FROM settings WHERE company_id = 1`);
    test.skip(made.before.config_mail_oauth_client_id !== app, 'mail is not on our app here');

    await visit(page, 'admin/settings_mail.php', errors);
    await expect(page.locator('#mitM365'), 'the Microsoft 365 app card').toHaveCount(1);

    // A secret typed over our app's: the certificate marker stays
    const token = await csrf(page);
    await db.pagePost(page, '/admin/post.php', { csrf_token: token, tab: 'oauth', edit_mail_oauth_settings: '1', config_mail_oauth_client_id: app,
        config_mail_oauth_client_secret: 'typed-over-secret', config_mail_oauth_tenant_id: made.before.config_mail_oauth_tenant_id });
    expect(db.val('SELECT config_mail_oauth_client_secret FROM settings WHERE company_id = 1'), 'still the certificate marker').toBe('mit-m365-certificate');

    // Test receiving: through Microsoft Graph, the way the mail parser reads the mailbox (not ITFlow's IMAP test)
    const imap = await db.pagePost(page, '/admin/post.php', { csrf_token: token, tab: 'oauth', test_email_imap: '1' });
    // (the page's own app card also names Microsoft Graph, so look for the test's message itself)
    expect(imap.text, 'the mailbox test went through Graph').toMatch(/Connected through Microsoft Graph to|Microsoft Graph mailbox test failed/);
    // Test the token: ITFlow's own refresh, signed with our certificate (the stand-in refuses the placeholder secret)
    const usesStandIn = db.php("echo defined('MICROSOFT_OAUTH_BASE_URL') && strpos(MICROSOFT_OAUTH_BASE_URL, '127.0.0.1') !== false ? 1 : 0;").trim() === '1';
    if (usesStandIn) {
        const tokenTest = await db.pagePost(page, '/admin/post.php', { csrf_token: token, tab: 'oauth', test_oauth_token_refresh: '1', oauth_provider: 'microsoft_oauth' });
        expect(tokenTest.text, 'the token test passes on the certificate').not.toContain('OAuth token test failed');
    }

    // Connect: catch the trip to Microsoft and read what it asks for
    // (a redirect is followed by the browser itself, so it is read from the request events; anything at Microsoft is stopped)
    let authorize = '';
    page.on('request', (request) => {
        if (request.url().includes('/oauth2/v2.0/authorize')) {
            authorize = request.url();
        }
    });
    await page.route('https://login.microsoftonline.com/**', (route) => route.fulfill({ status: 200, body: 'stopped by the smoke test' }));
    await visit(page, 'admin/settings_mail.php?tab=oauth', errors);
    await page.locator('button[name=oauth_connect_microsoft_mail]').first().click();
    await expect.poll(() => authorize, { message: 'went to Microsoft' }).toContain('/oauth2/v2.0/authorize');
    expect(new URL(authorize).searchParams.get('prompt'), 'pick an account, not admin consent').toBe('select_account');
});
