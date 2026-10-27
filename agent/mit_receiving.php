<?php

/*
 * Mountaineer IT - Procurement > Receiving (functions/mit_procurement.php): everything ordered and
 * still to arrive, by PO. Tick what came in - part of a line too - with serial numbers if wanted.
 * ?po_id= shows one PO.
 */

require_once "includes/inc_all.php";

mitModuleRequire('procurement');

enforceUserPermission('module_procurement');
$can_receive = mitProcLevel() >= 2;

$by_po = mitPoAwaitingLines();
$only_po = intval($_GET['po_id'] ?? 0);
if ($only_po) {
    $by_po = isset($by_po[$only_po]) ? [$only_po => $by_po[$only_po]] : [];
}
$tracking = mitPoTrackingFor(array_keys($by_po));
$units = 0;
foreach ($by_po as $lines) {
    foreach ($lines as $line) {
        $units += (float) $line['quantity'] - (float) $line['received_qty'];
    }
}

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-truck-loading me-2"></i>Receiving</h3>
        <?php if ($only_po) { ?>
            <div class="card-tools"><a href="mit_receiving.php" class="btn btn-light btn-sm mt-1">Show every PO</a></div>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if (!$by_po) { ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                <div>Nothing waiting to arrive.</div>
            </div>
        <?php } else { ?>
            <p class="text-muted"><?= count($by_po) ?> PO<?= count($by_po) === 1 ? '' : 's' ?> · <?= mitQty($units) ?> unit<?= $units == 1 ? '' : 's' ?> still to arrive. Tick what came in and change the number for part of a line.</p>
            <form action="post.php" method="post" autocomplete="off" id="mitReceiveForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="table-responsive">
                    <table class="table table-borderless mb-0 align-middle">
                        <thead class="text-nowrap border-bottom">
                            <tr>
                                <th style="width: 60px">Got it</th>
                                <th>Item</th>
                                <th>For</th>
                                <th class="text-center">Ordered</th>
                                <th class="text-center">Already in</th>
                                <th class="text-center" style="width: 110px">Received now</th>
                                <th style="width: 260px">Serial numbers <span class="fw-normal text-muted">(optional)</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_po as $po_id => $lines) {
                                $first = $lines[0]; ?>
                                <tr class="bg-body-tertiary">
                                    <td colspan="7" class="small py-1">
                                        <a href="mit_purchase_order.php?po_id=<?= intval($po_id) ?>" class="fw-bold"><?= escapeHtml(mitPoReference($first)) ?></a>
                                        · <?= escapeHtml($first['vendor_name']) ?>
                                        <?php if ($first['vendor_order_number']) { ?>· order <?= escapeHtml($first['vendor_order_number']) ?><?php } ?>
                                        <?php foreach ($tracking[$po_id] ?? [] as $t) { ?>· <?= mitTrackingHtml($t) ?><?= intval($t['delivered']) ? ' <span class="badge text-bg-success">delivered</span>' : '' ?><?php } ?>
                                    </td>
                                </tr>
                                <?php foreach ($lines as $line) {
                                    $line_id = intval($line['line_id']);
                                    $left = (float) $line['quantity'] - (float) $line['received_qty']; ?>
                                    <tr class="border-bottom">
                                        <td><input class="form-check-input" type="checkbox" data-mit-got data-left="<?= mitQty($left) ?>" aria-label="Received <?= escapeHtml($line['name']) ?>" <?= $can_receive ? '' : 'disabled' ?>></td>
                                        <td>
                                            <strong><?= escapeHtml($line['name']) ?></strong>
                                            <?php if (intval($line['client_id']) && $can_receive) {
                                                $guess = mitProcGuessAssetType($line['name']); ?>
                                                <div class="d-flex align-items-center gap-2 mt-1" data-mit-asset-type>
                                                    <span class="small text-muted text-nowrap">Asset type</span>
                                                    <select class="form-select form-select-sm w-auto" name="received[<?= $line_id ?>][asset_type]" aria-label="Asset type">
                                                        <?php foreach (array_keys($asset_types_array) as $type) { ?>
                                                            <option <?= $type === $guess ? 'selected' : '' ?>><?= escapeHtml($type) ?></option>
                                                        <?php } ?>
                                                        <option value="none">Don't make assets</option>
                                                    </select>
                                                </div>
                                            <?php } ?>
                                        </td>
                                        <td class="small"><?= mitProcForHtml($line) ?></td>
                                        <td class="text-center"><?= mitQty($line['quantity']) ?></td>
                                        <td class="text-center"><?= mitQty($line['received_qty']) ?></td>
                                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-center" name="received[<?= $line_id ?>][qty]" value="0" data-mit-qty aria-label="Received now" <?= $can_receive ? '' : 'disabled' ?>></td>
                                        <td><textarea class="form-control form-control-sm" name="received[<?= $line_id ?>][serials]" rows="1" placeholder="Scan or type, one per line" <?= $can_receive ? '' : 'disabled' ?>></textarea></td>
                                    </tr>
                                <?php }
                            } ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($can_receive) { ?>
                    <div class="d-flex flex-wrap align-items-center gap-3 mt-3 p-3 rounded bg-body-tertiary border">
                        <strong data-mit-total>Nothing ticked</strong>
                        <?php $mit_may = mitProcReceiveOptions(['assets' => 1, 'stock' => 1]); ?>
                        <?php if (isset($mit_may['assets'])) { ?>
                        <div class="form-check mb-0" title="One asset per unit for the client, with its serial number - linked to the ticket when it was a ticket's part">
                            <input class="form-check-input" type="checkbox" name="options[assets]" value="1" id="mitOptAssets">
                            <label class="form-check-label" for="mitOptAssets">Make client items assets</label>
                        </div>
                        <div class="form-check mb-0" title="Opens the asset window for each new asset in turn - rename it, add make, model, status, assignment">
                            <input class="form-check-input" type="checkbox" name="options[review]" value="1" id="mitOptReview" checked>
                            <label class="form-check-label" for="mitOptReview">Fill in each asset's details</label>
                        </div>
                        <?php } ?>
                        <?php if (isset($mit_may['stock'])) { ?>
                        <div class="form-check mb-0" title="Items ordered for our own stock are added to the product's stock">
                            <input class="form-check-input" type="checkbox" name="options[stock]" value="1" id="mitOptStock">
                            <label class="form-check-label" for="mitOptStock">Add stock items to stock</label>
                        </div>
                        <?php } ?>
                        <?php if (isset($mit_may['assets'])) { ?>
                        <div class="form-check mb-0" title="An internal note on the ticket a part was for">
                            <input class="form-check-input" type="checkbox" name="options[notes]" value="1" id="mitOptNotes" checked>
                            <label class="form-check-label" for="mitOptNotes">Note it on the ticket</label>
                        </div>
                        <?php } ?>
                        <button type="submit" name="mit_po_receive" class="btn btn-success ms-auto"><i class="fas fa-check me-2"></i>Mark received</button>
                    </div>
                <?php } ?>
            </form>
            <script>
                (function () {
                    const form = document.getElementById('mitReceiveForm');
                    const total = form.querySelector('[data-mit-total]');
                    const assets = document.getElementById('mitOptAssets');
                    if (assets) {
                        // Off until ticked: the asset type pickers and "Fill in each asset's details" only show with it
                        function assetOptions() {
                            form.querySelectorAll('[data-mit-asset-type]').forEach(function (row) {
                                row.classList.toggle('d-none', !assets.checked);
                            });
                            document.getElementById('mitOptReview').closest('.form-check').classList.toggle('d-none', !assets.checked);
                        }
                        assets.addEventListener('change', assetOptions);
                        assetOptions();
                    }
                    function sum() {
                        let n = 0;
                        form.querySelectorAll('[data-mit-qty]').forEach(function (input) {
                            n += parseFloat(input.value) || 0;
                        });
                        if (total) {
                            total.textContent = n ? n + ' item' + (n === 1 ? '' : 's') + ' to mark received' : 'Nothing ticked';
                        }
                    }
                    // Ticking fills in all that is left; unticking clears it
                    form.querySelectorAll('[data-mit-got]').forEach(function (box) {
                        const qty = box.closest('tr').querySelector('[data-mit-qty]');
                        box.addEventListener('change', function () {
                            qty.value = box.checked ? box.dataset.left : '0';
                            sum();
                        });
                        qty.addEventListener('input', function () {
                            box.checked = (parseFloat(qty.value) || 0) > 0;
                            sum();
                        });
                    });
                })();
            </script>
        <?php } ?>
    </div>
</div>

<?php
// Assets just made by receiving (?mit_review_assets=1,2,3): ITFlow's own asset window for each in turn. The address
// drops each one as its window opens, so saving (which comes back here) carries on with the rest; Cancel skips it.
$mit_review = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['mit_review_assets'] ?? '')))));
if ($mit_review) { ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const queue = <?= json_encode($mit_review) ?>;
            const total = Math.max(<?= intval($_GET['mit_review_total'] ?? 0) ?>, queue.length);
            function address(rest) {
                const url = new URL(location.href);
                if (rest.length) {
                    url.searchParams.set('mit_review_assets', rest.join(','));
                } else {
                    url.searchParams.delete('mit_review_assets');
                    url.searchParams.delete('mit_review_total');
                }
                return url.pathname + url.search;
            }
            function next() {
                const id = queue.shift();
                if (!id) {
                    history.replaceState(null, '', address([]));
                    return;
                }
                history.replaceState(null, '', address(queue));
                const opener = document.createElement('a');
                opener.className = 'ajax-modal d-none';
                opener.dataset.modalSize = 'lg';
                opener.dataset.modalUrl = 'modals/asset/asset_edit.php?id=' + id;
                document.body.appendChild(opener);
                opener.click();
                opener.remove();
                // Once the window is up: say where we are, and move on when it is closed without saving
                const watch = setInterval(function () {
                    const modal = document.querySelector('.modal.show');
                    if (!modal || !modal.querySelector('form')) return;
                    clearInterval(watch);
                    const title = modal.querySelector('.modal-title');
                    if (title) title.insertAdjacentHTML('beforeend', ' <span class="badge text-bg-light ms-2">New asset ' + (total - queue.length) + ' of ' + total + '</span>');
                    let saving = false;
                    modal.querySelector('form').addEventListener('submit', function () { saving = true; });
                    modal.addEventListener('hidden.bs.modal', function () {
                        if (!saving) next();
                    }, { once: true });
                }, 100);
            }
            next();
        });
    </script>
<?php }
require_once "../includes/footer.php";
