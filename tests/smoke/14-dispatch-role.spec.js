// The Dispatch permission (M035, functions/mit_dispatch.php): with Dispatch View the Technician (ITFLOW_TECH_EMAIL) sees the
// board marked "View only" and is refused when booking or assigning; with no Dispatch the page, its menu entry and its
// requests are refused. The role's Dispatch level is set in Admin > Roles > Edit and put back at the end, even when a step fails.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, submit, expectClean } = require('./helpers');

const techState = path.join(__dirname, '.auth', 'tech.json');
test.skip(!fs.existsSync(techState), 'no ITFLOW_TECH_EMAIL / ITFLOW_TECH_PASSWORD given');
test.describe.configure({ mode: 'serial' });

const made = { roleId: 0, restore: null };

async function techPage(browser) {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: techState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    return context.newPage();
}

// The role's Dispatch level in Admin > Roles > Edit (null when the permission is not there - M035 not run); with
// $level, sets it and saves
async function dispatchLevel(page, level = null) {
    await page.goto('admin/roles.php');
    const modal = await openModal(page, `modals/role/role_edit.php?id=${made.roleId}`, 'lg');
    const current = await modal.evaluate((root) => {
        const radios = [...root.querySelectorAll('[data-role-module="module_dispatch"] input[type=radio]')];
        return radios.length ? (radios.find((r) => r.checked) || { value: '0' }).value : null;
    });
    if (level !== null && current !== null) {
        await modal.evaluate((root, value) => {
            root.querySelector(`[data-role-module="module_dispatch"] input[type=radio][value="${value}"]`).checked = true;
        }, String(level));
        await submit(modal, 'edit_role');
    }
    return current;
}

test('dispatch role: View sees the board but cannot change it', async ({ page, browser }) => {
    // The technician's role, from their user form
    await visit(page, `admin/users.php?q=${encodeURIComponent(process.env.ITFLOW_TECH_EMAIL)}`);
    const quick = await page.locator('[data-modal-url*="modals/user/user.php?id="]').first().getAttribute('data-modal-url');
    const userId = new URL(quick, page.url()).searchParams.get('id');
    const userModal = await openModal(page, `modals/user/user_edit.php?id=${userId}`, 'lg');
    made.roleId = Number(await userModal.locator('select[name=role]').inputValue());
    expect(made.roleId).toBeGreaterThan(0);
    const current = await dispatchLevel(page);
    test.skip(current === null, 'needs database update M035');
    made.restore = current;
    await dispatchLevel(page, 1);
    expect(await dispatchLevel(page), 'saved as View').toBe('1');

    const tech = await techPage(browser);
    const errors = watch(tech);
    await visit(tech, 'agent/mit_dispatch.php', errors);
    test.skip(await tech.locator('#mitDispatch').count() === 0, 'Dispatch switched off');
    await expect(tech.locator('[data-md-view-only]'), 'marked View only').toBeVisible();
    await expect(tech.locator('.app-sidebar a[href="/agent/mit_dispatch.php"]'), 'still in the menu').toHaveCount(1);
    const answers = await tech.evaluate(async () => {
        const csrf = document.getElementById('mitDispatch').dataset.csrf;
        const queue = await fetch('/agent/mit_dispatch_ajax.php?queue=unassigned&board=all');
        const body = new URLSearchParams({ assign: '1', csrf_token: csrf, ticket_id: '1', tech_id: '1' });
        const assign = await fetch('/agent/mit_dispatch_ajax.php', { method: 'POST', body });
        return { queue: queue.status, assign: assign.status, canEdit: document.getElementById('mitDispatch').dataset.canEdit };
    });
    expect(answers.queue, 'the queue loads').toBe(200);
    expect(answers.canEdit, 'the board is drawn read-only').toBe('0');
    expect(answers.assign, 'assigning is refused').toBe(403);
    await expectClean(tech, errors);
    await tech.context().close();
});

test('dispatch role: no Dispatch, no board', async ({ page, browser }) => {
    test.skip(made.restore === null, 'not set up');
    await dispatchLevel(page, 0);
    const tech = await techPage(browser);
    await tech.goto('agent/dashboard.php');
    await expect(tech.locator('.app-sidebar a[href="/agent/mit_dispatch.php"]'), 'not in the menu').toHaveCount(0);
    await tech.goto('agent/mit_dispatch.php');
    await expect(tech.locator('#mitDispatch'), 'the page is refused').toHaveCount(0);
    const status = await tech.evaluate(async () => (await fetch('/agent/mit_dispatch_ajax.php?queue=unassigned&board=all')).status);
    expect(status, 'its requests are refused').toBe(403);
    await tech.context().close();
});

test.afterAll(async ({ browser }) => {
    if (made.restore === null) {
        return;
    }
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    await dispatchLevel(await context.newPage(), made.restore).catch(() => {});
    await context.close();
});
