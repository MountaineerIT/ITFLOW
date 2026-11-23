// API / service accounts are ITFlow's own since 26.12 (functions/service_account.php - users.user_service_account, its switch,
// no browser sign-in, its own lists). What stays ours (functions/mit_service_users.php): our agent lists leave them out too - the
// board window's members, Admin > Timesheets > People. Marked straight in the database (ITFlow's switch is ITFlow's to test).
const { test, expect } = require('@playwright/test');
const { watch, expectClean, visit } = require('./helpers');
const db = require('./mitdb');

const NAME = 'Smoke 96 API User';
const EMAIL = 'smoke96-api@example.test';

test('an API / service account is left out of our agent lists', async ({ page }) => {
    const errors = watch(page);
    test.skip(!db.val("SHOW COLUMNS FROM users LIKE 'user_service_account'"), 'ITFlow 26.12 service accounts not here');
    const role = Number(db.val(`SELECT r.role_id FROM user_roles r JOIN user_role_permissions p ON p.user_role_id = r.role_id
        JOIN modules m ON m.module_id = p.module_id AND m.module_name = 'module_support'
        WHERE r.role_is_admin = 0 AND r.role_archived_at IS NULL AND p.user_role_permission_level >= 2 ORDER BY r.role_id LIMIT 1`)
        || db.val("SELECT role_id FROM user_roles WHERE role_is_admin = 1 ORDER BY role_id LIMIT 1"));
    db.exec(`DELETE FROM users WHERE user_email = ${db.q(EMAIL)}`);
    const user = db.insert(`INSERT INTO users SET user_name = ${db.q(NAME)}, user_email = ${db.q(EMAIL)}, user_password = 'x',
        user_auth_method = 'local', user_type = 1, user_status = 1, user_role_id = ${role}`);
    db.exec(`INSERT IGNORE INTO user_settings SET user_id = ${user}`);
    const html = async (url) => {
        const r = await page.request.get(url);
        const t = await r.text();
        return url.includes('/modals/') ? (JSON.parse(t).content || '') : t;
    };
    const lists = async () => ({
        board: await html('admin/modals/mit_boards/board.php'),
        people: await html('admin/mit_timesheets.php?tab=people'),
    });
    try {
        await visit(page, 'admin/mit_boards.php', errors);
        let seen = await lists();
        expect(seen.board, 'an ordinary agent can be a board member').toContain(NAME);
        expect(seen.people, 'and is on Admin > Timesheets > People').toContain(NAME);

        db.exec(`UPDATE users SET user_service_account = 1 WHERE user_id = ${user}`);
        seen = await lists();
        expect(seen.board, 'a service account is not offered as a board member').not.toContain(NAME);
        expect(seen.people, 'nor listed on Timesheets > People').not.toContain(NAME);
        await expectClean(page, errors);
    } finally {
        db.exec(`DELETE FROM user_settings WHERE user_id = ${user}; DELETE FROM users WHERE user_id = ${user}`);
    }
});
