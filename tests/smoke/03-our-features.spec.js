// Mountaineer IT's own additions that the page crawl cannot judge by loading
// alone. Uses the admin login's own preferences - run with a test login.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, fetchText, getJson, csrf, openModal, fillForm, submit } = require('./helpers');

test('Tickets list remembers the view, and Reset forgets it', async ({ page }) => {
    const pill = () => page.locator('.card-header nav a.nav-link.active, nav.nav a.nav-link.active').first().innerText();
    const go = (selector) => Promise.all([page.waitForNavigation(), page.click(selector)]);

    await visit(page, 'agent/tickets.php');
    await go('a[href^="?mit_reset_view="]');
    await go('a[title^="Kanban"]');
    await go('nav a.nav-link:has-text("Unassigned")');
    await go('a[title="List"]');

    // Away and back by the side menu
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');
    await expect(page.locator('a[title="List"]')).toHaveClass(/btn-dark/);

    // A reset link without the session's token (as another site could make) leaves the view alone
    await visit(page, 'agent/tickets.php?mit_reset_view=1');
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');

    // A link from somewhere else shows what it points to, and is not remembered
    await visit(page, 'agent/tickets.php?show=closed');
    await visit(page, 'agent/dashboard.php');
    await go('.app-sidebar a[href$="/agent/tickets.php"]');
    expect(await pill()).toContain('Unassigned');

    await go('a[href^="?mit_reset_view="]');
    expect(await pill()).toContain('Open');
    await visit(page, 'agent/tickets.php');
    expect(await pill()).toContain('Open');
});

test('ticket board and filters', async ({ page }) => {
    const errors = watch(page);
    for (const url of ['agent/tickets.php?view=kanban', 'agent/tickets.php?show=all&sort=ticket_priority&order=ASC', 'agent/tickets.php?show=scheduled', 'agent/tickets.php?q=smoke']) {
        await visit(page, url, errors);
    }
    // The status columns are "Kanban" and their first pill "Active" - "board" means a service board on this page
    await visit(page, 'agent/tickets.php?view=kanban', errors);
    await expect(page.locator('[aria-label=View] a[title^="Kanban"]'), 'the Kanban view button').toHaveCount(1);
    await expect(page.locator('body'), 'no "On the board" pill').not.toContainText('On the board');
    await expect(page.locator('a.nav-link, a.btn', { hasText: /^\s*Active\b/ }).first(), 'the Active pill').toBeVisible();
    await visit(page, 'agent/tickets.php', errors);
    await Promise.all([page.waitForNavigation(), page.click('a[href^="?mit_reset_view="]')]);
});

test('timesheet and payroll report', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    await page.waitForTimeout(300);
    await expectClean(page, errors);
    await visit(page, 'agent/reports/mit_payroll.php', errors);
});

test('timesheets: who is behind, and reminding them', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheets.php', errors);
    // The handler answers even when there is nobody to remind (yourself is never reminded)
    const token = await csrf(page);
    // (fetch follows the redirect back to the page, which shows the message)
    const answer = await page.evaluate(async (token) => (await fetch('post.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({csrf_token: token, mit_ts_remind: '0'}).toString()})).text(), token);
    expect(answer, 'the Remind handler').toContain('Nobody to remind');
    const box = page.locator('[data-mit-ts-behind]');
    test.skip(await box.count() === 0, 'nobody is behind on their timesheets');
    // Pressing Remind sends a real notification and email to that person - only where they are test logins
    test.skip(!process.env.ITFLOW_SMOKE_REMIND, 'set ITFLOW_SMOKE_REMIND=1 when everyone on this install is a test login (Remind emails them)');
    // Remind the first person: sent, or already reminded within the hour (a second run)
    await Promise.all([page.waitForNavigation(), box.locator('button[name=mit_ts_remind]:not([value=all])').first().click()]);
    await expect(page.locator('body')).toContainText(/Reminder sent to|reminded in the last hour/);
    await expect(page.locator('[data-mit-ts-behind]')).toContainText(/Reminded /);
    await expectClean(page, errors);
});

test('Dispatch: our board picker narrows ITFlow\'s queue, Workload and This week; cards carry the board', async ({ page }) => {
    const errors = watch(page);
    const db = require('./mitdb');
    await visit(page, 'agent/dispatch.php', errors);
    test.skip(!/dispatch\.php/.test(page.url()) || await page.locator('#itflowDispatch').count() === 0, 'Dispatch is switched off');
    await expect(page.locator('[data-mit-dispatch-board]'), 'our board picker in the toolbar').toHaveCount(1);
    await expect(page.locator('[data-mit-dispatch-board-techs]'), '"Only its members"').toHaveCount(1);
    const n = 480000 + Math.floor(Math.random() * 9000);
    const id = db.insert(`INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = ${n}, ticket_subject = 'ZZboard ${n}', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 1, ticket_created_by = 1, ticket_assigned_to = 0, ticket_client_id = 1, ticket_url_key = 'zzb${n}'`);
    db.exec(`INSERT INTO mit_ticket_boards SET ticket_id = ${id}, board_id = 2`);
    try {
        const queue = async (board) => (await getJson(page, `ajax.php?dispatch_queue=unassigned&q=ZZboard%20${n}&board=${board}`)).tickets || [];
        const onTwo = await queue('2');
        expect(onTwo.map((t) => t.id), 'on its board').toContain(id);
        expect(onTwo.find((t) => t.id === id).mit_board, 'the card names its board').toBeTruthy();
        expect((await queue('3')).map((t) => t.id), 'not on another board').not.toContain(id);
        expect((await queue('all')).map((t) => t.id), 'on All boards').toContain(id);
        for (const url of ['ajax.php?dispatch_workload=1&board=2&agents=1', 'ajax.php?dispatch_week=1&board=2&agents=1&first_day=1']) {
            const { status, text } = await fetchText(page, url);
            expect(status, url).toBe(200);
            expect(() => JSON.parse(text), `${url}: ${text.slice(0, 200)}`).not.toThrow();
        }
    } finally {
        db.exec(`DELETE FROM mit_ticket_boards WHERE ticket_id = ${id}; DELETE FROM mit_board_moves WHERE ticket_id = ${id}; DELETE FROM tickets WHERE ticket_id = ${id}`);
    }
    await expectClean(page, errors);
});

test('calendar draws', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    await page.waitForTimeout(1000);
    await expectClean(page, errors);
});

test('our old health address answers as ITFlow\'s /health.php', async ({ page }) => {
    const db = require('./mitdb');
    await visit(page, 'agent/dashboard.php');
    const key = db.val("SELECT config_cron_health_key FROM settings WHERE company_id = 1");
    test.skip(!key, 'no health key made yet');
    const ours = await fetchText(page, `/mit_health.php?key=${encodeURIComponent(key)}`);
    const theirs = await fetchText(page, `/health.php?key=${encodeURIComponent(key)}`);
    expect([200, 503]).toContain(ours.status);
    expect(ours.status, 'the same answer').toBe(theirs.status);
    expect(ours.text.split('\n')[0]).toBe(theirs.text.split('\n')[0]);
    expect((await fetchText(page, '/mit_health.php?key=wrong')).status).toBe(403);
    // The top bar warning matches: shown exactly when the address reports a problem
    await expect(page.locator('#mitCronHealth')).toHaveCount(ours.status === 503 ? 1 : 0);
});

test('purchase order: our "Link to a project" opens as a button', async ({ page }) => {
    await visit(page, 'agent/purchase_orders.php');
    const link = page.locator('a[href*="purchase_order.php?"][href*="po_id="]').first();
    test.skip(await link.count() === 0, 'no purchase orders');
    await visit(page, await link.evaluate((a) => a.href));
    // A window opener keeps its button look (a second class attribute would be dropped by the browser)
    const plain = await page.locator('[data-modal-url*="po_project.php"]').evaluateAll((links) => links
        .filter((a) => !a.classList.contains('ajax-modal') || !(a.classList.contains('btn') || a.classList.contains('dropdown-item')))
        .map((a) => a.textContent.trim()));
    expect(plain).toEqual([]);
});

test('reply box starts open on Public note', async ({ page }) => {
    await visit(page, 'agent/tickets.php?show=open');
    const ticket = page.locator('a[href*="ticket.php?"][href*="ticket_id="]').first();
    test.skip(await ticket.count() === 0, 'no open tickets');
    const errors = watch(page);
    await visit(page, await ticket.evaluate((a) => a.href), errors);
    await expect(page.locator('#public_reply_type_opt1')).toBeChecked();
    await expect(page.locator('#replyComposer')).toHaveClass(/\bshow\b/);
    await expect(page.locator('#replyAudience [data-audience="1"]')).toBeVisible();
    await expect(page.locator('#replySubmitLabel')).toHaveText('Post note');
    // The editor is sized for an open box, not squashed to nothing
    const editor = page.locator('#replyComposer .tox-tinymce');
    await editor.waitFor();
    expect((await editor.boundingBox()).height, 'reply editor height').toBeGreaterThan(100);
    // Cancel still shuts it
    await page.click('#cancelReply');
    await expect(page.locator('#replyComposer')).not.toHaveClass(/\bshow\b/);
    await expectClean(page, errors);
});

test('NinjaOne: settings pages, and the status asked for beside assets', async ({ page }) => {
    const errors = watch(page);
    for (const tab of ['settings', 'mapping', 'runs']) {
        await visit(page, `admin/mit_ninjaone.php?tab=${tab}`, errors);
        await expect(page.locator('.card-title')).toContainText('NinjaOne');
    }
    // The endpoint the buttons use answers with an object of linked assets (empty when none are linked)
    const { status, text } = await fetchText(page, '/agent/mit_ninjaone_ajax.php?status=1,2,3');
    expect(status, 'NinjaOne status endpoint').toBe(200);
    const devices = JSON.parse(text);
    expect(typeof devices).toBe('object');
    for (const device of Object.values(devices)) {
        expect(device.url, 'button goes to a device page in NinjaOne').toMatch(/^https:\/\/[^/]+\/#\/deviceDashboard\/\d+\/overview$/);
    }
    await expectClean(page, errors);
});

test('description ticked Read stays folded away, untick shows it again', async ({ page }) => {
    await visit(page, 'agent/tickets.php?show=open');
    const ticket = page.locator('a[href*="ticket.php?"][href*="ticket_id="]').first();
    test.skip(await ticket.count() === 0, 'no open tickets');
    const url = await ticket.evaluate((a) => a.href);
    const errors = watch(page);
    await visit(page, url, errors);
    const tick = page.locator('#mitTicketRead');
    const details = page.locator('#ticketDetails');
    if (await tick.isChecked()) { // left ticked by hand - start from unread
        await Promise.all([page.waitForResponse((r) => r.url().includes('ajax.php')), tick.uncheck()]);
        await visit(page, url, errors);
    }
    await expect(details).toBeVisible();
    await Promise.all([page.waitForResponse((r) => r.url().includes('ajax.php') && r.ok()), tick.check()]);
    await expect(details).toBeHidden();
    await visit(page, url, errors);
    await expect(tick, 'still ticked after a reload').toBeChecked();
    await expect(details, 'and still folded away').toBeHidden();
    await Promise.all([page.waitForResponse((r) => r.url().includes('ajax.php') && r.ok()), tick.uncheck()]);
    await expect(details).toBeVisible();
    await visit(page, url, errors);
    await expect(tick).not.toBeChecked();
    await expect(details).toBeVisible();
    await expectClean(page, errors);
});

test('our version (ITFlow release + iteration) and its release notes on Admin > Update', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/update.php', errors);
    const version = (await page.locator('[data-update-install] [data-mit-version]').innerText()).trim();
    expect(version, 'version like 26.10.1, or 26.11.1.1 on an ITFlow patch release').toMatch(/^\d{2}\.\d{1,2}(\.\d+){1,2}$/);
    const itflow = (await page.locator('[data-update-install] [data-mit-itflow-version]').innerText()).replace('ITFlow ', '').trim();
    expect(version.startsWith(`${itflow}.`), `our version ${version} follows ITFlow ${itflow} (start again at .1 after merging an ITFlow release)`).toBeTruthy();
    await expect(page.locator(`[id="mit-release-${version}"]`), 'release notes for the running version (MIT_CHANGELOG.md)').toContainText('Running');
    await expect(page.locator('footer')).toContainText(`ITFlow ${version}`);
    const notes = await fetchText(page, '/MIT_CHANGELOG.md');
    expect(notes.status, 'our notes are not served').toBe(404);
    await expectClean(page, errors);
});

test('Admin > Timesheets > Holidays: add, edit and remove a holiday', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_timesheets.php?tab=holidays&year=2099', errors);
    test.skip(await page.locator('text=Timesheets need the database update').count() > 0, 'Timesheets not set up');
    await expect(page.locator('a.nav-link', { hasText: 'Holidays' }), 'the Holidays tab').toHaveCount(1);
    const row = () => page.locator('tr[data-mit-holiday="2099-12-30"]');
    if (await row().count()) { // left from a run that failed
        await page.goto(new URL(await row().locator('a[href*="mit_ts_holiday_delete="]').getAttribute('href'), page.url()).href);
        await visit(page, 'admin/mit_timesheets.php?tab=holidays&year=2099', errors);
    }
    let modal = await openModal(page, 'modals/mit_timesheets/holiday.php?year=2099');
    await fillForm(modal, { holiday_date: '2099-12-30', holiday_name: 'Smoke test holiday' });
    await submit(modal, 'mit_ts_holiday_save');
    await expect(row(), 'the new holiday in its year').toContainText('Smoke test holiday');

    const id = new URL(await row().locator('[data-modal-url*="holiday.php?id="]').getAttribute('data-modal-url'), page.url()).searchParams.get('id');
    // It shows on the calendar, under Holidays
    const cal = await fetchText(page, '/agent/ajax.php?calendar_items=1&from=2099-12-30&to=2099-12-30&sources=holidays');
    expect(JSON.parse(cal.text).map((i) => i.id), 'on the calendar').toContain(`holiday-${id}`);
    modal = await openModal(page, `modals/mit_timesheets/holiday.php?id=${id}`);
    await fillForm(modal, { holiday_name: 'Smoke test holiday renamed' });
    await submit(modal, 'mit_ts_holiday_save');
    await expect(row()).toContainText('Smoke test holiday renamed');

    await page.goto(new URL(await row().locator('a[href*="mit_ts_holiday_delete="]').getAttribute('href'), page.url()).href);
    await visit(page, 'admin/mit_timesheets.php?tab=holidays&year=2099', errors);
    await expect(row(), 'removed').toHaveCount(0);
    await expectClean(page, errors);
});

test('a pop-up link to another site is not loaded (ajax-modal runs the page it gets)', async ({ page }) => {
    await visit(page, 'agent/tickets.php');
    const outside = [];
    page.on('request', (request) => {
        if (new URL(request.url()).hostname === 'mit-smoke.example.invalid') {
            outside.push(request.url());
        }
    });
    // As a link in an emailed ticket would look, had it kept its class
    await page.evaluate(() => {
        const link = document.createElement('a');
        link.className = 'ajax-modal';
        link.id = 'mitSmokeOutsideModal';
        link.href = 'https://mit-smoke.example.invalid/modal.json';
        link.textContent = 'View invoice';
        document.body.appendChild(link);
    });
    await page.click('#mitSmokeOutsideModal');
    await page.waitForTimeout(500);
    expect(outside).toEqual([]);
});

test('security headers, and API keys in the address still work (as on stock ITFlow)', async ({ page }) => {
    const response = await page.goto('login.php');
    const headers = response.headers();
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['content-security-policy'] || '').toContain("frame-ancestors 'self'");
    expect(headers['referrer-policy']).toBe('same-origin');
    // A key in the address is checked like any other (tools written for stock ITFlow - MSP Align - read that way): a wrong one is
    // refused as invalid, not as "in the address" (scenario-api-key-ways.php reads with a real key every way)
    const api = await page.goto('api/v1/clients/read.php?api_key=mit-smoke-not-a-key');
    expect(api.status()).toBe(401);
    expect(await api.text()).toContain('API key is invalid');
});

test('Microsoft 365 card offers a calendar-only app for Outlook', async ({ page }) => {
    await visit(page, 'admin/settings_mail.php?tab=oauth');
    const card = page.locator('body');
    test.skip(await page.locator('button.js-mit-m365-start[data-purpose="create"]').count() > 0, 'no Microsoft 365 app set up on this install');
    // Either the button to make it, or the line saying Outlook already uses it
    const offer = page.locator('button.js-mit-m365-start[data-purpose="calendar"]');
    const done = card.getByText('calendar-only app (', { exact: false });
    expect(await offer.count() + await done.count()).toBeGreaterThan(0);
});

test('modern look: on by default, light and dark, client tiles; Classic turns it off', async ({ page }) => {
    const prefs = async () => {
        await visit(page, 'agent/user/user_preferences.php');
        return page.locator('form:has([name="edit_your_user_preferences"])');
    };
    const set = async (values) => {
        const form = await prefs();
        await fillForm(form, values);
        await submit(form, 'edit_your_user_preferences');
    };
    const form = await prefs();
    const before = {
        mit_look: await form.locator('select[name="mit_look"]').inputValue(),
        dark_mode: await form.locator('input[name="dark_mode"]:checked').inputValue(),
    };
    try {
        await set({ mit_look: 'modern', dark_mode: '0' });
        await visit(page, 'agent/tickets.php');
        await expect(page.locator('link[href*="/css/mit_theme.css"]')).toHaveCount(1);
        // The current page's menu item is the solid blue pill, the top bar is white with light menus
        await expect(page.locator('.app-sidebar .nav-link.active').first()).toHaveCSS('background-color', 'rgb(47, 111, 222)');
        await expect(page.locator('.app-header')).toHaveAttribute('data-bs-theme', 'light');
        await expect(page.locator('.app-header.navbar')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
        // A status keeps its own colour as a tint
        await expect(page.locator('.badge[style*="background"], .badge[style*="--itflow-status-bg"]').first()).toHaveClass(/mit-tone/);

        // A client's lists open with count tiles that link to what they count
        for (const [list, first] of [['assets', 'Assets'], ['software', 'Licenses'], ['tickets', 'Open']]) {
            await visit(page, `agent/${list}.php?client_id=1`);
            await expect(page.locator('.mit-tiles .mit-tile')).not.toHaveCount(0);
            await expect(page.locator('.mit-tiles .mit-tile-label').first()).toHaveText(first);
        }
        await expect(page.locator('.mit-tiles a.mit-tile[href*="show=overdue"]')).toHaveCount(1);
        await expect(page.locator('.itflow-client-head .mit-client-meta')).toHaveCount(1);
        // The client overview's Financial boxes keep label / amount / note stacked, the amount on one line
        await visit(page, 'agent/client_overview.php?client_id=1');
        const money = page.locator('.itflow-ov-stats .itflow-dash-rail-item').first();
        if (await money.count()) {
            await expect(money).toHaveCSS('flex-direction', 'column');
            await expect(money.locator('.itflow-dash-money-value')).toHaveCSS('white-space', 'nowrap');
        }

        // Quotes and invoices: ITFlow 26.11 draws them as a document itself (includes/billing_paper.php) - nothing of ours to check

        // Dark: same look on dark panels, the top bar stays dark
        await set({ dark_mode: '1' });
        await visit(page, 'agent/assets.php?client_id=1');
        await expect(page.locator('link[href*="/css/mit_theme.css"]')).toHaveCount(1);
        await expect(page.locator('.app-header')).toHaveAttribute('data-bs-theme', 'dark');
        await expect(page.locator('.mit-tile').first()).toHaveCSS('background-color', 'rgb(23, 33, 49)');

        await set({ mit_look: 'classic', dark_mode: '0' });
        await visit(page, 'agent/assets.php?client_id=1');
        await expect(page.locator('link[href*="/css/mit_theme.css"]')).toHaveCount(0);
        await expect(page.locator('.mit-tiles')).toHaveCount(0);
        await expect(page.locator('.app-header')).toHaveAttribute('data-bs-theme', 'dark');
    } finally {
        await set(before);
    }
});

test('sign-in page: the dark backdrop with its network art loads, and the skip links stay hidden', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    const page = await context.newPage();
    const refused = [];
    page.on('console', (m) => { if (m.type() === 'error') refused.push(m.text()); });
    try {
        for (const url of ['login.php', 'client/login_reset.php']) {
            await page.goto(url);
            await expect(page.locator('link[href*="/css/mit_login.css"]')).toHaveCount(1);
            await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(11, 18, 32)');
            await expect(page.locator('.login-page .card').first()).toHaveCSS('border-top-left-radius', '18px');
        }
        await page.goto('login.php');
        // The art is a file of its own (the page's security policy refuses data: images)
        expect(await page.evaluate(() => fetch('/css/mit_login_nodes.svg').then((r) => r.status))).toBe(200);
        expect(await page.evaluate(() => getComputedStyle(document.body, '::after').backgroundImage)).toContain('mit_login_nodes.svg');
        // AdminLTE's "Skip to" links show only when tabbed to
        const skip = await page.locator('.skip-links a').first().boundingBox();
        expect(!skip || skip.y + skip.height <= 0 || skip.x + skip.width <= 0 || skip.width <= 1).toBeTruthy();
        expect(refused.filter((t) => /Content Security Policy|mit_login/.test(t))).toEqual([]);
    } finally {
        await context.close();
    }
});

test('modern look follows the company theme colour (Admin > Settings > Theme), and the Theme page lays out', async ({ page }) => {
    // ITFlow 26.11's Theme page: pick a colour, then Save
    const pick = async (colour) => {
        await visit(page, 'admin/settings_theme.php');
        await page.locator(`label[for="theme-${colour}"]`).click();
        await submit(page.locator('form[data-settings-theme-form]'), 'edit_theme_settings');
    };
    await visit(page, 'admin/settings_theme.php');
    const before = await page.locator('input[name="config_theme"]:checked').getAttribute('value');
    test.skip(await page.locator('link[href*="/css/mit_theme.css"]').count() === 0, 'the modern look is off for this login');
    try {
        // Each colour's name sits under its preview on one line (ITFlow's Preferences swatch rule no longer shrinks them)
        const name = page.locator('label[for="theme-lightblue"] .itflow-theme-name');
        expect((await name.boundingBox()).width).toBeGreaterThan(50);

        await pick('red');
        await visit(page, 'agent/tickets.php');
        await expect(page.locator('.app-sidebar .nav-link.active').first()).toHaveCSS('background-color', 'rgb(220, 53, 69)');
        await expect(page.locator('.app-header.navbar')).toHaveCSS('border-top-color', 'rgb(220, 53, 69)');

        await pick('yellow');
        await visit(page, 'agent/tickets.php');
        // Dark text on the yellow pill
        await expect(page.locator('.app-sidebar .nav-link.active').first()).toHaveCSS('color', 'rgb(31, 41, 55)');
    } finally {
        await pick(before || 'blue');
    }
});
