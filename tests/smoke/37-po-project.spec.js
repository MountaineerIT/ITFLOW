// A PO's lines put on a project after it was ordered (functions/mit_procurement_extras.php mitPoSetProject, 26.11.3.5): the PO page has
// "Link to a project" and a project link on each line; the window puts the ticked lines on the picked project, the line shows it, and the
// project page's Procurement card lists the PO. The rules (other clients, received lines, cancelled POs) are in
// tests/scenarios/scenario-procurement-project.php.

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, fillForm, submit, flash, expectClean } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { po: 0, project: 0, items: [] };

test.beforeAll(() => {
    const client = Number(db.val('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1'));
    const vendor = Number(db.val('SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1'));
    made.project = db.insert(`INSERT INTO projects SET project_prefix = 'PRJ-', project_number = 8899, project_name = 'Smoke PO project', project_description = '',
        project_due = CURDATE(), project_client_id = ${client}`);
    const out = db.php(`$old = mitSetting('procurement_approval_on', '1'); mitSetSetting('procurement_approval_on', '0');
        $a = mitProcAddItem(['name' => 'Smoke PO switch', 'quantity' => 2, 'unit_cost' => 100, 'client_id' => ${client}, 'vendor_id' => 0, 'project_id' => 0, 'product_id' => 0]);
        $b = mitProcAddItem(['name' => 'Smoke PO cable', 'quantity' => 3, 'unit_cost' => 5, 'client_id' => ${client}, 'vendor_id' => 0, 'project_id' => 0, 'product_id' => 0]);
        $po = mitPoCreate([$a, $b], ${vendor}); mitPoSubmit(mitPoRow($po)); mitPoMarkOrdered(mitPoRow($po), date('Y-m-d'), '');
        mitSetSetting('procurement_approval_on', $old); echo json_encode([$po, $a, $b]);`);
    [made.po, ...made.items] = JSON.parse(out.trim().split('\n').pop());
});

test.afterAll(() => {
    if (made.po) {
        db.exec(`DELETE FROM mit_purchase_orders WHERE po_id = ${made.po}`);
    }
    if (made.items.length) {
        db.exec(`DELETE FROM mit_procurement_items WHERE item_id IN (${made.items.join(',')})`);
    }
    if (made.project) {
        db.exec(`DELETE FROM projects WHERE project_id = ${made.project}`);
    }
});

test('an ordered PO goes on a project from its page', async ({ page }) => {
    const errors = watch(page);
    await visit(page, `agent/mit_purchase_order.php?po_id=${made.po}`, errors);
    await expect(page.locator('[data-mit-po-project-btn]'), 'Link to a project on an ordered PO').toBeVisible();
    await expect(page.locator('[data-mit-po-line-project]'), 'a project link on each line').toHaveCount(2);

    // One line from its own link: only that line is ticked
    const switchLine = Number(db.val(`SELECT line_id FROM mit_po_lines WHERE po_id = ${made.po} AND name = 'Smoke PO switch'`));
    let modal = await openModal(page, `modals/mit_procurement/po_action.php?po_id=${made.po}&action=project&line_id=${switchLine}`);
    await expect(modal.locator('[name="line_ids[]"]:checked'), 'just that line').toHaveCount(1);
    await fillForm(modal, { project_id: String(made.project) });
    await submit(modal, 'mit_po_set_project');
    expect(await flash(page)).toContain('1 line put on the project');
    expect(db.val(`SELECT GROUP_CONCAT(project_id ORDER BY line_id) FROM mit_po_lines WHERE po_id = ${made.po}`), 'switch on, cable not yet').toBe(`${made.project},0`);

    // The rest from the PO's button
    modal = await openModal(page, `modals/mit_procurement/po_action.php?po_id=${made.po}&action=project`);
    await expect(modal.locator('[name="line_ids[]"]:checked'), 'every line ticked').toHaveCount(2);
    await fillForm(modal, { project_id: String(made.project) });
    await submit(modal, 'mit_po_set_project');
    expect(db.val(`SELECT COUNT(*) FROM mit_po_lines WHERE po_id = ${made.po} AND project_id = ${made.project}`), 'both lines on it').toBe('2');
    expect(db.val(`SELECT COUNT(*) FROM mit_procurement_items WHERE item_id IN (${made.items.join(',')}) AND project_id = ${made.project}`), 'their items too').toBe('2');
    await expect(page.locator('table').first(), 'the lines show the project').toContainText('PRJ-8899');
    await expectClean(page, errors);

    // The project page lists the PO
    const client = db.val(`SELECT project_client_id FROM projects WHERE project_id = ${made.project}`);
    await visit(page, `agent/project.php?client_id=${client}&project_id=${made.project}`, errors);
    const po = db.one(`SELECT po_prefix, po_number FROM mit_purchase_orders WHERE po_id = ${made.po}`);
    await expect(page.locator('body'), 'on the project\'s Procurement card').toContainText(`${po.po_prefix}${po.po_number}`);
    await expectClean(page, errors);
});

test('a receipt made by mistake is undone from the PO page (26.11.8.3)', async ({ page }) => {
    const errors = watch(page);
    const cable = Number(db.val(`SELECT line_id FROM mit_po_lines WHERE po_id = ${made.po} AND name = 'Smoke PO cable'`));
    db.php(`mitPoReceive([${cable} => ['qty' => 2]], []);`);
    expect(db.val(`SELECT ROUND(received_qty) FROM mit_po_lines WHERE line_id = ${cable}`), 'received 2').toBe('2');
    await visit(page, `agent/mit_purchase_order.php?po_id=${made.po}`, errors);
    const receipts = page.locator('[data-mit-po-receipts]');
    await expect(receipts, 'the Receipts card').toContainText('2 × Smoke PO cable');
    await receipts.locator('[data-mit-receipt-undo]').first().click();
    await Promise.all([page.waitForNavigation(), page.locator('.swal2-confirm').click()]);
    expect(await flash(page)).toContain('undone');
    expect(db.val(`SELECT ROUND(received_qty) FROM mit_po_lines WHERE line_id = ${cable}`), 'back to waiting').toBe('0');
    expect(db.val(`SELECT status FROM mit_purchase_orders WHERE po_id = ${made.po}`), 'the PO is ordered again').toBe('ordered');
    await expect(page.locator('[data-mit-po-receipts]'), 'no receipts left').toHaveCount(0);
    await expectClean(page, errors);
});
