// Admin > Timesheets tabs and every timesheet window open cleanly (no PHP warnings in the page or the log), and a charge code that
// approved timesheets used cannot change its kind / paid (the payroll they were approved with would change). Uses the mysql CLI.

const fs = require('fs');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { visit, openModal, csrf } = require('./helpers');

const DB = process.env.ITFLOW_DB || 'itflow';
const sql = (q) => execSync(`mysql -N -B ${DB}`, { input: q }).toString().trim();
const LOG = process.env.ITFLOW_PHP_LOG;
const logSize = () => (LOG && fs.existsSync(LOG) ? fs.statSync(LOG).size : 0);
// Open an ajax modal (forms or not) and check it
async function open(page, url) {
    await page.evaluate((url) => {
        const b = document.createElement('button');
        b.className = 'ajax-modal d-none';
        b.dataset.modalUrl = url;
        document.body.appendChild(b);
        b.click();
    }, url);
    const modal = page.locator('.modal.show').last();
    await modal.locator('.modal-body, form').first().waitFor({ state: 'attached', timeout: 15000 });
    const text = await modal.innerText();
    expect(text, url).not.toMatch(/Warning:|Notice:|Fatal error|Uncaught|does not exist|not yours/);
    await page.evaluate(() => document.querySelectorAll('.modal.show').forEach((m) => window.bootstrap?.Modal.getInstance(m)?.hide()));
    await page.waitForTimeout(400);
}
const logSince = (from) => (LOG && fs.existsSync(LOG) ? fs.readFileSync(LOG).subarray(Math.min(from, logSize())).toString().split('\n').filter((l) => /PHP (Warning|Notice|Fatal|Deprecated)/.test(l)) : []);

test('every Admin > Timesheets tab and window opens cleanly', async ({ page }) => {
    const from = logSize();
    const tech = sql("SELECT user_id FROM users WHERE user_email = '" + process.env.ITFLOW_TECH_EMAIL + "'");
    const bank = sql('SELECT MIN(bank_id) FROM mit_leave_banks WHERE bank_archived_at IS NULL');
    const code = sql('SELECT MIN(code_id) FROM mit_charge_codes');
    const holiday = sql('SELECT COALESCE(MIN(holiday_id), 0) FROM business_holidays');
    for (const tab of ['settings', 'codes', 'leave', 'people', 'holidays']) {
        await visit(page, `admin/mit_timesheets.php?tab=${tab}`);
    }
    await visit(page, 'admin/mit_timesheets.php?tab=codes');
    for (const url of [`modals/mit_timesheets/charge_code.php?id=${code}`, 'modals/mit_timesheets/charge_code.php', `modals/mit_timesheets/leave_bank.php?id=${bank}`,
        'modals/mit_timesheets/leave_bank.php', `modals/mit_timesheets/person.php?id=${tech}`, `modals/mit_timesheets/person_leave.php?id=${tech}`,
        `modals/mit_timesheets/holiday.php?id=${holiday}`, 'modals/mit_timesheets/holiday.php?year=2026']) {
        await open(page, url);
    }
    const start = sql(`SELECT period_start FROM mit_timesheets WHERE user_id = ${tech} ORDER BY period_start DESC LIMIT 1`);
    await visit(page, `agent/mit_timesheet.php?user_id=${tech}&start=${start}`);
    for (const url of [`modals/mit_timesheet/leave.php?user_id=${tech}&bank_id=${bank}`, `modals/mit_timesheet/decide.php?user_id=${tech}&start=${start}&decision=return`,
        `modals/mit_timesheet/day.php?user_id=${tech}&date=${start}`]) {
        await open(page, url);
    }
    expect(logSince(from), 'PHP log').toEqual([]);
});

test('a charge code used on approved timesheets keeps its kind and paid', async ({ page }) => {
    const code = sql("SELECT e.code_id FROM mit_time_entries e JOIN mit_timesheets s ON s.user_id = e.user_id AND e.entry_date BETWEEN s.period_start AND s.period_end AND s.status = 'approved' JOIN mit_charge_codes c ON c.code_id = e.code_id WHERE e.source = 'manual' AND e.minutes > 0 AND c.code_kind = 'work' AND c.code_paid = 1 LIMIT 1");
    test.skip(!code, 'no code used on an approved timesheet');
    await visit(page, 'admin/mit_timesheets.php?tab=codes');
    const modal = await openModal(page, `modals/mit_timesheets/charge_code.php?id=${code}`);
    const fields = await modal.locator('form').first().evaluate((f) => [...new FormData(f).entries()]);
    const submitName = await modal.locator('form button[type=submit][name]').first().getAttribute('name');
    const body = fields.filter(([k]) => k !== 'paid');
    body.push([submitName, '1']);
    await page.evaluate(async (body) => fetch('/admin/post.php', { method: 'POST', body: new URLSearchParams(body), credentials: 'same-origin' }), body);
    const paid = sql(`SELECT code_paid FROM mit_charge_codes WHERE code_id = ${code}`);
    if (paid !== '1') {
        sql(`UPDATE mit_charge_codes SET code_paid = 1 WHERE code_id = ${code}`);
    }
    expect(paid, 'a code on approved timesheets was switched to unpaid').toBe('1');
});
