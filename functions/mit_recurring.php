<?php

/*
 * Mountaineer IT - repeats by weekday: "the third Wednesday" or "the last Friday" of the month,
 * for recurring tickets and repeating calendar events (database update M006).
 *
 * A rule is stored the way calendars write it (RFC 5545 BYDAY): an ordinal and a weekday -
 * 1 to 4 for the first to fourth, -1 for the last - so "3WE" is the third Wednesday and "-1FR"
 * the last Friday. No rule means ITFlow's own behavior: the same date each month.
 * The choices offered always come from the date picked, so a rule always matches its first run.
 */

const MIT_WEEKDAYS = ['MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'];
const MIT_ORDINALS = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', -1 => 'last'];

/** The rule tidied ("3WE", "-1FR"), or '' when it is not one. */
function mitByDayRule($rule) {
    $rule = strtoupper(trim((string) $rule));
    return preg_match('/^(-1|[1-4])(MO|TU|WE|TH|FR|SA|SU)$/', $rule) ? $rule : '';
}

/** [ordinal, weekday code] of a rule. */
function mitByDayParts($rule) {
    $rule = mitByDayRule($rule);
    return $rule === '' ? [0, ''] : [intval(substr($rule, 0, -2)), substr($rule, -2)];
}

/** The date ('Y-m-d') a rule falls on in a month - the 1st-4th or last of that weekday always exists. */
function mitByDayDate($year, $month, $rule) {
    [$ordinal, $code] = mitByDayParts($rule);
    if (!$ordinal) {
        return null;
    }
    $weekday = array_search($code, array_keys(MIT_WEEKDAYS)) + 1;  // ISO 1 (Monday) .. 7 (Sunday)
    $days = intval(date('t', mktime(0, 0, 0, $month, 1, $year)));
    if ($ordinal > 0) {
        $first = intval(date('N', mktime(0, 0, 0, $month, 1, $year)));
        $day = 1 + (($weekday - $first + 7) % 7) + ($ordinal - 1) * 7;
    } else {
        $last = intval(date('N', mktime(0, 0, 0, $month, $days, $year)));
        $day = $days - (($last - $weekday + 7) % 7);
    }
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

/** "the third Wednesday", "the last Friday". */
function mitByDayText($rule) {
    [$ordinal, $code] = mitByDayParts($rule);
    return $ordinal ? 'the ' . MIT_ORDINALS[$ordinal] . ' ' . MIT_WEEKDAYS[$code] : '';
}

/**
 * The weekday rules a date can repeat on: its nth weekday of the month (when 1st-4th), and "last"
 * when it is in the month's last seven days. [rule => "the third Wednesday", ...]
 */
function mitByDayOptions($date) {
    $ts = strtotime(substr((string) $date, 0, 10));
    if (!$ts) {
        return [];
    }
    $code = array_keys(MIT_WEEKDAYS)[intval(date('N', $ts)) - 1];
    $day = intval(date('j', $ts));
    $options = [];
    $nth = intdiv($day - 1, 7) + 1;
    if ($nth <= 4) {
        $options[$nth . $code] = mitByDayText($nth . $code);
    }
    if ($day + 7 > intval(date('t', $ts))) {
        $options['-1' . $code] = mitByDayText('-1' . $code);
    }
    return $options;
}

/** A posted rule, kept only if the date can repeat on it. */
function mitByDayForDate($rule, $date) {
    $rule = mitByDayRule($rule);
    return $rule !== '' && isset(mitByDayOptions($date)[$rule]) ? $rule : '';
}

/**
 * A rule carried to a new date (a run or an event moved by hand): kept when the new date still fits
 * it, otherwise the same kind of rule for the new date - "last" stays "last" when it can.
 */
function mitByDayFollow($rule, $date) {
    $rule = mitByDayRule($rule);
    if ($rule === '' || mitByDayForDate($rule, $date) !== '') {
        return $rule;
    }
    $options = mitByDayOptions($date);
    $last = array_values(array_filter(array_keys($options), function ($r) { return strpos($r, '-1') === 0; }));
    if (strpos($rule, '-1') === 0 && $last) {
        return $last[0];
    }
    return (string) (array_key_first(array_filter($options, function ($text, $r) { return strpos($r, '-1') !== 0; }, ARRAY_FILTER_USE_BOTH)) ?? ($last[0] ?? ''));
}

/** The weekday rule of a recurring ticket, '' for none - quietly '' before database update M006 has run. */
function mitRecurringTicketByDay($row) {
    return mitByDayRule($row['recurring_ticket_by_day'] ?? '');
}

/** Save a recurring ticket's weekday rule from its form ("repeat_on": a rule, or "" for the same date) - only for month-based ones. */
function mitRecurringTicketSaveByDay($recurring_ticket_id, $frequency, $posted, $first_run) {
    global $mysqli;
    $months = isset(RECURRING_TICKET_FREQUENCIES[$frequency]['months']);
    $rule = $months ? mitByDayForDate($posted, $first_run) : '';
    try {
        mysqli_query($mysqli, "UPDATE recurring_tickets SET recurring_ticket_by_day = " . ($rule !== '' ? "'$rule'" : 'NULL') . " WHERE recurring_ticket_id = " . intval($recurring_ticket_id));
    } catch (Throwable $e) {
        // database update M006 still to run - it repeats on the date until then
    }
}

/**
 * The "Repeats on" field for a form (js/mit_repeat_on.js fills it from the date picked): $date and
 * $frequency are selectors in the same form, $months the frequency values it applies to, $yearly
 * those worded "of October". Posts "repeat_on".
 */
function mitRepeatOnField($date, $frequency, array $months, array $yearly, $current, $label = 'Repeats on') {
    return '<div class="mb-3" data-mit-repeat-on-wrap hidden>'
        . '<label class="form-label">' . escapeHtml($label) . '</label>'
        . '<div class="input-group"><span class="input-group-text"><i class="fa fa-fw fa-calendar-week"></i></span>'
        . '<select class="form-select" name="repeat_on" data-mit-repeat-on data-date="' . escapeHtml($date) . '" data-frequency="' . escapeHtml($frequency) . '"'
        . ' data-months="' . escapeHtml(implode(',', $months)) . '" data-yearly="' . escapeHtml(implode(',', $yearly)) . '" data-current="' . escapeHtml(mitByDayRule($current)) . '" disabled></select></div>'
        . '<div class="form-text">Same date each time, or the same weekday - like the third Wednesday or the last Friday.</div>'
        . '</div><script src="' . mitAsset('/js/mit_repeat_on.js') . '"></script>';
}

/** The field for a recurring ticket form. */
function mitRecurringTicketRepeatOnField($date_name, $current) {
    return mitRepeatOnField('[name=' . $date_name . ']', '[name=frequency]', ['Monthly', 'Quarterly', 'Biannually', 'Annually'], ['Annually'], $current);
}

/**
 * The SET part for a calendar event's weekday rule (functions/calendar.php saves the event):
 * "event_repeat_by_day = '3WE'," or NULL - nothing at all before database update M006 has run.
 */
function mitEventByDaySql($repeat, $posted, $start_date) {
    if (!mitEventHasByDay()) {
        return '';
    }
    $rule = in_array((string) $repeat, ['Month', 'Year'], true) ? mitByDayForDate($posted, $start_date) : '';
    return ' event_repeat_by_day = ' . ($rule !== '' ? "'$rule'" : 'NULL') . ',';
}

/** Has database update M006 added calendar_events.event_repeat_by_day yet? (Asked once per request.) */
function mitEventHasByDay() {
    global $mysqli;
    static $has_column = null;
    if ($has_column === null) {
        try {
            $has_column = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM calendar_events LIKE 'event_repeat_by_day'")) > 0;
        } catch (Throwable $e) {
            $has_column = false;
        }
    }
    return $has_column;
}

/** ", recurring_ticket_by_day" for a recurring tickets column list once database update M006 has added it, else ''. */
function mitRecurringTicketByDayColumn() {
    global $mysqli;
    static $has = null;
    if ($has === null) {
        try {
            $has = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM recurring_tickets LIKE 'recurring_ticket_by_day'")) === 1;
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has ? ', recurring_ticket_by_day' : '';
}

/** For ITFlow's event queries that list their columns: " event_repeat_by_day," once M006 has run. */
function mitEventByDayColumn() {
    return mitEventHasByDay() ? ' event_repeat_by_day,' : '';
}
