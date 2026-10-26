// California non-exempt timesheets (functions/mit_ts_california.php) and the timesheet page (agent/js/mit_timesheet.js),
// as the Technician (ITFLOW_TECH_EMAIL, who must be hourly / on California rules). Types times on a past day of the
// current period that has none, checks the meal check and that it saves on its own, adds a split shift (a second block
// after time off), then clears them again (the
// change history keeps both - this is a test login).

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, fillForm, submit, clearTime } = require('./helpers');

const techState = path.join(__dirname, '.auth', 'tech.json');
test.skip(!fs.existsSync(techState), 'no ITFLOW_TECH_EMAIL / ITFLOW_TECH_PASSWORD given');
test.use({ storageState: techState });

test('a day: times, meal check, saves as you type', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    await expect(page.locator('.mit-ts-day').first()).toBeVisible();
    const data = JSON.parse(await page.locator('#mitTsData').textContent());
    test.skip(!data.ca, 'the technician is not on California rules');
    test.skip(data.locked !== '', 'this period is locked: ' + data.locked);
    const date = Object.keys(data.days).find((d) => !data.days[d].future && data.days[d].ca && !(data.days[d].ca.blocks || []).length);
    test.skip(!date, 'no past day without times in this period');

    const week = data.weeks.findIndex((w) => w.includes(date));
    await page.click(`#mitTsWeeks [data-week="${week}"]`);
    await page.click(`.mit-ts-day[data-date="${date}"]`);
    const panel = page.locator('#mitTsDay');
    const block = (n) => panel.locator('[data-block]').nth(n);
    try {
        await block(0).locator('[data-block-start]').fill('08:00');
        await block(0).locator('[data-block-end]').fill('14:00');
        await expect(panel.locator('[data-worked]')).toHaveText(/^6(:00)? h$/);
        await expect(panel.locator('[data-issues]')).toContainText('No meal period');
        await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        await expect(page.locator(`.mit-ts-day[data-date="${date}"]`)).toContainText('8:00 AM - 2:00 PM');

        // A split shift: a second block after time off - the hours add up, the day says so
        await panel.locator('[data-add-block]').click();
        await block(1).locator('[data-gap]').selectOption('off');
        await block(1).locator('[data-block-start]').fill('17:00');
        await block(1).locator('[data-block-end]').fill('20:00');
        await expect(panel.locator('[data-worked]')).toHaveText(/^9(:00)? h$/);
        await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        await expect(page.locator(`.mit-ts-day[data-date="${date}"]`)).toContainText('8:00 AM - 8:00 PM');
        await expect(page.locator(`.mit-ts-day[data-date="${date}"]`)).toContainText('split shift, 3 h off');
    } finally {
        // Clear it again - also when a step above failed, so the technician's timesheet is left as it was
        while (await panel.locator('[data-block]').count() > 1) {
            await panel.locator('[data-remove-block]').last().click();
        }
        await clearTime(block(0).locator('[data-block-start]'));
        await clearTime(block(0).locator('[data-block-end]'));
        await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        await page.waitForTimeout(300);
        await expect(page.locator(`.mit-ts-day[data-date="${date}"]`)).not.toContainText('8:00 AM');
    }
    await expectClean(page, errors);
});

test('the signing window can be used (nothing covers it)', async ({ page }) => {
    await visit(page, 'agent/mit_timesheet.php');
    const submit = page.locator('[data-mit-ts-submit]');
    test.skip(await submit.count() === 0, 'nothing to sign in this period (submitted, locked or not on California rules)');
    await submit.click();
    const sign = page.locator('#mitCaSign');
    await expect(sign).toBeVisible();
    // A real click - it fails when the grey backdrop is on top of the window
    await sign.locator('#mitCaAccurate').check({ timeout: 5000 });
    await sign.locator('input[name=mit_ca_signature]').fill('test');
    await sign.locator('[data-bs-dismiss="modal"]').last().click();
    await expect(sign).toBeHidden();
});

test('a remote person\'s own time zone sets their timesheet\'s today', async ({ page }) => {
    const prefs = async () => {
        await visit(page, 'agent/user/user_preferences.php');
        return page.locator('form:has([name="edit_your_user_preferences"])');
    };
    const save = async (zone) => {
        const form = await prefs();
        await fillForm(form, { mit_ts_time_zone: zone });
        await submit(form, 'edit_your_user_preferences');
    };
    const before = await (await prefs()).locator('select[name="mit_ts_time_zone"]').inputValue();
    // The office's today, then a zone whose date is different right now (UTC+14 and UTC-11 are never on the same date)
    await save('');
    await visit(page, 'agent/mit_timesheet.php');
    const office = JSON.parse(await page.locator('#mitTsData').textContent()).today;
    const dateIn = (zone) => new Intl.DateTimeFormat('en-CA', { timeZone: zone }).format(new Date());
    const zone = dateIn('Pacific/Kiritimati') !== office ? 'Pacific/Kiritimati' : 'Pacific/Pago_Pago';
    try {
        await save(zone);
        await visit(page, 'agent/mit_timesheet.php');
        await expect(page.locator('#mitTsZone')).toContainText(zone.split('/')[1].replace('_', ' '));
        const data = JSON.parse(await page.locator('#mitTsData').textContent());
        expect(data.today).toBe(dateIn(zone));
        expect(data.today).not.toBe(office);
    } finally {
        await save(before);
    }
});

test('blocks that can\'t be right are refused, not saved', async ({ page }) => {
    await visit(page, 'agent/mit_timesheet.php');
    const data = JSON.parse(await page.locator('#mitTsData').textContent());
    test.skip(!data.ca || data.locked !== '', 'the technician is not on California rules, or the period is locked');
    const date = Object.keys(data.days).find((d) => !data.days[d].future && data.days[d].ca && !(data.days[d].ca.blocks || []).length);
    test.skip(!date, 'no past day without times in this period');
    await page.click(`#mitTsWeeks [data-week="${data.weeks.findIndex((w) => w.includes(date))}"]`);
    await page.click(`.mit-ts-day[data-date="${date}"]`);
    const panel = page.locator('#mitTsDay');
    const block = (n) => panel.locator('[data-block]').nth(n);
    const status = panel.locator('[data-mit-day-status]');
    try {
        // Same start and end (would be 24 hours)
        await block(0).locator('[data-block-start]').fill('09:00');
        await block(0).locator('[data-block-end]').fill('09:00');
        await expect(status).toContainText('cannot start and end at the same time', { timeout: 10000 });
        // A second block before the first one (would be the next morning)
        await block(0).locator('[data-block-start]').fill('13:00');
        await block(0).locator('[data-block-end]').fill('17:00');
        await panel.locator('[data-add-block]').click();
        await block(1).locator('[data-block-start]').fill('08:00');
        await block(1).locator('[data-block-end]').fill('12:00');
        await expect(status).toContainText('Blocks go in time order', { timeout: 10000 });
        // Neither was saved
        await visit(page, 'agent/mit_timesheet.php');
        const after = JSON.parse(await page.locator('#mitTsData').textContent());
        expect(after.days[date].ca.blocks || []).toEqual([]);
    } finally {
        await visit(page, 'agent/mit_timesheet.php');
        const now = JSON.parse(await page.locator('#mitTsData').textContent());
        if ((now.days[date].ca.blocks || []).length) {
            await page.click(`#mitTsWeeks [data-week="${now.weeks.findIndex((w) => w.includes(date))}"]`);
            await page.click(`.mit-ts-day[data-date="${date}"]`);
            while (await panel.locator('[data-remove-block]').count() > 1) await panel.locator('[data-remove-block]').last().click();
            await clearTime(block(0).locator('[data-block-start]'));
            await clearTime(block(0).locator('[data-block-end]'));
            await expect(status).toContainText('Saved', { timeout: 10000 });
        }
    }
});

test('time not on a code goes on the default charge code (Admin > Timesheets)', async ({ page, browser }, testInfo) => {
    const use = testInfo.project.use;
    const admin = await browser.newContext({ storageState: path.join(__dirname, '.auth', 'admin.json'), baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const adminPage = await admin.newPage();
    const settings = async () => {
        await visit(adminPage, 'admin/mit_timesheets.php');
        const select = adminPage.locator('select[name="unallocated_code"]');
        await expect(select).toHaveCount(1);
        return select;
    };
    const setCode = async (value) => {
        await settings();
        const form = adminPage.locator('form:has(select[name="unallocated_code"])');
        await fillForm(form, { unallocated_code: value });
        await submit(form, 'mit_ts_settings_save');
    };
    const select = await settings();
    const before = await select.inputValue();
    const codeId = await select.locator('option:not([value="0"])').first().getAttribute('value');
    let date;
    const panel = page.locator('#mitTsDay');
    const block = (n) => panel.locator('[data-block]').nth(n);
    try {
        await setCode(codeId);
        await visit(page, 'agent/mit_timesheet.php');
        const data = JSON.parse(await page.locator('#mitTsData').textContent());
        test.skip(!data.ca || data.locked !== '', 'the technician is not on California rules, or the period is locked');
        date = Object.keys(data.days).find((d) => !data.days[d].future && data.days[d].ca && !(data.days[d].ca.blocks || []).length);
        test.skip(!date, 'no past day without times in this period');
        // Long enough to leave an hour not on ticket time or a code, whatever the day already has
        const d = data.days[date];
        const spent = d.tickets.billable + d.tickets.other + Object.entries(d.codes).reduce((s, [id, e]) => s + (data.codes[id].kind === 'leave' ? 0 : e.m), 0);
        const end = 8 * 60 + spent + 60;
        test.skip(end >= 24 * 60, 'that day already has a full day on codes');
        await page.click(`#mitTsWeeks [data-week="${data.weeks.findIndex((w) => w.includes(date))}"]`);
        await page.click(`.mit-ts-day[data-date="${date}"]`);
        await block(0).locator('[data-block-start]').fill('08:00');
        await block(0).locator('[data-block-end]').fill(String(Math.floor(end / 60)).padStart(2, '0') + ':' + String(end % 60).padStart(2, '0'));
        await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        const row = page.locator(`.mit-ts-day[data-date="${date}"]`);
        await expect(row).toContainText(data.codes[codeId].name + ' (not on a code)');
        await expect(row.locator('.mit-ts-chips')).not.toContainText(/(^|[^(])Not on a code/);
    } finally {
        if (date) {
            await clearTime(block(0).locator('[data-block-start]'));
            await clearTime(block(0).locator('[data-block-end]'));
            await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        }
        await setCode(before);
        await admin.close();
    }
});

// The team list's columns add up to Total (agent/mit_timesheets.php, mitTsTotals): on California days Total is the typed times, so
// ticket / code time outside them shows as "Not clocked" and is taken off - before, the columns and Total disagreed and Billable %
// went over 100%.
const hoursOf = (text) => {
    const t = text.replace('−', '-').trim();
    if (t === '' || t === '-') return 0;
    const m = /^(-?)(\d+):(\d{2})$/.exec(t);
    return m ? (m[1] ? -1 : 1) * (Number(m[2]) + Number(m[3]) / 60) : Number(t);
};
test('team list: code hours past the typed times are "Not clocked" and every row adds up', async ({ page, browser }, testInfo) => {
    const use = testInfo.project.use;
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    const data = JSON.parse(await page.locator('#mitTsData').textContent());
    test.skip(!data.ca || data.locked !== '', 'the technician is not on California rules, or the period is locked');
    const date = Object.keys(data.days).find((d) => !data.days[d].future && data.days[d].ca && !(data.days[d].ca.blocks || []).length && !Object.keys(data.days[d].codes).length);
    test.skip(!date, 'no past day without times or codes in this period');
    const code = Object.entries(data.codes).find(([, c]) => c.usable && c.kind === 'work' && !c.note && (!c.max || c.max >= 180));
    test.skip(!code, 'no work code to use');
    await page.click(`#mitTsWeeks [data-week="${data.weeks.findIndex((w) => w.includes(date))}"]`);
    await page.click(`.mit-ts-day[data-date="${date}"]`);
    const panel = page.locator('#mitTsDay');
    const block = panel.locator('[data-block]').first();
    const admin = await browser.newContext({ storageState: path.join(__dirname, '.auth', 'admin.json'), baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    try {
        // One hour typed, three hours on a work code: two hours not clocked
        await block.locator('[data-block-start]').fill('08:00');
        await block.locator('[data-block-end]').fill('09:00');
        await panel.locator('select[data-add]').selectOption(code[0]);
        await panel.locator(`[data-code="${code[0]}"] [data-hours]`).fill('3');
        await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });

        const adminPage = await admin.newPage();
        await visit(adminPage, `agent/mit_timesheets.php?start=${Object.keys(data.days).sort()[0]}`);
        await expect(adminPage.locator('[data-mit-ts-not-clocked-head]')).toHaveCount(1);
        const rows = adminPage.locator('table tbody tr');
        let flagged = 0;
        for (let i = 0; i < await rows.count(); i++) {
            const row = rows.nth(i);
            const col = async (key) => hoursOf(await row.locator(`[data-mit-ts-col="${key}"]`).innerText());
            const sum = await col('billable') + await col('other') + await col('work') + await col('lost') + await col('leave') + await col('not_clocked');
            expect(Math.abs(sum - await col('total')), `row ${i + 1} adds up`).toBeLessThan(0.05);
            const share = (await row.locator('[data-mit-ts-col="share"]').innerText()).trim();
            expect(share === '-' || Number(share.replace('%', '')) <= 100, `row ${i + 1} billable % ${share}`).toBeTruthy();
            if (await col('not_clocked') <= -2) flagged++;
        }
        expect(flagged, 'the technician\'s row shows the two hours as Not clocked').toBeGreaterThan(0);
    } finally {
        await panel.locator(`[data-code="${code[0]}"] [data-remove]`).click().catch(() => {});
        await clearTime(block.locator('[data-block-start]'));
        await clearTime(block.locator('[data-block-end]'));
        await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        await admin.close();
    }
    await expectClean(page, errors);
});
