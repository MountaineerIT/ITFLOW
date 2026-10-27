// Service boards (functions/mit_boards.php): a client routing rule places a new ticket on its board, Escalate moves it
// with an internal note, and the Tickets board switcher and Dispatch's board picker follow. The client, its ticket and
// the rule are named "Smoke test ..." and removed again at the end, even when a step fails.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, getJson, expectClean, csrf } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0, boards: [], restore: null };

// The board form's current values (Admin > Settings > Boards > Edit), to put back afterwards
async function boardForm(page, boardId) {
    const modal = await openModal(page, `modals/mit_boards/board.php?board_id=${boardId}`);
    const values = await modal.evaluate((root) => {
        const picked = (name) => [...root.querySelectorAll(`select[name="${name}"] option:checked`)].map((o) => o.value);
        const field = (name) => root.querySelector(`[name="${name}"]`);
        return {
            'members[]': picked('members[]'), 'notify[]': picked('notify[]'), 'no_rotation[]': picked('no_rotation[]'),
            assign: field('assign') ? field('assign').value : null, assign_max: field('assign_max') ? field('assign_max').value : '',
            assign_hours: field('assign_hours') ? field('assign_hours').checked : true,
            agents: [...root.querySelectorAll('select[name="members[]"] option')].map((o) => o.value),
        };
    });
    return { modal, values };
}

async function restoreBoard(page) {
    if (!made.restore) {
        return;
    }
    await page.goto('admin/mit_boards.php');
    const { modal } = await boardForm(page, made.restore.id);
    const { agents, ...values } = made.restore.values;
    await fillForm(modal, values);
    await submit(modal, 'mit_board_save');
    made.restore = null;
}

test('boards: Admin > Settings > Boards and its routing rules', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_boards.php', errors);
    test.skip(await page.locator('text=need the database update').count() > 0, 'Boards not set up');
    await expect(page.locator('.badge', { hasText: 'Default' }), 'one default board').toHaveCount(1);
    // Two boards that are not the default, by their "Make the default" links
    for (const href of await page.locator('a[href*="mit_board_default="]').evaluateAll((links) => links.map((a) => a.href))) {
        made.boards.push(Number(new URL(href).searchParams.get('mit_board_default')));
    }
    expect(made.boards.length, 'at least two boards besides the default').toBeGreaterThanOrEqual(2);

    await visit(page, 'agent/clients.php');
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    expect(made.clientId).toBeGreaterThan(0);

    // The client's tickets go to the first of them
    await visit(page, 'admin/mit_boards.php?tab=routing', errors);
    const form = page.locator('form:has(input[name=rule_kind][value=client])');
    await fillForm(form, { rule_match: String(made.clientId), board_id: String(made.boards[0]) });
    await submit(form, 'mit_board_rule_add');
    await expect(page.locator('li.list-group-item', { hasText: made.clientName }), `the rule (${await flash(page)})`).toHaveCount(1);
    await expectClean(page, errors);
});

test('boards: a new ticket is routed, escalated, and shows on its board', async ({ page }) => {
    test.skip(!made.clientId, 'no client');
    const errors = watch(page);
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`, errors);
    let modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await expect(modal.locator('select[name=mit_board]'), 'the Board picker on New Ticket').toHaveCount(1);
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low', mit_board: '' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${await flash(page)})`).toBeGreaterThan(0);

    const boardLine = page.locator('.ticket-prop:has(dt:text-is("Board"))');
    await expect(boardLine.locator(`[data-mit-board="${made.boards[0]}"]`), 'placed on the client rule\'s board').toHaveCount(1);
    await expect(page.locator(`a.dropdown-item[data-modal-url*="board_move.php?escalate=1&ticket_id=${made.ticketId}"]`), 'Escalate in the ticket\'s menu').toHaveCount(1);

    // Escalate: a reason is required, it becomes an internal note
    modal = await openModal(page, `modals/mit_board/board_move.php?escalate=1&ticket_id=${made.ticketId}`);
    await expect(modal.locator('textarea[name=reason][required]')).toHaveCount(1);
    await fillForm(modal, { board_id: String(made.boards[1]), reason: 'Smoke test escalation reason' });
    await submit(modal, 'mit_board_move');
    await expect(boardLine.locator(`[data-mit-board="${made.boards[1]}"]`), `moved (${await flash(page)})`).toHaveCount(1);
    await expect(page.locator('body')).toContainText('Smoke test escalation reason');
    await expectClean(page, errors);

    // The Tickets board switcher: on the new board, not on the old one
    await visit(page, `agent/tickets.php?board=${made.boards[1]}&q=${encodeURIComponent('Smoke test ticket')}`, errors);
    await expect(page.locator(`a[href*="ticket_id=${made.ticketId}"]`).first(), 'listed on its board').toBeVisible();
    await expect(page.locator('form input[type=hidden][name=board]'), 'the search form keeps the board').toHaveValue(String(made.boards[1]));
    for (const view of ['list', 'kanban']) {
        await visit(page, `agent/tickets.php?view=${view}&q=${encodeURIComponent('Smoke test ticket')}&client_id=${made.clientId}`, errors);
        const row = page.locator(view === 'list' ? `tr:has(a[href*="ticket_id=${made.ticketId}"])` : `article[data-ticket-id="${made.ticketId}"]`).first();
        await expect(row.locator('.mit-board-dot'), `the board tag on the ${view}`).toHaveCount(1);
    }
    await visit(page, `agent/tickets.php?board=${made.boards[0]}&q=${encodeURIComponent('Smoke test ticket')}`, errors);
    await expect(page.locator(`a[href*="ticket_id=${made.ticketId}"]`), 'not on the board it left').toHaveCount(0);

    // Dispatch's queue follows the board picked (escalating unassigned it, so it waits under No agent)
    const queue = await page.goto('agent/mit_dispatch.php');
    if (queue.status() < 400 && !page.url().includes('dashboard')) {
        const on = await getJson(page, `/agent/mit_dispatch_ajax.php?queue=unassigned&board=${made.boards[1]}`);
        const off = await getJson(page, `/agent/mit_dispatch_ajax.php?queue=unassigned&board=${made.boards[0]}`);
        const ids = (json) => (json.tickets || json).map((t) => Number(t.id));
        expect(ids(on), 'in the Dispatch queue of its board').toContain(made.ticketId);
        expect(ids(off), 'not in the queue of the board it left').not.toContain(made.ticketId);
    }
});

test('boards: tickets made for several assets at once are routed too', async ({ page }) => {
    test.skip(!made.clientId, 'no client');
    const asset = db.insert(`INSERT INTO assets SET asset_type = 'Desktop', asset_name = 'Smoke 07 PC', asset_make = 'Smoke', asset_client_id = ${made.clientId}`);
    try {
        await visit(page, `agent/assets.php?client_id=${made.clientId}`);
        const token = await csrf(page);
        await db.pagePost(page, '/agent/post.php', { csrf_token: token, bulk_add_asset_ticket: '1', bulk_subject: smokeName('asset ticket'), bulk_details: 'x',
            bulk_priority: 'Low', bulk_billable: '0', 'asset_ids[]': [String(asset)] });
        const ticket = Number(db.val(`SELECT ticket_id FROM tickets WHERE ticket_asset_id = ${asset} ORDER BY ticket_id DESC LIMIT 1`) || 0);
        expect(ticket, 'the ticket was made').toBeGreaterThan(0);
        made.assetTicket = ticket;
        expect(Number(db.val(`SELECT board_id FROM mit_ticket_boards WHERE ticket_id = ${ticket}`) || 0), "placed on the client rule's board at once").toBe(made.boards[0]);
    } finally {
        db.exec(`DELETE FROM assets WHERE asset_id = ${asset}`);
    }
});

test('boards: round-robin hands a ticket moved in unassigned to a member', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, 'admin/mit_boards.php', errors);
    let { modal, values } = await boardForm(page, made.boards[0]);
    test.skip(values.assign === null, 'auto-assign needs database update M027');
    made.restore = { id: made.boards[0], values };
    // Everyone on it and in the turn, at any hour
    await fillForm(modal, { 'members[]': values.agents, 'no_rotation[]': [], assign: 'round_robin', assign_max: '', assign_hours: false });
    await submit(modal, 'mit_board_save');
    await expect(page.locator('.badge', { hasText: 'Round-robin' }), 'the board shows it hands tickets out').toHaveCount(1);

    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    modal = await openModal(page, `modals/mit_board/board_move.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { board_id: String(made.boards[0]) }); // still unassigned since the escalation
    await submit(modal, 'mit_board_move');
    expect(await flash(page), 'handed to the next member').toMatch(/handed to \S/);
    await restoreBoard(page);
    await expectClean(page, errors);
});

test('boards: dashboard board filter, and the Board Performance report', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, `agent/dashboard.php?board=${made.boards[1]}`, errors);
    await expect(page.locator('#mitDashBoard'), 'the dashboard board picker').toHaveValue(String(made.boards[1]));
    for (const href of await page.locator('.itflow-dash-rail a[href^="/agent/tickets.php"]').evaluateAll((links) => links.map((a) => a.getAttribute('href')))) {
        expect(href, 'Needs attention opens Tickets on the same board').toContain(`board=${made.boards[1]}`);
    }
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('#mitDashBoard'), 'remembered').toHaveValue(String(made.boards[1]));
    await visit(page, 'agent/dashboard.php?board=all', errors);

    await visit(page, `agent/reports/mit_boards.php?period=last_30&client_id=${made.clientId}`, errors);
    test.skip(await page.locator('text=need the database update').count() > 0, 'report needs M027');
    const csv = await page.evaluate(async (client) => (await fetch(`/agent/reports/mit_boards.php?period=last_30&client_id=${client}&export=csv&table=flows`)).text(), made.clientId);
    const names = await page.locator('select[name=board] option').evaluateAll((o) => Object.fromEntries(o.map((x) => [x.value, x.textContent])));
    const lines = csv.replace(/"/g, '').split(/\r?\n/);
    expect(lines, 'the escalation in the flows export').toContain(`${names[made.boards[0]]},${names[made.boards[1]]},1,0`);
    expect(lines, 'and the move back').toContain(`${names[made.boards[1]]},${names[made.boards[0]]},0,1`);
    await visit(page, `agent/reports/mit_boards.php?period=last_30&board=${made.boards[1]}`, errors);
    await expect(page.locator('h3', { hasText: 'Agents on' }), 'one board: its agents').toHaveCount(1);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    await restoreBoard(page).catch(() => {});
    if (made.assetTicket) {
        await action(page, `delete_ticket=${made.assetTicket}`, `agent/ticket.php?ticket_id=${made.assetTicket}`).catch(() => {});
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    }
    // This run's rule (and any a failed earlier run left), then the client
    await page.goto('admin/mit_boards.php?tab=routing');
    for (const href of await page.locator('li.list-group-item', { hasText: /Smoke test client \d{14}|A removed client/ })
        .locator('a[href*="mit_board_rule_delete="]').evaluateAll((links) => links.map((a) => a.href))) {
        await page.goto(href);
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    await context.close();
});
