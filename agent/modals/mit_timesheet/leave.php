<?php

/*
 * Mountaineer IT - a leave bank's history for a person (functions/mit_leave.php): earned, granted,
 * carried over, adjusted and used, newest first.
 */

require_once '../../../includes/modal_header.php';

mitModuleRequire('timesheets', true);

$user_id = intval($_GET['user_id'] ?? 0);
$bank = mitLeaveBanks(true)[intval($_GET['bank_id'] ?? 0)] ?? null;
if (!mitTsReady() || !$bank || !mitTsMaySee($user_id)) {
    exit(json_encode(['error' => 'You cannot see that.']));
}
$person = mitTsUsers([$user_id])[$user_id] ?? ['user_name' => ''];
$balance = mitLeaveBalance($user_id, $bank);
$history = mitLeaveHistory($user_id, $bank);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-umbrella-beach me-2"></i><?= escapeHtml($bank['bank_name']) ?> <small class="opacity-75 ms-1"><?= escapeHtml($person['user_name']) ?></small></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <p class="mb-2">
        <strong><?= mitTsHours($balance['now'], false) ?></strong> hours today<?= $balance['used_ahead'] ? ', <strong>' . mitTsHours($balance['booked'], false) . '</strong> after ' . mitTsHours($balance['used_ahead'], false) . ' booked ahead' : '' ?>.
        <span class="text-muted small d-block"><?= escapeHtml(mitLeaveRuleText($bank, mitLeaveRate($user_id, $bank['bank_id'])['rate'])) ?><?= $bank['bank_max'] !== null ? ' · at most ' . mitTsHours($bank['bank_max'], false) . ' h' : '' ?></span>
    </p>
    <?php if (!$history) { ?>
        <div class="text-muted">Nothing yet.</div>
    <?php } else { ?>
        <table class="table table-sm align-middle mb-0">
            <thead class="small text-muted"><tr><th>Date</th><th>What</th><th class="text-end">Hours</th></tr></thead>
            <tbody>
                <?php foreach ($history as $row) { ?>
                    <tr>
                        <td class="text-nowrap"><?= escapeHtml(date('M j, Y', strtotime($row['entry_date']))) ?></td>
                        <td>
                            <?= escapeHtml($row['kind'] === 'used' ? 'Used' : (MIT_LEAVE_KINDS[$row['kind']] ?? $row['kind'])) ?>
                            <?php if ((string) $row['note'] !== '') { ?><span class="text-muted small"> - <?= escapeHtml($row['note']) ?></span><?php } ?>
                            <?php if (!empty($row['created_by_name']) && in_array($row['kind'], ['adjust', 'start'], true)) { ?><span class="text-muted small"> (<?= escapeHtml($row['created_by_name']) ?>)</span><?php } ?>
                        </td>
                        <td class="text-end <?= $row['minutes'] < 0 ? 'text-danger' : 'text-success' ?>"><?= ($row['minutes'] > 0 ? '+' : '-') . mitTsHours(abs($row['minutes']), false) ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
</div>

<?php
require_once '../../../includes/modal_footer.php';
