// Pictures in the Ask AI panel (26.11.8.5): add one with the picture button, see it as a thumbnail with the warning that pictures go to the AI
// provider as they are, take it off, paste one in (Ctrl+V), ask with no text - the answer comes back, the question bubble says a picture went
// with it, the provider got it as an image_url part, and nothing of it is stored. A throw-away stand-in provider (a PHP file put in the
// install for this test - ITFLOW_ROOT) records what reaches it; it, its provider / model rows and the settings changed are put back.

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { watch, visit, expectClean } = require('./helpers');
const db = require('./db');

const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';
const ECHO = path.join(ROOT, 'zz_smoke_ai_echo.php');
const RECORD = path.join(os.tmpdir(), `zz_smoke_ai_echo_${process.pid}.json`);
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const SETTINGS = ['ai_helper_enabled', 'ai_helper_embed_provider', 'ai_helper_monthly_tokens'];

test('Ask AI: add, take off and paste a picture, then ask about it', async ({ page }) => {
    test.skip(!fs.existsSync(path.join(ROOT, 'functions', 'mit_ai_ask.php')), `no install at ${ROOT} (set ITFLOW_ROOT)`);
    test.skip(db.value("SHOW TABLES LIKE 'mit_ai_messages'") === '', 'needs database update M045');
    const saved = {};
    SETTINGS.forEach((name) => { saved[name] = db.rows(`SELECT setting_value AS v FROM mit_settings WHERE setting_name = ${db.q(name)}`)[0]; });
    let provider = 0;
    fs.writeFileSync(ECHO, `<?php
file_put_contents(${JSON.stringify(RECORD)}, file_get_contents('php://input'));
header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => ['content' => 'Stand-in: that is error 0x80070005, access denied.']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]);
`);
    try {
        db.run("INSERT INTO ai_providers SET ai_provider_name = 'ZZ smoke pictures', ai_provider_kind = 'custom', ai_provider_api_url = 'http://127.0.0.1/zz_smoke_ai_echo.php', ai_provider_api_key = 'x', ai_provider_redact = 1");
        provider = Number(db.value("SELECT MAX(ai_provider_id) FROM ai_providers WHERE ai_provider_name = 'ZZ smoke pictures'"));
        db.run("UPDATE ai_models SET ai_model_use_case = 'zz-Tickets' WHERE ai_model_use_case = 'Tickets'");
        db.run(`INSERT INTO ai_models SET ai_model_name = 'zz-vision', ai_model_use_case = 'Tickets', ai_model_ai_provider_id = ${provider}`);
        db.run("REPLACE INTO mit_settings (setting_name, setting_value) VALUES ('ai_helper_enabled', '1'), ('ai_helper_embed_provider', ''), ('ai_helper_monthly_tokens', '0')");

        const errors = watch(page);
        await visit(page, 'agent/dashboard.php', errors);
        await page.locator('[data-mit-ai-open]').click();
        const panel = page.locator('#mitAiPanel');
        await expect(panel.locator('[data-mit-ai-attach]'), 'the picture button').toBeVisible();
        await expect(panel.locator('[data-mit-ai-pics]')).toBeHidden();

        await panel.locator('[data-mit-ai-file]').setInputFiles({ name: 'error.png', mimeType: 'image/png', buffer: PNG });
        await expect(panel.locator('[data-mit-ai-pic]'), 'a thumbnail').toHaveCount(1);
        await expect(panel.locator('[data-mit-ai-pic-warning]'), 'the warning').toContainText('Pictures go to the AI provider as they are');
        await panel.locator('[data-mit-ai-pic-remove]').click();
        await expect(panel.locator('[data-mit-ai-pic]')).toHaveCount(0);
        await expect(panel.locator('[data-mit-ai-pics]')).toBeHidden();

        // Pasted, as a screenshot is
        await panel.locator('[data-mit-ai-question]').evaluate((el, b64) => {
            const bytes = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
            const data = new DataTransfer();
            data.items.add(new File([bytes], 'screenshot.png', { type: 'image/png' }));
            el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
        }, PNG.toString('base64'));
        await expect(panel.locator('[data-mit-ai-pic]'), 'a pasted picture').toHaveCount(1);
        await panel.locator('[data-mit-ai-question]').press('Enter');
        await expect(panel.locator('[data-mit-ai-waiting]')).toHaveCount(0, { timeout: 30000 });
        await expect(panel.locator('[data-mit-ai-message=assistant]').last()).toContainText('0x80070005');
        await expect(panel.locator('[data-mit-ai-message=user]').last(), 'the question says a picture went').toContainText('A picture was sent with this question - not kept.');
        await expect(panel.locator('[data-mit-ai-pic]'), 'sent, so gone from the box').toHaveCount(0);

        const body = JSON.parse(fs.readFileSync(RECORD, 'utf8'));
        const last = body.messages[body.messages.length - 1];
        expect(Array.isArray(last.content), 'sent in parts').toBe(true);
        expect(last.content.some((p) => p.type === 'image_url' && /^data:image\/png;base64,/.test(p.image_url.url)), 'the picture as image_url').toBe(true);
        const stored = db.column("SELECT m.content FROM mit_ai_messages m JOIN mit_ai_conversations c USING (conversation_id) WHERE c.title LIKE 'What does this show%'").join('\n');
        expect(stored).toContain('not kept');
        expect(stored).not.toContain('base64');
        await expectClean(page, errors);
    } finally {
        fs.rmSync(ECHO, { force: true });
        fs.rmSync(RECORD, { force: true });
        db.run("DELETE FROM mit_ai_conversations WHERE title LIKE 'What does this show%'");
        if (provider) {
            db.run(`DELETE FROM ai_models WHERE ai_model_ai_provider_id = ${provider}`);
            db.run(`DELETE FROM ai_usage WHERE ai_usage_provider_id = ${provider}`);
            db.run(`DELETE FROM ai_providers WHERE ai_provider_id = ${provider}`);
        }
        db.run("UPDATE ai_models SET ai_model_use_case = 'Tickets' WHERE ai_model_use_case = 'zz-Tickets'");
        SETTINGS.forEach((name) => {
            db.run(saved[name] ? `REPLACE INTO mit_settings (setting_name, setting_value) VALUES (${db.q(name)}, ${db.q(saved[name].v)})` : `DELETE FROM mit_settings WHERE setting_name = ${db.q(name)}`);
        });
    }
});
