<?php

/*
 * Mountaineer IT - leave balances (database update M014), required from functions/mit_timesheets.php.
 *
 * A leave bank (mit_leave_banks - PTO, Sick ...) is used up by the hours on its charge codes and
 * filled by its ledger (mit_leave_ledger): what was earned, granted, carried over or adjusted.
 *   balance on a date = the ledger up to then - hours on its codes up to then
 * Earning, per bank (all set in Admin > Timesheets > Leave):
 *   period - a set amount each pay period;  worked - a set amount for every so many hours worked;
 *   yearly - a set amount at the start of each leave year (1 Jan, or each person's hire anniversary);
 *   none.  Optional: a maximum balance, a waiting period after the hire date, and a person's own rate.
 * Nothing is earned before the hire date, or for pay periods that ended before the bank was set up.
 * Pay period earnings are added when the period is approved (taken off again if it is reopened), or
 * once it has settled (locked, or a week after it ends) if timesheets are not approved. At the start
 * of each leave year what is over the carry-over limit goes (keep all / up to a cap / none - worked
 * out again for the first 60 days) and the yearly grant comes in - both from the timesheet cron job.
 * A charge code belongs to one bank at most. Everything automatic has a unique (person, bank, kind, ref), so it is added once.
 */

const MIT_LEAVE_METHODS = ['none' => 'Not earned (adjust by hand)', 'period' => 'Each pay period', 'worked' => 'Per hour worked', 'yearly' => 'Once a year'];
const MIT_LEAVE_KINDS = ['accrual' => 'Earned', 'grant' => 'Yearly grant', 'carryover' => 'Year end', 'adjust' => 'Adjustment', 'start' => 'Starting balance'];

/** Has database update M014 run? */
function mitLeaveReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_leave_banks'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** bank_id => row (with 'codes' as a list of code ids), in order. Archived ones too when asked. */
function mitLeaveBanks($with_archived = false) {
    global $mysqli;
    static $cache = [];
    $key = $with_archived ? 'all' : 'active';
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        if (mitLeaveReady()) {
            // Every column on purpose: a small settings table later updates may add to, read whole like ITFlow's settings row
            $result = mysqli_query($mysqli, "SELECT * FROM mit_leave_banks" . ($with_archived ? '' : ' WHERE bank_archived_at IS NULL') . " ORDER BY bank_sort, bank_name");
            while ($row = mysqli_fetch_assoc($result)) {
                $row['codes'] = array_values(array_filter(array_map('intval', explode(',', (string) $row['bank_codes']))));
                $cache[$key][intval($row['bank_id'])] = $row;
            }
        }
    }
    return $cache[$key];
}

/** A person's own setting for a bank: ['rate' => minutes or null, 'excluded' => bool]. */
function mitLeaveRate($user_id, $bank_id) {
    global $mysqli;
    static $rates = null;
    if ($rates === null) {
        $rates = [];
        if (mitLeaveReady()) {
            $result = mysqli_query($mysqli, "SELECT user_id, bank_id, rate, excluded FROM mit_leave_rates");
            while ($row = mysqli_fetch_assoc($result)) {
                $rates[intval($row['user_id'])][intval($row['bank_id'])] = ['rate' => $row['rate'] === null ? null : intval($row['rate']), 'excluded' => (bool) intval($row['excluded'])];
            }
        }
    }
    return $rates[intval($user_id)][intval($bank_id)] ?? ['rate' => null, 'excluded' => false];
}

/** The banks a person has (not left out of). */
function mitLeaveBanksFor($user_id) {
    return array_filter(mitLeaveBanks(), function ($bank) use ($user_id) {
        return !mitLeaveRate($user_id, $bank['bank_id'])['excluded'];
    });
}

/** "8 h every pay period", "1 h for every 30 h worked", "80 h a year (1 Jan)" ... */
function mitLeaveRuleText(array $bank, $rate = null) {
    $rate = $rate ?? intval($bank['bank_rate']);
    $h = function ($m) {
        return mitTsHours($m, false, 'decimal') . ' h';
    };
    switch ($bank['bank_method']) {
        case 'period':
            return $h($rate) . ' every pay period';
        case 'worked':
            return $h($rate) . ' for every ' . $h($bank['bank_per_worked']) . ' worked';
        case 'yearly':
            return $h($rate) . ' a year (' . ($bank['bank_year_start'] === 'hire' ? 'hire anniversary' : '1 Jan') . ')';
    }
    return 'Not earned automatically';
}

/** A person's hire date, or ''. */
function mitLeaveHireDate($user_id) {
    $row = mitTsPeople()[intval($user_id)] ?? [];
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['hire_date'] ?? '')) ? $row['hire_date'] : '';
}

/** Is the person still in the bank's waiting period on this date? */
function mitLeaveWaiting(array $bank, $user_id, $date) {
    $hire = mitLeaveHireDate($user_id);
    return $hire !== '' && intval($bank['bank_waiting_days']) > 0 && $date < date('Y-m-d', strtotime("$hire +" . intval($bank['bank_waiting_days']) . ' days'));
}

/** Minutes on a bank's codes for a person up to a date (or all of them, booked ahead too, when $to is null). */
function mitLeaveUsed($user_id, array $bank, $to = null) {
    global $mysqli;
    if (!$bank['codes']) {
        return 0;
    }
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(minutes), 0) FROM mit_time_entries WHERE source = 'manual' AND user_id = " . intval($user_id) . "
        AND code_id IN (" . implode(',', $bank['codes']) . ")" . ($to === null ? '' : " AND entry_date <= '" . escapeSql($to) . "'")))[0]);
}

/**
 * Balances of several people on every bank they have, at the end of a date: [user_id][bank_id] => minutes (ledger less
 * hours on its codes) - two queries for everyone, for reports.
 */
function mitLeaveBalancesAt(array $user_ids, $to) {
    global $mysqli;
    $user_ids = array_values(array_filter(array_map('intval', $user_ids)));
    $balances = [];
    if (!$user_ids || !mitLeaveReady() || !mitLeaveBanks()) {
        return $balances;
    }
    $in = implode(',', $user_ids);
    $credited = [];
    $result = mysqli_query($mysqli, "SELECT user_id, bank_id, SUM(minutes) AS m FROM mit_leave_ledger WHERE user_id IN ($in) AND entry_date <= '" . escapeSql($to) . "' GROUP BY user_id, bank_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $credited[intval($row['user_id'])][intval($row['bank_id'])] = intval($row['m']);
    }
    $used = [];  // [user_id][code_id] => minutes
    $result = mysqli_query($mysqli, "SELECT user_id, code_id, SUM(minutes) AS m FROM mit_time_entries WHERE source = 'manual' AND user_id IN ($in) AND entry_date <= '" . escapeSql($to) . "' GROUP BY user_id, code_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $used[intval($row['user_id'])][intval($row['code_id'])] = intval($row['m']);
    }
    foreach ($user_ids as $user_id) {
        foreach (mitLeaveBanksFor($user_id) as $bank_id => $bank) {
            $balances[$user_id][$bank_id] = ($credited[$user_id][$bank_id] ?? 0) - array_sum(array_intersect_key($used[$user_id] ?? [], array_flip($bank['codes'])));
        }
    }
    return $balances;
}

/** The ledger's total for a person and bank up to a date (all of it when $to is null). */
function mitLeaveCredited($user_id, $bank_id, $to = null) {
    global $mysqli;
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(minutes), 0) FROM mit_leave_ledger WHERE user_id = " . intval($user_id) . " AND bank_id = " . intval($bank_id)
        . ($to === null ? '' : " AND entry_date <= '" . escapeSql($to) . "'")))[0]);
}

/** ['now' => balance today, 'booked' => after everything booked ahead, 'used_ahead' => minutes booked after today]. */
function mitLeaveBalance($user_id, array $bank, $on = null) {
    $on = $on ?? date('Y-m-d');
    $now = mitLeaveCredited($user_id, $bank['bank_id'], $on) - mitLeaveUsed($user_id, $bank, $on);
    $all = mitLeaveCredited($user_id, $bank['bank_id']) - mitLeaveUsed($user_id, $bank);
    return ['now' => $now, 'booked' => $all, 'used_ahead' => $now - $all];
}

/** Add to the ledger. Automatic kinds (with a ref) are added once; returns true when a row went in. */
function mitLeavePost($user_id, $bank_id, $date, $minutes, $kind, $ref = null, $note = '') {
    global $mysqli, $session_user_id;
    $minutes = intval($minutes);
    if (!$minutes) {
        return false;
    }
    mysqli_query($mysqli, "INSERT IGNORE INTO mit_leave_ledger SET user_id = " . intval($user_id) . ", bank_id = " . intval($bank_id) . ", entry_date = '" . escapeSql($date) . "',
        minutes = $minutes, kind = '" . escapeSql($kind) . "', ref = " . ($ref === null ? 'NULL' : "'" . escapeSql($ref) . "'") . ",
        note = " . ($note === '' ? 'NULL' : "'" . escapeSql(mb_substr($note, 0, 255)) . "'") . ", created_by = " . intval($session_user_id ?? 0));
    return mysqli_affected_rows($mysqli) > 0;
}

/** What a person earns in a pay period on a bank (minutes), before the maximum. */
function mitLeaveEarned(array $bank, $user_id, array $period) {
    $rate = mitLeaveRate($user_id, $bank['bank_id'])['rate'] ?? intval($bank['bank_rate']);
    $hire = mitLeaveHireDate($user_id);
    // Nothing before they were hired, before the waiting period is over, or for periods that ended before the bank was set up
    if ($rate <= 0 || mitLeaveWaiting($bank, $user_id, $period['end']) || ($hire !== '' && $hire > $period['end']) || $period['end'] < substr($bank['bank_created_at'], 0, 10)) {
        return 0;
    }
    if ($bank['bank_method'] === 'period') {
        return $rate;
    }
    if ($bank['bank_method'] === 'worked' && intval($bank['bank_per_worked']) > 0) {
        $worked = 0;
        foreach (mitTsDays([$user_id], $period['start'], $period['end'])[intval($user_id)] as $day) {
            // Unaccounted time never earns leave (mitTsDayWorked already leaves it out when it is unpaid)
            $worked += mitTsDayWorked($day) - (mitTsSettings()['auto_lost_paid'] ? $day['auto_lost'] : 0);
        }
        return (int) floor($worked * $rate / intval($bank['bank_per_worked']));
    }
    return 0;
}

/** Add a pay period's earnings for a person (replacing any added before for that period). Returns how many banks earned. */
function mitLeaveAccruePeriod($user_id, array $period) {
    if (!mitLeaveReady()) {
        return 0;
    }
    $posted = 0;
    mitLeaveUnaccruePeriod($user_id, $period);
    foreach (mitLeaveBanksFor($user_id) as $bank_id => $bank) {
        if (!in_array($bank['bank_method'], ['period', 'worked'], true)) {
            continue;
        }
        $earned = mitLeaveEarned($bank, $user_id, $period);
        if ($bank['bank_max'] !== null) {
            $room = intval($bank['bank_max']) - (mitLeaveCredited($user_id, $bank_id, $period['end']) - mitLeaveUsed($user_id, $bank, $period['end']));
            $earned = max(0, min($earned, $room));
        }
        $posted += mitLeavePost($user_id, $bank_id, $period['end'], $earned, 'accrual', $period['start'], $period['label']) ? 1 : 0;
    }
    return $posted;
}

/** Take a pay period's earnings back off (the period was reopened). */
function mitLeaveUnaccruePeriod($user_id, array $period) {
    global $mysqli;
    if (mitLeaveReady()) {
        mysqli_query($mysqli, "DELETE FROM mit_leave_ledger WHERE user_id = " . intval($user_id) . " AND kind = 'accrual' AND ref = '" . escapeSql($period['start']) . "'");
    }
}

/** The first day of the leave year a date is in, for a person and bank (1 Jan, or the hire anniversary). */
function mitLeaveYearStart(array $bank, $user_id, $date) {
    $hire = mitLeaveHireDate($user_id);
    if ($bank['bank_year_start'] !== 'hire' || $hire === '') {
        return substr($date, 0, 4) . '-01-01';
    }
    $start = substr($date, 0, 4) . substr($hire, 4);
    if (substr($hire, 5) === '02-29' && !checkdate(2, 29, intval(substr($date, 0, 4)))) {
        $start = substr($date, 0, 4) . '-02-28';
    }
    return $start > $date ? (intval(substr($date, 0, 4)) - 1) . substr($start, 4) : $start;
}

/** Days into a leave year the carry-over is worked out again (late approvals and corrections to last year). */
const MIT_LEAVE_CARRYOVER_DAYS = 60;

/**
 * The start of each leave year (cron): what is over the carry-over limit goes, then the yearly grant comes in.
 * Not in the leave year someone was hired in (theirs starts at the next), and no carry-over for years before the bank
 * was made; a bank set up mid-year grants the current year at once. For the first MIT_LEAVE_CARRYOVER_DAYS of the
 * year the carry-over is worked out again each day, so last year's late changes still count. Returns lines for the log.
 */
function mitLeaveYearEnds() {
    global $mysqli;
    $log = [];
    $today = date('Y-m-d');
    // What was already done this year and last, in one query: "user-bank-kind-ref" => minutes
    $done = [];
    $result = mysqli_query($mysqli, "SELECT user_id, bank_id, kind, ref, minutes FROM mit_leave_ledger
        WHERE kind IN ('carryover', 'grant') AND entry_date >= '" . (intval(date('Y')) - 2) . "-01-01'");
    while ($row = mysqli_fetch_assoc($result)) {
        $done[$row['user_id'] . '-' . $row['bank_id'] . '-' . $row['kind'] . '-' . $row['ref']] = intval($row['minutes']);
    }
    foreach (mitTsUsers() as $user_id => $user) {
        foreach (mitLeaveBanksFor($user_id) as $bank_id => $bank) {
            $year = mitLeaveYearStart($bank, $user_id, $today);
            $hire = mitLeaveHireDate($user_id);
            if ($hire !== '' && $hire > $year) {
                continue;  // hired during this leave year - their first one starts next year
            }
            $before = date('Y-m-d', strtotime("$year -1 day"));
            $recheck = $today < date('Y-m-d', strtotime("$year +" . MIT_LEAVE_CARRYOVER_DAYS . ' days'));
            if ($bank['bank_carryover_max'] !== null && substr($bank['bank_created_at'], 0, 10) < $year && $recheck) {
                $key = "$user_id-$bank_id-carryover-$year";
                $removed = $done[$key] ?? 0;  // negative, already in the ledger on $before
                $balance = mitLeaveCredited($user_id, $bank_id, $before) - $removed - mitLeaveUsed($user_id, $bank, $before);
                $over = max(0, $balance - intval($bank['bank_carryover_max']));
                if (-$removed !== $over) {
                    mysqli_query($mysqli, "DELETE FROM mit_leave_ledger WHERE user_id = $user_id AND bank_id = " . intval($bank_id) . " AND kind = 'carryover' AND ref = '" . escapeSql($year) . "'");
                    mitLeavePost($user_id, $bank_id, $before, -$over, 'carryover', $year, 'Over the carry-over limit at year end');
                    $log[] = "{$user['user_name']} {$bank['bank_name']}: " . mitTsHours($over, false, 'decimal') . ' h over the carry-over limit removed';
                }
            }
            if ($bank['bank_method'] === 'yearly' && !isset($done["$user_id-$bank_id-grant-$year"]) && !mitLeaveWaiting($bank, $user_id, $today)) {
                $rate = mitLeaveRate($user_id, $bank_id)['rate'] ?? intval($bank['bank_rate']);
                if ($bank['bank_max'] !== null) {
                    $room = intval($bank['bank_max']) - (mitLeaveCredited($user_id, $bank_id, $year) - mitLeaveUsed($user_id, $bank, $year));
                    $rate = max(0, min($rate, $room));
                }
                if (mitLeavePost($user_id, $bank_id, $year, $rate, 'grant', $year, 'Yearly grant')) {
                    $log[] = "{$user['user_name']} {$bank['bank_name']}: " . mitTsHours($rate, false, 'decimal') . ' h granted';
                }
            }
        }
    }
    return $log;
}

/**
 * Without approval, a pay period earns once its hours have settled (cron): when it locks (Admin >
 * Timesheets, days after the end) or else 7 days after it ends. The last few periods, each once; a
 * period that earned nothing is looked at again on later runs, in case hours were added.
 */
function mitLeaveAccrueEnded() {
    global $mysqli;
    $log = [];
    if (!mitLeaveReady() || mitTsSettings()['approval']) {
        return $log;
    }
    $wait = mitTsSettings()['autolock_days'] ?: 7;
    $period = mitTsPeriodShift(mitTsPeriodFor(), -1);
    for ($i = 0; $i < 4; $i++, $period = mitTsPeriodShift($period, -1)) {
        if (date('Y-m-d') <= date('Y-m-d', strtotime($period['end'] . " +$wait days"))) {
            continue;  // still settling
        }
        foreach (mitTsUsers(mitTsUsersWithActivity($period['start'], $period['end'])) as $user_id => $user) {
            $done = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_leave_ledger WHERE user_id = $user_id AND kind = 'accrual' AND ref = '" . escapeSql($period['start']) . "'"))[0]);
            $accrues = array_filter(mitLeaveBanksFor($user_id), function ($bank) {
                return in_array($bank['bank_method'], ['period', 'worked'], true);
            });
            if (!$done && $accrues && mitLeaveAccruePeriod($user_id, $period)) {
                $log[] = "{$user['user_name']}: leave earned for {$period['label']}";
            }
        }
    }
    return $log;
}

/**
 * A save on a timesheet would take a bank below zero where that is not allowed: the message, or ''.
 * $wanted is [date][code_id] => minutes about to be saved; $current the entries there now.
 */
function mitLeaveCheckSave($user_id, array $wanted, array $current) {
    foreach (mitLeaveBanksFor($user_id) as $bank) {
        if (intval($bank['bank_allow_negative']) || !$bank['codes']) {
            continue;
        }
        $change = 0;
        foreach ($wanted as $date => $by_code) {
            foreach ($by_code as $code_id => $minutes) {
                if (in_array(intval($code_id), $bank['codes'], true)) {
                    $change += $minutes - intval($current[$date][$code_id]['minutes'] ?? 0);
                }
            }
        }
        if ($change > 0) {
            $left = mitLeaveBalance($user_id, $bank)['booked'];
            if ($left - $change < 0) {
                return 'not enough ' . $bank['bank_name'] . ' - ' . mitTsHours(max(0, $left), false) . ' hours left';
            }
        }
    }
    return '';
}

/** The ledger for a person and bank, newest first, with what was used each pay period. */
function mitLeaveHistory($user_id, array $bank, $limit = 60) {
    global $mysqli;
    $rows = mysqli_fetch_all(mysqli_query($mysqli, "SELECT l.ledger_id, l.user_id, l.bank_id, l.entry_date, l.minutes, l.kind, l.ref, l.note, l.created_by, l.created_at, u.user_name AS created_by_name FROM mit_leave_ledger l LEFT JOIN users u ON u.user_id = l.created_by
        WHERE l.user_id = " . intval($user_id) . " AND l.bank_id = " . intval($bank['bank_id']) . " ORDER BY l.entry_date DESC, l.ledger_id DESC LIMIT " . intval($limit)), MYSQLI_ASSOC);
    if ($bank['codes']) {
        $result = mysqli_query($mysqli, "SELECT entry_date, SUM(minutes) AS minutes, GROUP_CONCAT(note SEPARATOR '; ') AS notes FROM mit_time_entries
            WHERE source = 'manual' AND user_id = " . intval($user_id) . " AND code_id IN (" . implode(',', $bank['codes']) . ") GROUP BY entry_date ORDER BY entry_date DESC LIMIT " . intval($limit));
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = ['entry_date' => $row['entry_date'], 'minutes' => -intval($row['minutes']), 'kind' => 'used', 'note' => $row['notes'], 'created_by_name' => null];
        }
    }
    usort($rows, function ($a, $b) {
        return strcmp($b['entry_date'], $a['entry_date']);
    });
    return array_slice($rows, 0, $limit);
}

/** The balances box on a timesheet (agent/mit_timesheet.php): each bank, live as hours are typed. */
function mitLeaveTimesheetBox($user_id, array $period) {
    if (!mitLeaveReady()) {
        return '';
    }
    $banks = array_filter(mitLeaveBanksFor($user_id), function ($bank) {
        return $bank['codes'];
    });
    if (!$banks) {
        return '';
    }
    $entries = mitTsEntries([$user_id], $period['start'], $period['end'])[intval($user_id)] ?? [];
    $html = '<div class="d-flex flex-wrap gap-2 mb-3" data-mit-leave>';
    foreach ($banks as $bank) {
        $balance = mitLeaveBalance($user_id, $bank);
        $in_period = 0;
        foreach ($entries as $by_code) {
            foreach ($bank['codes'] as $code_id) {
                $in_period += intval($by_code[$code_id]['minutes'] ?? 0);
            }
        }
        // What is left with this period's hours taken out - the page adds back what is typed
        $base = $balance['booked'] + $in_period;
        $html .= '<div class="border rounded px-3 py-2" data-mit-leave-bank data-name="' . escapeHtml($bank['bank_name']) . '" data-base="' . $base . '" data-codes="' . implode(',', $bank['codes']) . '" data-negative="' . intval($bank['bank_allow_negative']) . '">'
            . '<div class="small text-muted"><i class="fas fa-fw fa-umbrella-beach me-1"></i>' . escapeHtml($bank['bank_name']) . ' left'
            . ' <a href="#" class="ajax-modal ms-1" data-modal-url="modals/mit_timesheet/leave.php?user_id=' . intval($user_id) . '&bank_id=' . intval($bank['bank_id']) . '" title="History">history</a></div>'
            . '<div class="fs-5 fw-semibold" data-mit-leave-left>' . mitTsHours($balance['booked'], false) . '</div>'
            . '<div class="small text-muted">' . ($balance['used_ahead'] ? mitTsHours($balance['now'], false) . ' today, ' . mitTsHours($balance['used_ahead'], false) . ' booked ahead' : escapeHtml(mitLeaveRuleText($bank, mitLeaveRate($user_id, $bank['bank_id'])['rate']))) . '</div>'
            . '</div>';
    }
    return $html . '</div>';
}
