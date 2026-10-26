// Our features end to end through their real forms: procurement (item to order ->
// PO -> approve -> order -> receive), a ticket's parts, mileage and a purchase (one Trips & purchases box) and helper agents,
// quote pricing from our cost (and that the customer never sees the cost), and a
// recurring ticket on "the third Wednesday", and a ticket put in a project and taken out. Works on the first client in the list,
// on a "Smoke test" ticket / quote / recurring ticket it makes and deletes again.
// The received PO cannot be deleted - its line is named "Smoke test ...".
// Nothing is emailed to a customer: no contact on anything we make.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, fetchText } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const techState = path.join(__dirname, '.auth', 'tech.json');
const made = { clientId: 0, ticketId: 0, ticketName: smokeName('flows ticket'), quoteId: 0, recurringName: smokeName('recurring'), raisedTicketId: 0, recurringId: 0, poId: 0, projectId: 0 };
const ticketUrl = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;

// A box on the ticket page, by its title icon (the layout script may move it)
const box = (page, icon) => page.locator('.card').filter({ has: page.locator(`.card-title i.${icon}`) });

const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
// The third Wednesday of the month `ahead` months from now
function thirdWednesday(ahead) {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth() + ahead, 1);
    return new Date(first.getFullYear(), first.getMonth(), 1 + ((3 - first.getDay() + 7) % 7) + 14);
}

test('open a ticket to work on', async ({ page }) => {
    await visit(page, 'agent/clients.php');
    const links = await page.locator('a[href*="client_overview.php?client_id="]').evaluateAll((as) => as
        .filter((a) => a.textContent.trim() && !/^Smoke test/.test(a.textContent.trim())).map((a) => a.href));
    test.skip(!links.length, 'no clients');
    made.clientId = Number(new URL(links[0]).searchParams.get('client_id'));

    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await page.waitForTimeout(500); // the contact list loads
    await fillForm(modal, { subject: made.ticketName, details: 'Opened by the smoke tests (06-flows).', priority: 'Low', contact_id: '0' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${page.url()} - ${await flash(page)})`).toBeGreaterThan(0);
});

test('procurement: item to order, PO, approve, order, receive', async ({ page }) => {
    test.skip(!made.clientId, 'no client');
    const errors = watch(page);
    await visit(page, 'agent/mit_procurement.php', errors);
    test.skip(!/mit_procurement\.php/.test(page.url()), 'Procurement is switched off');
    const name = smokeName('PO item');

    // An item to order for the client, with no cost yet
    let modal = await openModal(page, 'modals/mit_procurement/item.php');
    await fillForm(modal, { name, quantity: '2', client_id: String(made.clientId) });
    await submit(modal, 'mit_proc_save_item');
    expect(await flash(page)).toContain('added to the list to order');
    const row = page.locator('#mitProcForm tr', { has: page.locator(`input[aria-label="Select ${name}"]`) });
    await expect(row, 'the item on To order').toHaveCount(1);
    // A second item on the same PO - Receiving's menu count is of items, not POs
    const name2 = smokeName('PO item two');
    modal = await openModal(page, 'modals/mit_procurement/item.php');
    await fillForm(modal, { name: name2, quantity: '1', client_id: String(made.clientId) });
    await submit(modal, 'mit_proc_save_item');
    const row2 = page.locator('#mitProcForm tr', { has: page.locator(`input[aria-label="Select ${name2}"]`) });

    // Tick both, pick a vendor, Create PO
    await row.locator('input[name="item_ids[]"]').check();
    await row2.locator('input[name="item_ids[]"]').check();
    const vendor = await page.locator('#mitPoVendor option:not([value="0"])').first().getAttribute('value');
    test.skip(!vendor, 'no vendors to order from');
    await page.selectOption('#mitPoVendor', vendor);
    await submit(page.locator('#mitProcForm'), 'mit_po_create');
    expect(page.url(), await flash(page)).toMatch(/mit_purchase_order\.php\?po_id=\d+/);
    const poId = Number(new URL(page.url()).searchParams.get('po_id'));
    made.poId = poId;
    const poUrl = `agent/mit_purchase_order.php?po_id=${poId}`;

    // Give the line a cost: 2 x 300.00, over the default approval amount
    const lines = page.locator('form:has(button[name=mit_po_save_lines])');
    await expect(lines).toContainText(name);
    await lines.locator('input[name$="[cost]"]').first().fill('300');
    await submit(lines, 'mit_po_save_lines');
    expect(await flash(page)).toContain('Quantities and costs saved');
    await expect(page.locator('input[name$="[cost]"]').first()).toHaveValue('300.00');
    await expect(page.locator('body')).toContainText('600.00');

    // Submit; approve when it waits for approval (the admin may approve, logged as self-approved)
    await Promise.all([page.waitForNavigation(), page.click('a[href*="mit_po_submit="]')]);
    await expectClean(page);
    expect(await flash(page)).toMatch(/Sent for approval|Submitted/);
    if (await page.locator('[data-modal-url*="action=approve"]').count()) {
        modal = await openModal(page, `modals/mit_procurement/po_action.php?po_id=${poId}&action=approve`);
        await fillForm(modal, { note: 'Smoke test approval' });
        await submit(modal, 'mit_po_decide');
        expect(await flash(page)).toContain('Approved');
    }
    await expect(page.locator('h2 + .badge')).toHaveText('Approved');

    modal = await openModal(page, `modals/mit_procurement/po_action.php?po_id=${poId}&action=ordered`);
    await fillForm(modal, { vendor_order_number: 'SMOKE-1' });
    await submit(modal, 'mit_po_ordered');
    expect(await flash(page)).toContain('Marked ordered');
    await expect(page.locator('h2 + .badge')).toHaveText('Ordered');

    // The side menu counts every item still to arrive (it counted POs before)
    await visit(page, 'agent/mit_receiving.php', errors);
    const waiting = await page.locator('#mitReceiveForm [data-mit-got]').count();
    await expect(page.locator('.app-sidebar a[href="/agent/mit_receiving.php"] .badge'), 'Receiving counts items').toHaveText(String(waiting));

    // Receive both on Receiving, making no assets and adding nothing to stock
    await visit(page, `agent/mit_receiving.php?po_id=${poId}`, errors);
    const line = page.locator('#mitReceiveForm tr', { hasText: name }).filter({ hasNotText: name2 });
    await expect(line).toHaveCount(1);
    await line.locator('input[name$="[qty]"]').fill('2');
    await page.locator('#mitReceiveForm tr', { hasText: name2 }).locator('input[name$="[qty]"]').fill('1');
    const form = page.locator('#mitReceiveForm');
    await form.locator('#mitOptAssets').uncheck();
    await form.locator('#mitOptStock').uncheck();
    await submit(form, 'mit_po_receive');
    const message = await flash(page);
    expect(message).toContain('Received 2 lines on 1 PO');
    expect(message, 'no assets or stock when not ticked').not.toMatch(/asset|stock/i);

    await visit(page, poUrl, errors);
    await expect(page.locator('h2 + .badge')).toHaveText('Received');
    await expect(page.locator('body')).toContainText(`received 2 × ${name}`);
    await expectClean(page, errors);
});

test('ticket part shows in the Parts box and on Unbilled Parts', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, ticketUrl());
    test.skip(await box(page, 'fa-microchip').count() === 0, 'Parts box not shown (Procurement off?)');
    const name = smokeName('part');
    const modal = await openModal(page, `modals/mit_procurement/part.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { name, source: 'stock', quantity: '3', unit_price: '45' });
    await submit(modal, 'mit_part_save');
    expect(await flash(page)).not.toMatch(/error|needs/i);

    await page.waitForTimeout(500); // the layout script moves the boxes
    await expect(box(page, 'fa-microchip')).toContainText(`3 × ${name}`);

    await visit(page, `agent/mit_unbilled_parts.php?client=${made.clientId}`);
    const row = page.locator('tr', { hasText: `3 × ${name}` });
    await expect(row, 'the part on Unbilled Parts').toHaveCount(1);
    await expect(row).toContainText(made.ticketName);
});

test('mileage and a purchase on the ticket: one Trips & purchases box, paid with payroll', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, ticketUrl());
    const menuItem = page.locator('[data-mit-reimb-menu]');
    test.skip(await menuItem.count() === 0, 'Reimbursements off (Timesheets off?)');
    // Nothing logged yet: no Reimbursements box of its own - purchases are added from the ⋮ menu, beside ITFlow's Log a trip
    await expect(box(page, 'fa-receipt'), 'no empty Reimbursements box').toHaveCount(0);
    await expect(menuItem).toHaveAttribute('data-modal-url', new RegExp(`ticket_id=${made.ticketId}&kind=purchase`));

    // Mileage: an ITFlow trip in their own vehicle on this ticket (ITFlow's Trips box), waiting for payroll
    let modal = await openModal(page, `/agent/modals/mit_reimburse/reimburse.php?ticket_id=${made.ticketId}&kind=mileage`);
    await fillForm(modal, { miles: '12.5', source: 'Smoke office', destination: 'Smoke client', round_trip: false });
    await submit(modal, 'mit_reimb_save');
    expect(await flash(page)).not.toMatch(/error|enter|pick|no rate|locked/i);
    await page.waitForTimeout(500);
    const trips = box(page, 'fa-route');
    await expect(trips, 'ITFlow\'s Trips box').toHaveCount(1);
    await expect(trips).toContainText('12.5');
    await expect(trips.locator('[title="Add a purchase"]'), 'Add a purchase beside Log a trip').toHaveCount(1);
    await expect(box(page, 'fa-receipt'), 'no box of our own').toHaveCount(0);

    // A purchase from the ⋮ menu: listed inside the Trips box (not twice), "Goes to payroll"
    modal = await openModal(page, await menuItem.getAttribute('data-modal-url'));
    await fillForm(modal, { amount: '23.45', description: 'Smoke test cable', vendor: 'Smoke store' });
    await submit(modal, 'mit_reimb_save');
    expect(await flash(page)).not.toMatch(/error|enter|pick|locked/i);
    await page.waitForTimeout(500);
    await expect(box(page, 'fa-receipt'), 'still no box of our own').toHaveCount(0);
    await expect(trips.locator('.card-title')).toContainText('Trips & purchases');
    const inTrips = trips.locator('[data-mit-reimb-in-trips]');
    await expect(inTrips).toContainText('Smoke test cable');
    await expect(inTrips).toContainText('$23.45');
    await expect(inTrips, 'mileage only in ITFlow\'s list').not.toContainText('12.5 mi');
    await expect(inTrips.locator('[data-mit-reimb-state]')).toHaveText('Goes to payroll');

    // The trip waits under Trips > To pay back until the timesheet is approved
    await visit(page, 'agent/trips.php?show=to_pay');
    await expect(page.locator('body')).toContainText('Smoke client');
});

test('an additional agent on the ticket, then taken off', async ({ page, browser }) => {
    test.skip(!made.ticketId, 'no ticket');
    test.skip(!fs.existsSync(techState), 'no technician login');
    // The technician's name, from their own Account page
    const use = test.info().project.use;
    const tech = await browser.newContext({ storageState: techState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const techPage = await tech.newPage();
    await techPage.goto('agent/user/user_details.php');
    const techName = await techPage.locator('input[name=name]').inputValue();
    await tech.close();
    expect(techName).toBeTruthy();

    await visit(page, ticketUrl());
    const prop = page.locator('.ticket-prop', { has: page.locator('dt', { hasText: 'Additional agents' }) });
    await expect(prop).toHaveCount(1);
    await expect(prop).not.toContainText(techName);

    const modal = await openModal(page, `modals/ticket/mit_ticket_tech.php?ticket_id=${made.ticketId}`);
    const techId = await modal.locator('select[name=user_id] option').evaluateAll((options, name) =>
        (options.find((o) => o.dataset.name === name) || {}).value, techName);
    expect(techId, `${techName} offered as a helper (needs access to the client)`).toBeTruthy();
    await fillForm(modal, { user_id: techId, tech_mode: 'none' }); // not booked - no invite email
    await submit(modal, 'mit_save_ticket_tech');
    expect(await flash(page)).not.toMatch(/error|cannot|not on/i);
    await expect(prop).toContainText(techName);

    // Their own window has Remove
    const edit = await openModal(page, `modals/ticket/mit_ticket_tech.php?ticket_id=${made.ticketId}&user_id=${techId}`);
    const remove = await edit.locator('a[href*="mit_remove_ticket_tech="]').getAttribute('href');
    await page.goto(new URL(remove, page.url()).href, { referer: page.url() }); // back to the ticket, as the link does
    await expectClean(page);
    expect(await flash(page)).toContain('removed from the ticket');
    await visit(page, ticketUrl());
    await expect(prop).not.toContainText(techName);
});

test('a ticket in a project leaves the Tickets board and comes back when taken out', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    const list = (show) => `agent/tickets.php?client_id=${made.clientId}&show=${show}&view=list`;
    const onList = async (show) => {
        await visit(page, list(show), errors);
        return page.locator('a', { hasText: made.ticketName }).count();
    };
    expect(await onList('open'), 'a service ticket is on Open').toBeGreaterThan(0);

    // A project for the client, and the ticket put in it
    let modal = await openModal(page, `modals/project/project_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { name: smokeName('project') });
    await submit(modal, 'add_project');
    made.projectId = Number(new URL(page.url()).searchParams.get('project_id'));
    expect(made.projectId, `project page after saving (${await flash(page)})`).toBeGreaterThan(0);
    await visit(page, ticketUrl(), errors);
    modal = await openModal(page, `modals/ticket/ticket_edit_project.php?id=${made.ticketId}`);
    await fillForm(modal, { project: String(made.projectId) });
    await submit(modal, 'edit_ticket_project');

    expect(await onList('open'), 'a project ticket is off Open').toBe(0);
    expect(await onList('all'), 'and off All').toBe(0);
    expect(await onList('projects'), 'but on the Projects view').toBeGreaterThan(0);

    // Booked, it is in the project color under the calendar's Projects source - not under Scheduled tickets
    const day = new Date(Date.now() + 30 * 86400000);
    await visit(page, ticketUrl(), errors);
    modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { scheduled_date_time: `${ymd(day)}T10:00`, onsite: '0' });
    await submit(modal, 'edit_ticket_schedule');
    const calendar = async (sources) => {
        const { text } = await fetchText(page, `/agent/ajax.php?calendar_items=1&from=${ymd(day)}&to=${ymd(day)}&sources=${sources}`);
        return JSON.parse(text).filter((item) => item.id === `ticket-${made.ticketId}`);
    };
    expect(await calendar('tickets'), 'project booking left out of Scheduled tickets').toHaveLength(0);
    const booked = await calendar('projects');
    expect(booked, 'project booking under Projects').toHaveLength(1);
    expect(booked[0].extendedProps.tech_color, 'tech color kept for the dot').toBeTruthy();
    await action(page, `cancel_ticket_schedule=${made.ticketId}`, ticketUrl());

    // Taken out of the project, it is a service ticket again
    await visit(page, ticketUrl(), errors);
    modal = await openModal(page, `modals/ticket/ticket_edit_project.php?id=${made.ticketId}`);
    await fillForm(modal, { project: '0' });
    await submit(modal, 'edit_ticket_project');
    expect(await onList('open'), 'back on Open').toBeGreaterThan(0);
    expect(await onList('projects')).toBe(0);
    await action(page, `delete_project=${made.projectId}`, `agent/project.php?client_id=${made.clientId}&project_id=${made.projectId}`);
    made.projectId = 0;
    await expectClean(page, errors);
});

test('recurring ticket on the third Wednesday', async ({ page }) => {
    test.skip(!made.clientId, 'no client');
    const start = thirdWednesday(1);
    await visit(page, 'agent/recurring_tickets.php');
    const modal = await openModal(page, `modals/recurring_ticket/recurring_ticket_add.php?client_id=${made.clientId}`, 'lg');
    await page.waitForTimeout(500);
    await fillForm(modal, { subject: made.recurringName, details: 'Smoke test - deleted again.', priority: 'Low', frequency: 'Monthly', start_date: ymd(start), assigned_to: '0', contact_id: '0' });
    // The choice follows the date: "On the 18th" or "On the third Wednesday"
    const repeatOn = modal.locator('select[name=repeat_on]');
    await expect(repeatOn).toBeEnabled();
    await expect(repeatOn.locator('option[value="3WE"]')).toHaveText('On the third Wednesday');
    await fillForm(modal, { repeat_on: '3WE' });
    await submit(modal, 'add_recurring_ticket');
    expect(await flash(page)).toContain('created');

    const rowFor = () => page.locator('tr.itflow-ticket-row', { hasText: made.recurringName });
    const nextRun = async () => new Date((await rowFor().locator('td:nth-child(5) .small').innerText()).trim());
    await visit(page, `agent/recurring_tickets.php?q=${encodeURIComponent(made.recurringName)}`);
    await expect(rowFor()).toHaveCount(1);
    await expect(rowFor()).toContainText('on the third Wednesday');
    expect(ymd(await nextRun()), 'first run on the start date').toBe(ymd(start));
    made.recurringId = Number(await rowFor().locator('input[name="recurring_ticket_ids[]"]').getAttribute('value'));

    // Raise it now: the next run moves on to the third Wednesday of the month after
    const force = await rowFor().locator('a[href*="force_recurring_ticket="]').getAttribute('href');
    await page.goto(new URL(force, page.url()).href, { referer: page.url() }); // the menu item, past its confirm box
    await expectClean(page);
    const raisedMessage = await flash(page);
    await visit(page, `agent/recurring_tickets.php?q=${encodeURIComponent(made.recurringName)}`);
    const raised = await rowFor().locator('a[href*="ticket_id="]').first().getAttribute('href');
    made.raisedTicketId = Number(new URL(raised, page.url()).searchParams.get('ticket_id'));
    expect(raisedMessage).toContain('raised from');
    const next = await nextRun();
    expect(ymd(next), 'next run after raising').toBe(ymd(thirdWednesday(2)));
    expect(next.getDay(), 'a Wednesday').toBe(3);
    expect(next.getDate()).toBeGreaterThanOrEqual(15);
    expect(next.getDate()).toBeLessThanOrEqual(21);
});

test('quote line priced from our cost and markup; the customer view hides the cost', async ({ page, browser }) => {
    test.skip(!made.clientId, 'no client');
    const errors = watch(page);
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
    let modal = await openModal(page, `modals/quote/quote_add.php?client_id=${made.clientId}`);
    const category = await modal.locator('select[name=category] option:not([value=""])').first().getAttribute('value');
    await fillForm(modal, { scope: smokeName('quote'), category });
    await submit(modal, 'add_quote');
    made.quoteId = Number(new URL(page.url()).searchParams.get('quote_id'));
    expect(made.quoteId, `quote page after saving (${await flash(page)})`).toBeGreaterThan(0);
    await visit(page, `agent/quote.php?quote_id=${made.quoteId}`, errors);

    // ITFlow 26.11's add-a-line row on the document with our cost and markup in it, then its Edit Line window
    const add = page.locator('[data-billing-add]');
    test.skip(await add.locator('input[name=item_cost]').count() === 0, 'quote pricing columns missing (database update?)');
    await add.locator('input[name=name]').fill(smokeName('quote line'));
    await add.locator('input[name=qty]').fill('2');
    await add.locator('select[name=item_price_method]').selectOption('markup');
    await add.locator('input[name=item_price_percent]').fill('20');
    await add.locator('input[name=item_cost]').fill('87.31');
    // 87.31 + 20% = 104.772
    await expect(add.locator('input[name=price]'), 'add row: price worked out from cost and markup').toHaveValue('104.77');
    await Promise.all([page.waitForNavigation(), add.locator('button[type=submit]').click()]);
    const lineRow = page.locator('tr[data-item-id]', { hasText: 'Smoke test quote line' });
    await expect(lineRow).toHaveCount(1);
    await expect(lineRow.locator('[data-mit-line-cost]'), 'cost saved from the add row').toContainText('$87.31');
    const itemId = await lineRow.getAttribute('data-item-id');
    const edit = await openModal(page, `modals/billing/billing_item.php?type=quote&doc_id=${made.quoteId}&item_id=${itemId}`);
    await expect(edit.locator('input[name=item_cost]')).toHaveValue('87.31');
    await expect(edit.locator('select[name=item_price_method]')).toHaveValue('markup');
    await edit.locator('input[name=item_price_percent]').fill('25');
    // 87.31 + 25% = 109.1375
    await expect(edit.locator('input[name=price]'), 'price worked out from cost and markup').toHaveValue('109.14');
    await Promise.all([page.waitForNavigation(), edit.locator('button[type=submit]').click()]);

    const line = page.locator('tr[data-item-id]', { hasText: 'Smoke test quote line' });
    await expect(line).toHaveCount(1);
    await expect(line.locator('[data-mit-line-cost]')).toContainText('$87.31'); // our cost, staff only
    await expect(line).toContainText('$109.14');
    await expect(line).toContainText('$218.28');
    await expectClean(page, errors);

    // The customer's link: their price, not our cost or markup
    const link = await page.locator('[data-clipboard-text*="guest_view_quote.php"]').first().getAttribute('data-clipboard-text');
    const guestUrl = 'guest/guest_view_quote.php' + new URL(link).search;
    const use = test.info().project.use;
    const guest = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const guestPage = await guest.newPage();
    await visit(guestPage, guestUrl);
    const text = await guestPage.locator('body').innerText();
    const html = await guestPage.content();
    await guest.close();
    expect(text).toContain('Smoke test quote line');
    expect(text).toContain('109.14');
    expect(html, 'our cost on the customer page').not.toContain('87.31');
    expect(text).not.toMatch(/Our cost|Markup|Margin|Profit/i);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.ticketId) {
        // Reimbursements are not tied to the ticket in the database - remove them (and their trips) first
        await page.goto(ticketUrl());
        for (const url of await page.locator('[data-modal-url*="mit_reimburse/reimburse.php?id="]').evaluateAll((els) => els.map((e) => e.dataset.modalUrl))) {
            const id = new URL(url, page.url()).searchParams.get('id');
            await action(page, `mit_reimb_delete=${id}`, ticketUrl());
        }
        // Mileage is listed by ITFlow's Trips box, not ours - its entry is removed from the timesheet's list
        await page.goto('agent/mit_timesheet.php');
        for (const url of await page.locator('.list-group-item', { hasText: made.ticketName }).locator('[data-modal-url*="mit_reimburse/reimburse.php?id="]').evaluateAll((els) => els.map((e) => e.dataset.modalUrl))) {
            const id = new URL(url, page.url()).searchParams.get('id');
            await action(page, `mit_reimb_delete=${id}`, 'agent/mit_timesheet.php');
        }
        await page.goto(ticketUrl());
        // Deleting the ticket takes its parts (and helpers) with it
        await action(page, `delete_ticket=${made.ticketId}`, ticketUrl());
    }
    if (made.raisedTicketId) {
        await action(page, `delete_ticket=${made.raisedTicketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.raisedTicketId}`);
    }
    if (made.projectId) {
        await action(page, `delete_project=${made.projectId}`, `agent/project.php?client_id=${made.clientId}&project_id=${made.projectId}`);
    }
    if (made.recurringId) {
        await action(page, `delete_recurring_ticket=${made.recurringId}`, 'agent/recurring_tickets.php');
    }
    // A PO this run left short of Received: delete (draft) or cancel it - its item goes back on To order
    if (made.poId) {
        await page.goto(`agent/mit_purchase_order.php?po_id=${made.poId}`);
        const out = page.locator('a[href*="mit_po_delete="], a[href*="mit_po_cancel="]');
        if (await out.count()) {
            await page.goto(new URL(await out.first().getAttribute('href'), page.url()).href, { referer: page.url() });
        }
    }
    // Smoke test items still waiting to be ordered (this run's, or an earlier failed run's): Not needed
    await page.goto('agent/mit_procurement.php');
    const waiting = page.locator('#mitProcForm input[name="item_ids[]"][aria-label^="Select Smoke test PO item"]');
    if (await waiting.count()) {
        for (const box of await waiting.all()) {
            await box.check();
        }
        await submit(page.locator('#mitProcForm'), 'mit_proc_cancel_items');
    }
    if (made.quoteId) {
        await action(page, `delete_quote=${made.quoteId}`, `agent/quote.php?quote_id=${made.quoteId}`);
    }
    await context.close();
});
