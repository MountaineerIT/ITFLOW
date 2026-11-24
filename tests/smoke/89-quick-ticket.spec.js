// Quick ticket is ITFlow's own since 26.12 (functions/quick_ticket.php, agent/modals/ticket/ticket_quick.php - built from ours).
// What stays ours (functions/mit_boards.php): the board a quick ticket lands on - "Board it lands on" in Admin > Settings > Tickets'
// Quick tickets section (counted in ITFlow's saved message), the window's hidden board input, and the ticket placed on that board.
// Everything it makes is removed and the setting put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean, flash, openModal, fillForm, submit } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { client: 0, contact: 0, quick: 0, board: null };

test.beforeAll(() => {
    made.board = db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'quick_ticket_board'");
    made.client = db.insert(`INSERT INTO clients SET client_name = ${db.q(smokeName('quick client'))}, client_currency_code = 'USD', client_created_at = NOW()`);
    made.contact = db.insert(`INSERT INTO contacts SET contact_name = ${db.q(smokeName('Quick Caller'))}, contact_email = 'quick.caller.${Date.now()}@example.com',
        contact_primary = 1, contact_client_id = ${made.client}, contact_created_at = NOW()`);
});

test.afterAll(() => {
    if (made.quick) {
        db.exec(`DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.quick}; DELETE FROM mit_ticket_boards WHERE ticket_id = ${made.quick};
            DELETE FROM mit_board_moves WHERE ticket_id = ${made.quick}; DELETE FROM notifications WHERE notification_entity_id = ${made.quick};
            DELETE FROM email_queue WHERE email_subject LIKE '%Smoke test quick%'; DELETE FROM tickets WHERE ticket_id = ${made.quick}`);
    }
    if (made.client) {
        db.exec(`DELETE FROM contacts WHERE contact_client_id = ${made.client}; DELETE FROM logs WHERE log_client_id = ${made.client}; DELETE FROM clients WHERE client_id = ${made.client}`);
    }
    db.exec("DELETE FROM mit_settings WHERE setting_name = 'quick_ticket_board'");
    if (made.board !== null) {
        db.exec(`INSERT INTO mit_settings SET setting_name = 'quick_ticket_board', setting_value = ${db.q(made.board)}`);
    }
});

test('Admin > Settings > Tickets: "Board it lands on" saves, in ITFlow\'s message', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings_ticket.php', errors);
    const field = page.locator('[name="mit_quick_ticket_board"]');
    await expect(field).toHaveCount(1);
    await field.evaluate((el) => { el.value = '3'; el.dispatchEvent(new Event('change', { bubbles: true })); }); // Tier 2
    await Promise.all([page.waitForNavigation(), page.locator('button[name="edit_ticket_settings"]').click()]);
    await expectClean(page, errors);
    expect(await flash(page)).toMatch(/saved/i);
    expect(db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'quick_ticket_board'")).toBe('3');
    expect(db.php("echo isset(settingsFields()['mit_quick_ticket_board']) ? 'yes' : 'no';")).toBe('yes');
});

test('a quick ticket lands on that board', async ({ page }) => {
    const errors = watch(page);
    await visit(page, `agent/client_overview.php?client_id=${made.client}`, errors);
    const modal = await openModal(page, `/agent/modals/ticket/ticket_quick.php?client_id=${made.client}`);
    await expect(modal.locator('[data-mit-quick-board]'), 'the hidden board input').toHaveValue('3');
    await fillForm(modal, { client_id: made.client, subject: smokeName('quick printer jams') });
    await modal.locator('[name="contact_id"]').evaluate((el, id) => {
        el.disabled = false;
        if (![...el.options].some((o) => o.value === String(id))) { el.add(new Option('c', String(id))); }
        el.value = String(id);
    }, made.contact);
    await submit(modal, 'add_ticket');
    await expectClean(page, errors);
    made.quick = Number(db.val(`SELECT ticket_id FROM tickets WHERE ticket_client_id = ${made.client} ORDER BY ticket_id DESC LIMIT 1`));
    expect(made.quick).toBeGreaterThan(0);
    expect(db.val(`SELECT board_id FROM mit_ticket_boards WHERE ticket_id = ${made.quick}`), 'on the board set').toBe('3');
});
