// "Resolve - no response" and the "Ticket resolved" email's own wording (functions/mit_no_response.php): the arrow beside Resolve opens the message filled in
// for the ticket; sending posts it as a public note, emails the contact, resolves the ticket, and no Customer Thermometer survey
// goes out for it - while a ticket resolved the normal way next to it still gets one (the test install's ct-mock stand-in), and its
// "Ticket resolved" email uses the wording set on Admin > Settings > Ticket. Both wordings are changed for the run and put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, getJson } = require('./helpers');

const MOCK = 'http://127.0.0.1/ct-mock/index.php';
const made = { clientName: smokeName('No reply client'), clientId: 0, tickets: [], refs: [], boardId: '', template: null, resolvedTemplate: '' };
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

test('no response: resolves with the message, emails it, and sends no survey', async ({ page }) => {
    test.setTimeout(120000);
    const errors = watch(page);
    test.skip(!(await mock(page, '/_reset')), 'the Customer Thermometer stand-in is not installed on this test install');

    // The standard wording, with placeholders
    await visit(page, 'admin/settings_ticket.php', errors);
    const card = page.locator('[data-mit-no-response-card]');
    await expect(card, 'the card on Admin > Settings > Ticket').toHaveCount(1);
    made.template = await card.locator('[data-mit-no-response-template]').inputValue();
    made.resolvedTemplate = await card.locator('[data-mit-resolved-template]').inputValue();
    expect(made.resolvedTemplate, 'the resolved email starts as ITFlow\'s wording').toContain('has been marked as solved and is pending closure');
    await card.locator('[data-mit-no-response-template]').fill('Hi [First Name], we tried several times about [Ticket] ([Subject]). Smoke no-response.');
    await card.locator('[data-mit-resolved-template]').fill('Hi [First Name], [Ticket] is [Status].\n[Note]\nSmoke resolved wording. Re-open: [Ticket Link]');
    await submit(card.locator('form'), 'mit_edit_no_response');
    expect(await flash(page)).toContain('Messages saved');

    // A client whose contact has an email address, and two tickets
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

    // No response on the first: the window has the message filled in for the ticket, and can be changed
    await visit(page, ticketPage(made.tickets[0]), errors);
    await page.click('.mit-no-response > .dropdown-toggle');
    await page.click('[data-mit-no-response]');
    const box = page.locator('.modal.show textarea[name=message]');
    await expect(box).toHaveValue(new RegExp(`^Hi Pat, we tried several times about ${made.refs[0]} \\(`));
    await box.fill((await box.inputValue()) + ' Edited for this ticket.');
    await submit(page.locator('.modal.show form'), 'mit_no_response_resolve');
    expect(await flash(page)).toContain('resolved - no response, message emailed');
    await visit(page, ticketPage(made.tickets[0]), errors);
    await expect(page.locator('body')).toContainText('Smoke no-response. Edited for this ticket.');
    await expect(page.locator('body'), 'the ticket history').toContainText('resolved the ticket - no response from the client');
    await expect(page.locator('[data-mit-no-response]'), 'no button on a resolved ticket').toHaveCount(0);

    // Its email is queued to the contact
    await visit(page, 'admin/mail_queue.php', errors);
    await expect(page.locator('table', { hasText: 'smoke-noreply@example.invalid' }).first()).toContainText(`Ticket resolved - [${made.refs[0]}]`);

    // The second resolved the normal way: the "Ticket resolved" email in our own wording
    await action(page, `resolve_ticket=${made.tickets[1]}`, ticketPage(made.tickets[1]));
    await visit(page, 'admin/mail_queue.php', errors);
    const row = page.locator('tr', { hasText: `Ticket resolved - [${made.refs[1]}]` }).filter({ hasText: 'smoke-noreply@example.invalid' }).first();
    const view = await row.locator('[data-modal-url*="mail_queue_message_view"]').first().getAttribute('data-modal-url');
    // The viewer window's content, as text
    const json = await getJson(page, view);
    // ITFlow 26.11 shows the email in a sandboxed frame - its HTML is escaped into srcdoc
    const frame = /srcdoc="([^"]*)"/.exec(String(json.content || ''));
    const decode = (html) => html.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&');
    const message = decode(decode(frame ? frame[1] : String(json.content || ''))).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
    expect(message, 'the resolved email in our wording').toContain(`Hi Pat, ${made.refs[1]} is Resolved.`);
    expect(message).toContain('Smoke resolved wording. Re-open: the button below');
    expect(message, 'an empty note leaves nothing behind').not.toContain('[Note]');

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
    if (made.template !== null) {
        await visit(page, 'admin/settings_ticket.php').catch(() => {});
        const card = page.locator('[data-mit-no-response-card]');
        await card.locator('[data-mit-no-response-template]').fill(made.template).catch(() => {});
        await card.locator('[data-mit-resolved-template]').fill(made.resolvedTemplate).catch(() => {});
        await submit(card.locator('form'), 'mit_edit_no_response').catch(() => {});
    }
    for (const id of made.tickets) {
        await action(page, `delete_ticket=${id}`, ticketPage(id)).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
