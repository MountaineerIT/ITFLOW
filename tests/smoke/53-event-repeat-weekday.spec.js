// A calendar entry repeating "on the last Friday" made in New Event as a person does (js/mit_repeat_on.js offers the choices from
// the date picked): saved as -1FR, the quick look says so, the calendar shows it on the last Friday of each following month
// (not the same date), and its .ics download carries RRULE BYDAY=-1FR. The entry is deleted after.

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, fillForm, submit, flash, getJson, fetchText, expectClean } = require('./helpers');
const { sql, one } = require('./t2db');

const tag = `ZZrep${Date.now() % 1000000}`;
const made = { eventId: 0 };
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
function lastFriday(year, month) { // month 0-11
    const d = new Date(year, month + 1, 0);
    while (d.getDay() !== 5) {
        d.setDate(d.getDate() - 1);
    }
    return d;
}

test('event repeating on the last Friday: offered, saved, shown on each last Friday, in the .ics', async ({ page }) => {
    const errors = watch(page);
    const now = new Date();
    const first = lastFriday(now.getFullYear(), now.getMonth() + 1);
    await visit(page, 'agent/calendar.php', errors);
    const modal = await openModal(page, `modals/calendar/calendar_event_add.php?start=${ymd(first)}`, 'lg');
    await fillForm(modal, { title: `${tag} last friday`, start_date: ymd(first), end_date: ymd(first), start_time: '15:00', end_time: '16:00', repeat: 'Month' });
    const repeatOn = modal.locator('select[name=repeat_on]');
    await expect(repeatOn, 'Repeats on offered for a monthly repeat').toBeEnabled();
    const offered = await repeatOn.locator('option').evaluateAll((o) => o.map((x) => x.value));
    expect(offered, 'the choices follow the date').toContain('-1FR');
    await fillForm(modal, { repeat_on: '-1FR' });
    await submit(modal, 'add_event');
    expect(await flash(page)).not.toMatch(/error|could not/i);
    made.eventId = Number(one(`SELECT event_id FROM calendar_events WHERE event_title = '${tag} last friday' ORDER BY event_id DESC LIMIT 1`));
    expect(made.eventId, 'saved').toBeGreaterThan(0);
    expect(one(`SELECT event_repeat_by_day FROM calendar_events WHERE event_id = ${made.eventId}`), 'the rule saved').toBe('-1FR');

    // The next three months: each on its last Friday at 15:00
    const want = [1, 2, 3].map((k) => ymd(lastFriday(first.getFullYear(), first.getMonth() + k)) + ' 15:00:00');
    const to = new Date(first.getFullYear(), first.getMonth() + 4, 0);
    const calendars = sql('SELECT calendar_id FROM calendars WHERE calendar_archived_at IS NULL').map((r) => r.calendar_id).join(',');
    const items = await getJson(page, `ajax.php?calendar_items=1&from=${ymd(new Date(first.getTime() + 86400000))}&to=${ymd(to)}&calendars=${calendars}&sources=`);
    const starts = items.filter((i) => i.extendedProps && i.extendedProps.event_id === made.eventId).map((i) => i.start);
    expect(starts, 'on each last Friday').toEqual(want);

    // The quick look says so
    const look = await openModal(page, `modals/calendar/calendar_event.php?id=${made.eventId}&occurrence=${ymd(first)}`, 'lg');
    await expect(look).toContainText('last Friday');
    await page.keyboard.press('Escape');
    // The .ics download
    const ics = await fetchText(page, `post.php?export_event_ics=${made.eventId}`);
    expect(ics.status).toBe(200);
    expect(ics.text.replace(/\r?\n /g, ''), 'RRULE with BYDAY').toMatch(/RRULE:FREQ=MONTHLY;BYDAY=-1FR/);
    await expectClean(page, errors);
});

test.afterAll(() => {
    if (made.eventId) {
        sql(`DELETE FROM calendar_event_attendees WHERE attendee_event_id = ${made.eventId}`);
        sql(`DELETE FROM calendar_events WHERE event_id = ${made.eventId}`);
    }
    sql(`DELETE FROM email_queue WHERE email_subject LIKE '%${tag}%'`);
});
