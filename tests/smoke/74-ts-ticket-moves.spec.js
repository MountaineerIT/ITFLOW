// ITFlow's own ticket actions must not change hours already worked on timesheets: moving a ticket to another client (ITFlow's
// "Change client" turns its public replies into internal notes) keeps the time its notes carry on everyone's timesheet - with
// Admin > Timesheets "count internal notes" off, as here. Uses ../scenarios/fixture-hourly.php (Aug 1-15 2026, 8 h of ticket time).

const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { visit, csrf } = require('./helpers');

const DB = process.env.ITFLOW_DB || 'itflow';
const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';
const FIXTURE = path.join(__dirname, '..', 'scenarios', 'fixture-hourly.php');
const sql = (q) => execSync(`mysql -N -B ${DB}`, { input: q }).toString().trim();

let made;
test.beforeAll(() => { made = JSON.parse(execSync(`php ${FIXTURE} ${ROOT} setup`).toString().trim().split('\n').pop()); });
test.afterAll(() => { execSync(`php ${FIXTURE} ${ROOT} teardown`); });

async function billable(page) {
    await visit(page, `agent/mit_timesheets.php?start=2026-08-01`);
    return (await page.locator('tr', { hasText: 'ZZ Fixture Hourly' }).locator('[data-mit-ts-col=billable]').innerText()).trim();
}

test('moving a ticket to another client keeps its time on the timesheets', async ({ page }) => {
    test.skip(sql("SELECT COALESCE(MAX(setting_value), '1') FROM mit_settings WHERE setting_name = 'ts_ticket_internal'") !== '0', 'internal notes count here anyway');
    const ticket = sql('SELECT ticket_id FROM tickets WHERE ticket_number = 990011');
    const from = sql(`SELECT ticket_client_id FROM tickets WHERE ticket_id = ${ticket}`);
    const to = sql(`SELECT client_id FROM clients WHERE client_archived_at IS NULL AND client_id <> ${from} ORDER BY client_id LIMIT 1`);
    expect(await billable(page)).toBe('8:00');
    const token = await csrf(page);
    const status = await page.evaluate(async (fields) => (await fetch('/agent/post.php', { method: 'POST', body: new URLSearchParams(fields), credentials: 'same-origin' })).status,
        { change_client_ticket: '1', csrf_token: token, ticket_id: ticket, new_client_id: to, new_contact_id: '0' });
    expect(status).toBeLessThan(400);
    expect(sql(`SELECT ticket_client_id FROM tickets WHERE ticket_id = ${ticket}`), 'the ticket moved').toBe(to);
    expect(await billable(page), 'ticket time worked in August vanished from the timesheet when the ticket changed client').toBe('8:00');
});
