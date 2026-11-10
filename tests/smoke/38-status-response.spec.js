// Statuses that meet the SLA response target (functions/mit_status_response.php): Admin > Ticket Statuses offers "SLA response
// target" on a status of our own; the "Ticket assigned" message is edited in the Messages to clients card on Admin > Settings > Ticket
// (beside Resolve - no response); a ticket moved into the status with ITFlow's own status picker emails the contact the message, keeps
// it as a public note and records the first response. The status, client and ticket are removed after, the message put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean } = require('./helpers');

const made = { status: smokeName('Assigned'), statusId: 0, clientName: smokeName('Status reply client'), clientId: 0, ticketId: 0, template: null };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;

test('a status that meets the response target emails the client and counts', async ({ page }) => {
    test.setTimeout(120000);
    const errors = watch(page);

    // The status, set to email the client
    await visit(page, 'admin/ticket_statuses.php', errors);
    let modal = await openModal(page, 'modals/ticket_status/ticket_status_add.php');
    await expect(modal.locator('[data-mit-status-response] select[name=mit_status_response]'), 'the field on a new status').toHaveCount(1);
    await fillForm(modal, { name: made.status, mit_status_response: '2' });
    await submit(modal, 'add_ticket_status');
    expect(await flash(page)).toContain('added');
    const edit = await page.locator('a.ajax-modal', { hasText: made.status }).first().getAttribute('data-modal-url');
    made.statusId = Number(new URLSearchParams(edit.split('?')[1]).get('id'));
    expect(made.statusId, 'status saved').toBeGreaterThan(0);
    modal = await openModal(page, edit);
    await expect(modal.locator('select[name=mit_status_response]'), 'kept').toHaveValue('2');
    await page.keyboard.press('Escape');
    // Not on New
    await openModal(page, 'modals/ticket_status/ticket_status_edit.php?id=1');
    await expect(page.locator('.modal.show [data-mit-status-response]'), 'New cannot carry it').toHaveCount(0);
    await page.keyboard.press('Escape');

    // The message, beside Resolve - no response
    await visit(page, 'admin/settings_ticket.php', errors);
    const card = page.locator('[data-mit-no-response-card]');
    await expect(card).toContainText('Messages to clients');
    await expect(card, 'names the statuses that send it').toContainText(made.status);
    const box = card.locator('[data-mit-status-response-template]');
    made.template = await box.inputValue();
    await box.fill('Hi [First Name],\n\n[Ticket] is with [Tech] - smoke status response.\n\nThanks,\n[Company]');
    await submit(card.locator('form'), 'mit_edit_no_response');
    expect(await flash(page)).toContain('Message saved');

    // A client with an email address and a ticket, moved into the status with ITFlow's picker
    await visit(page, 'agent/clients.php', errors);
    modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Pat Assigned', contact_email: 'smoke-assigned@example.invalid' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('needs a tech'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, 'ticket saved').toBeGreaterThan(0);
    const ref = (await page.locator('title').textContent()).match(/[A-Z]+-\d+/)?.[0] || '';

    await page.locator('button[data-quick-pick="status"]').click();
    await page.locator('.itflow-select-option', { hasText: made.status }).first().click();
    await page.waitForTimeout(800);
    await visit(page, ticketPage(), errors);
    await expect(page.locator('body'), 'the message as a public note').toContainText('smoke status response.');
    await expect(page.locator('body'), 'the ticket history').toContainText('counts as the first response');

    await visit(page, 'admin/mail_queue.php', errors);
    await expect(page.locator('tr', { hasText: 'smoke-assigned@example.invalid' }).filter({ hasText: `[${ref}]` }).first(), 'the email to the contact').toContainText('Ticket assigned');
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.template !== null) {
        await visit(page, 'admin/settings_ticket.php').catch(() => {});
        const card = page.locator('[data-mit-no-response-card]');
        await card.locator('[data-mit-status-response-template]').fill(made.template).catch(() => {});
        await submit(card.locator('form'), 'mit_edit_no_response').catch(() => {});
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    if (made.statusId) {
        await visit(page, 'admin/ticket_statuses.php').catch(() => {});
        const modal = await openModal(page, `modals/ticket_status/ticket_status_remove.php?id=${made.statusId}&action=delete`).catch(() => null);
        if (modal) {
            await submit(modal, 'delete_ticket_status').catch(() => {});
        }
    }
    await context.close();
});
