<?php

/*
 * Mountaineer IT - bill a ticket's parts onto a new or existing draft invoice (functions/mit_procurement_parts.php).
 */

require_once '../../../includes/modal_header.php';

mitModuleRequire('procurement', true);

enforceUserPermission('module_sales', 2);
$ticket = ticketForModal($_GET['ticket_id'] ?? 0, 2);
$ticket_id = intval($ticket['ticket_id']);
$client_id = intval($ticket['ticket_client_id']);
if (!$client_id) {
    exit(json_encode(['error' => 'Move the ticket to a client before billing it.']));
}
$parts = array_filter(mitTicketParts($ticket_id), 'mitTicketPartBillable');
$drafts = mysqli_fetch_all(mysqli_query($mysqli, "SELECT invoice_id, invoice_prefix, invoice_number, invoice_scope, invoice_date FROM invoices
    WHERE invoice_client_id = $client_id AND invoice_status = 'Draft' ORDER BY invoice_id DESC"), MYSQLI_ASSOC);
$categories = mysqli_fetch_all(mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_name"), MYSQLI_ASSOC);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-file-invoice-dollar me-2"></i>Bill parts <small class="opacity-75 ms-1"><?= escapeHtml(ticketReference($ticket)) ?></small></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <div class="modal-body">
        <?php if (!$parts) { ?>
            <div class="text-muted">Every part on this ticket is billed.</div>
        <?php } else { ?>
            <table class="table table-sm table-borderless align-middle">
                <thead class="small text-muted border-bottom"><tr><th style="width: 36px"></th><th>Part</th><th class="text-center">Qty</th><th style="width: 130px">Price (each)</th></tr></thead>
                <tbody>
                    <?php foreach ($parts as $part_id => $part) { ?>
                        <tr class="border-bottom">
                            <td><input class="form-check-input" type="checkbox" name="part_ids[]" value="<?= intval($part_id) ?>" checked aria-label="Bill <?= escapeHtml($part['name']) ?>"></td>
                            <td><?= escapeHtml($part['name']) ?><div class="small"><?= mitTicketPartStatus($part) ?></div></td>
                            <td class="text-center"><?= mitQty($part['quantity']) ?></td>
                            <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end" name="prices[<?= intval($part_id) ?>]" value="<?= number_format((float) $part['unit_price'], 2, '.', '') ?>" aria-label="Price"></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>

            <label class="form-label">Invoice</label>
            <select class="form-select mb-3" name="invoice_id" data-mit-bill-invoice>
                <option value="0">A new draft invoice</option>
                <?php foreach ($drafts as $draft) { ?>
                    <option value="<?= intval($draft['invoice_id']) ?>">Add to draft <?= escapeHtml($draft['invoice_prefix'] . $draft['invoice_number'] . ($draft['invoice_scope'] ? ' - ' . $draft['invoice_scope'] : '') . ' (' . $draft['invoice_date'] . ')') ?></option>
                <?php } ?>
            </select>
            <div class="row g-2" data-mit-bill-new>
                <div class="col-sm-6">
                    <label class="form-label">Invoice date</label>
                    <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Income category</label>
                    <select class="form-select" name="category">
                        <?php foreach ($categories as $category) { ?>
                            <option value="<?= intval($category['category_id']) ?>"><?= escapeHtml($category['category_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <?php if ($parts) { ?>
            <button type="submit" name="mit_parts_bill" class="btn btn-success text-bold"><i class="fas fa-check me-2"></i>Bill</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
    if (!window.mitBillBound) {
        window.mitBillBound = true;
        document.addEventListener('change', function (e) {
            if (e.target.matches('[data-mit-bill-invoice]')) {
                e.target.form.querySelector('[data-mit-bill-new]').hidden = e.target.value !== '0';
            }
        });
    }
</script>

<?php
require_once '../../../includes/modal_footer.php';
