// Local embeddings (functions/mit_ai_local.php) on Admin > Integrations > AI helper, against the test install's Ollama stand-in
// (ollama-mock/index.php - skipped without it): with Ollama not running the card gives the install command and no switch; running,
// "Use this server" downloads the model, adds ITFlow's Ollama provider and picks it for meaning search (On badge); "Switch off" goes
// back to what was set before. Puts the settings back and removes the provider it made.

const { test, expect } = require('@playwright/test');
const { watch, visit, flash, expectClean } = require('./helpers');
const db = require('./mitdb');

const MOCK = 'http://127.0.0.1/ollama-mock/index.php';
const NAMES = ['ai_helper_enabled', 'ai_helper_embed_provider', 'ai_helper_embed_model', 'ai_local_on', 'ai_local_provider', 'ai_local_prev_provider', 'ai_local_prev_model', 'ai_helper_embed_error'];
const made = { saved: null };

test.afterAll(async ({ request }) => {
    if (!made.saved) {
        return;
    }
    const local = Number(db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'ai_local_provider'") || 0);
    if (local) {
        db.exec(`DELETE FROM ai_usage WHERE ai_usage_provider_id = ${local}; DELETE FROM ai_providers WHERE ai_provider_id = ${local}`);
    }
    for (const [name, value] of Object.entries(made.saved)) {
        db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(name)}, setting_value = ${db.q(value ?? '')} ON DUPLICATE KEY UPDATE setting_value = ${db.q(value ?? '')}`);
    }
    await request.get(MOCK + '/_state?reset=1');
});

test('AI helper: local embeddings on this server - install hint, switch on, switch off', async ({ page }) => {
    test.setTimeout(90000);
    const alive = await page.request.get(MOCK + '/_state?reset=1&running=0').then((r) => r.ok()).catch(() => false);
    test.skip(!alive, 'no Ollama stand-in on this test install');
    made.saved = {};
    for (const name of NAMES) {
        made.saved[name] = db.val(`SELECT setting_value FROM mit_settings WHERE setting_name = ${db.q(name)}`);
    }
    const set = (name, value) => db.exec(`INSERT INTO mit_settings SET setting_name = ${db.q(name)}, setting_value = ${db.q(value)} ON DUPLICATE KEY UPDATE setting_value = ${db.q(value)}`);
    set('ai_helper_enabled', '1');
    set('ai_helper_embed_provider', '0');
    set('ai_local_on', '0');
    const errors = watch(page);

    // Not running: the install command, no switch
    await visit(page, 'admin/mit_ai_helper.php', errors);
    const card = page.locator('[data-mit-ai-local]');
    await expect(card).toHaveAttribute('data-running', '0');
    await expect(card).toContainText('install_local_ai.sh');
    await expect(card.locator('[data-mit-ai-local-on]')).toHaveCount(0);

    // Running without the model: switch on
    await page.request.get(MOCK + '/_state?reset=1');
    await visit(page, 'admin/mit_ai_helper.php', errors);
    await expect(card).toHaveAttribute('data-running', '1');
    await expect(card).toHaveAttribute('data-model', '0');
    await Promise.all([page.waitForNavigation(), card.locator('[data-mit-ai-local-on]').click()]);
    expect(await flash(page)).toContain('Meaning search now runs on this server');
    await expect(card).toHaveAttribute('data-on', '1');
    await expect(card).toHaveAttribute('data-model', '1');
    await expect(page.locator('select[name=ai_helper_embed_provider] option:checked')).toHaveText('This server (Ollama)');
    await expect(page.locator('input[name=ai_helper_embed_model]')).toHaveValue('nomic-embed-text');
    expect(db.val("SELECT ai_provider_kind FROM ai_providers WHERE ai_provider_name = 'This server (Ollama)'")).toBe('ollama');

    // A failing model shows on the page (the library job records the last error)
    set('ai_helper_embed_error', '2026-10-26 12:00 - Embeddings failed: Could not reach the provider');
    await visit(page, 'admin/mit_ai_helper.php', errors);
    await expect(page.locator('[data-mit-ai-embed-error]')).toContainText('Could not reach the provider');
    set('ai_helper_embed_error', '');
    await visit(page, 'admin/mit_ai_helper.php', errors);
    await expect(page.locator('[data-mit-ai-embed-error]')).toHaveCount(0);

    // Switch off: back to keyword search (what was set before)
    await Promise.all([page.waitForNavigation(), card.locator('[data-mit-ai-local-off]').click()]);
    expect(await flash(page)).toContain('keyword search only');
    await expect(card).toHaveAttribute('data-on', '0');
    await expect(page.locator('select[name=ai_helper_embed_provider]')).toHaveValue('0');
    await expectClean(page, errors);
});
