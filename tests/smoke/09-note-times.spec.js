// Times on ticket notes are ITFlow's own since 26.12 (the reply box's When it was worked - ticket_replies.ticket_reply_work_start /
// _end). What the timesheet does with them is ours (functions/mit_timesheets.php, functions/mit_reply_times.php): it counts a note on
// the day it was worked and lists it with its times, flagging two that overlap, and an entry's times can be put right there (the
// note follows). Our js/mit_time_inputs.js fills ITFlow's time fields. Also the Contractor pay type
// (never under California rules) and lost time shown with the codes. Makes its own client and ticket (deleted again,
// with their notes, even when a step fails); a person's pay type is put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, clearTime } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0, person: null };
const yesterday = (() => {
    const d = new Date(Date.now() - 86400000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

// A public note (counted on timesheets whatever the internal-notes setting) with these times
async function note(page, text, start, end) {
    await page.click('label[for=public_reply_type_opt1]');
    await page.locator('#replyComposer.show').waitFor();
    await page.evaluate((text) => window.tinymce.get(document.querySelector('#replyComposer textarea').id).setContent(text), text);
    await page.fill('#work_date', yesterday);
    await page.fill('#work_start', start);
    await page.fill('#work_end', end);
    await Promise.all([page.waitForNavigation(), page.click('#ticket_add_reply')]);
    await expectClean(page);
}

test('note times: a client, a ticket and a timed note', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/clients.php');
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${await flash(page)})`).toBeGreaterThan(0);
    const ticketPage = `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;

    await visit(page, ticketPage, errors);
    await note(page, 'Smoke test timed note', '09:00', '09:45');
    await expectClean(page, errors);
});

// js/mit_time_inputs.js, on every agent / admin page
test('time fields: the clock icon puts in now, typing the hour and minutes fills AM / PM from now, a click alone fills nothing', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await page.clock.setFixedTime(new Date('2026-10-21T21:36:00'));
    const errors = watch(page);
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    await page.click('label[for=public_reply_type_opt1]');
    await page.locator('#replyComposer.show').waitFor();
    const start = page.locator('#work_start');
    const end = page.locator('#work_end');
    await clearTime(start);
    await start.scrollIntoViewIfNeeded();
    const box = await start.boundingBox();
    await page.mouse.click(box.x + box.width - 14, box.y + box.height / 2);
    await expect(start, 'the clock icon: now').toHaveValue('21:36');
    await clearTime(end);
    await end.click({ position: { x: 12, y: box.height / 2 } });
    await expect(end, 'clicked into: nothing filled').toHaveValue('');
    await page.keyboard.type('915');
    await expect(end, '9:15 typed in the evening is 9:15 PM').toHaveValue('21:15');
    await expectClean(page, errors);
});

test('timesheet: the note counts on the day it was worked, listed with its times, and overlaps are flagged', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    // A second note overlapping the first
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    await note(page, 'Smoke test overlapping note', '09:30', '10:00');

    await visit(page, `agent/mit_timesheet.php?start=${yesterday}`, errors);
    test.skip(await page.locator('text=Timesheets need the database update').count() > 0, 'Timesheets not set up');
    const day = page.locator(`[data-date="${yesterday}"]`).first();
    // The page opens on today's week - yesterday may be in the week before (today a Sunday)
    const weeks = page.locator('button[data-week]');
    for (let i = 0; i < await weeks.count() && await day.count() === 0; i++) {
        await weeks.nth(i).click();
    }
    test.skip(await day.count() === 0, 'yesterday is not on this timesheet page');
    await expect(day, 'flagged on the day').toContainText('Ticket times overlap');
    await day.click();
    const entries = page.locator('[data-ts-entries] li');
    await expect(entries.filter({ hasText: '09:00 - 09:45' }), 'the first note with its times').toHaveCount(1);
    await expect(entries.filter({ hasText: '09:30 - 10:00' }).locator('.badge', { hasText: 'Overlaps' }), 'the overlap marked').toHaveCount(1);
    // Lost time sits with the codes when the day has some
    if (await day.locator('text=Lost time (auto)').count()) {
        await expect(page.locator('[data-auto-lost]'), 'lost time among the codes').toBeVisible();
    }

    // Put right from the timesheet: a wrong end is refused, "after the one before" moves it clear, and the note follows
    const second = entries.filter({ hasText: '09:30 - 10:00' });
    await second.locator('[data-entry-edit]').click();
    const form = page.locator('[data-entry-form]');
    await form.locator('[data-entry-end]').fill('09:30');
    await form.locator('[data-entry-save]').click();
    await expect(form.locator('[data-entry-error]'), 'start = end refused').not.toBeEmpty();
    await form.locator('[data-entry-after]').click();
    await expect(form.locator('[data-entry-start]')).toHaveValue('09:45');
    await expect(form.locator('[data-entry-end]')).toHaveValue('10:15');
    await form.locator('[data-entry-save]').click();
    await expect(entries.filter({ hasText: '09:45 - 10:15' }), 'moved after the first').toHaveCount(1);
    await expect(page.locator('[data-ts-entries] .badge', { hasText: 'Overlaps' }), 'no overlap left').toHaveCount(0);
    await expect(day, 'the day no longer flagged').not.toContainText('Ticket times overlap');
    // Its length follows the new times too
    await entries.filter({ hasText: '09:00 - 09:45' }).locator('[data-entry-edit]').click();
    await form.locator('[data-entry-end]').fill('09:15');
    await form.locator('[data-entry-end]').press('Enter');
    await expect(entries.filter({ hasText: '09:00 - 09:15' }), 'the first one shortened').toHaveCount(1);
    await expectClean(page, errors);

    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    const times = page.locator('span[title="When the work was done"]');
    await expect(times.filter({ hasText: '9:45 - 10:15 AM' }), 'the note on the ticket moved').toHaveCount(1);
    await expect(times.filter({ hasText: '9:00 - 9:15 AM' }), 'and the other shortened').toHaveCount(1);
    await expect(page.locator('body'), 'the shortened length').toContainText('15m');
    await expectClean(page, errors);
});

// Pay type is set in Admin > Users (Edit user, "Employment"); the People modal only shows it
async function setPayType(page, id, payType) {
    await visit(page, 'admin/users.php');
    const modal = await openModal(page, `modals/user/user_edit.php?id=${id}`, 'lg');
    await fillForm(modal, { mit_pay_type: payType });
    await submit(modal, 'edit_user');
}

test('timesheets: a Contractor (set in Admin > Users) is never under California rules', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_timesheets.php?tab=people', errors);
    const row = page.locator('tr', { has: page.locator('.badge', { hasText: 'California' }) }).first();
    test.skip(await row.count() === 0, 'nobody is on California rules here');
    const id = new URL(await row.locator('[data-modal-url*="person.php?id="]').getAttribute('data-modal-url'), page.url()).searchParams.get('id');
    let modal = await openModal(page, `modals/mit_timesheets/person.php?id=${id}`);
    made.person = { id, pay_type: await modal.locator('[data-mit-pay-type]').getAttribute('data-mit-pay-type'), ca_rules: await modal.locator('select[name=ca_rules]').inputValue() };
    // Even with California rules switched on for them by hand
    await fillForm(modal, { ca_rules: '1' });
    await submit(modal, 'mit_ts_person_save');
    await setPayType(page, id, 'contractor');
    await visit(page, 'admin/mit_timesheets.php?tab=people', errors);
    const person = page.locator('tr', { has: page.locator(`[data-modal-url$="person.php?id=${id}"]`) });
    await expect(person, 'shown as a contractor').toContainText('Contractor');
    await expect(person.locator('.badge', { hasText: 'California' }), 'no California rules').toHaveCount(0);
    // Saving the People details keeps the pay type set in Users
    modal = await openModal(page, `modals/mit_timesheets/person.php?id=${id}`);
    await fillForm(modal, { ca_rules: made.person.ca_rules });
    await submit(modal, 'mit_ts_person_save');
    await expect(person, 'still a contractor').toContainText('Contractor');
    await setPayType(page, id, made.person.pay_type);
    made.person = null;
    await visit(page, 'admin/mit_timesheets.php?tab=people', errors);
    await expect(person.locator('.badge', { hasText: 'California' }), 'back on California rules').toHaveCount(1);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.person) {
        await page.goto('admin/mit_timesheets.php?tab=people');
        const modal = await openModal(page, `modals/mit_timesheets/person.php?id=${made.person.id}`);
        await fillForm(modal, { ca_rules: made.person.ca_rules });
        await submit(modal, 'mit_ts_person_save');
        await setPayType(page, made.person.id, made.person.pay_type);
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    await context.close();
});
