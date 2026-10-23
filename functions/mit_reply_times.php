<?php

/*
 * Mountaineer IT - times on ticket notes: when the work on a note with time worked was done - date, start and end, as the
 * person's clock read them (stored as typed, like the California work times) - in mit_reply_times (M028). The reply box
 * asks for them whenever time is logged (agent/js/mit_reply_times.js keeps them in step with ITFlow's timer: end = now,
 * start = end less the timer, until they are typed; typed times set the length). Edit reply has them too.
 * A note without a row (from before, the API, bulk resolve, email) counts as ending when it was posted.
 * Timesheets count ticket time on the day it was worked (mitTsReplyWorkRows in functions/mit_timesheets.php).
 * Hooks: agent/ticket.php (mitReplyTimesField in the reply box, mitReplyTimesText beside a note's time),
 * agent/modals/ticket/ticket_reply_edit.php (mitReplyTimesEditField), agent/post/ticket.php add_ticket_reply
 * (mitReplyTimesFromPost + mitReplyTimesSavePending) and edit_ticket_reply (mitReplyTimesFromEdit + mitReplyTimesSavePending).
 */

/** Has database update M028 run? */
function mitReplyTimesReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_reply_times'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
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

/** What to save once the note exists: [start, end], 'delete', or null (nothing). */
function mitReplyTimesPending($set = false) {
    static $pending = null;
    if ($set !== false) {
        $pending = $set;
    }
    return $pending;
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

/**
 * Hook in add_ticket_reply, after ITFlow has made the time worked from Hrs / Mins / Secs: the note's times from the form.
 * Typed times decide the length (unless they agree with it to the minute, keeping the timer's seconds). Returns the time
 * worked to store; mitReplyTimesSavePending() writes the times once the note is in. A note whose times can't be read, or
 * fall on a submitted / approved timesheet, keeps its length and counts as ending when posted (with a message).
 */
function mitReplyTimesFromPost(array $post, $time_worked) {
    global $session_user_id;
    mitReplyTimesPending(null);
    $seconds = mitReplyTimesSeconds($time_worked);
    if (!mitReplyTimesReady() || $seconds <= 0) {
        return $time_worked;
    }
    $range = mitReplyTimesParse($post['mit_work_date'] ?? '', $post['mit_work_start'] ?? '', $post['mit_work_end'] ?? '');
    if (!$range) {
        return $time_worked;
    }
    $locked = mitReplyTimesLocked($session_user_id, substr($range[0], 0, 10));
    if ($locked) {
        flashAlert('The times were not kept: ' . date('M j', strtotime($range[0])) . ' ' . mitReplyTimesLockedText($locked) . ', so the time counts as worked now.', 'warning');
        return $time_worked;
    }
    $length = strtotime($range[1]) - strtotime($range[0]);
    mitReplyTimesPending($range);
    return abs($length - $seconds) < 60 ? $time_worked : mitReplyTimesClock($length);
}

/**
 * Hook in edit_ticket_reply, before ITFlow's save: the times as edited. New times decide the length; the same times with
 * a new length move the start (the end stays). A note without times keeps having none unless its times are changed from
 * what the form showed (when it ended by its posting). Moving time onto or off a day whose timesheet is submitted,
 * approved or closed - or onto a day still to come - is refused. Returns the time worked to store.
 */
function mitReplyTimesFromEdit($ticket_reply_id, array $post, $time_worked) {
    global $mysqli;
    mitReplyTimesPending(null);
    $ticket_reply_id = intval($ticket_reply_id);
    if (!mitReplyTimesReady() || !$ticket_reply_id) {
        return $time_worked;
    }
    $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_created_at, ticket_reply_time_worked FROM ticket_replies WHERE ticket_reply_id = $ticket_reply_id"));
    if (!$reply) {
        return $time_worked;
    }
    $old = mitReplyTimesOf($ticket_reply_id);
    // Without times it counts on the day it was posted where its author was (as the timesheets count it)
    $old_date = $old ? substr($old['work_start'], 0, 10)
        : (function_exists('mitTsLocalDate') ? mitTsLocalDate($reply['ticket_reply_by'], $reply['ticket_reply_created_at']) : substr($reply['ticket_reply_created_at'], 0, 10));
    $seconds = mitReplyTimesSeconds($time_worked);
    $range = null;
    if ($seconds > 0) {
        $range = mitReplyTimesParse($post['mit_work_date'] ?? '', $post['mit_work_start'] ?? '', $post['mit_work_end'] ?? '');
        if ($range && !$old) {
            // Left as the form filled them in: not times anyone typed, so the note stays without them
            $shown = mitReplyTimesShown($reply);
            if (substr($range[0], 0, 16) === substr($shown['work_start'], 0, 16) && substr($range[1], 0, 16) === substr($shown['work_end'], 0, 16)) {
                $range = null;
            }
        }
        $same = $range && $old && substr($range[0], 0, 16) === substr($old['work_start'], 0, 16) && substr($range[1], 0, 16) === substr($old['work_end'], 0, 16);
        if ($same && $seconds !== mitReplyTimesSeconds($reply['ticket_reply_time_worked'])) {
            // Only the length changed: the end stays, the start moves
            $range[0] = date('Y-m-d H:i:s', strtotime($range[1]) - $seconds);
        } elseif ($range) {
            $length = strtotime($range[1]) - strtotime($range[0]);
            $time_worked = abs($length - $seconds) < 60 ? $time_worked : mitReplyTimesClock($length);
        }
    }
    $new_date = $range ? substr($range[0], 0, 10) : $old_date;
    if ($new_date !== $old_date) {
        foreach ([$old_date, $new_date] as $date) {
            $locked = mitReplyTimesLocked($reply['ticket_reply_by'], $date, $date === $new_date);
            if ($locked) {
                flashAlert('This note\'s time can\'t move ' . ($date === $old_date ? 'off ' : 'onto ') . date('M j', strtotime($date)) . ' - that day ' . mitReplyTimesLockedText($locked) . '.', 'error');
                redirect();
            }
        }
    }
    // Times out (length 0): a row goes; times not given or not changed on a note without them: nothing to store
    mitReplyTimesPending($seconds > 0 ? $range : 'delete');
    return $time_worked;
}

/** After the note is saved: write (or remove) its times. */
function mitReplyTimesSavePending($ticket_reply_id) {
    global $mysqli, $session_user_id;
    $pending = mitReplyTimesPending();
    $ticket_reply_id = intval($ticket_reply_id);
    if (!$ticket_reply_id || $pending === null || !mitReplyTimesReady()) {
        return;
    }
    if ($pending === 'delete') {
        mysqli_query($mysqli, "DELETE FROM mit_reply_times WHERE reply_id = $ticket_reply_id");
    } else {
        [$start, $end] = array_map('escapeSql', $pending);
        mysqli_query($mysqli, "INSERT INTO mit_reply_times SET reply_id = $ticket_reply_id, work_start = '$start', work_end = '$end', set_by = " . intval($session_user_id) . "
            ON DUPLICATE KEY UPDATE work_start = '$start', work_end = '$end', set_by = " . intval($session_user_id) . ", set_at = NOW()");
    }
    mitReplyTimesPending(null);
    if (function_exists('mitTsForget')) {
        mitTsForget();
    }
}

/** One note's times, or null. */
function mitReplyTimesOf($ticket_reply_id) {
    global $mysqli;
    if (!mitReplyTimesReady()) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT work_start, work_end FROM mit_reply_times WHERE reply_id = " . intval($ticket_reply_id))) ?: null;
}

/** "Tue Oct 20, 9:10 - 9:40 AM" (the end's day too when it runs past midnight). */
function mitReplyTimesWords($start, $end) {
    $from = strtotime($start);
    $to = strtotime($end);
    $same_half = date('A', $from) === date('A', $to) && date('Y-m-d', $from) === date('Y-m-d', $to);
    return date('D M j, ', $from) . date($same_half ? 'g:i' : 'g:i A', $from) . ' - '
        . (date('Y-m-d', $from) !== date('Y-m-d', $to) ? date('D ', $to) : '') . date('g:i A', $to);
}

/** agent/ticket.php: beside a note's time, when it was worked. */
function mitReplyTimesText($ticket_reply_id, $ticket_id) {
    global $mysqli;
    static $cache = [];
    $ticket_id = intval($ticket_id);
    if (!mitReplyTimesReady()) {
        return '';
    }
    if (!isset($cache[$ticket_id])) {
        $cache[$ticket_id] = [];
        $sql = mysqli_query($mysqli, "SELECT t.reply_id, t.work_start, t.work_end FROM mit_reply_times t JOIN ticket_replies ON ticket_reply_id = t.reply_id
            WHERE ticket_reply_ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($sql)) {
            $cache[$ticket_id][intval($row['reply_id'])] = $row;
        }
    }
    $row = $cache[$ticket_id][intval($ticket_reply_id)] ?? null;
    return $row ? ' <span class="text-nowrap" data-mit-reply-times title="When the work was done">(' . escapeHtml(mitReplyTimesWords($row['work_start'], $row['work_end'])) . ')</span>' : '';
}

/**
 * The reply box: a time strip - ITFlow's timer (moved in by agent/js/mit_reply_times.js) and Date worked / Start - End -
 * above one row of Status and the send buttons. The script keeps the times in step with the timer.
 */
function mitReplyTimesField() {
    if (!mitReplyTimesReady()) {
        return '';
    }
    return '<div class="col-12" data-mit-reply-times-field>'
        . '<style>
            .mit-reply-foot { --bs-gutter-y: .6rem; }
            .mit-reply-foot > [data-mit-reply-times-field] { order: -1; }
            .mit-reply-foot > .mit-status-col { flex: 1 1 11rem; width: auto; max-width: 22rem; }
            .mit-reply-foot > .mit-send-col { flex: 0 0 auto; width: auto; margin-left: auto; }
            .mit-reply-foot > .mit-send-col .btn { white-space: nowrap; }
            .mit-time-strip { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .5rem 1rem; padding: .6rem .75rem; border: 1px solid var(--bs-border-color); border-radius: .6rem; background: var(--bs-body-bg); }
            .mit-time-strip .mit-strip-part { display: flex; flex-direction: column; gap: .2rem; min-width: 0; }
            .mit-time-strip .mit-strip-label { font-size: .75rem; color: var(--bs-secondary-color); }
            .mit-time-strip #hours, .mit-time-strip #minutes, .mit-time-strip #seconds { flex: 0 0 3.1rem; width: 3.1rem; text-align: center; padding-left: .25rem; padding-right: .25rem; }
            .mit-time-strip #mitWorkDate { flex: 0 0 10rem; width: 10rem; }
            .mit-time-strip #mitWorkStart, .mit-time-strip #mitWorkEnd { flex: 0 0 8.75rem; width: 8.75rem; }
            .mit-time-strip [data-mit-times-mode] { font-size: .75rem; color: var(--bs-secondary-color); align-self: center; }
          </style>'
        . '<div class="mit-time-strip">'
        . '<div class="mit-strip-part" data-mit-time-slot><span class="mit-strip-label">Time worked</span></div>'
        . '<div class="mit-strip-part"><span class="mit-strip-label">When</span>'
        . '<div class="input-group">'
        . '<input type="date" class="form-control" id="mitWorkDate" name="mit_work_date" aria-label="Date worked" title="Date worked">'
        . '<input type="time" step="60" class="form-control" id="mitWorkStart" name="mit_work_start" aria-label="Start" title="Start">'
        . '<span class="input-group-text px-2">-</span>'
        . '<input type="time" step="60" class="form-control" id="mitWorkEnd" name="mit_work_end" aria-label="End" title="End">'
        . '</div></div>'
        . '<span data-mit-times-mode></span>'
        . '</div>'
        . '<div class="small text-danger mt-1" data-mit-times-error hidden></div>'
        . '</div><script src="' . mitAsset('/agent/js/mit_reply_times.js') . '"></script>';
}

/** What the edit form shows for a note without times: ending when it was posted, as long as its time worked. */
function mitReplyTimesShown(array $reply) {
    $end = strtotime($reply['ticket_reply_created_at'] ?? 'now');
    return ['work_start' => date('Y-m-d H:i:s', $end - mitReplyTimesSeconds($reply['ticket_reply_time_worked'] ?? '')), 'work_end' => date('Y-m-d H:i:s', $end)];
}

/** Edit reply: the note's times (or, for an older note, when it ended by its posting). */
function mitReplyTimesEditField($ticket_reply_id) {
    global $mysqli;
    if (!mitReplyTimesReady()) {
        return '';
    }
    $row = mitReplyTimesOf($ticket_reply_id);
    if (!$row) {
        $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_created_at, ticket_reply_time_worked FROM ticket_replies WHERE ticket_reply_id = " . intval($ticket_reply_id)));
        $row = mitReplyTimesShown($reply ?: []);
    }
    return '<div class="row g-2 mt-1">'
        . '<div class="col-sm-4"><label class="form-label small mb-1">Date worked</label><input type="date" class="form-control" name="mit_work_date" value="' . substr($row['work_start'], 0, 10) . '"></div>'
        . '<div class="col-6 col-sm-4"><label class="form-label small mb-1">Start</label><input type="time" step="60" class="form-control" name="mit_work_start" value="' . substr($row['work_start'], 11, 5) . '"></div>'
        . '<div class="col-6 col-sm-4"><label class="form-label small mb-1">End</label><input type="time" step="60" class="form-control" name="mit_work_end" value="' . substr($row['work_end'], 11, 5) . '"></div>'
        . '<div class="col-12 form-text mt-0">Change the times and the length follows them; change only the length and the start moves.</div>'
        . '</div>';
}

/*
 * ---------- for the timesheets (functions/mit_timesheets.php) ----------
 */

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

/** mitTsReplyWorkRows() from the database. */
function mitTsReplyWorkRowsRead(array $user_ids, $from, $to) {
    global $mysqli;
    $settings = mitTsSettings();
    $billable = mitTsBillableSql($settings['billable']);
    $where = "ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00' AND ticket_reply_by IN (" . implode(',', $user_ids) . ")"
        . ($settings['ticket_internal'] ? '' : " AND ticket_reply_type <> 'Internal'");
    // Posted two days either side (people in other time zones), or worked within the dates
    $wide_from = escapeSql(date('Y-m-d', strtotime("$from -2 days")) . ' 00:00:00');
    $wide_to = escapeSql(date('Y-m-d', strtotime("$to +3 days")) . ' 00:00:00');
    $day_from = escapeSql($from . ' 00:00:00');
    $day_to = escapeSql(date('Y-m-d', strtotime("$to +1 day")) . ' 00:00:00');
    $ready = mitReplyTimesReady();
    $columns = "ticket_reply_id, ticket_reply_by, ticket_reply_created_at, TIME_TO_SEC(ticket_reply_time_worked) AS seconds,
            IF($billable, 1, 0) AS billable, ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_client_id, client_name";
    // Two parts, each on its own index (database update M029: replies by person and posting time; M028: times by start) -
    // notes without times by when they were posted, notes with times by when they were worked
    $sql = "SELECT $columns, " . ($ready ? 'mrt.work_start, mrt.work_end' : 'NULL AS work_start, NULL AS work_end') . ", ticket_reply_created_at AS sort_at
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id LEFT JOIN clients ON client_id = ticket_client_id
        " . ($ready ? 'LEFT JOIN mit_reply_times mrt ON mrt.reply_id = ticket_reply_id' : '') . "
        WHERE $where AND ticket_reply_created_at >= '$wide_from' AND ticket_reply_created_at < '$wide_to'" . ($ready ? ' AND mrt.reply_id IS NULL' : '');
    if ($ready) {
        $sql .= " UNION ALL SELECT $columns, mrt.work_start, mrt.work_end, mrt.work_start AS sort_at
            FROM mit_reply_times mrt JOIN ticket_replies ON ticket_reply_id = mrt.reply_id JOIN tickets ON ticket_id = ticket_reply_ticket_id
            LEFT JOIN clients ON client_id = ticket_client_id
            WHERE $where AND mrt.work_start >= '$day_from' AND mrt.work_start < '$day_to'";
    }
    $result = mysqli_query($mysqli, "$sql ORDER BY sort_at, ticket_reply_id");
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $user_id = intval($row['ticket_reply_by']);
        $date = $row['work_start'] ? substr($row['work_start'], 0, 10) : mitTsLocalDate($user_id, $row['ticket_reply_created_at']);
        if ($date < $from || $date > $to) {
            continue;
        }
        $rows[] = [
            'user_id' => $user_id, 'date' => $date, 'reply_id' => intval($row['ticket_reply_id']), 'ticket_id' => intval($row['ticket_id']),
            'reference' => ticketReference($row), 'ticket_prefix' => $row['ticket_prefix'], 'ticket_number' => $row['ticket_number'], 'subject' => $row['ticket_subject'], 'client_id' => intval($row['ticket_client_id']),
            'client_name' => (string) $row['client_name'], 'billable' => intval($row['billable']), 'seconds' => intval($row['seconds']),
            'start' => $row['work_start'], 'end' => $row['work_end'], 'est' => !$row['work_start'],
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
    $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_type, ticket_reply_created_at, ticket_reply_time_worked, ticket_reply_archived_at,
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
    mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_time_worked = '" . escapeSql($time_worked) . "' WHERE ticket_reply_id = $ticket_reply_id");
    mitReplyTimesPending($range);
    mitReplyTimesSavePending($ticket_reply_id);

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
