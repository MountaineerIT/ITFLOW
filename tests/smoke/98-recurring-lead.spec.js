// Recurring tickets and invoices made ahead (functions/mit_recurring_lead.php, 26.11.3.2, M055): New Recurring Ticket's Schedule tab takes a
// time, length, visit and "Create it N days before" (kept, and shown again in Edit); Edit Recurring Invoice takes "Make it N days before"
// and the Due rule. What the nightly job then does with them is in tests/scenarios/scenario-recurring-lead.php.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, expectClean } = require('./helpers');
const db = require('./mitdb');

const CLIENT = Number(db.val('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1'));
const made = { ticket: 0, invoice: 0, subject: smokeName('made ahead') };
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

test.beforeAll(() => {
    test.skip(!db.val("SHOW TABLES LIKE 'mit_recurring_ticket_plan'"), 'database update M055 not run');
});

test.afterAll(() => {
    if (made.ticket) {
        db.exec(`DELETE FROM recurring_tickets WHERE recurring_ticket_id = ${made.ticket}`);
    }
    if (made.invoice) {
        db.exec(`DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = ${made.invoice}; DELETE FROM history WHERE history_recurring_invoice_id = ${made.invoice};
            DELETE FROM recurring_invoices WHERE recurring_invoice_id = ${made.invoice}`);
    }
});

test('recurring ticket: time, length, visit and days ahead saved and shown again', async ({ page }) => {
    const errors = watch(page);
    const first = new Date(Date.now() + 20 * 86400000);
    await visit(page, `agent/recurring_tickets.php?client_id=${CLIENT}`, errors);
    let modal = await openModal(page, `modals/recurring_ticket/recurring_ticket_add.php?client_id=${CLIENT}`, 'lg');
    await expect(modal.locator('[data-mit-recurring-plan]'), 'Book the work on the Schedule tab').toHaveCount(1);
    await fillForm(modal, { subject: made.subject, priority: 'Low', frequency: 'Monthly', start_date: ymd(first),
        mit_plan_time: '09:30', mit_plan_minutes: '90', mit_plan_onsite: '1', mit_plan_lead: '7' });
    await submit(modal, 'add_recurring_ticket');
    made.ticket = Number(db.val(`SELECT recurring_ticket_id FROM recurring_tickets WHERE recurring_ticket_subject = ${db.q(made.subject)}`) || 0);
    expect(made.ticket, `saved (${await flash(page)})`).toBeGreaterThan(0);
    expect(db.val(`SELECT CONCAT_WS('|', schedule_time, schedule_minutes, schedule_onsite, lead_days) FROM mit_recurring_ticket_plan WHERE recurring_ticket_id = ${made.ticket}`))
        .toBe('09:30:00|90|1|7');

    modal = await openModal(page, `modals/recurring_ticket/recurring_ticket_edit.php?id=${made.ticket}`, 'lg');
    await expect(modal.locator('[name=mit_plan_time]'), 'Edit shows the time').toHaveValue('09:30');
    await expect(modal.locator('[name=mit_plan_lead]'), 'and the days ahead').toHaveValue('7');
    // More days than one repeat would make a run twice - kept under a month (27)
    await fillForm(modal, { mit_plan_lead: '40', mit_plan_time: '' });
    await submit(modal, 'edit_recurring_ticket');
    expect(db.val(`SELECT CONCAT_WS('|', IFNULL(schedule_time, 'none'), lead_days) FROM mit_recurring_ticket_plan WHERE recurring_ticket_id = ${made.ticket}`))
        .toBe('none|27');
    await expectClean(page, errors);
});

test('recurring invoice: days ahead and the Due rule saved and shown again', async ({ page }) => {
    const errors = watch(page);
    const next = new Date(Date.now() + 30 * 86400000);
    made.invoice = db.insert(`INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'ZZR-', recurring_invoice_number = ${900000 + Math.floor(Math.random() * 90000)},
        recurring_invoice_scope = ${db.q(made.subject)}, recurring_invoice_frequency = 'month', recurring_invoice_next_date = '${ymd(next)}', recurring_invoice_anchor_day = ${next.getDate()},
        recurring_invoice_status = 1, recurring_invoice_discount_amount = 0, recurring_invoice_amount = 0, recurring_invoice_currency_code = 'USD',
        recurring_invoice_auto_send = 0, recurring_invoice_client_id = ${CLIENT},
        recurring_invoice_category_id = ${Number(db.val("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1") || 0)}`);
    await visit(page, `agent/recurring_invoice.php?recurring_invoice_id=${made.invoice}`, errors);
    let modal = await openModal(page, `modals/recurring_invoice/recurring_invoice_edit.php?id=${made.invoice}`, 'lg');
    await expect(modal.locator('[data-mit-recurring-invoice-plan]'), 'Make it ... days before').toHaveCount(1);
    await fillForm(modal, { mit_plan_lead: '10', mit_plan_due: 'next' });
    await submit(modal, 'edit_recurring_invoice');
    expect(db.val(`SELECT CONCAT_WS('|', lead_days, due_mode) FROM mit_recurring_invoice_plan WHERE recurring_invoice_id = ${made.invoice}`)).toBe('10|next');
    modal = await openModal(page, `modals/recurring_invoice/recurring_invoice_edit.php?id=${made.invoice}`, 'lg');
    await expect(modal.locator('[name=mit_plan_lead]')).toHaveValue('10');
    await expect(modal.locator('[name=mit_plan_due]')).toHaveValue('next');
    await expectClean(page, errors);
});
