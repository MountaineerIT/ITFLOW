<?php

/*
 * Mountaineer IT - one person's timesheet details (admin/mit_timesheets.php?tab=people): normal week,
 * approver, employee ID, pay type and hire date.
 */

require_once '../../includes/modal_header.php';

$user_id = intval($_GET['id'] ?? 0);
$people = mitTsUsers([$user_id], true);
$person = $people[$user_id] ?? null;
if (!$person) {
    exit(json_encode(['error' => 'That person does not keep a timesheet.']));
}
$details = mitTsPerson($user_id);
$week = $details['schedule'] === '' ? null : mitTsSchedule($user_id);
$company = mitTsCompanySchedule();

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-clock me-2"></i><?= escapeHtml($person['user_name']) ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="user_id" value="<?= $user_id ?>">
    <div class="modal-body">
        <?php if (mitTsKeepsColumn()) { ?>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" name="keeps_timesheet" value="1" id="mitTsKeeps" <?= mitTsKeepsTimesheet($user_id) ? 'checked' : '' ?>>
                <label class="form-check-label" for="mitTsKeeps">Keeps a timesheet</label>
                <div class="form-text">Off for people who don't (owners, contractors): no timesheet, reminders or leave earned, and they are left off the Everyone page.</div>
            </div>
        <?php } ?>
        <label class="form-label">Normal week <span class="text-muted small">(hours a day)</span></label>
        <div class="d-flex flex-wrap gap-2 mb-1">
            <?php foreach (MIT_TS_WEEKDAYS as $n => $day) { ?>
                <div style="width: 58px">
                    <div class="small text-muted text-center"><?= $day ?></div>
                    <input type="text" inputmode="decimal" class="form-control form-control-sm text-center" name="schedule[<?= $n ?>]" aria-label="<?= $day ?>"
                        value="<?= $week === null ? '' : mitTsHours($week[$n], false, 'decimal') ?>" placeholder="<?= mitTsHours($company[$n], false, 'decimal') ?>">
                </div>
            <?php } ?>
        </div>
        <div class="form-text mb-3">All empty = the company's week (shown faintly). Part-time? e.g. 6 a day, or 0 on days off.</div>

        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label">Approves their timesheets</label>
                <select class="form-select" name="approver_id">
                    <option value="0">Anyone with Full Timesheets access</option>
                    <?php foreach (mitTsUsers([], true) as $uid => $user) { // any active agent - a manager may keep no timesheet
                        if ($uid === $user_id) {
                            continue;
                        } ?>
                        <option value="<?= intval($uid) ?>" <?= $uid === $details['approver_id'] ? 'selected' : '' ?>><?= escapeHtml($user['user_name']) ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Their approver can also see and correct their timesheets.</div>
            </div>
            <div class="col-sm-6">
                <label class="form-label">Employee ID <span class="text-muted small">(payroll)</span></label>
                <input type="text" class="form-control" name="employee_id" maxlength="50" value="<?= escapeHtml($details['employee_id']) ?>">
            </div>
            <div class="col-sm-6">
                <label class="form-label">Hire date <span class="text-muted small">(leave waiting periods, anniversaries)</span></label>
                <input type="date" class="form-control" name="hire_date" value="<?= escapeHtml(mitLeaveHireDate($user_id)) ?>">
            </div>
            <div class="col-sm-6">
                <label class="form-label">Employment</label>
                <div class="form-control-plaintext py-1"><strong data-mit-pay-type="<?= escapeHtml($details['pay_type']) ?>"><?= escapeHtml(MIT_TS_PAY_TYPE_SHORT[$details['pay_type']]) ?></strong></div>
                <div class="form-text">Set in <a href="users.php">Admin &gt; Users</a> (Edit user).</div>
            </div>
            <?php if (mitTsZoneColumn()) { ?>
                <div class="col-12">
                    <label class="form-label">Time zone</label>
                    <?= mitTsZoneSelect('time_zone', mitTsOwnZone($user_id)) ?>
                    <div class="form-text">For someone working remotely: their days, "today" and the day their ticket time falls on follow their clock. A change counts from now (or after their last submitted or approved timesheet) and is logged. They can also set it in Account > Preferences.</div>
                </div>
            <?php } ?>
            <?php if (mitTsCaReady()) { ?>
                <div class="col-12">
                    <label class="form-label">California non-exempt rules</label>
                    <select class="form-select" name="ca_rules">
                        <option value="" <?= $details['ca_rules'] === null ? 'selected' : '' ?>>By pay type (on for hourly, off for salaried)</option>
                        <option value="1" <?= $details['ca_rules'] === 1 ? 'selected' : '' ?>>On</option>
                        <option value="0" <?= $details['ca_rules'] === 0 ? 'selected' : '' ?>>Off</option>
                    </select>
                    <div class="form-text">On: they type start, meal and end times each day, answer for missed meal periods, sign their timesheet, and get California overtime, double time and premium pay. Contractors are never under these rules.</div>
                </div>
            <?php } ?>

        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_ts_person_save" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
