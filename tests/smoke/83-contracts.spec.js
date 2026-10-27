// ITFlow's own contracts with our build around them: a template from Admin > Contract templates (production's contract_templates has
// a leftover company_id from ITFlow's November 2025 db.sql - M046 gives it a default, or ITFlow's own Add fails), a client contract
// from it, made active, its recurring invoice (our usage card and QuickBooks strip sit on that page), prepaid hours added, and the
// contract page, the client's contracts and the recurring invoice open cleanly. Everything it makes is removed afterwards.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, expectClean } = require('./helpers');
const db = require('./mitdb');

const made = { templateId: 0, contractId: 0, recurringId: 0, name: smokeName('contract'), templateName: smokeName('contract template') };
const CLIENT = Number(db.val("SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1"));

test.afterAll(() => {
    if (made.contractId) {
        db.exec(`DELETE FROM contract_blocks WHERE contract_block_contract_id = ${made.contractId}`); // its uses go with it (CASCADE)
        db.exec(`DELETE FROM contracts WHERE contract_id = ${made.contractId}`);
    }
    if (made.recurringId) {
        db.exec(`DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = ${made.recurringId}`);
        db.exec(`DELETE FROM recurring_invoices WHERE recurring_invoice_id = ${made.recurringId}`);
    }
    if (made.templateId) {
        db.exec(`DELETE FROM contract_templates WHERE contract_template_id = ${made.templateId}`);
    }
});

test('ITFlow contracts: template, client contract, active, recurring invoice, prepaid hours', async ({ page }) => {
    test.skip(!CLIENT, 'no client here');
    const errors = watch(page);

    // A template - ITFlow's own Add, which leaves company_id out
    await visit(page, 'admin/contract_templates.php', errors);
    let modal = await openModal(page, 'modals/contract_template/contract_template_add.php', 'lg');
    await fillForm(modal, { name: made.templateName, rate_standard: '125', monthly_hours: '10', term_months: '12' });
    await submit(modal, 'add_contract_template');
    made.templateId = Number(db.val(`SELECT contract_template_id FROM contract_templates WHERE contract_template_name = ${db.q(made.templateName)}`) || 0);
    expect(made.templateId, 'the template was saved').toBeGreaterThan(0);

    // A client contract from it
    await visit(page, `agent/contracts.php?client_id=${CLIENT}`, errors);
    modal = await openModal(page, `modals/contract/contract_add.php?client_id=${CLIENT}`, 'lg');
    await fillForm(modal, { template_id: String(made.templateId), name: made.name });
    await submit(modal, 'add_contract');
    made.contractId = Number(db.val(`SELECT contract_id FROM contracts WHERE contract_name = ${db.q(made.name)}`) || 0);
    expect(made.contractId, 'the contract was saved').toBeGreaterThan(0);

    // Active (New contract may save it active already; a draft is made active)
    await visit(page, `agent/contract.php?contract_id=${made.contractId}`, errors);
    if (db.val(`SELECT contract_status FROM contracts WHERE contract_id = ${made.contractId}`) === 'Draft') {
        modal = await openModal(page, `modals/contract/contract_activate.php?id=${made.contractId}`);
        await submit(modal, 'activate_contract');
    }
    expect(db.val(`SELECT contract_status FROM contracts WHERE contract_id = ${made.contractId}`), 'active').toMatch(/active/i);

    // Its recurring invoice
    await visit(page, `agent/contract.php?contract_id=${made.contractId}`, errors);
    modal = await openModal(page, `modals/contract/contract_recurring.php?id=${made.contractId}`, 'lg');
    await fillForm(modal, { item_name: 'Smoke 83 managed services', amount: '500' });
    await submit(modal, 'make_contract_recurring');
    expect(await flash(page)).not.toMatch(/No recurring invoice was made/);
    made.recurringId = Number(db.val(`SELECT contract_recurring_invoice_id FROM contracts WHERE contract_id = ${made.contractId}`) || 0);
    expect(made.recurringId, 'the recurring invoice is linked').toBeGreaterThan(0);

    // Prepaid hours
    await visit(page, `agent/contract.php?contract_id=${made.contractId}`, errors);
    modal = await openModal(page, `modals/contract/contract_hours_add.php?id=${made.contractId}`);
    await fillForm(modal, { hours: '5' });
    await submit(modal, 'add_contract_hours');
    expect(Number(db.val(`SELECT COUNT(*) FROM contract_blocks WHERE contract_block_contract_id = ${made.contractId} AND contract_block_hours = 5`)), 'the 5-hour block (beside the template\'s monthly hours)').toBe(1);

    // The pages it touches open cleanly - the recurring invoice carries our usage card
    await visit(page, `agent/contract.php?contract_id=${made.contractId}`, errors);
    await expect(page.locator('body')).toContainText(made.name);
    await visit(page, `agent/contracts.php?client_id=${CLIENT}`, errors);
    await visit(page, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`, errors);
    await expectClean(page, errors);
});
