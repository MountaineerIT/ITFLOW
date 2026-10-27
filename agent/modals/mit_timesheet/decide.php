<?php

/*
 * Mountaineer IT - approve a timesheet, or send it back with a reason (functions/mit_timesheets.php).
 */

require_once '../../../includes/modal_header.php';

mitModuleRequire('timesheets', true);

$user_id = intval($_GET['user_id'] ?? 0);
if (!mitTsReady() || !mitTsSettings()['approval']) {
    exit(json_encode(['error' => 'Timesheets are not approved here (Admin > Timesheets).']));
}
if (!mitTsMayApprove($user_id) || ($user_id === intval($session_user_id) && mitTsLevel() < 3)) {
    exit(json_encode(['error' => 'You cannot approve that timesheet.']));
}
$period = mitTsPeriodFromRequest($_GET['start'] ?? '');
$decision = ($_GET['decision'] ?? '') === 'return' ? 'return' : 'approve';
$person = mitTsUsers([$user_id])[$user_id] ?? null;
if (!$person) {
    exit(json_encode(['error' => 'That person does not keep a timesheet.']));
}
$sheet = mitTsSheet($user_id, $period);
$t = mitTsTotals([$user_id], $period['start'], $period['end'])[$user_id];

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw <?= $decision === 'approve' ? 'fa-check' : 'fa-undo' ?> me-2"></i><?= $decision === 'approve' ? 'Approve' : 'Send back' ?> <?= escapeHtml($person['user_name']) ?>'s timesheet</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="user_id" value="<?= $user_id ?>">
    <input type="hidden" name="start" value="<?= $period['start'] ?>">
    <input type="hidden" name="decision" value="<?= $decision ?>">
    <div class="modal-body">
        <p class="mb-2"><?= escapeHtml($period['label']) ?> · <?= mitTsBadge($sheet['status']) ?></p>
        <p class="small text-muted">
            <?= mitTsHours($t['total'], false) ?> hours: <?= mitTsHours($t['billable'], false) ?> billable, <?= mitTsHours($t['other'], false) ?> other ticket time,
            <?= mitTsHours($t['work'], false) ?> admin &amp; other work, <?= mitTsHours($t['lost'], false) ?> lost, <?= mitTsHours($t['leave_paid'], false) ?> paid leave<?= $t['unpaid'] ? ', ' . mitTsHours($t['unpaid']) . ' unpaid (leave and codes' . ($t['auto_lost'] && !mitTsSettings()['auto_lost_paid'] ? ', unaccounted time' : '') . ')' : '' ?><?= $t['break_paid'] ? ', ' . mitTsHours($t['break_paid']) . ' paid breaks' : '' ?><?= $t['overtime'] ? ', ' . mitTsHours($t['overtime']) . ' overtime' : '' ?>.
        </p>
        <?= mitTsCaApproverNote($user_id, $period, $sheet) // Mountaineer IT: California non-exempt - signature, premiums ?>
        <?php if ($decision === 'approve' && $sheet['status'] !== 'submitted') { ?>
            <div class="alert alert-warning py-2 small">It has not been submitted - approving locks it as it is now.</div>
        <?php } ?>
        <label class="form-label"><?= $decision === 'approve' ? 'Note (optional)' : 'Why - they see this on the timesheet' ?></label>
        <input type="text" class="form-control" name="note" maxlength="255" <?= $decision === 'return' ? 'required' : '' ?>>
        <?php if ($decision === 'approve') { ?>
            <div class="form-text">Approving locks the period and keeps its ticket time, unaccounted time and paid breaks as they are now.</div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_ts_decide" class="btn <?= $decision === 'approve' ? 'btn-success' : 'btn-danger' ?> text-bold"><i class="fas fa-check me-2"></i><?= $decision === 'approve' ? 'Approve' : 'Send back' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
