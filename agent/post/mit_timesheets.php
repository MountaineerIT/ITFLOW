<?php

/*
 * Mountaineer IT - timesheets (functions/mit_timesheets.php): save, submit, take back, approve or
 * send back, reopen, and approve several at once from the Everyone page.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

mitModuleGuard('timesheets', ['mit_ts_', 'mit_ca_']);

if (isset($_POST['mit_ts_save'])) {

    validateCSRFToken();

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!mitTsReady() || !mitTsMaySee($user_id) || !(mitTsUsers([$user_id])[$user_id] ?? null)) {
        flashAlert('That timesheet is not yours.', 'error');
        redirect();
    }
    $period = mitTsPeriodFromRequest($_POST['start'] ?? '');
    $sheet = mitTsSheet($user_id, $period);
    $locked = mitTsWhyLocked($sheet);
    if ($locked !== '') {
        flashAlert(escapeHtml($locked), 'error');
        redirect();
    }
    // California non-exempt (functions/mit_ts_california.php): the start, meal and end times and the meal answers first
    $ca = mitTsCaOn($user_id, $period['end']);
    if ($ca) {
        [$ok, $message, $ca_changed] = mitTsCaSave($sheet, $period, (array) ($_POST['ca_times'] ?? []), (array) ($_POST['ca_answers'] ?? []), $_POST['ca_reason'] ?? '');
        if (!$ok) {
            flashAlert(escapeHtml($message), 'error');
            redirect();
        }
    }
    [$ok, $message] = mitTsSaveCells($sheet, $period, (array) ($_POST['cells'] ?? []), (array) ($_POST['notes'] ?? []));
    if ($ok && $ca && $ca_changed) {
        $message = 'Timesheet saved';
    }
    if ($ok && $_POST['mit_ts_save'] === 'submit') {
        if ($user_id !== intval($session_user_id) && !mitTsMayCorrect($user_id)) {
            [$ok, $message] = [false, 'Only they can submit their timesheet.'];
        } else {
            if ($ca) {
                // They sign first - what they certify, their answer about breaks, their typed name
                [$ok, $message] = mitTsCaAttest(mitTsSheet($user_id, $period), $period, $_POST);
            }
            if ($ok && (!$ca || mitTsSettings()['approval'])) {
                [$ok, $message] = mitTsSubmit(mitTsSheet($user_id, $period), $period);
            }
        }
    }
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_GET['mit_ca_acknowledge'])) {

    validateCSRFToken();

    // The signed-in person acknowledges the changes others made to their times in a period
    $period = mitTsPeriodFromRequest($_GET['mit_ca_acknowledge']);
    $done = mitTsCaReady() ? mitTsCaAcknowledge($period) : 0;
    if ($done) {
        logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . " acknowledged $done change(s) to their times for " . $period['label']), 0, intval($session_user_id));
    }
    flashAlert($done ? 'Thanks - acknowledged' : 'Nothing to acknowledge');
    redirect();
}

if (isset($_GET['mit_ts_withdraw'])) {

    validateCSRFToken();

    [$sheet, $period] = mitTsSheetForRequest($_GET['mit_ts_withdraw']);
    if (intval($sheet['user_id']) !== intval($session_user_id)) {
        flashAlert('Only they can take their timesheet back.', 'error');
        redirect();
    }
    [$ok, $message] = mitTsWithdraw($sheet);
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_POST['mit_ts_decide'])) {

    validateCSRFToken();

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!mitTsSettings()['approval']) {
        flashAlert('Timesheets are not approved here (Admin > Timesheets).', 'error');
        redirect();
    }
    if (!(mitTsUsers([$user_id])[$user_id] ?? null) || !mitTsMayApprove($user_id) || ($user_id === intval($session_user_id) && mitTsLevel() < 3)) {
        flashAlert('You cannot approve that timesheet.', 'error');
        redirect();
    }
    $period = mitTsPeriodFromRequest($_POST['start'] ?? '');
    [$ok, $message] = mitTsDecide(mitTsSheet($user_id, $period), $period, ($_POST['decision'] ?? '') === 'return' ? 'return' : 'approve', $_POST['note'] ?? '');
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_GET['mit_ts_reopen'])) {

    validateCSRFToken();

    [$sheet, $period] = mitTsSheetForRequest($_GET['mit_ts_reopen']);
    if (!mitTsMayApprove($sheet['user_id']) || (intval($sheet['user_id']) === intval($session_user_id) && mitTsLevel() < 3)) {
        flashAlert('You cannot reopen that timesheet.', 'error');
        redirect();
    }
    [$ok, $message] = mitTsReopen($sheet, $period);
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

// Remind people who are behind on their timesheets (the team page): one, or everyone listed
if (isset($_POST['mit_ts_remind'])) {

    validateCSRFToken();

    $wanted = $_POST['mit_ts_remind'] === 'all' ? array_map('intval', (array) ($_POST['remind_ids'] ?? [])) : [intval($_POST['mit_ts_remind'])];
    // Only people this agent may see, and not themselves
    $wanted = array_values(array_filter($wanted, function ($user_id) use ($session_user_id) {
        return $user_id && $user_id !== intval($session_user_id) && mitTsMaySee($user_id);
    }));
    $behind = mitTsBehind($wanted);
    $sent = [];
    $skipped = [];
    foreach ($wanted as $user_id) {
        [$ok, $result] = mitTsRemind($user_id, $behind[$user_id] ?? []);
        if ($ok) {
            $sent[] = $result;
        } else {
            $skipped[] = $result;
        }
    }
    if ($sent) {
        flashAlert('Reminder sent to ' . escapeHtml(implode(', ', $sent)) . ($skipped ? ' (' . count($skipped) . ' not: ' . escapeHtml(implode(' ', array_unique($skipped))) . ')' : ''));
    } else {
        flashAlert(escapeHtml($skipped ? implode(' ', array_unique($skipped)) : 'Nobody to remind.'), 'error');
    }
    redirect();
}

if (isset($_POST['mit_ts_bulk_approve'])) {

    validateCSRFToken();

    $period = mitTsPeriodFromRequest($_POST['start'] ?? '');
    $people = mitTsSettings()['approval'] ? mitTsVisibleUsers($period) : [];
    $done = 0;
    foreach ((array) ($_POST['user_ids'] ?? []) as $user_id) {
        $user_id = intval($user_id);
        $sheet = mitTsSheet($user_id, $period);
        if (isset($people[$user_id]) && $sheet['status'] === 'submitted' && mitTsMayApprove($user_id) && ($user_id !== intval($session_user_id) || mitTsLevel() >= 3)) {
            [$ok] = mitTsDecide($sheet, $period, 'approve');
            $done += $ok ? 1 : 0;
        }
    }
    flashAlert($done ? "Approved $done timesheet" . ($done === 1 ? '' : 's') : 'Nothing to approve - only submitted timesheets can be approved here.', $done ? 'success' : 'error');
    redirect();
}
