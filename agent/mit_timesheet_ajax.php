<?php

/*
 * Mountaineer IT - the timesheet page's saves (agent/mit_timesheet.php, agent/js/mit_timesheet.js), JSON:
 *   POST save_day=1 user_id start date [times[blocks][i][start|end|gap]] [answers[meal1|meal2|rest_missed]] cells[code_id] notes[code_id] [reason]
 *        saves one day as it is typed - the same checks as the timesheet form (mitTsCaSave, mitTsSaveCells) - and
 *        returns the day again, the summary, leave left and what still stops it being submitted.
 *   POST save_entry=1 user_id start date reply_id work_start work_end
 *        a ticket entry's times put right from the day panel (mitReplyTimesQuickSet - the note's own time changes too),
 *        returning the same as save_day.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');

$mit_fail = function ($message, $code = 400, array $extra = []) {
    http_response_code($code);
    echo json_encode(['error' => $message] + $extra);
    exit;
};

if (!mitTsOn()) {
    $mit_fail('Timesheets are switched off (Admin > Settings > Modules).', 403);
}

if (isset($_POST['save_day']) || isset($_POST['save_entry'])) {

    validateCSRFToken();

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!mitTsMaySee($user_id) || !(mitTsUsers([$user_id])[$user_id] ?? null)) {
        $mit_fail('That timesheet is not yours.', 403);
    }
    $period = mitTsPeriodFromRequest($_POST['start'] ?? '');
    $date = (string) ($_POST['date'] ?? '');
    if (!in_array($date, mitTsDates($period), true)) {
        $mit_fail('That day is not in this pay period.');
    }
    $sheet = mitTsSheet($user_id, $period);
    $locked = mitTsWhyLocked($sheet);
    if ($locked !== '') {
        $mit_fail($locked, 403);
    }

    $reply = function ($ok, $message) use ($user_id, $period, $date) {
        $ca = mitTsCaOn($user_id, $period['end']);
        echo json_encode([
            'ok' => $ok, 'message' => $message,
            'day' => mitTsViewDays($user_id, $period, [$date])[$date] ?? null,
            'summary' => mitTsViewSummary($user_id, $period),
            'leave' => mitLeaveTimesheetBox($user_id, $period),
            'problems' => $ca ? mitTsCaProblems($user_id, $period) : [],
            'changes' => $ca ? mitTsCaChangesBox($user_id, $period, $user_id === intval($GLOBALS['session_user_id'])) : '',
        ]);
        exit;
    };

    if (isset($_POST['save_entry'])) {
        $why = mitReplyTimesQuickSet($_POST['reply_id'] ?? 0, $user_id, $date, $_POST['work_start'] ?? '', $_POST['work_end'] ?? '');
        $reply($why === '', $why === '' ? 'Ticket time saved' : $why);
    }

    // Under the timesheet's lock (as submit / approve take it), and checked again inside: a save can't land after an approval
    $lock = 'ts_sheet_' . $user_id . '_' . $period['start'];
    if (!mitLock($lock, 10)) {
        $reply(false, 'The timesheet is being submitted or approved right now - try again in a moment.');
    }
    if (($locked = mitTsWhyLocked(mitTsSheet($user_id, $period))) !== '') {
        mitUnlock($lock);
        $mit_fail($locked, 403);
    }
    register_shutdown_function('mitUnlock', $lock);
    if (mitTsCaOn($user_id, $period['end']) && isset($_POST['times'])) {
        [$ok, $message] = mitTsCaSave($sheet, $period, [$date => (array) $_POST['times']], [$date => (array) ($_POST['answers'] ?? [])], $_POST['reason'] ?? '');
        if (!$ok) {
            $reply(false, $message);
        }
    }
    // Not on California rules: their own "could not take a rest break" answer for the day (a record - no premium)
    if (!mitTsCaOn($user_id, $period['end']) && isset($_POST['answers']['rest_missed']) && function_exists('mitTsRestMissedSave')) {
        mitTsRestMissedSave($user_id, $date, !empty($_POST['answers']['rest_missed']));
    }
    [$ok, $message] = mitTsSaveCells(mitTsSheet($user_id, $period), $period, [$date => (array) ($_POST['cells'] ?? [])], [$date => (array) ($_POST['notes'] ?? [])]);
    $reply($ok, $ok ? 'Saved' : $message);
}

$mit_fail('Nothing asked.');
