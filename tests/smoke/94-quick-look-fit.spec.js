// The ticket and calendar-entry quick looks (opened from the calendar, Dispatch and the lists through ITFlow's ajax-modal) fit the screen:
// a long ticket no longer runs off the bottom - the title and buttons stay and the middle scrolls (functions/mit_theme.php mitThemeHead,
// Bootstrap's scrollable-dialog rules keyed on ITFlow's markup; 26.11.2.3).
const { test, expect } = require('@playwright/test');
const { watch, visit } = require('./helpers');
const { sql, one, deleteTickets, makeTicket } = require('./t2db');

const long = Array.from({ length: 60 }, (_, i) => `<p>Line ${i + 1} of a long ticket description.</p>`).join('');

// Opens a quick look the way the calendar does (an .ajax-modal link) and measures it
async function openAndMeasure(page, url) {
    await page.evaluate((u) => {
        const a = document.createElement('a');
        a.className = 'ajax-modal d-none';
        a.dataset.modalUrl = u;
        a.dataset.modalSize = 'lg';
        a.href = '#';
        document.body.appendChild(a);
        a.click();
    }, url);
    const modal = page.locator('.modal.show .modal-content');
    await expect(modal).toBeVisible();
    await page.waitForTimeout(400);
    return modal.evaluate((el) => {
        const box = el.getBoundingClientRect();
        const body = el.querySelector(':scope > .modal-body');
        const footer = el.querySelector(':scope > .modal-footer');
        return { top: box.top, bottom: box.bottom, height: window.innerHeight, scrolls: body.scrollHeight > body.clientHeight + 5,
            footerShown: !!footer && footer.getBoundingClientRect().bottom <= window.innerHeight };
    });
}

test('ticket and calendar-entry quick looks fit a short screen, the middle scrolling', async ({ page }) => {
    const errors = watch(page);
    await page.setViewportSize({ width: 1280, height: 600 });
    const ticket = makeTicket({ subject: `ZZ quick look ${Date.now() % 100000}`, client: 1 });
    sql(`UPDATE tickets SET ticket_details = '${long}' WHERE ticket_id = ${ticket}`);
    const event = Number(one('SELECT MIN(event_id) FROM calendar_events'));
    const description = event ? one(`SELECT event_description FROM calendar_events WHERE event_id = ${event}`) : null;
    try {
        await visit(page, 'agent/calendar.php', errors);
        const t = await openAndMeasure(page, `modals/ticket/ticket.php?id=${ticket}`);
        expect(t.bottom, 'the ticket window ends on screen').toBeLessThanOrEqual(t.height);
        expect(t.top).toBeGreaterThanOrEqual(0);
        expect(t.scrolls, 'its middle scrolls').toBe(true);
        expect(t.footerShown, 'its buttons are on screen').toBe(true);
        await page.keyboard.press('Escape');
        await expect(page.locator('.modal.show')).toHaveCount(0);
        if (event) {
            sql(`UPDATE calendar_events SET event_description = '${long}' WHERE event_id = ${event}`);
            const e = await openAndMeasure(page, `modals/calendar/calendar_event.php?id=${event}`);
            expect(e.bottom, 'the entry window ends on screen').toBeLessThanOrEqual(e.height);
            expect(e.scrolls, 'its middle scrolls').toBe(true);
        }
    } finally {
        deleteTickets([ticket]);
        if (event) {
            sql(`UPDATE calendar_events SET event_description = ${description === null ? 'NULL' : `'${String(description).replace(/'/g, "''")}'`} WHERE event_id = ${event}`);
        }
    }
    expect(errors).toEqual([]);
});
