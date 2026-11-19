// Unlimited support hours on a contract (functions/mit_contract_unlimited.php, 26.11.8.8): in ITFlow's New Contract window the Unlimited switch
// empties and locks the hours / alert boxes, saving stores the big number in ITFlow's hours each month with no alert, the contract page's
// Prepaid hours card reads Unlimited with the hours used this month, Edit opens with the switch on, and switching it off saves a number again.
// A template picked on New Contract that is unlimited ticks the switch. Everything it makes is removed afterwards.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, expectClean } = require('./helpers');
const db = require('./mitdb');

// The hours are on the windows' Cover tab
const cover = async (modal) => modal.locator('a.nav-link[data-bs-toggle="pill"]', { hasText: 'Cover' }).click();

const made = { contractId: 0, templateId: 0, name: smokeName('unlimited contract'), templateName: smokeName('unlimited template') };
const CLIENT = Number(db.val("SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1"));

test.afterAll(() => {
    if (made.contractId) {
        db.exec(`DELETE FROM contract_blocks WHERE contract_block_contract_id = ${made.contractId}`);
        db.exec(`DELETE FROM contracts WHERE contract_id = ${made.contractId}`);
    }
    if (made.templateId) {
        db.exec(`DELETE FROM contract_templates WHERE contract_template_id = ${made.templateId}`);
    }
});

test('contracts: unlimited support hours', async ({ page }) => {
    test.skip(!CLIENT, 'no client here');
    const errors = watch(page);

    // New Contract: the switch locks the boxes
    await visit(page, `agent/contracts.php?client_id=${CLIENT}`, errors);
    let modal = await openModal(page, `modals/contract/contract_add.php?client_id=${CLIENT}`, 'lg');
    await cover(modal);
    const unlimited = modal.locator('[data-mit-unlimited]');
    await expect(unlimited, 'the Unlimited switch is under Hours included each month').toBeVisible();
    await modal.locator('[name=monthly_hours]').fill('12');
    await modal.locator('[name=hours_alert]').fill('3');
    await unlimited.check();
    await expect(modal.locator('[name=monthly_hours]')).toHaveJSProperty('readOnly', true);
    await expect(modal.locator('[name=monthly_hours]')).toHaveValue('');
    await expect(modal.locator('[name=monthly_hours]')).toHaveAttribute('placeholder', 'Unlimited');
    await expect(modal.locator('[name=hours_alert]')).toHaveJSProperty('readOnly', true);
    // Off again puts back what was typed, on once more for the save
    await unlimited.uncheck();
    await expect(modal.locator('[name=monthly_hours]')).toHaveValue('12');
    await unlimited.check();
    await fillForm(modal, { name: made.name, status: 'Active' });
    await submit(modal, 'add_contract');
    made.contractId = Number(db.val(`SELECT contract_id FROM contracts WHERE contract_name = ${db.q(made.name)}`) || 0);
    expect(made.contractId, 'the contract was saved').toBeGreaterThan(0);
    expect(db.val(`SELECT CONCAT(ROUND(contract_monthly_hours), '/', ROUND(contract_hours_alert)) FROM contracts WHERE contract_id = ${made.contractId}`), 'hours each month / alert').toBe('99999/0');
    if (db.val(`SELECT contract_status FROM contracts WHERE contract_id = ${made.contractId}`) === 'Draft') {
        await visit(page, `agent/contract.php?contract_id=${made.contractId}`, errors);
        modal = await openModal(page, `modals/contract/contract_activate.php?id=${made.contractId}`);
        await submit(modal, 'activate_contract');
    }

    // The contract page
    await visit(page, `agent/contract.php?contract_id=${made.contractId}`, errors);
    const card = page.locator('[data-contract-hours]');
    await expect(card.locator('[data-mit-contract-unlimited]'), 'the Prepaid hours card reads Unlimited').toContainText('Unlimited');
    await expect(card.locator('[data-mit-unlimited-used]')).toContainText('used this month');
    await expect(card).toContainText('none goes to Unbilled Time');
    await expect(card, 'no running-low warning or "Of 99999 h"').not.toContainText(/99,?999|Running low|All used/);

    // Edit opens with it on; off with a number saves the number
    modal = await openModal(page, `modals/contract/contract_edit.php?id=${made.contractId}`, 'lg');
    await cover(modal);
    await expect(modal.locator('[data-mit-unlimited]')).toBeChecked();
    await expect(modal.locator('[name=monthly_hours]')).toHaveValue('');
    await modal.locator('[data-mit-unlimited]').uncheck();
    await modal.locator('[name=monthly_hours]').fill('20');
    await submit(modal, 'edit_contract');
    expect(Number(db.val(`SELECT contract_monthly_hours FROM contracts WHERE contract_id = ${made.contractId}`)), 'switched off: 20 h a month').toBe(20);

    // An unlimited template ticks the switch when picked on New Contract
    await visit(page, 'admin/contract_templates.php', errors);
    modal = await openModal(page, 'modals/contract_template/contract_template_add.php', 'lg');
    await fillForm(modal, { name: made.templateName, term_months: '12' });
    await cover(modal);
    await modal.locator('[data-mit-unlimited]').check();
    await submit(modal, 'add_contract_template');
    made.templateId = Number(db.val(`SELECT contract_template_id FROM contract_templates WHERE contract_template_name = ${db.q(made.templateName)}`) || 0);
    expect(Number(db.val(`SELECT contract_template_monthly_hours FROM contract_templates WHERE contract_template_id = ${made.templateId}`)), 'the template is unlimited').toBe(99999);
    await visit(page, `agent/contracts.php?client_id=${CLIENT}`, errors);
    modal = await openModal(page, `modals/contract/contract_add.php?client_id=${CLIENT}`, 'lg');
    await modal.locator('[name=template_id]').selectOption(String(made.templateId));
    await modal.locator('[name=template_id]').dispatchEvent('change');
    await cover(modal);
    await expect(modal.locator('[data-mit-unlimited]'), 'picked template ticks Unlimited').toBeChecked();
    await expect(modal.locator('[name=monthly_hours]')).toHaveValue('');
    await expectClean(page, errors);
});
