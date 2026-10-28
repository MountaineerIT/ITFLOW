<?php

/*
 * Mountaineer IT - offices (M048). ITFlow knows one company address; this adds the other places we work from. Contact
 * details only (name, address, phone, email) - the company name, logo, country settings, invoices, quotes and contracts
 * stay the main company's (the user's choice, Oct 28).
 *
 * The main office is ITFlow's own companies row (office 0, edited in Admin > Settings > Company Details as before); the
 * others are mit_offices, listed and edited in an "Other offices" section of that page (modal admin/modals/mit_offices/office.php,
 * handlers admin/post/mit_offices.php). Each agent belongs to one (mit_user_offices; none = the main office) - picked in the
 * office window or in Admin > Users > Edit (mitOfficeUserField / mitOfficeUserSave).
 *
 * Where an agent's office shows:
 *  - Trips: a new trip starts From the driver's office (mitOfficeTripStart in agent/modals/trip/trip_form.php), the From box
 *    offers every office, and changing the driver moves an untouched From to theirs (mitOfficeTripScript).
 *  - Ticket emails: the foot shows the office of the agent on the ticket (the assigned agent, else the one sending) - its
 *    address and phone / email (mitOfficeForTicket: 'mit_office' on the email array, swapped in by emailHtml).
 *  - Purchase orders: Ship to picks any office (agent/modals/mit_procurement/po_action.php); a new PO ships to its maker's office.
 */

/** Has database update M048 run? */
function mitOfficesReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_user_offices'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The other offices - office_id => row, by name. $archived includes removed ones. Read once a request (mitOfficesForget after a write). */
function mitOffices($archived = false) {
    global $mysqli;
    $cache = &mitOfficesCache();
    if (!isset($cache['offices'])) {
        $cache['offices'] = [];
        if (mitOfficesReady()) {
            $sql = mysqli_query($mysqli, "SELECT office_id, office_name, office_address, office_city, office_state, office_zip, office_country, office_phone_country_code,
                office_phone, office_email, office_archived_at FROM mit_offices ORDER BY office_name");
            while ($row = mysqli_fetch_assoc($sql)) {
                $cache['offices'][intval($row['office_id'])] = mitOfficeShape($row);
            }
        }
    }
    return $archived ? $cache['offices'] : array_filter($cache['offices'], function ($office) {
        return $office['archived_at'] === null;
    });
}

function &mitOfficesCache() {
    static $cache = [];
    return $cache;
}

function mitOfficesForget() {
    $cache = &mitOfficesCache();
    $cache = [];
}

/** One office in one shape (an mit_offices row, or the company for the main one). */
function mitOfficeShape(array $row) {
    $office = [
        'office_id' => intval($row['office_id'] ?? 0),
        'name' => (string) ($row['office_name'] ?? ''),
        'address' => (string) ($row['office_address'] ?? ''),
        'city' => (string) ($row['office_city'] ?? ''),
        'state' => (string) ($row['office_state'] ?? ''),
        'zip' => (string) ($row['office_zip'] ?? ''),
        'country' => (string) ($row['office_country'] ?? ''),
        'phone_country_code' => (string) ($row['office_phone_country_code'] ?? ''),
        'phone' => (string) ($row['office_phone'] ?? ''),
        'email' => (string) ($row['office_email'] ?? ''),
        'archived_at' => $row['office_archived_at'] ?? null,
    ];
    $office['phone_display'] = $office['phone'] !== '' ? formatPhoneNumber($office['phone'], $office['phone_country_code']) : '';
    return $office;
}

/** The main office - the company as Admin > Settings > Company Details has it. */
function mitOfficeMain() {
    $company = billingCompany();
    return mitOfficeShape([
        'office_id' => 0,
        'office_name' => 'Main office',
        'office_address' => $company['company_address'] ?? '',
        'office_city' => $company['company_city'] ?? '',
        'office_state' => $company['company_state'] ?? '',
        'office_zip' => $company['company_zip'] ?? '',
        'office_country' => $company['company_country'] ?? '',
        'office_phone_country_code' => $company['company_phone_country_code'] ?? '',
        'office_phone' => $company['company_phone'] ?? '',
        'office_email' => $company['company_email'] ?? '',
    ]);
}

/** An office by id (0 = the main one); null when there is none (or it was removed, unless $archived). */
function mitOffice($office_id, $archived = false) {
    $office_id = intval($office_id);
    return $office_id === 0 ? mitOfficeMain() : (mitOffices($archived)[$office_id] ?? null);
}

/** Every office, the main one first - office_id => row. */
function mitOfficesAll() {
    return [0 => mitOfficeMain()] + mitOffices();
}

/** The office a login belongs to (0 = the main one, also when theirs was removed). */
function mitOfficeIdForUser($user_id) {
    global $mysqli;
    $user_id = intval($user_id);
    $cache = &mitOfficesCache();
    if (!isset($cache['users'])) {
        $cache['users'] = [];
        if (mitOfficesReady()) {
            $sql = mysqli_query($mysqli, "SELECT user_id, office_id FROM mit_user_offices");
            while ($row = mysqli_fetch_row($sql)) {
                $cache['users'][intval($row[0])] = intval($row[1]);
            }
        }
    }
    $office_id = $cache['users'][$user_id] ?? 0;
    return $office_id && isset(mitOffices()[$office_id]) ? $office_id : 0;
}

/** The office a login belongs to - the main one when none. */
function mitOfficeForUser($user_id) {
    return mitOffice(mitOfficeIdForUser($user_id)) ?? mitOfficeMain();
}

/** An office's address on one line (', ' or another separator), without the country. */
function mitOfficeAddressLine(array $office, $separator = ' ') {
    return formatAddress($office['address'], $office['city'], $office['state'], $office['zip'], '', $separator);
}

/** Put a login in an office (0 = the main one - no row). */
function mitOfficeSetUser($user_id, $office_id) {
    global $mysqli;
    $user_id = intval($user_id);
    $office_id = intval($office_id);
    if (!$user_id || !mitOfficesReady()) {
        return;
    }
    if ($office_id && isset(mitOffices()[$office_id])) {
        mysqli_query($mysqli, "INSERT INTO mit_user_offices SET user_id = $user_id, office_id = $office_id ON DUPLICATE KEY UPDATE office_id = $office_id");
    } else {
        mysqli_query($mysqli, "DELETE FROM mit_user_offices WHERE user_id = $user_id");
    }
    mitOfficesForget();
}

/** Who is in each office - office_id => [user_id ...] (agents not in one are under 0). */
function mitOfficePeople() {
    global $mysqli;
    $people = [0 => []];
    $sql = mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name");
    while ($row = mysqli_fetch_row($sql)) {
        $people[mitOfficeIdForUser($row[0])][] = intval($row[0]);
    }
    return $people;
}

/*
 * ---------- Admin > Settings > Company Details ----------
 */

/** The "Other offices" section (one line in admin/settings_company.php after the Contact section). */
function mitOfficesSection() {
    global $mysqli;
    if (!mitOfficesReady() || !function_exists('settingsSectionStart')) {
        return '';
    }
    $names = [];
    $sql = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name");
    while ($row = mysqli_fetch_row($sql)) {
        $names[intval($row[0])] = $row[1];
    }
    $people = mitOfficePeople();
    $rows = '';
    foreach (mitOfficesAll() as $office_id => $office) {
        $who = array_map(function ($user_id) use ($names) {
            return escapeHtml($names[$user_id] ?? '');
        }, $people[$office_id] ?? []);
        $contact = array_filter([escapeHtml(mitOfficeAddressLine($office, ', ')), escapeHtml($office['phone_display']), escapeHtml($office['email'])]);
        $rows .= '<tr data-mit-office="' . $office_id . '"><td><div class="fw-semibold">' . escapeHtml($office['name'])
            . ($office_id === 0 ? ' <span class="badge text-bg-secondary ms-1">Above</span>' : '') . '</div>'
            . '<div class="small text-body-secondary">' . ($contact ? implode(' &middot; ', $contact) : 'No address yet') . '</div></td>'
            . '<td class="small">' . ($who ? implode(', ', $who) : '<span class="text-body-secondary">Nobody</span>') . '</td>'
            . '<td class="text-end text-nowrap">' . ($office_id === 0 ? ''
                : '<button type="button" class="btn btn-sm btn-outline-secondary ajax-modal" data-modal-url="modals/mit_offices/office.php?office_id=' . $office_id . '" data-mit-office-edit><i class="fas fa-edit"></i><span class="d-none d-md-inline ms-1">Edit</span></button>')
            . '</td></tr>';
    }
    return settingsSectionStart('mit-offices', 'Offices', 'Other places you work from. Each agent belongs to one - their trips start there and their ticket emails show its address and phone. Purchase orders can ship to any of them. Invoices, quotes and contracts keep the main address above.')
        . '<div class="table-responsive" data-setting="mit_offices"><table class="table table-sm align-middle mb-2" data-mit-offices><thead><tr><th>Office</th><th>Who works there</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<button type="button" class="btn btn-sm btn-outline-primary ajax-modal" data-modal-url="modals/mit_offices/office.php" data-mit-office-add><i class="fas fa-plus me-2"></i>Add an office</button>'
        . '<div class="form-text">Agents not put in another office belong to the main one. An agent\'s office can also be set in Admin &gt; Users.</div>'
        . settingsSectionEnd();
}

/** Save an office from its window - [office_id, error]. */
function mitOfficeSave(array $post) {
    global $mysqli;
    $office_id = intval($post['office_id'] ?? 0);
    if ($office_id && !isset(mitOffices()[$office_id])) {
        return [0, 'That office is not there any more.'];
    }
    $name = trim((string) ($post['office_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        return [0, 'An office needs a name (up to 100 characters).'];
    }
    $email = trim((string) ($post['office_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [0, 'That email address does not look right.'];
    }
    $phone = mb_substr(preg_replace('/[^0-9]/', '', (string) ($post['office_phone'] ?? '')), 0, 200);
    $code = $phone === '' ? '' : mb_substr(preg_replace('/[^0-9]/', '', (string) ($post['office_phone_country_code'] ?? '')), 0, 10);
    $text = function ($key) use ($post) {
        return escapeSql(mb_substr(trim((string) ($post[$key] ?? '')), 0, 200));
    };
    $set = "office_name = '" . escapeSql($name) . "', office_address = '" . $text('office_address') . "', office_city = '" . $text('office_city') . "',
        office_state = '" . $text('office_state') . "', office_zip = '" . $text('office_zip') . "', office_country = '" . $text('office_country') . "',
        office_phone_country_code = '$code', office_phone = '$phone', office_email = '" . escapeSql($email) . "'";
    if ($office_id) {
        mysqli_query($mysqli, "UPDATE mit_offices SET $set WHERE office_id = $office_id");
    } else {
        mysqli_query($mysqli, "INSERT INTO mit_offices SET $set");
        $office_id = mysqli_insert_id($mysqli);
    }
    mitOfficesForget();

    // Who works there: those ticked are moved here; those unticked go back to the main office
    if (isset($post['mit_office_people_present'])) {
        $picked = array_filter(array_map('intval', (array) ($post['office_people'] ?? [])));
        foreach (mitOfficePeople() as $at => $user_ids) {
            foreach ($user_ids as $user_id) {
                if (in_array($user_id, $picked, true)) {
                    mitOfficeSetUser($user_id, $office_id);
                } elseif ($at === $office_id) {
                    mitOfficeSetUser($user_id, 0);
                }
            }
        }
    }
    return [$office_id, ''];
}

/** Remove an office - its people go back to the main one. Kept (archived) for the record. */
function mitOfficeRemove($office_id) {
    global $mysqli;
    $office_id = intval($office_id);
    if (!$office_id || !isset(mitOffices()[$office_id])) {
        return false;
    }
    mysqli_query($mysqli, "UPDATE mit_offices SET office_archived_at = NOW() WHERE office_id = $office_id");
    mysqli_query($mysqli, "DELETE FROM mit_user_offices WHERE office_id = $office_id");
    mitOfficesForget();
    return true;
}

/*
 * ---------- Admin > Users ----------
 */

/** The Office picker in the user window (one line in admin/modals/user/user_form.php) - only once there is another office. */
function mitOfficeUserField($user_id) {
    if (!mitOfficesReady() || !mitOffices()) {
        return '';
    }
    $current = mitOfficeIdForUser($user_id);
    $html = '<div class="col-12" data-mit-office-user><label class="form-label">Office</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fas fa-fw fa-map-marker-alt"></i></span><select class="form-select" name="mit_office_id">';
    foreach (mitOfficesAll() as $office_id => $office) {
        $html .= '<option value="' . $office_id . '"' . ($office_id === $current ? ' selected' : '') . '>' . escapeHtml($office['name']) . '</option>';
    }
    return $html . '</select></div><div class="form-text">Where they work from - their trips start there and their ticket emails show its address and phone.</div></div>';
}

/** Save the Office picker (one line each in admin/post/users.php add_user / edit_user); a no-op when the form did not have it. */
function mitOfficeUserSave($user_id, array $post) {
    if (isset($post['mit_office_id'])) {
        mitOfficeSetUser($user_id, intval($post['mit_office_id']));
    }
}

/*
 * ---------- Trips ----------
 */

/** Where a new trip by this driver starts: their office's address - '' when they are in the main one (ITFlow's own default then). */
function mitOfficeTripStart($driver_id) {
    $office_id = mitOfficesReady() ? mitOfficeIdForUser($driver_id) : 0;
    return $office_id ? mitOfficeAddressLine(mitOffice($office_id)) : '';
}

/**
 * Under the trip form's From box (one line in agent/modals/trip/trip_form.php): every office for its list, and - on a new trip -
 * changing the driver moves From to their office while it still shows the last driver's.
 */
function mitOfficeTripScript($form_id, array $driver_ids, $new) {
    if (!mitOfficesReady() || !mitOffices()) {
        return '';
    }
    $places = [];
    foreach (mitOfficesAll() as $office) {
        $line = mitOfficeAddressLine($office);
        if ($line !== '') {
            $places[] = $line;
        }
    }
    $drivers = [];
    foreach ($driver_ids as $driver_id) {
        $drivers[intval($driver_id)] = mitOfficeAddressLine(mitOfficeForUser($driver_id));
    }
    $id = preg_replace('/[^A-Za-z0-9]/', '', (string) $form_id);
    return '<script>(function () {'
        . 'var list = document.getElementById(' . json_encode($id . 'Places') . '), root = document.getElementById(' . json_encode($id) . ');'
        . 'var places = ' . json_encode($places) . ', drivers = ' . json_encode($drivers) . ';'
        . 'if (list) { var have = Array.prototype.map.call(list.options, function (o) { return o.value; });'
        . 'places.forEach(function (p) { if (have.indexOf(p) < 0) { var o = document.createElement("option"); o.value = p; list.appendChild(o); } }); }'
        . ($new ? 'if (!root) return; var driver = root.querySelector("[data-trip-driver]"), from = root.querySelector("[data-trip-from]");'
            . 'if (!driver || !from) return; var last = drivers[driver.value] || "";'
            . 'var follow = function () { var next = drivers[driver.value] || ""; if (next && (from.value === "" || from.value === last || places.indexOf(from.value) >= 0)) { from.value = next; from.dispatchEvent(new Event("input", {bubbles: true})); } last = next; };'
            . 'driver.addEventListener("change", follow); if (window.jQuery) { window.jQuery(driver).on("change", follow); }' : '')
        . '})();</script>';
}

/*
 * ---------- Ticket emails ----------
 */

/**
 * The office a ticket email's foot shows - the assigned agent's, else the agent sending it; null when that is the main office
 * (ITFlow's own foot then). On the email array as 'mit_office' (one same-line addition at each ticketEmailContactLine() in
 * functions/ticket.php); emailHtml swaps it in (one line in functions/email.php).
 */
function mitOfficeForTicket($row) {
    if (!is_array($row) || !mitOfficesReady() || !mitOffices()) {
        return null;
    }
    $user_id = intval($row['ticket_assigned_to'] ?? 0);
    if (!$user_id && !empty($GLOBALS['session_user_id']) && empty($_SESSION['client_logged_in'])) {
        $user_id = intval($GLOBALS['session_user_id']);
    }
    $office_id = $user_id ? mitOfficeIdForUser($user_id) : 0;
    if (!$office_id) {
        return null;
    }
    $office = mitOffice($office_id);
    $company = billingCompany();
    $from = function_exists('ticketEmailFrom') ? (string) (ticketEmailFrom()['email'] ?? '') : '';
    return [
        'company_address' => $office['address'],
        'company_city' => $office['city'],
        'company_state' => $office['state'],
        'company_zip' => $office['zip'],
        'company_country' => $office['country'] !== '' ? $office['country'] : (string) ($company['company_country'] ?? ''),
        'contact_line' => implode('  ·  ', array_filter([
            $office['phone_display'] !== '' ? $office['phone_display'] : (string) ($company['phone_display'] ?? ''),
            $office['email'] !== '' ? $office['email'] : $from,
            (string) ($company['company_website'] ?? ''),
        ])),
    ];
}

/** emailHtml's side: the office's address and contact line in place of the company's (functions/email.php, one line). */
function mitOfficeEmailCompany(array $company, array &$email) {
    if (empty($email['mit_office']) || !is_array($email['mit_office'])) {
        return $company;
    }
    $office = $email['mit_office'];
    $email['contact'] = (string) ($office['contact_line'] ?? '');
    unset($office['contact_line']);
    return array_merge($company, $office);
}

/*
 * ---------- Purchase orders ----------
 */

/** What a PO ships to for an office: the main one is Procurement's own Ship to setting; the others are built from their address. */
function mitOfficeShipTo($office_id) {
    $office_id = intval($office_id);
    if ($office_id === 0 || !mitOffice($office_id)) {
        return mitProcSettings()['ship_to'];
    }
    $office = mitOffice($office_id);
    $company = (string) (billingCompany()['company_name'] ?? '');
    return trim(($company !== '' ? "$company - " : '') . $office['name'] . "\n" . mitOfficeAddressLine($office, "\n"));
}

/** The office a PO's ship_to column names ('office:<id>'; 'office' = the main one) - null when it ships somewhere typed. */
function mitOfficeFromShipTo($ship_to) {
    if ($ship_to === 'office') {
        return 0;
    }
    return preg_match('/^office:(\d+)$/', (string) $ship_to, $m) ? intval($m[1]) : null;
}
