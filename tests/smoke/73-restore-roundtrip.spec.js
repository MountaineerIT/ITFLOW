// Restore on a running install, for real (ITFLOW_SMOKE_RESTORE=1 only): an older stored backup (before the upstream merge) is
// restored from Admin > Backup - ITFlow's and our database updates must bring it to today's versions - then the "Before restore"
// copy it made puts the install back exactly: a marker written just before, row counts and versions as they were, the safety
// copy listed with its real size. Uses the mysql CLI (ITFLOW_DB, default itflow).

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { visit, openModal } = require('./helpers');

test.describe.configure({ mode: 'serial' });
const DB = process.env.ITFLOW_DB || 'itflow';
const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';
const sql = (q) => execSync(`mysql -N -B ${DB}`, { input: q }).toString().trim();

async function restore(page, id, password) {
    await visit(page, 'admin/backup.php');
    const modal = await openModal(page, `modals/mit_backup/restore.php?backup_id=${id}`);
    await modal.locator('input[name=password]').fill(password);
    await modal.locator('input[name=confirm]').fill('RESTORE');
    await Promise.all([page.waitForNavigation({ timeout: 600000 }), modal.locator('button[data-mit-restore-go]').click()]);
}
async function signIn(page) {
    await page.goto('login.php');
    await page.fill('input[name=email]', process.env.ITFLOW_EMAIL);
    await page.fill('input[name=password]', process.env.ITFLOW_PASSWORD);
    await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
}
const snapshot = () => ({
    version: sql('SELECT config_current_database_version FROM settings'),
    ours: sql('SELECT COUNT(*) FROM mit_database_updates'),
    tickets: sql('SELECT COUNT(*) FROM tickets'),
    entries: sql('SELECT COUNT(*) FROM mit_time_entries'),
    users: sql('SELECT COUNT(*) FROM users'),
    ledger: sql('SELECT COALESCE(SUM(minutes), 0) FROM mit_leave_ledger'),
});

test('restore an older backup, then put the install back exactly', async ({ page }) => {
    test.setTimeout(1500000);
    test.skip(process.env.ITFLOW_SMOKE_RESTORE !== '1', 'set ITFLOW_SMOKE_RESTORE=1 to restore the test install for real');
    const target = sql("SELECT backup_id FROM backups WHERE backup_status = 'Complete' AND backup_size > 0 AND backup_source <> 'Before restore' ORDER BY backup_created_at DESC LIMIT 1");
    test.skip(!target, 'no stored backup to restore');
    const ourUpdates = fs.readdirSync(path.join(ROOT, 'admin/database_updates/mountaineerit')).filter((f) => /^M\d+\.php$/.test(f)).length;
    const marker = 'zz-' + Date.now();
    sql(`INSERT INTO mit_settings SET setting_name = 'zz_restore_marker', setting_value = '${marker}' ON DUPLICATE KEY UPDATE setting_value = '${marker}'`);
    const before = snapshot();
    const startedAt = Date.now();

    await restore(page, target, process.env.ITFLOW_PASSWORD);
    await expect(page.locator('[data-mit-restored]')).toContainText('Backup restored');
    await expect(page.locator('[data-mit-restored] .alert-warning'), 'database updates ran cleanly').toHaveCount(0);
    expect(sql("SELECT COUNT(*) FROM mit_settings WHERE setting_name = 'zz_restore_marker'"), 'the older database is in place').toBe('0');
    expect(sql('SELECT config_current_database_version FROM settings'), 'ITFlow updates brought it to today').toBe(before.version);
    expect(Number(sql('SELECT COUNT(*) FROM mit_database_updates')), 'every one of our updates ran').toBe(ourUpdates);
    // The safety copy is listed with its real size
    const safety = sql("SELECT CONCAT(backup_id, ',', backup_size, ',', backup_file_name) FROM backups WHERE backup_source = 'Before restore' ORDER BY backup_created_at DESC, backup_id DESC LIMIT 1").split(',');
    expect(fs.statSync(path.join(ROOT, 'uploads/backups', safety[2])).mtimeMs, 'a new "Before restore" copy').toBeGreaterThan(startedAt - 60000);
    const onDisk = fs.statSync(path.join(ROOT, 'uploads/backups', safety[2])).size;
    expect(Number(safety[1]), 'the safety copy\'s size on the list').toBe(onDisk);

    await signIn(page);
    await visit(page, 'agent/mit_timesheet.php');
    await restore(page, safety[0], process.env.ITFLOW_PASSWORD);
    await expect(page.locator('[data-mit-restored]')).toContainText('Backup restored');
    expect(sql("SELECT setting_value FROM mit_settings WHERE setting_name = 'zz_restore_marker'"), 'put back exactly').toBe(marker);
    expect(snapshot()).toEqual(before);
    sql("DELETE FROM mit_settings WHERE setting_name = 'zz_restore_marker'");
    await signIn(page);
    await page.context().storageState({ path: path.join(__dirname, '.auth', 'admin.json') });
    await visit(page, 'agent/dashboard.php');
});
