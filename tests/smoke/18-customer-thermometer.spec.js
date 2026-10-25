// Customer Thermometer (functions/mit_ct.php) against the test install's stand-in (ct-mock - never a real account): connect and
// read the thermometers and lists, then for a resolved ticket: no survey before the start date, none when its board is not
// ticked, one when it is (carrying the tech, ticket number and subject) and never a second; a Gold response with a comment comes
// back and shows on the client overview, the dashboard and the reviews list. Surveys are switched off again and the client and
// ticket ("Smoke test ...") removed at the end, even when a step fails. Skipped when the stand-in is not installed.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const MOCK = 'http://127.0.0.1/ct-mock/index.php';
const made = { clientName: smokeName('CT client'), subject: smokeName('CT ticket'), clientId: 0, ticketId: 0, ref: '', boardId: '' };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const day = (offset) => new Date(Date.now() + offset * 86400000).toISOString().slice(0, 10);

async function mock(page, path) {
    const response = await page.request.get(MOCK + path).catch(() => null);
    return response && response.ok() ? response.json() : null;
}

// Admin > Integrations > Customer Thermometer: save "What to send"
async function settings(page, { enabled = true, start = day(-1), boards = [] }) {
    await visit(page, 'admin/mit_customer_thermometer.php');
    const form = page.locator('form:has(button[name=mit_ct_save])');
    await form.locator('select[name=ct_thermometer_id]').selectOption('101');
    await form.locator('select[name=ct_list_id]').selectOption('7');
    await form.locator('input[name=ct_enabled]').setChecked(enabled);
    await form.locator('input[name="ct_boards[]"]').evaluateAll((boxes, ids) => boxes.forEach((b) => { b.checked = ids.includes(b.value); }), boards);
    await form.locator('input[name=ct_start]').fill(start);
    await submit(form, 'mit_ct_save');
    return flash(page);
}

async function runNow(page) {
    await visit(page, 'admin/mit_customer_thermometer.php');
    await submit(page.locator('form:has(button[name=mit_ct_run])'), 'mit_ct_run');
    return flash(page);
}

const sentFor = async (page) => ((await mock(page, '/_state')) || { sent: [] }).sent.filter((s) => s.custom2 === made.ref);

test('customer thermometer: connect, and a resolved ticket to survey', async ({ page }) => {
    const errors = watch(page);
    test.skip(!(await mock(page, '/_reset')), 'the Customer Thermometer stand-in is not installed on this test install');
    await visit(page, 'admin/mit_customer_thermometer.php', errors);
    await expect(page.locator('a.nav-link[href="/admin/mit_customer_thermometer.php"]'), 'Admin > Integrations menu entry').toHaveCount(1);

    // A wrong key is refused, the right one reads the account's thermometers and lists
    let form = page.locator('form:has(button[name=mit_ct_connect])');
    await fillForm(form, { ct_api_key: 'wrong-key' });
    await submit(form, 'mit_ct_connect');
    expect(await flash(page), 'a wrong key').toContain('Invalid API Key');
    form = page.locator('form:has(button[name=mit_ct_connect])');
    await fillForm(form, { ct_api_key: 'ct-key' });
    await submit(form, 'mit_ct_connect');
    expect(await flash(page)).toContain('connected: 2 thermometer(s), 1 recipient list(s)');
    await expect(page.locator('select[name=ct_thermometer_id] option[value="101"]')).toHaveText('Ticket closed');

    // A client whose contact has an email address, and a resolved ticket
    await visit(page, 'agent/clients.php', errors);
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke CT Contact', contact_email: 'smoke-ct@example.invalid' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: made.subject, details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, 'ticket saved').toBeGreaterThan(0);
    made.boardId = await page.locator('button[data-quick-pick="mit_board"]').getAttribute('data-quick-current');
    made.ref = (await page.locator('title').textContent()).match(/[A-Z]+-\d+/)?.[0] || '';
    await action(page, `resolve_ticket=${made.ticketId}`, ticketPage());
    expect(made.boardId, 'the ticket\'s board').toBeTruthy();
    expect(made.ref, 'the ticket number').toBeTruthy();
});

test('customer thermometer: only from the start date, only the ticked boards, once', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    // Start date the day after tomorrow: today's ticket is too old (a day either side, so the test machine's and the
    // server's time zones can't disagree about "today")
    await settings(page, { start: day(2), boards: [made.boardId] });
    await runNow(page);
    expect(await sentFor(page), 'nothing before the start date').toHaveLength(0);

    // Its board not ticked
    const others = await (async () => {
        await visit(page, 'admin/mit_customer_thermometer.php');
        return page.locator('input[name="ct_boards[]"]').evaluateAll((boxes, id) => boxes.map((b) => b.value).filter((v) => v !== id), made.boardId);
    })();
    if (others.length) {
        await settings(page, { start: day(-1), boards: others });
        await runNow(page);
        expect(await sentFor(page), 'nothing from a board that is not ticked').toHaveLength(0);
    }

    // Its board ticked, from yesterday
    expect(await settings(page, { start: day(-1), boards: [made.boardId] })).toContain('surveys go out for tickets resolved from');
    expect(await runNow(page)).toMatch(/[1-9]\d* surveys? sent/);
    const sent = await sentFor(page);
    expect(sent, 'one survey for the ticket').toHaveLength(1);
    expect(sent[0].emailAddress, 'to the contact').toBe('smoke-ct@example.invalid');
    expect(sent[0].custom1, 'the tech').toBeTruthy();
    expect(sent[0].custom3, 'the ticket\'s subject').toBe(made.subject);
    expect(sent[0].thermometerID + '/' + sent[0].listID).toBe('101/7');

    // Never a second one
    await runNow(page);
    expect(await sentFor(page), 'still one').toHaveLength(1);
    await expect(page.locator('[data-mit-ct-surveys]'), 'listed as sent').toContainText(made.ref);
});

test('customer thermometer: a response shows on the client, the dashboard and the reviews list', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    const [survey] = await sentFor(page);
    test.skip(!survey, 'no survey was sent');
    await mock(page, `/_respond?blast=${survey.blast}&temperature=1&comment=${encodeURIComponent('Great work, thanks!')}`);
    expect(await runNow(page)).toMatch(/1 new response\b/);

    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`, errors);
    const card = page.locator('[data-mit-ct-client]');
    await expect(card.locator('[data-mit-ct-happiness]'), 'the client\'s score').toHaveAttribute('data-mit-ct-happiness', '100');
    await expect(card, 'the comment').toContainText('Great work, thanks!');
    await expect(card.locator('[data-mit-ct-count="1"] .itflow-dash-rail-count')).toHaveText('1');
    await expect(card.locator('[data-mit-ct-more]'), 'no "All comments" link for one comment').toHaveCount(0);

    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('[data-mit-ct-dash] [data-mit-ct-happiness]'), 'the dashboard score').toHaveCount(1);

    await visit(page, `agent/mit_ct_reviews.php?client_id=${made.clientId}`, errors);
    await expect(page.locator('[data-mit-ct-reviews]')).toContainText(made.clientName);
    await expect(page.locator('table')).toContainText('Great work, thanks!');
    await expect(page.locator('table')).toContainText(made.ref);

    // The client picker: lists the client, and "All Clients" goes back to everyone
    const pick = page.locator('[data-mit-ct-client-pick] select[name=client_id]');
    await expect(pick, 'the client picked').toHaveValue(String(made.clientId));
    await Promise.all([page.waitForNavigation(), pick.evaluate((select) => { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); })]);
    expect(new URL(page.url()).searchParams.get('client_id') || '', 'all clients').toBe('');
    await expect(page.locator('[data-mit-ct-reviews] h2')).not.toContainText(made.clientName);

    await visit(page, ticketPage(), errors);
    await expect(page.locator('body'), 'the ticket\'s history').toContainText('Customer Thermometer: rated Gold');
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.boardId) {
        await settings(page, { enabled: false, boards: [made.boardId] }).catch(() => {});
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
