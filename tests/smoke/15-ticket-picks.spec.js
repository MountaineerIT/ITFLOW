// The ticket page's Board, SLA and Category change in place (functions/mit_ticket_picks.php) - drop-down pickers like
// Status and Priority, no window. Picks another category, SLA plan and board on a new ticket and checks each is saved and
// shown. The client and ticket ("Smoke test ...") are removed at the end, even when a step fails.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0 };

const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;

// Open a picker in the Details box and choose the first option that is not the current one; returns its label
async function pickOther(page, field) {
    const button = page.locator(`button[data-quick-pick="${field}"]`);
    await expect(button, `the ${field} picker (not a window)`).toHaveCount(1);
    await button.click();
    const option = page.locator('.itflow-select-option:not(.is-selected):not(.is-none)').first();
    await expect(option, `choices for ${field}`).toBeVisible();
    const label = (await option.locator('.itflow-select-label').textContent()).trim();
    await option.click();
    return label;
}

test('ticket picks: a client and a ticket', async ({ page }) => {
    await visit(page, 'agent/clients.php');
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket saved (${await flash(page)})`).toBeGreaterThan(0);
});

test('ticket picks: category, SLA and board change in place', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, ticketPage(), errors);
    const prop = (name) => page.locator(`.ticket-prop:has(dt:text-is("${name}"))`);

    // Category - redrawn where it is, no reload
    const category = await pickOther(page, 'mit_category');
    await expect(prop('Category'), 'the category shown').toContainText(category);
    await visit(page, ticketPage(), errors);
    await expect(prop('Category'), 'the category saved').toContainText(category);

    // SLA - only when the SLA line is there (SLAs in use)
    if (await page.locator('button[data-quick-pick="mit_sla"]').count()) {
        // An SLA change reloads the page (the timers start again) - wait for it before going on
        const reloaded = page.waitForNavigation();
        const sla = await pickOther(page, 'mit_sla');
        await reloaded;
        await expect(prop('SLA'), 'the SLA plan shown').toContainText(sla);
        await visit(page, ticketPage(), errors);
        await expect(prop('SLA'), 'the SLA plan saved').toContainText(sla);
    }

    // Board - only with boards set up
    if (await page.locator('button[data-quick-pick="mit_board"]').count()) {
        const board = await pickOther(page, 'mit_board');
        await expect(prop('Board'), 'the board shown').toContainText(board);
        await visit(page, ticketPage(), errors);
        await expect(prop('Board'), 'the board saved').toContainText(board);
        await expect(page.locator('body'), 'the move in the history').toContainText(`to ${board}`);
    }
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
