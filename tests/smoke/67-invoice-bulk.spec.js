// Bulk actions on the invoices list (functions/mit_invoice_bulk.php): tick invoices of a throw-away client, open Mark Paid from ITFlow's Bulk
// Action menu - the window lists the open ones with what is owed and leaves the draft alone, saying why; Mark Paid pays each in full (checked in
// payments / invoices). Then Mark Sent on the draft and Delete on everything. Removes its client.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, flash, expectClean } = require('./helpers');
const db = require('./db');

test('invoice bulk actions: mark paid, mark sent, delete', async ({ page }) => {
    const errors = watch(page);
    const name = smokeName('bulk invoices');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    const clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    const account = Number(db.value('SELECT account_id FROM accounts WHERE account_archived_at IS NULL ORDER BY account_id LIMIT 1'));
    const make = (n, status, amount) => {
        db.run(`INSERT INTO invoices SET invoice_prefix = 'SMB-', invoice_number = ${n}, invoice_scope = 'smoke', invoice_date = CURDATE(), invoice_due = CURDATE(),
            invoice_amount = ${amount}, invoice_currency_code = 'USD', invoice_status = '${status}', invoice_url_key = 'smoke', invoice_client_id = ${clientId}, invoice_category_id = 0`);
        return Number(db.value(`SELECT MAX(invoice_id) FROM invoices WHERE invoice_client_id = ${clientId}`));
    };
    const ids = [];
    try {
        ids.push(make(970001, 'Sent', 120), make(970002, 'Viewed', 80.5), make(970003, 'Draft', 30));
        const status = (id) => db.value(`SELECT invoice_status FROM invoices WHERE invoice_id = ${id}`);
        const tick = async (only) => {
            await visit(page, `agent/invoices.php?client_id=${clientId}`, errors);
            for (const id of only) {
                await page.locator(`input.bulk-select[value="${id}"]`).check();
            }
            await expect(page.locator('#bulkActionButton')).toBeVisible();
            await page.locator('#bulkActionButton > button').click();
        };
        const open = async (action) => {
            await page.locator(`#bulkActionButton [data-mit-invoice-bulk="${action}"]`).click();
            const modal = page.locator('.modal.show').last();
            await modal.locator(`form[data-mit-invoice-bulk-form="${action}"]`).waitFor();
            return modal;
        };

        // Mark Paid: the two open ones, the draft left alone
        await tick(ids);
        await expect(page.locator('#bulkActionButton .dropdown-item', { hasText: 'Set Category' }), 'ITFlow\'s own item still there').toHaveCount(1);
        let modal = await open('pay');
        await expect(modal.locator('[data-mit-bulk-do] [data-invoice-id]')).toHaveCount(2);
        await expect(modal.locator('[data-mit-bulk-total]')).toContainText('200.50');
        await expect(modal.locator('[data-mit-bulk-skip]')).toContainText('SMB-970003 - a draft - mark it sent first');
        await modal.locator('select[name=payment_method]').selectOption({ index: 1 });
        await modal.locator('select[name=account]').selectOption(String(account));
        await modal.locator('input[name=reference]').fill('Smoke bulk');
        await Promise.all([page.waitForNavigation(), modal.locator('button[name=mit_invoice_bulk]').click()]);
        expect(await flash(page)).toContain("2 invoices marked paid ($200.50)");
        expect([status(ids[0]), status(ids[1]), status(ids[2])]).toEqual(['Paid', 'Paid', 'Draft']);
        expect(Number(db.value(`SELECT SUM(payment_amount) FROM payments WHERE payment_invoice_id IN (${ids.join(',')}) AND payment_reference = 'Smoke bulk'`))).toBeCloseTo(200.5);

        // Mark Sent on the draft
        await tick([ids[2]]);
        modal = await open('sent');
        await modal.locator('select[name=sent_method]').selectOption('Hand Delivered');
        await Promise.all([page.waitForNavigation(), modal.locator('button[name=mit_invoice_bulk]').click()]);
        expect(await flash(page)).toContain('1 invoice marked sent');
        expect(status(ids[2])).toBe('Sent');

        // Delete everything
        await tick(ids);
        modal = await open('delete');
        await expect(modal.locator('[data-mit-bulk-do] [data-invoice-id]')).toHaveCount(3);
        await Promise.all([page.waitForNavigation(), modal.locator('button[name=mit_invoice_bulk]').click()]);
        expect(await flash(page)).toContain('3 invoices deleted');
        expect(db.value(`SELECT COUNT(*) FROM invoices WHERE invoice_client_id = ${clientId}`)).toBe('0');
        expect(db.value(`SELECT COUNT(*) FROM payments WHERE payment_invoice_id IN (${ids.join(',')})`)).toBe('0');
        await expectClean(page, errors);
    } finally {
        if (ids.length) {
            db.run(`DELETE FROM payments WHERE payment_invoice_id IN (${ids.join(',')})`);
            db.run(`DELETE FROM history WHERE history_invoice_id IN (${ids.join(',')})`);
            db.run(`DELETE FROM invoices WHERE invoice_id IN (${ids.join(',')})`);
        }
        db.run(`DELETE FROM logs WHERE log_client_id = ${clientId}`);
        db.run(`DELETE FROM clients WHERE client_id = ${clientId}`);
    }
});
