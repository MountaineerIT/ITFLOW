// The client portal on a phone (js/mit_portal_tables.js): ITFlow's portal tables sit straight in the page, so a wide one made the
// whole page wider than the screen. A contact given a portal password (ITFlow's own contact form) signs in at phone width; their
// "Your activity" table must be inside a .table-responsive and the page no wider than the screen.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, action } = require('./helpers');

const made = { clientName: smokeName('Portal phone client'), clientId: 0 };
const email = `smoke-portal-${Date.now()}@example.invalid`;
const password = `Smoke-${Date.now()}-Portal!`;

test('portal: tables fit a phone screen', async ({ page, browser }) => {
    test.setTimeout(90000);
    const errors = watch(page);

    // A client, its contact given a portal login with a password
    await visit(page, 'agent/clients.php', errors);
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Pia Phone', contact_email: email });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    await visit(page, `agent/contacts.php?client_id=${made.clientId}`, errors);
    const contactUrl = await page.locator('[data-modal-url*="modals/contact/contact.php?id="]').first().getAttribute('data-modal-url');
    const contactId = new URL(contactUrl, page.url()).searchParams.get('id');
    modal = await openModal(page, `modals/contact/contact_edit.php?id=${contactId}`, 'lg');
    test.skip(await modal.locator('select[name=auth_method]').count() === 0, 'the client portal is off on this install');
    await fillForm(modal, { auth_method: 'local', contact_password: password });
    await submit(modal, 'edit_contact');

    // The contact on a phone
    const use = test.info().project.use;
    // Its own empty cookie jar: browser.newContext() in a test takes the project's admin storageState, and signing in there would
    // replace the admin's session for the tests after this one
    const context = await browser.newContext({ baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors, viewport: { width: 390, height: 844 },
        storageState: { cookies: [], origins: [] } });
    const phone = await context.newPage();
    await phone.goto('login.php');
    await phone.fill('input[name=email]', email);
    await phone.fill('input[name=password]', password);
    await Promise.all([phone.waitForNavigation(), phone.click('button[name=login]')]);
    await phone.goto('client/activity.php');
    const table = phone.locator('table.table').first();
    await expect(table, 'the activity table').toBeVisible();
    await expect(phone.locator('.table-responsive table.table').first(), 'the table is wrapped so it scrolls in its box').toBeVisible();
    const wider = await phone.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(wider, 'the page is no wider than the phone').toBeLessThanOrEqual(1);
    await context.close();
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
