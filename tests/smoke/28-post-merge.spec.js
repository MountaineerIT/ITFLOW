// After merging ITFlow 26.11: our hooks that met its new features.
//  - "View portal as them" (ITFlow's, agent/contact.php) opens the portal in the agent's own session - our portal sign-in check
//    (client/includes/check_login.php) used to end that session, signing the agent out of ITFlow.
//  - The ticket page layout script (agent/js/mit_ticket_layout.js) knows ITFlow's new boxes (Fill-ins, Trips, the AI triage
//    banner): every box gets its name, and the conversation stays under the reply box.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, action, expectClean } = require('./helpers');

test('View portal as a contact keeps the agent signed in', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/contacts.php', errors);
    const contact = page.locator('a[href*="contact.php?"][href*="contact_id="]').first();
    test.skip(await contact.count() === 0, 'no contacts on this install');
    await visit(page, new URL(await contact.getAttribute('href'), page.url()).href.replace(/#.*$/, ''), errors);
    const viewAs = page.locator('a[href*="portal_view_as="]');
    test.skip(await viewAs.count() === 0, 'the client portal is off, or this contact is archived');
    await page.goto(new URL(await viewAs.first().getAttribute('href'), page.url()).href);
    expect(page.url(), 'the portal opens as the contact').toContain('/client/');
    await expect(page.locator('body')).not.toContainText('Fatal error');
    // Back in ITFlow: still signed in
    await page.goto('agent/dashboard.php');
    expect(page.url(), 'the agent is still signed in').toContain('/agent/dashboard.php');
});

const made = { clientName: smokeName('Post-merge client'), clientId: 0, ticketId: 0 };

test('ticket layout: every box of the 26.11 ticket page is known, the conversation stays under the reply box', async ({ page }) => {
    test.setTimeout(90000);
    const errors = watch(page);
    // A ticket booked as an onsite visit - ITFlow then shows its Trips box
    await visit(page, 'agent/clients.php', errors);
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Pat Postmerge', contact_email: 'smoke-postmerge@example.invalid' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('layout'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, 'ticket saved').toBeGreaterThan(0);
    const day = new Date(Date.now() + 86400000).toISOString().slice(0, 10);
    modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { scheduled_date_time: `${day}T10:00`, schedule_minutes: '60', onsite: '1' });
    await submit(modal, 'edit_ticket_schedule');

    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    await page.waitForFunction(() => document.querySelector('.ticket-layout-main [data-mit-box="reply"]'));
    const result = await page.evaluate(() => {
        const unknown = [];
        document.querySelectorAll('.ticket-layout-main > .card, .ticket-layout-side > .card, .mit-layout-left > .card').forEach((card) => {
            if (!card.dataset.mitBox) {
                unknown.push((card.querySelector('.card-title') || card).textContent.trim().slice(0, 40));
            }
        });
        const main = Array.from(document.querySelectorAll('.ticket-layout-main > [data-mit-box]')).map((el) => el.dataset.mitBox);
        const side = Array.from(document.querySelectorAll('.ticket-layout-side > [data-mit-box]')).map((el) => el.dataset.mitBox);
        return { unknown, main, side };
    });
    expect(result.side, 'ITFlow\'s Trips box is shown for an onsite visit, by its name').toContain('trips');
    expect(result.side.indexOf('trips'), `side column order: ${result.side.join(', ')}`).toBeGreaterThan(result.side.indexOf('details'));
    expect(result.unknown, 'cards the layout script does not know').toEqual([]);
    expect(result.main.indexOf('reply'), `main column order: ${result.main.join(', ')}`).toBeLessThan(result.main.indexOf('conversation') === -1 ? 99 : result.main.indexOf('conversation'));
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const page = await browser.newPage();
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await page.close();
});
