<?php

/*
 * Mountaineer IT - add or change a leave bank (admin/mit_timesheets.php?tab=leave, functions/mit_leave.php).
 */

require_once '../../includes/modal_header.php';

$bank_id = intval($_GET['id'] ?? 0);
$bank = $bank_id ? (mitLeaveBanks(true)[$bank_id] ?? null) : null;
if (!mitLeaveReady() || ($bank_id && !$bank)) {
    exit(json_encode(['error' => 'That leave bank does not exist.']));
}
$bank = $bank ?: ['bank_name' => '', 'codes' => [], 'bank_method' => 'none', 'bank_rate' => 0, 'bank_per_worked' => 0, 'bank_max' => null,
    'bank_carryover_max' => null, 'bank_year_start' => 'jan1', 'bank_waiting_days' => 0, 'bank_allow_negative' => 0];
$h = function ($minutes) {
    return $minutes === null || !intval($minutes) ? '' : mitTsHours($minutes, false, 'decimal');
};
$carry = $bank['bank_carryover_max'] === null ? 'all' : (intval($bank['bank_carryover_max']) ? 'cap' : 'none');

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-umbrella-beach me-2"></i><?= $bank_id ? escapeHtml($bank['bank_name']) : 'New leave bank' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off" data-mit-leave-bank-form>
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="bank_id" value="<?= $bank_id ?>">
    <div class="modal-body">
        <div class="mb-3">
            <label class="form-label">Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="100" value="<?= escapeHtml($bank['bank_name']) ?>" placeholder="e.g. PTO, Sick" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Used up by hours on</label>
            <div class="d-flex flex-wrap gap-3">
                <?php foreach (mitTsCodes() as $code_id => $code) { ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="codes[]" value="<?= intval($code_id) ?>" id="mitBankCode<?= intval($code_id) ?>" <?= in_array(intval($code_id), $bank['codes'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mitBankCode<?= intval($code_id) ?>"><?= mitTsCodeDot($code) ?><?= escapeHtml($code['code_name']) ?></label>
                    </div>
                <?php } ?>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-5">
                <label class="form-label">Earned</label>
                <select class="form-select" name="method" data-mit-bank-method>
                    <?php foreach (MIT_LEAVE_METHODS as $key => $label) { ?>
                        <option value="<?= $key ?>" <?= $key === $bank['bank_method'] ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-sm-7" data-mit-bank-show="period worked yearly">
                <label class="form-label">How much</label>
                <div class="input-group">
                    <input type="text" inputmode="decimal" class="form-control" name="rate" value="<?= $h($bank['bank_rate']) ?>" placeholder="0" aria-label="Hours">
                    <span class="input-group-text" data-mit-bank-show="period">h each pay period</span>
                    <span class="input-group-text" data-mit-bank-show="yearly">h each year</span>
                    <span class="input-group-text" data-mit-bank-show="worked">h for every</span>
                    <input type="text" inputmode="decimal" class="form-control" name="per_worked" value="<?= $h($bank['bank_per_worked']) ?>" placeholder="30" aria-label="Hours worked" data-mit-bank-show="worked">
                    <span class="input-group-text" data-mit-bank-show="worked">h worked</span>
                </div>
                <div class="form-text" data-mit-bank-show="period">e.g. 3.33 semi-monthly is 80 h a year.</div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-sm-4">
                <label class="form-label">Most it can hold</label>
                <div class="input-group">
                    <input type="text" inputmode="decimal" class="form-control" name="max" value="<?= $h($bank['bank_max']) ?>" placeholder="no limit">
                    <span class="input-group-text">h</span>
                </div>
            </div>
            <div class="col-sm-4">
                <label class="form-label">At year end</label>
                <select class="form-select" name="carryover" data-mit-bank-carry>
                    <option value="all" <?= $carry === 'all' ? 'selected' : '' ?>>Keep everything</option>
                    <option value="cap" <?= $carry === 'cap' ? 'selected' : '' ?>>Keep up to ...</option>
                    <option value="none" <?= $carry === 'none' ? 'selected' : '' ?>>Use it or lose it</option>
                </select>
            </div>
            <div class="col-sm-4" data-mit-bank-cap>
                <label class="form-label">Keep up to</label>
                <div class="input-group">
                    <input type="text" inputmode="decimal" class="form-control" name="carryover_max" value="<?= $carry === 'cap' ? $h($bank['bank_carryover_max']) : '' ?>" placeholder="40">
                    <span class="input-group-text">h</span>
                </div>
            </div>
            <div class="col-sm-4">
                <label class="form-label">The leave year starts</label>
                <select class="form-select" name="year_start">
                    <option value="jan1" <?= $bank['bank_year_start'] !== 'hire' ? 'selected' : '' ?>>1 January</option>
                    <option value="hire" <?= $bank['bank_year_start'] === 'hire' ? 'selected' : '' ?>>Each person's hire anniversary</option>
                </select>
                <div class="form-text">For year end and yearly grants.</div>
            </div>
            <div class="col-sm-4">
                <label class="form-label">Waiting period</label>
                <div class="input-group">
                    <input type="number" min="0" max="3650" class="form-control" name="waiting_days" value="<?= intval($bank['bank_waiting_days']) ?>">
                    <span class="input-group-text">days</span>
                </div>
                <div class="form-text">After the hire date (People tab) before it is earned.</div>
            </div>
            <div class="col-sm-4">
                <div class="form-check mt-sm-4">
                    <input class="form-check-input" type="checkbox" name="allow_negative" value="1" id="mitBankNegative" <?= intval($bank['bank_allow_negative']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitBankNegative">Can go below 0</label>
                </div>
                <div class="form-text">Off: hours that would take it below 0 are not saved.</div>
            </div>
        </div>
        <div class="small text-muted">Pay period earnings are added when the period is approved (or when it ends, if timesheets are not approved). Year end and yearly grants run with the Timesheets cron job.</div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_leave_bank_save" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i><?= $bank_id ? 'Save' : 'Create' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
    (function (form) {
        const method = form.querySelector('[data-mit-bank-method]');
        const carry = form.querySelector('[data-mit-bank-carry]');
        function show() {
            form.querySelectorAll('[data-mit-bank-show]').forEach(function (el) {
                el.classList.toggle('d-none', !el.dataset.mitBankShow.split(' ').includes(method.value));
            });
            form.querySelector('[data-mit-bank-cap]').classList.toggle('d-none', carry.value !== 'cap');
        }
        method.addEventListener('change', show);
        carry.addEventListener('change', show);
        show();
    })(document.currentScript.previousElementSibling);
</script>

<?php
require_once '../../../includes/modal_footer.php';
