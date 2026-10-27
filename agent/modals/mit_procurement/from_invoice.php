<?php

/*
 * Mountaineer IT - send an invoice's lines to procurement (functions/mit_procurement_parts.php).
 * Lines already on the list or ordered show how much; only what is left can be sent.
 */

require_once '../../../includes/modal_header.php';

mitModuleRequire('procurement', true);

enforceUserPermission('module_procurement', 2);
enforceUserPermission('module_sales'); // invoices are Sales'

$invoice_id = intval($_GET['invoice_id'] ?? 0);
$invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_prefix, invoice_number, invoice_client_id FROM invoices WHERE invoice_id = $invoice_id"));
if (!$invoice || !hasClientAccess($invoice['invoice_client_id'])) {
    exit(json_encode(['error' => 'That invoice does not exist, or it is for a client you cannot see.']));
}
$client_id = intval($invoice['invoice_client_id']);
$lines = mitProcInvoiceLineProducts(mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_id, item_name, item_quantity, item_product_id FROM invoice_items WHERE item_invoice_id = $invoice_id ORDER BY item_order, item_id"), MYSQLI_ASSOC));
$products = mitProductsInfo(array_column($lines, 'item_product_id'));
$queued = mitProcInvoiceQueued($invoice_id);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-cart-plus me-2"></i>Send <?= escapeHtml($invoice['invoice_prefix'] . $invoice['invoice_number']) ?> to procurement</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
    <div class="modal-body">
        <div class="mb-3">
            <?= mitProcProjectField('project_id', $client_id, 0, 'Project <span class="text-muted small">(optional)</span>') ?>
        </div>
        <?php if (!$lines) { ?>
            <div class="text-muted">This invoice has no lines.</div>
        <?php } else { ?>
            <table class="table table-sm table-borderless align-middle mb-0">
                <thead class="small text-muted border-bottom">
                    <tr><th style="width: 36px"></th><th>Item</th><th class="text-center" style="width: 90px">Qty to order</th><th style="width: 120px">Our cost (each)</th><th style="width: 180px">Order from</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line) {
                        $item_id = intval($line['item_id']);
                        $product = $products[intval($line['item_product_id'])] ?? null;
                        $done = $queued[$item_id] ?? 0;
                        $left = max(0, (float) $line['item_quantity'] - $done);
                        // Pre-ticked only when it is known hardware - lines made from a quote do not keep their product
                        $is_service = $product && $product['product_type'] === 'service'; ?>
                        <tr class="border-bottom">
                            <td><input class="form-check-input" type="checkbox" name="lines[<?= $item_id ?>][send]" value="1" <?= $left > 0 && $product && !$is_service ? 'checked' : '' ?> <?= $left > 0 ? '' : 'disabled' ?> aria-label="Send <?= escapeHtml($line['item_name']) ?>"></td>
                            <td>
                                <?= escapeHtml($line['item_name']) ?>
                                <?php if ($done > 0) { ?><div class="small text-muted"><?= mitQty($done) ?> already sent<?= $left > 0 ? '' : ' - all of it' ?></div><?php } ?>
                                <?php if ($is_service) { ?><span class="badge text-bg-light border">Service</span><?php } ?>
                                <?php if ($product && $product['product_type'] === 'product') { ?><div class="small text-muted">Taken from stock when it was put on the invoice - that stock goes back when the order arrives</div><?php } ?>
                            </td>
                            <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-center" name="lines[<?= $item_id ?>][qty]" value="<?= mitQty($left) ?>" aria-label="Quantity" <?= $left > 0 ? '' : 'disabled' ?>></td>
                            <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end" name="lines[<?= $item_id ?>][cost]" value="<?= $product && $product['cost'] !== null ? number_format((float) $product['cost'], 2, '.', '') : '' ?>" placeholder="0.00" aria-label="Our cost" <?= $left > 0 ? '' : 'disabled' ?>></td>
                            <td><select class="form-select form-select-sm" name="lines[<?= $item_id ?>][vendor_id]" aria-label="Order from" <?= $left > 0 ? '' : 'disabled' ?>><?= mitProcVendorOptions($product['vendor_id'] ?? 0) ?></select></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_proc_from_invoice" class="btn btn-primary text-bold"><i class="fas fa-cart-plus me-2"></i>Send to procurement</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
