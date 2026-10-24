// Admin > Settings > Notifications: our rows (functions/mit_notifications.php) in ITFlow's table, and who is emailed when a ticket is
// scheduled - defaults per kind of visit, changeable in the Schedule window for one booking:
//  - remote defaults with nobody ticked: a remote booking queues no "Ticket Scheduled" email;
//  - switching the window to Onsite ticks the onsite defaults (contact + an extra address): both are emailed;
//  - a remote booking with the contact ticked by hand emails the contact.
// Every setting is put back as it was.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean } = require('./helpers');

const made = { clientName: smokeName('Notify client'), clientId: 0, ticketId: 0, ref: '', was: null };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const EXTRA = 'smoke-frontdesk@example.invalid';
const day = (offset, hour) => {
    const d = new Date(Date.now() + offset * 86400000);
    return `${d.toISOString().slice(0, 10)}T${String(hour).padStart(2, '0')}:00`;
};

// Set the defaults table ({ 'remote-contact': false, 'onsite-extra': '...' }); returns what was there
async function defaults(page, values) {
    await visit(page, 'admin/settings_notification.php');
    const fields = page.locator('[data-mit-schedule]');
    const before = {};
    for (let i = 0; i < await fields.count(); i++) {
        const field = fields.nth(i);
        const key = await field.getAttribute('data-mit-schedule');
        const box = (await field.getAttribute('type')) === 'checkbox';
        before[key] = box ? await field.isChecked() : await field.inputValue();
        if (key in values) {
            if (box) {
                await field.setChecked(values[key]);
            } else {
                await field.fill(values[key]);
            }
        }
    }
    await submit(page.locator('form:has(button[name=edit_notification_settings])'), 'edit_notification_settings');
    return before;
}

async function schedule(page, when, onsite, ticks = null) {
    await visit(page, ticketPage());
    const modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { scheduled_date_time: when, schedule_minutes: '60' });
    await modal.locator('select[name=onsite]').selectOption(onsite ? '1' : '0');
    if (ticks) {
        for (const [who, on] of Object.entries(ticks)) {
            await modal.locator(`[data-mit-schedule-notify] input[data-who="${who}"]`).setChecked(on);
        }
    }
    const state = {};
    for (const who of ['tech', 'contact', 'watchers']) {
        state[who] = await modal.locator(`[data-mit-schedule-notify] input[data-who="${who}"]`).isChecked();
    }
    state.extra = await modal.locator('[data-mit-schedule-notify] input[data-who="extra"]').inputValue();
    await submit(modal, 'edit_ticket_schedule');
    expect(await flash(page)).not.toMatch(/error|could not|can't/i);
    return state;
}

const queued = async (page) => {
    await visit(page, 'admin/mail_queue.php');
    return page.locator('tr', { hasText: `Ticket Scheduled - [${made.ref}]` });
};

test('notifications: who is emailed for remote and onsite bookings, and per booking', async ({ page }) => {
    test.setTimeout(150000);
    const errors = watch(page);
    await visit(page, 'admin/settings_notification.php', errors);
    await expect(page.locator('[data-mit-schedule-defaults]'), 'the scheduling defaults table').toHaveCount(1);
    await expect(page.locator('input[data-mit-notify="assign_email"]'), 'the other rows').toHaveCount(1);

    // A client whose contact has an email address, and a ticket
    await visit(page, 'agent/clients.php', errors);
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Sam Notify', contact_email: 'smoke-notify@example.invalid' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('notify ticket'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    made.ref = (await page.locator('title').textContent()).match(/[A-Z]+-\d+/)?.[0] || '';
    expect(made.ticketId, 'ticket saved').toBeGreaterThan(0);

    // Remote: nobody; onsite: the contact and an extra address
    made.was = await defaults(page, {
        'remote-tech': false, 'remote-contact': false, 'remote-watchers': false, 'remote-site': false, 'remote-primary': false, 'remote-extra': '',
        'onsite-tech': false, 'onsite-contact': true, 'onsite-watchers': true, 'onsite-site': false, 'onsite-primary': false, 'onsite-extra': EXTRA,
    });
    expect(await flash(page)).toContain('Notification Settings updated');

    // A remote booking: nothing queued
    let state = await schedule(page, day(2, 10), false);
    expect(state, 'the window starts on the remote defaults').toEqual({ tech: false, contact: false, watchers: false, extra: '' });
    await expect(await queued(page), 'nothing queued for a remote booking').toHaveCount(0);

    // Onsite: switching the visit ticks the onsite defaults; the contact and the extra address are emailed
    state = await schedule(page, day(3, 11), true);
    expect(state, 'the onsite defaults').toEqual({ tech: false, contact: true, watchers: true, extra: EXTRA });
    let mails = await queued(page);
    await expect(mails.filter({ hasText: 'smoke-notify@example.invalid' }), 'the contact').toHaveCount(1);
    await expect(mails.filter({ hasText: EXTRA }), 'the extra address').toHaveCount(1);

    // Remote again, the contact ticked by hand for this booking
    await schedule(page, day(4, 9), false, { contact: true });
    mails = await queued(page);
    await expect(mails.filter({ hasText: 'smoke-notify@example.invalid' }), 'the contact again, ticked by hand').toHaveCount(2);
    await expect(mails.filter({ hasText: EXTRA }), 'the extra address only for the onsite one').toHaveCount(1);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.was) {
        await defaults(page, made.was).catch(() => {});
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
