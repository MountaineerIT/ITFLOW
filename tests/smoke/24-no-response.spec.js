// "Resolve - no response" is ITFlow's own since 26.12 (modals/ticket/ticket_resolve_no_response.php, tickets.ticket_no_response_at -
// built from ours). What stays ours: Customer Thermometer (functions/mit_ct.php) sends no survey for a ticket resolved that way, while a
// ticket resolved the normal way beside it still gets one (the test install's ct-mock stand-in). Surveys are switched off again after.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, action, expectClean } = require('./helpers');

const MOCK = 'http://127.0.0.1/ct-mock/index.php';
const made = { clientName: smokeName('No reply client'), clientId: 0, tickets: [], refs: [], boardId: '' };
const ticketPage = (id) => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${id}`;
const day = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);
const mock = (page, path) => page.request.get(MOCK + path).then((r) => (r.ok() ? r.json() : null)).catch(() => null);

async function surveys(page, { enabled, boards }) {
    await visit(page, 'admin/mit_customer_thermometer.php');
    const form = page.locator('form:has(button[name=mit_ct_save])');
    await form.locator('select[name=ct_thermometer_id]').selectOption('101');
    await form.locator('select[name=ct_list_id]').selectOption('7');
    await form.locator('input[name=ct_enabled]').setChecked(enabled);
    await form.locator('input[name="ct_boards[]"]').evaluateAll((boxes, ids) => boxes.forEach((b) => { b.checked = ids.includes(b.value); }), boards);
    await form.locator('input[name=ct_start]').fill(day(-1));
    await submit(form, 'mit_ct_save');
}

test('no response: ITFlow resolves it, and Customer Thermometer sends no survey for it', async ({ page }) => {
    test.setTimeout(120000);
    const errors = watch(page);
    test.skip(!(await mock(page, '/_reset')), 'the Customer Thermometer stand-in is not installed on this test install');

    await visit(page, 'agent/clients.php', errors);
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Pat Noreply', contact_email: 'smoke-noreply@example.invalid' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    for (const what of ['no reply', 'normal']) {
        modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
        await fillForm(modal, { subject: smokeName(what), details: 'Opened by the smoke tests.', priority: 'Low' });
        await submit(modal, 'add_ticket');
        made.tickets.push(Number(new URL(page.url()).searchParams.get('ticket_id')));
        made.refs.push((await page.locator('title').textContent()).match(/[A-Z]+-\d+/)?.[0] || '');
        made.boardId = made.boardId || await page.locator('button[data-quick-pick="mit_board"]').getAttribute('data-quick-current');
    }
    expect(made.tickets.every((id) => id > 0), 'tickets saved').toBe(true);

    // ITFlow's Resolve - no response on the first
    await visit(page, ticketPage(made.tickets[0]), errors);
    modal = await openModal(page, `modals/ticket/ticket_resolve_no_response.php?ticket_id=${made.tickets[0]}`, 'lg');
    await submit(modal, 'resolve_ticket_no_response');
    // The second resolved the normal way
    await action(page, `resolve_ticket=${made.tickets[1]}`, ticketPage(made.tickets[1]));

    // Surveys run for the board: only the normal one gets one
    await surveys(page, { enabled: true, boards: [made.boardId] });
    await visit(page, 'admin/mit_customer_thermometer.php');
    await submit(page.locator('form:has(button[name=mit_ct_run])'), 'mit_ct_run');
    const sent = ((await mock(page, '/_state')) || { sent: [] }).sent.map((s) => s.custom2);
    expect(sent, 'the normally resolved ticket is surveyed').toContain(made.refs[1]);
    expect(sent, 'the no-response ticket is not').not.toContain(made.refs[0]);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.boardId) {
        await surveys(page, { enabled: false, boards: [made.boardId] }).catch(() => {});
    }
    for (const id of made.tickets) {
        await action(page, `delete_ticket=${id}`, ticketPage(id)).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
