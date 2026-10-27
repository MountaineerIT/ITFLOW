<?php

/*
 * Mountaineer IT - with the modern look on (functions/mit_theme.php), a client's pages show what needs attention at a
 * glance, like a PSA's client view: count tiles at the top of the client's Assets, Licenses and Tickets lists
 * (mitClientTiles - one line each in agent/assets.php, agent/software.php, agent/tickets.php), and a contact line in the
 * client header (mitClientHeadMeta - one line in agent/includes/inc_client_top_head.php). Read only; a tile links to the
 * list it counts where ITFlow has that filter. Classic look: nothing is added.
 */

/** One count: [label, value, icon, colour, detail, link]. Text is escaped here. */
function mitTile($label, $value, $icon, $tone, $detail = '', $url = '') {
    $tag = $url !== '' ? 'a' : 'div';
    return "<$tag class=\"mit-tile\"" . ($url !== '' ? ' href="' . escapeHtml($url) . '"' : '') . ' style="--mit-tone: ' . $tone . '">'
        . '<span class="mit-tile-icon"><i class="fas fa-' . $icon . '" aria-hidden="true"></i></span>'
        . '<span class="mit-tile-label">' . escapeHtml($label) . '</span>'
        . '<span class="mit-tile-value">' . escapeHtml($value) . '</span>'
        . ($detail !== '' ? '<span class="mit-tile-detail">' . escapeHtml($detail) . '</span>' : '')
        . "</$tag>";
}

/** A single number from SQL (0 on error, e.g. a table of ours not there yet). */
function mitTileCount($sql) {
    global $mysqli;
    try {
        $result = mysqli_query($mysqli, $sql);
        $row = $result ? mysqli_fetch_row($result) : null;
        return $row ? floatval($row[0]) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The tiles for one of a client's lists: 'assets', 'licenses' or 'tickets'. The page has already checked the agent may
 * see this client and this module.
 */
function mitClientTiles($kind, $client_id) {
    if (!function_exists('mitLookOn') || !mitLookOn()) {
        return '';
    }
    $client_id = intval($client_id);
    $base = "client_id=$client_id";
    $grey = '#64748b';
    $tiles = [];

    if ($kind === 'assets') {
        $where = "asset_client_id = $client_id AND asset_archived_at IS NULL";
        $has_warranty = "asset_warranty_expire IS NOT NULL AND asset_warranty_expire != '0000-00-00'";
        $total = mitTileCount("SELECT COUNT(*) FROM assets WHERE $where");
        $expired = mitTileCount("SELECT COUNT(*) FROM assets WHERE $where AND $has_warranty AND asset_warranty_expire < CURDATE()");
        $ending = mitTileCount("SELECT COUNT(*) FROM assets WHERE $where AND $has_warranty AND asset_warranty_expire BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
        $old = mitTileCount("SELECT COUNT(*) FROM assets WHERE $where AND COALESCE(NULLIF(asset_install_date, '0000-00-00'), NULLIF(asset_purchase_date, '0000-00-00')) < DATE_SUB(CURDATE(), INTERVAL 5 YEAR)");
        $tiles[] = mitTile('Assets', number_format($total), 'desktop', '#2f6fde', '', "assets.php?$base");
        $tiles[] = mitTile('Warranty expired', number_format($expired), 'shield-alt', $expired ? '#e0484d' : $grey, '', "assets.php?$base&expire_days=expired");
        $tiles[] = mitTile('Warranty ends in 90 days', number_format($ending), 'hourglass-half', $ending ? '#d9930c' : $grey, '', "assets.php?$base&expire_days=90");
        $tiles[] = mitTile('Over 5 years old', number_format($old), 'recycle', $old ? '#d9930c' : $grey, 'By install or purchase date');
        // NinjaOne's last word on whether each linked device is online (functions/mit_ninjaone.php)
        if (mitTileCount("SELECT COUNT(*) FROM mit_ninja_devices d JOIN assets a ON a.asset_id = d.asset_id WHERE $where")) {
            $offline = mitTileCount("SELECT COUNT(*) FROM mit_ninja_devices d JOIN assets a ON a.asset_id = d.asset_id WHERE $where AND d.offline = 1");
            $tiles[] = mitTile('Offline in NinjaOne', number_format($offline), 'plug', $offline ? '#e0484d' : '#1f9d61');
        }
    } elseif ($kind === 'licenses') {
        $where = "software_client_id = $client_id AND software_archived_at IS NULL";
        $has_expiry = "software_expire IS NOT NULL AND software_expire != '0000-00-00'";
        $total = mitTileCount("SELECT COUNT(*) FROM software WHERE $where");
        $seats = mitTileCount("SELECT COALESCE(SUM(software_seats), 0) FROM software WHERE $where");
        // ITFlow's own count (archived assets / contacts left out), the one its Over seats tab uses
        $used = function_exists('softwareSeatsUsedSql') ? mitTileCount("SELECT COALESCE(SUM(" . softwareSeatsUsedSql('software.software_id') . "), 0) FROM software WHERE $where")
            : mitTileCount("SELECT (SELECT COUNT(*) FROM software_contacts JOIN software USING (software_id) WHERE $where)
            + (SELECT COUNT(*) FROM software_assets JOIN software USING (software_id) WHERE $where)");
        $expired = mitTileCount("SELECT COUNT(*) FROM software WHERE $where AND $has_expiry AND software_expire < CURDATE()");
        $renewing = mitTileCount("SELECT COUNT(*) FROM software WHERE $where AND $has_expiry AND software_expire BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
        $tiles[] = mitTile('Licenses', number_format($total), 'key', '#2f6fde', '', "software.php?$base");
        $tiles[] = mitTile('Seats', number_format($seats), 'users', '#1a9cbf', number_format($used) . ' assigned');
        $tiles[] = mitTile('Expired', number_format($expired), 'calendar-times', $expired ? '#e0484d' : $grey, '', "software.php?$base&expire_days=expired");
        $tiles[] = mitTile('Renewing in 90 days', number_format($renewing), 'sync-alt', $renewing ? '#d9930c' : $grey, '', "software.php?$base&expire_days=90");
    } elseif ($kind === 'tickets') {
        $where = "tickets.ticket_client_id = $client_id" . (function_exists('mitServiceTicketsAnd') ? mitServiceTicketsAnd('tickets.ticket_project_id') : '') . mitBoardVisibleSql('tickets');
        $open = mitTileCount("SELECT COUNT(*) FROM tickets WHERE $where AND " . TICKET_OPEN_SQL);
        $overdue = mitTileCount("SELECT COUNT(*) FROM tickets WHERE $where AND " . ticketOverdueSql());
        $unassigned = mitTileCount("SELECT COUNT(*) FROM tickets WHERE $where AND " . TICKET_OPEN_SQL . " AND tickets.ticket_assigned_to = 0");
        $closed = mitTileCount("SELECT COUNT(*) FROM tickets WHERE $where AND COALESCE(tickets.ticket_resolved_at, tickets.ticket_closed_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $seconds = mitTileCount("SELECT COALESCE(SUM(TIME_TO_SEC(ticket_reply_time_worked)), 0) FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id
            WHERE $where AND ticket_reply_archived_at IS NULL AND ticket_reply_created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $tiles[] = mitTile('Open', number_format($open), 'life-ring', '#2f6fde', '', "tickets.php?$base");
        $tiles[] = mitTile('Overdue', number_format($overdue), 'stopwatch', $overdue ? '#e0484d' : $grey, 'Past due or SLA breached', "tickets.php?$base&show=overdue");
        $tiles[] = mitTile('Unassigned', number_format($unassigned), 'user-slash', $unassigned ? '#d9930c' : $grey, '', "tickets.php?$base&show=unassigned");
        $tiles[] = mitTile('Closed in 30 days', number_format($closed), 'check-circle', '#1f9d61', 'Resolved or closed'); // no link: the list's date filter is on created, not closed
        $tiles[] = mitTile('Time this month', dashboardDuration($seconds), 'clock', '#7c5cd6', date('F'));
    }
    return $tiles ? '<div class="mit-tiles">' . implode('', $tiles) . '</div>' : '';
}

/**
 * The client header's contact line: primary contact (title), phone, email and website - read from the variables
 * agent/includes/inc_all_client.php set (already escaped there).
 */
function mitClientHeadMeta() {
    global $client_id, $contact_id, $contact_name, $contact_title, $contact_phone, $contact_extension, $contact_mobile, $contact_email,
        $location_phone, $client_website;
    if (!function_exists('mitLookOn') || !mitLookOn()) {
        return '';
    }
    $parts = [];
    if (!empty($contact_name)) {
        $parts[] = '<span><i class="fas fa-user me-1" aria-hidden="true"></i><a href="contact.php?client_id=' . intval($client_id) . '&contact_id=' . intval($contact_id) . '">'
            . $contact_name . '</a>' . (!empty($contact_title) ? " ($contact_title)" : '') . '</span>';
    }
    $phone = !empty($contact_phone) ? $contact_phone . (!empty($contact_extension) ? " x$contact_extension" : '') : ($contact_mobile ?: ($location_phone ?? ''));
    if ($phone !== '') {
        $dial = preg_replace('/[^0-9+]/', '', html_entity_decode((string) ($contact_phone ?: $contact_mobile ?: $location_phone)));
        $parts[] = '<span><i class="fas fa-phone me-1" aria-hidden="true"></i><a href="tel:' . escapeHtml($dial) . '">' . $phone . '</a></span>';
    }
    if (!empty($contact_email)) {
        $parts[] = '<span><i class="fas fa-envelope me-1" aria-hidden="true"></i><a href="mailto:' . $contact_email . '">' . $contact_email . '</a></span>';
    }
    if (!empty($client_website)) {
        $site = html_entity_decode($client_website);
        $url = preg_match('~^https?://~i', $site) ? $site : "https://$site";
        $parts[] = '<span><i class="fas fa-globe me-1" aria-hidden="true"></i><a href="' . escapeHtml($url) . '" target="_blank" rel="noopener noreferrer">'
            . escapeHtml(preg_replace('~^https?://~i', '', rtrim($site, '/'))) . '</a></span>';
    }
    return $parts ? '<div class="mit-client-meta w-100 d-flex flex-wrap align-items-center">' . implode('', $parts) . '</div>' : '';
}
