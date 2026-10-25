<?php

/*
 * Mountaineer IT - add a part to a ticket, or change / remove one (?part_id=) - functions/mit_procurement_parts.php.
 */

require_once '../../../includes/modal_header.php';

mitModuleRequire('procurement', true);

$ticket = ticketForModal($_GET['ticket_id'] ?? 0, 2);
$ticket_id = intval($ticket['ticket_id']);
$client_id = intval($ticket['ticket_client_id']);
$part_id = intval($_GET['part_id'] ?? 0);
$part = $part_id ? (mitTicketParts($ticket_id)[$part_id] ?? null) : null;
if ($part_id && (!$part || intval($part['invoice_id']))) {
    exit(json_encode(['error' => 'That part is billed, or no longer on this ticket.']));
}
$on_po = $part && $part['source'] === 'order' && !in_array($part['proc_status'], [null, 'waiting', 'cancelled'], true);
$value = function ($key, $default = '') use ($part) {
    return escapeHtml($part[$key] ?? $default);
};

$products = mysqli_fetch_all(mysqli_query($mysqli, "SELECT product_id, product_name, product_description, product_price, product_tax_id, product_type"
    . (mitQuoteColumnsReady() ? ', product_cost' : ', NULL AS product_cost') . (mitProductVendorReady() ? ', product_vendor_id' : ', NULL AS product_vendor_id') . "
    FROM products WHERE product_archived_at IS NULL ORDER BY product_name"), MYSQLI_ASSOC);
$see_cost = mitPartsSeeCost();
$stocks = mitProductStocks(array_column(array_filter($products, function ($product) {
    return $product['product_type'] === 'product';
}), 'product_id'));
$taxes = mysqli_fetch_all(mysqli_query($mysqli, "SELECT tax_id, tax_name, tax_percent FROM taxes WHERE tax_archived_at IS NULL ORDER BY tax_name"), MYSQLI_ASSOC);
$assets = $client_id ? mysqli_fetch_all(mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_type FROM assets WHERE asset_client_id = $client_id AND asset_archived_at IS NULL ORDER BY asset_name"), MYSQLI_ASSOC) : [];
$source = $part['source'] ?? 'stock';
$vendor_id = $part && intval($part['proc_item_id']) ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT vendor_id FROM mit_procurement_items WHERE item_id = " . intval($part['proc_item_id'])))[0] ?? 0) : 0;

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-microchip me-2"></i><?= $part ? 'Part' : 'Add a part' ?> <small class="opacity-75 ms-1"><?= escapeHtml(ticketReference($ticket)) ?></small></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <input type="hidden" name="part_id" value="<?= $part_id ?>">
    <div class="modal-body">
        <?php if ($on_po) { ?>
            <div class="alert alert-info small">This part is on a purchase order - its quantity and where it comes from are changed there. Price, tax and asset can still change.</div>
        <?php } ?>

        <div class="mb-3">
            <label class="form-label">Product <span class="text-muted small">(optional)</span></label>
            <select class="form-select select2" name="product_id" data-mit-part-product <?= $on_po ? 'disabled' : '' ?>>
                <option value="0">- Type it in below -</option>
                <?php foreach ($products as $product) { ?>
                    <option value="<?= intval($product['product_id']) ?>" <?= intval($part['product_id'] ?? 0) === intval($product['product_id']) ? 'selected' : '' ?>
                        data-name="<?= escapeHtml($product['product_name']) ?>" data-price="<?= number_format((float) $product['product_price'], 2, '.', '') ?>"
                        data-cost="<?= !$see_cost || (float) $product['product_cost'] <= 0 ? '' : number_format((float) $product['product_cost'], 2, '.', '') ?>"
                        data-tax="<?= intval($product['product_tax_id']) ?>" data-vendor="<?= intval($product['product_vendor_id']) ?>"
                        data-stock="<?= $product['product_type'] === 'product' ? mitQty($stocks[intval($product['product_id'])] ?? 0) : '' ?>"><?= escapeHtml($product['product_name']) ?></option>
                <?php } ?>
            </select>
            <div class="form-text" data-mit-part-stock></div>
        </div>

        <div class="mb-3">
            <label class="form-label">Part <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="200" required value="<?= $value('name') ?>" data-mit-part-name>
        </div>

        <div class="mb-3">
            <label class="form-label d-block">Where it comes from</label>
            <div class="btn-group flex-wrap" role="group">
                <?php foreach (MIT_PART_SOURCES as $key => $label) { ?>
                    <input type="radio" class="btn-check" name="source" value="<?= $key ?>" id="mitPartSource<?= $key ?>" <?= $source === $key ? 'checked' : '' ?> <?= $on_po && $key !== 'order' ? 'disabled' : '' ?>>
                    <label class="btn btn-outline-secondary" for="mitPartSource<?= $key ?>"><?= $label ?></label>
                <?php } ?>
            </div>
            <div class="form-text">From stock takes it off the product's stock. To order puts it on Procurement > To order and shows its PO here.</div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-sm-3">
                <label class="form-label">Qty <strong class="text-danger">*</strong></label>
                <input type="text" inputmode="decimal" class="form-control" name="quantity" required value="<?= $part ? mitQty($part['quantity']) : '1' ?>" <?= $on_po ? 'readonly' : '' ?>>
            </div>
            <?php if ($see_cost) { ?>
                <div class="col-sm-3">
                    <label class="form-label">Our cost</label>
                    <input type="text" inputmode="decimal" class="form-control" name="unit_cost" placeholder="0.00" value="<?= $part && $part['unit_cost'] !== null ? number_format((float) $part['unit_cost'], 2, '.', '') : '' ?>" data-mit-part-cost>
                </div>
            <?php } ?>
            <div class="col-sm-3">
                <label class="form-label">Price (each)</label>
                <input type="text" inputmode="decimal" class="form-control" name="unit_price" placeholder="0.00" value="<?= $part ? number_format((float) $part['unit_price'], 2, '.', '') : '' ?>" data-mit-part-price>
            </div>
            <div class="col-sm-3">
                <label class="form-label">Tax</label>
                <select class="form-select" name="tax_id" data-mit-part-tax>
                    <option value="0">No tax</option>
                    <?php foreach ($taxes as $tax) { ?>
                        <option value="<?= intval($tax['tax_id']) ?>" <?= intval($part['tax_id'] ?? 0) === intval($tax['tax_id']) ? 'selected' : '' ?>><?= escapeHtml($tax['tax_name'] . ' ' . $tax['tax_percent'] . '%') ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="row g-2">
            <div class="col-sm-6" data-mit-part-vendor-wrap>
                <label class="form-label">Order from</label>
                <select class="form-select" name="vendor_id" data-mit-part-vendor <?= $on_po ? 'disabled' : '' ?>><?= mitProcVendorOptions($vendor_id) ?></select>
            </div>
            <?php if ($assets) { ?>
                <div class="col-sm-6">
                    <label class="form-label">For asset <span class="text-muted small">(optional)</span></label>
                    <select class="form-select select2" name="asset_id">
                        <option value="0">- None -</option>
                        <?php foreach ($assets as $asset) { ?>
                            <option value="<?= intval($asset['asset_id']) ?>" <?= intval($part['asset_id'] ?? 0) === intval($asset['asset_id']) ? 'selected' : '' ?>><?= escapeHtml($asset['asset_name'] . ' (' . $asset['asset_type'] . ')') ?></option>
                        <?php } ?>
                    </select>
                </div>
            <?php } ?>
        </div>
        <?php if ($on_po) { ?>
            <input type="hidden" name="source" value="order">
            <input type="hidden" name="product_id" value="<?= intval($part['product_id']) ?>">
            <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
        <?php } ?>
    </div>
    <div class="modal-footer">
        <?php if ($part && !$on_po) { ?>
            <a href="post.php?mit_part_remove=<?= $part_id ?>&ticket_id=<?= $ticket_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger confirm-link me-auto"><i class="fas fa-trash me-2"></i>Remove</a>
        <?php } ?>
        <button type="submit" name="mit_part_save" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i><?= $part ? 'Save' : 'Add' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
    // A product fills in the part; the vendor only matters for a part to order
    if (!window.mitPartBound) {
        window.mitPartBound = true;
        document.addEventListener('change', function (e) {
            const form = e.target.form;
            if (!form || !form.querySelector('[data-mit-part-product]')) {
                return;
            }
            if (e.target.matches('[data-mit-part-product]')) {
                const o = e.target.options[e.target.selectedIndex];
                if (o && o.value !== '0') {
                    form.querySelector('[data-mit-part-name]').value = o.dataset.name || '';
                    form.querySelector('[data-mit-part-price]').value = o.dataset.price || '';
                    if (o.dataset.cost && form.querySelector('[data-mit-part-cost]')) {
                        form.querySelector('[data-mit-part-cost]').value = o.dataset.cost;
                    }
                    form.querySelector('[data-mit-part-tax]').value = o.dataset.tax || '0';
                    if (o.dataset.vendor && o.dataset.vendor !== '0') {
                        form.querySelector('[data-mit-part-vendor]').value = o.dataset.vendor;
                    }
                }
                const stock = form.querySelector('[data-mit-part-stock]');
                stock.textContent = o && o.dataset.stock !== undefined && o.dataset.stock !== '' ? o.dataset.stock + ' on the shelf' : '';
            }
            if (e.target.name === 'source') {
                form.querySelector('[data-mit-part-vendor-wrap]').hidden = e.target.value !== 'order';
            }
        });
    }
    (function () {
        const all = document.querySelectorAll('[data-mit-part-product]');
        const form = all[all.length - 1].form;
        const checked = form.querySelector('[name=source]:checked');
        form.querySelector('[data-mit-part-vendor-wrap]').hidden = !checked || checked.value !== 'order';
    })();
</script>

<?php
require_once '../../../includes/modal_footer.php';
