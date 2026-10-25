<?php

/*
 * Mountaineer IT - MSP Align, read only (https://mspalign.org - the self-hosted vCIO toolkit). Required from functions.php.
 * MSP Align already keeps ITFlow's ids on what it brings in from ITFlow: psa_id on clients and licenses, psa_asset_id on
 * devices - so nothing is mapped by hand. Every hour (cron job mit_align_sync, or "Sync now") its REST API (/api/v1, a key
 * "Bearer msa_...") is read with GET only, and a copy kept in mit_align_clients / mit_align_devices / mit_align_licenses (M039):
 *   - clients: GET /clients (every page), then per matched client GET /clients/{id} (summary: devices, alignment, next meeting)
 *     and GET /clients/{id}/health (score and band - needs the key's health:read);
 *   - devices: GET /devices (every page) - lifecycle status, warranty end, end of life, replace by, planned quarter, OS support;
 *   - licenses: GET /licenses (every page) - expiry, contract end, renegotiate by, auto renew, annual cost.
 * Everything is read in full each time (MSP Align works lifecycle status out from today's date, so a status changes without
 * the device changing - updated_since would miss it). Rows MSP Align no longer has are removed; a part the key can't read
 * (403) is skipped and its rows left alone. 429 waits for Retry-After.
 * Shown: mitAlignClientCard (client overview) and mitAlignDashCard (dashboard) - in place of ITFlow's Needs attention while
 * mitAlignReplacesAttention -, mitAlignClientButton (client header), mitAlignAssetBadge (asset list),
 * mitAlignAssetPanel (asset page / window), mitAlignLicenseLine (license list). Admin > Integrations > MSP Align
 * (admin/mit_align.php, handlers admin/post/mit_align.php). Settings mit_settings align_* (the key via mitEncryptSecret).
 * Development mode: reads are allowed (mitHttp lets GET through); nothing is ever written to MSP Align.
 */

const MIT_ALIGN_PER_PAGE = 200;
const MIT_ALIGN_MAX_PAGES = 500;
const MIT_ALIGN_TONES = ['bad' => 'danger', 'warn' => 'warning', 'ok' => 'success', 'muted' => 'secondary'];
const MIT_ALIGN_CLIENT_PAGES = [
    '' => ['Overview', 'fa-compass'],
    '/roadmap' => ['Roadmap', 'fa-route'],
    '/budget' => ['Budget', 'fa-coins'],
    '/devices' => ['Devices', 'fa-laptop'],
    '/licenses' => ['Licenses', 'fa-key'],
    '/compliance' => ['Compliance', 'fa-clipboard-check'],
    '/backups' => ['Backups', 'fa-hdd'],
    '/alignment' => ['Alignment', 'fa-bullseye'],
    '/meetings' => ['Meetings', 'fa-handshake'],
    '/reports' => ['Reports', 'fa-file-alt'],
];

/** Has M039 run? */
function mitAlignReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_align_licenses'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

function mitAlignSettings() {
    return [
        'url' => (string) mitSetting('align_url', ''),
        'has_key' => (string) mitSetting('align_key', '') !== '',
        'enabled' => mitSetting('align_enabled', '1') === '1',
        'scopes' => json_decode((string) mitSetting('align_scopes', '[]'), true) ?: [],
        'key_name' => (string) mitSetting('align_key_name', ''),
        'last_run' => (string) mitSetting('align_last_run', ''),
        'last_result' => (string) mitSetting('align_last_result', ''),
        'report' => json_decode((string) mitSetting('align_report', '{}'), true) ?: [],
    ];
}

/** Connected and switched on (the address and a key are saved). */
function mitAlignOn() {
    static $on = null;
    if ($on === null) {
        $s = mitAlignReady() ? mitAlignSettings() : null;
        $on = $s && $s['url'] !== '' && $s['has_key'] && $s['enabled'];
    }
    return $on;
}

/** The MSP Align address as typed, tidied: http(s) only, no trailing slash or /api/v1. '' when it isn't an address. */
function mitAlignCleanUrl($url) {
    $url = trim((string) $url);
    if (!preg_match('~^https?://[^\s/?#]+~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }
    $url = preg_replace('~[?#].*$~', '', $url);
    return preg_replace('~/api/v1/?$~i', '', rtrim($url, '/'));
}

/** A link to an MSP Align page: the API's own absolute url, else the address + path. */
function mitAlignLink($api_url, $path) {
    $api_url = (string) $api_url;
    return preg_match('~^https?://~i', $api_url) ? $api_url : mitAlignSettings()['url'] . $path;
}

/**
 * One GET on the API. Returns the decoded JSON; throws with why not - code 403 when the key lacks the scope (callers skip that
 * part). Waits for Retry-After on 429 (at most three times, a minute each).
 */
function mitAlignCall($path, array $query = [], $key = null, $url = null) {
    $key = $key ?? mitDecryptSecret((string) mitSetting('align_key', ''));
    $url = $url ?? mitAlignSettings()['url'];
    if ($key === '' || $url === '') {
        throw new RuntimeException('MSP Align is not set up - save its address and an API key first.');
    }
    $full = $url . '/api/v1' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    for ($try = 0; ; $try++) {
        $response = mitHttp('GET', $full, ['Authorization: Bearer ' . $key, 'Accept: application/json'], null, 60);
        if ($response['status'] === 429 && $try < 3) {
            // MSP Align's per-key limit is per minute: wait and try again
            sleep(20);
            continue;
        }
        break;
    }
    $error = $response['json']['error'] ?? null;
    $message = is_array($error) ? (string) ($error['message'] ?? '') : '';
    if ($response['status'] === 401) {
        throw new RuntimeException('MSP Align refused the API key' . ($message !== '' ? ": $message" : '.'));
    }
    if ($response['status'] === 403) {
        throw new RuntimeException('The MSP Align key may not read this' . ($message !== '' ? ": $message" : '.'), 403);
    }
    if ($response['status'] < 200 || $response['status'] >= 300 || !$response['json']) {
        throw new RuntimeException('MSP Align ' . $path . ' failed: ' . ($message ?: ($response['error'] ?: 'HTTP ' . $response['status'])));
    }
    return $response['json'];
}

/** Every row of a list endpoint (all pages). */
function mitAlignAll($path, array $query = []) {
    $rows = [];
    for ($page = 1; $page <= MIT_ALIGN_MAX_PAGES; $page++) {
        $answer = mitAlignCall($path, $query + ['page' => $page, 'per_page' => MIT_ALIGN_PER_PAGE]);
        $data = $answer['data'] ?? [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        if (empty($answer['meta']['has_more']) || !$data) {
            break;
        }
    }
    return $rows;
}

/** An ITFlow id MSP Align holds (psa_id / psa_asset_id - text since its 2.0), or 0. */
function mitAlignItflowId($value) {
    $value = trim((string) $value);
    return preg_match('/^[1-9][0-9]{0,9}$/', $value) ? intval($value) : 0;
}

/** A date (YYYY-MM-DD) from the API, or 'NULL' for SQL. */
function mitAlignSqlDate($value) {
    $value = substr((string) $value, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? "'$value'" : 'NULL';
}

/** Which ITFlow ids of a table exist (one query). */
function mitAlignExisting($table, $column, array $ids) {
    global $mysqli;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $found = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
        $result = mysqli_query($mysqli, "SELECT $column FROM $table WHERE $column IN (" . implode(',', $chunk) . ")");
        while ($row = mysqli_fetch_row($result)) {
            $found[intval($row[0])] = true;
        }
    }
    return $found;
}

/** Remove rows MSP Align no longer has (only after a part was read in full). */
function mitAlignPrune($table, $column, array $seen) {
    global $mysqli;
    $seen = array_map('intval', array_keys($seen));
    mysqli_query($mysqli, "DELETE FROM $table" . ($seen ? " WHERE $column NOT IN (" . implode(',', $seen) . ")" : ''));
    return mysqli_affected_rows($mysqli);
}

/** The hourly copy. Returns a one-line summary; throws when MSP Align can't be reached at all. */
function mitAlignSync() {
    if (!mitAlignOn()) {
        throw new RuntimeException('MSP Align is not set up or is switched off.');
    }
    if (!mitLock('align_sync', 5)) {
        return 'another sync is running';
    }
    try {
        $done = [];
        $skipped = [];
        $report = ['mismatched' => [], 'unmatched_clients' => 0];
        try {
            [$matched, $report] = mitAlignSyncClients();
            $done[] = "$matched client" . ($matched === 1 ? '' : 's');
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 403) {
                throw $e;
            }
            $skipped[] = 'clients';
        }
        foreach (['devices' => 'mitAlignSyncDevices', 'licenses' => 'mitAlignSyncLicenses'] as $part => $function) {
            try {
                $n = $function();
                $done[] = "$n " . ($part === 'devices' ? 'asset' : 'license') . ($n === 1 ? '' : 's');
            } catch (RuntimeException $e) {
                if ($e->getCode() !== 403) {
                    throw $e;
                }
                $skipped[] = $part;
            }
        }
        $result = 'Matched ' . implode(', ', $done) . ($skipped ? ' - the key may not read ' . implode(' / ', $skipped) . ' (skipped)' : '');
        mitSetSetting('align_last_run', date('Y-m-d H:i:s'));
        mitSetSetting('align_last_result', $result);
        mitSetSetting('align_report', json_encode($report));
        return $result;
    } catch (Throwable $e) {
        mitSetSetting('align_last_run', date('Y-m-d H:i:s'));
        mitSetSetting('align_last_result', 'Failed: ' . mb_substr($e->getMessage(), 0, 400));
        throw $e;
    } finally {
        mitUnlock('align_sync');
    }
}

/** Clients, with each matched one's summary and health score. Returns [matched, report]. */
function mitAlignSyncClients() {
    global $mysqli;
    $rows = mitAlignAll('/clients', ['include_excluded' => 'true']);
    $exists = mitAlignExisting('clients', 'client_id', array_map(fn($r) => mitAlignItflowId($r['psa_id'] ?? ''), $rows));
    $names = [];
    if ($exists) {
        $result = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_id IN (" . implode(',', array_keys($exists)) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $names[intval($row['client_id'])] = (string) $row['client_name'];
        }
    }
    $seen = [];
    $report = ['mismatched' => [], 'unmatched_clients' => 0];
    $health_ok = true;
    foreach ($rows as $row) {
        $client_id = mitAlignItflowId($row['psa_id'] ?? '');
        $align_id = intval($row['id'] ?? 0);
        if (!$client_id || !$align_id || !isset($exists[$client_id])) {
            $report['unmatched_clients']++;
            continue;
        }
        // A different name may mean MSP Align came from another ITFlow - listed on the admin page, still linked
        if (mb_strtolower(trim((string) ($row['name'] ?? ''))) !== mb_strtolower(trim(html_entity_decode($names[$client_id] ?? '')))
            && count($report['mismatched']) < 25) {
            $report['mismatched'][] = ['itflow' => $names[$client_id] ?? '', 'align' => (string) ($row['name'] ?? '')];
        }
        $summary = (mitAlignCall("/clients/$align_id")['data']['summary'] ?? []) ?: [];
        $health = null;
        if ($health_ok) {
            try {
                $health = mitAlignCall("/clients/$align_id/health")['data'] ?? null;
            } catch (RuntimeException $e) {
                if ($e->getCode() !== 403) {
                    throw $e;
                }
                $health_ok = false;
            }
        }
        $devices = $summary['devices'] ?? null;
        $alignment = $summary['alignment'] ?? null;
        $meeting = $summary['next_meeting'] ?? null;
        $int = fn($v) => is_numeric($v) ? intval($v) : 'NULL';
        $str = fn($v, $len) => $v === null || $v === '' ? 'NULL' : "'" . escapeSql(mb_substr((string) $v, 0, $len)) . "'";
        $meeting_at = is_array($meeting) && !empty($meeting['starts_at']) && strtotime($meeting['starts_at']) ? "'" . date('Y-m-d H:i:s', strtotime($meeting['starts_at'])) . "'" : 'NULL';
        $values = "align_id = $align_id, url = '" . escapeSql(mb_substr(mitAlignLink($row['url'] ?? '', "/clients/$align_id"), 0, 500)) . "',
            health_score = " . $int($health['score'] ?? null) . ", health_band = " . $str($health['band'] ?? null, 40) . ",
            alignment_score = " . $int($alignment['score'] ?? null) . ", alignment_band = " . $str($alignment['band'] ?? null, 40) . ",
            devices_total = " . $int($devices['total'] ?? null) . ",
            devices_attention = " . (is_array($devices) && is_numeric($devices['total'] ?? null) && is_numeric($devices['healthy'] ?? null)
                ? max(0, intval($devices['total']) - intval($devices['healthy'])) : 'NULL') . ",
            next_meeting_at = $meeting_at, next_meeting_title = " . $str(is_array($meeting) ? ($meeting['title'] ?? null) : null, 255) . ", synced_at = NOW()";
        mysqli_query($mysqli, "INSERT INTO mit_align_clients SET client_id = $client_id, $values ON DUPLICATE KEY UPDATE $values");
        $seen[$client_id] = true;
    }
    mitAlignPrune('mit_align_clients', 'client_id', $seen);
    return [count($seen), $report];
}

/** Devices matched to assets. Returns how many. */
function mitAlignSyncDevices() {
    global $mysqli;
    $rows = mitAlignAll('/devices');
    $exists = mitAlignExisting('assets', 'asset_id', array_map(fn($r) => mitAlignItflowId($r['psa_asset_id'] ?? ''), $rows));
    $seen = [];
    foreach ($rows as $row) {
        $asset_id = mitAlignItflowId($row['psa_asset_id'] ?? '');
        $align_id = intval($row['id'] ?? 0);
        if (!$asset_id || !$align_id || !isset($exists[$asset_id])) {
            continue;
        }
        $life = is_array($row['lifecycle'] ?? null) ? $row['lifecycle'] : [];
        $planned = is_array($life['planned_replacement'] ?? null) ? ($life['planned_replacement']['label'] ?? null) : null;
        $values = "align_id = $align_id, url = '" . escapeSql(mb_substr(mitAlignLink($row['url'] ?? '', "/devices/$align_id"), 0, 500)) . "',
            status = '" . escapeSql(mb_substr((string) ($life['status'] ?? ''), 0, 40)) . "',
            status_label = '" . escapeSql(mb_substr((string) ($life['status_label'] ?? ''), 0, 80)) . "',
            health = '" . escapeSql(mb_substr((string) ($life['health'] ?? ''), 0, 20)) . "',
            warranty_end = " . mitAlignSqlDate($row['warranty']['end'] ?? null) . ", end_of_life = " . mitAlignSqlDate($life['end_of_life'] ?? null) . ",
            replace_by = " . mitAlignSqlDate($life['replace_by'] ?? null) . ",
            planned = " . ($planned ? "'" . escapeSql(mb_substr((string) $planned, 0, 80)) . "'" : 'NULL') . ",
            os_support_ends = " . mitAlignSqlDate(is_array($row['os'] ?? null) ? ($row['os']['support_ends'] ?? null) : null) . ", synced_at = NOW()";
        mysqli_query($mysqli, "INSERT INTO mit_align_devices SET asset_id = $asset_id, $values ON DUPLICATE KEY UPDATE $values");
        $seen[$asset_id] = true;
    }
    mitAlignPrune('mit_align_devices', 'asset_id', $seen);
    return count($seen);
}

/** Licenses matched to ITFlow licenses (software). Returns how many. */
function mitAlignSyncLicenses() {
    global $mysqli;
    $rows = mitAlignAll('/licenses');
    $exists = mitAlignExisting('software', 'software_id', array_map(fn($r) => mitAlignItflowId($r['psa_id'] ?? ''), $rows));
    $seen = [];
    foreach ($rows as $row) {
        $software_id = mitAlignItflowId($row['psa_id'] ?? '');
        $align_id = intval($row['id'] ?? 0);
        if (!$software_id || !$align_id || !isset($exists[$software_id])) {
            continue;
        }
        // Annual cost as MSP Align works it out (none for one-time purchases or unpriced licenses)
        $annual = is_numeric($row['annual'] ?? null) ? round(floatval($row['annual']), 2) : null;
        $values = "align_id = $align_id, align_client_id = " . intval($row['client_id'] ?? 0) . ",
            expire_date = " . mitAlignSqlDate($row['expire_date'] ?? null) . ", contract_end = " . mitAlignSqlDate($row['contract_end'] ?? null) . ",
            renegotiate_date = " . mitAlignSqlDate($row['renegotiate_date'] ?? null) . ", auto_renew = " . (empty($row['auto_renew']) ? 0 : 1) . ",
            annual = " . ($annual === null ? 'NULL' : $annual) . ",
            billing_cycle = " . (($row['billing_cycle'] ?? '') !== '' ? "'" . escapeSql(mb_substr((string) $row['billing_cycle'], 0, 20)) . "'" : 'NULL') . ", synced_at = NOW()";
        mysqli_query($mysqli, "INSERT INTO mit_align_licenses SET software_id = $software_id, $values ON DUPLICATE KEY UPDATE $values");
        $seen[$software_id] = true;
    }
    mitAlignPrune('mit_align_licenses', 'software_id', $seen);
    return count($seen);
}

/*
 * ---------- on ITFlow's pages ----------
 */

function mitAlignBadge($label, $tone, $title = '') {
    return '<span class="badge rounded-pill text-bg-' . (MIT_ALIGN_TONES[$tone] ?? 'secondary') . ' fw-normal"' . ($title !== '' ? ' title="' . escapeHtml($title) . '"' : '')
        . '>' . escapeHtml($label) . '</span>';
}

/** The copied row for a client, or null. */
function mitAlignClient($client_id) {
    global $mysqli;
    if (!mitAlignOn()) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_align_clients WHERE client_id = " . intval($client_id))) ?: null;
}

/** A score's tone from MSP Align's band names (health: Healthy / Needs attention / At risk; alignment: On track / ...). */
function mitAlignBandTone($band) {
    $band = mb_strtolower((string) $band);
    return in_array($band, ['healthy', 'on track'], true) ? 'var(--bs-success)' : ($band === 'needs attention' ? 'var(--bs-warning)'
        : ($band === 'at risk' ? 'var(--bs-danger)' : 'var(--bs-secondary)'));
}

/** One tile of an MSP Align rail. MSP Align pages open in a new tab, ITFlow's own lists ($blank false) in this one. */
function mitAlignTile($link, $count, $label, $icon, $detail, $tone, $data, $blank = true) {
    return '<a href="' . escapeHtml($link) . '"' . ($blank ? ' target="_blank" rel="noopener"' : '') . ' class="itflow-dash-rail-item" ' . $data
        . ' style="--itflow-dash-tone: ' . $tone . '">'
        . '<span class="itflow-dash-rail-count">' . $count . '</span>'
        . '<span class="itflow-dash-rail-label"><i class="fas fa-fw ' . $icon . '" aria-hidden="true"></i>' . escapeHtml($label) . '</span>'
        . ($detail !== '' ? '<span class="itflow-dash-rail-detail">' . escapeHtml($detail) . '</span>' : '') . '</a>';
}

/** The SLA tile: open tickets past SLA (ITFlow's own rule and list), red when any. */
function mitAlignSlaTile($count, $url) {
    $count = intval($count);
    return mitAlignTile($url, number_format($count), 'Past SLA', 'fa-stopwatch', $count ? 'Open tickets' : 'All open tickets in SLA',
        $count ? 'var(--bs-danger)' : 'var(--bs-success)', 'data-mit-align-sla="' . $count . '"', false);
}

/**
 * Does MSP Align take the place of ITFlow's "Needs attention" strip (client overview and dashboard)? mit_settings
 * align_replace_attention, on unless switched off on Admin > Integrations > MSP Align.
 */
function mitAlignReplacesAttention() {
    return mitAlignOn() && mitSetting('align_replace_attention', '1') === '1';
}

/**
 * agent/client_overview.php (one line, in front of Needs attention): the client's MSP Align health, alignment, devices, next
 * meeting, open tickets past SLA (when $tickets - the agent's Support access) and quick links. '' when the client isn't in MSP Align.
 */
function mitAlignClientCard($client_id, $tickets = false) {
    $row = mitAlignClient($client_id);
    if (!$row) {
        return '';
    }
    $client_id = intval($client_id);
    $url = $row['url'];
    $sla = '';
    if ($tickets && function_exists('clientOverviewSlaBreachedSql')) {
        $sla = mitAlignSlaTile(dashboardCount("SELECT COUNT(ticket_id) FROM tickets WHERE ticket_client_id = $client_id AND ticket_resolved_at IS NULL
            AND ticket_closed_at IS NULL AND " . clientOverviewSlaBreachedSql() . (function_exists('mitBoardVisibleSql') ? mitBoardVisibleSql('tickets') : '')),
            "/agent/tickets.php?client_id=$client_id&sla=breached");
    }
    $html = '<section class="mb-4" data-mit-align-client><h2 class="itflow-dash-section mb-2">MSP Align '
        . '<span class="small text-body-secondary fw-normal">as of ' . escapeHtml(date('M j, g:i A', strtotime($row['synced_at']))) . '</span></h2>'
        . '<div class="card mb-0 overflow-hidden"><div class="itflow-dash-rail">'
        . mitAlignTile($url, $row['health_score'] === null ? '-' : intval($row['health_score']), 'Health score', 'fa-heartbeat', (string) ($row['health_band'] ?? 'No score'),
            mitAlignBandTone($row['health_band']), 'data-mit-align-health="' . ($row['health_score'] ?? '') . '"')
        . mitAlignTile("$url/alignment", $row['alignment_score'] === null ? '-' : intval($row['alignment_score']) . '%', 'Alignment', 'fa-bullseye',
            (string) ($row['alignment_band'] ?? 'No review yet'), mitAlignBandTone($row['alignment_band']), 'data-mit-align-alignment')
        . mitAlignTile("$url/devices", $row['devices_attention'] === null ? '-' : intval($row['devices_attention']), 'Devices to act on', 'fa-laptop-medical',
            $row['devices_total'] === null ? '' : 'of ' . intval($row['devices_total']) . ' devices',
            intval($row['devices_attention']) > 0 ? 'var(--bs-warning)' : 'var(--bs-success)', 'data-mit-align-devices="' . ($row['devices_attention'] ?? '') . '"')
        . $sla
        . mitAlignTile("$url/meetings", $row['next_meeting_at'] ? escapeHtml(date('M j', strtotime($row['next_meeting_at']))) : '-', 'Next meeting', 'fa-handshake',
            (string) ($row['next_meeting_title'] ?? ($row['next_meeting_at'] ? '' : 'None scheduled')), 'var(--bs-info)', 'data-mit-align-meeting')
        . '</div><div class="card-footer d-flex flex-wrap gap-2 py-2" data-mit-align-links>';
    foreach (MIT_ALIGN_CLIENT_PAGES as $path => [$label, $icon]) {
        $html .= '<a class="btn btn-sm btn-outline-secondary" href="' . escapeHtml($url . $path) . '" target="_blank" rel="noopener">'
            . '<i class="fas fa-fw ' . $icon . ' me-1"></i>' . $label . '</a>';
    }
    return $html . '</div></div></section>';
}

/**
 * agent/dashboard.php (one line, in front of Needs attention): every client the agent can see that MSP Align has - open tickets
 * past SLA (ITFlow's count from $attention, which follows the dashboard's board), clients at risk with the average health,
 * devices to act on and meetings in the next 7 days. '' when MSP Align doesn't replace Needs attention or has none of their clients.
 */
function mitAlignDashCard($attention, array $access) {
    global $mysqli;
    if (!mitAlignReplacesAttention()) {
        return '';
    }
    $sum = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS clients, MAX(a.synced_at) AS synced,
            SUM(a.health_band = 'At risk') AS at_risk, SUM(a.health_band = 'Needs attention') AS needs, ROUND(AVG(a.health_score)) AS health,
            SUM(a.devices_attention) AS devices, SUM(a.devices_total) AS devices_total,
            SUM(a.next_meeting_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY) AS meetings
        FROM mit_align_clients a JOIN clients c ON c.client_id = a.client_id
        WHERE c.client_archived_at IS NULL " . clientScopeSql('c.client_id')));
    if (!$sum || !intval($sum['clients'])) {
        return '';
    }
    $base = mitAlignSettings()['url'];
    $next = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT a.next_meeting_at, c.client_name FROM mit_align_clients a JOIN clients c ON c.client_id = a.client_id
        WHERE c.client_archived_at IS NULL AND a.next_meeting_at >= NOW() " . clientScopeSql('c.client_id') . " ORDER BY a.next_meeting_at LIMIT 1"));
    $sla = '';
    if (!empty($access['tickets'])) {
        $item = null;
        foreach ((array) $attention as $candidate) {
            if (($candidate['icon'] ?? '') === 'stopwatch') {
                $item = $candidate;
            }
        }
        $sla = mitAlignSlaTile($item['count'] ?? 0, $item['url'] ?? '/agent/tickets.php?sla=breached');
    }
    $at_risk = intval($sum['at_risk']);
    $devices = intval($sum['devices']);
    return '<section class="mb-4" data-mit-align-dash><h2 class="itflow-dash-section mb-2">MSP Align '
        . '<span class="small text-body-secondary fw-normal">' . intval($sum['clients']) . ' client' . (intval($sum['clients']) === 1 ? '' : 's')
        . ' · as of ' . escapeHtml(date('M j, g:i A', strtotime($sum['synced']))) . '</span></h2>'
        . '<div class="card mb-0 overflow-hidden"><div class="itflow-dash-rail">'
        . $sla
        . mitAlignTile("$base/clients", number_format($at_risk), 'Clients at risk', 'fa-heartbeat',
            ($sum['health'] === null ? 'No health scores' : 'Average health ' . intval($sum['health'])) . (intval($sum['needs']) ? ' · ' . intval($sum['needs']) . ' to watch' : ''),
            $at_risk ? 'var(--bs-danger)' : (intval($sum['needs']) ? 'var(--bs-warning)' : 'var(--bs-success)'), 'data-mit-align-at-risk="' . $at_risk . '"')
        . mitAlignTile("$base/devices", number_format($devices), 'Devices to act on', 'fa-laptop-medical',
            $sum['devices_total'] === null ? '' : 'of ' . number_format(intval($sum['devices_total'])) . ' devices',
            $devices ? 'var(--bs-warning)' : 'var(--bs-success)', 'data-mit-align-devices="' . $devices . '"')
        . mitAlignTile($base, number_format(intval($sum['meetings'])), 'Meetings this week', 'fa-handshake',
            $next ? 'Next: ' . html_entity_decode((string) $next['client_name']) . ', ' . date('M j', strtotime($next['next_meeting_at'])) : 'None scheduled',
            'var(--bs-info)', 'data-mit-align-meetings')
        . '</div></div></section>';
}

/** agent/includes/inc_client_top_head.php (one line): a small link to the client in MSP Align. */
function mitAlignClientButton($client_id) {
    $row = mitAlignClient($client_id);
    if (!$row) {
        return '';
    }
    return '<a class="badge rounded-pill text-bg-light border fw-normal text-decoration-none" href="' . escapeHtml($row['url']) . '" target="_blank" rel="noopener"'
        . ' title="Open in MSP Align" data-mit-align-head><i class="fas fa-compass me-1"></i>MSP Align</a>';
}

/** Copied device rows for these assets (one query per page, kept for the request). */
function mitAlignDevices(array $asset_ids) {
    global $mysqli;
    static $cache = [];
    if (!mitAlignOn()) {
        return [];
    }
    $missing = array_diff(array_unique(array_filter(array_map('intval', $asset_ids))), array_keys($cache));
    if ($missing) {
        foreach ($missing as $id) {
            $cache[$id] = null;
        }
        $result = mysqli_query($mysqli, "SELECT * FROM mit_align_devices WHERE asset_id IN (" . implode(',', $missing) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $cache[intval($row['asset_id'])] = $row;
        }
    }
    return array_intersect_key($cache, array_flip(array_map('intval', $asset_ids)));
}

/** What a device's dates say, for a tooltip or a line. */
function mitAlignDeviceDetail(array $d) {
    $parts = [];
    if ($d['warranty_end']) {
        $parts[] = ($d['warranty_end'] < date('Y-m-d') ? 'warranty ended ' : 'warranty to ') . $d['warranty_end'];
    }
    if ($d['os_support_ends']) {
        $parts[] = 'OS support to ' . $d['os_support_ends'];
    }
    if ($d['planned']) {
        $parts[] = 'replacement planned ' . $d['planned'];
    } elseif ($d['replace_by']) {
        $parts[] = 'replace by ' . $d['replace_by'];
    }
    return implode(' · ', $parts);
}

/** agent/assets.php (one line, in the name cell): the lifecycle status as a badge. $page_ids reads the page in one go. */
function mitAlignAssetBadge($asset_id, array $page_ids = []) {
    $d = mitAlignDevices($page_ids ?: [$asset_id])[intval($asset_id)] ?? null;
    if (!$d || $d['status_label'] === '') {
        return '';
    }
    return ' <span class="ms-1" data-mit-align-asset="' . intval($asset_id) . '">'
        . mitAlignBadge($d['status_label'], $d['health'], 'MSP Align' . (($detail = mitAlignDeviceDetail($d)) !== '' ? ": $detail" : '')) . '</span>';
}

/** agent/asset.php and the asset window (one line): status, dates and "Open in MSP Align". */
function mitAlignAssetPanel($asset_id, $small = false) {
    $d = mitAlignDevices([$asset_id])[intval($asset_id)] ?? null;
    if (!$d) {
        return '';
    }
    $detail = mitAlignDeviceDetail($d);
    return '<a href="' . escapeHtml($d['url']) . '" target="_blank" rel="noopener" class="btn ' . ($small ? 'btn-sm ' : '') . 'btn-outline-secondary text-nowrap"'
        . ' data-mit-align-asset-panel="' . intval($asset_id) . '" title="MSP Align' . ($detail !== '' ? ': ' . escapeHtml($detail) : '') . '">'
        . '<i class="fas fa-fw fa-compass me-1"></i>MSP Align' . ($d['status_label'] !== '' ? ' ' . mitAlignBadge($d['status_label'], $d['health']) : '') . '</a>';
}

/** Copied license rows (the whole table - a few hundred rows - once per request). */
function mitAlignLicenses() {
    global $mysqli;
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        if (mitAlignOn()) {
            $result = mysqli_query($mysqli, "SELECT * FROM mit_align_licenses");
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[intval($row['software_id'])] = $row;
            }
        }
    }
    return $rows;
}

/** agent/software.php (one line, in the name cell): MSP Align's renewal date and annual cost, linking to the client's licenses there. */
function mitAlignLicenseLine($software_id) {
    $l = mitAlignLicenses()[intval($software_id)] ?? null;
    if (!$l) {
        return '';
    }
    $dates = array_filter(['renews' => $l['expire_date'], 'contract ends' => $l['contract_end'], 'renegotiate by' => $l['renegotiate_date']]);
    $parts = [];
    foreach ($dates as $what => $date) {
        $parts[] = ($what === 'renews' && !$l['auto_renew'] ? 'expires' : $what) . ' ' . $date;
    }
    if ($l['annual'] !== null) {
        $parts[] = numfmt_format_currency($GLOBALS['currency_format'], floatval($l['annual']), $GLOBALS['session_company_currency'] ?? 'USD') . '/yr';
    }
    $link = mitAlignSettings()['url'] . '/clients/' . intval($l['align_client_id']) . '/licenses';
    return '<div class="small itflow-above-stretch" data-mit-align-license="' . intval($software_id) . '"><a class="text-body-secondary" href="' . escapeHtml($link)
        . '" target="_blank" rel="noopener" title="Open the client\'s licenses in MSP Align"><i class="fas fa-compass me-1"></i>MSP Align'
        . ($parts ? ': ' . escapeHtml(implode(' · ', $parts)) : '') . '</a></div>';
}
