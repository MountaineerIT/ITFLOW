// The calendar and Dispatch in the agent's own time zone (functions/mit_zones.php, 26.11.2.5 - a tech works from Japan): with "My time
// zone" set to Tokyo, a booking shows on Tokyo's clock (on the next day here), a time dropped on the calendar or picked in Dispatch is
// stored on the company's clock, a new event typed in Tokyo time is saved right, and the tech's booking email says both.
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');
const { sql, one, php, deleteTickets, makeTicket } = require('./t2db');

const ZONE = 'Asia/Tokyo';

// A company-clock time on another zone's clock (and back), worked out by PHP the way the server does
function shift(value, from, to) {
    return php(`echo (new DateTime(${JSON.stringify(value)}, new DateTimeZone(${JSON.stringify(from)})))->setTimezone(new DateTimeZone(${JSON.stringify(to)}))->format('Y-m-d H:i:s');`).trim();
}

test.describe.configure({ mode: 'serial' });

test('calendar and Dispatch: times on the agent\'s own clock, stored on the company\'s', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    const me = Number(await page.locator('#itflowCalendar').getAttribute('data-user-id'));
    test.skip(!one("SHOW COLUMNS FROM mit_timesheet_people LIKE 'time_zone'"), 'database update M023 not run');
    const company = php("echo date_default_timezone_get();").trim();
    test.skip(company === ZONE, 'the company is in Tokyo already');

    const had = sql(`SELECT time_zone FROM mit_timesheet_people WHERE user_id = ${me}`);
    sql(`INSERT INTO mit_timesheet_people SET user_id = ${me}, time_zone = '${ZONE}' ON DUPLICATE KEY UPDATE time_zone = '${ZONE}'`);
    const ticket = makeTicket({ subject: 'ZZ zone smoke', assigned: me });
    const day = php("echo date('Y-m-d', strtotime('+7 days'));").trim();
    const stored = `${day} 13:00:00`;
    sql(`UPDATE tickets SET ticket_schedule = '${stored}', ticket_schedule_end = '${day} 14:00:00' WHERE ticket_id = ${ticket}`);
    const seen = shift(stored, company, ZONE); // e.g. the next day 03:00
    const hm = (value) => {
        const [h, m] = value.slice(11, 16).split(':').map(Number);
        return `${h % 12 || 12}:${String(m).padStart(2, '0')}`;
    };
    let eventId = 0;
    try {
        // The calendar, on the Tokyo day
        await visit(page, `agent/calendar.php?date=${seen.slice(0, 10)}`, errors);
        await expect(page.locator('#itflowCalendar')).toHaveAttribute('data-mit-zone', ZONE);
        await expect(page.locator('[data-mit-zone-note]'), 'it says which clock the times are on').toContainText('Tokyo');
        await page.evaluate((v) => {
            const select = document.getElementById('calendarAgent');
            select.value = v;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }, String(me));
        await page.locator('#itflowCalendar button', { hasText: /^Day$/ }).first().click();
        const booking = page.locator('[data-kind="ticket"]', { hasText: 'ZZ zone smoke' });
        await expect(booking, 'the booking is on the Tokyo day').toHaveCount(1);
        await expect(booking, 'at the Tokyo time').toContainText(hm(seen));

        // Dropped at 10:00 Tokyo -> stored on the company's clock
        const csrf = await page.locator('#itflowCalendar').getAttribute('data-csrf');
        const drop = `${seen.slice(0, 10)} 10:00:00`;
        const moved = await page.evaluate(async ([csrf, id, start]) => {
            const fd = new FormData();
            Object.entries({ calendar_move: 1, csrf_token: csrf, kind: 'ticket', id, start, end: '' }).forEach(([k, v]) => fd.append(k, v));
            return (await fetch('/agent/ajax.php', { method: 'POST', body: fd })).json();
        }, [csrf, ticket, drop]);
        expect(moved.ok, JSON.stringify(moved)).toBeTruthy();
        expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${ticket}`), 'stored on the company\'s clock').toBe(shift(drop, ZONE, company));
        expect(moved.message, 'the message names both clocks').toContain('JST');

        // New event typed in Tokyo time
        const title = `ZZ zone event ${Date.now()}`;
        await page.evaluate((url) => {
            const a = document.createElement('a');
            a.className = 'ajax-modal d-none';
            a.dataset.modalUrl = url;
            a.href = '#';
            document.body.appendChild(a);
            a.click();
        }, `modals/calendar/calendar_event_add.php?start=${seen.slice(0, 10)}T15:00&end=${seen.slice(0, 10)}T16:00`);
        const modal = page.locator('.modal.show');
        await expect(modal.locator('[name="start_time"]'), 'the picked time stays on the Tokyo clock').toHaveValue('15:00');
        await expect(modal.locator('[data-mit-zone-hint]')).toBeVisible();
        await modal.locator('[name="title"]').fill(title);
        await modal.locator('button[name="add_event"]').click();
        await page.waitForLoadState('networkidle');
        eventId = Number(one(`SELECT event_id FROM calendar_events WHERE event_title = '${title}'`) || 0);
        expect(eventId, 'the event was saved').toBeGreaterThan(0);
        expect(one(`SELECT event_start FROM calendar_events WHERE event_id = ${eventId}`), 'saved on the company\'s clock')
            .toBe(shift(`${seen.slice(0, 10)} 15:00:00`, ZONE, company));

        // Dispatch: the same day on the Tokyo clock, and a booking picked there
        if (await page.request.get('agent/mit_dispatch.php').then((r) => r.ok())) {
            await visit(page, `agent/mit_dispatch.php?date=${seen.slice(0, 10)}`, errors);
            await expect(page.locator('[data-mit-zone-note]')).toBeVisible();
            await page.locator('[data-md-view="day"]').first().click();
            await page.waitForLoadState('networkidle');
            const card = page.locator('.mit-dispatch [data-kind="ticket"]', { hasText: 'ZZ zone smoke' });
            await expect(card, 'Dispatch shows it at the Tokyo time').toContainText('10:00');
            const dcsrf = await page.locator('#mitDispatch').getAttribute('data-csrf');
            const pick = `${seen.slice(0, 10)} 11:30:00`;
            const booked = await page.evaluate(async ([csrf, id, tech, start]) => {
                const fd = new FormData();
                Object.entries({ book: 1, csrf_token: csrf, ticket_id: id, tech_id: tech, start, minutes: 60 }).forEach(([k, v]) => fd.append(k, v));
                return (await fetch('/agent/mit_dispatch_ajax.php', { method: 'POST', body: fd })).json();
            }, [dcsrf, ticket, me, pick]);
            expect(booked.ok, JSON.stringify(booked)).toBeTruthy();
            expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${ticket}`), 'Dispatch stores the company\'s clock').toBe(shift(pick, ZONE, company));
            // The tech's own booking email, on their clock with the company's after it
            const mail = one(`SELECT email_content FROM email_queue WHERE email_subject LIKE '%ZZ zone smoke%' AND email_recipient = (SELECT user_email FROM users WHERE user_id = ${me}) ORDER BY email_id DESC LIMIT 1`) || '';
            expect(mail, 'the tech\'s email is in their zone').toContain('JST');
        }
        await expectClean(page, errors);
    } finally {
        if (eventId) {
            sql(`DELETE FROM calendar_event_attendees WHERE attendee_event_id = ${eventId}; DELETE FROM calendar_events WHERE event_id = ${eventId}`);
        }
        sql(`DELETE FROM email_queue WHERE email_subject LIKE '%ZZ zone smoke%'`);
        deleteTickets([ticket]);
        const back = had.length && had[0].time_zone ? `'${had[0].time_zone}'` : 'NULL';
        sql(`UPDATE mit_timesheet_people SET time_zone = ${back} WHERE user_id = ${me}`);
    }
});
