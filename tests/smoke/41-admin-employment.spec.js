// Employment (pay type) set by an admin in Admin > Users (functions/mit_timesheets.php mitTsPayTypeField / mitTsSavePayType, and
// functions/mit_invites.php for an invite): the field is in ITFlow's New / Edit User window (admin/modals/user/user_form.php), a new
// login with a password and an edit both save it (admin/post/users.php add_user / edit_user), and an invite keeps it for when they
// join (mitInviteExtrasSave). Written after the hook proof (Nov 23) found no test noticed those four lines gone. Removes its users.

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, submit, flash, expectClean, smokeName } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const stamp = Date.now();
const made = { email: `zz-employ-${stamp}@example.invalid`, invite: `zz-employ-inv-${stamp}@example.invalid` };

function payType(email) {
    return db.val(`SELECT p.pay_type FROM mit_timesheet_people p JOIN users u ON u.user_id = p.user_id WHERE u.user_email = ${db.q(email)}`);
}

async function newUserWindow(page) {
    const modal = await openModal(page, 'modals/user/user_add.php', 'lg');
    await expect(modal.locator('select[name=mit_pay_type]'), 'the Employment field in New User').toHaveCount(1);
    await modal.locator('select[name=role]').selectOption('2', { force: true }); // Technician
    return modal;
}

test('New User: Employment is asked and saved with a password', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/users.php', errors);
    test.skip(db.val("SHOW TABLES LIKE 'mit_timesheet_people'") === null, 'timesheets are not installed');
    const modal = await newUserWindow(page);
    await modal.locator('input[name=name]').fill(smokeName('Employment user'));
    await modal.locator('input[name=email]').fill(made.email);
    await modal.locator('a.nav-link[href$=Security]').click();
    const choice = modal.locator('input[name=sign_in][value=password]');
    if (await choice.count()) {
        await modal.locator(`label[for="${await choice.getAttribute('id')}"]`).click();
    }
    await modal.locator('input[name=password]').fill(`Zz-${stamp}-pass!word`);
    await modal.locator('select[name=mit_pay_type]').selectOption('contractor', { force: true });
    await submit(modal.locator('form').first(), 'add_user');
    await flash(page);
    expect(payType(made.email), 'saved on the new login').toBe('contractor');
    await expectClean(page, errors);
});

test('Edit User: a change of Employment is saved', async ({ page }) => {
    const errors = watch(page);
    const id = db.val(`SELECT user_id FROM users WHERE user_email = ${db.q(made.email)}`);
    test.skip(!id, 'the user from the first test is not there');
    await visit(page, 'admin/users.php', errors);
    const modal = await openModal(page, `modals/user/user_edit.php?id=${id}`, 'lg');
    const field = modal.locator('select[name=mit_pay_type]');
    await expect(field, 'the Employment field in Edit User').toHaveValue('contractor');
    await field.selectOption('salary', { force: true });
    await submit(modal.locator('form').first(), 'edit_user');
    await flash(page);
    expect(payType(made.email), 'changed').toBe('salary');
    await expectClean(page, errors);
});

test('an invite keeps Employment for when they join', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/users.php', errors);
    test.skip(db.val("SHOW TABLES LIKE 'mit_invite_extras'") === null || db.val("SHOW TABLES LIKE 'user_invites'") === null, 'no invites here');
    const modal = await newUserWindow(page);
    await modal.locator('input[name=name]').fill(smokeName('Employment invite'));
    await modal.locator('input[name=email]').fill(made.invite);
    await modal.locator('a.nav-link[href$=Security]').click();
    const choice = modal.locator('input[name=sign_in][value=invite]');
    test.skip(!(await choice.count()), 'invites are not offered here');
    await modal.locator(`label[for="${await choice.getAttribute('id')}"]`).click();
    await modal.locator('select[name=mit_pay_type]').selectOption('contractor', { force: true });
    await submit(modal.locator('form').first(), 'add_user');
    const extras = db.val(`SELECT e.extras FROM mit_invite_extras e JOIN user_invites i ON i.invite_id = e.invite_id WHERE i.invite_email = ${db.q(made.invite)}`);
    expect(JSON.parse(extras || '{}').mit_pay_type, 'kept with the invite').toBe('contractor');
    await expectClean(page, errors);
});

test.afterAll(() => {
    try {
        db.exec(`DELETE FROM user_invites WHERE invite_email = ${db.q(made.invite)}`);
        const id = db.val(`SELECT user_id FROM users WHERE user_email = ${db.q(made.email)}`);
        if (id) {
            db.exec(`DELETE FROM mit_timesheet_people WHERE user_id = ${Number(id)}; DELETE FROM user_settings WHERE user_id = ${Number(id)}; DELETE FROM users WHERE user_id = ${Number(id)}`);
        }
    } catch (e) { /* best effort */ }
});
