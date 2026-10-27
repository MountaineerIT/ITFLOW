// A pay period worked out by hand (../scenarios/fixture-hourly.php - Aug 1-15 2026, hourly, not California): every figure on the
// timesheet page, the team list and both payroll CSVs (summary and detail) checked against the arithmetic. Needs php + the
// scenarios folder next to this one (ITFLOW_ROOT the install).

const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { visit } = require('./helpers');

const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';
const FIXTURE = path.join(__dirname, '..', 'scenarios', 'fixture-hourly.php');
const fixture = (mode) => execSync(`php ${FIXTURE} ${ROOT} ${mode}`).toString().trim();

function parseCsv(text) {
    const rows = [];
    let row = [], field = '', quoted = false;
    for (let i = 0; i < text.length; i++) {
        const c = text[i];
        if (quoted) {
            if (c === '"' && text[i + 1] === '"') { field += '"'; i++; } else if (c === '"') { quoted = false; } else { field += c; }
        } else if (c === '"') { quoted = true; } else if (c === ',') { row.push(field); field = ''; } else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; } else if (c !== '\r') { field += c; }
    }
    if (field !== '' || row.length) { row.push(field); rows.push(row); }
    const head = rows.shift().map((h) => h.replace(/^﻿/, ''));
    return rows.filter((r) => r.length > 1).map((r) => Object.fromEntries(head.map((h, i) => [h, r[i]])));
}
async function getText(page, url) {
    return page.evaluate(async (url) => (await fetch(url, { credentials: 'same-origin' })).text(), url);
}

let made;
test.beforeAll(() => {
    made = JSON.parse(fixture('setup').split('\n').pop());
});
test.afterAll(() => {
    fixture('teardown');
});

test('timesheet page and team list add up to the hand-worked figures', async ({ page }) => {
    await visit(page, `agent/mit_timesheet.php?user_id=${made.user_id}&start=2026-08-01`);
    const stats = await page.locator('.mit-ts-stat').evaluateAll((els) => Object.fromEntries(els.map((e) => [e.querySelector('.mit-ts-stat-label').textContent.trim(), e.querySelector('.mit-ts-stat-value').textContent.trim()])));
    expect(stats['Total hours']).toBe('87:00');
    expect(stats['Ticket time']).toBe('8:00');
    expect(stats['Overtime']).toBe('5:00');
    expect(stats['Lost time']).toBe('10:00');
    expect(stats['Leave']).toBe('16:00');

    await visit(page, 'agent/mit_timesheets.php?start=2026-08-01');
    const row = page.locator('tr', { hasText: 'ZZ Fixture Hourly' });
    const col = async (k) => (await row.locator(`[data-mit-ts-col=${k}]`).innerText()).trim();
    expect(await col('billable')).toBe('8:00');
    expect(await col('work')).toBe('53:00');
    expect(await col('lost')).toBe('10:00');
    expect(await col('leave')).toBe('16:00');
    expect(await col('total')).toBe('87:00');
    expect(await col('share')).toBe('11%');
});

test('payroll summary CSV: one row with every column right', async ({ page }) => {
    await visit(page, 'agent/reports/mit_payroll.php');
    const csv = parseCsv(await getText(page, `/agent/reports/mit_payroll.php?period=custom&from=2026-08-01&to=2026-08-15&agent=${made.user_id}&export=csv`));
    const r = csv.find((x) => x.Person === 'ZZ Fixture Hourly');
    expect(r, JSON.stringify(csv.slice(0, 2))).toBeTruthy();
    const pick = (re) => {
        const key = Object.keys(r).find((k) => re.test(k));
        expect(key, `column ${re} in ${Object.keys(r).join(', ')}`).toBeTruthy();
        return Number(r[key]);
    };
    expect(r['Employee ID']).toBe('ZZ-71');
    expect(r['Pay type']).toBe('Hourly');
    expect(pick(/^Billable \(hours\)|^Billable$|^Billable hours/)).toBe(8);
    expect(pick(/^Admin/)).toBe(51);
    expect(pick(/^Training/)).toBe(2);
    expect(pick(/^PTO/)).toBe(8);
    expect(pick(/^Unpaid leave/)).toBe(8);
    expect(pick(/^Lunch/)).toBe(1);
    expect(pick(/^Unaccounted/)).toBe(10);
    expect(pick(/^Worked/)).toBe(71);
    expect(pick(/^Leave/)).toBe(16);
    expect(pick(/^Unpaid \(|^Unpaid$|^Unpaid hours/)).toBe(18);
    expect(pick(/^Paid hours/)).toBe(69);
    expect(pick(/^Regular/)).toBe(64);
    expect(pick(/^Overtime/)).toBe(5);
    expect(pick(/^Billable %/)).toBeCloseTo(11.3, 1);
    // PTO left at the end: 10 h adjustment - 8 h used (nothing earned - not approved)
    expect(pick(/^PTO left/)).toBe(2);
    expect(Object.keys(r).filter((k) => /premium/i.test(k)), 'no premium columns for someone not on California rules').toEqual([]);
});

test('payroll detail CSV: the rows of each day add up to the summary', async ({ page }) => {
    await visit(page, 'agent/reports/mit_payroll.php');
    const csv = parseCsv(await getText(page, `/agent/reports/mit_payroll.php?period=custom&from=2026-08-01&to=2026-08-15&agent=${made.user_id}&export=csv&table=detail`));
    const mine = csv.filter((x) => x.Person === 'ZZ Fixture Hourly');
    const hoursKey = Object.keys(mine[0]).find((k) => /^Hours/.test(k));
    const sum = (f) => mine.filter(f).reduce((s, x) => s + Number(x[hoursKey] || 0), 0);
    expect(sum((x) => x['Charge code'] === 'Ticket time - billable')).toBe(8);
    expect(sum((x) => x['Charge code'] === 'Unaccounted (lost)')).toBe(10);
    expect(sum((x) => x['Charge code'] === 'Admin')).toBe(51);
    expect(sum((x) => x.Paid === 'Yes')).toBe(8 + 51 + 2 + 8); // tickets + work codes + paid leave = 69 paid
    expect(sum((x) => x.Paid === 'No')).toBe(10 + 8 + 1);      // unaccounted + unpaid leave + lunch
    // The note worked on the evening of Aug 12 (posted Aug 13) is on Aug 12
    expect(mine.filter((x) => x.Date === '2026-08-12' && x['Charge code'] === 'Ticket time - billable').map((x) => Number(x[hoursKey]))).toEqual([1]);
    expect(mine.filter((x) => x.Date === '2026-08-13' && /Ticket time/.test(x['Charge code']))).toEqual([]);
    // Not California: the missed rest break is a record (no hours, no premium)
    const rest = mine.filter((x) => x['Charge code'] === 'Rest break');
    expect(rest.map((x) => [x.Date, x[hoursKey]])).toEqual([['2026-08-05', '']]);
});
