// Timesheet permissions end to end, as real logins sending the real requests: the Technician (own timesheet only, California),
// a Timesheets View login (sees, can't change or approve), Edit (corrects, can't approve), and the own-sheet locks (submitted,
// approved). Needs ITFLOW_TECH_EMAIL / _PASSWORD and the mysql CLI on this machine (ITFLOW_DB, default itflow) - the
// Accountant login's password and its role's Timesheets level are set for the test and put back after.

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { visit, csrf } = require('./helpers');

const DB = process.env.ITFLOW_DB || 'itflow';
const sql = (q) => execSync(`mysql -N -B ${DB}`, { input: q }).toString().trim();
const techState = path.join(__dirname, '.auth', 'tech.json');
test.skip(!fs.existsSync(techState), 'no technician login');

const TECH = Number(sql("SELECT user_id FROM users WHERE user_email = '" + process.env.ITFLOW_TECH_EMAIL + "'"));
const ACCT_EMAIL = 'acct@test.test';
const ACCT = Number(sql(`SELECT user_id FROM users WHERE user_email = '${ACCT_EMAIL}'`) || 0);
const ACCT_PW = 'Acct-Smoke-70!';
const MODULE = Number(sql("SELECT module_id FROM modules WHERE module_name = 'module_timesheets'"));

// The current pay period's start (semi-monthly) and a past day in it
function currentStart() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${d.getDate() <= 15 ? '01' : '16'}`;
}

async function saveDay(page, fields) {
    return page.evaluate(async (fields) => {
        const r = await fetch('/agent/mit_timesheet_ajax.php', { method: 'POST', body: new URLSearchParams(fields), credentials: 'same-origin' });
        return { status: r.status, text: await r.text() };
    }, fields);
}
async function post(page, fields) {
    return page.evaluate(async (fields) => {
        const r = await fetch('/agent/post.php', { method: 'POST', body: new URLSearchParams(fields), credentials: 'same-origin' });
        return r.status;
    }, fields);
}

test.describe('technician (own timesheet only)', () => {
    test.use({ storageState: techState });

    test('cannot open, change or approve other people\'s timesheets, nor approve / reopen their own', async ({ page }) => {
        const start = currentStart();
        await visit(page, 'agent/mit_timesheet.php');
        const token = await csrf(page);
        // Someone they do not approve: the page falls back to their own
        await visit(page, `agent/mit_timesheet.php?user_id=${ACCT}&start=${start}`);
        await expect(page.locator('select[aria-label=Person]')).toHaveValue(String(TECH));
        const other = await saveDay(page, { save_day: '1', csrf_token: token, user_id: String(ACCT), start, date: start, 'cells[1]': '1' });
        expect(other.status, 'save_day on someone else\'s timesheet').toBe(403);
        expect(Number(sql(`SELECT COUNT(*) FROM mit_time_entries WHERE user_id = ${ACCT} AND entry_date = '${start}' AND code_id = 1 AND minutes = 60`))).toBe(0);

        // Their own approved period: locked
        const approved = sql(`SELECT period_start FROM mit_timesheets WHERE user_id = ${TECH} AND status = 'approved' ORDER BY period_start DESC LIMIT 1`);
        if (approved) {
            const r = await saveDay(page, { save_day: '1', csrf_token: token, user_id: String(TECH), start: approved, date: approved, 'cells[1]': '1' });
            expect(r.status, 'save_day on an approved period').toBe(403);
            const sheet = sql(`SELECT sheet_id FROM mit_timesheets WHERE user_id = ${TECH} AND period_start = '${approved}'`);
            await page.evaluate((u) => fetch(u, { credentials: 'same-origin' }), `/agent/post.php?mit_ts_reopen=${sheet}&csrf_token=${encodeURIComponent(token)}`);
            expect(sql(`SELECT status FROM mit_timesheets WHERE sheet_id = ${sheet}`), 'a technician reopened their own approved timesheet').toBe('approved');
        }
        // Approve their own
        await visit(page, 'agent/mit_timesheet.php');
        await post(page, { mit_ts_decide: '1', csrf_token: token, user_id: String(TECH), start, decision: 'approve' });
        expect(sql(`SELECT COALESCE(MAX(status), 'none') FROM mit_timesheets WHERE user_id = ${TECH} AND period_start = '${start}'`)).not.toBe('approved');
        // The payroll report and the team page's payroll are not theirs
        await page.goto('agent/reports/mit_payroll.php');
        expect(await page.locator('body').innerText()).not.toMatch(/Paid hours/);
    });

    test('own submitted timesheet: locked until taken back', async ({ page }) => {
        const start = currentStart();
        await visit(page, 'agent/mit_timesheet.php');
        const token = await csrf(page);
        const row = sql(`SELECT CONCAT(sheet_id, ',', status) FROM mit_timesheets WHERE user_id = ${TECH} AND period_start = '${start}'`);
        test.skip(!row || !/,open$/.test(row), 'the technician has no open timesheet this period');
        const sheet = row.split(',')[0];
        sql(`UPDATE mit_timesheets SET status = 'submitted', submitted_at = NOW() WHERE sheet_id = ${sheet}`);
        try {
            const r = await saveDay(page, { save_day: '1', csrf_token: token, user_id: String(TECH), start, date: start, 'cells[1]': '1' });
            expect(r.status, 'save on a submitted timesheet').toBe(403);
            expect(r.text).toMatch(/Submitted for approval/);
            await page.evaluate((u) => fetch(u, { credentials: 'same-origin' }), `/agent/post.php?mit_ts_withdraw=${sheet}&csrf_token=${encodeURIComponent(token)}`);
            expect(sql(`SELECT status FROM mit_timesheets WHERE sheet_id = ${sheet}`)).toBe('open');
        } finally {
            sql(`UPDATE mit_timesheets SET status = 'open', submitted_at = NULL WHERE sheet_id = ${sheet}`);
        }
    });
});

test.describe('Timesheets View and Edit (the Accountant role)', () => {
    test.skip(!ACCT, 'no Accountant login on this install');
    let saved;
    test.beforeAll(() => {
        saved = sql(`SELECT CONCAT_WS('|', user_password, user_status, COALESCE((SELECT user_role_permission_level FROM user_role_permissions WHERE user_role_id = 1 AND module_id = ${MODULE}), -1)) FROM users WHERE user_id = ${ACCT}`).split('|');
        const hash = execSync(`php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' '${ACCT_PW}'`).toString();
        sql(`UPDATE users SET user_password = '${hash}', user_status = 1 WHERE user_id = ${ACCT}`);
    });
    test.afterAll(() => {
        sql(`UPDATE users SET user_password = '${saved[0].replace(/'/g, "''")}', user_status = ${saved[1]} WHERE user_id = ${ACCT}`);
        const level = Number(saved[2]);
        sql(level < 0 ? `DELETE FROM user_role_permissions WHERE user_role_id = 1 AND module_id = ${MODULE}`
            : `UPDATE user_role_permissions SET user_role_permission_level = ${level} WHERE user_role_id = 1 AND module_id = ${MODULE}`);
    });
    const setLevel = (n) => {
        if (!Number(sql(`SELECT COUNT(*) FROM user_role_permissions WHERE user_role_id = 1 AND module_id = ${MODULE}`))) {
            sql(`INSERT INTO user_role_permissions SET user_role_id = 1, module_id = ${MODULE}, user_role_permission_level = ${n}`);
        }
        sql(`UPDATE user_role_permissions SET user_role_permission_level = ${n} WHERE user_role_id = 1 AND module_id = ${MODULE}`);
    };
    async function login(browser) {
        const use = test.info().project.use;
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: use.baseURL });
        const page = await context.newPage();
        await page.goto('login.php');
        await page.fill('input[name=email]', ACCT_EMAIL);
        await page.fill('input[name=password]', ACCT_PW);
        await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
        expect(page.url()).not.toMatch(/login\.php/);
        return { context, page };
    }

    test('View: sees the technician\'s timesheet and the payroll report, cannot change or approve', async ({ browser }) => {
        setLevel(1);
        const { context, page } = await login(browser);
        const start = currentStart();
        await visit(page, `agent/mit_timesheet.php?user_id=${TECH}&start=${start}`);
        await expect(page.locator('select[aria-label=Person]')).toHaveValue(String(TECH));
        const token = await csrf(page);
        const r = await saveDay(page, { save_day: '1', csrf_token: token, user_id: String(TECH), start, date: start, 'cells[1]': '7' });
        expect(r.status, 'View changed someone\'s hours').toBe(403);
        await post(page, { mit_ts_decide: '1', csrf_token: token, user_id: String(TECH), start, decision: 'approve' });
        expect(sql(`SELECT COALESCE(MAX(status), 'none') FROM mit_timesheets WHERE user_id = ${TECH} AND period_start = '${start}'`)).not.toBe('approved');
        await visit(page, `agent/reports/mit_payroll.php?period=custom&from=${start}&to=${start}`);
        await expect(page.locator('body')).toContainText('Paid hours');
        await context.close();
    });

    test('Edit: corrects the technician\'s hours (they are told), still cannot approve', async ({ browser }) => {
        setLevel(2);
        const { context, page } = await login(browser);
        const start = currentStart();
        const status = sql(`SELECT COALESCE(MAX(status), 'open') FROM mit_timesheets WHERE user_id = ${TECH} AND period_start = '${start}'`);
        test.skip(status !== 'open', 'technician sheet not open');
        const before = sql(`SELECT COALESCE(MAX(minutes), 0) FROM mit_time_entries WHERE user_id = ${TECH} AND entry_date = '${start}' AND code_id = 1 AND source = 'manual'`);
        await visit(page, `agent/mit_timesheet.php?user_id=${TECH}&start=${start}`);
        const token = await csrf(page);
        const notesBefore = Number(sql(`SELECT COUNT(*) FROM notifications WHERE notification_user_id = ${TECH} AND notification_type = 'Timesheet'`));
        try {
            const r = await saveDay(page, { save_day: '1', csrf_token: token, user_id: String(TECH), start, date: start, 'cells[1]': '0:15' });
            expect(r.status, r.text).toBe(200);
            expect(JSON.parse(r.text).ok, r.text).toBe(true);
            expect(sql(`SELECT minutes FROM mit_time_entries WHERE user_id = ${TECH} AND entry_date = '${start}' AND code_id = 1 AND source = 'manual'`)).toBe('15');
            expect(Number(sql(`SELECT COUNT(*) FROM notifications WHERE notification_user_id = ${TECH} AND notification_type = 'Timesheet'`)), 'the person is told').toBeGreaterThanOrEqual(notesBefore + (notesBefore ? 0 : 1));
            await post(page, { mit_ts_decide: '1', csrf_token: token, user_id: String(TECH), start, decision: 'approve' });
            expect(sql(`SELECT COALESCE(MAX(status), 'none') FROM mit_timesheets WHERE user_id = ${TECH} AND period_start = '${start}'`)).not.toBe('approved');
        } finally {
            sql(Number(before) ? `UPDATE mit_time_entries SET minutes = ${before} WHERE user_id = ${TECH} AND entry_date = '${start}' AND code_id = 1 AND source = 'manual'`
                : `DELETE FROM mit_time_entries WHERE user_id = ${TECH} AND entry_date = '${start}' AND code_id = 1 AND source = 'manual'`);
            await context.close();
        }
    });
});
