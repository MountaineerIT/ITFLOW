// The calendar's sidebar lists: To schedule and Left behind (functions/mit_left_behind.php) follow the Whose picker -
// Everyone = everyone's - and a ticket whose booked day has gone is left behind (marked on the calendar, and in Dispatch's
// Left behind tab) until it is booked again. Makes its own client and ticket ("Smoke test ...") and deletes them, even when a step fails; the
// calendar's Whose choice is put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, getJson, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0, whose: null, eventId: 0 };

function day(offset, time = '10:00') {
    const d = new Date(Date.now() + offset * 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${time}`;
}

// Book through the calendar's own move request
async function book(page, start) {
    return page.evaluate(async ([id, start]) => {
        const root = document.getElementById('itflowCalendar');
        const body = new URLSearchParams({ calendar_move: 1, csrf_token: root.dataset.csrf, kind: 'ticket', id, start });
        return (await fetch('ajax.php', { method: 'POST', credentials: 'same-origin', body })).json();
    }, [String(made.ticketId), start]);
}

async function pickWhose(page, value) {
    await page.evaluate((v) => {
        const select = document.getElementById('calendarAgent');
        select.value = v;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }, String(value));
}

test('calendar: To schedule and Left behind follow Whose, and a passed booking is left behind', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    test.skip(await page.locator('#mitLeftBehind').count() === 0, 'needs Support edit (scheduling) and the Left behind list');
    const me = await page.locator('#itflowCalendar').getAttribute('data-user-id');
    made.whose = await page.locator('#calendarAgent').inputValue();

    await visit(page, 'agent/clients.php');
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    expect(made.clientId).toBeGreaterThan(0);
    modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low', assigned_to: me });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${await flash(page)})`).toBeGreaterThan(0);

    // To schedule: mine, and everyone's on Everyone
    await visit(page, 'agent/calendar.php', errors);
    const ids = (json) => (json.tickets || []).map((t) => t.id);
    expect(ids(await getJson(page, `ajax.php?calendar_unscheduled=1&user_id=${me}&client_id=${made.clientId}`)), 'To schedule, mine').toContain(made.ticketId);
    const everyone = await getJson(page, `ajax.php?calendar_unscheduled=1&user_id=0&client_id=${made.clientId}`);
    expect(ids(everyone), 'To schedule, everyone\'s').toContain(made.ticketId);
    expect(everyone.tickets.find((t) => t.id === made.ticketId).agent, 'with the agent named').toBeTruthy();
    await pickWhose(page, 0);
    await expect(page.locator(`#calendarUnscheduled [data-ticket="${made.ticketId}"]`), 'in the list on Everyone').toHaveCount(1);

    // Booked three days ago and still open: left behind - in the list (Everyone and mine) and marked on the calendar
    expect((await book(page, day(-3))).ok, 'booked in the past').toBe(true);
    await visit(page, 'agent/calendar.php', errors);
    await pickWhose(page, 0);
    await expect(page.locator(`#mitLeftBehind [data-ticket="${made.ticketId}"]`), 'left behind, Everyone').toHaveCount(1);
    await pickWhose(page, me);
    await expect(page.locator(`#mitLeftBehind [data-ticket="${made.ticketId}"]`), 'left behind, mine').toHaveCount(1);
    const date = day(-3).slice(0, 10);
    const items = await getJson(page, `ajax.php?calendar_items=1&from=${date}&to=${date}&sources=tickets`);
    const item = items.find((i) => i.id === `ticket-${made.ticketId}`);
    expect(item && item.className, 'its old booking is marked').toContain('mit-left-behind');
    // and in Dispatch's Left behind tab (every agent's)
    const dispatch = await page.evaluate(async () => {
        const res = await fetch('mit_dispatch_ajax.php?queue=left_behind&board=all', { credentials: 'same-origin' });
        return res.ok ? res.json() : null;
    });
    if (dispatch) { // Dispatch can be switched off
        expect(dispatch.tickets.map((t) => t.id), 'in Dispatch\'s Left behind tab').toContain(made.ticketId);
        expect(dispatch.counts.left_behind, 'counted on the tab').toBeGreaterThan(0);
    }

    // Booked again for tomorrow: no longer left behind
    expect((await book(page, day(1))).ok, 'booked again').toBe(true);
    expect(ids(await getJson(page, `ajax.php?mit_left_behind=1&user_id=0&client_id=${made.clientId}`)), 'moved on').not.toContain(made.ticketId);
    await expectClean(page, errors);
});

test('dispatch: an agent\'s meetings show beside their bookings, count as a clash, and bookings carry onsite / remote', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    const me = await page.locator('#itflowCalendar').getAttribute('data-user-id');
    const tomorrow = day(1).slice(0, 10);
    // A meeting tomorrow 10:00 - 11:00 with me on it - the ticket was booked tomorrow 10:00 above
    const modal = await openModal(page, 'modals/calendar/calendar_event_add.php', 'lg');
    await fillForm(modal, { title: 'Smoke test meeting', start_date: tomorrow, start_time: '10:00', end_date: tomorrow, end_time: '11:00', 'agents[]': [me], send_invites: false });
    await submit(modal, 'add_event');

    const dispatch = await page.evaluate(async ([date, me]) => {
        const res = await fetch(`/agent/mit_dispatch_ajax.php?items=1&from=${date}&to=${date}&techs=${me}`, { credentials: 'same-origin' });
        return res.ok ? res.json() : null;
    }, [tomorrow, me]);
    test.skip(dispatch === null, 'Dispatch is switched off');
    const meeting = dispatch.find((i) => i.extendedProps.kind === 'event' && i.title === 'Smoke test meeting');
    expect(meeting, 'the meeting on my Dispatch column').toBeTruthy();
    made.eventId = meeting.extendedProps.event_id;
    expect(meeting.editable, 'a one-off entry moves on Dispatch (functions/mit_event_agents.php)').toBe(true);
    const booking = dispatch.find((i) => i.extendedProps.kind === 'ticket' && i.extendedProps.ticket_id === made.ticketId);
    expect(booking && ['fa-car', 'fa-headset'], 'the booking says onsite or remote').toContain(booking && booking.extendedProps.icon);

    // A service ticket's booking: the service color (Admin > Settings > Ticket), the agent's color as a dot
    await visit(page, 'admin/settings_ticket.php', errors);
    const colorField = page.locator('#mitServiceTicketColor');
    const serviceColor = await colorField.inputValue();
    expect(booking.color, 'the service color').toBe(serviceColor);
    expect(booking.extendedProps.tech_color, 'the agent color').toMatch(/^#[0-9a-f]{6}$/i);
    expect(booking.extendedProps.agent_name, 'the agent\'s name, shown beside the time').toBeTruthy();
    await colorField.fill('#123456');
    await submit(page.locator('form:has(button[name=mit_edit_calendar_colors])'), 'mit_edit_calendar_colors');
    const recolored = await page.evaluate(async ([date, me]) => (await (await fetch(`/agent/mit_dispatch_ajax.php?items=1&from=${date}&to=${date}&techs=${me}`)).json()), [tomorrow, me]);
    await visit(page, 'admin/settings_ticket.php');
    await page.locator('#mitServiceTicketColor').fill(serviceColor);
    await submit(page.locator('form:has(button[name=mit_edit_calendar_colors])'), 'mit_edit_calendar_colors');
    expect(recolored.find((i) => i.extendedProps.ticket_id === made.ticketId && i.extendedProps.kind === 'ticket').color, 'a new service color').toBe('#123456');
    // Drawn with a bar in the agent's color on Dispatch's day view
    await visit(page, `agent/mit_dispatch.php?date=${tomorrow}`, errors);
    await page.click('[data-md-view="day"]');
    const column = page.locator(`.mit-dispatch-col[data-tech="${me}"]`);
    if (await column.count()) {
        const striped = column.locator('[data-mit-agent]').first();
        await striped.waitFor();
        expect(await striped.evaluate((el) => getComputedStyle(el).boxShadow), 'the agent bar').toContain('inset');
    }

    // Booking over it: the meeting is named as a clash
    await visit(page, 'agent/calendar.php', errors);
    const moved = await book(page, `${tomorrow} 10:30`);
    expect((moved.conflicts || []).join(' | '), 'the meeting as a clash').toContain('Smoke test meeting');
    await expectClean(page, errors);
});

test('dispatch week: the agent names stay at the left when the days scroll sideways', async ({ browser, baseURL }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors, viewport: { width: 1000, height: 700 } });
    const page = await context.newPage();
    const errors = watch(page);
    const response = await page.goto('agent/mit_dispatch.php');
    test.skip(response.status() >= 400 || !(await page.locator('[data-md-view="week"]').count()), 'Dispatch is switched off');
    const view = await page.locator('[data-md-view].active, [data-md-view].btn-secondary').first().getAttribute('data-md-view').catch(() => 'day');
    await page.click('[data-md-view="week"]');
    try {
        const name = page.locator('.mit-dispatch-week tbody th').first();
        await name.waitFor();
        const before = (await name.boundingBox()).x;
        const scrolled = await page.evaluate(() => {
            // Whichever box around the table scrolls sideways (it depends on the screen width)
            let scroller = document.querySelector('.mit-dispatch-week').parentElement;
            while (scroller && !(scroller.scrollWidth > scroller.clientWidth + 5 && getComputedStyle(scroller).overflowX !== 'visible')) {
                scroller = scroller.parentElement;
            }
            if (!scroller) {
                return 0;
            }
            scroller.scrollLeft = 200;
            return scroller.scrollLeft;
        });
        test.skip(scrolled === 0, 'the week fits without scrolling');
        expect(Math.abs((await name.boundingBox()).x - before), 'the name did not move').toBeLessThan(2);
        const corner = await page.locator('.mit-dispatch-week thead th').first().evaluate((th) => getComputedStyle(th).backgroundColor);
        expect(corner, 'the corner is solid, so the days do not show through').not.toMatch(/rgba\(0, 0, 0, 0\)|transparent/);
        await expectClean(page, errors);
    } finally {
        if (view && view !== 'week') {
            await page.click(`[data-md-view="${view}"]`); // put the agent's view back (it is remembered)
            await page.waitForTimeout(500);
        }
        await context.close();
    }
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.whose !== null) {
        await page.goto('agent/calendar.php');
        await pickWhose(page, made.whose);
        await page.waitForTimeout(1500); // the choice is saved to the account a moment after the change
    }
    // The meeting - found again when a failed step never learnt its id
    if (!made.eventId && made.ticketId) {
        await page.goto('agent/calendar.php');
        const date = day(1).slice(0, 10);
        made.eventId = await page.evaluate(async (date) => {
            const calendars = [...document.querySelectorAll('[data-calendar]')].map((box) => box.dataset.calendar).join(',');
            const items = await (await fetch(`ajax.php?calendar_items=1&from=${date}&to=${date}&calendars=${calendars}`, { credentials: 'same-origin' })).json();
            const found = items.find((i) => i.title === 'Smoke test meeting');
            return found ? found.extendedProps.event_id : 0;
        }, date);
    }
    if (made.eventId) {
        await action(page, `delete_event=${made.eventId}`, 'agent/calendar.php');
    }
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    await context.close();
});

test('calendar: the 7 days view moves a day at a time, so a weekend can be crossed without changing weeks', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    const seven = page.locator('button', { hasText: /^7 days$/ });
    await expect(seven, 'the 7 days button beside Week').toHaveCount(1);
    const days = async () => (await page.locator('[role="columnheader"]').allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim()).filter(Boolean);
    // The view the agent had, to put back: Month (day names only), Week (7 dated), Day (1) or List (none)
    const before = await days();
    const was = before.length === 0 ? 'List' : (before.length === 1 ? 'Day' : (/\d/.test(before[0]) ? 'Week' : 'Month'));
    await seven.click();
    await expect.poll(async () => (await days()).length, 'seven day columns').toBe(7);
    const first = await days();
    await page.locator('button[title*="next" i], button[aria-label*="next" i]').first().click();
    await expect.poll(async () => (await days())[0], 'next moves one day').toBe(first[1]);
    expect((await days())[6], 'a new day at the end').not.toBe(first[6]);
    // Remembered like the other views: back on 7 dated days (from today)
    await page.reload();
    await expect.poll(async () => (await days()).length, 'still 7 days after a reload').toBe(7);
    expect((await days())[0], 'dated days, not month names').toMatch(/\d/);
    // Put the agent's own view back
    await page.locator('button', { hasText: new RegExp(`^${was}$`) }).first().click();
    await page.waitForTimeout(600);
});

test('calendar: Left behind sits under the calendar, as wide as it, both on the screen', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    const card = page.locator('#mitLeftBehindCard');
    await expect(card, 'the Left behind card').toHaveCount(1);
    await page.waitForTimeout(800); // its list loads
    const cal = await page.locator('#calendar').boundingBox();
    const box = await card.boundingBox();
    expect(box.y, 'under the calendar').toBeGreaterThan(cal.y + cal.height - 1);
    expect(Math.abs(box.width - cal.width), 'as wide as the calendar (its card)').toBeLessThan(60);
    expect(await page.locator('.itflow-cal-side #mitLeftBehindCard').count(), 'not in the left column').toBe(0);
    // With rows to drag, the top of the list is on the screen with the calendar
    if (await page.locator('#mitLeftBehind > *').count()) {
        expect(box.y + 60, 'its first rows on the screen').toBeLessThan(page.viewportSize().height);
    }
});
