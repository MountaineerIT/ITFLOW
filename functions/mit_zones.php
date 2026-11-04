<?php

/*
 * Mountaineer IT: the calendar and Dispatch in each person's own time zone (26.11.2.5, the user's choice - a tech works
 * from Japan).
 *
 * ITFlow runs every request in the company's zone (Admin > Settings > Localization, includes/inc_set_timezone.php) and
 * keeps bookings as clock times in it. The person's zone is the timesheet one (Account > Preferences "My time zone",
 * mit_timesheet_people.time_zone - M023, functions/mit_timesheets.php). Nothing stored changes: times going out to the
 * calendar / Dispatch / the ticket's booking are moved into the viewer's zone (mitToView, mitZoneItems), and times coming
 * back from a drag, a drop or a form are moved back into the company's (mitFromView) before ITFlow's own code sees them.
 * Customer emails, invites, Outlook sync and clash checks keep working on the stored company times.
 */

/** The company's zone - the one stored times are in. */
function mitCompanyZone() {
    return date_default_timezone_get();
}

/** A person's own zone, else the company's. */
function mitUserZone($user_id) {
    if (function_exists('mitTsOwnZone') && function_exists('mitTsZoneColumn') && mitTsZoneColumn()) {
        $zone = mitTsOwnZone(intval($user_id));
        if ($zone !== '') {
            return $zone;
        }
    }
    return mitCompanyZone();
}

/** The zone the signed-in person sees times in. */
function mitViewZone() {
    $user_id = intval($GLOBALS['session_user_id'] ?? 0);
    return $user_id ? mitUserZone($user_id) : mitCompanyZone();
}

/** Is the viewer away from the company's zone? */
function mitViewShifted($zone = null) {
    return ($zone ?? mitViewZone()) !== mitCompanyZone();
}

/**
 * A clock time moved from one zone to another, in the shape it came in ('Y-m-d H:i:s', 'Y-m-d H:i' or with a T). A date
 * on its own, an empty value or anything else is handed back as it is.
 */
function mitZoneConvert($value, $from, $to) {
    $value = (string) $value;
    if ($from === $to || !preg_match('/^(\d{4}-\d{2}-\d{2})([ T])(\d{2}:\d{2})(:\d{2})?$/', $value, $m)) {
        return $value;
    }
    try {
        $date = new DateTime($m[1] . ' ' . $m[3] . ($m[4] ?? ''), new DateTimeZone($from));
        $date->setTimezone(new DateTimeZone($to));
    } catch (Throwable $e) {
        return $value;
    }
    return $date->format('Y-m-d') . $m[2] . $date->format(isset($m[4]) && $m[4] !== '' ? 'H:i:s' : 'H:i');
}

/** A stored (company) time as the viewer's clock reads it. */
function mitToView($value, $zone = null) {
    return mitZoneConvert($value, mitCompanyZone(), $zone ?? mitViewZone());
}

/** A time typed / dropped on the viewer's clock as the company's clock reads it (what is stored). */
function mitFromView($value, $zone = null) {
    return mitZoneConvert($value, $zone ?? mitViewZone(), mitCompanyZone());
}

/** Calendar items (FullCalendar's start / end) moved into the viewer's zone; whole-day items stay on their dates. */
function mitZoneItems(array $items, $zone = null) {
    $zone = $zone ?? mitViewZone();
    if (!mitViewShifted($zone)) {
        return $items;
    }
    foreach ($items as $i => $item) {
        if (!empty($item['allDay'])) {
            continue;
        }
        foreach (['start', 'end'] as $key) {
            if (!empty($item[$key])) {
                $items[$i][$key] = mitToView($item[$key], $zone);
            }
        }
    }
    return $items;
}

/**
 * A range of whole days on the viewer's clock ('Y-m-d H:i:s' from / to) as the company's clock reads it, for the
 * queries behind a calendar view.
 */
function mitZoneRange($from, $to, $zone = null) {
    return [mitFromView($from, $zone), mitFromView($to, $zone)];
}

/** Now on the viewer's clock ('Y-m-d\TH:i:s', for FullCalendar's now). */
function mitZoneNow($zone = null) {
    try {
        return (new DateTime('now', new DateTimeZone($zone ?? mitViewZone())))->format('Y-m-d\TH:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d\TH:i:s');
    }
}

/** Minutes the viewer's clock is ahead of the company's right now (Tokyo vs Los Angeles: 1020). */
function mitZoneOffsetMinutes($zone = null) {
    try {
        $now = new DateTime('now');
        $view = (new DateTimeZone($zone ?? mitViewZone()))->getOffset($now);
        $company = (new DateTimeZone(mitCompanyZone()))->getOffset($now);
        return intdiv($view - $company, 60);
    } catch (Throwable $e) {
        return 0;
    }
}

/** "Tokyo (JST)" - a zone as people say it. */
function mitZoneName($zone) {
    $city = str_replace('_', ' ', substr(strrchr('/' . $zone, '/'), 1));
    try {
        $abbr = (new DateTime('now', new DateTimeZone($zone)))->format('T');
    } catch (Throwable $e) {
        $abbr = '';
    }
    return $city . ($abbr !== '' && !preg_match('/^[+-]\d/', $abbr) ? " ($abbr)" : ($abbr !== '' ? " (UTC$abbr)" : ''));
}

/** The note on the calendar / Dispatch when the viewer is away from the company's zone; '' when not. */
function mitZoneNote() {
    $zone = mitViewZone();
    if (!mitViewShifted($zone)) {
        return '';
    }
    return '<div class="mb-2"><span class="badge text-bg-light border fw-normal" data-mit-zone-note="' . escapeHtml($zone) . '" title="Times are moved into your time zone (Account &gt; Preferences). Bookings are kept in the company\'s time.">'
        . '<i class="fas fa-globe-asia me-1"></i>Times in ' . escapeHtml(mitZoneName($zone)) . ' &middot; company: ' . escapeHtml(mitZoneName(mitCompanyZone())) . '</span></div>';
}

/**
 * A booking's time for the ticket page and the schedule window: the viewer's clock, with the company's beside it when
 * they differ. $format is PHP's date format.
 */
function mitZoneWhen($company_value, $format = 'D, M j g:i A', $zone = null) {
    $zone = $zone ?? mitViewZone();
    $company_value = (string) $company_value;
    if ($company_value === '' || strtotime($company_value) === false) {
        return '';
    }
    $view = date($format, strtotime(mitToView($company_value, $zone)));
    if (!mitViewShifted($zone)) {
        return $view;
    }
    return $view . ' ' . mitZoneAbbr($zone) . ' (' . date($format, strtotime($company_value)) . ' ' . mitZoneAbbr(mitCompanyZone()) . ')';
}

/** A zone's short name now ("JST", "PST"; "UTC+09" when it has none). */
function mitZoneAbbr($zone) {
    try {
        $abbr = (new DateTime('now', new DateTimeZone($zone)))->format('T');
    } catch (Throwable $e) {
        return '';
    }
    return preg_match('/^[+-]\d/', $abbr) ? 'UTC' . $abbr : $abbr;
}

/**
 * A calendar / Dispatch move or booking (start / end posted on the viewer's clock) with its times moved into the
 * company's. Whole-day drops keep their dates.
 */
function mitZoneMovePost(array $post, $zone = null) {
    if (!empty($post['all_day']) || !mitViewShifted($zone)) {
        return $post;
    }
    foreach (['start', 'end'] as $key) {
        if (!empty($post[$key]) && is_string($post[$key])) {
            $post[$key] = mitFromView(trim($post[$key]), $zone);
        }
    }
    return $post;
}

/** The line under a form's times when the viewer is away from the company's zone ('' when not). */
function mitZoneFormHint() {
    $zone = mitViewZone();
    if (!mitViewShifted($zone)) {
        return '';
    }
    return '<div class="col-12 form-text mt-1" data-mit-zone-hint><i class="fas fa-globe-asia me-1"></i>Times in ' . escapeHtml(mitZoneName($zone))
        . ' - kept in the company\'s time (' . escapeHtml(mitZoneName(mitCompanyZone())) . ').</div>';
}

/**
 * New / Edit Event's posted day + time fields (start_date / start_time, end_date / end_time) typed on the viewer's clock,
 * moved into the company's. Whole-day events keep their dates.
 */
function mitZoneEventPost(array $post, $zone = null) {
    if (!empty($post['all_day']) || !mitViewShifted($zone)) {
        return $post;
    }
    foreach (['start', 'end'] as $key) {
        $date = (string) ($post[$key . '_date'] ?? ($key === 'end' ? ($post['start_date'] ?? '') : ''));
        $time = (string) ($post[$key . '_time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            continue;
        }
        $moved = mitFromView("$date $time", $zone);
        $post[$key . '_date'] = substr($moved, 0, 10);
        $post[$key . '_time'] = substr($moved, 11, 5);
    }
    return $post;
}

/** An event's when (calendarWhenText) on the viewer's clock, with the company's after it when they differ. */
function mitZoneWhenText(array $event, $start, $end, $zone = null) {
    $zone = $zone ?? mitViewZone();
    if (!empty($event['event_all_day']) || !mitViewShifted($zone)) {
        return calendarWhenText($event, $start, $end);
    }
    return calendarWhenText($event, mitToView($start, $zone), mitToView($end, $zone)) . ' ' . mitZoneAbbr($zone)
        . ' (' . calendarWhenText($event, $start, $end) . ' ' . mitZoneAbbr(mitCompanyZone()) . ')';
}

/**
 * Working hours (FullCalendar businessHours: daysOfWeek, startTime, endTime - Admin > SLA) moved onto the viewer's clock.
 * Hours that run past midnight there come back as two pieces, the second on the next days.
 */
function mitZoneBusinessHours($hours, $zone = null) {
    $offset = mitZoneOffsetMinutes($zone);
    if (!$offset || !is_array($hours) || !isset($hours['startTime'], $hours['endTime'], $hours['daysOfWeek'])) {
        return $hours;
    }
    $minutes = function ($time) {
        return intval(substr($time, 0, 2)) * 60 + intval(substr($time, 3, 2));
    };
    $start = $minutes($hours['startTime']) + $offset;
    $end = $minutes($hours['endTime']) + $offset;
    $day_shift = intdiv($start - ((($start % 1440) + 1440) % 1440), 1440);
    $start -= $day_shift * 1440;
    $end -= $day_shift * 1440;
    $days = array_values(array_map(function ($day) use ($day_shift) {
        return ((intval($day) + $day_shift) % 7 + 7) % 7;
    }, (array) $hours['daysOfWeek']));
    $clock = function ($m) {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    };
    if ($end <= 1440) {
        return [['daysOfWeek' => $days, 'startTime' => $clock($start), 'endTime' => $clock($end)]];
    }
    return [
        ['daysOfWeek' => $days, 'startTime' => $clock($start), 'endTime' => '24:00'],
        ['daysOfWeek' => array_map(function ($day) {
            return ($day + 1) % 7;
        }, $days), 'startTime' => '00:00', 'endTime' => $clock($end - 1440)],
    ];
}

/**
 * A booking's when-text (mitScheduleWhenText - it remembers the times behind each one) on a person's own clock, with the
 * company's after it when they differ: the tech's and helpers' booking emails. Anything else comes back as it was.
 */
function mitZoneWhenFor($when_text, $user_id) {
    $times = $GLOBALS['mit_zone_when'][$when_text] ?? null;
    $zone = mitUserZone($user_id);
    if (!$times || !mitViewShifted($zone)) {
        return $when_text;
    }
    [$start, $end] = $times;
    $start_ts = strtotime(mitToView($start, $zone));
    $end_ts = strtotime(mitToView($end, $zone));
    $text = date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts)
        ? date('l, F j, Y, g:ia', $start_ts) . ' - ' . date('g:ia', $end_ts)
        : date('l, F j, Y, g:ia', $start_ts) . ' - ' . date('l, F j, Y, g:ia', $end_ts);
    return $text . ' ' . mitZoneAbbr($zone) . ' (' . $when_text . ' ' . mitZoneAbbr(mitCompanyZone()) . ')';
}

/** ITFlow's booking email options ($opts['when']) for the assigned tech, with the time on their own clock. */
function mitZoneAgentOpts(array $opts, $user_id) {
    if (!empty($opts['when'])) {
        $opts['when'] = mitZoneWhenFor($opts['when'], $user_id);
    }
    return $opts;
}
