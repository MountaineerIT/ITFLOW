// Service boards, the numbers and the admin side (functions/mit_boards.php): the Tickets board switcher's counts and the
// dashboard's "Your boards" card agree with the open service tickets on each board in the database; archiving a board
// (Admin > Settings > Boards) moves its tickets to the default board (logged as a move), drops its routing rules, and a ticket
// routed afterwards by the rule's client lands on the default. A board, client and tickets of its own, removed at the end.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, csrf, expectClean } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const made = { boardId: 0, clientId: 0, tickets: [] };

function openPerBoard() {
    const def = db.value('SELECT board_id FROM mit_boards WHERE board_is_default = 1 AND board_archived_at IS NULL') || '0';
    const apart = db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'project_tickets_apart'") !== '0';
    return Object.fromEntries(db.rows(`SELECT COALESCE(b.board_id, ${def}) AS board, COUNT(*) AS n, SUM(t.ticket_assigned_to = 0) AS unassigned FROM tickets t
        LEFT JOIN mit_ticket_boards b ON b.ticket_id = t.ticket_id WHERE t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL ${apart ? 'AND t.ticket_project_id = 0' : ''}
        GROUP BY board`).map((r) => [r.board, { n: Number(r.n), unassigned: Number(r.unassigned) }]));
}

test('boards: switcher counts and Your boards match the open tickets on each board', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/tickets.php?board=all', errors);
    const counts = await page.locator('.mit-board-switcher .dropdown-item').evaluateAll((items) => Object.fromEntries(items
        .map((a) => [new URL(a.href).searchParams.get('board'), Number((a.querySelector('.badge') || {}).textContent)])));
    const want = openPerBoard();
    for (const id of db.column('SELECT board_id FROM mit_boards WHERE board_archived_at IS NULL')) {
        expect(counts[id], `switcher count for board ${id}`).toBe(want[id] ? want[id].n : 0);
    }
    expect(counts.all, 'All boards').toBe(Object.values(want).reduce((s, x) => s + x.n, 0));

    await visit(page, 'agent/dashboard.php?board=all', errors);
    const mine = db.column('SELECT m.board_id FROM mit_board_members m JOIN mit_boards b USING (board_id) WHERE m.user_id = 1 AND b.board_archived_at IS NULL');
    test.skip(!mine.length, 'the admin is on no board');
    for (const id of mine) {
        const item = page.locator(`[data-mit-dash-boards] [data-mit-board="${id}"]`);
        await expect(item.locator('.itflow-dash-rail-count'), `Your boards: board ${id}`).toHaveText(String(want[id] ? want[id].n : 0));
        if (want[id] && want[id].unassigned) {
            await expect(item.locator('.itflow-dash-rail-detail')).toContainText(`${want[id].unassigned} unassigned`);
        }
    }
    await expectClean(page, errors);
});

test('boards: archiving a board moves its tickets to the default and drops its rules', async ({ page }) => {
    const errors = watch(page);
    const name = smokeName('t1 board');
    await visit(page, 'admin/mit_boards.php', errors);
    let modal = await openModal(page, 'modals/mit_boards/board.php');
    await fillForm(modal, { name, description: 'Archive test' });
    await submit(modal, 'mit_board_save');
    made.boardId = Number(db.value(`SELECT board_id FROM mit_boards WHERE board_name = ${db.q(name)}`));
    expect(made.boardId, await flash(page)).toBeGreaterThan(0);

    const client = smokeName('t1 board client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(client)}, client_currency_code = 'USD', client_net_terms = 30`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(client)}`));
    // A client rule for the new board, then a ticket opened with Automatic routing lands on it
    await visit(page, 'admin/mit_boards.php?tab=routing', errors);
    const form = page.locator('form:has(input[name=rule_kind][value=client])');
    await fillForm(form, { rule_match: String(made.clientId), board_id: String(made.boardId) });
    await submit(form, 'mit_board_rule_add');
    const newTicket = async () => {
        await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
        const m = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
        await fillForm(m, { subject: smokeName('t1 board ticket'), details: 'x', priority: 'Low', mit_board: '' });
        await submit(m, 'add_ticket');
        const id = Number(new URL(page.url()).searchParams.get('ticket_id'));
        made.tickets.push(id);
        return id;
    };
    const first = await newTicket();
    expect(db.value(`SELECT board_id FROM mit_ticket_boards WHERE ticket_id = ${first}`), 'routed by the client rule').toBe(String(made.boardId));

    await visit(page, 'admin/mit_boards.php', errors);
    const token = await csrf(page);
    await page.goto(`admin/post.php?mit_board_archive=${made.boardId}&csrf_token=${encodeURIComponent(token)}`);
    expect(await flash(page)).toContain('1 ticket(s) moved');
    const def = db.value('SELECT board_id FROM mit_boards WHERE board_is_default = 1');
    expect(db.value(`SELECT board_id FROM mit_ticket_boards WHERE ticket_id = ${first}`), 'its ticket on the default board').toBe(def);
    expect(db.value(`SELECT CONCAT(from_board_id, '>', to_board_id, ':', move_how) FROM mit_board_moves WHERE ticket_id = ${first} ORDER BY move_id DESC LIMIT 1`)).toBe(`${made.boardId}>${def}:archived`);
    expect(db.value(`SELECT COUNT(*) FROM mit_board_rules WHERE board_id = ${made.boardId}`), 'its rules gone').toBe('0');
    expect(db.value(`SELECT board_archived_at IS NOT NULL FROM mit_boards WHERE board_id = ${made.boardId}`)).toBe('1');
    // The New Ticket picker and the switcher no longer offer it; a new ticket of that client goes to the default
    await visit(page, 'agent/tickets.php', errors);
    expect(await page.locator(`.mit-board-switcher a[href*="board=${made.boardId}"]`).count(), 'not in the switcher').toBe(0);
    const second = await newTicket();
    expect(db.value(`SELECT board_id FROM mit_ticket_boards WHERE ticket_id = ${second}`), 'routed to the default now').toBe(def);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL });
    const page = await context.newPage();
    if (made.boardId) {
        db.run(`DELETE FROM mit_board_rules WHERE board_id = ${made.boardId}`);
        db.run(`DELETE FROM mit_board_members WHERE board_id = ${made.boardId}`);
        db.run(`DELETE FROM mit_board_moves WHERE from_board_id = ${made.boardId} OR to_board_id = ${made.boardId}`);
        db.run(`UPDATE mit_ticket_boards SET board_id = (SELECT board_id FROM mit_boards WHERE board_is_default = 1) WHERE board_id = ${made.boardId}`);
        db.run(`DELETE FROM mit_boards WHERE board_id = ${made.boardId}`);
    }
    if (made.clientId) {
        db.run(`DELETE FROM email_queue WHERE email_subject LIKE '%Smoke test t1 board ticket%'`);
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
