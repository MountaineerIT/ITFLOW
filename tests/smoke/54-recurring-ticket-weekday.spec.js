// A recurring ticket repeating "on the third Wednesday" (functions/mit_recurring.php + js/mit_repeat_on.js, hooks in ITFlow's
// recurring ticket windows and agent/post/recurring_ticket.php): offered in New Recurring Ticket from the date picked and saved as
// 3WE, shown again in Edit, and put back to "the same date each month" from there. The recurring ticket is deleted after.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, expectClean } = require('./helpers');
const db = require('./mitdb');

const CLIENT = Number(db.val('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1'));
const made = { id: 0, subject: smokeName('third wednesday') };
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
function thirdWednesday(year, month) { // month 0-11
    const d = new Date(year, month, 1);
    while (d.getDay() !== 3) {
        d.setDate(d.getDate() + 1);
    }
    d.setDate(d.getDate() + 14);
    return d;
}

test.afterAll(() => {
    if (made.id) {
        db.exec(`DELETE FROM recurring_tickets WHERE recurring_ticket_id = ${made.id}`);
    }
});

test('recurring ticket on the third Wednesday: offered, saved, shown in Edit, and put back to the same date', async ({ page }) => {
    const errors = watch(page);
    const now = new Date();
    const first = thirdWednesday(now.getFullYear(), now.getMonth() + 1);
    await visit(page, `agent/recurring_tickets.php?client_id=${CLIENT}`, errors);
    let modal = await openModal(page, `modals/recurring_ticket/recurring_ticket_add.php?client_id=${CLIENT}`, 'lg');
    await fillForm(modal, { subject: made.subject, priority: 'Low', frequency: 'Monthly', start_date: ymd(first) });
    const repeatOn = modal.locator('select[name=repeat_on]');
    await expect(repeatOn, 'Repeats on offered for a monthly ticket').toBeEnabled();
    expect(await repeatOn.locator('option').evaluateAll((o) => o.map((x) => x.value)), 'the choices follow the date').toContain('3WE');
    await fillForm(modal, { repeat_on: '3WE' });
    await submit(modal, 'add_recurring_ticket');
    made.id = Number(db.val(`SELECT recurring_ticket_id FROM recurring_tickets WHERE recurring_ticket_subject = ${db.q(made.subject)}`) || 0);
    expect(made.id, `saved (${await flash(page)})`).toBeGreaterThan(0);
    expect(db.val(`SELECT recurring_ticket_by_day FROM recurring_tickets WHERE recurring_ticket_id = ${made.id}`), 'the rule saved').toBe('3WE');

    modal = await openModal(page, `modals/recurring_ticket/recurring_ticket_edit.php?id=${made.id}`, 'lg');
    await expect(modal.locator('select[name=repeat_on]'), 'Edit shows the rule').toHaveValue('3WE');
    await fillForm(modal, { repeat_on: '' });
    await submit(modal, 'edit_recurring_ticket');
    expect(db.val(`SELECT recurring_ticket_by_day FROM recurring_tickets WHERE recurring_ticket_id = ${made.id}`), 'back to the same date each month').toBeNull();

    // Moved in bulk (Recurring Tickets > tick > Next run) to a date: a weekday rule follows the new date's weekday
    db.exec(`UPDATE recurring_tickets SET recurring_ticket_by_day = '3WE' WHERE recurring_ticket_id = ${made.id}`);
    const second = new Date(first.getFullYear(), first.getMonth() + 1, 1);
    while (second.getDay() !== 2) {
        second.setDate(second.getDate() + 1);
    }
    second.setDate(second.getDate() + 7); // the second Tuesday of the month after
    const token = await page.locator('input[name=csrf_token]').first().inputValue();
    await db.pagePost(page, '/agent/post.php', { csrf_token: token, bulk_edit_recurring_ticket_next_run_date: '1', next_run_date: ymd(second), 'recurring_ticket_ids[]': [String(made.id)] });
    expect(db.val(`SELECT recurring_ticket_next_run FROM recurring_tickets WHERE recurring_ticket_id = ${made.id}`), 'moved').toBe(ymd(second));
    expect(db.val(`SELECT recurring_ticket_by_day FROM recurring_tickets WHERE recurring_ticket_id = ${made.id}`), 'the rule follows: the second Tuesday').toBe('2TU');
    await expectClean(page, errors);
});
