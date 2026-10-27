// Sign-ins end when the password or MFA changes (mitSessionStamp / mitSessionCheck / mitSessionRestamp - functions/mit_common.php,
// hooks in includes/load_user_session.php, agent/user/post/profile.php, functions/user.php):
//  - an admin setting a new password for someone (Admin > Users > Edit) ends every sign-in of theirs (the admin's own carries on);
//  - turning MFA on yourself ends your other sign-ins, and the browser you did it in carries on (turning it off too).
// Not tested, on purpose: userSetPassword's own re-stamp (functions/user.php) - ITFlow signs you out after changing your own password.
// Uses a throwaway agent login of its own (the technician's role), removed at the end.

const crypto = require('crypto');
const { test, expect } = require('@playwright/test');
const { smokeName, visit, csrf } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const PASSWORD = 'Smoke-Session-pass-7341';
const made = { userId: 0, role: 0, email: `smoke-session-${Date.now()}@example.invalid` };

/** RFC 6238 code (30 s, 6 digits, SHA-1) for a base32 secret - what an authenticator app shows. */
function totp(secret, offset = 0) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/\s+/g, '').toUpperCase()) {
        bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    }
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 1000 / 30) + offset));
    const hmac = crypto.createHmac('sha1', key).update(counter).digest();
    const at = hmac[hmac.length - 1] & 0xf;
    return String((hmac.readUInt32BE(at) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function signIn(browser, baseURL) {
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    await page.goto('login.php');
    await page.fill('input[name=email]', made.email);
    await page.fill('input[name=password]', PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
    expect(page.url(), 'signed in').not.toMatch(/login\.php/);
    return { context, page };
}
const signedIn = async (page) => {
    await page.goto('agent/user/user_security.php');
    return !/login\.php/.test(page.url());
};

test.afterAll(() => {
    if (made.userId) {
        db.exec(`DELETE FROM remember_tokens WHERE remember_token_user_id = ${made.userId}; DELETE FROM user_settings WHERE user_id = ${made.userId}; DELETE FROM users WHERE user_id = ${made.userId}`);
    }
});

test('setup: a throwaway agent login', async () => {
    const role = made.role = Number(db.val("SELECT user_role_id FROM users WHERE user_email = 'tech@test.test'") || 0);
    test.skip(!role, 'no technician role to copy');
    made.userId = Number(db.php(`
        mysqli_query($mysqli, "INSERT INTO users SET user_name = 'Smoke Session', user_email = '${made.email}', user_password = '', user_role_id = ${role}, user_type = 1, user_status = 1");
        $id = mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "INSERT IGNORE INTO user_settings SET user_id = $id");
        userSetPassword($id, '${PASSWORD}');
        echo $id;`).trim());
    expect(made.userId).toBeGreaterThan(0);
});

test('an admin sets a new password for them: every sign-in of theirs ends, the admin carries on', async ({ browser, page, baseURL }) => {
    test.skip(!made.userId, 'no login');
    const one = await signIn(browser, baseURL);
    const two = await signIn(browser, baseURL);
    expect(await signedIn(one.page) && await signedIn(two.page), 'both signed in').toBe(true);

    await visit(page, 'admin/users.php');
    const token = await csrf(page);
    // The same password again - the stored one still changes (a new salt), which is what ends the sign-ins
    await db.pagePost(page, '/admin/post.php', { csrf_token: token, edit_user: '1', user_id: String(made.userId), name: 'Smoke Session',
        email: made.email, role: String(made.role), new_password: PASSWORD });
    expect(db.val(`SELECT user_name FROM users WHERE user_id = ${made.userId}`), 'the edit was saved').toBe('Smoke Session');
    expect(await signedIn(one.page), 'first sign-in ended').toBe(false);
    expect(await signedIn(two.page), 'second sign-in ended').toBe(false);
    await visit(page, 'admin/users.php');
    expect(page.url(), 'the admin carries on').not.toMatch(/login\.php/);
    await one.context.close();
    await two.context.close();
});

test('turning MFA on yourself: your other sign-ins end, this one carries on', async ({ browser, baseURL }) => {
    test.skip(!made.userId, 'no login');
    const mine = await signIn(browser, baseURL);
    const other = await signIn(browser, baseURL);
    await mine.page.goto('agent/user/user_security.php');
    const secret = await mine.page.locator('#enableMFAModal [data-clipboard-text]').first().getAttribute('data-clipboard-text');
    expect(secret, 'the set up shows its secret').toMatch(/^[A-Z2-7]{16,}$/);
    const token = await csrf(mine.page);
    await db.pagePost(mine.page, '/agent/user/post.php', { csrf_token: token, enable_mfa: '1', verify_code: totp(secret) });
    expect(db.val(`SELECT user_token <> '' FROM users WHERE user_id = ${made.userId}`), 'MFA is on').toBe('1');
    expect(await signedIn(mine.page), 'the browser that turned it on carries on').toBe(true);
    expect(await signedIn(other.page), 'the other sign-in ended').toBe(false);

    // Turning it off again (with the password) - the stored MFA changes again, and this browser still carries on
    const again = await csrf(mine.page);
    await db.pagePost(mine.page, '/agent/user/post.php', { csrf_token: again, disable_your_mfa: '1', current_password: PASSWORD });
    expect(db.val(`SELECT user_token = '' FROM users WHERE user_id = ${made.userId}`), 'MFA is off').toBe('1');
    expect(await signedIn(mine.page), 'the browser that turned it off carries on').toBe(true);
    await mine.context.close();
    await other.context.close();
});
