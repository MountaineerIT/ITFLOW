<?php

/*
 * Mountaineer IT - add or change a holiday (admin/mit_timesheets.php?tab=holidays, functions/mit_ts_holidays.php).
 */

require_once '../../includes/modal_header.php';

$holiday_id = intval($_GET['id'] ?? 0);
$holiday = $holiday_id ? mitTsHoliday($holiday_id) : null;
if ($holiday_id && !$holiday) {
    exit(json_encode(['error' => 'That holiday no longer exists.']));
}
$year = intval($_GET['year'] ?? date('Y'));

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-calendar-day me-2"></i><?= $holiday_id ? 'Holiday' : 'New holiday' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="holiday_id" value="<?= $holiday_id ?>">
    <div class="modal-body">
        <div class="row g-3">
            <div class="col-sm-5">
                <label class="form-label">Date <strong class="text-danger">*</strong></label>
                <input type="date" class="form-control" name="holiday_date" value="<?= escapeHtml($holiday['holiday_date'] ?? '') ?>" required <?= $holiday ? '' : 'autofocus' ?>
                    <?= $holiday ? '' : 'min="' . $year . '-01-01" max="' . $year . '-12-31"' ?>>
            </div>
            <div class="col-sm-7">
                <label class="form-label">Name <strong class="text-danger">*</strong></label>
                <input type="text" class="form-control" name="holiday_name" maxlength="200" value="<?= escapeHtml($holiday['holiday_name'] ?? '') ?>" placeholder="e.g. Christmas Eve, Office closed" required>
            </div>
        </div>
        <div class="form-text mt-2">The office is closed: SLA clocks do not run, and timesheets fill the day in with each person's normal hours.
            <?= $holiday ? 'Hours already filled in on open timesheets move with it.' : '' ?></div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_ts_holiday_save" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i><?= $holiday_id ? 'Save' : 'Add' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
