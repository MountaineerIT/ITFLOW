<?php

/*
 * Mountaineer IT - everyone's timesheets for one pay period (functions/mit_timesheets.php): hours
 * by kind, billable share, status. Timesheets View (or being someone's approver) to see it; Full
 * access, or the person's approver, ticks and approves submitted ones. Anyone who has left but has
 * hours in the period is listed too. ?start= any date in the period.
 */

require_once "includes/inc_all.php";

mitModuleRequire('timesheets');

if (!mitTsReady()) {
    echo '<div class="alert alert-warning">Timesheets need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}
if (mitTsLevel() < 1 && !mitTsMyReports()) {
    enforceUserPermission('module_timesheets');
}

$settings = mitTsSettings();
$approval = $settings['approval'];
$overtime = $settings['ot_day'] || $settings['ot_week'];
$period = mitTsPeriodFromRequest($_GET['start'] ?? '');
$people = mitTsVisibleUsers($period);
$sheets = mitTsSheets(array_keys($people), $period);
$totals = mitTsTotals(array_keys($people), $period['start'], $period['end']);
$prev = mitTsPeriodShift($period, -1);
$next = mitTsPeriodShift($period, 1);
$sum = ['billable' => 0, 'other' => 0, 'work' => 0, 'lost' => 0, 'leave_paid' => 0, 'unpaid' => 0, 'worked' => 0, 'leave' => 0, 'total' => 0, 'overtime' => 0,
    'accounted' => 0, 'not_clocked' => 0, 'unallocated' => 0, 'break_paid' => 0];
foreach ($totals as $t) {
    foreach ($sum as $key => $value) {
        $sum[$key] += $t[$key];
    }
}
// The columns add up to Total: Admin & work also holds paid breaks and typed time on no code (California), and ticket / code
// time outside a California day's typed times is taken off as "Not clocked" (shown when anyone has some)
$not_clocked = $sum['not_clocked'] > 0;
$waiting = $approval ? count(array_filter($sheets, function ($sheet) {
    return $sheet['status'] === 'submitted';
})) : 0;
$can_approve = [];
foreach ($people as $user_id => $person) {
    $can_approve[$user_id] = $approval && mitTsMayApprove($user_id) && ($user_id !== intval($session_user_id) || mitTsLevel() >= 3);
}
$bulk = in_array(true, $can_approve, true);
// Behind on timesheets: ended periods not submitted (everyone the agent sees, not themselves)
$behind = mitTsBehind(array_diff(array_keys(mitTsVisibleUsers()), [intval($session_user_id)]));
$period_ended = $period['end'] < date('Y-m-d');

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-users me-2"></i>Timesheets</h3>
        <div class="btn-group">
            <a class="btn btn-light btn-sm" href="?start=<?= $prev['start'] ?>" title="Previous period"><i class="fas fa-chevron-left"></i></a>
            <a class="btn btn-light btn-sm fw-semibold" href="?start=<?= mitTsPeriodFor()['start'] ?>" title="This period"><?= escapeHtml($period['label']) ?></a>
            <a class="btn btn-light btn-sm" href="?start=<?= $next['start'] ?>" title="Next period"><i class="fas fa-chevron-right"></i></a>
        </div>
        <?php if (reportCan(mitTsReportEntry())) { ?>
            <a class="btn btn-outline-light btn-sm" href="reports/mit_payroll.php?period=custom&from=<?= $period['start'] ?>&to=<?= $period['end'] ?>"><i class="fas fa-file-invoice-dollar me-2"></i>Payroll report</a>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if ($behind) { ?>
            <div class="border border-warning-subtle bg-warning-subtle rounded p-3 mb-3" data-mit-ts-behind>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <strong><i class="fas fa-exclamation-triangle text-warning-emphasis me-2"></i>Behind on timesheets</strong>
                        <span class="small text-muted">Pay periods that have ended and not been submitted, in the last <?= MIT_TS_BEHIND_PERIODS ?></span>
                        <?php foreach (array_keys($behind) as $user_id) { ?><input type="hidden" name="remind_ids[]" value="<?= intval($user_id) ?>"><?php } ?>
                        <?php if (count($behind) > 1) { ?>
                            <button type="submit" name="mit_ts_remind" value="all" class="btn btn-sm btn-warning ms-auto"><i class="fas fa-bell me-2"></i>Remind all <?= count($behind) ?></button>
                        <?php } ?>
                    </div>
                    <div class="list-group">
                        <?php foreach ($behind as $user_id => $late) {
                            $user = mitTsUsers([$user_id])[$user_id] ?? ['user_name' => "user $user_id"]; ?>
                            <div class="list-group-item d-flex flex-wrap align-items-center gap-2 py-2">
                                <a class="fw-semibold" href="mit_timesheet.php?user_id=<?= intval($user_id) ?>&start=<?= $late['periods'][0]['start'] ?>"><?= escapeHtml($user['user_name']) ?></a>
                                <span class="small"><?= count($late['periods']) ?> period<?= count($late['periods']) === 1 ? '' : 's' ?>: <?= escapeHtml(implode(', ', array_column($late['periods'], 'label'))) ?></span>
                                <span class="small text-muted ms-auto"><?= $late['reminded_at'] !== '' ? 'Reminded ' . escapeHtml(timeAgo($late['reminded_at'])) : 'Not reminded yet' ?></span>
                                <button type="submit" name="mit_ts_remind" value="<?= intval($user_id) ?>" class="btn btn-sm btn-outline-dark"><i class="fas fa-bell me-1"></i>Remind</button>
                            </div>
                        <?php } ?>
                    </div>
                    <div class="small text-muted mt-2">A reminder is an app notification and an email. Automatic reminders are set in Admin &gt; Timesheets.</div>
                </form>
            </div>
        <?php } ?>
        <p class="text-muted"><?= count($people) ?> people · <?= mitTsHours($sum['total'], false) ?> hours · <?= mitTsBillableShare($sum) ?> of hours worked billable · <?= mitTsHours($sum['lost'], false) ?> lost<?= $not_clocked ? ' · <span class="text-warning-emphasis">' . mitTsHours($sum['not_clocked'], false) . ' ticket hours outside clocked times</span>' : '' ?><?= $waiting ? " · <strong>$waiting waiting for approval</strong>" : '' ?></p>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="start" value="<?= $period['start'] ?>">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap small">
                        <tr>
                            <?php if ($bulk) { ?><th style="width: 36px"></th><?php } ?>
                            <th>Person</th>
                            <th class="text-end">Billable</th>
                            <th class="text-end">Other tickets</th>
                            <th class="text-end" title="Hours on work codes, paid breaks, and clocked time not on a code">Admin &amp; work</th>
                            <th class="text-end" title="Lost time codes and unaccounted time">Lost</th>
                            <th class="text-end">Leave</th>
                            <?php if ($not_clocked) { ?><th class="text-end" title="California rules: ticket time and hours on codes outside the start / end times typed for the day (or on a day with none typed) - not paid until the times are put right" data-mit-ts-not-clocked-head>Not clocked</th><?php } ?>
                            <th class="text-end" title="Billable + Other tickets + Admin &amp; work + Lost + Leave, less Not clocked">Total</th>
                            <?php if ($overtime) { ?><th class="text-end">Overtime</th><?php } ?>
                            <th class="text-end">Billable %</th>
                            <?php if ($approval) { ?><th>Status</th><th></th><?php } ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($people as $user_id => $person) {
                            $t = $totals[$user_id];
                            $status = $sheets[$user_id]['status'] ?? 'open'; ?>
                            <tr>
                                <?php if ($bulk) { ?>
                                    <td><input class="form-check-input" type="checkbox" name="user_ids[]" value="<?= intval($user_id) ?>" <?= $status === 'submitted' && $can_approve[$user_id] ? '' : 'disabled' ?> aria-label="Approve <?= escapeHtml($person['user_name']) ?>"></td>
                                <?php } ?>
                                <td><a href="mit_timesheet.php?user_id=<?= intval($user_id) ?>&start=<?= $period['start'] ?>"><?= escapeHtml($person['user_name']) ?></a>
                                    <?php if (intval($person['user_status']) !== 1) { ?><span class="badge text-bg-secondary ms-1">Inactive</span><?php } ?></td>
                                <td class="text-end" data-mit-ts-col="billable"><?= mitTsHours($t['billable'], false) ?></td>
                                <td class="text-end" data-mit-ts-col="other"><?= mitTsHours($t['other'], false) ?></td>
                                <td class="text-end" data-mit-ts-col="work"><?= mitTsHours($t['work'] + $t['unallocated'] + $t['break_paid'], false) ?></td>
                                <td class="text-end <?= $t['lost'] ? 'text-danger' : '' ?>" data-mit-ts-col="lost"><?= mitTsHours($t['lost'], false) ?></td>
                                <td class="text-end" data-mit-ts-col="leave"><?= mitTsHours($t['leave'], false) ?></td>
                                <?php if ($not_clocked) { ?><td class="text-end <?= $t['not_clocked'] ? 'text-warning-emphasis fw-semibold' : '' ?>" data-mit-ts-col="not_clocked"><?= $t['not_clocked'] ? '&minus;' . mitTsHours($t['not_clocked'], false) : mitTsHours(0, false) ?></td><?php } ?>
                                <td class="text-end fw-semibold" data-mit-ts-col="total"><?= mitTsHours($t['total'], false) ?></td>
                                <?php if ($overtime) { ?><td class="text-end"><?= mitTsHours($t['overtime'], false) ?></td><?php } ?>
                                <td class="text-end" data-mit-ts-col="share"><?= mitTsBillableShare($t) ?></td>
                                <?php if ($approval) { ?>
                                    <td class="text-nowrap"><?= mitTsBadge($status) ?><?= $period_ended && in_array($status, ['open', 'returned'], true) && isset($behind[$user_id]) ? ' <span class="badge text-bg-danger">Late</span>' : '' ?></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($status === 'submitted' && $can_approve[$user_id]) { ?>
                                            <a href="#" class="btn btn-sm btn-outline-danger ajax-modal" data-modal-url="modals/mit_timesheet/decide.php?user_id=<?= intval($user_id) ?>&start=<?= $period['start'] ?>&decision=return"><i class="fas fa-undo me-1"></i>Send back</a>
                                        <?php } ?>
                                    </td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($bulk && $waiting) { ?>
                <div class="d-flex mt-3">
                    <button type="submit" name="mit_ts_bulk_approve" class="btn btn-success ms-auto"><i class="fas fa-check-double me-2"></i>Approve ticked</button>
                </div>
            <?php } ?>
        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";
