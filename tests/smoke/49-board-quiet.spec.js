// Quiet boards (M071, functions/mit_boards.php mitBoardQuietSql - an Alerts board whose tickets come and go by themselves): the switch
// "Keep off Dispatch and the dashboard counts" in a board's window (admin/modals/mit_boards/board.php) saves, the Boards list marks it
// Quiet, the default board can't have it; on Dispatch an unassigned ticket on the quiet board is not in the queue under "My boards" (the
// admin is a member) but is when the board itself is picked. A board, client and ticket of its own, removed at the end.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, expectClean } = require('./helpers');
const db = require('./db');
const { dispatchState } = require('./dispatch');

test.describe.configure({ mode: 'serial' });

const made = { boardId: 0, clientId: 0, ticketId: 0, name: smokeName('Quiet alerts') };

test('a board can be kept off Dispatch and the dashboard counts - never the default board', async ({ page }) => {
    const errors = watch(page);
    test.skip(db.value("SHOW COLUMNS FROM mit_boards LIKE 'board_quiet'") === null, 'quiet boards (M071) not here');
    await visit(page, 'admin/mit_boards.php', errors);
    const modal = await openModal(page, 'modals/mit_boards/board.php');
    await fillForm(modal, { name: made.name, description: 'Alerts that open and close by themselves' });
    await expect(modal.locator('[data-mit-board-quiet] input[name=quiet]'), 'the switch in a new board\'s window').toBeEnabled();
    await modal.locator('[data-mit-board-quiet] label').click();
    await submit(modal, 'mit_board_save');
    await flash(page);
    made.boardId = Number(db.value(`SELECT board_id FROM mit_boards WHERE board_name = ${db.q(made.name)}`));
    expect(db.value(`SELECT board_quiet FROM mit_boards WHERE board_id = ${made.boardId}`), 'saved quiet').toBe('1');
    await visit(page, 'admin/mit_boards.php', errors);
    await expect(page.locator('tr', { hasText: made.name }).locator('[data-mit-board-quiet-badge]'), 'marked Quiet in the list').toHaveCount(1);

    const def = db.value('SELECT board_id FROM mit_boards WHERE board_is_default = 1 AND board_archived_at IS NULL');
    const defWindow = await openModal(page, `modals/mit_boards/board.php?board_id=${def}`);
    await expect(defWindow.locator('[data-mit-board-quiet] input[name=quiet]'), 'not for the default board').toBeDisabled();
    await expectClean(page, errors);
});

test('Dispatch: a quiet board\'s unassigned ticket is off "My boards" but there when the board is picked', async ({ page }) => {
    test.skip(!made.boardId, 'no quiet board from the first test');
    const errors = watch(page);
    db.run(`INSERT IGNORE INTO mit_board_members SET board_id = ${made.boardId}, user_id = 1, member_notify = 0`);
    db.run(`INSERT INTO clients SET client_name = ${db.q(smokeName('Quiet client'))}, client_currency_code = 'USD'`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(smokeName('Quiet client'))}`));
    const n = Number(db.value('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
    db.run(`UPDATE settings SET config_ticket_next_number = ${n + 1} WHERE company_id = 1`);
    const subject = smokeName('quiet alert ticket');
    db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_source = 'Agent', ticket_category = 0, ticket_subject = ${db.q(subject)},
        ticket_details = 'x', ticket_priority = 'Low', ticket_billable = 0, ticket_status = 1, ticket_created_by = 1, ticket_assigned_to = 0, ticket_contact_id = 0,
        ticket_url_key = 'zzquiet${Date.now()}', ticket_client_id = ${made.clientId}, ticket_invoice_id = 0, ticket_project_id = 0`);
    made.ticketId = Number(db.value(`SELECT ticket_id FROM tickets WHERE ticket_subject = ${db.q(subject)}`));
    db.run(`INSERT INTO mit_ticket_boards SET ticket_id = ${made.ticketId}, board_id = ${made.boardId}, board_set_by = 0, board_set_how = 'routed'`);

    const card = page.locator(`.itflow-dispatch-item[data-ticket="${made.ticketId}"]`);
    const look = async () => {
        await page.goto('agent/dispatch.php');
        await expect(page.locator('#itflowDispatch')).toHaveCount(1);
        await page.locator('[data-dispatch-filter="q"]').fill(subject);
        await page.locator('[data-dispatch-filter="q"]').dispatchEvent('input');
        await page.locator('[data-dispatch-tab="unassigned"]').click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);
    };
    let restore = dispatchState(1, 'mine');
    try {
        await look();
        await expect(card, '"My boards": the quiet board\'s ticket stays off the queue').toHaveCount(0);
    } finally {
        restore();
    }
    restore = dispatchState(1, String(made.boardId));
    try {
        await look();
        await expect(card, 'the quiet board picked itself: the ticket is there').toHaveCount(1, { timeout: 10000 });
    } finally {
        restore();
    }
    await expectClean(page, errors);
});

test.afterAll(() => {
    try {
        if (made.ticketId) {
            db.run(`DELETE FROM mit_ticket_boards WHERE ticket_id = ${made.ticketId}; DELETE FROM mit_board_moves WHERE ticket_id = ${made.ticketId};
                DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticketId}; DELETE FROM tickets WHERE ticket_id = ${made.ticketId}`);
        }
        if (made.clientId) {
            db.run(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
        }
        if (made.boardId) {
            db.run(`DELETE FROM mit_board_members WHERE board_id = ${made.boardId}; DELETE FROM mit_boards WHERE board_id = ${made.boardId}`);
        }
    } catch (e) { /* best effort */ }
});
