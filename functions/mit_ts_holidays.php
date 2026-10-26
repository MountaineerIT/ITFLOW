<?php

/*
 * Mountaineer IT - holidays from Admin > Timesheets > Holidays. They are ITFlow's own closure days (business_holidays,
 * also on Admin > SLA): SLA clocks do not run on them, and timesheets fill them in with the person's normal hours on the
 * code set to "Fill in closure days" (mitTsFillClosures). Changing one here also puts that right on timesheets that are
 * still open: hours filled in for a holiday that moved or went come off the old day, a renamed one gets the new name,
 * and a new or moved one is filled in now for days already gone. Submitted and approved timesheets are left as they are.
 */

/** The holidays of a year, by date: [['holiday_id', 'holiday_date', 'holiday_name']]. */
function mitTsHolidays($year) {
    global $mysqli;
    $year = intval($year);
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT holiday_id, holiday_date, holiday_name FROM business_holidays
        WHERE holiday_date BETWEEN '$year-01-01' AND '$year-12-31' ORDER BY holiday_date"), MYSQLI_ASSOC);
}

function mitTsHoliday($holiday_id) {
    global $mysqli;
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT holiday_id, holiday_date, holiday_name FROM business_holidays WHERE holiday_id = " . intval($holiday_id))) ?: null;
}

/** The codes that fill holidays in. code_id => row */
function mitTsHolidayCodes() {
    return array_filter(mitTsCodes(true), function ($code) {
        return intval($code['code_closure_fill'] ?? 0) && empty($code['code_archived_at']);
    });
}

/** Add (no id) or change a holiday. Returns [ok, message]. */
function mitTsHolidaySave($holiday_id, $date, $name) {
    global $mysqli;
    $holiday_id = intval($holiday_id);
    $name = trim((string) $name);
    $parsed = DateTime::createFromFormat('Y-m-d', (string) $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return [false, 'Pick a date for the holiday.'];
    }
    if ($name === '') {
        return [false, 'Give the holiday a name.'];
    }
    $name = mb_substr($name, 0, 200);
    $old = $holiday_id ? mitTsHoliday($holiday_id) : null;
    if ($holiday_id && !$old) {
        return [false, 'That holiday no longer exists.'];
    }
    $clash = mysqli_fetch_row(mysqli_query($mysqli, "SELECT holiday_name FROM business_holidays WHERE holiday_date = '" . escapeSql($date) . "' AND holiday_id <> $holiday_id"));
    if ($clash) {
        return [false, date('M j, Y', strtotime($date)) . ' is already a holiday (' . $clash[0] . ').'];
    }
    $set = "holiday_date = '" . escapeSql($date) . "', holiday_name = '" . escapeSql($name) . "'";
    mysqli_query($mysqli, $holiday_id ? "UPDATE business_holidays SET $set WHERE holiday_id = $holiday_id" : "INSERT INTO business_holidays SET $set");
    $note = mitTsHolidayChanged($old ? $old['holiday_date'] : null, $old ? $old['holiday_name'] : null, $date, $name);
    return [true, ($old ? 'Holiday saved' : 'Holiday added') . $note];
}

/** Remove a holiday. Returns [ok, message]. */
function mitTsHolidayDelete($holiday_id) {
    global $mysqli;
    $old = mitTsHoliday($holiday_id);
    if (!$old) {
        return [false, 'That holiday no longer exists.'];
    }
    mysqli_query($mysqli, "DELETE FROM business_holidays WHERE holiday_id = " . intval($holiday_id));
    return [true, 'Holiday removed' . mitTsHolidayChanged($old['holiday_date'], $old['holiday_name'], null, null)];
}

/** Add the US federal holidays of a year (ITFlow's own list - usFederalHolidays). Returns [ok, message]. */
function mitTsHolidaysAddUs($year) {
    global $mysqli;
    $year = intval($year);
    if ($year < 2000 || $year > 2100) {
        return [false, 'Pick a year from 2000 to 2100.'];
    }
    $added = 0;
    $note = '';
    foreach (usFederalHolidays($year) as $holiday) {
        mysqli_query($mysqli, "INSERT IGNORE INTO business_holidays SET holiday_date = '" . escapeSql($holiday['date']) . "', holiday_name = '" . escapeSql($holiday['name']) . "'");
        if (mysqli_affected_rows($mysqli) > 0) {
            $added++;
        }
    }
    if ($added) {
        $note = mitTsHolidayChanged(null, null, null, null, true);
    }
    return [true, "$added US holiday" . ($added === 1 ? '' : 's') . " added for $year" . $note];
}

/**
 * After a change: SLA targets worked out again (as Admin > SLA does) and open timesheets put right. Returns a sentence
 * to add to the message ('' when there is nothing to say).
 */
function mitTsHolidayChanged($old_date, $old_name, $new_date, $new_name, $fill_all = false) {
    global $mysqli;
    getBusinessHolidays(true);
    $restamped = function_exists('restampOpenSlaTickets') ? restampOpenSlaTickets() : 0;
    $fixed = 0;
    $kept = 0;
    $codes = array_keys(mitTsHolidayCodes());
    if ($codes && $old_date && ($old_date !== $new_date || $old_name !== $new_name)) {
        // Hours filled in for the old holiday: the closure code on that day, with the holiday's name as the note
        $result = mysqli_query($mysqli, "SELECT user_id, entry_id FROM mit_time_entries WHERE entry_date = '" . escapeSql($old_date) . "'
            AND code_id IN (" . implode(',', $codes) . ") AND note = '" . escapeSql(mb_substr((string) $old_name, 0, 255)) . "'");
        while ($row = mysqli_fetch_assoc($result)) {
            if (mitTsHolidayLocked($row['user_id'], $old_date)) {
                $kept++;
                continue;
            }
            if ($old_date === $new_date) {
                mysqli_query($mysqli, "UPDATE mit_time_entries SET note = '" . escapeSql(mb_substr((string) $new_name, 0, 255)) . "' WHERE entry_id = " . intval($row['entry_id']));
            } else {
                mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE entry_id = " . intval($row['entry_id']));
            }
            $fixed++;
        }
    }
    // Filled in now on open timesheets for days already gone (otherwise when the timesheet is next saved)
    $dates = $fill_all ? [] : array_filter([$new_date]);
    if ($codes && ($dates || $fill_all) && mitModuleOn('timesheets')) {
        foreach (mitTsUsers() as $user_id => $user) {
            $periods = [];
            foreach ($fill_all ? [date('Y-m-d')] : $dates as $date) {
                if ($date <= date('Y-m-d')) {
                    $period = mitTsPeriodFor($date);
                    $periods[$period['start']] = $period;
                }
            }
            foreach ($periods as $period) {
                if (!mitTsHolidayLocked($user_id, $period['start'], $period)) {
                    $fixed += mitTsFillClosures($user_id, $period);
                }
            }
        }
    }
    if ($fixed) {
        mitTsForget();
    }
    $parts = [];
    if ($fixed) {
        $parts[] = "$fixed timesheet day" . ($fixed === 1 ? '' : 's') . ' updated';
    }
    if ($kept) {
        $parts[] = "$kept on submitted or approved timesheets left as they are - reopen them to change";
    }
    if ($restamped) {
        $parts[] = "SLA targets recalculated on $restamped open ticket" . ($restamped === 1 ? '' : 's');
    }
    return $parts ? ' - ' . implode('; ', $parts) : '';
}

/** Is this person's day on a submitted or approved timesheet? */
function mitTsHolidayLocked($user_id, $date, ?array $period = null) {
    $period = $period ?: mitTsPeriodFor($date);
    $sheet = mitTsSheet($user_id, $period);
    return in_array($sheet['status'], ['submitted', 'approved'], true) || mitTsLockedDates($user_id, $date, $date, ['submitted', 'approved']);
}

/**
 * Holidays changed outside our Holidays tab (ITFlow's own Admin > SLA closure days have add / delete): the list as it was last
 * seen is kept (mit_settings ts_holidays_seen), and each closure day since taken away, renamed or added is put right on open
 * timesheets through mitTsHolidayChanged - what our tab does. From the hourly cron job. Lines for the log.
 */
function mitTsHolidaySweep() {
    if (!mitModuleOn('timesheets') || !mitTsHolidayCodes()) {
        return [];
    }
    $now = getBusinessHolidays(true);
    $seen_text = (string) mitSetting('ts_holidays_seen', '');
    mitSetSetting('ts_holidays_seen', json_encode($now));
    $seen = json_decode($seen_text, true);
    if (!is_array($seen)) {
        return []; // first run: nothing to compare with
    }
    $log = [];
    foreach ($seen as $date => $name) {
        if (!isset($now[$date]) || (string) $now[$date] !== (string) $name) {
            $log[] = "Closure day $date ($name) " . (isset($now[$date]) ? 'renamed' : 'taken away') . ' in Admin > SLA: ' . mitTsHolidayChanged($date, $name, isset($now[$date]) ? $date : '', (string) ($now[$date] ?? ''));
        }
    }
    foreach ($now as $date => $name) {
        if (!isset($seen[$date])) {
            $log[] = "Closure day $date ($name) added in Admin > SLA: " . mitTsHolidayChanged('', '', $date, (string) $name);
        }
    }
    return $log;
}
