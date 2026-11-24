// The ticket page layout (functions/mit_ticket_layout.php + agent/js/mit_ticket_layout.js), checked as a person uses it and in what is
// stored (the top bar's ticket timers are ITFlow's own since 26.12 - #itflowTimers):
//  - Customize layout from the ticket's ⋮ menu: three columns, a box half width and one starting collapsed, "Save for me" -> the
//    tech's mit_user_preferences ticket.layout, put back on the next visit; "Use company default" forgets it; an admin's company
//    default applies to the technician too; the technician can't save one.
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

test('layout: three columns - the new left column shows where to drop, takes boxes by drag, and keeps them', async ({ page }) => {
    const errors = watch(page);
    await page.setViewportSize({ width: 1280, height: 900 });
    await visit(page, ticketPage(), errors);
    await page.locator('.ticket-layout-header .dropdown:not(.mit-no-response) > [data-bs-toggle="dropdown"]').first().click();
    await page.locator('.dropdown-item', { hasText: 'Customize layout' }).click();
    const bar = page.locator('.mit-layout-toolbar');
    await bar.locator('select').selectOption('three');
    // The empty left column is a visible target (26.11.2.2 - it showed nothing at all, so there was nowhere to drop)
    const left = page.locator('.ticket-layout > .mit-layout-left');
    await expect(left).toBeVisible();
    expect(await left.evaluate((el) => getComputedStyle(el, '::before').content), 'the left column says where to drop').toContain('Drag boxes here');
    // Drag the Details box into it by its bar
    const handle = page.locator('[data-mit-box="details"] .mit-box-handle');
    // Let the columns settle after the switch (a drag started while they redraw only selected text)
    await handle.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    const from = await handle.boundingBox();
    await page.mouse.move(from.x + 10, from.y + 5);
    await page.mouse.down();
    await page.mouse.move(from.x + 30, from.y + 25, { steps: 5 });
    const to = await left.boundingBox();
    await page.mouse.move(to.x + to.width / 2, to.y + to.height / 2, { steps: 25 });
    await page.mouse.up();
    await expect(page.locator('.mit-layout-left > [data-mit-box="details"]'), 'Details dropped into the left column').toHaveCount(1);
    // Three columns share the width, the middle one the widest
    const widths = await page.evaluate(() => ['.mit-layout-left', '.ticket-layout-main', '.ticket-layout-side'].map((s) => document.querySelector(s).getBoundingClientRect().width));
    expect(widths[1], 'the middle column is the widest').toBeGreaterThan(widths[0]);
    await Promise.all([page.waitForNavigation(), bar.locator('button', { hasText: 'Save for me' }).click()]);
    expect(JSON.parse(pref(1) || 'null').left, 'saved in the left column').toContain('details');
    await expect(page.locator('.ticket-layout.mit-cols-three > .mit-layout-left > [data-mit-box="details"]'), 'still there after the reload').toHaveCount(1);
    db.run("DELETE FROM mit_user_preferences WHERE user_id = 1 AND preference = 'ticket.layout'");
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
