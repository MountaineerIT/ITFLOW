<?php

/*
 * Mountaineer IT - each person's Outlook calendar (Account > Outlook calendar).
 *
 * A tech connects their own Microsoft 365 account to ITFlow's Microsoft 365 app
 * (functions/mit_m365.php - already approved for the organization, so nobody is asked to approve
 * anything). From then on every open ticket booked for them is an event in their Outlook calendar,
 * and ITFlow keeps it that way:
 *
 *  - a booking that is moved, made longer, renamed, reassigned, cancelled or closed changes or
 *    removes its event within a minute (cron/mit_outlook_sync.php);
 *  - ITFlow is the source of truth - an event moved, renamed or deleted in Outlook is put back by
 *    the next check (every 15 minutes, or Sync now);
 *  - only events ITFlow made are touched, never the person's own appointments;
 *  - those appointments are copied the other way as busy times (mit_outlook_busy, M005) every
 *    MIT_OUTLOOK_BUSY_MINUTES - shown on the ITFlow calendar and counted when booking work.
 *
 * Tables from database update M004: mit_outlook_links (one per person) and mit_outlook_events
 * (one per booked ticket per person). The refresh token is encrypted with a key made from this
 * server's config.php (mitEncryptSecret), so a copy of the database alone does not give access to
 * anyone's calendar.
 */

/** Bookings that ended longer ago than this are left alone: not added, and an event already there is kept. */
const MIT_OUTLOOK_PAST_DAYS = 30;
/** How often Outlook itself is checked for events changed or deleted there. */
const MIT_OUTLOOK_CHECK_MINUTES = 15;
const MIT_OUTLOOK_CATEGORY = 'ITFlow';
/** Outlook busy times (database update M005): how often they are copied, and how far back and ahead. */
const MIT_OUTLOOK_BUSY_MINUTES = 5;
const MIT_OUTLOOK_BUSY_PAST_DAYS = 7;
const MIT_OUTLOOK_BUSY_AHEAD_DAYS = 56;
const MIT_OUTLOOK_SCOPES = 'offline_access openid profile email https://graph.microsoft.com/Calendars.ReadWrite https://graph.microsoft.com/User.Read';

/*
 * ---------- the person's link ----------
 */

/** A person's Outlook link, or null. */
function mitOutlookLink($user_id) {
    global $mysqli;
    $user_id = intval($user_id);
    try {
        $result = mysqli_query($mysqli, "SELECT user_id, account_email, account_name, refresh_token, access_token, access_expires_at, connected_at,
            last_sync_at, last_check_at, sync_signature, last_error, busy_refreshed_at FROM mit_outlook_links WHERE user_id = $user_id LIMIT 1");
    } catch (Throwable $e) {
        return null;  // database update pending
    }
    return $result ? mysqli_fetch_assoc($result) : null;
}

/**
 * Is this agent's Outlook synced and working? Then booking emails to them go without the calendar invite
 * (functions/ticket.php) - a link with a sync error still gets the invite, so nothing is missed.
 */
function mitOutlookSyncsEmail($email) {
    global $mysqli;
    try {
        $email = mysqli_real_escape_string($mysqli, (string) $email);
        $result = mysqli_query($mysqli, "SELECT 1 FROM mit_outlook_links JOIN users USING (user_id) WHERE user_email = '$email' AND user_archived_at IS NULL AND user_status = 1 AND last_error IS NULL LIMIT 1");
        return $result && mysqli_num_rows($result) > 0 && mitOutlookAvailable();
    } catch (Throwable $e) {
        return false;  // our tables not there yet (database update pending) - ITFlow as usual
    }
}

/** Can people connect Outlook here? Needs ITFlow's Microsoft 365 app (Admin > Settings > Mail). */
function mitOutlookAvailable() {
    return mitM365App() !== null;
}

/** Remember a problem with the person's sync, shown on their Outlook calendar page. */
function mitOutlookSetError($user_id, $error) {
    global $mysqli;
    $user_id = intval($user_id);
    $error_sql = $error === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, substr((string) $error, 0, 1000)) . "'";
    mysqli_query($mysqli, "UPDATE mit_outlook_links SET last_error = $error_sql WHERE user_id = $user_id");
}

/*
 * ---------- signing in ----------
 */

/** The app people's Outlook sign-ins go through: the calendar-only one once it is set up, else ITFlow's main app. */
function mitOutlookClientId() {
    $calendar = mitM365CalendarApp();
    return $calendar ? $calendar['client_id'] : mitM365App()['client_id'];
}

/** The token address for the app's tenant (call only when mitOutlookAvailable()). */
function mitOutlookTokenUrl() {
    return MIT_M365_LOGIN . '/' . rawurlencode(mitM365App()['tenant_id']) . '/oauth2/v2.0/token';
}

/** Where to send the person to sign in to Microsoft. */
function mitOutlookAuthorizeUrl($state) {
    $app = mitM365App();
    return MIT_M365_LOGIN . '/' . rawurlencode($app['tenant_id']) . '/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => mitOutlookClientId(),
        'response_type' => 'code',
        'redirect_uri' => mitM365RedirectUris()[1],
        'response_mode' => 'query',
        'scope' => MIT_OUTLOOK_SCOPES,
        'state' => $state,
        'prompt' => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Back from Microsoft with a code: save the person's link. Throws with what went wrong. */
function mitOutlookConnect($user_id, $code) {
    global $mysqli;
    if (!mitOutlookAvailable()) {
        throw new RuntimeException('Microsoft 365 is no longer set up in ITFlow - ask an admin (Admin > Settings > Mail).');
    }
    $response = mitHttp('POST', mitOutlookTokenUrl(), [], mitMicrosoftTokenFields(mitOutlookTokenUrl(), [
        'client_id' => mitOutlookClientId(),
        'client_secret' => MIT_M365_CERT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => mitM365RedirectUris()[1],
        'scope' => MIT_OUTLOOK_SCOPES,
    ]));
    if (empty($response['json']['access_token']) || empty($response['json']['refresh_token'])) {
        throw new RuntimeException(mitM365Error($response, 'Signing in to Outlook'));
    }
    $access = $response['json']['access_token'];
    $me = mitGraph('GET', '/me?$select=displayName,mail,userPrincipalName', $access);
    if ($me['status'] !== 200) {
        throw new RuntimeException(mitM365Error($me, 'Reading your Microsoft 365 account'));
    }
    $email = (string) ($me['json']['mail'] ?? '') ?: (string) ($me['json']['userPrincipalName'] ?? '');

    $user_id = intval($user_id);
    $email_sql = mysqli_real_escape_string($mysqli, $email);
    $name_sql = mysqli_real_escape_string($mysqli, (string) ($me['json']['displayName'] ?? ''));
    $refresh_sql = mysqli_real_escape_string($mysqli, mitEncryptSecret($response['json']['refresh_token']));
    $access_sql = mysqli_real_escape_string($mysqli, mitEncryptSecret($access));
    $expires_sql = date('Y-m-d H:i:s', time() + intval($response['json']['expires_in'] ?? 3600));

    return mitOutlookWithLock($user_id, function () use ($mysqli, $user_id, $email, $email_sql, $name_sql, $refresh_sql, $access_sql, $expires_sql) {
        // A different Microsoft account than before: its events and busy times are not this one's
        $old = mitOutlookLink($user_id);
        if ($old && strcasecmp($old['account_email'], $email) !== 0) {
            mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE user_id = $user_id");
            mysqli_query($mysqli, "DELETE FROM mit_outlook_busy WHERE user_id = $user_id");
        }
        mysqli_query($mysqli, "INSERT INTO mit_outlook_links SET user_id = $user_id, account_email = '$email_sql', account_name = '$name_sql',
            refresh_token = '$refresh_sql', access_token = '$access_sql', access_expires_at = '$expires_sql', connected_at = NOW(), sync_signature = '', last_error = NULL
            ON DUPLICATE KEY UPDATE account_email = VALUES(account_email), account_name = VALUES(account_name), refresh_token = VALUES(refresh_token),
            access_token = VALUES(access_token), access_expires_at = VALUES(access_expires_at), connected_at = NOW(), sync_signature = '', last_error = NULL");
        mitDeleteSetting("outlook_failed_at_$user_id");
        return mitOutlookLink($user_id);
    });
}

/** A Graph token for the person's calendar - refreshed when needed. Throws when Microsoft refuses. */
function mitOutlookToken(array $link) {
    global $mysqli;
    if (!empty($link['access_expires_at']) && strtotime($link['access_expires_at']) - 120 > time()) {
        $access = mitDecryptSecret($link['access_token']);
        if ($access !== '') {
            return $access;
        }
    }
    if (!mitOutlookAvailable()) {
        throw new RuntimeException('ITFlow\'s Microsoft 365 app has been removed - ask an admin to set it up again under Admin > Settings > Mail.');
    }
    if ((string) $link['refresh_token'] === '') {
        throw new RuntimeException('Click Reconnect to sign in to Outlook again - ITFlow now connects Outlook calendars through a calendar-only app.');
    }
    $refresh = mitDecryptSecret($link['refresh_token']);
    if ($refresh === '') {
        throw new RuntimeException('This server cannot read your saved Outlook sign-in (ITFlow was restored onto another server, or config.php changed) - click Reconnect.');
    }
    $response = mitHttp('POST', mitOutlookTokenUrl(), [], mitMicrosoftTokenFields(mitOutlookTokenUrl(), [
        'client_id' => mitOutlookClientId(),
        'client_secret' => MIT_M365_CERT_SECRET,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh,
        'scope' => MIT_OUTLOOK_SCOPES,
    ]));
    if (empty($response['json']['access_token'])) {
        $error = mitM365Error($response, 'Signing in to Outlook');
        if (($response['json']['error'] ?? '') === 'invalid_grant') {
            $error = 'Microsoft asked for you to sign in again - click Reconnect. (' . $error . ')';
        }
        throw new RuntimeException($error);
    }
    $user_id = intval($link['user_id']);
    $access_sql = mysqli_real_escape_string($mysqli, mitEncryptSecret($response['json']['access_token']));
    $expires_sql = date('Y-m-d H:i:s', time() + intval($response['json']['expires_in'] ?? 3600));
    $refresh_set = '';
    if (!empty($response['json']['refresh_token'])) {
        $refresh_set = ", refresh_token = '" . mysqli_real_escape_string($mysqli, mitEncryptSecret($response['json']['refresh_token'])) . "'";
    }
    mysqli_query($mysqli, "UPDATE mit_outlook_links SET access_token = '$access_sql', access_expires_at = '$expires_sql' $refresh_set WHERE user_id = $user_id");
    return $response['json']['access_token'];
}

/*
 * ---------- what should be in the calendar ----------
 */

/**
 * The person's open booked tickets - as the lead or helping - ticket_id => row: those that ended within the last
 * MIT_OUTLOOK_PAST_DAYS, or within $past_days when given (to tell an old booking from a cancelled one).
 */
function mitOutlookBookings($user_id, $past_days = null) {
    global $mysqli;
    $user_id = intval($user_id);
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $past_days = intval($past_days ?? MIT_OUTLOOK_PAST_DAYS);
    $result = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_schedule, ticket_schedule_end, ticket_onsite,
            client_name, contact_name, contact_phone, contact_mobile, location_name, location_address, location_city, location_state, location_zip
        FROM tickets
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN contacts ON contact_id = ticket_contact_id
        LEFT JOIN locations ON location_id = IF(ticket_location_id > 0, ticket_location_id, contact_location_id)
        WHERE ticket_assigned_to = $user_id AND ticket_schedule IS NOT NULL
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND COALESCE(ticket_schedule_end, DATE_ADD(ticket_schedule, INTERVAL $default_minutes MINUTE)) >= DATE_SUB(NOW(), INTERVAL $past_days DAY)
        ORDER BY ticket_schedule");
    $bookings = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $bookings[intval($row['ticket_id'])] = $row;
    }
    // And the tickets they are helping on, at their own time or the lead's (functions/mit_ticket_techs.php)
    return $bookings + mitTicketTechOutlookBookings($user_id, $past_days);
}

/** The Outlook event for a booking. Times go to Microsoft in UTC. */
function mitOutlookEvent(array $row) {
    $start = strtotime($row['ticket_schedule']);
    $end = strtotime(mitTicketScheduleEnd($row['ticket_schedule'], $row['ticket_schedule_end']));
    $reference = ticketReference($row);
    $client = trim((string) $row['client_name']);
    $onsite = intval($row['ticket_onsite']) === 1;

    $address = trim(implode(', ', array_filter([$row['location_address'], $row['location_city'], trim($row['location_state'] . ' ' . $row['location_zip'])])), ', ');
    $where = $onsite ? trim(($client !== '' ? "Onsite - $client" : 'Onsite') . ($address !== '' ? ", $address" : '')) : 'Remote';
    $phone = $row['contact_mobile'] ?: $row['contact_phone'];

    $lines = [];
    $lines[] = '<a href="' . escapeHtml(mitM365BaseUrl() . '/agent/ticket.php?ticket_id=' . intval($row['ticket_id'])) . '">Open ticket ' . escapeHtml($reference) . ' in ITFlow</a>';
    if ($client !== '') {
        $lines[] = 'Client: ' . escapeHtml($client);
    }
    if (!empty($row['contact_name'])) {
        $lines[] = 'Contact: ' . escapeHtml($row['contact_name']) . ($phone ? ' - ' . escapeHtml($phone) : '');
    }
    $lines[] = escapeHtml($where);
    $lines[] = '<small>Scheduled in ITFlow - change it there; changes made here are put back.</small>';

    return [
        'subject' => $reference . ' - ' . $row['ticket_subject'] . ($client !== '' ? " ($client)" : ''),
        'body' => ['contentType' => 'HTML', 'content' => '<p>' . implode('<br>', $lines) . '</p>'],
        'start' => ['dateTime' => gmdate('Y-m-d\TH:i:s', $start), 'timeZone' => 'UTC'],
        'end' => ['dateTime' => gmdate('Y-m-d\TH:i:s', $end), 'timeZone' => 'UTC'],
        'location' => ['displayName' => $where],
        'categories' => [MIT_OUTLOOK_CATEGORY],
        'showAs' => 'busy',
        'isReminderOn' => true,
        'reminderMinutesBeforeStart' => 15,
    ];
}

/** Does the event in Outlook still match what ITFlow made? (The parts people would change.) */
function mitOutlookEventMatches(array $want, array $have) {
    $time = function ($value) {
        return substr((string) ($value['dateTime'] ?? ''), 0, 19);
    };
    return (string) ($have['subject'] ?? '') === $want['subject']
        && $time($have['start'] ?? []) === $want['start']['dateTime']
        && $time($have['end'] ?? []) === $want['end']['dateTime']
        && (string) ($have['location']['displayName'] ?? '') === $want['location']['displayName']
        && empty($have['isCancelled']);
}

/*
 * ---------- the sync ----------
 */

/**
 * Bring a person's Outlook calendar in line with their bookings. Changed bookings are sent every
 * time; Outlook itself is checked for edits and deletions every MIT_OUTLOOK_CHECK_MINUTES, or now
 * with $check. Returns a short summary; problems are kept on the link (last_error) and thrown.
 */
function mitOutlookSync($user_id, $check = false) {
    global $mysqli;
    $user_id = intval($user_id);
    // One sync per person at a time - Sync now and the cron job together would add events twice
    $lock = "mit_outlook_sync_$user_id";
    $got = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock', 20)"));
    if (!$got || intval($got[0]) !== 1) {
        return 'Already syncing';
    }
    try {
        return mitOutlookSyncLocked($user_id, $check);
    } finally {
        mysqli_query($mysqli, "DO RELEASE_LOCK('$lock')");
    }
}

/**
 * The sync itself, under the person's lock (mitOutlookSync): push new, changed and cancelled bookings,
 * and when $check (or the 15-minute check is due) read back events changed in Outlook. Returns a summary.
 */
function mitOutlookSyncLocked($user_id, $check) {
    global $mysqli;
    $link = mitOutlookLink($user_id);
    if (!$link) {
        return 'Not connected';
    }
    $forced = $check;  // asked for now (Sync now) - $check itself may be turned on below when the 15-minute check is due

    // After a failure, try again every MIT_OUTLOOK_CHECK_MINUTES rather than every minute (Sync now always tries)
    $failed_at = mitSetting("outlook_failed_at_$user_id", '');
    if (!$forced && !empty($link['last_error']) && $failed_at !== '' && strtotime($failed_at) > time() - MIT_OUTLOOK_CHECK_MINUTES * 60) {
        return 'Waiting to retry';
    }

    $want = [];
    foreach (mitOutlookBookings($user_id) as $ticket_id => $row) {
        $event = mitOutlookEvent($row);
        $want[$ticket_id] = ['event' => $event, 'hash' => sha1(json_encode($event))];
    }
    $signature = sha1(json_encode(array_map(function ($w) { return $w['hash']; }, $want)));
    $busy_due = $check || empty($link['busy_refreshed_at']) || strtotime($link['busy_refreshed_at']) <= time() - MIT_OUTLOOK_BUSY_MINUTES * 60;
    $check = $check || empty($link['last_check_at']) || strtotime($link['last_check_at']) <= time() - MIT_OUTLOOK_CHECK_MINUTES * 60;
    if (mitDevMode()) {
        // Development mode: busy times are read in, nothing is written to the tech's real calendar
        if (!$busy_due) {
            return 'Up to date';
        }
        try {
            mitOutlookRefreshBusy(mitOutlookToken($link), $user_id);
        } catch (Throwable $e) {
            mitOutlookSetError($user_id, $e->getMessage());
            mitSetSetting("outlook_failed_at_$user_id", date('Y-m-d H:i:s'));
            throw $e;
        }
        mitDeleteSetting("outlook_failed_at_$user_id");
        mysqli_query($mysqli, "UPDATE mit_outlook_links SET last_error = NULL WHERE user_id = " . intval($user_id));
        return 'Busy times read (development mode)';
    }
    if (!$check && !$busy_due && $signature === $link['sync_signature'] && empty($link['last_error'])) {
        return 'Up to date';
    }

    $have = [];
    $result = mysqli_query($mysqli, "SELECT ticket_id, event_id, event_hash FROM mit_outlook_events WHERE user_id = $user_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $have[intval($row['ticket_id'])] = $row;
    }

    $counts = ['added' => 0, 'updated' => 0, 'removed' => 0, 'restored' => 0];
    $problems = [];
    // One booking Outlook will not take must not hold up the others
    $each = function ($ticket_id, callable $step) use (&$problems) {
        try {
            $step();
        } catch (Throwable $e) {
            $problems[] = "Ticket #$ticket_id: " . $e->getMessage();
        }
    };
    try {
        $token = mitOutlookToken($link);

        // What Outlook has now for the events that should be unchanged
        $outlook = [];
        if ($check) {
            $ids = [];
            foreach ($have as $ticket_id => $row) {
                if (isset($want[$ticket_id]) && $want[$ticket_id]['hash'] === $row['event_hash']) {
                    $ids[$ticket_id] = $row['event_id'];
                }
            }
            $outlook = mitOutlookFetchEvents($token, $ids);
        }

        foreach ($want as $ticket_id => $w) {
            $row = $have[$ticket_id] ?? null;
            $each($ticket_id, function () use ($token, $user_id, $ticket_id, $w, $row, $check, $outlook, &$counts) {
                if (!$row) {
                    mitOutlookCreate($token, $user_id, $ticket_id, $w);
                    $counts['added']++;
                } elseif ($row['event_hash'] !== $w['hash']) {
                    mitOutlookUpdate($token, $user_id, $ticket_id, $row['event_id'], $w) ? $counts['updated']++ : $counts['added']++;
                } elseif ($check && array_key_exists($ticket_id, $outlook)) {
                    if ($outlook[$ticket_id] === null) {
                        mitOutlookCreate($token, $user_id, $ticket_id, $w);  // deleted in Outlook
                        $counts['restored']++;
                    } elseif (!mitOutlookEventMatches($w['event'], $outlook[$ticket_id])) {
                        mitOutlookUpdate($token, $user_id, $ticket_id, $row['event_id'], $w);  // changed in Outlook
                        $counts['restored']++;
                    }
                }
            });
        }
        $old = null;  // bookings still in place but older than the window: their events stay
        foreach ($have as $ticket_id => $row) {
            if (!isset($want[$ticket_id])) {
                $old = $old ?? mitOutlookBookings($user_id, 3650);
                if (isset($old[$ticket_id])) {
                    continue;
                }
                $each($ticket_id, function () use ($token, $user_id, $ticket_id, $row, &$counts) {
                    global $mysqli;
                    mitOutlookDelete($token, $row['event_id']);
                    mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE user_id = $user_id AND ticket_id = " . intval($ticket_id));
                    $counts['removed']++;
                });
            }
        }

        if ($busy_due) {
            $busy = mitOutlookRefreshBusy($token, $user_id);
        }
    } catch (Throwable $e) {
        $problems[] = $e->getMessage();
    }

    if ($problems) {
        mitOutlookSetError($user_id, $problems[0] . (count($problems) > 1 ? ' (and ' . (count($problems) - 1) . ' more)' : ''));
        mitSetSetting("outlook_failed_at_$user_id", date('Y-m-d H:i:s'));
        throw new RuntimeException(implode('; ', array_slice($problems, 0, 3)));
    }
    mitDeleteSetting("outlook_failed_at_$user_id");

    $signature_sql = mysqli_real_escape_string($mysqli, $signature);
    mysqli_query($mysqli, "UPDATE mit_outlook_links SET last_sync_at = NOW(), sync_signature = '$signature_sql', last_error = NULL"
        . ($check ? ", last_check_at = NOW()" : '') . " WHERE user_id = $user_id");

    $parts = [];
    foreach ($counts as $what => $count) {
        if ($count) {
            $parts[] = "$count $what";
        }
    }
    if (isset($busy) && $check) {
        $parts[] = "$busy busy time" . ($busy === 1 ? '' : 's') . " from Outlook";
    }
    return $parts ? implode(', ', $parts) : 'Up to date';
}

/** Run $work while holding the person's sync lock (connect, disconnect and the sync never overlap). */
function mitOutlookWithLock($user_id, callable $work) {
    global $mysqli;
    $lock = 'mit_outlook_sync_' . intval($user_id);
    $got = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$lock', 20)"));
    if (!$got || intval($got[0]) !== 1) {
        throw new RuntimeException('Your Outlook calendar is busy syncing - try again in a moment.');
    }
    try {
        return $work();
    } finally {
        mysqli_query($mysqli, "DO RELEASE_LOCK('$lock')");
    }
}

/*
 * ---------- Outlook busy times -> ITFlow ----------
 */

/**
 * Copy the person's own Outlook appointments (not ITFlow's events, not free or cancelled ones)
 * into mit_outlook_busy, replacing the last copy. Returns how many.
 */
function mitOutlookRefreshBusy($token, $user_id) {
    global $mysqli;
    $user_id = intval($user_id);

    // ITFlow's own events in this calendar - they are bookings already, not busy time
    $ours = [];
    $result = mysqli_query($mysqli, "SELECT event_id FROM mit_outlook_events WHERE user_id = $user_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $ours[$row['event_id']] = true;
    }

    $from = gmdate('Y-m-d\TH:i:s\Z', strtotime('-' . MIT_OUTLOOK_BUSY_PAST_DAYS . ' days midnight'));
    $to = gmdate('Y-m-d\TH:i:s\Z', strtotime('+' . MIT_OUTLOOK_BUSY_AHEAD_DAYS . ' days midnight'));
    $url = MIT_M365_GRAPH . '/me/calendarView?' . http_build_query([
        'startDateTime' => $from, 'endDateTime' => $to, '$top' => 250,
        '$select' => 'id,iCalUId,subject,start,end,isAllDay,showAs,sensitivity,isCancelled,categories',
    ], '', '&', PHP_QUERY_RFC3986);

    $rows = [];
    for ($page = 0; $url && $page < 20; $page++) {
        $response = mitHttp('GET', $url, ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Prefer: outlook.timezone="UTC"']);
        if ($response['status'] !== 200) {
            throw new RuntimeException(mitM365Error($response, 'Reading your Outlook calendar'));
        }
        foreach ($response['json']['value'] ?? [] as $event) {
            $show_as = strtolower((string) ($event['showAs'] ?? 'busy'));
            // Not busy time: ITFlow's own events, and invites ITFlow emailed for a ticket booking (ticket-<id>@..., a helper's ticket-<id>-tech-<user>@...)
            if (isset($ours[$event['id'] ?? '']) || in_array(MIT_OUTLOOK_CATEGORY, $event['categories'] ?? [], true)
                || preg_match('/^ticket-\d+(-tech-\d+)?@/', (string) ($event['iCalUId'] ?? ''))
                || !empty($event['isCancelled']) || !in_array($show_as, ['busy', 'tentative', 'oof'], true)) {
                continue;
            }
            $start_raw = substr((string) ($event['start']['dateTime'] ?? ''), 0, 19);
            $end_raw = substr((string) ($event['end']['dateTime'] ?? ''), 0, 19);
            if (!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $start_raw) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $end_raw)) {
                continue;
            }
            if (!empty($event['isAllDay'])) {
                // All-day events have no time zone - just the days
                $starts = substr($start_raw, 0, 10) . ' 00:00:00';
                $ends = substr($end_raw, 0, 10) . ' 00:00:00';
            } else {
                $starts = date('Y-m-d H:i:s', strtotime("$start_raw UTC"));
                $ends = date('Y-m-d H:i:s', strtotime("$end_raw UTC"));
            }
            if (strtotime($ends) <= strtotime($starts)) {
                continue;
            }
            // A private or confidential event's subject is never kept, even for its owner
            $private = in_array(strtolower((string) ($event['sensitivity'] ?? 'normal')), ['private', 'confidential'], true);
            $rows[] = "($user_id, '$starts', '$ends', " . (empty($event['isAllDay']) ? 0 : 1) . ", '"
                . ($private ? '' : mysqli_real_escape_string($mysqli, mb_substr((string) ($event['subject'] ?? ''), 0, 255))) . "', "
                . ($private ? 1 : 0) . ", '$show_as')";
        }
        $url = $response['json']['@odata.nextLink'] ?? null;
        // Only ever follow Graph's own paging links with the token
        if ($url !== null && strpos($url, MIT_M365_GRAPH . '/') !== 0) {
            throw new RuntimeException('Outlook sent an unexpected paging address.');
        }
    }
    // More pages than we read: a partial copy would drop busy times - keep the old one instead
    if (!empty($url)) {
        throw new RuntimeException('More Outlook appointments than ITFlow reads at once (20 pages) - busy times were left as they were.');
    }

    // Swap the old copy for the new one in one go; on any failure keep the old copy and pass the error on
    mysqli_begin_transaction($mysqli);
    try {
        mysqli_query($mysqli, "DELETE FROM mit_outlook_busy WHERE user_id = $user_id");
        foreach (array_chunk($rows, 200) as $chunk) {
            mysqli_query($mysqli, "INSERT INTO mit_outlook_busy (user_id, starts_at, ends_at, is_all_day, subject, is_private, show_as) VALUES " . implode(', ', $chunk));
        }
        mysqli_query($mysqli, "UPDATE mit_outlook_links SET busy_refreshed_at = NOW() WHERE user_id = $user_id");
        mysqli_commit($mysqli);
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
    return count($rows);
}

/** What a busy time says on the calendar: the person sees their own titles, everyone else Busy. */
function mitOutlookBusyTitle(array $row) {
    global $session_user_id;
    $label = ['tentative' => 'Tentative', 'oof' => 'Out of office'][$row['show_as']] ?? 'Busy';
    if (intval($row['user_id']) === intval($session_user_id) && !intval($row['is_private']) && trim($row['subject']) !== '') {
        return $row['subject'];
    }
    return $label;
}

/** The calendar source, for functions/calendar.php calendarSources() - only once someone has connected Outlook. */
function mitOutlookCalendarSources() {
    global $mysqli;
    if (!mitOutlookAvailable()) {
        return [];
    }
    try {
        $result = mysqli_query($mysqli, "SELECT 1 FROM mit_outlook_links LIMIT 1");
    } catch (Throwable $e) {
        return [];  // database update pending
    }
    if (!$result || !mysqli_num_rows($result)) {
        return [];
    }
    return ['outlook_busy' => ['label' => 'Outlook busy times', 'icon' => 'fa-calendar-alt', 'color' => '#6c757d', 'module' => 'module_support', 'default' => true,
        'title' => 'Appointments in each agent\'s own Outlook calendar (connected under Account > Outlook calendar)']];
}

/** Busy times between two datetimes, ready for FullCalendar. Not on a client's calendar. */
function mitOutlookBusyItems($from, $to, array $opts = []) {
    global $mysqli;
    if (intval($opts['client_id'] ?? 0)) {
        return [];
    }
    $from = escapeSql($from);
    $to = escapeSql($to);
    $user_id = intval($opts['user_id'] ?? 0);
    $items = [];
    $result = mysqli_query($mysqli, "SELECT busy_id, user_id, starts_at, ends_at, is_all_day, subject, is_private, show_as, user_name FROM mit_outlook_busy JOIN users USING (user_id)
        WHERE starts_at < '$to' AND ends_at > '$from' AND user_archived_at IS NULL" . ($user_id ? " AND user_id = $user_id" : '') . "
        ORDER BY starts_at");
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $all_day = intval($row['is_all_day']) === 1;
        $items[] = [
            'id' => 'outlook-' . intval($row['busy_id']),
            'title' => mitOutlookBusyTitle($row),
            'start' => $all_day ? substr($row['starts_at'], 0, 10) : $row['starts_at'],
            'end' => $all_day ? substr($row['ends_at'], 0, 10) : $row['ends_at'],
            'allDay' => $all_day,
            'editable' => false,
            'color' => mitAgentColor($row['user_id']),
            'extendedProps' => ['kind' => 'outlook_busy', 'icon' => 'fa-calendar-alt', 'detail' => $row['user_name'] . ' · Outlook'],
        ];
    }
    return $items;
}

/** The agent's Outlook busy times overlapping $start - $end, as plain text lines (functions/mit_calendar.php mitScheduleConflicts). */
function mitOutlookBusyConflicts($user_id, $start, $end) {
    global $mysqli;
    $user_id = intval($user_id);
    $start = escapeSql($start);
    $end = escapeSql($end);
    $lines = [];
    try {
        $result = mysqli_query($mysqli, "SELECT user_id, starts_at, ends_at, is_all_day, subject, is_private, show_as FROM mit_outlook_busy WHERE user_id = $user_id AND starts_at < '$end' AND ends_at > '$start' ORDER BY starts_at");
    } catch (Throwable $e) {
        return [];  // database update pending
    }
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $lines[] = 'Outlook: ' . mitOutlookBusyTitle($row) . ' @ ' . (intval($row['is_all_day'])
            ? 'all day'
            : date('g:ia', strtotime($row['starts_at'])) . '-' . date('g:ia', strtotime($row['ends_at'])));
    }
    return $lines;
}

/** Events by ticket id as Outlook has them now (null = gone), 20 to a Graph batch. */
function mitOutlookFetchEvents($token, array $event_ids) {
    $found = [];
    foreach (array_chunk($event_ids, 20, true) as $chunk) {
        $requests = [];
        foreach ($chunk as $ticket_id => $event_id) {
            $requests[] = ['id' => (string) $ticket_id, 'method' => 'GET',
                'url' => '/me/events/' . rawurlencode($event_id) . '?$select=subject,start,end,location,isCancelled',
                'headers' => ['Prefer' => 'outlook.timezone="UTC"']];
        }
        $response = mitGraph('POST', '/$batch', $token, ['requests' => $requests]);
        if ($response['status'] !== 200) {
            throw new RuntimeException(mitM365Error($response, 'Checking your Outlook calendar'));
        }
        foreach ($response['json']['responses'] ?? [] as $answer) {
            $status = intval($answer['status'] ?? 0);
            if ($status === 200) {
                $found[intval($answer['id'])] = $answer['body'] ?? [];
            } elseif ($status === 404) {
                $found[intval($answer['id'])] = null;
            }
            // anything else (throttled, a hiccup): leave it for the next check
        }
    }
    return $found;
}

/** Remember which Outlook event is a person's copy of a booking, and what it last held ($hash). */
function mitOutlookSaveEvent($user_id, $ticket_id, $event_id, $hash) {
    global $mysqli;
    $user_id = intval($user_id);
    $ticket_id = intval($ticket_id);
    $event_id_sql = mysqli_real_escape_string($mysqli, $event_id);
    $hash_sql = mysqli_real_escape_string($mysqli, $hash);
    mysqli_query($mysqli, "INSERT INTO mit_outlook_events SET user_id = $user_id, ticket_id = $ticket_id, event_id = '$event_id_sql', event_hash = '$hash_sql'
        ON DUPLICATE KEY UPDATE event_id = VALUES(event_id), event_hash = VALUES(event_hash)");
}

/** Create the Outlook event for a booking ($w from mitOutlookEvent) and remember it. Returns the event id. */
function mitOutlookCreate($token, $user_id, $ticket_id, array $w) {
    // The same id for a retry within ten minutes: if Microsoft made the event but the answer was lost, it is not made twice
    $id = substr(sha1("$user_id|$ticket_id|{$w['hash']}|" . intdiv(time(), 600)), 0, 32);
    $transaction = vsprintf('%s-%s-%s-%s-%s', [substr($id, 0, 8), substr($id, 8, 4), substr($id, 12, 4), substr($id, 16, 4), substr($id, 20, 12)]);
    $created = mitGraph('POST', '/me/events', $token, $w['event'] + ['transactionId' => $transaction]);
    if ($created['status'] !== 201 || empty($created['json']['id'])) {
        throw new RuntimeException(mitM365Error($created, 'Adding a booking to Outlook'));
    }
    mitOutlookSaveEvent($user_id, $ticket_id, $created['json']['id'], $w['hash']);
}

/** Change an event; true when changed, false when it had been deleted in Outlook and was added again. */
function mitOutlookUpdate($token, $user_id, $ticket_id, $event_id, array $w) {
    $patched = mitGraph('PATCH', '/me/events/' . rawurlencode($event_id), $token, $w['event']);
    if ($patched['status'] === 404) {
        mitOutlookCreate($token, $user_id, $ticket_id, $w);
        return false;
    }
    if ($patched['status'] !== 200) {
        throw new RuntimeException(mitM365Error($patched, 'Changing a booking in Outlook'));
    }
    mitOutlookSaveEvent($user_id, $ticket_id, $event_id, $w['hash']);
    return true;
}

/** Delete an Outlook event (already gone counts as done). */
function mitOutlookDelete($token, $event_id) {
    $deleted = mitGraph('DELETE', '/me/events/' . rawurlencode($event_id), $token);
    if ($deleted['status'] !== 204 && $deleted['status'] !== 404) {
        throw new RuntimeException(mitM365Error($deleted, 'Removing a booking from Outlook'));
    }
}

/** Disconnect: take ITFlow's events out of the person's Outlook (as far as Microsoft lets us) and forget the link. */
function mitOutlookDisconnect($user_id) {
    global $mysqli;
    $user_id = intval($user_id);
    return mitOutlookWithLock($user_id, function () use ($mysqli, $user_id) {
        $link = mitOutlookLink($user_id);
        $left = 0;
        if ($link) {
            try {
                $token = mitOutlookToken($link);
                $result = mysqli_query($mysqli, "SELECT event_id FROM mit_outlook_events WHERE user_id = $user_id");
                while ($row = mysqli_fetch_assoc($result)) {
                    try {
                        mitOutlookDelete($token, $row['event_id']);
                    } catch (Throwable $e) {
                        $left++;
                    }
                }
            } catch (Throwable $e) {
                $left = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_outlook_events WHERE user_id = $user_id"))[0]);
            }
        }
        mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE user_id = $user_id");
        mysqli_query($mysqli, "DELETE FROM mit_outlook_busy WHERE user_id = $user_id");
        mysqli_query($mysqli, "DELETE FROM mit_outlook_links WHERE user_id = $user_id");
        mitDeleteSetting("outlook_failed_at_$user_id");
        return $left;
    });
}
