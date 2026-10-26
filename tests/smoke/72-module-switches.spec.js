// Admin > Settings > Modules: our switches saved through ITFlow's own form, and "off" really closing the module everywhere -
// menu, pages, the timesheet's own saves, its post handlers, the report - then on again. Uses the mysql CLI for the before / after
// check (ITFLOW_DB, default itflow).

const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { visit, csrf } = require('./helpers');

const DB = process.env.ITFLOW_DB || 'itflow';
const sql = (q) => execSync(`mysql -N -B ${DB}`, { input: q }).toString().trim();
const setting = (name) => sql(`SELECT COALESCE(MAX(setting_value), '1') FROM mit_settings WHERE setting_name = '${name}'`);

async function saveModules(page, values) {
    await visit(page, 'admin/settings_module.php');
    const form = page.locator('form[data-settings-form]');
    for (const [name, on] of Object.entries(values)) {
        const box = form.locator(`input[type=checkbox][name="${name}"]`);
        if (on) { await box.check({ force: true }); } else { await box.uncheck({ force: true }); }
    }
    const fields = await form.evaluate((f) => [...new FormData(f).entries()]);
    // ITFlow's form posts with its own save button name
    const status = await page.evaluate(async (fields) => {
        const body = new URLSearchParams(fields);
        body.set('edit_module_settings', '1');
        return (await fetch('/admin/post.php', { method: 'POST', body, credentials: 'same-origin' })).status;
    }, fields);
    expect(status).toBeLessThan(400);
}

const fs = require('fs');
const LOG = process.env.ITFLOW_PHP_LOG;
const logSize = () => (LOG && fs.existsSync(LOG) ? fs.statSync(LOG).size : 0);

test('Timesheets switched off closes it everywhere, and on again opens it', async ({ page }) => {
    const before = setting('module_timesheets');
    const logFrom = logSize();
    test.skip(before === '0', 'Timesheets are off on this install');
    try {
        await saveModules(page, { mit_module_timesheets: false });
        expect(setting('module_timesheets'), 'the switch was not saved').toBe('0');
        // Other module switches untouched
        expect(setting('module_procurement')).toBe('1');

        await visit(page, 'agent/tickets.php');
        await expect(page.locator('.app-sidebar a[href*="mit_timesheet"]'), 'menu still shows Timesheets').toHaveCount(0);
        const token = await csrf(page);
        await page.goto('agent/mit_timesheet.php');
        await expect(page.locator('body')).toContainText('switched off');
        // The "switched off" page itself must not log PHP warnings
        if (LOG && fs.existsSync(LOG)) {
            const added = fs.readFileSync(LOG).subarray(Math.min(logFrom, logSize())).toString();
            expect(added.split('\n').filter((l) => /PHP (Warning|Notice|Fatal|Deprecated)/.test(l)).map((l) => l.replace(/^.*PHP /, '')), 'PHP log while Timesheets is off').toEqual([]);
        }
        const ajax = await page.evaluate(async (token) => {
            const r = await fetch('/agent/mit_timesheet_ajax.php', { method: 'POST', body: new URLSearchParams({ save_day: '1', csrf_token: token }), credentials: 'same-origin' });
            return r.status;
        }, token);
        expect(ajax).toBe(403);
        const report = await page.evaluate(async () => (await fetch('/agent/reports/mit_payroll.php', { credentials: 'same-origin' })).text());
        expect(report).not.toMatch(/Paid hours/);
        await page.goto('agent/reports/index.php').catch(() => {});
        expect(await page.locator('body').innerText()).not.toMatch(/Timesheets & Payroll/);
    } finally {
        await saveModules(page, { mit_module_timesheets: true });
    }
    expect(setting('module_timesheets')).toBe('1');
    await visit(page, 'agent/mit_timesheet.php');
    await expect(page.locator('body')).not.toContainText('switched off');
    await expect(page.locator('.app-sidebar a[href*="mit_timesheet"]').first()).toBeAttached();
});
