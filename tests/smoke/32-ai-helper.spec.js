// The AI helper's library (functions/mit_ai_helper.php), Admin > Integrations > AI helper: switched on through its form, a resolved
// ticket with a fix and a password written into a note goes into the library (the cron job's mitAiIndexRun, run here directly), the
// page's test search finds the fix, shows where it came from and never the password, the ticket is listed under "Passwords taken out",
// and Rebuild empties the library. Puts the settings back and removes its client and ticket. (The password wall itself is checked
// in depth by tests/scenarios/scenario-ai-wall.php; the code by scripts/mit_ai_wall_check.php.)

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, submit, flash, expectClean } = require('./helpers');
const db = require('./mitdb');

const SECRET = 'SmkCanary!77x';
const made = { clientName: smokeName('AI helper client'), clientId: 0, ticketId: 0, saved: null };

test.afterAll(() => {
    if (made.ticketId) {
        db.exec(`DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticketId}; DELETE FROM tickets WHERE ticket_id = ${made.ticketId}`);
    }
    if (made.clientId) {
        db.exec(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
    if (made.saved) {
        for (const [name, value] of Object.entries(made.saved)) {
            db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(name)}, setting_value = ${db.q(value ?? '')} ON DUPLICATE KEY UPDATE setting_value = ${db.q(value ?? '')}`);
        }
    }
});

test('AI helper: library, test search, passwords taken out, rebuild', async ({ page }) => {
    test.setTimeout(120000);
    const errors = watch(page);
    made.saved = {};
    for (const name of ['ai_helper_enabled', 'ai_helper_internal', 'ai_helper_documents', 'ai_helper_embed_provider', 'ai_helper_embed_model', 'ai_helper_index_version']) {
        made.saved[name] = db.val(`SELECT setting_value FROM mit_settings WHERE setting_name = ${db.q(name)}`);
    }

    // A resolved ticket: the fix in an internal note, a password in another
    made.clientId = db.insert(`INSERT INTO clients SET client_name = ${db.q(made.clientName)}, client_currency_code = 'USD', client_net_terms = 30`);
    const number = Number(db.val('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
    db.exec(`UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1`);
    made.ticketId = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_subject = ${db.q(made.clientName + ' label printer prints off the edge')},
        ticket_details = '<p>Labels print shifted.</p>', ticket_priority = 'Low', ticket_status = 4, ticket_created_by = 1, ticket_url_key = 'smoke${Date.now()}',
        ticket_client_id = ${made.clientId}, ticket_resolved_at = NOW()`);
    db.exec(`INSERT INTO ticket_replies SET ticket_reply = '<p>Fixed by recalibrating with the Zebra Setup Utility (media type gap).</p>', ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = ${made.ticketId};
        INSERT INTO ticket_replies SET ticket_reply = '<p>The printer web page password is ${SECRET}</p>', ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = ${made.ticketId}`);

    // Switch it on through the page - keyword search only
    await visit(page, 'admin/mit_ai_helper.php', errors);
    await expect(page.locator('.app-sidebar a[href="/admin/mit_ai_helper.php"]')).toBeVisible();
    const form = page.locator('form:has(button[name=mit_ai_helper_save])');
    await form.locator('input[name=ai_helper_enabled]').setChecked(true);
    await form.locator('input[name=ai_helper_internal]').setChecked(true);
    await form.locator('input[name=ai_helper_documents]').setChecked(true);
    await form.locator('select[name=ai_helper_embed_provider]').selectOption('0');
    await submit(form, 'mit_ai_helper_save');
    expect(await flash(page)).toContain('AI helper saved');
    expect(db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'ai_helper_enabled'")).toBe('1');

    // What the cron job does
    db.php('mitAiIndexReset(); mitSetSetting("ai_helper_index_version", mitAiIndexVersion()); for ($i = 0; $i < 10; $i++) { mitAiIndexRun(50); if (mitSetting("ai_helper_pass_started", "") === "") break; }');
    const stored = db.val(`SELECT GROUP_CONCAT(REPLACE(k.chunk_text, CHAR(10), ' ') SEPARATOR ' ') AS t FROM mit_ai_chunks k JOIN mit_ai_sources s USING (source_id) WHERE s.ticket_id = ${made.ticketId}`);
    expect(stored).toContain('Zebra Setup Utility');
    expect(stored).not.toContain(SECRET);

    await visit(page, 'admin/mit_ai_helper.php?q=' + encodeURIComponent('label printer recalibrating'), errors);
    expect(Number(await page.locator('[data-mit-ai-stat=tickets]').innerText())).toBeGreaterThan(0);
    const hit = page.locator('[data-mit-ai-hit=ticket]', { hasText: made.clientName });
    await expect(hit).toBeVisible();
    await expect(hit).toContainText('Zebra Setup Utility');
    await expect(hit.locator('a')).toHaveAttribute('href', new RegExp(`ticket_id=${made.ticketId}`));
    await expect(page.locator('body')).not.toContainText(SECRET);
    await expect(page.locator('[data-mit-ai-scrubbed] a', { hasText: made.clientName })).toBeVisible();

    // Rebuild empties it (the cron job fills it again)
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('button[name=mit_ai_helper_rebuild]').click();
    await page.waitForLoadState('load');
    expect(await flash(page)).toContain('being built again');
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_ai_sources WHERE ticket_id = ${made.ticketId}`))).toBe(0);
    await expectClean(page, errors);
});
