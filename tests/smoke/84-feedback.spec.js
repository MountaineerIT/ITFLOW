// Bugs & ideas (functions/mit_feedback.php, the top bar's button): the admin sends a bug with a screenshot from a page - the page,
// build and picture are kept, the picture only through the handler (the folder is closed to the web) and drawn as text where people
// typed; the technician sees it but can't close or delete it, sends a feature request (the admins' bell rings); the admin marks it
// done with a note (the technician's bell rings), it moves to Done, and reopens; the technician deletes their own open one only.
// It is off until an admin turns it on in Admin > Debug: off, there is no button and the handler refuses. Everything it makes is
// removed afterwards and the switch put back as it was.

const path = require('path');
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

const techState = path.join(__dirname, '.auth', 'tech.json');
const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';
const made = { ids: [] };
const bugTitle = smokeName('bug <b>bold</b>');
const ideaTitle = smokeName('idea');
// A small PNG, made by GD
const PNG = Buffer.from(db.php('$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 200, 30, 30)); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());').trim(), 'base64');

test.describe.configure({ mode: 'serial' });

test.afterAll(() => {
    if (made.wasOn !== undefined) {
        db.exec(`INSERT INTO mit_settings SET setting_name = 'feedback_enabled', setting_value = ${db.q(made.wasOn)} ON DUPLICATE KEY UPDATE setting_value = ${db.q(made.wasOn)}`);
    }
    const rows = db.rows(`SELECT feedback_id, image FROM mit_feedback WHERE title IN (${db.q(bugTitle)}, ${db.q(ideaTitle)})`);
    for (const row of rows) {
        if (row.image) {
            try { fs.unlinkSync(path.join(ROOT, row.image)); } catch (e) { /* gone */ }
        }
        db.exec(`DELETE FROM notifications WHERE notification_type = 'Feedback' AND notification_entity_id = ${row.feedback_id}`);
        db.exec(`DELETE FROM mit_feedback WHERE feedback_id = ${row.feedback_id}`);
    }
});

async function openPanel(page) {
    await page.locator('[data-mit-feedback-open]').click();
    const panel = page.locator('#mitFeedbackPanel');
    await expect(panel).toBeVisible();
    return panel;
}

test('off by default; an admin turns it on in Admin > Debug', async ({ page }) => {
    test.skip(!db.val("SHOW TABLES LIKE 'mit_feedback'"), 'Bugs & ideas is not set up here (M047)');
    made.wasOn = db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'feedback_enabled'") || '0';
    db.exec("DELETE FROM mit_settings WHERE setting_name = 'feedback_enabled'");
    const errors = watch(page);
    await visit(page, 'agent/tickets.php', errors);
    await expect(page.locator('[data-mit-feedback-open]'), 'no button while it is off (the default)').toHaveCount(0);
    // fetch from the page (the browser knows the test host; Playwright's own request client does not)
    const refused = await page.evaluate(() => fetch('/agent/mit_feedback_ajax.php?list=open').then((r) => r.status));
    expect(refused, 'and the handler refuses').toBe(403);

    await visit(page, 'admin/debug.php', errors);
    const card = page.locator('[data-mit-feedback-card]');
    await expect(card, 'the switch on Admin > Debug').toBeVisible();
    await expect(card.locator('[data-mit-feedback-enabled]')).not.toBeChecked();
    await card.locator('[data-mit-feedback-enabled]').check();
    await Promise.all([page.waitForLoadState('load'), card.locator('button[name=mit_feedback_settings]').click()]);
    await expect(page.locator('[data-mit-feedback-card] [data-mit-feedback-enabled]')).toBeChecked();
    expect(db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'feedback_enabled'"), 'saved').toBe('1');
    await expect(page.locator('[data-mit-feedback-open]'), 'the button is back').toHaveCount(1);
    await expectClean(page, errors);
});

test('admin: a bug with a screenshot, from the page it was on', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/tickets.php', errors);
    test.skip(!db.val("SHOW TABLES LIKE 'mit_feedback'"), 'Bugs & ideas is not set up here (M047)');
    await expect(page.locator('[data-mit-feedback-open]'), 'the button in the top bar').toHaveCount(1);
    const panel = await openPanel(page);
    await panel.locator('[data-mit-feedback-new]').click();
    await panel.locator('[name=title]').fill(bugTitle);
    await panel.locator('[name=details]').fill('Steps: open Tickets.\n<script>window.mitFeedbackXss = 1</script>');
    await panel.locator('input[type=file]').setInputFiles({ name: 'shot.png', mimeType: 'image/png', buffer: PNG });
    await expect(panel.locator('[data-mit-feedback-preview] img'), 'the screenshot is shown before sending').toBeVisible();
    await panel.locator('[data-mit-feedback-send]').click();
    const item = panel.locator('[data-mit-feedback-item]', { hasText: bugTitle });
    await expect(item).toBeVisible();

    const row = db.one(`SELECT feedback_id, kind, status, page, version, image FROM mit_feedback WHERE title = ${db.q(bugTitle)}`); // not details: it has line breaks
    made.bug = Number(row.feedback_id);
    expect([row.kind, row.status], 'an open bug').toEqual(['bug', 'open']);
    expect(row.page, 'kept with the page it came from (Tickets may add the agent\'s remembered filters)').toMatch(/^\/agent\/tickets\.php(\?|$)/);
    expect(row.version, 'and our build').toMatch(/^\d+\.\d+(\.\d+){1,2} \/ ITFlow /);
    expect(row.image, 'the screenshot stored in its own folder').toMatch(/^uploads\/mit_feedback\/[a-f0-9]{32}\.png$/);

    // Typed text is text, not HTML
    await expect(item.locator('.mit-feedback-title')).toHaveText(bugTitle);
    expect(await page.evaluate(() => window.mitFeedbackXss), 'nothing typed runs').toBeUndefined();
    // The picture only through the handler
    // no referer: 99-php-log reads a refused request from a page as a broken link
    const direct = await page.evaluate(async (file) => (await fetch('/' + file, { referrerPolicy: 'no-referrer' })).status, row.image);
    expect(direct, 'the folder is closed to the web').toBe(403);
    const served = await page.evaluate(async (id) => { const r = await fetch(`/agent/mit_feedback_ajax.php?image=${id}`); return [r.status, r.headers.get('content-type')]; }, made.bug);
    expect(served).toEqual([200, 'image/png']);
    await expectClean(page, errors);
});

test('technician: sees the bug, can\'t close or delete it; sends a feature request (admins\' bell)', async ({ browser }) => {
    test.skip(!fs.existsSync(techState) || !made.bug, 'no technician login / no bug');
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: techState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    const panel = await openPanel(page);
    const bug = panel.locator('[data-mit-feedback-item]', { hasText: bugTitle });
    await expect(bug, 'everyone sees every report').toBeVisible();
    await expect(bug.locator('[data-act]'), 'no Mark done / delete on someone else\'s').toHaveCount(0);
    const token = await panel.getAttribute('data-csrf');
    const refused = await db.pagePost(page, '/agent/mit_feedback_ajax.php', { csrf_token: token, done: '1', id: String(made.bug) });
    expect(refused.status, 'only admins mark done').toBe(403);
    const notDeleted = await db.pagePost(page, '/agent/mit_feedback_ajax.php', { csrf_token: token, delete: '1', id: String(made.bug) });
    expect(notDeleted.status, 'nor delete someone else\'s').toBe(403);

    await panel.locator('[data-mit-feedback-new]').click();
    await panel.locator('label[for=mitFeedbackIdea]').click();
    await panel.locator('[name=title]').fill(ideaTitle);
    await panel.locator('[data-mit-feedback-send]').click();
    await expect(panel.locator('[data-mit-feedback-item]', { hasText: ideaTitle })).toBeVisible();
    made.idea = Number(db.val(`SELECT feedback_id FROM mit_feedback WHERE title = ${db.q(ideaTitle)} AND kind = 'feature'`));
    expect(made.idea).toBeGreaterThan(0);
    expect(Number(db.val(`SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id = n.notification_user_id JOIN user_roles r ON r.role_id = u.user_role_id
        WHERE n.notification_type = 'Feedback' AND n.notification_entity_id = ${made.idea} AND r.role_is_admin = 1`)), 'the admins\' bell').toBeGreaterThan(0);
    await expectClean(page, errors);
    await context.close();
});

test('admin: marks the request done with a note (the sender is told), Done tab, reopen', async ({ page }) => {
    test.skip(!made.idea, 'no feature request');
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    const panel = await openPanel(page);
    const idea = panel.locator('[data-mit-feedback-item]', { hasText: ideaTitle });
    await idea.locator('[data-act=done]').click();
    await idea.locator('[data-mit-feedback-note]').fill('Added in 26.10.176');
    await idea.locator('[data-act=done-save]').click();
    await expect(panel.locator('[data-mit-feedback-item]', { hasText: ideaTitle }), 'gone from Open').toHaveCount(0);
    const row = db.one(`SELECT status, done_note, created_by FROM mit_feedback WHERE feedback_id = ${made.idea}`);
    expect([row.status, row.done_note]).toEqual(['done', 'Added in 26.10.176']);
    expect(Number(db.val(`SELECT COUNT(*) FROM notifications WHERE notification_type = 'Feedback' AND notification_entity_id = ${made.idea}
        AND notification_user_id = ${row.created_by} AND notification LIKE 'Done:%'`)), 'the sender\'s bell').toBe(1);

    await panel.locator('[data-mit-feedback-tab=done]').click();
    const done = panel.locator('[data-mit-feedback-item]', { hasText: ideaTitle });
    await expect(done.locator('.mit-feedback-note')).toContainText('Added in 26.10.176');
    await done.locator('[data-act=reopen]').click();
    await expect(panel.locator('[data-mit-feedback-item]', { hasText: ideaTitle }), 'gone from Done').toHaveCount(0);
    expect(db.val(`SELECT status FROM mit_feedback WHERE feedback_id = ${made.idea}`)).toBe('open');

    // The bell's link opens the panel on the report
    await visit(page, `agent/dashboard.php?mit_feedback=${made.bug}`, errors);
    await expect(page.locator('#mitFeedbackPanel .mit-feedback-hit'), 'the report is picked out').toContainText(bugTitle);
    await expectClean(page, errors);
});

test('technician: deletes their own open request', async ({ browser }) => {
    test.skip(!fs.existsSync(techState) || !made.idea, 'no technician login / no request');
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: techState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    await visit(page, 'agent/dashboard.php');
    const panel = await openPanel(page);
    page.once('dialog', (d) => d.accept());
    await panel.locator('[data-mit-feedback-item]', { hasText: ideaTitle }).locator('[data-act=delete]').click();
    await expect(panel.locator('[data-mit-feedback-item]', { hasText: ideaTitle })).toHaveCount(0);
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_feedback WHERE feedback_id = ${made.idea}`))).toBe(0);
    await context.close();
});
