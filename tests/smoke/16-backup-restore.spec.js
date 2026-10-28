// Restore from Admin > Backup (functions/mit_backup_restore.php). Every run: a stored backup's Restore window says what is
// in it, a wrong password changes nothing, a file that is not a backup is refused. With ITFLOW_SMOKE_RESTORE=1 only (it
// really replaces the test install's database): restore the newest backup, then the "Before restore" copy it made - the
// install ends as it started - and sign in again for the tests after this one.

const path = require('path');
const fs = require('fs');
const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, flash, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

// Complete, restorable backups on the page: [{id, source}]
async function backups(page) {
    await visit(page, 'admin/backup.php');
    return page.locator('[data-modal-url*="mit_backup/restore.php?backup_id="]').evaluateAll((buttons) => buttons.map((b) => ({
        id: Number(new URL(b.dataset.modalUrl, location.href).searchParams.get('backup_id')),
        source: b.closest('tr').children[3].textContent.trim(),
    })));
}

async function restore(page, id, password) {
    await visit(page, 'admin/backup.php');
    const modal = await openModal(page, `modals/mit_backup/restore.php?backup_id=${id}`);
    await modal.locator('input[name=password]').fill(password);
    await modal.locator('input[name=confirm]').fill('RESTORE');
    await Promise.all([page.waitForNavigation({ timeout: 300000 }), modal.locator('button[data-mit-restore-go]').click()]);
}

test('restore: the window shows the backup, and a wrong password changes nothing', async ({ page }) => {
    const errors = watch(page);
    const list = await backups(page);
    test.skip(await page.locator('td .badge', { hasText: /^Complete$/ }).count() === 0, 'no complete backup to look at');
    expect(list.length, 'a Restore button on the complete backups').toBeGreaterThan(0);
    const modal = await openModal(page, `modals/mit_backup/restore.php?backup_id=${list[0].id}`);
    await expect(modal, 'what is in it').toContainText(/Contains\s*(Database and uploads|Database only)/);
    await expect(modal, 'the safety copy is promised').toContainText('Before restore');
    const before = list.length;
    await modal.locator('input[name=password]').fill('not the password');
    await modal.locator('input[name=confirm]').fill('RESTORE');
    await Promise.all([page.waitForNavigation(), modal.locator('button[data-mit-restore-go]').click()]);
    expect(await flash(page), 'refused').toContain('Incorrect password - nothing was changed');
    expect((await backups(page)).length, 'no safety backup made, nothing restored').toBe(before);
    await expectClean(page, errors);
});

test('restore: a file that is not a backup is refused', async ({ page }) => {
    const file = path.join(test.info().outputDir, 'not-a-backup.zip');
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, 'this is not a zip');
    await visit(page, 'admin/backup.php');
    const form = page.locator('form:has(button[name=mit_backup_upload])');
    await form.locator('input[name=backup_file]').setInputFiles(file);
    await form.locator('button[name=mit_backup_upload]').click();
    // Sent in pieces by the page's script - the answer shows under the progress bar
    await expect(form.locator('[data-mit-upload-text]')).toContainText('not a backup that can be read');
});

test('restore: a big backup goes up in pieces and is put back together', async ({ page }) => {
    const errors = watch(page);
    const list = await backups(page);
    test.skip(!list.length, 'no complete backup to send');
    const before = list.length;
    const download = await page.locator(`a[href*="backup_download.php?backup_id=${list[0].id}"]`).getAttribute('href');
    // The stored backup, sent back in 1 MB pieces through the same requests the page's script makes
    const result = await page.evaluate(async (href) => {
        const token = document.querySelector('form[data-mit-backup-upload] [name=csrf_token]').value;
        const blob = await (await fetch(href, { credentials: 'same-origin' })).blob();
        const post = async (fields, piece) => {
            const data = new FormData();
            data.append('csrf_token', token);
            Object.entries(fields).forEach(([k, v]) => data.append(k, v));
            if (piece) data.append('chunk', piece, 'chunk');
            const r = await fetch('post.php', { method: 'POST', body: data, credentials: 'same-origin' });
            return r.json();
        };
        const start = await post({ mit_backup_chunk_start: 1, name: 'big.zip', size: blob.size });
        if (!start.id) return { error: start.error || 'no start' };
        const piece = 1024 * 1024;
        let pieces = 0;
        for (let offset = 0; offset < blob.size; offset += piece) {
            const r = await post({ mit_backup_chunk: 1, id: start.id, offset }, blob.slice(offset, offset + piece));
            if (r.error) return { error: r.error };
            pieces++;
        }
        // A piece out of order is refused (the upload is finished, so it is gone)
        const late = await post({ mit_backup_chunk: 1, id: start.id, offset: 0 }, blob.slice(0, 10));
        const done = await post({ mit_backup_chunk_finish: 1, id: start.id, restore_key: '' });
        return { pieces, size: blob.size, done, late };
    }, download);
    expect(result.error, 'no error while sending').toBeUndefined();
    expect(result.pieces, 'sent in several pieces').toBeGreaterThan(1);
    expect(result.done.redirect, `put together and listed (${JSON.stringify(result.done)})`).toMatch(/backup\.php\?mit_restore=\d+/);
    await visit(page, 'admin/' + result.done.redirect, errors);
    expect(await flash(page)).toContain('Backup uploaded');
    const modal = page.locator('.modal.show');
    await expect(modal, 'its Restore window reads it').toContainText(/Contains\s*(Database and uploads|Database only)/);
    const after = await backups(page);
    expect(after.length, 'one more in the list').toBe(before + 1);
    // Clean up: delete the uploaded copy
    const id = Number(new URL(result.done.redirect, page.url()).searchParams.get('mit_restore'));
    const del = await page.locator(`a[href*="delete_backup=${id}&"]`).getAttribute('href');
    await page.goto(new URL(del, page.url()).href);
    expect((await backups(page)).length, 'the copy is deleted again').toBe(before);
    await expectClean(page, errors);
});

test('restore: a backup, then the "Before restore" copy - the install ends as it started', async ({ page, browser }) => {
    test.setTimeout(900000); // two restores of a minute or more each - cut short, the page left mid-restore and the next specs met a half-imported database
    test.skip(process.env.ITFLOW_SMOKE_RESTORE !== '1', 'set ITFLOW_SMOKE_RESTORE=1 to restore the test install for real');
    const list = await backups(page);
    test.skip(!list.length, 'no complete backup to restore');

    const password = process.env.ITFLOW_PASSWORD;
    await restore(page, list[0].id, password);
    await expect(page.locator('[data-mit-restored]'), 'restored').toContainText('Backup restored');

    // Signed out: sign in again, find the safety copy it made, restore that
    const signIn = async () => {
        await page.goto('login.php');
        await page.fill('input[name=email]', process.env.ITFLOW_EMAIL);
        await page.fill('input[name=password]', password);
        await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
    };
    await signIn();
    const safety = (await backups(page)).filter((b) => b.source === 'Before restore').sort((a, b) => b.id - a.id)[0];
    expect(safety, 'the "Before restore" copy is listed').toBeTruthy();
    await restore(page, safety.id, password);
    await expect(page.locator('[data-mit-restored]'), 'put back').toContainText('Backup restored');
    await expect(page.locator('[data-mit-restored] .alert-warning'), 'database updates ran cleanly').toHaveCount(0);

    // The tests after this one use the saved admin sign-in - make a fresh one
    await signIn();
    await page.context().storageState({ path: path.join(__dirname, '.auth', 'admin.json') });
});
