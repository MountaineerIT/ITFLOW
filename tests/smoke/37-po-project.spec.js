// A PO's lines put on a project after it was ordered (functions/mit_procurement_extras.php mitPoSetProject, 26.11.3.5; on ITFlow 26.12's
// purchase orders since 26.12): ITFlow's PO page has our "Link to a Project" in its menu and a project link under each line; the window
// puts the ticked lines on the picked project, the line shows it, and the project page's Purchase orders card lists the PO. Then a receipt
// is undone from ITFlow's Received card (its poUndoReceipt with our hook). The rules (other clients, received lines, cancelled POs) are in
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
    // Approval off for the making of it (poSettings() reads it once), then put back
    const out = db.php(`$old = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_po_approval FROM settings WHERE company_id = 1"))[0]);
        mysqli_query($mysqli, "UPDATE settings SET config_po_approval = 0 WHERE company_id = 1");
        $a = mitProcAddItem(['name' => 'Smoke PO switch', 'quantity' => 2, 'unit_cost' => 100, 'client_id' => ${client}, 'vendor_id' => 0, 'project_id' => 0, 'product_id' => 0]);
        $b = mitProcAddItem(['name' => 'Smoke PO cable', 'quantity' => 3, 'unit_cost' => 5, 'client_id' => ${client}, 'vendor_id' => 0, 'project_id' => 0, 'product_id' => 0]);
        $po = poCreate(${vendor}, [$a, $b], $error); poMarkOrdered(poRow($po), date('Y-m-d'), '', $error);
        mysqli_query($mysqli, "UPDATE settings SET config_po_approval = $old WHERE company_id = 1"); echo json_encode([$po, $a, $b]);`);
    [made.po, ...made.items] = JSON.parse(out.trim().split('\n').pop());
});

test.afterAll(() => {
    if (made.po) {
        db.exec(`DELETE FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = ${made.po}; DELETE FROM purchase_orders WHERE po_id = ${made.po}`);
    }
    if (made.items.length) {
        db.exec(`DELETE FROM order_items WHERE order_item_id IN (${made.items.join(',')})`);
    }
    if (made.project) {
        db.exec(`DELETE FROM projects WHERE project_id = ${made.project}`);
    }
});

const lineProject = `SELECT GROUP_CONCAT(COALESCE(pp.project_id, links.project_id, 0) ORDER BY l.po_item_id) FROM po_items l
    LEFT JOIN mit_po_item_projects pp ON pp.po_item_id = l.po_item_id LEFT JOIN mit_order_item_links links ON links.order_item_id = l.po_item_order_item_id
    WHERE l.po_item_po_id = `;

test('an ordered PO goes on a project from its page', async ({ page }) => {
    const errors = watch(page);
    await visit(page, `agent/purchase_order.php?po_id=${made.po}`, errors);
    await expect(page.locator('[data-mit-po-project-btn]'), 'Link to a Project in the PO\'s menu').toHaveCount(1);
    await expect(page.locator('[data-mit-po-line-project]'), 'a project link under each line').toHaveCount(2);

    // One line from its own link: only that line is ticked
    const switchLine = Number(db.val(`SELECT po_item_id FROM po_items WHERE po_item_po_id = ${made.po} AND po_item_name = 'Smoke PO switch'`));
    let modal = await openModal(page, `modals/mit_procurement/po_project.php?po_id=${made.po}&line_id=${switchLine}`);
    await expect(modal.locator('[name="line_ids[]"]:checked'), 'just that line').toHaveCount(1);
    await fillForm(modal, { project_id: String(made.project) });
    await submit(modal, 'mit_po_set_project');
    expect(await flash(page)).toContain('1 line put on the project');
    expect(db.val(lineProject + made.po), 'switch on, cable not yet').toBe(`${made.project},0`);

    // The rest from the PO's menu
    modal = await openModal(page, `modals/mit_procurement/po_project.php?po_id=${made.po}`);
    await expect(modal.locator('[name="line_ids[]"]:checked'), 'every line ticked').toHaveCount(2);
    await fillForm(modal, { project_id: String(made.project) });
    await submit(modal, 'mit_po_set_project');
    expect(db.val(lineProject + made.po), 'both lines on it').toBe(`${made.project},${made.project}`);
    expect(db.val(`SELECT COUNT(*) FROM mit_order_item_links WHERE order_item_id IN (${made.items.join(',')}) AND project_id = ${made.project}`), 'their items too').toBe('2');
    await expect(page.locator('[data-po-lines]'), 'the lines show the project').toContainText('PRJ-8899');
    await expectClean(page, errors);

    // The project page lists the PO
    const client = db.val(`SELECT project_client_id FROM projects WHERE project_id = ${made.project}`);
    await visit(page, `agent/project.php?client_id=${client}&project_id=${made.project}`, errors);
    const po = db.one(`SELECT po_prefix, po_number FROM purchase_orders WHERE po_id = ${made.po}`);
    await expect(page.locator('body'), 'on the project\'s Purchase orders card').toContainText(`${po.po_prefix}${po.po_number}`);
    await expectClean(page, errors);
});

test('a receipt made by mistake is undone from the PO page (26.11.8.3, ITFlow\'s since 26.12)', async ({ page }) => {
    const errors = watch(page);
    const cable = Number(db.val(`SELECT po_item_id FROM po_items WHERE po_item_po_id = ${made.po} AND po_item_name = 'Smoke PO cable'`));
    db.php(`poReceive([${cable} => ['qty' => 2]]);`);
    expect(db.val(`SELECT ROUND(po_item_received) FROM po_items WHERE po_item_id = ${cable}`), 'received 2').toBe('2');
    expect(db.val(`SELECT COUNT(*) FROM mit_po_receipt_extras x JOIN po_receipts r ON r.po_receipt_id = x.po_receipt_id WHERE r.po_receipt_item_id = ${cable}`), 'our extras kept').toBe('1');
    await visit(page, `agent/purchase_order.php?po_id=${made.po}`, errors);
    const receipts = page.locator('[data-po-receipts]');
    await expect(receipts, 'the Received card').toContainText('Smoke PO cable');
    await receipts.locator('a.confirm-link').first().click();
    await Promise.all([page.waitForNavigation(), page.locator('.swal2-confirm').click()]);
    expect(await flash(page)).toContain('undone');
    expect(db.val(`SELECT ROUND(po_item_received) FROM po_items WHERE po_item_id = ${cable}`), 'back to waiting').toBe('0');
    expect(db.val(`SELECT po_status FROM purchase_orders WHERE po_id = ${made.po}`), 'the PO is ordered again').toBe('ordered');
    await expect(page.locator('[data-po-receipts]'), 'no receipts left').toHaveCount(0);
    await expectClean(page, errors);
});
