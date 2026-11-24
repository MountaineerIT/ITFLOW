// Shared steps for the Dispatch specs (ITFlow 26.12's agent/dispatch.php + agent/js/dispatch.js, our extras from agent/js/mit_dispatch.js):
// a known view for the admin (Day, nobody hidden, weekends on, all boards) kept and put back, and a queue ticket dragged onto an agent's
// column at 10:00 - which opens ITFlow's Schedule window (#dispatchScheduleModal) with our fields filled in.
const { expect } = require('@playwright/test');
const { sql, one } = require('./t2db');

const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";

/**
 * Day view, nobody hidden, weekends shown, the default board (1 - where tickets made in the database land) picked - returns a function
 * that puts the old state back. (Not "All boards": agent/js/mit_dispatch.js sends no ?board= for it, and the server then takes "My boards".)
 */
function dispatchState(userId = 1, pick = '1') {
    const calendar = one(`SELECT user_config_calendar_state FROM user_settings WHERE user_id = ${userId}`);
    const board = one(`SELECT value FROM mit_user_preferences WHERE user_id = ${userId} AND preference = 'dispatch.board'`);
    let saved = {};
    try {
        saved = JSON.parse(calendar || '{}') || {};
    } catch (e) {
        saved = {};
    }
    saved.dispatch = { view: 'day', hidden: [], weekends: true };
    sql(`INSERT INTO user_settings SET user_id = ${userId}, user_config_calendar_state = ${q(JSON.stringify(saved))}
        ON DUPLICATE KEY UPDATE user_config_calendar_state = VALUES(user_config_calendar_state)`);
    sql(`INSERT INTO mit_user_preferences SET user_id = ${userId}, preference = 'dispatch.board', value = ${q(JSON.stringify({ board: pick, board_techs: false }))}
        ON DUPLICATE KEY UPDATE value = VALUES(value)`);
    return () => {
        sql(`UPDATE user_settings SET user_config_calendar_state = ${calendar === null ? 'NULL' : q(calendar)} WHERE user_id = ${userId}`);
        sql(`DELETE FROM mit_user_preferences WHERE user_id = ${userId} AND preference = 'dispatch.board'`);
        if (board !== null) {
            sql(`INSERT INTO mit_user_preferences SET user_id = ${userId}, preference = 'dispatch.board', value = ${q(board)}`);
        }
    };
}

/** Find the ticket in a queue tab (searching for `search`) on `day` and drag it onto the agent's 10:00; waits for the Schedule window and our fields. */
async function dragToAgent(page, { ticketId, agentId, day, search, tab = 'unassigned' }) {
    await page.goto('agent/dispatch.php' + (day ? `?date=${day}` : ''));
    await expect(page.locator('#itflowDispatch')).toHaveCount(1);
    await page.locator('[data-dispatch-filter="q"]').fill(search);
    await page.locator('[data-dispatch-filter="q"]').dispatchEvent('input');
    await page.locator(`[data-dispatch-tab="${tab}"]`).click();
    const card = page.locator(`.itflow-dispatch-item[data-ticket="${ticketId}"]`);
    await expect(card, 'the ticket in the queue').toHaveCount(1, { timeout: 10000 });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
    await expect(card).toHaveCount(1);
    const slotOf = () => page.evaluate((agent) => {
        const col = document.querySelector(`.itflow-dispatch-col[data-agent="${agent}"]`);
        if (!col) {
            return null;
        }
        col.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        const cell = [...col.querySelectorAll('[data-time="10:00:00"]')].map((el) => el.getBoundingClientRect()).sort((a, b) => b.width - a.width)[0];
        return cell ? { x: cell.x + cell.width / 2, y: cell.y + Math.min(4, cell.height / 2) } : null;
    }, agentId);
    expect(await slotOf(), `agent ${agentId}'s column at 10:00`).not.toBeNull();
    await card.scrollIntoViewIfNeeded();
    const from = await card.boundingBox();
    await page.mouse.move(from.x + 20, from.y + 10);
    await page.mouse.down();
    await page.mouse.move(from.x + 40, from.y + 30, { steps: 5 });
    const slot = await slotOf();
    await page.mouse.move(slot.x, slot.y, { steps: 15 });
    await page.mouse.up();
    const modal = page.locator('#dispatchScheduleModal');
    await expect(modal, 'the Schedule window').toBeVisible();
    await expect(modal.locator('#mitDTitle'), 'our ticket details in it').toBeVisible({ timeout: 10000 });
    return modal;
}

/** The agent's id by email. */
function userId(email) {
    return Number(one(`SELECT user_id FROM users WHERE user_email = ${q(email)}`));
}

module.exports = { dispatchState, dragToAgent, userId };
