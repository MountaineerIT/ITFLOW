<?php

/*
 * Mountaineer IT - log mileage or a purchase on a ticket for reimbursement (?ticket_id=&kind=mileage|purchase),
 * or change / remove one (?id=) - functions/mit_reimbursements.php.
 */

require_once '../../../includes/modal_header.php';

mitModuleRequire('timesheets', true);
if (!mitReimbReady()) {
    exit(json_encode(['error' => 'Reimbursements need the database update - Admin > Update.']));
}

$reimb_id = intval($_GET['id'] ?? 0);
$row = $reimb_id ? mitReimbRow($reimb_id) : null;
if ($reimb_id) {
    if (!$row || (intval($row['client_id']) && !hasClientAccess($row['client_id']))) {
        exit(json_encode(['error' => 'That reimbursement no longer exists.']));
    }
    $why = mitReimbWhyLocked($row);
    if ($why !== '') {
        exit(json_encode(['error' => $why]));
    }
    $ticket = ticketRow($row['ticket_id']);
    $kind = $row['kind'];
} else {
    $ticket = ticketForModal($_GET['ticket_id'] ?? 0, 2);
    $kind = ($_GET['kind'] ?? '') === 'purchase' ? 'purchase' : 'mileage';
}
$settings = mitReimbSettings();
$value = function ($key, $default = '') use ($row) {
    return escapeHtml((string) ($row[$key] ?? $default));
};
$can_add_part = !$row && $kind === 'purchase' && $ticket && function_exists('mitProcOn') && mitProcOn() && intval($ticket['ticket_client_id']);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw <?= $kind === 'mileage' ? 'fa-car' : 'fa-shopping-bag' ?> me-2"></i><?= $row ? 'Change ' : '' ?><?= $kind === 'mileage' ? 'Mileage' : 'Purchase' ?>
        <small class="opacity-75 ms-1"><?= $ticket ? escapeHtml(ticketReference($ticket)) : 'deleted ticket' ?></small></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="reimb_id" value="<?= $reimb_id ?>">
    <input type="hidden" name="ticket_id" value="<?= intval($ticket['ticket_id'] ?? 0) ?>">
    <input type="hidden" name="kind" value="<?= $kind ?>">
    <div class="modal-body">
        <?php if (!$ticket) { ?>
            <div class="alert alert-warning py-2 small">Its ticket was deleted - it can only be removed.</div>
        <?php } ?>
        <div class="row g-3">
            <div class="col-sm-5">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="date" value="<?= $value('entry_date', date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required>
            </div>
            <?php if ($kind === 'mileage') { ?>
                <div class="col-sm-7">
                    <label class="form-label">Miles driven</label>
                    <div class="input-group">
                        <input type="text" inputmode="decimal" class="form-control" name="miles" value="<?= $row ? rtrim(rtrim(number_format((float) $row['miles'], 1, '.', ''), '0'), '.') : '' ?>" required>
                        <span class="input-group-text">× <?= mitMoney($row ? $row['rate'] : $settings['rate']) ?> a mile</span>
                    </div>
                    <?php if (!$row && $settings['rate'] <= 0) { ?><div class="form-text text-danger">No rate per mile is set yet (Admin &gt; Timesheets &gt; Settings).</div><?php } ?>
                </div>
                <div class="col-sm-6">
                    <label class="form-label">From</label>
                    <input type="text" class="form-control" name="source" maxlength="200" value="<?= $value('source') ?>" placeholder="Office">
                </div>
                <div class="col-sm-6">
                    <label class="form-label">To</label>
                    <input type="text" class="form-control" name="destination" maxlength="200" value="<?= $value('destination', $ticket['client_name'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="round_trip" value="1" id="mitReimbRound" <?= !$row || intval($row['round_trip']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mitReimbRound">Round trip <span class="text-muted small">(the miles above are both ways)</span></label>
                    </div>
                    <div class="form-text">Also logged as a trip under Finance &gt; Trips.</div>
                </div>
            <?php } else { ?>
                <div class="col-sm-7">
                    <label class="form-label">What it cost</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span>
                        <input type="text" inputmode="decimal" class="form-control" name="amount" value="<?= $row ? number_format((float) $row['amount'], 2, '.', '') : '' ?>" placeholder="0.00" required>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">What was bought</label>
                    <input type="text" class="form-control" name="description" maxlength="255" value="<?= $value('description') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Where from</label>
                    <input type="text" class="form-control" name="vendor" maxlength="200" value="<?= $value('vendor') ?>" placeholder="Store or website">
                </div>
                <div class="col-12">
                    <label class="form-label">Receipt <span class="text-muted small">(a photo or PDF)</span></label>
                    <input type="file" class="form-control" name="receipt" accept="image/*,application/pdf">
                    <?php if (!empty($row['receipt'])) { ?>
                        <div class="form-text"><a href="/agent/mit_receipt.php?id=<?= $reimb_id ?>" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i><?= $value('receipt_name', 'receipt') ?></a> - choose a file to replace it.</div>
                    <?php } ?>
                </div>
                <?php if ($can_add_part) { ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="add_part" value="1" id="mitReimbPart" checked>
                            <label class="form-check-label" for="mitReimbPart">Add it to the ticket's Parts, to bill the customer</label>
                        </div>
                    </div>
                    <div class="col-sm-7" data-mit-reimb-sell>
                        <label class="form-label">Sell price <span class="text-muted small">(empty = what it cost)</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span>
                            <input type="text" inputmode="decimal" class="form-control" name="sell_price" placeholder="0.00">
                        </div>
                    </div>
                <?php } elseif (!empty($row['part_id'])) { ?>
                    <div class="col-12 form-text">On the ticket's Parts - its cost follows this while the part is not billed.</div>
                <?php } ?>
            <?php } ?>
        </div>
    </div>
    <div class="modal-footer">
        <?php if ($row) { ?>
            <a href="post.php?mit_reimb_delete=<?= $reimb_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger confirm-link me-auto"><i class="fas fa-trash-alt me-2"></i>Remove</a>
        <?php } ?>
        <?php if ($ticket) { ?>
            <button type="submit" name="mit_reimb_save" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i><?= $row ? 'Save' : 'Log it' ?></button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php if ($can_add_part) { ?>
<script>
    (function () {
        const box = document.getElementById('mitReimbPart');
        box.addEventListener('change', function () {
            box.form.querySelector('[data-mit-reimb-sell]').hidden = !box.checked;
        });
    })();
</script>
<?php } ?>

<?php
require_once '../../../includes/modal_footer.php';
