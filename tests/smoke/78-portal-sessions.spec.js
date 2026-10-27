// Client portal sign-ins end when the password or two-step sign-in changes (mitSessionRestamp / mitSessionCheck -
// functions/mit_common.php, hooks in client/post.php and client/includes/check_login.php): a contact changing their password,
// turning two-step sign-in on, or off, ends their other sign-ins and the browser they did it in carries on.
// Makes its own client with a portal contact (ITFlow's own client and contact forms), removed at the end.

const crypto = require('crypto');
const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, csrf } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('portal sessions'), clientId: 0, email: `smoke-portal-s-${Date.now()}@example.invalid`, password: 'Smoke-Portal-pass-5821', secret: '' };

/** RFC 6238 code (30 s, 6 digits, SHA-1) for a base32 secret. */
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/\s+/g, '').toUpperCase()) {
        bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30)));
    const hmac = crypto.createHmac('sha1', key).update(counter).digest();
    const at = hmac[hmac.length - 1] & 0xf;
    return String((hmac.readUInt32BE(at) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function signIn(browser) {
    const use = test.info().project.use;
    const context = await browser.newContext({ baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('login.php');
    await page.fill('input[name=email]', made.email);
    await page.fill('input[name=password]', made.password);
    await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
    if (made.secret && await page.locator('input[name=current_code]').count()) {
        await page.fill('input[name=current_code]', totp(made.secret));
        await Promise.all([page.waitForNavigation(), page.locator('button[type=submit]').first().click()]);
    }
    expect(await signedIn(page), 'signed in to the portal').toBe(true);
    return { context, page };
}
async function signedIn(page) {
    await page.goto('client/profile.php');
    return /\/client\//.test(page.url()) && !/login\.php/.test(page.url());
}

test.afterAll(() => {
    if (made.clientId) {
        db.exec(`DELETE u FROM users u JOIN contacts c ON c.contact_user_id = u.user_id WHERE c.contact_client_id = ${made.clientId};
            DELETE FROM contacts WHERE contact_client_id = ${made.clientId}; DELETE FROM locations WHERE location_client_id = ${made.clientId};
            DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
});

test('setup: a client with a portal contact', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/clients.php', errors);
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Sam Session', contact_email: made.email });
    await submit(modal, 'add_client');
    made.clientId = Number(db.val(`SELECT client_id FROM clients WHERE client_name = ${db.q(made.clientName)}`) || 0);
    expect(made.clientId).toBeGreaterThan(0);
    const contactId = db.val(`SELECT contact_id FROM contacts WHERE contact_client_id = ${made.clientId} LIMIT 1`);
    await visit(page, `agent/contacts.php?client_id=${made.clientId}`, errors);
    modal = await openModal(page, `modals/contact/contact_edit.php?id=${contactId}`, 'lg');
    test.skip(await modal.locator('select[name=auth_method]').count() === 0, 'the client portal is off on this install');
    await fillForm(modal, { auth_method: 'local', contact_password: made.password });
    await submit(modal, 'edit_contact');
});

test('changing the password in the portal: the other sign-in ends, this one carries on', async ({ browser }) => {
    test.skip(!made.clientId, 'no setup');
    const mine = await signIn(browser);
    const other = await signIn(browser);
    await mine.page.goto('client/profile.php');
    const token = await csrf(mine.page);
    const next = 'Smoke-Portal-pass-9147';
    await db.pagePost(mine.page, '/client/post.php', { csrf_token: token, portal_password_change: '1', current_password: made.password, new_password: next, confirm_password: next });
    made.password = next;
    expect(await signedIn(mine.page), 'this browser carries on').toBe(true);
    expect(await signedIn(other.page), 'the other sign-in ended').toBe(false);
    await mine.context.close();
    await other.context.close();
});

test('turning two-step sign-in on, then off: the other sign-ins end each time, this one carries on', async ({ browser }) => {
    test.skip(!made.clientId, 'no setup');
    const mine = await signIn(browser);
    const other = await signIn(browser);
    await mine.page.goto('client/profile.php?mfa=setup');
    const secret = (await mine.page.locator('.font-monospace.user-select-all').first().innerText()).replace(/\s+/g, '');
    expect(secret, 'the set up shows its secret').toMatch(/^[A-Z2-7]{16,}$/);
    let token = await csrf(mine.page);
    await db.pagePost(mine.page, '/client/post.php', { csrf_token: token, portal_mfa_enable: '1', verify_code: totp(secret) });
    expect(db.val(`SELECT u.user_token <> '' FROM users u JOIN contacts c ON c.contact_user_id = u.user_id WHERE c.contact_client_id = ${made.clientId}`), 'two-step is on').toBe('1');
    made.secret = secret;
    expect(await signedIn(mine.page), 'this browser carries on').toBe(true);
    expect(await signedIn(other.page), 'the other sign-in ended').toBe(false);
    await other.context.close();

    const third = await signIn(browser);
    token = await csrf(mine.page);
    await db.pagePost(mine.page, '/client/post.php', { csrf_token: token, portal_mfa_disable: '1', current_password: made.password });
    expect(db.val(`SELECT u.user_token = '' OR u.user_token IS NULL FROM users u JOIN contacts c ON c.contact_user_id = u.user_id WHERE c.contact_client_id = ${made.clientId}`), 'two-step is off').toBe('1');
    expect(await signedIn(mine.page), 'this browser carries on').toBe(true);
    expect(await signedIn(third.page), 'the other sign-in ended').toBe(false);
    await mine.context.close();
    await third.context.close();
});
