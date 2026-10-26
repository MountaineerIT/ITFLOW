// The Ask AI panel (functions/mit_ai_ask.php, agent/js/mit_ai_helper.js) against the test install's AI stand-in (ai-mock/index.php -
// skipped without it): the top bar's Ask AI opens the panel; a question gets an answer citing a past ticket (linked) without the
// password written in that ticket; thumbs up is kept; earlier conversations list it; on a ticket page "About this ticket" ties the
// conversation to the ticket; a Technician limited to another client gets nothing of this client's; the helper switched off takes
// the button away. Makes its own AI provider / model, client and tickets, and removes them.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

const MOCK = 'http://127.0.0.1/ai-mock/index.php';
const SECRET = 'SmkPanelSecret!42';
const techState = path.join(__dirname, '.auth', 'tech.json');
const made = { clientName: smokeName('Ask AI client'), clientId: 0, tickets: [], providerId: 0, saved: null, techUser: 0, techAccess: null };

test.describe.configure({ mode: 'serial' });

function ticket(subject, details, resolved) {
    const number = Number(db.val('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
    db.exec('UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
    const id = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_subject = ${db.q(subject)}, ticket_details = ${db.q(details)},
        ticket_priority = 'Low', ticket_status = ${resolved ? 4 : 1}, ticket_created_by = 1, ticket_url_key = 'smoke${Date.now()}${number}', ticket_client_id = ${made.clientId}
        ${resolved ? ', ticket_resolved_at = NOW()' : ''}`);
    made.tickets.push(id);
    return { id, ref: 'TCK-' + number };
}

test.beforeAll(async ({ request }) => {
    const alive = await request.get(MOCK + '/_clear').then((r) => r.ok()).catch(() => false);
    if (!alive) {
        return;
    }
    made.saved = {};
    for (const name of ['ai_helper_enabled', 'ai_helper_internal', 'ai_helper_documents', 'ai_helper_embed_provider', 'ai_helper_embed_model', 'ai_helper_monthly_tokens']) {
        made.saved[name] = db.val(`SELECT setting_value FROM mit_settings WHERE setting_name = ${db.q(name)}`);
    }
    db.exec("DELETE FROM ai_providers WHERE ai_provider_name LIKE 'ZZ %stand-in%'");
    made.providerId = db.insert(`INSERT INTO ai_providers SET ai_provider_name = 'ZZ Smoke stand-in', ai_provider_kind = 'custom', ai_provider_api_url = '${MOCK}/v1/chat/completions', ai_provider_api_key = 'mock', ai_provider_redact = 1`);
    db.exec(`INSERT INTO ai_models SET ai_model_name = 'mock-chat', ai_model_use_case = 'Tickets', ai_model_ai_provider_id = ${made.providerId}`);
    const set = (name, value) => db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(name)}, setting_value = ${db.q(value)} ON DUPLICATE KEY UPDATE setting_value = ${db.q(value)}`);
    set('ai_helper_enabled', '1');
    set('ai_helper_internal', '1');
    set('ai_helper_documents', '1');
    set('ai_helper_embed_provider', String(made.providerId));
    set('ai_helper_embed_model', 'mock-embed');
    set('ai_helper_monthly_tokens', '0');
    made.clientId = db.insert(`INSERT INTO clients SET client_name = ${db.q(made.clientName)}, client_currency_code = 'USD', client_net_terms = 30`);
    made.fixed = ticket(made.clientName + ' scanner will not send to email', '<p>The copier scanner stopped sending scans to email.</p>', true);
    db.exec(`INSERT INTO ticket_replies SET ticket_reply = '<p>Fixed: the SMTP relay connector needed the copier IP added. Scan to email works.</p>', ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = ${made.fixed.id};
        INSERT INTO ticket_replies SET ticket_reply = '<p>Copier admin password is ${SECRET}</p>', ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = ${made.fixed.id}`);
    made.open = ticket(made.clientName + ' scans not arriving', '<p>Scans from the front copier never arrive in mailboxes.</p>', false);
    db.php('mitAiIndexReset(); mitSetSetting("ai_helper_index_version", mitAiIndexVersion()); for ($i = 0; $i < 20; $i++) { mitAiIndexRun(50); if (mitSetting("ai_helper_pass_started", "") === "") break; } mitAiEmbedPending(30);');
});

test.afterAll(() => {
    if (!made.saved) {
        return;
    }
    if (made.techUser && made.techAccess !== null) {
        db.exec(`DELETE FROM user_client_permissions WHERE user_id = ${made.techUser}`);
        for (const row of made.techAccess) {
            db.exec(`INSERT INTO user_client_permissions SET user_id = ${made.techUser}, client_id = ${row.client_id}, permission_type = ${db.q(row.permission_type)}`);
        }
    }
    db.exec(`DELETE c FROM mit_ai_conversations c WHERE c.title LIKE '%scan%' AND c.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)`);
    for (const id of made.tickets) {
        db.exec(`DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${id}; DELETE FROM tickets WHERE ticket_id = ${id}`);
    }
    if (made.clientId) {
        db.exec(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
    db.exec(`DELETE FROM ai_usage WHERE ai_usage_provider_id = ${made.providerId}; DELETE FROM ai_providers WHERE ai_provider_id = ${made.providerId}; DELETE FROM mit_ai_sources`);
    for (const [name, value] of Object.entries(made.saved)) {
        db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(name)}, setting_value = ${db.q(value ?? '')} ON DUPLICATE KEY UPDATE setting_value = ${db.q(value ?? '')}`);
    }
    db.exec("UPDATE mit_settings SET setting_value = '' WHERE setting_name = 'ai_helper_index_version'");
});

async function ask(page, text) {
    const panel = page.locator('#mitAiPanel');
    await panel.locator('[data-mit-ai-question]').fill(text);
    await panel.locator('[data-mit-ai-question]').press('Enter');
    await expect(panel.locator('[data-mit-ai-waiting]')).toHaveCount(0, { timeout: 30000 });
    return panel.locator('[data-mit-ai-message=assistant]').last();
}

test('Ask AI: question, cited answer, thumbs up, earlier conversations', async ({ page }) => {
    test.skip(!made.saved, 'no AI stand-in on this test install');
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    await page.locator('[data-mit-ai-open]').click();
    await expect(page.locator('#mitAiPanel')).toBeVisible();
    const answer = await ask(page, 'How do we fix scan to email from the copier?');
    await expect(answer).toContainText('MOCK ANSWER');
    const source = answer.locator('[data-mit-ai-sources] a', { hasText: 'scanner will not send to email' }); // (the name's timestamp reads as a card number to ITFlow's filter)
    await expect(source).toHaveAttribute('href', new RegExp(`ticket_id=${made.fixed.id}`));
    await expect(page.locator('#mitAiPanel')).not.toContainText(SECRET);
    const sent = await page.request.get(MOCK + '/_calls').then((r) => r.text());
    expect(sent).toContain('SMTP relay connector');
    expect(sent).not.toContain(SECRET);

    await answer.locator('[data-mit-ai-rate="1"]').click();
    await expect(answer.locator('[data-mit-ai-rate="1"] i.fas')).toHaveCount(1);
    expect(db.val("SELECT m.feedback FROM mit_ai_messages m JOIN mit_ai_conversations c USING (conversation_id) WHERE c.title LIKE 'How do we fix scan to email%' AND m.role = 'assistant' ORDER BY m.message_id DESC LIMIT 1")).toBe('1');

    await page.locator('[data-mit-ai-history]').click();
    const row = page.locator('[data-mit-ai-conversation]', { hasText: 'How do we fix scan to email' }).first();
    await expect(row).toBeVisible();
    await page.locator('[data-mit-ai-new]').click();
    await expect(page.locator('[data-mit-ai-thread] [data-mit-ai-message]')).toHaveCount(0);
    await page.locator('[data-mit-ai-history]').click();
    await row.click();
    await expect(page.locator('[data-mit-ai-thread] [data-mit-ai-message]')).toHaveCount(2);
    await expectClean(page, errors);
});

test('Ask AI on a ticket: about this ticket', async ({ page }) => {
    test.skip(!made.saved, 'no AI stand-in on this test install');
    const errors = watch(page);
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.open.id}`, errors);
    await page.locator('[data-mit-ai-open]').click();
    await expect(page.locator('#mitAiPanel [data-mit-ai-about]')).toBeChecked();
    await expect(page.locator('#mitAiPanel')).toContainText(`About this ticket (${made.open.ref})`);
    await page.request.get(MOCK + '/_clear');
    const answer = await ask(page, 'What should I try next on this one?');
    await expect(answer).toContainText('MOCK ANSWER');
    expect(db.val("SELECT ticket_id FROM mit_ai_conversations WHERE title = 'What should I try next on this one?' ORDER BY conversation_id DESC LIMIT 1")).toBe(String(made.open.id));
    const sent = await page.request.get(MOCK + '/_calls').then((r) => r.text());
    expect(sent).toContain('never arrive in mailboxes'); // the open ticket went with the question
    await expectClean(page, errors);
});

test('Ask AI as a Technician limited to another client', async ({ browser }) => {
    test.skip(!made.saved || !fs.existsSync(techState), 'no AI stand-in or no technician login');
    made.techUser = Number(db.val(`SELECT user_id FROM users WHERE user_email = ${db.q(process.env.ITFLOW_TECH_EMAIL)}`));
    made.techAccess = db.rows(`SELECT client_id, permission_type FROM user_client_permissions WHERE user_id = ${made.techUser}`);
    const other = Number(db.val(`SELECT client_id FROM clients WHERE client_id <> ${made.clientId} AND client_archived_at IS NULL ORDER BY client_id LIMIT 1`));
    db.exec(`DELETE FROM user_client_permissions WHERE user_id = ${made.techUser}; INSERT INTO user_client_permissions SET user_id = ${made.techUser}, client_id = ${other}, permission_type = 'allow'`);
    const context = await browser.newContext({ storageState: techState, baseURL: test.info().project.use.baseURL, ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    await page.locator('[data-mit-ai-open]').click();
    await page.request.get(MOCK + '/_clear');
    const answer = await ask(page, 'How do we fix scan to email from the copier?');
    await expect(answer).toContainText('MOCK ANSWER');
    await expect(answer).not.toContainText(made.clientName);
    const sent = await page.request.get(MOCK + '/_calls').then((r) => r.text());
    expect(sent).not.toContain('SMTP relay connector'); // that fix is the other client's
    await context.close();
});

test('Ask AI switched off: no button', async ({ page }) => {
    test.skip(!made.saved, 'no AI stand-in on this test install');
    db.exec("UPDATE mit_settings SET setting_value = '0' WHERE setting_name = 'ai_helper_enabled'");
    await visit(page, 'agent/dashboard.php');
    await expect(page.locator('[data-mit-ai-open]')).toHaveCount(0);
});
