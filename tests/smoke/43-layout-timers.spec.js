// The ticket page layout (functions/mit_ticket_layout.php + agent/js/mit_ticket_layout.js) and the ticket timers in the top bar
// (includes/mit_timers_nav.php + js/mit_timers.js), checked as a person uses them and in what is stored:
//  - Customize layout from the ticket's ⋮ menu: three columns, a box half width and one starting collapsed, "Save for me" -> the
//    tech's mit_user_preferences ticket.layout, put back on the next visit; "Use company default" forgets it; an admin's company
//    default applies to the technician too; the technician can't save one.
//  - Timers: ITFlow's own localStorage keys from two tickets (one running, one paused) and one the agent can't see are listed with
//    the tickets' references, paused / resumed / discarded through those same keys.
// A client and two tickets of its own; layouts and settings put back at the end.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const made = { clientId: 0, tickets: [], refs: [], defaultLayout: null, mine: null };
const ticketPage = (i = 0) => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.tickets[i]}`;
const pref = (user) => db.value(`SELECT value FROM mit_user_preferences WHERE user_id = ${user} AND preference = 'ticket.layout'`);

test('layout and timers: setup', async () => {
    const name = smokeName('t1 layout client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    for (const n of [1, 2]) {
        const number = Number(db.value('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
        db.run(`UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1`);
        db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_source = 'Agent', ticket_subject = ${db.q(`${smokeName('t1 layout ticket')} ${n}`)},
            ticket_details = '<p>Layout test</p>', ticket_priority = 'Low', ticket_status = 1, ticket_created_by = 1, ticket_assigned_to = 1, ticket_url_key = 'x${Date.now()}${n}', ticket_client_id = ${made.clientId}`);
        made.tickets.push(Number(db.value(`SELECT MAX(ticket_id) FROM tickets WHERE ticket_client_id = ${made.clientId}`)));
        made.refs.push(`TCK-${number}`);
    }
    made.defaultLayout = db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'ticket_layout'");
    made.mine = pref(1);
});

test('layout: Customize layout, Save for me, and back to the company default', async ({ page }) => {
    const errors = watch(page);
    await visit(page, ticketPage(), errors);
    await page.locator('.ticket-layout-header .dropdown:not(.mit-no-response) > [data-bs-toggle="dropdown"]').first().click();
    await page.locator('.dropdown-item', { hasText: 'Customize layout' }).click();
    const bar = page.locator('.mit-layout-toolbar');
    await expect(bar).toBeVisible();
    await bar.locator('select').selectOption('three');
    const boxes = await page.locator('[data-mit-box]').evaluateAll((b) => b.map((x) => x.dataset.mitBox));
    expect(boxes, 'the details box is known').toContain('details');
    await page.locator('[data-mit-box="details"] [data-mit-opt="half"]').check();
    const collapsible = await page.locator('[data-mit-box] [data-mit-opt="collapsed"]').first().evaluate((el) => el.closest('[data-mit-box]').dataset.mitBox);
    await page.locator(`[data-mit-box="${collapsible}"] [data-mit-opt="collapsed"]`).check();
    await Promise.all([page.waitForNavigation(), bar.locator('button', { hasText: 'Save for me' }).click()]);
    const saved = JSON.parse(pref(1) || 'null');
    expect(saved && saved.columns, 'saved for the admin').toBe('three');
    expect(saved.half).toContain('details');
    expect(saved.collapsed).toContain(collapsible);

    await visit(page, ticketPage(1), errors); // another ticket: the same layout
    await page.waitForTimeout(400);
    await expect(page.locator('.ticket-layout.mit-cols-three'), 'three columns put back').toHaveCount(1);
    await expect(page.locator('[data-mit-box="details"].mit-half')).toHaveCount(1);
    await expect(page.locator(`[data-mit-box="${collapsible}"].collapsed-card`), 'starts collapsed').toHaveCount(1);

    // Use company default: forgotten
    await page.locator('.ticket-layout-header .dropdown:not(.mit-no-response) > [data-bs-toggle="dropdown"]').first().click();
    await page.locator('.dropdown-item', { hasText: 'Customize layout' }).click();
    await Promise.all([page.waitForNavigation(), page.locator('.mit-layout-toolbar button', { hasText: 'Use company default' }).click()]);
    expect(pref(1), 'the admin\'s own layout gone').toBeNull();
    await expectClean(page, errors);
});

test('layout: a company default reaches the technician, who cannot set one', async ({ page, browser }) => {
    await visit(page, ticketPage());
    const csrf = await page.evaluate(() => window.mitTicketLayout.csrf);
    const post = (p, body) => p.evaluate(async (body) => {
        const r = await fetch('/agent/ajax.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) });
        return { status: r.status, json: await r.json().catch(() => null) };
    }, body);
    const layout = JSON.stringify({ columns: 'even', main: ['details'], side: [], left: [], collapsed: [], half: [] });
    expect((await post(page, { mit_ticket_layout: 1, csrf_token: csrf, scope: 'default', layout })).status).toBe(200);
    expect(JSON.parse(db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'ticket_layout'")).columns).toBe('even');

    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: path.join(__dirname, '.auth', 'tech.json'), baseURL: use.baseURL });
    const tech = await context.newPage();
    try {
        await visit(tech, ticketPage());
        await tech.waitForTimeout(400);
        await expect(tech.locator('.ticket-layout.mit-cols-even'), 'the technician gets the company default').toHaveCount(1);
        const techCsrf = await tech.evaluate(() => window.mitTicketLayout.csrf);
        const refused = await post(tech, { mit_ticket_layout: 1, csrf_token: techCsrf, scope: 'default', layout: JSON.stringify({ columns: 'single', main: ['details'] }) });
        expect(refused.status, 'a technician cannot change the company default').toBe(403);
        expect(JSON.parse(db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'ticket_layout'")).columns).toBe('even');
    } finally {
        await context.close();
    }
});

test('timers: the top bar lists this browser\'s ticket timers and works their keys', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    const [a, b] = made.tickets.map(String);
    await page.evaluate(([a, b]) => {
        for (const k of Object.keys(localStorage)) {
            if (/^\d+-(startTime|pausedTime)$|^ticket-timer-running-/.test(k)) {
                localStorage.removeItem(k);
            }
        }
        localStorage.setItem(`${a}-startTime`, String(Date.now() - 95 * 1000)); // running 1:35
        localStorage.setItem(`${b}-pausedTime`, '300'); // paused at 5:00
        localStorage.setItem('99999999-pausedTime', '60'); // a ticket that is gone
    }, [a, b]);
    await visit(page, 'agent/dashboard.php', errors);
    const nav = page.locator('#mitTimers');
    await expect(nav, 'shown once there are timers').toBeVisible();
    await expect(nav.locator('[data-timers-badge]')).toHaveText('3');
    await expect(nav, 'running look').toHaveClass(/is-running/);
    await nav.locator('.nav-link').click();
    const row = (id) => nav.locator(`[data-timer="${id}"]`);
    await expect(row(a).locator('a'), 'named from the ticket').toContainText(made.refs[0]);
    await expect(row(a).locator('a')).toHaveAttribute('href', `/agent/ticket.php?client_id=${made.clientId}&ticket_id=${a}`);
    await expect(row(b).locator('[data-timer-time]')).toHaveText('0:05:00');
    await expect(row('99999999')).toContainText('Deleted, or not one you can see');
    expect(await nav.locator('[data-timer]').evaluateAll((r) => r.map((x) => x.dataset.timer)), 'running first, then most time').toEqual([a, b, '99999999']);

    await row(a).locator('button[title=Pause]').click();
    const keys = () => page.evaluate(([a, b]) => ({ aStart: localStorage.getItem(`${a}-startTime`), aPaused: localStorage.getItem(`${a}-pausedTime`), bStart: localStorage.getItem(`${b}-startTime`), bPaused: localStorage.getItem(`${b}-pausedTime`), gone: localStorage.getItem('99999999-pausedTime') }), [a, b]);
    let k = await keys();
    expect(k.aStart, 'paused: not counting').toBeNull();
    expect(Number(k.aPaused), 'paused with its time').toBeGreaterThanOrEqual(95);
    await row(b).locator('button[title=Resume]').click();
    k = await keys();
    expect(Number(k.bStart), 'resumed: counting from now').toBeGreaterThan(Date.now() - 10000);
    expect(k.bPaused, 'with its 5 minutes kept').toBe('300');
    page.once('dialog', (d) => d.accept());
    await row('99999999').locator('button[title="Discard this timer"]').click();
    expect((await keys()).gone, 'discarded').toBeNull();

    // On the ticket page ITFlow's own timer shows the time the list kept
    await visit(page, ticketPage(1), errors);
    const shown = Number(await page.locator('#minutes').inputValue());
    expect(shown, 'the ticket page carries on from 5 minutes').toBeGreaterThanOrEqual(5);
    await page.evaluate(([a, b]) => [a, b].forEach((id) => ['startTime', 'pausedTime'].forEach((s) => localStorage.removeItem(`${id}-${s}`))), [a, b]);
    await expectClean(page, errors);
});

test.afterAll(async () => {
    if (made.defaultLayout === null) {
        db.run("DELETE FROM mit_settings WHERE setting_name = 'ticket_layout'");
    } else {
        db.run(`UPDATE mit_settings SET setting_value = ${db.q(made.defaultLayout)} WHERE setting_name = 'ticket_layout'`);
    }
    db.run("DELETE FROM mit_user_preferences WHERE preference = 'ticket.layout' AND user_id IN (1, 2)");
    if (made.mine !== null) {
        db.run(`INSERT INTO mit_user_preferences SET user_id = 1, preference = 'ticket.layout', value = ${db.q(made.mine)}`);
    }
    const ids = made.tickets.join(',') || '0';
    db.run(`DELETE FROM ticket_replies WHERE ticket_reply_ticket_id IN (${ids})`);
    db.run(`DELETE FROM ticket_history WHERE ticket_history_ticket_id IN (${ids})`);
    db.run(`DELETE FROM mit_ticket_boards WHERE ticket_id IN (${ids})`);
    db.run(`DELETE FROM tickets WHERE ticket_id IN (${ids})`);
    if (made.clientId) {
        db.run(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
});
