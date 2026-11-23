// An agent's own time zone is ITFlow's since 26.12 (functions/user_timezone.php - "My time zone" in Account > Preferences,
// user_settings.user_config_timezone; the calendar, Dispatch and booking emails on their clock - built from ours). Ours on top
// (functions/mit_timesheets.php mitTsSaveZone, one line in ITFlow's timezoneSave): the timesheet follows it - the person's timesheet zone
// (mit_timesheet_people.time_zone) and a dated change (mit_ts_zone_changes), so ticket time can't move in or out of a period handed in;
// back on the company's clock clears it. The admin's zone is put back.
const { test, expect } = require('@playwright/test');
const { watch, visit, fillForm, submit, openModal, expectClean } = require('./helpers');
const { sql, one, php, makeTicket, deleteTickets } = require('./t2db');

const ZONE = 'Asia/Tokyo';

test('"My time zone" in Preferences: the timesheet follows it', async ({ page }) => {
    const errors = watch(page);
    const company = php("echo date_default_timezone_get();").trim();
    test.skip(company === ZONE, 'the company is in Tokyo already');
    const before = one('SELECT user_config_timezone FROM user_settings WHERE user_id = 1');
    const changes = Number(one('SELECT COUNT(*) FROM mit_ts_zone_changes WHERE user_id = 1'));
    const save = async (zone) => {
        await visit(page, 'agent/user/user_preferences.php', errors);
        const form = page.locator('form:has([name="edit_your_user_preferences"])');
        await fillForm(form, { timezone: zone });
        await submit(form, 'edit_your_user_preferences');
    };
    try {
        await save(ZONE);
        expect(one('SELECT user_config_timezone FROM user_settings WHERE user_id = 1'), 'ITFlow\'s own field saved').toBe(ZONE);
        expect(one('SELECT time_zone FROM mit_timesheet_people WHERE user_id = 1'), 'the timesheet zone follows').toBe(ZONE);
        expect(Number(one('SELECT COUNT(*) FROM mit_ts_zone_changes WHERE user_id = 1')), 'the change recorded').toBeGreaterThan(changes);
        await visit(page, 'agent/mit_timesheet.php', errors);
        await expect(page.locator('#mitTsZone'), 'the timesheet says which clock').toContainText('Tokyo');

        await save('');
        expect(one('SELECT user_config_timezone FROM user_settings WHERE user_id = 1'), 'the company\'s again').toBeNull();
        expect(one('SELECT time_zone FROM mit_timesheet_people WHERE user_id = 1'), 'and the timesheet\'s').toBeNull();
        await expectClean(page, errors);
    } finally {
        if (before) {
            await save(before).catch(() => {});
        } else {
            sql('UPDATE user_settings SET user_config_timezone = NULL WHERE user_id = 1');
            sql('UPDATE mit_timesheet_people SET time_zone = NULL WHERE user_id = 1');
        }
    }
});

// The reply box's "When it was worked" on the agent's own clock (ours on top of ITFlow 26.12, functions/mit_reply_times.php - the user's
// choice): typed in Tokyo, kept on the company's clock, shown back as typed in the note header, with a hint by the box.
test("note times: typed on the agent's own clock, stored on the company's", async ({ page }) => {
    const errors = watch(page);
    const company = php("echo date_default_timezone_get();").trim();
    test.skip(company === ZONE, 'the company is in Tokyo already');
    const before = one('SELECT user_config_timezone FROM user_settings WHERE user_id = 1');
    const client = Number(one('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1'));
    const ticket = makeTicket({ subject: 'Smoke 95 own clock', client, assigned: 1 });
    const day = php("echo date('Y-m-d', strtotime('-1 day'));").trim();
    const save = async (zone) => {
        await visit(page, 'agent/user/user_preferences.php', errors);
        const form = page.locator('form:has([name="edit_your_user_preferences"])');
        await fillForm(form, { timezone: zone });
        await submit(form, 'edit_your_user_preferences');
    };
    try {
        await save(ZONE);
        await visit(page, `agent/ticket.php?client_id=${client}&ticket_id=${ticket}`, errors);
        await expect(page.locator('form:has(#workTimes) [data-mit-reply-zone]'), 'the box says whose clock').toContainText(ZONE);
        // The box's "now" (what an untyped date / end fills in from) is on their clock, not the company's
        const boxNow = Date.parse((await page.locator('#workTimes').getAttribute('data-now')) + 'Z');
        const theirNow = Date.parse(php(`echo (new DateTime('now', new DateTimeZone('${ZONE}')))->format('Y-m-d\\TH:i:s');`).trim() + 'Z');
        expect(Math.abs(boxNow - theirNow), "the reply box's now on their clock").toBeLessThan(5 * 60000);
        await page.click('label[for=public_reply_type_opt1]');
        await page.locator('#replyComposer.show').waitFor();
        await page.evaluate(() => window.tinymce.get(document.querySelector('#replyComposer textarea').id).setContent('Smoke 95 own clock note'));
        await page.fill('#work_date', day);
        await page.fill('#work_start', '09:00');
        await page.fill('#work_end', '10:00');
        await Promise.all([page.waitForNavigation(), page.click('#ticket_add_reply')]);
        const row = JSON.parse(one(`SELECT JSON_OBJECT('s', ticket_reply_work_start, 'e', ticket_reply_work_end) FROM ticket_replies WHERE ticket_reply_ticket_id = ${ticket} ORDER BY ticket_reply_id DESC LIMIT 1`));
        const want = (t) => php(`echo (new DateTime('${day} ${t}', new DateTimeZone('${ZONE}')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');`).trim();
        expect([row.s, row.e], 'kept on the company clock').toEqual([want('09:00:00'), want('10:00:00')]);
        await expect(page.locator('[title="When the work was done"]').first(), 'shown back on their clock').toContainText('9:00');
        // Edit Note: its times on their clock in the window, and a change typed there stored on the company's
        const replyId = one(`SELECT ticket_reply_id FROM ticket_replies WHERE ticket_reply_ticket_id = ${ticket} ORDER BY ticket_reply_id DESC LIMIT 1`);
        const edit = await openModal(page, `modals/ticket/ticket_reply_edit.php?id=${replyId}`, 'lg');
        await expect(edit.locator('input[name=work_start]'), 'the edit window on their clock').toHaveValue('09:00');
        await expect(edit.locator('[data-mit-reply-zone]'), 'the edit window says whose clock').toContainText(ZONE);
        await edit.locator('input[name=work_end]').fill('10:30');
        await submit(edit.locator('form').first(), 'edit_ticket_reply');
        const edited = JSON.parse(one(`SELECT JSON_OBJECT('s', ticket_reply_work_start, 'e', ticket_reply_work_end) FROM ticket_replies WHERE ticket_reply_id = ${replyId}`));
        expect([edited.s, edited.e], 'an edit typed on their clock, kept on the company clock').toEqual([want('09:00:00'), want('10:30:00')]);
        await expectClean(page, errors);
    } finally {
        deleteTickets([ticket]);
        if (before) {
            await save(before).catch(() => {});
        } else {
            await save('').catch(() => {});
            sql('UPDATE user_settings SET user_config_timezone = NULL WHERE user_id = 1');
            sql('UPDATE mit_timesheet_people SET time_zone = NULL WHERE user_id = 1');
        }
    }
});
