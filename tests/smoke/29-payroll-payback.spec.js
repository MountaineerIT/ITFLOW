// Mileage and purchases paid with payroll (functions/mit_reimbursements.php, mitReimbItems):
//  - one mileage rate: saved in Admin > Timesheets, it is also ITFlow's "Paid back a mile" (Log a trip offers "In their own
//    vehicle" only while that is set);
//  - a trip (ITFlow's Log a trip, own vehicle) and a purchase (the ticket's ⋮ menu) share the ticket's "Trips & purchases" box and
//    the timesheet's "Mileage and purchases" box as "Goes to payroll"; approving the timesheet pays them through payroll; ITFlow
//    then refuses to change the paid trip; reopening puts them back.
// Uses the admin's own timesheet for this period (approved, then reopened) - skipped when it is approved already or on California
// rules (those need the person's signature first).

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientId: 0, ticketId: 0, tripId: 0, purchase: smokeName('payroll cable') };
const ticketUrl = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const tsBox = (page) => page.locator('.card').filter({ has: page.locator('.card-title i.fa-receipt') }).first();

test('one mileage rate: Admin > Timesheets sets ITFlow\'s too, and the labels use ITFlow\'s names', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_timesheets.php', errors);
    const rate = page.locator('[data-mit-mileage-rate]');
    test.skip(await rate.count() === 0, 'Reimbursements not here (Timesheets off?)');
    const value = (await rate.inputValue()) || '0.7';
    await rate.fill(value);
    const account = page.locator('select[name="reimb_account"]');
    if (await account.count() && (await account.inputValue()) === '0') {
        await account.selectOption({ index: 1 });
    }
    await submit(page.locator('form', { has: rate }), 'mit_ts_settings_save');
    expect(await flash(page)).toMatch(/saved/i);
    await visit(page, 'admin/mit_timesheets.php', errors);
    await expect(page.locator('[data-mit-rate-missing]'), 'no warning once the rate is set').toHaveCount(0);
    const section = page.locator('[data-mit-reimb-itflow]');
    if (await section.count()) {
        await expect(page.locator('body')).not.toContainText('Expenses > Reimburse');
        await expect(page.locator('select[name="reimb_payback"]')).toContainText('Expenses > To reimburse');
    }
    // ITFlow's own rate is the same
    await visit(page, 'admin/settings_invoice.php', errors);
    const itflow = page.locator('[name="config_mileage_rate"]');
    if (await itflow.count()) {
        expect(Number(await itflow.inputValue()), 'ITFlow\'s Paid back a mile').toBeCloseTo(Number(value), 4);
    }
});

test('a trip and a purchase go to payroll: one box, the timesheet, approve pays them, ITFlow keeps its hands off, reopen', async ({ page }) => {
    test.setTimeout(120000);
    const errors = watch(page);
    // The admin's own timesheet must be open and not on California rules
    await visit(page, 'agent/mit_timesheet.php', errors);
    test.skip(await page.locator('a[href*="mit_ts_reopen"]').count() > 0, 'this period is approved already');
    test.skip(await page.locator('[data-mit-ts-submit] .fa-file-signature').count() > 0, 'California rules - approving needs a signature');
    test.skip(await page.locator('[data-modal-url*="decision=approve"]').count() === 0, 'approval is off');

    // A ticket for the first client
    await visit(page, 'agent/clients.php', errors);
    const links = await page.locator('a[href*="client_overview.php?client_id="]').evaluateAll((as) => as
        .filter((a) => a.textContent.trim() && !/^Smoke test/.test(a.textContent.trim())).map((a) => a.href));
    test.skip(!links.length, 'no clients');
    made.clientId = Number(new URL(links[0]).searchParams.get('client_id'));
    let modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await page.waitForTimeout(500);
    await fillForm(modal, { subject: smokeName('payroll ticket'), details: 'Opened by the smoke tests (29-payroll-payback).', priority: 'Low', contact_id: '0' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId).toBeGreaterThan(0);

    // ITFlow's Log a trip: "In their own vehicle" is offered (the rate is set) and on
    modal = await openModal(page, `modals/trip/trip_add.php?ticket_id=${made.ticketId}`, 'lg');
    await expect(modal.locator('[name="own_vehicle"]'), 'own vehicle offered').toBeChecked();
    await fillForm(modal, { source: 'Smoke office', destination: 'Smoke payroll client', miles: '15' });
    await submit(modal, 'add_trip');
    // A purchase from the ⋮ menu
    await visit(page, ticketUrl(), errors);
    modal = await openModal(page, await page.locator('[data-mit-reimb-menu]').getAttribute('data-modal-url'));
    await fillForm(modal, { amount: '12.34', description: made.purchase, vendor: 'Smoke store', ...(await modal.locator('[name="add_part"]').count() ? { add_part: false } : {}) });
    await submit(modal, 'mit_reimb_save');
    await page.waitForTimeout(600);

    // One box on the ticket, counting both lines
    const box = page.locator('.itflow-ticket-trips');
    await expect(box.locator('.card-title')).toContainText('Trips & purchases');
    await expect(box.locator('.card-title .badge')).toHaveText('2');
    await expect(box.locator('[data-mit-reimb-in-trips]')).toContainText(made.purchase);
    await expect(box.locator('[data-mit-reimb-in-trips] [data-mit-reimb-state]')).toHaveText('Goes to payroll');
    made.tripId = Number(/id=(\d+)/.exec(await box.locator('a[data-modal-url*="trip.php?id="]').first().getAttribute('data-modal-url'))[1]);

    // The timesheet lists both, waiting for payroll
    await visit(page, 'agent/mit_timesheet.php', errors);
    const line = (text) => tsBox(page).locator('.list-group-item', { hasText: text });
    await expect(tsBox(page).locator('.card-title')).toHaveText('Mileage and purchases');
    await expect(line(made.purchase).locator('[data-mit-reimb-state]')).toHaveText('Goes to payroll');
    await expect(line('Smoke payroll client').locator('[data-mit-reimb-state]')).toHaveText('Goes to payroll');

    // Approve: paid through payroll
    await page.locator('[data-modal-url*="decision=approve"]').first().click();
    modal = page.locator('.modal.show').last();
    await modal.locator('form').waitFor();
    await submit(modal, 'mit_ts_decide');
    expect(await flash(page), 'approved and paid').toMatch(/paid through payroll/i);
    await visit(page, 'agent/mit_timesheet.php', errors);
    await expect(line(made.purchase).locator('[data-mit-reimb-state]')).toContainText('Paid through payroll');
    await expect(line('Smoke payroll client').locator('[data-mit-reimb-state]')).toContainText('Paid through payroll');

    // ITFlow won't change the trip payroll paid
    modal = await openModal(page, `modals/trip/trip_edit.php?id=${made.tripId}`, 'lg');
    await submit(modal, 'edit_trip');
    expect(await flash(page), 'ITFlow refuses').toMatch(/paid through payroll/i);

    // Reopen: back to waiting for payroll
    await visit(page, 'agent/mit_timesheet.php', errors);
    // The page's address as the Referer, so the handler sends us back here (none -> index.php, whose refresh raced the next visit)
    await page.goto(new URL(await page.locator('a[href*="mit_ts_reopen"]').getAttribute('href'), page.url()).href, { referer: page.url() });
    await visit(page, 'agent/mit_timesheet.php', errors);
    await expect(line(made.purchase).locator('[data-mit-reimb-state]')).toHaveText('Goes to payroll');
    await expect(line('Smoke payroll client').locator('[data-mit-reimb-state]')).toHaveText('Goes to payroll');
});

test.afterAll(async ({ browser }) => {
    const context = await browser.newContext({ storageState: require('path').join(__dirname, '.auth', 'admin.json') });
    const page = await context.newPage();
    if (made.ticketId) {
        await page.goto(ticketUrl());
        for (const url of await page.locator('[data-modal-url*="mit_reimburse/reimburse.php?id="]').evaluateAll((els) => els.map((e) => e.dataset.modalUrl))) {
            await action(page, `mit_reimb_delete=${new URL(url, page.url()).searchParams.get('id')}`, ticketUrl());
        }
        if (made.tripId) {
            await action(page, `delete_trip=${made.tripId}`, ticketUrl());
        }
        await action(page, `delete_ticket=${made.ticketId}`, ticketUrl());
    }
    await context.close();
});
