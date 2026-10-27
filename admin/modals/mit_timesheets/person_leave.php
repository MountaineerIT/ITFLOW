<?php

/*
 * Mountaineer IT - a person's leave (admin/mit_timesheets.php?tab=people, functions/mit_leave.php):
 * balances, their own rate or leaving them out of a bank, adding or taking off hours, and the history.
 */

require_once '../../includes/modal_header.php';

$user_id = intval($_GET['id'] ?? 0);
$person = mitTsUsers([$user_id])[$user_id] ?? null;
if (!mitLeaveReady() || !$person) {
    exit(json_encode(['error' => 'That person does not keep a timesheet.']));
}
$banks = mitLeaveBanks();

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-umbrella-beach me-2"></i>Leave <small class="opacity-75 ms-1"><?= escapeHtml($person['user_name']) ?></small></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <?php if (!$banks) { ?>
        <div class="text-muted">No leave banks yet - add them on the Leave tab.</div>
    <?php } else { ?>
        <form action="post.php" method="post" autocomplete="off" class="mb-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="user_id" value="<?= $user_id ?>">
            <table class="table table-sm align-middle mb-2">
                <thead class="small text-muted"><tr><th>Bank</th><th class="text-end">Today</th><th class="text-end">After booked</th><th style="width: 190px">Their rate</th><th class="text-center">Left out</th></tr></thead>
                <tbody>
                    <?php foreach ($banks as $bank_id => $bank) {
                        $rate = mitLeaveRate($user_id, $bank_id);
                        $balance = mitLeaveBalance($user_id, $bank); ?>
                        <tr>
                            <td><?= escapeHtml($bank['bank_name']) ?><div class="small text-muted"><?= escapeHtml(mitLeaveRuleText($bank)) ?></div></td>
                            <td class="text-end fw-semibold <?= $balance['now'] < 0 ? 'text-danger' : '' ?>"><?= mitTsHours($balance['now'], false, 'decimal') ?></td>
                            <td class="text-end <?= $balance['booked'] < 0 ? 'text-danger' : '' ?>"><?= mitTsHours($balance['booked'], false, 'decimal') ?></td>
                            <td>
                                <?php if ($bank['bank_method'] !== 'none') { ?>
                                    <div class="input-group input-group-sm">
                                        <input type="text" inputmode="decimal" class="form-control" name="rates[<?= intval($bank_id) ?>]" value="<?= $rate['rate'] === null ? '' : mitTsHours($rate['rate'], false, 'decimal') ?>" placeholder="<?= mitTsHours($bank['bank_rate'], false, 'decimal') ?> (bank's)" aria-label="Their rate">
                                        <span class="input-group-text">h</span>
                                    </div>
                                <?php } else { ?><span class="small text-muted">-</span><?php } ?>
                            </td>
                            <td class="text-center"><input class="form-check-input" type="checkbox" name="excluded[<?= intval($bank_id) ?>]" value="1" <?= $rate['excluded'] ? 'checked' : '' ?> aria-label="Leave them out of <?= escapeHtml($bank['bank_name']) ?>"></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <button type="submit" name="mit_leave_rates_save" class="btn btn-sm btn-primary"><i class="fas fa-check me-2"></i>Save rates</button>
            <span class="small text-muted ms-2">Their rate replaces the bank's amount (per pay period, per hours worked or per year). Empty = the bank's.</span>
        </form>

        <h6>Add or take off hours</h6>
        <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end mb-4">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="user_id" value="<?= $user_id ?>">
            <div class="col-sm-3">
                <label class="form-label small">Bank</label>
                <select class="form-select form-select-sm" name="bank_id">
                    <?php foreach ($banks as $bank_id => $bank) { ?>
                        <option value="<?= intval($bank_id) ?>"><?= escapeHtml($bank['bank_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label small">Hours</label>
                <input type="text" class="form-control form-control-sm" name="hours" placeholder="e.g. 16 or -4" required>
            </div>
            <div class="col-sm-3">
                <label class="form-label small">Date</label>
                <input type="date" class="form-control form-control-sm" name="date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-sm-4">
                <label class="form-label small">Why</label>
                <select class="form-select form-select-sm" name="kind">
                    <option value="adjust">Adjustment</option>
                    <option value="start">Starting balance</option>
                </select>
            </div>
            <div class="col-sm-9">
                <input type="text" class="form-control form-control-sm" name="note" maxlength="255" placeholder="Note (shown in their history)">
            </div>
            <div class="col-sm-3">
                <button type="submit" name="mit_leave_adjust" class="btn btn-sm btn-success w-100"><i class="fas fa-plus me-2"></i>Add</button>
            </div>
        </form>

        <h6>History</h6>
        <?php foreach ($banks as $bank_id => $bank) {
            $history = mitLeaveHistory($user_id, $bank, 15); ?>
            <div class="small fw-semibold mt-2"><?= escapeHtml($bank['bank_name']) ?></div>
            <?php if (!$history) { ?>
                <div class="small text-muted">Nothing yet.</div>
            <?php } else { ?>
                <table class="table table-sm small mb-1">
                    <?php foreach ($history as $row) { ?>
                        <tr>
                            <td class="text-nowrap" style="width: 110px"><?= escapeHtml(date('M j, Y', strtotime($row['entry_date']))) ?></td>
                            <td><?= escapeHtml($row['kind'] === 'used' ? 'Used' : (MIT_LEAVE_KINDS[$row['kind']] ?? $row['kind'])) ?><?= (string) $row['note'] !== '' ? ' <span class="text-muted">- ' . escapeHtml($row['note']) . '</span>' : '' ?></td>
                            <td class="text-end <?= $row['minutes'] < 0 ? 'text-danger' : 'text-success' ?>" style="width: 80px"><?= ($row['minutes'] > 0 ? '+' : '-') . mitTsHours(abs($row['minutes']), false, 'decimal') ?></td>
                            <td style="width: 30px">
                                <?php if (in_array($row['kind'], ['adjust', 'start'], true)) { ?>
                                    <a class="text-danger confirm-link" href="post.php?mit_leave_adjust_delete=<?= intval($row['ledger_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" title="Remove"><i class="fas fa-times"></i></a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </table>
            <?php } ?>
        <?php } ?>
    <?php } ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
</div>

<?php
require_once '../../../includes/modal_footer.php';
