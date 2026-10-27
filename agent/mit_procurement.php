<?php

/*
 * Mountaineer IT - Procurement > To order (functions/mit_procurement.php): everything waiting to be
 * put on a purchase order, grouped by client. Tick items and make a PO, add them to a draft PO,
 * set who they come from, or drop them.
 */

require_once "includes/inc_all.php";

mitModuleRequire('procurement');

enforceUserPermission('module_procurement');
$can_edit = mitProcLevel() >= 2;

$vendor_filter = intval($_GET['vendor'] ?? 0);
$client_filter = intval($_GET['client'] ?? 0);
$items = mitProcWaitingItems($vendor_filter, $client_filter);

$vendors = mitProcVendorRows();
$drafts = mysqli_fetch_all(mysqli_query($mysqli, "SELECT po_id, po_prefix, po_number, vendor_name FROM mit_purchase_orders LEFT JOIN vendors ON vendors.vendor_id = mit_purchase_orders.vendor_id
    WHERE status = 'draft'" . mitPoScopeSql('mit_purchase_orders.po_id') . " ORDER BY po_id DESC"), MYSQLI_ASSOC);
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT clients.client_id, client_name FROM mit_procurement_items proc JOIN clients ON clients.client_id = proc.client_id
    WHERE proc.status = 'waiting'" . clientScopeSql('proc.client_id') . " ORDER BY client_name"), MYSQLI_ASSOC);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-cart-plus me-2"></i>To order</h3>
        <div class="card-tools">
            <?php if ($can_edit) { ?>
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/mit_procurement/item.php">
                    <i class="fas fa-plus me-2"></i>Add item
                </button>
            <?php } ?>
        </div>
    </div>

    <div class="card-body">
        <form class="row g-2 mb-3" method="get" autocomplete="off">
            <div class="col-sm-4 col-lg-3">
                <select class="form-select select2" name="vendor" onchange="this.form.submit()" aria-label="Vendor">
                    <option value="0">All vendors</option>
                    <option value="-1" <?= $vendor_filter === -1 ? 'selected' : '' ?>>No vendor yet</option>
                    <?php foreach ($vendors as $vendor) { ?>
                        <option value="<?= intval($vendor['vendor_id']) ?>" <?= $vendor_filter === intval($vendor['vendor_id']) ? 'selected' : '' ?>><?= escapeHtml($vendor['vendor_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-sm-4 col-lg-3">
                <select class="form-select select2" name="client" onchange="this.form.submit()" aria-label="Client">
                    <option value="0">All clients</option>
                    <option value="-1" <?= $client_filter === -1 ? 'selected' : '' ?>>Our stock (no client)</option>
                    <?php foreach ($clients as $client) { ?>
                        <option value="<?= intval($client['client_id']) ?>" <?= $client_filter === intval($client['client_id']) ? 'selected' : '' ?>><?= escapeHtml($client['client_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
        </form>

        <?php if (!$items) { ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                <div>Nothing waiting to be ordered<?= $vendor_filter || $client_filter ? ' for this filter' : '' ?>.</div>
            </div>
        <?php } else { ?>
            <form action="post.php" method="post" autocomplete="off" id="mitProcForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="table-responsive">
                    <table class="table table-borderless table-hover mb-0">
                        <thead class="text-nowrap">
                            <tr class="border-bottom">
                                <?php if ($can_edit) { ?><th style="width: 36px"><input class="form-check-input" type="checkbox" data-mit-check-all aria-label="Select all"></th><?php } ?>
                                <th>Item</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Our cost</th>
                                <th>Order from</th>
                                <th>For</th>
                                <th>Waiting</th>
                                <?php if ($can_edit) { ?><th></th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $group = null;
                            foreach ($items as $item) {
                                $item_group = intval($item['client_id']);
                                if ($item_group !== $group) {
                                    $group = $item_group; ?>
                                    <tr class="bg-body-tertiary">
                                        <td colspan="<?= $can_edit ? 8 : 6 ?>" class="small fw-bold text-uppercase py-1"><?= $group ? escapeHtml($item['client_name']) : 'Our stock' ?></td>
                                    </tr>
                                <?php }
                                $waiting_days = (time() - strtotime($item['created_at'])) / 86400; ?>
                                <tr class="border-top">
                                    <?php if ($can_edit) { ?><td><input class="form-check-input" type="checkbox" name="item_ids[]" value="<?= intval($item['item_id']) ?>" data-vendor="<?= intval($item['vendor_id']) ?>" aria-label="Select <?= escapeHtml($item['name']) ?>"></td><?php } ?>
                                    <td>
                                        <strong><?= escapeHtml($item['name']) ?></strong>
                                        <?php if ($item['note']) { ?><div class="small text-muted"><?= escapeHtml($item['note']) ?></div><?php } ?>
                                    </td>
                                    <td class="text-center"><?= mitQty($item['quantity']) ?></td>
                                    <td class="text-end"><?= $item['unit_cost'] === null ? '<span class="text-muted">-</span>' : mitMoney($item['unit_cost']) ?></td>
                                    <td><?= $item['vendor_name'] ? escapeHtml($item['vendor_name']) : '<span class="text-warning-emphasis">Not set</span>' ?></td>
                                    <td><?= mitProcForHtml($item) ?></td>
                                    <td class="<?= $waiting_days >= 2 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= timeAgo($item['created_at']) ?></td>
                                    <?php if ($can_edit) { ?>
                                        <td class="text-end"><a href="#" class="btn btn-sm btn-light ajax-modal" data-modal-url="modals/mit_procurement/item.php?item_id=<?= intval($item['item_id']) ?>" title="Edit"><i class="fas fa-pen"></i></a></td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($can_edit) { ?>
                    <div class="d-flex flex-wrap align-items-end gap-2 mt-3 p-3 rounded bg-body-tertiary border">
                        <div class="me-auto">
                            <strong data-mit-selected>0 selected</strong>
                            <div class="small text-muted">A PO is from one vendor. Items from several clients can share it.</div>
                        </div>
                        <div>
                            <label class="form-label small mb-1" for="mitPoVendor">Order from</label>
                            <select class="form-select" name="vendor_id" id="mitPoVendor">
                                <option value="0">- Pick a vendor -</option>
                                <?php foreach ($vendors as $vendor) { ?>
                                    <option value="<?= intval($vendor['vendor_id']) ?>"><?= escapeHtml($vendor['vendor_name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <button type="submit" name="mit_po_create" class="btn btn-primary"><i class="fas fa-file-signature me-2"></i>Create PO</button>
                        <button type="submit" name="mit_proc_set_vendor" class="btn btn-outline-secondary">Set vendor</button>
                        <?php if ($drafts) { ?>
                            <div class="input-group w-auto">
                                <select class="form-select" name="po_id" aria-label="Draft PO">
                                    <?php foreach ($drafts as $draft) { ?>
                                        <option value="<?= intval($draft['po_id']) ?>"><?= escapeHtml(mitPoReference($draft) . ' · ' . $draft['vendor_name']) ?></option>
                                    <?php } ?>
                                </select>
                                <button type="submit" name="mit_po_add_items" class="btn btn-outline-primary">Add to draft</button>
                            </div>
                        <?php } ?>
                        <button type="submit" name="mit_proc_cancel_items" class="btn btn-outline-danger confirm-link" data-confirm-title="Take the ticked items off the list?" data-confirm-text="They will not be ordered." data-confirm-button="Not needed">Not needed</button>
                    </div>
                <?php } ?>
            </form>

            <script>
                (function () {
                    const form = document.getElementById('mitProcForm');
                    const boxes = form.querySelectorAll('[name="item_ids[]"]');
                    const label = form.querySelector('[data-mit-selected]');
                    const vendor = document.getElementById('mitPoVendor');
                    function update() {
                        const ticked = Array.prototype.filter.call(boxes, function (b) { return b.checked; });
                        if (label) {
                            label.textContent = ticked.length + ' selected';
                        }
                        // The vendor the ticked items share, when they share one
                        const vendors = Array.from(new Set(ticked.map(function (b) { return b.dataset.vendor; })));
                        if (vendor && vendors.length === 1 && vendors[0] !== '0') {
                            vendor.value = vendors[0];
                        }
                    }
                    boxes.forEach(function (b) { b.addEventListener('change', update); });
                    const all = form.querySelector('[data-mit-check-all]');
                    if (all) {
                        all.addEventListener('change', function () {
                            boxes.forEach(function (b) { b.checked = all.checked; });
                            update();
                        });
                    }
                })();
            </script>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
