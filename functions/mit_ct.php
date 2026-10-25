<?php

/*
 * Mountaineer IT - Customer Thermometer: a satisfaction survey for each resolved ticket, and the scores that come back.
 * Required from functions.php. Table mit_ct_surveys (M036); settings mit_settings ct_* (the API key via mitEncryptSecret).
 *   - Sending: cron job mit_customer_thermometer (every 5 min, mitCtRun) picks tickets resolved (or closed without being resolved)
 *     on or after ct_start, on one of the ct_boards service boards, whose contact has an email address, and that have no survey
 *     yet - and asks Customer Thermometer to email that contact the chosen thermometer (API sendEmail: thermometerID, listID,
 *     the contact, custom1 = the tech - the ticket's lead -, custom2 = the ticket number, custom3 = its subject). With no blastID
 *     Customer Thermometer makes a one-person blast and returns its id - kept, it is how the response finds its ticket again.
 *   - Responses: getBlastResults / getComments from the oldest unanswered survey's day (every 15 min), XML read generically
 *     (Customer Thermometer does not publish its element names - mitCtXmlRecords / mitCtField; the last raw answer is kept as
 *     ct_last_sample and shown in Admin), matched by blast id, else by the ticket number in custom2.
 *   - Scores: Happiness = Gold + Green responses as a share of all responses (Customer Thermometer's own Happiness Factor).
 *     Dashboard card (mitCtDashCard), client overview card (mitCtClientCard), list agent/mit_ct_reviews.php.
 * Admin > Integrations > Customer Thermometer (admin/mit_customer_thermometer.php; handlers admin/post/mit_customer_thermometer.php).
 * Development mode: every survey goes to the test inbox, not the customer (sendEmail is a GET, which the read-only gate lets by).
 * A test install may point at a stand-in with MIT_CT_API_BASE in config.php (never on a real one).
 */

const MIT_CT_TEMPERATURES = [
    1 => ['name' => 'Gold', 'color' => '#c99a06', 'icon' => 'fa-star'],
    2 => ['name' => 'Green', 'color' => '#2e9e4f', 'icon' => 'fa-smile'],
    3 => ['name' => 'Yellow', 'color' => '#e0a800', 'icon' => 'fa-meh'],
    4 => ['name' => 'Red', 'color' => '#d9534f', 'icon' => 'fa-frown'],
];
const MIT_CT_SEND_BATCH = 25;          // surveys sent per run at most
const MIT_CT_POLL_MINUTES = 15;        // how often responses are read
const MIT_CT_CLIENT_COMMENTS = 3;      // comments shown on the client overview - the rest on the responses page
const MIT_CT_SUMMARY_LENGTH = 100;     // custom3 (the ticket's subject) cut to this

/** Has M036 run? */
function mitCtReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ct_surveys'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function mitCtSettings() {
    $boards = array_values(array_filter(array_map('intval', explode(',', (string) mitSetting('ct_boards', '')))));
    return [
        'api_base' => defined('MIT_CT_API_BASE') ? MIT_CT_API_BASE : 'https://app.customerthermometer.com/api.php',
        'has_key' => (string) mitSetting('ct_api_key', '') !== '',
        'enabled' => mitSetting('ct_enabled', '0') === '1',
        'thermometer_id' => (string) mitSetting('ct_thermometer_id', ''),
        'list_id' => (string) mitSetting('ct_list_id', ''),
        'boards' => $boards,
        'start' => (string) mitSetting('ct_start', ''),
        'last_run' => (string) mitSetting('ct_last_run', ''),
        'last_result' => (string) mitSetting('ct_last_result', ''),
        'last_poll' => (string) mitSetting('ct_last_poll', ''),
    ];
}

/** Everything needed to send is set: '' or what is missing. */
function mitCtMissing() {
    $s = mitCtSettings();
    if (!$s['has_key']) {
        return 'the API key';
    }
    if ($s['thermometer_id'] === '' || $s['list_id'] === '') {
        return 'the thermometer and recipient list';
    }
    if (!$s['boards']) {
        return 'at least one service board';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['start'])) {
        return 'the start date';
    }
    return '';
}

/**
 * One API call: GET api.php?getMethod=...&apiKey=... Returns the body; throws with why not. A plain-text body that is not
 * XML or a number is Customer Thermometer's error message.
 */
function mitCtCall($method, array $params = [], $key = null) {
    $key = $key ?? mitDecryptSecret((string) mitSetting('ct_api_key', ''));
    if ($key === '') {
        throw new RuntimeException('Customer Thermometer is not set up - add the API key first.');
    }
    $url = mitCtSettings()['api_base'] . '?' . http_build_query(['getMethod' => $method, 'apiKey' => $key] + $params, '', '&', PHP_QUERY_RFC3986);
    $response = mitHttp('GET', $url, ['Accept: application/xml, text/plain'], null, 60);
    if ($response['status'] === 401 || $response['status'] === 403) {
        throw new RuntimeException('Customer Thermometer refused the API key.');
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException("Customer Thermometer $method failed: " . ($response['error'] ?: 'HTTP ' . $response['status']));
    }
    $body = trim($response['body']);
    if ($body !== '' && $body[0] !== '<' && !preg_match('/^-?\d+$/', $body)) {
        throw new RuntimeException("Customer Thermometer $method: " . mb_substr(strip_tags($body), 0, 200));
    }
    return $body;
}

/**
 * The records in an XML answer, each as [lowercased element name => text]: every element whose children are all plain values.
 * Customer Thermometer does not publish its element names, so nothing here depends on them.
 */
function mitCtXmlRecords($xml) {
    if (trim((string) $xml) === '' || ($xml = trim($xml))[0] !== '<') {
        return [];
    }
    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($previous);
    if ($doc === false) {
        return [];
    }
    $records = [];
    $walk = function (SimpleXMLElement $node) use (&$walk, &$records) {
        $children = $node->children();
        if (!count($children)) {
            return;
        }
        $flat = [];
        $leaves = true;
        foreach ($children as $name => $child) {
            if (count($child->children())) {
                $leaves = false;
                $walk($child);
            } else {
                $flat[strtolower((string) $name)] = trim((string) $child);
            }
        }
        foreach ($node->attributes() as $name => $value) {
            $flat[strtolower((string) $name)] = trim((string) $value);
        }
        if ($leaves && $flat) {
            $records[] = $flat;
        }
    };
    $walk($doc);
    return $records;
}

/** The first of $names a record has (compared without _ - and case), or ''. */
function mitCtField(array $record, array $names) {
    $plain = [];
    foreach ($record as $key => $value) {
        $plain[str_replace(['_', '-', ' '], '', $key)] = $value;
    }
    foreach ($names as $name) {
        $name = str_replace(['_', '-', ' '], '', strtolower($name));
        if (isset($plain[$name]) && $plain[$name] !== '') {
            return $plain[$name];
        }
    }
    return '';
}

/** The account's thermometers or recipient lists: [id => name]. */
function mitCtChoices($what, $key = null) {
    $method = $what === 'lists' ? 'getRecipientLists' : 'getThermometers';
    $id_fields = $what === 'lists' ? ['listid', 'recipientlistid', 'id'] : ['thermometerid', 'thermoid', 'id'];
    $choices = [];
    foreach (mitCtXmlRecords(mitCtCall($method, [], $key)) as $record) {
        $id = mitCtField($record, $id_fields);
        if ($id !== '') {
            $choices[$id] = mitCtField($record, ['name', 'listname', 'thermometername', 'title', 'description']) ?: "#$id";
        }
    }
    return $choices;
}

/** A temperature from Customer Thermometer (1-4 or a colour name) as 1-4, or 0. */
function mitCtTemperature($value) {
    $value = strtolower(trim((string) $value));
    if (preg_match('/^[1-4]$/', $value)) {
        return intval($value);
    }
    foreach (MIT_CT_TEMPERATURES as $id => $temperature) {
        if (strpos($value, strtolower($temperature['name'])) !== false) {
            return $id;
        }
    }
    return 0;
}

/*
 * ---------- sending ----------
 */

/** Tickets due a survey: resolved since the start date, on a chosen board, a contact with an email, no survey yet. */
function mitCtDueTickets($limit = MIT_CT_SEND_BATCH) {
    global $mysqli;
    $s = mitCtSettings();
    if (!$s['boards'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['start'])) {
        return [];
    }
    $boards = implode(',', $s['boards']);
    $start = escapeSql($s['start']);
    $done = "COALESCE(t.ticket_resolved_at, t.ticket_closed_at)";
    $result = mysqli_query($mysqli, "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_client_id, t.ticket_contact_id,
        t.ticket_assigned_to, c.contact_name, c.contact_email, cl.client_name, u.user_name, $done AS done_at
        FROM tickets t
        JOIN contacts c ON c.contact_id = t.ticket_contact_id
        JOIN clients cl ON cl.client_id = t.ticket_client_id
        LEFT JOIN users u ON u.user_id = t.ticket_assigned_to
        LEFT JOIN mit_ct_surveys s ON s.ticket_id = t.ticket_id
        WHERE s.survey_id IS NULL AND $done IS NOT NULL AND $done >= '$start' AND t.ticket_archived_at IS NULL
        AND c.contact_email != '' AND c.contact_archived_at IS NULL AND cl.client_archived_at IS NULL
        AND " . mitBoardSql('t') . " IN ($boards) AND " . (function_exists('mitNoResponseNotSql') ? mitNoResponseNotSql('t') : '1') . "
        ORDER BY done_at, t.ticket_id LIMIT " . intval($limit));
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/** Send one ticket's survey and record it. Returns sent | failed | skipped; throws when Customer Thermometer can't be reached. */
function mitCtSend(array $ticket) {
    global $mysqli;
    $s = mitCtSettings();
    $ticket_id = intval($ticket['ticket_id']);
    $ref = ticketReference($ticket);
    $tech = trim((string) ($ticket['user_name'] ?? '')) ?: 'Unassigned';
    $email = (string) $ticket['contact_email'];
    $status = 'sent';
    $blast = null;
    $error = null;

    // Development mode: to the test inbox only
    if (mitDevMode()) {
        $email = mitDevMailTo();
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $status = 'skipped';
        $error = mitDevMode() ? 'Development mode has no test inbox' : 'The contact\'s email address is not valid';
    } else {
        $name = preg_split('/\s+/', trim((string) $ticket['contact_name']), 2);
        $answer = mitCtCall('sendEmail', [
            'thermometerID' => $s['thermometer_id'],
            'listID' => $s['list_id'],
            'emailAddress' => $email,
            'firstName' => $name[0] ?? '',
            'lastName' => $name[1] ?? '',
            'companyName' => (string) $ticket['client_name'],
            'custom1' => $tech,
            'custom2' => $ref,
            'custom3' => mb_substr(trim((string) $ticket['ticket_subject']), 0, MIT_CT_SUMMARY_LENGTH),
        ]);
        if (preg_match('/^\d+$/', $answer) && intval($answer) > 0) {
            $blast = $answer;
        } else {
            $status = 'failed';
            $error = 'Customer Thermometer did not send it (out of send credits, the address unsubscribed, or a sending limit)';
        }
    }

    mysqli_query($mysqli, "INSERT INTO mit_ct_surveys SET ticket_id = $ticket_id, ticket_ref = '" . escapeSql($ref) . "',
        client_id = " . intval($ticket['ticket_client_id']) . ", contact_id = " . intval($ticket['ticket_contact_id']) . ",
        tech_user_id = " . intval($ticket['ticket_assigned_to']) . ", tech_name = '" . escapeSql($tech) . "', email = '" . escapeSql($email) . "',
        status = '$status', blast_id = " . ($blast === null ? 'NULL' : "'" . escapeSql($blast) . "'") . ",
        error = " . ($error === null ? 'NULL' : "'" . escapeSql($error) . "'"));
    if ($status === 'sent') {
        logTicketHistory($ticket_id, escapeSql("Customer Thermometer survey sent to $email"));
    }
    return $status;
}

/*
 * ---------- responses ----------
 */

/** Read responses since the oldest unanswered survey (last 60 days) and record them. Returns how many were new. */
function mitCtFetchResponses() {
    global $mysqli;
    $oldest = mysqli_fetch_row(mysqli_query($mysqli, "SELECT MIN(sent_at) FROM mit_ct_surveys WHERE status = 'sent' AND responded_at IS NULL
        AND sent_at >= NOW() - INTERVAL 60 DAY"))[0] ?? null;
    if (!$oldest) {
        mitSetSetting('ct_last_poll', date('Y-m-d H:i:s'));
        return 0;
    }
    $filter = ['fromDate' => date('Y-m-d', strtotime($oldest)), 'toDate' => date('Y-m-d', strtotime('+1 day')), 'limit' => 1000];
    $xml = mitCtCall('getBlastResults', $filter);
    mitSetSetting('ct_last_sample', mb_substr($xml, 0, 4000));
    $responses = [];
    foreach (mitCtXmlRecords($xml) as $record) {
        $responses[] = $record;
    }
    // Comments may only come with getComments - joined by blast
    $comments = [];
    try {
        foreach (mitCtXmlRecords(mitCtCall('getComments', $filter)) as $record) {
            $blast = mitCtField($record, ['blastid', 'blast']);
            $text = mitCtField($record, ['comment', 'comments', 'commenttext', 'text']);
            if ($blast !== '' && $text !== '') {
                $comments[$blast] = $text;
            }
        }
    } catch (Throwable $e) {
        // Comments are extra - the ratings still count
    }

    $new = 0;
    foreach ($responses as $record) {
        $temperature = mitCtTemperature(mitCtField($record, ['temperatureid', 'temperature', 'rating', 'temperaturename', 'response']));
        if (!$temperature) {
            continue;
        }
        $blast = mitCtField($record, ['blastid', 'blast']);
        $ref = mitCtField($record, ['custom2', 'customfield2']);
        $where = $blast !== '' ? "blast_id = '" . escapeSql($blast) . "'" : ($ref !== '' ? "ticket_ref = '" . escapeSql($ref) . "' AND status = 'sent'" : '');
        if ($where === '') {
            continue;
        }
        $survey = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT survey_id, ticket_id, temperature, comment FROM mit_ct_surveys WHERE $where ORDER BY survey_id DESC LIMIT 1"));
        if (!$survey) {
            continue;
        }
        $comment = mitCtField($record, ['comment', 'comments', 'commenttext']) ?: ($comments[$blast] ?? '');
        $when = strtotime(mitCtField($record, ['responsedate', 'responsedatetime', 'datetime', 'date', 'created', 'timestamp'])) ?: time();
        if (intval($survey['temperature']) === $temperature && (string) $survey['comment'] === $comment) {
            continue;
        }
        mysqli_query($mysqli, "UPDATE mit_ct_surveys SET temperature = $temperature, comment = " . ($comment === '' ? 'NULL' : "'" . escapeSql(mb_substr($comment, 0, 5000)) . "'") . ",
            responded_at = '" . date('Y-m-d H:i:s', $when) . "', response_data = '" . escapeSql(json_encode($record)) . "' WHERE survey_id = " . intval($survey['survey_id']));
        if (!$survey['temperature'] && $survey['ticket_id']) {
            logTicketHistory(intval($survey['ticket_id']), escapeSql('Customer Thermometer: rated ' . MIT_CT_TEMPERATURES[$temperature]['name'] . ($comment !== '' ? ' - "' . mb_substr($comment, 0, 200) . '"' : '')));
        }
        $new++;
    }
    mitSetSetting('ct_last_poll', date('Y-m-d H:i:s'));
    return $new;
}

/** Send what is due and read responses when due. Returns a line for the log / screen; throws when Customer Thermometer fails. */
function mitCtRun($poll_now = false) {
    if (!mitLock('ct_run', 1)) {
        return 'Already running';
    }
    try {
        $sent = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        foreach (mitCtDueTickets() as $ticket) {
            $sent[mitCtSend($ticket)]++;
        }
        $responses = null;
        $last_poll = strtotime(mitCtSettings()['last_poll']) ?: 0;
        if ($poll_now || $last_poll < time() - MIT_CT_POLL_MINUTES * 60) {
            $responses = mitCtFetchResponses();
        }
        $line = $sent['sent'] . ' survey' . ($sent['sent'] === 1 ? '' : 's') . ' sent'
            . ($sent['failed'] ? ", {$sent['failed']} refused" : '') . ($sent['skipped'] ? ", {$sent['skipped']} skipped" : '')
            . ($responses !== null ? ", $responses new response" . ($responses === 1 ? '' : 's') : '');
        mitSetSetting('ct_last_run', date('Y-m-d H:i:s'));
        mitSetSetting('ct_last_result', $line);
        return $line;
    } catch (Throwable $e) {
        mitSetSetting('ct_last_run', date('Y-m-d H:i:s'));
        mitSetSetting('ct_last_result', 'Failed: ' . $e->getMessage());
        throw $e;
    } finally {
        mitUnlock('ct_run');
    }
}

/*
 * ---------- scores ----------
 */

/** Responses in the last $days days (0 = all), for one client or everyone the agent can see: counts per temperature + happiness. */
function mitCtScore($client_id = 0, $days = 90) {
    global $mysqli;
    $score = ['total' => 0, 'happiness' => null, 'counts' => array_fill_keys(array_keys(MIT_CT_TEMPERATURES), 0)];
    if (!mitCtReady()) {
        return $score;
    }
    $where = "temperature IS NOT NULL" . ($days ? " AND responded_at >= NOW() - INTERVAL " . intval($days) . " DAY" : '')
        . ($client_id ? " AND client_id = " . intval($client_id) : clientScopeSql('client_id'));
    $result = mysqli_query($mysqli, "SELECT temperature, COUNT(*) AS n FROM mit_ct_surveys WHERE $where GROUP BY temperature");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($score['counts'][intval($row['temperature'])])) {
            $score['counts'][intval($row['temperature'])] = intval($row['n']);
            $score['total'] += intval($row['n']);
        }
    }
    if ($score['total']) {
        $score['happiness'] = round(100 * ($score['counts'][1] + $score['counts'][2]) / $score['total']);
    }
    return $score;
}

/** A temperature as a small coloured badge. */
function mitCtBadge($temperature) {
    $t = MIT_CT_TEMPERATURES[intval($temperature)] ?? null;
    if (!$t) {
        return '<span class="badge text-bg-light border">No response</span>';
    }
    return '<span class="badge" style="background:' . $t['color'] . ';color:#fff"><i class="fas ' . $t['icon'] . ' me-1"></i>' . $t['name'] . '</span>';
}

/** The score as the dashboards' rail: Happiness % and the four counts. */
function mitCtRail(array $score, $link) {
    $tone = $score['happiness'] === null ? 'var(--bs-secondary)' : ($score['happiness'] >= 85 ? 'var(--bs-success)' : ($score['happiness'] >= 70 ? 'var(--bs-warning)' : 'var(--bs-danger)'));
    $html = '<div class="card mb-0 overflow-hidden"><div class="itflow-dash-rail">'
        . '<a href="' . escapeHtml($link) . '" class="itflow-dash-rail-item" data-mit-ct-happiness="' . ($score['happiness'] ?? '') . '" style="--itflow-dash-tone: ' . $tone . '">'
        . '<span class="itflow-dash-rail-count">' . ($score['happiness'] === null ? '-' : $score['happiness'] . '%') . '</span>'
        . '<span class="itflow-dash-rail-label"><i class="fas fa-fw fa-thermometer-half" aria-hidden="true"></i>Happiness</span>'
        . '<span class="itflow-dash-rail-detail">' . ($score['total'] ? number_format($score['total']) . ' response' . ($score['total'] === 1 ? '' : 's') . ' · Gold + Green' : 'No responses yet') . '</span></a>';
    foreach (MIT_CT_TEMPERATURES as $id => $t) {
        $html .= '<a href="' . escapeHtml($link . (strpos($link, '?') === false ? '?' : '&') . "temperature=$id") . '" class="itflow-dash-rail-item" data-mit-ct-count="' . $id . '" style="--itflow-dash-tone: ' . $t['color'] . '">'
            . '<span class="itflow-dash-rail-count">' . number_format($score['counts'][$id]) . '</span>'
            . '<span class="itflow-dash-rail-label"><i class="fas fa-fw ' . $t['icon'] . '" style="color:' . $t['color'] . '" aria-hidden="true"></i>' . $t['name'] . '</span></a>';
    }
    return $html . '</div></div>';
}

/** Customer Thermometer is in use here: set up, or responses on record. */
function mitCtInUse() {
    global $mysqli;
    static $in_use = null;
    if ($in_use === null) {
        $in_use = mitCtReady() && (mitCtSettings()['enabled'] || mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_ct_surveys LIMIT 1")) > 0);
    }
    return $in_use;
}

/** agent/dashboard.php (one line): the company's score over the last 90 days. */
function mitCtDashCard() {
    if (!mitCtInUse() || lookupUserPermission('module_support') < 1) {
        return '';
    }
    return '<section class="mb-4" data-mit-ct-dash><h2 class="itflow-dash-section mb-2">Customer happiness <span class="small text-body-secondary fw-normal">last 90 days</span></h2>'
        . mitCtRail(mitCtScore(0, 90), '/agent/mit_ct_reviews.php') . '</section>';
}

/** agent/client_overview.php (one line): this client's score over the last 12 months and its latest comments. */
function mitCtClientCard($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    if (!mitCtInUse() || lookupUserPermission('module_support') < 1) {
        return '';
    }
    $score = mitCtScore($client_id, 365);
    $html = '<section class="mb-4" data-mit-ct-client><h2 class="itflow-dash-section mb-2">Customer happiness <span class="small text-body-secondary fw-normal">last 12 months</span></h2>'
        . mitCtRail($score, "/agent/mit_ct_reviews.php?client_id=$client_id");
    $result = mysqli_query($mysqli, "SELECT * FROM mit_ct_surveys WHERE client_id = $client_id AND temperature IS NOT NULL AND comment IS NOT NULL
        AND responded_at >= NOW() - INTERVAL 365 DAY
        ORDER BY responded_at DESC LIMIT " . MIT_CT_CLIENT_COMMENTS);
    $comments = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_ct_surveys WHERE client_id = $client_id
        AND temperature IS NOT NULL AND comment IS NOT NULL AND responded_at >= NOW() - INTERVAL 365 DAY"))[0]);
    if (mysqli_num_rows($result)) {
        $html .= '<div class="card mt-2 mb-0"><ul class="list-group list-group-flush">';
        while ($row = mysqli_fetch_assoc($result)) {
            $html .= '<li class="list-group-item small">' . mitCtBadge($row['temperature']) . ' <span class="text-body-secondary ms-1">'
                . escapeHtml(date('M j', strtotime($row['responded_at']))) . ' · ' . escapeHtml($row['tech_name']) . ' · '
                . ($row['ticket_id'] ? '<a href="/agent/ticket.php?client_id=' . $client_id . '&ticket_id=' . intval($row['ticket_id']) . '">' . escapeHtml($row['ticket_ref']) . '</a>' : escapeHtml($row['ticket_ref']))
                . '</span><div class="mt-1">' . nl2br(escapeHtml($row['comment'])) . '</div></li>';
        }
        $html .= '</ul>';
        // The rest on the responses page (the Happiness tile goes there too)
        if ($comments > MIT_CT_CLIENT_COMMENTS) {
            $html .= '<div class="card-footer py-2 small" data-mit-ct-more><a href="/agent/mit_ct_reviews.php?client_id=' . $client_id . '&days=365">All '
                . $comments . ' comments <i class="fas fa-arrow-right ms-1"></i></a></div>';
        }
        $html .= '</div>';
    }
    return $html . '</section>';
}
