// Quick ticket (functions/mit_quick_ticket.php): its defaults on Admin > Settings > Tickets save and are counted in ITFlow's message;
// the top bar's + opens the window, starts on the page's client and its primary contact, lists the contact's tickets still open, and
// Make ticket goes through ITFlow's own New Ticket handler - the ticket lands on the board, assignee, status and priority set, its
// details kept, a history line, no "Ticket created" email when switched off, and ITFlow's timer running from when the window opened.
// "Work on this one" goes to an open ticket with its timer started. Off, the button is gone and the requests refuse.
// Everything it makes is removed and the settings put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean, flash } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const N = { client: smokeName('quick client'), contact: smokeName('Quick Caller'), issue: smokeName('printer jams') };
const made = { client: 0, contact: 0, open: 0, quick: 0, settings: [] };
const email = `quick.caller.${Date.now()}@example.com`;

// Set a select by value through the native element - ITFlow's picker draws itself from it
async function pick(locator, value) {
    await locator.evaluate((el, v) => {
        el.value = String(v);
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, value);
}

test.beforeAll(() => {
    made.settings = db.rows("SELECT setting_name, setting_value FROM mit_settings WHERE setting_name LIKE 'quick_ticket_%'");
    made.client = db.insert(`INSERT INTO clients SET client_name = ${db.q(N.client)}, client_currency_code = 'USD', client_created_at = NOW()`);
    made.contact = db.insert(`INSERT INTO contacts SET contact_name = ${db.q(N.contact)}, contact_email = ${db.q(email)}, contact_phone = '5415550199',
        contact_primary = 1, contact_client_id = ${made.client}, contact_created_at = NOW()`);
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.open = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = ${db.q(smokeName('already open'))}, ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 2, ticket_client_id = ${made.client}, ticket_contact_id = ${made.contact}, ticket_assigned_to = 2, ticket_created_by = 1,
        ticket_url_key = MD5(RAND()), ticket_created_at = NOW() - INTERVAL 1 DAY`);
});

test.afterAll(() => {
    for (const id of [made.quick, made.open].filter(Boolean)) {
        db.exec(`DELETE FROM email_queue WHERE email_recipient = ${db.q(email)}; DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${id};
            DELETE FROM mit_ticket_boards WHERE ticket_id = ${id}; DELETE FROM mit_board_moves WHERE ticket_id = ${id}; DELETE FROM notifications WHERE notification_entity_id = ${id};
            DELETE FROM tickets WHERE ticket_id = ${id}`);
    }
    if (made.client) {
        db.exec(`DELETE FROM contacts WHERE contact_client_id = ${made.client}; DELETE FROM logs WHERE log_client_id = ${made.client}; DELETE FROM clients WHERE client_id = ${made.client}`);
    }
    db.exec("DELETE FROM mit_settings WHERE setting_name LIKE 'quick_ticket_%'");
    for (const row of made.settings) {
        db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(row.setting_name)}, setting_value = ${db.q(row.setting_value)}`);
    }
});

test('Admin > Settings > Tickets: the Quick tickets section saves, in ITFlow\'s message', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings_ticket.php', errors);
    const section = page.locator('#section-mit-quick-ticket');
    await expect(section).toBeVisible();
    await pick(page.locator('[name="mit_quick_ticket_board"]'), 3);       // Tier 2
    await pick(page.locator('[name="mit_quick_ticket_assign"]'), 'me');
    await pick(page.locator('[name="mit_quick_ticket_status"]'), 3);      // On Hold
    await pick(page.locator('[name="mit_quick_ticket_priority"]'), 'High');
    await pick(page.locator('[name="mit_quick_ticket_timer"]'), 'open');
    await page.locator('[name="mit_quick_ticket_on"]').check();
    await page.locator('[name="mit_quick_ticket_primary"]').check();
    await page.locator('[name="mit_quick_ticket_open_tickets"]').check();
    await page.locator('[name="mit_quick_ticket_email_client"]').uncheck();
    await Promise.all([page.waitForNavigation(), page.locator('button[name="edit_ticket_settings"]').click()]);
    await expectClean(page, errors);
    expect(await flash(page)).toMatch(/saved/i);
    const saved = Object.fromEntries(db.rows("SELECT setting_name, setting_value FROM mit_settings WHERE setting_name LIKE 'quick_ticket_%'").map((r) => [r.setting_name, r.setting_value]));
    expect(saved).toMatchObject({ quick_ticket_board: '3', quick_ticket_status: '3', quick_ticket_priority: 'High', quick_ticket_timer: 'open', quick_ticket_email_client: '0' });
    // Find a setting knows it
    expect(db.php("echo isset(settingsFields()['mit_quick_ticket_timer']) ? 'yes' : 'no';")).toBe('yes');
});

test('the window: the page\'s client, its primary contact, the contact\'s open tickets, Make ticket through ITFlow', async ({ page }) => {
    const errors = watch(page);
    await visit(page, `agent/client_overview.php?client_id=${made.client}`, errors);
    const opener = page.locator('[data-mit-quick-ticket-open]');
    await expect(opener).toBeVisible();
    const openedAt = await page.evaluate(() => Date.now());
    await opener.click();
    const modal = page.locator('#mitQuickTicket');
    await expect(modal).toBeVisible();
    // Started on this page's client and its primary contact
    await expect(modal.locator('[data-mit-quick-client]')).toHaveValue(String(made.client));
    await expect(modal.locator('[data-mit-quick-contact]')).toHaveValue(String(made.contact));
    // The contact's open ticket, with Work on this one
    const list = modal.locator('[data-mit-quick-open-list]');
    await expect(list).toBeVisible();
    await expect(list.locator(`[data-mit-quick-open-ticket="${made.open}"]`)).toContainText('already open');
    await expect(list.locator(`[data-mit-quick-work="${made.open}"]`)).toBeVisible();
    // Priority starts as the setting says
    await expect(modal.locator('[name="priority"]')).toHaveValue('High');
    await modal.locator('[data-mit-quick-subject]').fill(N.issue);
    await modal.locator('[data-mit-quick-details]').fill('Line one <b>not bold</b>\nLine two');
    await Promise.all([page.waitForURL(/ticket\.php\?.*ticket_id=\d+/), modal.locator('[data-mit-quick-make]').click()]);
    await expectClean(page, errors);
    made.quick = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.quick).toBeGreaterThan(0);
    const row = db.one(`SELECT ticket_subject, ticket_client_id, ticket_contact_id, ticket_assigned_to, ticket_status, ticket_priority, REPLACE(ticket_details, '\\n', ' ') AS details,
        ticket_source FROM tickets WHERE ticket_id = ${made.quick}`);
    expect(row).toMatchObject({ ticket_subject: N.issue, ticket_client_id: String(made.client), ticket_contact_id: String(made.contact), ticket_assigned_to: '1',
        ticket_status: '3', ticket_priority: 'High', ticket_source: 'Agent' });
    expect(row.details, 'the text kept, escaped, with its line break').toContain('Line one &lt;b&gt;not bold&lt;/b&gt;<br>Line two');
    expect(db.val(`SELECT board_id FROM mit_ticket_boards WHERE ticket_id = ${made.quick}`), 'on the board set').toBe('3');
    expect(db.val(`SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = ${made.quick} AND ticket_history_description LIKE '%Quick ticket%'`)).toBe('1');
    expect(db.val(`SELECT COUNT(*) FROM email_queue WHERE email_recipient = ${db.q(email)} AND email_subject LIKE '%Ticket Created%'`), 'no "Ticket created" email (switched off)').toBe('0');
    // ITFlow's timer runs, from when the window opened
    const start = Number(await page.evaluate((id) => localStorage.getItem(id + '-startTime'), String(made.quick)));
    expect(start).toBeGreaterThanOrEqual(openedAt);
    expect(start).toBeLessThan(await page.evaluate(() => Date.now()));
    await expect.poll(() => page.locator('#seconds').inputValue(), { timeout: 5000 }).not.toBe('');
    // The timer start is used once: cleared, the ticket opened again does not start it from the window's time
    await page.evaluate((id) => { localStorage.removeItem(id + '-startTime'); localStorage.setItem(id + '-pausedTime', '0'); }, String(made.quick));
    await page.reload();
    expect(await page.evaluate((id) => localStorage.getItem(id + '-startTime'), String(made.quick))).toBeNull();
    await page.evaluate((id) => { localStorage.removeItem(id + '-pausedTime'); localStorage.removeItem('ticket-timer-running-' + id); }, String(made.quick));
});

test('Work on this one: to the open ticket, its timer started', async ({ page }) => {
    const errors = watch(page);
    await visit(page, `agent/client_overview.php?client_id=${made.client}`, errors);
    await page.evaluate((id) => { localStorage.removeItem(id + '-startTime'); localStorage.removeItem(id + '-pausedTime'); }, String(made.open));
    await page.locator('[data-mit-quick-ticket-open]').click();
    const work = page.locator(`#mitQuickTicket [data-mit-quick-work="${made.open}"]`);
    await expect(work).toBeVisible();
    await Promise.all([page.waitForURL(new RegExp(`ticket_id=${made.open}`)), work.click()]);
    await expectClean(page, errors);
    expect(Number(await page.evaluate((id) => localStorage.getItem(id + '-startTime'), String(made.open)))).toBeGreaterThan(0);
    await page.evaluate((id) => { localStorage.removeItem(id + '-startTime'); localStorage.removeItem('ticket-timer-running-' + id); }, String(made.open));
});

test('switched off: no button, and its requests refuse', async ({ page }) => {
    db.exec("REPLACE INTO mit_settings SET setting_name = 'quick_ticket_on', setting_value = '0'");
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('[data-mit-quick-ticket-open]')).toHaveCount(0);
    const status = await page.evaluate(() => fetch('/agent/mit_quick_ticket_ajax.php?clients=1').then((r) => r.status));
    expect(status).toBe(403);
    db.exec("REPLACE INTO mit_settings SET setting_name = 'quick_ticket_on', setting_value = '1'");
});
