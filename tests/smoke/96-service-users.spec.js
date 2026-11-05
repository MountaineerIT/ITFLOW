// API / service accounts (functions/mit_service_users.php, 26.11.2.6 - the user's request): a login an API key acts as, marked in
// Admin > Users > Edit, drops out of the calendar's Whose, Dispatch's Agents, the New Ticket assign picker and the main users list
// (it gets its own section at the bottom), and can't sign in to ITFlow. Unmarked it is back.
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, openModal, submit } = require('./helpers');
const db = require('./mitdb');

const NAME = 'Smoke 96 API User';
const EMAIL = 'smoke96-api@example.test';
const PASSWORD = 'Smoke96-pass-2026!';

test('an API / service account is left out of the agent lists and can\'t sign in', async ({ page, browser }) => {
    test.setTimeout(180000);
    const errors = watch(page);
    test.skip(!db.val("SHOW TABLES LIKE 'mit_service_users'"), 'database update M054 not run');
    // A role that works tickets (Dispatch lists only those), not an admin one
    const role = Number(db.val(`SELECT r.role_id FROM user_roles r JOIN user_role_permissions p ON p.user_role_id = r.role_id
        JOIN modules m ON m.module_id = p.module_id AND m.module_name = 'module_support'
        WHERE r.role_is_admin = 0 AND r.role_archived_at IS NULL AND p.user_role_permission_level >= 2 ORDER BY r.role_id LIMIT 1`)
        || db.val("SELECT role_id FROM user_roles WHERE role_is_admin = 1 ORDER BY role_id LIMIT 1"));
    db.exec(`DELETE FROM users WHERE user_email = ${db.q(EMAIL)}`);
    const hash = db.php(`echo password_hash(${JSON.stringify(PASSWORD)}, PASSWORD_DEFAULT);`).trim();
    const user = db.insert(`INSERT INTO users SET user_name = ${db.q(NAME)}, user_email = ${db.q(EMAIL)}, user_password = ${db.q(hash)},
        user_auth_method = 'local', user_type = 1, user_status = 1, user_role_id = ${role}`);
    db.exec(`INSERT IGNORE INTO user_settings SET user_id = ${user}`);
    // The pages' own HTML (faster than drawing them): the calendar's Whose, Dispatch's Agents, New Ticket's assign picker, Admin > Users
    const html = async (url) => {
        const r = await page.request.get(url);
        const t = await r.text();
        return url.includes('/modals/') ? (JSON.parse(t).content || '') : t;
    };
    const lists = async () => {
        const calendar = await html('agent/calendar.php');
        const whose = (calendar.match(/<select[^>]*id="calendarAgent"[\s\S]*?<\/select>/) || [''])[0];
        const dispatchPage = await html('agent/mit_dispatch.php');
        const dispatch = [...dispatchPage.matchAll(/value="(\d+)"[^>]*data-md-tech/g)].map((m) => m[1]);
        const add = await html('agent/modals/ticket/ticket_add.php');
        const assign = (add.match(/<select[^>]*name="assigned_to"[\s\S]*?<\/select>/) || [''])[0];
        const users = await html('admin/users.php');
        const cut = users.indexOf('data-mit-service-users');
        return { whose, dispatch, assign, main: cut > 0 ? users.slice(0, cut) : users, section: cut > 0 ? users.slice(cut) : '' };
    };
    try {
        // An ordinary agent first: everywhere
        let seen = await lists();
        expect(seen.whose, 'Whose lists the agent').toContain(NAME);
        expect(seen.assign, 'New Ticket offers the agent').toContain(NAME);
        expect(seen.main, 'in the main users list').toContain(NAME);
        if (seen.dispatch.length) {
            expect(seen.dispatch, 'on Dispatch\'s Agents').toContain(String(user));
        }

        // Marked in Admin > Users > Edit
        await visit(page, 'admin/users.php', errors);
        const modal = await openModal(page, `modals/user/user_edit.php?id=${user}`, 'lg');
        const sw = modal.locator('[name="mit_service_user"]');
        await expect(sw, 'the switch is in the user window').toHaveCount(1);
        await sw.check({ force: true });
        await submit(modal, 'edit_user');
        expect(Number(db.val(`SELECT COUNT(*) FROM mit_service_users WHERE user_id = ${user}`)), 'saved').toBe(1);

        seen = await lists();
        expect(seen.whose, 'off the calendar\'s Whose').not.toContain(NAME);
        expect(seen.dispatch, 'off Dispatch\'s Agents').not.toContain(String(user));
        expect(seen.assign, 'off the New Ticket assign picker').not.toContain(NAME);
        expect(seen.main, 'off the main users list').not.toContain(NAME);
        expect(seen.section, 'in its own section').toContain(NAME);

        // It can't sign in
        const other = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const login = await other.newPage();
        await login.goto('login.php');
        await login.locator('[name="email"]').fill(EMAIL);
        await login.locator('[name="password"]').fill(PASSWORD);
        await login.locator('[name="login"], button[type="submit"]').first().click();
        await login.waitForLoadState('networkidle');
        expect(login.url(), 'the sign-in is refused').toContain('login.php');
        await other.close();

        // Unmarked: back
        await visit(page, 'admin/users.php', errors);
        const again = await openModal(page, `modals/user/user_edit.php?id=${user}`, 'lg');
        await again.locator('[name="mit_service_user"]').uncheck({ force: true });
        await submit(again, 'edit_user');
        expect(Number(db.val(`SELECT COUNT(*) FROM mit_service_users WHERE user_id = ${user}`)), 'unmarked').toBe(0);
        seen = await lists();
        expect(seen.whose, 'back in Whose').toContain(NAME);
        await expectClean(page, errors);
    } finally {
        db.exec(`DELETE FROM user_settings WHERE user_id = ${user}; DELETE FROM users WHERE user_id = ${user}`);
    }
});
