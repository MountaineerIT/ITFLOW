<?php

/*
 * Mountaineer IT - when the work on a ticket note was done, for timesheets. Since 26.12 ITFlow keeps it itself
 * (ticket_replies.ticket_reply_work_start / _end on the company's clock, the reply box's When it was worked - built from ours; M067
 * moved our mit_reply_times there, out of each person's own clock). What stays ours:
 *  - timesheets read a note's work on the person's own clock (mitReplyPersonClock - their timesheet zone, with its history), so
 *    ticket time lands on the day they worked it (mitTsReplyWorkRows; California checks compare with their typed times);
 *  - a note's time can't go onto a day whose timesheet is submitted / approved / closed, nor today when today is on an approved one
 *    (mitReplyWorkGuardAdd - one guarded line after ITFlow's work-time block in add_ticket_reply; the time is kept, the times dropped,
 *    with a warning kept in ITFlow's "Reply added" message by mitReplyTimesFlash), and an edit can't move it onto or off such a day
 *    (mitReplyWorkGuardEdit - one guarded line before ITFlow's update in edit_ticket_reply);
 *  - the timesheet's day panel puts a note's times right (mitReplyTimesQuickSet, agent/mit_timesheet_ajax.php save_entry).
 * Notes without times (older ones, the API, bulk resolve, email) count as ending when posted.
 */

/** ITFlow keeps notes' work times (database 3.7.8). */
function mitReplyTimesReady() {
    return function_exists('ticketWorkTimesReady') && ticketWorkTimesReady();
}

/** A company-clock datetime as the person's own clock read it then (their timesheet zone in force - functions/mit_timesheets.php). */
function mitReplyPersonClock($user_id, $datetime, $anchor = null) {
    if (!$datetime || !function_exists('mitTsZoneAt')) {
        return $datetime;
    }
    // One zone for a whole entry: the one in force when its work started ($anchor) - an end converted with a later zone could fall before its start
    [$zone] = mitTsZoneAt($user_id, (string) ($anchor ?: $datetime));
    return $zone ? (new DateTime((string) $datetime))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s') : $datetime;
}

/**
 * A datetime on the person's own clock as the company's clock reads it - for storing in ITFlow's columns. Their zone now, or - for an
 * entry already stored ($anchor = its company-clock start) - the zone it is listed with (mitReplyPersonClock), so a fix to an older
 * entry stays where it was shown.
 */
function mitReplyCompanyClock($user_id, $datetime, $anchor = null) {
    if ($anchor && function_exists('mitTsZoneAt')) {
        [$zone] = mitTsZoneAt($user_id, (string) $anchor);
        $zone = (string) $zone;
    } else {
        $zone = function_exists('mitTsOwnZone') ? mitTsOwnZone($user_id) : '';
    }
    if (!$datetime || $zone === '') {
        return $datetime;
    }
    return (new DateTime((string) $datetime, new DateTimeZone($zone)))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}

/*
 * ITFlow's "When it was worked" on the agent's own clock (the user's choice, Nov 22 - as ours was before 26.12): ITFlow keeps and takes
 * the times on the company's clock (functions/ticket.php ticketWorkRange); these move them at the edges with ITFlow's own viewer zone
 * (functions/user_timezone.php - the Preferences zone, kept in step with the timesheet one by mitTsSaveZone). Stored times are unchanged.
 * Hooks, one line each: agent/ticket.php (the box's data-now, the note header, the hint), agent/post/ticket.php add / edit_ticket_reply,
 * agent/modals/ticket/ticket_reply_edit.php. Nothing happens for an agent on the company's clock.
 */

/** "Now" for the reply box (#workTimes data-now) on the agent's clock. */
function mitReplyViewNow() {
    $now = date('Y-m-d\TH:i:s');
    return function_exists('timezoneToView') ? timezoneToView($now) : $now;
}

/** Typed times (ticketWorkRange - read as the agent's clock) as the company's clock keeps them; null stays null. */
function mitReplyWorkFromView($work) {
    if (!is_array($work) || !function_exists('timezoneFromView')) {
        return $work;
    }
    return [timezoneFromView($work[0]), timezoneFromView($work[1])];
}

/** Stored (company) start and end on the viewing agent's clock: [start, end]. */
function mitReplyWorkToView($start, $end) {
    return function_exists('timezoneToView') ? [timezoneToView((string) $start), timezoneToView((string) $end)] : [$start, $end];
}

/** A short hint under the times for an agent away from the company's zone, else ''. */
function mitReplyViewZoneHint() {
    if (!function_exists('timezoneShifted') || !timezoneShifted()) {
        return '';
    }
    return '<div class="form-text" data-mit-reply-zone>Your time (' . escapeHtml(timezoneView()) . ').</div>';
}

/** A note's work times on its author's own clock: ['work_start', 'work_end'] or null when it has none. */
function mitReplyTimesOf($ticket_reply_id) {
    global $mysqli;
    if (!mitReplyTimesReady()) {
        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_work_start, ticket_reply_work_end FROM ticket_replies WHERE ticket_reply_id = " . intval($ticket_reply_id)));
    if (!$row || !$row['ticket_reply_work_start'] || !$row['ticket_reply_work_end']) {
        return null;
    }
    return ['work_start' => mitReplyPersonClock($row['ticket_reply_by'], $row['ticket_reply_work_start']),
        'work_end' => mitReplyPersonClock($row['ticket_reply_by'], $row['ticket_reply_work_end'], $row['ticket_reply_work_start'])];
}

/** The day a note counts on for its author's timesheet: the day its work started, else the day it was posted - both where they were. */
function mitReplyPersonDate($user_id, $work_start, $created_at) {
    if ($work_start) {
        return substr((string) mitReplyPersonClock($user_id, $work_start), 0, 10);
    }
    return function_exists('mitTsLocalDate') ? mitTsLocalDate($user_id, $created_at) : substr((string) $created_at, 0, 10);
}

/**
 * add_ticket_reply, after ITFlow's work-time block (one line): [time worked, $work_sql]. Today on the author's approved timesheet = no time
 * at all (its hours are stored - the time would be on no timesheet); work times on a submitted / approved / closed day (or one still to come)
 * are dropped with a warning and the note counts as worked now.
 */
function mitReplyWorkGuardAdd(array $post, $time_worked, $work_sql) {
    global $session_user_id;
    if (!function_exists('mitTsReady') || !mitTsReady() || mitReplyTimesSeconds($time_worked) <= 0) {
        return [$time_worked, $work_sql];
    }
    $now_approved = function () use ($session_user_id) {
        if (!function_exists('mitTsApprovedDates') || !function_exists('mitTsLocalDate')) {
            return false;
        }
        $today = mitTsLocalDate($session_user_id, date('Y-m-d H:i:s'));
        if (!mitTsApprovedDates($session_user_id, $today, $today)) {
            return false;
        }
        mitReplyTimesWarn('The time was not counted: today is on your approved timesheet - ask for it to be reopened, then add the time to the note.');
        return true;
    };
    if (!preg_match("/ticket_reply_work_start = '([0-9: -]{19})'/", (string) $work_sql, $match)) {
        return $now_approved() ? ['00:00:00', ''] : [$time_worked, $work_sql];
    }
    $date = substr((string) mitReplyPersonClock($session_user_id, $match[1]), 0, 10);
    $locked = mitReplyTimesLocked($session_user_id, $date);
    if ($locked) {
        if ($now_approved()) {
            return ['00:00:00', ''];
        }
        mitReplyTimesWarn('The times were not kept: ' . date('M j', strtotime($date)) . ' ' . mitReplyTimesLockedText($locked) . ', so the time counts as worked now.');
        return [$time_worked, ''];
    }
    return [$time_worked, $work_sql];
}

/** edit_ticket_reply, before ITFlow's update (one line): refused (message, back to the ticket) when the edit moves the note onto or off a locked day. */
function mitReplyWorkGuardEdit($ticket_reply_id, $work_sql) {
    global $mysqli;
    if (!function_exists('mitTsReady') || !mitTsReady() || !mitReplyTimesReady() || trim((string) $work_sql) === '') {
        return;
    }
    $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_created_at, ticket_reply_work_start FROM ticket_replies WHERE ticket_reply_id = " . intval($ticket_reply_id)));
    if (!$reply) {
        return;
    }
    $user_id = intval($reply['ticket_reply_by']);
    $old = mitReplyPersonDate($user_id, $reply['ticket_reply_work_start'], $reply['ticket_reply_created_at']);
    $new = preg_match("/ticket_reply_work_start = '([0-9: -]{19})'/", (string) $work_sql, $match)
        ? substr((string) mitReplyPersonClock($user_id, $match[1]), 0, 10) : mitReplyPersonDate($user_id, null, $reply['ticket_reply_created_at']);
    if ($old === $new) {
        return;
    }
    foreach ([$old => false, $new => true] as $date => $future) {
        $locked = mitReplyTimesLocked($user_id, $date, $future);
        if ($locked) {
            flashAlert('The note was not changed: ' . date('M j', strtotime($date)) . ' ' . escapeHtml(mitReplyTimesLockedText($locked)) . '.', 'error');
            redirect();
        }
    }
}

/** A date (Y-m-d) and HH:MM start / end -> ['Y-m-d H:i:s' start, end] (an end before the start runs past midnight), or null. */
function mitReplyTimesParse($date, $start, $end) {
    $date = (string) $date;
    $start = (string) $start;
    $end = (string) $end;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
        return null;
    }
    $from = strtotime("$date $start:00");
    $to = strtotime("$date $end:00");
    if ($to <= $from) {
        // The next calendar day (not 86400 seconds - an hour off on the nights the clocks change)
        $to = strtotime("$date $end:00 +1 day");
    }
    if ($to >= strtotime("$date $start:00 +1 day")) {
        return null;
    }
    return [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)];
}

/** "HH:MM:SS" -> seconds. */
function mitReplyTimesSeconds($time) {
    $parts = array_map('intval', explode(':', (string) $time) + [0, 0, 0]);
    return $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
}

/** Seconds -> "HH:MM:SS" (under a day). */
function mitReplyTimesClock($seconds) {
    $seconds = max(0, min(86399, intval($seconds)));
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}

/**
 * Can time go onto (or come off) $date for this person from a note? '' or why not (mitTsDayLock: 'submitted' / 'approved' /
 * 'closed'), or 'future' for a day still to come where they are - a note's time is work already done.
 */
function mitReplyTimesLocked($user_id, $date, $future = true) {
    if (!function_exists('mitTsReady') || !mitTsReady() || !mitModuleOn('timesheets') || !$date) {
        return '';
    }
    if ($future && $date > mitTsToday($user_id)) {
        return 'future';
    }
    return mitTsDayLock($user_id, $date);
}

/** Words for a reason from mitReplyTimesLocked(): "is on a submitted timesheet", "has not come yet" ... */
function mitReplyTimesLockedText($why) {
    return $why === 'future' ? 'has not come yet' : mitTsDayLockText($why);
}

/** A warning about the note's time, kept until ITFlow has said "Reply added" (mitReplyTimesFlash adds it to that message). */
function mitReplyTimesWarn($text) {
    $GLOBALS['mit_reply_times_warning'] = $text;
    flashAlert($text, 'warning'); // shown as it is if the note is not added after all
}

/**
 * Hook after ITFlow's own "Reply added" message in add_ticket_reply (agent/post/ticket.php): flashAlert keeps one message, so ITFlow's
 * would hide our warning about the time - both are shown, as a warning.
 */
function mitReplyTimesFlash() {
    if (!empty($GLOBALS['mit_reply_times_warning'])) {
        flashAlert(($_SESSION['alert_message'] ?? '') . '<br>' . escapeHtml($GLOBALS['mit_reply_times_warning']), 'warning');
        unset($GLOBALS['mit_reply_times_warning']);
    }
}

/**
 * Notes with time worked by these people that count on $from - $to: the day they were worked - from their times, else
 * the day they were posted where the person was (mitTsLocalDate). Each row: user_id, date, reply_id, ticket_id, reference,
 * subject, client_id, client_name, billable (1/0), seconds, start / end ('Y-m-d H:i:s', or null when the note has no times:
 * it is then taken as ending when posted - 'est' true).
 */
function mitTsReplyWorkRows(array $user_ids, $from, $to) {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    sort($user_ids);
    if (!$user_ids) {
        return [];
    }
    // Read once per request for the same people and dates (a save works the period out several times)
    return mitTsMemo('reply_rows', [$user_ids, $from, $to], function () use ($user_ids, $from, $to) {
        return mitTsReplyWorkRowsRead($user_ids, $from, $to);
    });
}

/** Has database update M043 made mit_reply_was_public? */
function mitReplyWasPublicReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_reply_was_public'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** SQL: a note counted as public ticket time - public, or made internal only because its ticket moved to another client (M043). */
function mitTsPublicNotesSql() {
    return mitReplyWasPublicReady()
        ? "(ticket_reply_type <> 'Internal' OR ticket_reply_id IN (SELECT reply_id FROM mit_reply_was_public))"
        : "ticket_reply_type <> 'Internal'";
}

/**
 * Hook just before a ticket moves to another client turns its public notes internal (agent/post/ticket.php change_client_ticket,
 * mitDispatchSaveDetails): remember which were public, so timesheets keep counting their time.
 */
function mitTsKeepPublicNotes($ticket_id) {
    global $mysqli;
    if (mitReplyWasPublicReady()) {
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_reply_was_public (reply_id) SELECT ticket_reply_id FROM ticket_replies
            WHERE ticket_reply_ticket_id = " . intval($ticket_id) . " AND ticket_reply_type = 'Public' AND ticket_reply_time_worked > '00:00:00'");
    }
}

/** mitTsReplyWorkRows() from the database - each note's times on its author's own clock. */
function mitTsReplyWorkRowsRead(array $user_ids, $from, $to) {
    global $mysqli;
    $settings = mitTsSettings();
    $billable = mitTsBillableSql($settings['billable']);
    $where = "ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00' AND ticket_reply_by IN (" . implode(',', $user_ids) . ")"
        . ($settings['ticket_internal'] ? '' : ' AND ' . mitTsPublicNotesSql());
    // Two days either side - people in other time zones - by when it was posted, or when it was worked
    $wide_from = escapeSql(date('Y-m-d', strtotime("$from -2 days")) . ' 00:00:00');
    $wide_to = escapeSql(date('Y-m-d', strtotime("$to +3 days")) . ' 00:00:00');
    $ready = mitReplyTimesReady();
    $columns = "ticket_reply_id, ticket_reply_by, ticket_reply_created_at, TIME_TO_SEC(ticket_reply_time_worked) AS seconds,
            IF($billable, 1, 0) AS billable, ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_client_id, client_name,
            " . ($ready ? 'ticket_reply_work_start AS work_start, ticket_reply_work_end AS work_end' : 'NULL AS work_start, NULL AS work_end');
    // Two parts, each on its own index: notes without times by when they were posted, notes with times by when they were worked
    $sql = "SELECT $columns, ticket_reply_created_at AS sort_at
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id LEFT JOIN clients ON client_id = ticket_client_id
        WHERE $where AND ticket_reply_created_at >= '$wide_from' AND ticket_reply_created_at < '$wide_to'" . ($ready ? ' AND ticket_reply_work_start IS NULL' : '');
    if ($ready) {
        $sql .= " UNION ALL SELECT $columns, ticket_reply_work_start AS sort_at
            FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id LEFT JOIN clients ON client_id = ticket_client_id
            WHERE $where AND ticket_reply_work_start >= '$wide_from' AND ticket_reply_work_start < '$wide_to'";
    }
    $result = mysqli_query($mysqli, "$sql ORDER BY sort_at, ticket_reply_id");
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $user_id = intval($row['ticket_reply_by']);
        $start = $row['work_start'] ? mitReplyPersonClock($user_id, $row['work_start']) : null;
        $end = $row['work_end'] ? mitReplyPersonClock($user_id, $row['work_end'], $row['work_start'] ?: null) : null;
        $date = $start ? substr($start, 0, 10) : mitTsLocalDate($user_id, $row['ticket_reply_created_at']);
        if ($date < $from || $date > $to) {
            continue;
        }
        $rows[] = [
            'user_id' => $user_id, 'date' => $date, 'reply_id' => intval($row['ticket_reply_id']), 'ticket_id' => intval($row['ticket_id']),
            'reference' => ticketReference($row), 'ticket_prefix' => $row['ticket_prefix'], 'ticket_number' => $row['ticket_number'], 'subject' => $row['ticket_subject'], 'client_id' => intval($row['ticket_client_id']),
            'client_name' => (string) $row['client_name'], 'billable' => intval($row['billable']), 'seconds' => intval($row['seconds']),
            'start' => $start, 'end' => $end, 'est' => !$start,
        ];
    }
    return $rows;
}

/**
 * A day's ticket entries for the day panel, in time order, each flagged: 'overlap' (another of the person's timed entries
 * runs at the same time) and 'outside' (a California day with clocked work times, and this entry is not inside them).
 * Notes without times have no flags. $periods: the day's clocked work periods (start / end datetimes), or [].
 */
function mitTsDayEntryFlags(array $rows, array $periods) {
    $out = [];
    foreach ($rows as $i => $row) {
        $row['overlap'] = false;
        $row['outside'] = false;
        if (!$row['est']) {
            foreach ($rows as $j => $other) {
                if ($i !== $j && !$other['est'] && $row['start'] < $other['end'] && $other['start'] < $row['end']) {
                    $row['overlap'] = true;
                    break;
                }
            }
            if ($periods) {
                $inside = false;
                foreach ($periods as $period) {
                    if ($row['start'] >= $period['start'] && $row['end'] <= $period['end']) {
                        $inside = true;
                        break;
                    }
                }
                $row['outside'] = !$inside;
            }
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Can the signed-in agent change this person's ticket entries on $date from the timesheet's day panel? '' or why not:
 * Support edit, a timesheet they may change that day (mitTsWhyDayLocked - their own while open, someone else's when they
 * correct it) and a day that no submitted / approved / closed timesheet holds (mitReplyTimesLocked, as on the ticket).
 */
function mitReplyTimesEntryLock($user_id, $date) {
    if (!mitReplyTimesReady() || lookupUserPermission('module_support') < 2) {
        return 'Changing ticket time takes Support edit access.';
    }
    $why = mitTsWhyDayLocked($user_id, $date);
    if ($why !== '') {
        return $why;
    }
    $locked = mitReplyTimesLocked($user_id, $date);
    return $locked ? date('M j', strtotime($date)) . ' ' . mitReplyTimesLockedText($locked) . '.' : '';
}

/**
 * The timesheet's quick edit of a ticket entry (agent/mit_timesheet_ajax.php save_entry): a note's start and end on the day
 * it counts on ($date, the day it is listed under - an end before the start runs past midnight). The length follows the
 * times, as when the note is edited on the ticket. Same rules as ITFlow's Edit reply (Support edit, the ticket's client)
 * plus mitReplyTimesEntryLock. Returns '' or why it was not changed.
 */
function mitReplyTimesQuickSet($ticket_reply_id, $user_id, $date, $start, $end) {
    global $mysqli, $session_user_id;
    $ticket_reply_id = intval($ticket_reply_id);
    $user_id = intval($user_id);
    $why = mitReplyTimesEntryLock($user_id, $date);
    if ($why !== '') {
        return $why;
    }
    $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_type, ticket_reply_created_at, ticket_reply_time_worked, ticket_reply_archived_at, ticket_reply_work_start,
        ticket_id, ticket_prefix, ticket_number, ticket_client_id FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id WHERE ticket_reply_id = $ticket_reply_id"));
    if (!$reply || intval($reply['ticket_reply_by']) !== $user_id || $reply['ticket_reply_archived_at'] !== null || $reply['ticket_reply_type'] === 'Client'
        || mitReplyTimesSeconds($reply['ticket_reply_time_worked']) <= 0) {
        return 'That ticket entry is not on this timesheet any more - reload the page.';
    }
    if (intval($reply['ticket_client_id']) && !hasClientAccess($reply['ticket_client_id'])) {
        return 'That ticket is for a client you cannot see.';
    }
    // It stays on the day it is listed under
    $old = mitReplyTimesOf($ticket_reply_id);
    $old_date = $old ? substr($old['work_start'], 0, 10) : mitTsLocalDate($user_id, $reply['ticket_reply_created_at']);
    if ($old_date !== $date) {
        return 'That ticket entry is not on this day any more - reload the page.';
    }
    $range = mitReplyTimesParse($date, $start, $end);
    if (!$range) {
        return 'Type a start and an end (under 24 hours apart).';
    }
    if ($old && substr($old['work_start'], 0, 16) === substr($range[0], 0, 16) && substr($old['work_end'], 0, 16) === substr($range[1], 0, 16)) {
        return '';
    }
    $length = strtotime($range[1]) - strtotime($range[0]);
    $time_worked = abs($length - mitReplyTimesSeconds($reply['ticket_reply_time_worked'])) < 60 ? $reply['ticket_reply_time_worked'] : mitReplyTimesClock($length);
    // Kept on the company's clock in ITFlow's columns
    // with the zone the entry is listed with (its old start, else when it was posted), not the person's zone now
    $anchor = $reply['ticket_reply_work_start'] ?: $reply['ticket_reply_created_at'];
    $store = [mitReplyCompanyClock($user_id, $range[0], $anchor), mitReplyCompanyClock($user_id, $range[1], $anchor)];
    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_time_worked = '" . escapeSql($time_worked) . "', ticket_reply_work_start = '" . escapeSql($store[0]) . "',
        ticket_reply_work_end = '" . escapeSql($store[1]) . "' WHERE ticket_reply_id = $ticket_reply_id");

    $period = mitTsPeriodFor($date);
    $words = substr($range[0], 11, 5) . ' - ' . substr($range[1], 11, 5) . ' on ' . $date;
    logAudit('Ticket', 'Reply', escapeSql(mitTsWho() . ' set the time of a reply on ticket ' . ticketReference($reply) . " to $words from the timesheet"
        . ($old ? ' (was ' . substr($old['work_start'], 11, 5) . ' - ' . substr($old['work_end'], 11, 5) . ')' : '')), intval($reply['ticket_client_id']), $ticket_reply_id);
    if (function_exists('mitTsCaChangedAfterSigning')) {
        mitTsCaChangedAfterSigning(mitTsSheet($user_id, $period), $period);
    }
    if ($user_id !== intval($session_user_id) && intval($session_user_id)) {
        // The person is told, once an hour while someone works on their sheet (as for hours on codes)
        $text = mitTsWho() . ' changed hours on your timesheet for ' . $period['label'];
        $recent = mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM notifications WHERE notification_user_id = $user_id AND notification_type = 'Timesheet'
            AND notification = '" . escapeSql($text) . "' AND notification_timestamp > NOW() - INTERVAL 1 HOUR LIMIT 1"));
        if (!$recent) {
            mitTsNotify($user_id, $text, mitTsLink($user_id, $period), intval(mitTsSheet($user_id, $period)['sheet_id'] ?? 0));
        }
    }
    return '';
}
