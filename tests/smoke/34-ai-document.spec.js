// Write up (functions/mit_ai_docs.php, agent/modals/mit_ai/document.php) against the test install's AI stand-in (ai-mock/index.php -
// skipped without it; for a document it answers HTML with a <script> and a password in it on purpose): after a question in the Ask
// AI panel, "Write up" opens ITFlow's New Document form filled in by AI for the ticket's client - title, summary and steps, without
// the script or the password -, and Create document keeps it with that client through ITFlow's own form. Removes what it makes.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

const MOCK = 'http://127.0.0.1/ai-mock/index.php';
const made = { clientName: smokeName('Write up client'), clientId: 0, tickets: [], providerId: 0, saved: null };

function ticket(subject, details, resolved) {
    const number = Number(db.val('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
    db.exec('UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
    const id = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_subject = ${db.q(subject)}, ticket_details = ${db.q(details)},
        ticket_priority = 'Low', ticket_status = ${resolved ? 4 : 1}, ticket_created_by = 1, ticket_url_key = 'smoke${Date.now()}${number}', ticket_client_id = ${made.clientId}
        ${resolved ? ', ticket_resolved_at = NOW()' : ''}`);
    made.tickets.push(id);
    return id;
}

test.beforeAll(async ({ request }) => {
    if (!(await request.get(MOCK + '/_clear').then((r) => r.ok()).catch(() => false))) {
        return;
    }
    made.saved = {};
    for (const name of ['ai_helper_enabled', 'ai_helper_internal', 'ai_helper_documents', 'ai_helper_embed_provider', 'ai_helper_embed_model', 'ai_helper_monthly_tokens']) {
        made.saved[name] = db.val(`SELECT setting_value FROM mit_settings WHERE setting_name = ${db.q(name)}`);
    }
    db.exec("DELETE FROM ai_providers WHERE ai_provider_name LIKE 'ZZ %stand-in%'");
    made.providerId = db.insert(`INSERT INTO ai_providers SET ai_provider_name = 'ZZ Smoke stand-in', ai_provider_kind = 'custom', ai_provider_api_url = '${MOCK}/v1/chat/completions', ai_provider_api_key = 'mock', ai_provider_redact = 1`);
    db.exec(`INSERT INTO ai_models SET ai_model_name = 'mock-chat', ai_model_use_case = 'General', ai_model_ai_provider_id = ${made.providerId}`);
    const set = (name, value) => db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(name)}, setting_value = ${db.q(value)} ON DUPLICATE KEY UPDATE setting_value = ${db.q(value)}`);
    for (const [name, value] of Object.entries({ ai_helper_enabled: '1', ai_helper_internal: '1', ai_helper_documents: '1', ai_helper_embed_provider: String(made.providerId),
        ai_helper_embed_model: 'mock-embed', ai_helper_monthly_tokens: '0' })) {
        set(name, value);
    }
    made.clientId = db.insert(`INSERT INTO clients SET client_name = ${db.q(made.clientName)}, client_currency_code = 'USD', client_net_terms = 30`);
    const fixed = ticket(made.clientName + ' label printer prints blank', '<p>Labels come out blank.</p>', true);
    db.exec(`INSERT INTO ticket_replies SET ticket_reply = '<p>Fixed: the label stock was loaded upside down; turned the roll and calibrated.</p>', ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = ${fixed}`);
    made.open = ticket(made.clientName + ' labels blank again', '<p>Blank labels again at shipping.</p>', false);
    db.php('mitAiIndexReset(); mitSetSetting("ai_helper_index_version", mitAiIndexVersion()); for ($i = 0; $i < 20; $i++) { mitAiIndexRun(50); if (mitSetting("ai_helper_pass_started", "") === "") break; } mitAiEmbedPending(30);');
});

test.afterAll(() => {
    if (!made.saved) {
        return;
    }
    db.exec(`DELETE FROM documents WHERE document_client_id = ${made.clientId}`);
    db.exec("DELETE FROM mit_ai_conversations WHERE title LIKE 'Blank labels%' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    for (const id of made.tickets) {
        db.exec(`DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${id}; DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${id}; DELETE FROM tickets WHERE ticket_id = ${id}`);
    }
    db.exec(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
    db.exec(`DELETE FROM ai_usage WHERE ai_usage_provider_id = ${made.providerId}; DELETE FROM ai_providers WHERE ai_provider_id = ${made.providerId}; DELETE FROM mit_ai_sources`);
    for (const [name, value] of Object.entries(made.saved)) {
        db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(name)}, setting_value = ${db.q(value ?? '')} ON DUPLICATE KEY UPDATE setting_value = ${db.q(value ?? '')}`);
    }
    db.exec("UPDATE mit_settings SET setting_value = '' WHERE setting_name = 'ai_helper_index_version'");
});

test('Write up: a conversation becomes a document for the ticket\'s client', async ({ page }) => {
    test.skip(!made.saved, 'no AI stand-in on this test install');
    test.setTimeout(90000);
    const errors = watch(page);
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.open}`, errors);
    await page.locator('[data-mit-ai-open]').click();
    const panel = page.locator('#mitAiPanel');
    await expect(panel.locator('[data-mit-ai-writeup]')).toBeHidden(); // nothing to write up yet
    await panel.locator('[data-mit-ai-question]').fill('Blank labels - how was it fixed before?');
    await panel.locator('[data-mit-ai-question]').press('Enter');
    await expect(panel.locator('[data-mit-ai-message=assistant]')).toHaveCount(1, { timeout: 30000 });
    await panel.locator('[data-mit-ai-writeup]').click();

    const form = page.locator('[data-mit-ai-doc-form]');
    await expect(form.locator('[data-mit-ai-doc-status][data-mit-ai-doc-ready]')).toBeVisible({ timeout: 30000 });
    await expect(form.locator('[data-mit-ai-doc-client]')).toHaveValue(String(made.clientId));
    await expect(form.locator('[name=name]')).toHaveValue('Mock article');
    await expect(form.locator('[name=description]')).toHaveValue('Mock summary of the fix.');
    const html = await page.evaluate(() => (window.tinymce && tinymce.activeEditor ? tinymce.activeEditor.getContent() : document.querySelector('[data-mit-ai-doc-form] textarea[name=content]').value));
    expect(html).toContain('Do the first step from the material.');
    expect(html).not.toContain('MockDocLeak9');
    expect(html).not.toContain('<script');

    await form.locator('button[name=add_document]').click();
    await page.waitForLoadState('load');
    const kept = db.one(`SELECT document_name, REPLACE(document_content, CHAR(10), ' ') AS content FROM documents WHERE document_client_id = ${made.clientId} ORDER BY document_id DESC LIMIT 1`);
    expect(kept && kept.document_name).toBe('Mock article');
    expect(kept.content).toContain('Do the first step from the material.');
    expect(kept.content).not.toContain('MockDocLeak9');
    await expectClean(page, errors);
});
