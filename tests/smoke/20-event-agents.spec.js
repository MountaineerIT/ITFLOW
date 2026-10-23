// Calendar entries assigned to agents (functions/mit_event_agents.php): New Event opened from another agent's calendar starts
// assigned to them ("Assigned to" under the title, the rest is "Invite"); the entry is drawn in its agent's color with their name; the
// quick look hands it to someone else; Dispatch moves it to another time and agent. Makes its own entry ("Smoke test ...") and
// deletes it, even when a step fails; the calendar's Whose choice is put back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, getJson, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { title: smokeName('entry'), eventId: 0, me: 0, other: 0, otherName: '', whose: null };
const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const day = (offset) => ymd(new Date(Date.now() + offset * 86400000));

async function pickWhose(page, value) {
    await page.evaluate((v) => {
        const select = document.getElementById('calendarAgent');
        select.value = v;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }, String(value));
}

// The entry as Dispatch has it on each agent's column
async function entryOn(page, techs) {
    const json = await getJson(page, `mit_dispatch_ajax.php?items=1&from=${day(-1)}&to=${day(3)}&techs=${techs.join(',')}`);
    return (json.items || json || []).filter((i) => i.extendedProps && i.extendedProps.event_id === made.eventId);
}

test('calendar entries: assigned to the agent whose calendar it was made on, shown with them, handed over', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    made.me = Number(await page.locator('#itflowCalendar').getAttribute('data-user-id'));
    made.whose = await page.locator('#calendarAgent').inputValue();
    const other = await page.locator('#calendarAgent option').evaluateAll((options, me) => options
        .map((o) => ({ id: Number(o.value), name: o.textContent.trim() })).find((o) => o.id && o.id !== me), made.me);
    test.skip(!other, 'needs a second agent');
    made.other = other.id;
    made.otherName = other.name;

    // From their calendar: New event starts assigned to them
    await pickWhose(page, made.other);
    await page.click('#calendarNewEvent');
    const modal = page.locator('.modal.show');
    await modal.locator('[data-mit-event-assign]').waitFor();
    expect(await modal.locator('[data-mit-event-assign] select option:checked').evaluateAll((o) => o.map((x) => Number(x.value))), 'assigned to them').toEqual([made.other]);
    await expect(modal.locator('label', { hasText: 'Invite' }), 'the rest is Invite').toHaveCount(1);
    await expect(modal.locator('select[name="agents[]"]'), 'one agents field').toHaveCount(1);
    // Calendar only as a picker when there are several (ITFlow's lists), otherwise a hidden field
    const calendars = await modal.locator('[name=calendar]').evaluate((el) => el.tagName === 'SELECT' ? el.options.length : 1);
    await expect(modal.locator('[data-mit-event-calendar]'), 'Calendar picker only with several calendars').toHaveCount(calendars > 1 ? 1 : 0);
    await fillForm(modal, { title: made.title });
    await submit(modal, 'add_event');
    expect(await flash(page)).toContain('added');

    // Dispatch has it on their column, with their name and color dot
    await visit(page, 'agent/mit_dispatch.php', errors);
    test.skip(await page.locator('#mitDispatch').count() === 0, 'Dispatch is off');
    let found = await getJson(page, `mit_dispatch_ajax.php?items=1&from=${day(-1)}&to=${day(3)}&techs=${made.other}`);
    let item = (found.items || found).find((i) => i.title === made.title);
    expect(item, 'on their column').toBeTruthy();
    made.eventId = item.extendedProps.event_id;
    expect(item.extendedProps.agents).toEqual([made.other]);
    expect(item.extendedProps.agent_name, 'their first name, shown beside the time').toBe(made.otherName.split(/\s+/)[0]);
    expect(item.extendedProps.agent_full, 'their full name in the tooltip').toBe(made.otherName);
    const otherColor = await page.evaluate(async (id) => {
        const opt = [...document.querySelectorAll('[data-tech]')].find((el) => Number(el.dataset.tech) === id);
        return opt ? opt.querySelector('.rounded-circle')?.style.background : null;
    }, made.other);
    expect(item.color, 'drawn in their color').toMatch(/^#[0-9a-f]{6}$/i);
    if (otherColor) {
        expect(await page.evaluate(([a, b]) => { const s = document.createElement('span'); s.style.background = a; const t = document.createElement('span'); t.style.background = b; return s.style.background === t.style.background; }, [item.color, otherColor]), 'their own color').toBe(true);
    }
    expect(item.editable, 'a one-off entry moves on Dispatch').toBe(true);

    // The quick look hands it to me
    await visit(page, 'agent/calendar.php', errors);
    let look = await openModal(page, `modals/calendar/calendar_event.php?id=${made.eventId}`, 'lg');
    await expect(look.locator('dt', { hasText: /^Who$/ }), 'agents only under Assigned to').toHaveCount(0);
    const form = look.locator('[data-mit-event-quick-assign]');
    await fillForm(form, { 'agents[]': [made.me] });
    await submit(form, 'mit_event_assign');
    expect(await flash(page), 'handed over').toContain(`taken off ${made.otherName}`);
    await visit(page, 'agent/mit_dispatch.php', errors);
    expect((await entryOn(page, [made.me, made.other])).map((i) => i.extendedProps.tech), 'on my column only').toEqual([made.me]);

    // Dispatch: dragged onto their column at 14:00 the day after tomorrow
    const result = await page.evaluate(async ([id, from, to, start, end]) => {
        const csrf = document.getElementById('mitDispatch').dataset.csrf;
        const body = new URLSearchParams({ event_move: '1', csrf_token: csrf, event_id: String(id), start, end, all_day: '0', from: String(from), to: String(to) });
        return (await fetch('/agent/mit_dispatch_ajax.php', { method: 'POST', body })).json();
    }, [made.eventId, made.me, made.other, `${day(2)} 14:00:00`, `${day(2)} 15:30:00`]);
    expect(result.error, 'moved').toBeUndefined();
    const moved = await entryOn(page, [made.me, made.other]);
    expect(moved.map((i) => i.extendedProps.tech), 'now theirs').toEqual([made.other]);
    expect(String(moved[0].start)).toContain(`${day(2)} 14:00`);
    expect(String(moved[0].end)).toContain(`${day(2)} 15:30`);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.eventId) {
        await action(page, `delete_event=${made.eventId}`, 'agent/calendar.php').catch(() => {});
    }
    if (made.whose !== null) {
        await page.goto('agent/calendar.php');
        await pickWhose(page, made.whose).catch(() => {});
        await page.waitForTimeout(1500);
    }
    await context.close();
});
