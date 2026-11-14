// "When the client replies" can move a ticket to any working status (functions/mit_reply_status.php): on Admin > Ticket Statuses a new
// "Client responded" status is made with ITFlow's own window, On Hold is set to "Move it to" it, the window keeps the choice and the list
// says where it goes; Closed keeps ITFlow's fixed answer. The rules and a real email reply are in tests/scenarios/scenario-reply-status.php.
// Puts On Hold back and removes the status after.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, expectClean } = require('./helpers');
const db = require('./mitdb');

const made = { status: smokeName('Client responded'), statusId: 0, onHold: null };

test('On Hold set to move a client reply to a "Client responded" status', async ({ page }) => {
    const errors = watch(page);
    made.onHold = db.val('SELECT reply_status_id FROM mit_ticket_status_reply WHERE ticket_status_id = 3');
    await visit(page, 'admin/ticket_statuses.php', errors);
    let modal = await openModal(page, 'modals/ticket_status/ticket_status_add.php');
    await fillForm(modal, { name: made.status });
    await submit(modal, 'add_ticket_status');
    expect(await flash(page)).toContain('added');
    made.statusId = Number(db.val(`SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = ${db.q(made.status)}`));
    expect(made.statusId, 'status made').toBeGreaterThan(0);

    modal = await openModal(page, 'modals/ticket_status/ticket_status_edit.php?id=3');
    const picker = modal.locator('[data-mit-reply-status] select[name=client_reply]');
    await expect(picker.locator(`option[value="1:${made.statusId}"]`), 'offered on On Hold').toHaveCount(1);
    await expect(picker.locator('option[value="1:5"]'), 'never Closed').toHaveCount(0);
    await picker.selectOption(`1:${made.statusId}`);
    await submit(modal, 'edit_ticket_status');
    expect(await flash(page)).toContain('saved');
    expect(Number(db.val('SELECT reply_status_id FROM mit_ticket_status_reply WHERE ticket_status_id = 3')), 'kept').toBe(made.statusId);
    expect(db.val('SELECT ticket_status_client_reply FROM ticket_statuses WHERE ticket_status_id = 3'), "ITFlow's own column stays back to Open").toBe('1');
    await expect(page.locator('tr[data-ticket-status-id="3"] [data-mit-reply-to]').first(), 'the list says where it goes').toContainText(made.status);

    modal = await openModal(page, 'modals/ticket_status/ticket_status_edit.php?id=3');
    await expect(modal.locator('[data-mit-reply-status] select[name=client_reply]'), 'the window keeps it').toHaveValue(`1:${made.statusId}`);
    await page.keyboard.press('Escape');
    await openModal(page, 'modals/ticket_status/ticket_status_edit.php?id=5');
    await expect(page.locator('.modal.show [data-mit-reply-status]'), 'Closed keeps ITFlow\'s fixed text').toHaveCount(0);
    await expectClean(page, errors);
});

test.afterAll(() => {
    db.exec('DELETE FROM mit_ticket_status_reply WHERE ticket_status_id = 3');
    if (made.onHold) {
        db.exec(`INSERT IGNORE INTO mit_ticket_status_reply SET ticket_status_id = 3, reply_status_id = ${Number(made.onHold)}`);
    }
    if (made.statusId) {
        db.exec(`DELETE FROM ticket_statuses WHERE ticket_status_id = ${made.statusId}`);
    }
});
