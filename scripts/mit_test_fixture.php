<?php
/*
 * Mountaineer IT - the data the scenarios and smoke tests take for granted, on a freshly installed TEST install.
 *
 *   php scripts/mit_test_fixture.php [/var/www/itflow]      (as root, on the test server only - refuses an install that is not *.test)
 *
 * Written Nov 17 2026 after a rebuild: the container holding the old test install went, and these were only ever set by hand there, so
 * a fresh install failed about 30 scenarios / smoke tests on data alone (no client 1, no contact 3, no SLA, mail not set up, ...). Safe to run
 * again - it only adds what is missing and sets settings to the values below. The test logins' passwords: tests/smoke/.env (ITFLOW_PASSWORD
 * for admin@mountaineerit.test, ITFLOW_TECH_PASSWORD for tech@test.test - this script sets the technician's from ITFLOW_TECH_PASSWORD when
 * given, else Fixture-Pass-123!). The rest of the server, as scripts/install.sh sets it: the server's clock on the company's time zone
 * (America/Los_Angeles here - the tests read "today" from MariaDB, PHP and Node), PHP upload_max_filesize / post_max_size 500M.
 * The outside services' stand-ins (QuickBooks, NinjaOne, Microsoft 365, Customer Thermometer, MSP Align, usage connectors, AI, Ollama)
 * are not in the repository - their tests skip without them.
 */
if (php_sapi_name() !== 'cli') {
    die("Run this from the command line.\n");
}
$root = rtrim($argv[1] ?? '/var/www/itflow', '/');
chdir($root);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'mit_test_fixture';
require_once 'config.php';
if (!preg_match('/\.test$/', (string) ($config_base_url ?? ''))) {
    die("Not a test install ($config_base_url is not *.test) - refusing.\n");
}
require_once 'functions.php';
require_once 'includes/load_global_settings.php';
$session_user_id = 1;
$session_name = 'Test fixture';

$q = function ($sql) use ($mysqli) {
    mysqli_query($mysqli, $sql);
    return mysqli_insert_id($mysqli);
};
$has = function ($sql) use ($mysqli) {
    return mysqli_num_rows(mysqli_query($mysqli, $sql)) > 0;
};

// Logins: 2 the technician (tests/smoke/.env), 6 the Accountant, 10 a technician limited to client 31 (approved by the technician, so their
// Person picker shows - 70); 3 and 5 only fill ids, disabled - with them on, Dispatch's day view squeezed the technician's column off the edge
// (92-dispatch-dialog dropped on the wrong agent). Names matter: 42-helpers expects "Restricted Tech", 40 types "@Rest" (so no other name may
// contain it), 20 and 03 take the first other agent by name - one who can work client 1 ("Zack" / "Zoe" sort the limited login and the
// Accountant last).
$people = [
    2 => ['Restricted Tech', 'tech@test.test', 2, 1, getenv('ITFLOW_TECH_PASSWORD') ?: 'Fixture-Pass-123!'],
    3 => ['Bob Field', 'bob@test.test', 2, 0, 'Fixture-Pass-123!'],
    5 => ['Dan Desk', 'dan@test.test', 2, 0, 'Fixture-Pass-123!'],
    6 => ['Zoe Accountant', 'acct@test.test', 1, 1, 'Fixture-Pass-123!'],
    10 => ['Zack Limited', 'limited@test.test', 2, 1, 'Fixture-Pass-123!'],
];
foreach ($people as $id => [$name, $email, $role, $status, $password]) {
    if (!$has("SELECT 1 FROM users WHERE user_id = $id")) {
        $q("INSERT INTO users SET user_id = $id, user_name = '$name', user_email = '$email', user_password = '', user_role_id = $role, user_type = 1, user_status = $status");
        userSetPassword($id, $password);
        $q("INSERT IGNORE INTO user_settings SET user_id = $id, user_config_force_mfa = 0");
    } else {
        mysqli_query($mysqli, "UPDATE users SET user_name = '$name', user_email = '$email', user_role_id = $role, user_status = $status WHERE user_id = $id");
    }
}

// Clients: 1 (the one the tests open by id), its contact 3 and assets 8-10 (92-dispatch-dialog); two more, 31 the limited login's
$client = function ($id, $name) use ($q, $has) {
    if (!$has("SELECT 1 FROM clients WHERE client_id = $id")) {
        $q("INSERT INTO clients SET client_id = $id, client_name = '$name', client_currency_code = 'USD'");
        $q("INSERT INTO locations SET location_name = 'Main', location_primary = 1, location_client_id = $id");
        $q("INSERT INTO contacts SET contact_name = 'Fixture Primary', contact_email = 'primary-$id@example.invalid', contact_primary = 1, contact_client_id = $id");
    }
};
$client(1, 'Fixture Client One');
$client(30, 'Acme Fixture Corp');
$client(31, 'Beta Fixture LLC');
if (!$has("SELECT 1 FROM contacts WHERE contact_id = 3")) {
    $q("INSERT INTO contacts SET contact_id = 3, contact_name = 'Casey Contact', contact_email = 'casey@example.invalid', contact_client_id = 1");
}
foreach ([8 => ['ACME-PC1', 'Desktop', 'Dell', 3], 9 => ['ACME-PC2', 'Desktop', 'Dell', 3], 10 => ['Front Desk - Printer', 'Printer', 'HP', 0]] as $id => [$name, $type, $make, $contact]) {
    if (!$has("SELECT 1 FROM assets WHERE asset_id = $id")) {
        $q("INSERT INTO assets SET asset_id = $id, asset_name = '$name', asset_type = '$type', asset_make = '$make', asset_client_id = 1, asset_contact_id = $contact");
    }
}
mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE user_id = 10");
$q("INSERT INTO user_client_permissions SET user_id = 10, client_id = 31, permission_type = 'allow'");
// An open ticket on client 1, so the Tickets list has a status badge (03-our-features)
if (!$has("SELECT 1 FROM tickets WHERE ticket_subject = 'Fixture open ticket'")) {
    $n = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_ticket_next_number FROM settings WHERE company_id = 1"))[0]);
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    $q("INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = $n, ticket_subject = 'Fixture open ticket', ticket_details = 'Kept open for the list pages',
        ticket_priority = 'Medium', ticket_status = 2, ticket_billable = 1, ticket_created_by = 1, ticket_assigned_to = 1, ticket_contact_id = 3, ticket_client_id = 1,
        ticket_url_key = '" . bin2hex(random_bytes(16)) . "'");
}

// A company vendor, a bank account, an SLA with targets, a product (the products API answers), the Oct 12 closure day
if (!$has("SELECT 1 FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL")) {
    $q("INSERT INTO vendors SET vendor_name = 'Fixture Supply Co', vendor_email = 'orders@fixture-supply.example.invalid', vendor_client_id = 0");
}
$account = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT account_id FROM accounts ORDER BY account_id LIMIT 1"))[0] ?? 0)
    ?: $q("INSERT INTO accounts SET account_name = 'Fixture Checking', account_currency_code = 'USD', account_type = 11");
if (!$has("SELECT 1 FROM slas WHERE sla_archived_at IS NULL AND sla_resolution_minutes > 0")) {
    $q("INSERT INTO slas SET sla_name = 'Fixture Standard', sla_description = 'Fixture', sla_response_minutes = 240, sla_resolution_minutes = 2880");
}
if (!$has("SELECT 1 FROM products WHERE product_archived_at IS NULL")) {
    $q("INSERT INTO products SET product_name = 'Fixture Support Hour', product_price = 125, product_currency_code = 'USD', product_type = 'service', product_category_id = 0, product_tax_id = 0");
}
if (!$has("SELECT 1 FROM business_holidays WHERE holiday_date = '2026-10-12'")) {
    $q("INSERT INTO business_holidays SET holiday_date = '2026-10-12', holiday_name = 'Columbus Day'");
}

// Mail set up (queued only - 127.0.0.1:25 has no server), client notifications on, ITFlow's mileage rate
mysqli_query($mysqli, "UPDATE settings SET config_smtp_provider = 'standard_smtp', config_smtp_host = '127.0.0.1', config_smtp_port = 25, config_smtp_encryption = '',
    config_mail_from_email = 'noreply@mountaineerit.test', config_mail_from_name = 'Mountaineer IT Test',
    config_invoice_from_email = 'billing@mountaineerit.test', config_invoice_from_name = 'Billing',
    config_quote_from_email = 'sales@mountaineerit.test', config_quote_from_name = 'Sales',
    config_ticket_from_email = 'support@mountaineerit.test', config_ticket_from_name = 'Support',
    config_ticket_client_general_notifications = 1, config_mileage_rate = 0.70 WHERE company_id = 1");

// Roles: Technician Procurement Edit, Accountant Procurement View (62-procurement-roles); our modules are granted to nobody by our updates
$module = function ($name) use ($mysqli) {
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT module_id FROM modules WHERE module_name = '$name'"))[0] ?? 0);
};
if ($procurement = $module('module_procurement')) {
    $q("INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level) VALUES (2, $procurement, 2), (1, $procurement, 1)
        ON DUPLICATE KEY UPDATE user_role_permission_level = VALUES(user_role_permission_level)");
}

// Timesheets as the tests were written against: week from Sunday, 15-minute rounding, internal notes not counted, overtime past 8 h a day /
// 40 a week, auto lost time, California from Jan 1, payroll paying from the account; Holiday fills closure days (max 8 h a day), Lost time
// needs a note; the admin, the technician and the limited login keep timesheets (8 h weekdays), the technician approves the limited login's;
// the admin reads h:mm, everyone else decimal
foreach (['ts_week_start' => '7', 'ts_rounding' => '15', 'ts_ticket_internal' => '0', 'ts_ot_day' => '480', 'ts_ot_week' => '2400', 'ts_auto_lost' => '1',
    'ts_ca_start' => '2026-01-01', 'ts_mileage_rate' => '0.70', 'ts_reimb_account' => (string) $account] as $name => $value) {
    mitSetSetting($name, $value);
}
mitDeleteSetting('ts_format');
mysqli_query($mysqli, "UPDATE mit_charge_codes SET code_closure_fill = 1, code_daily_max = 480 WHERE code_name = 'Holiday'");
mysqli_query($mysqli, "UPDATE mit_charge_codes SET code_note_required = 1 WHERE code_name = 'Lost time'");
foreach ([1 => 'ca_rules = 0, approver_id = 0', 2 => 'approver_id = 0', 10 => 'approver_id = 2'] as $id => $extra) {
    $q("INSERT INTO mit_timesheet_people SET user_id = $id, schedule = '480,480,480,480,480,0,0', pay_type = 'hourly', keeps_timesheet = 1, hire_date = '2026-01-05', $extra
        ON DUPLICATE KEY UPDATE schedule = VALUES(schedule), keeps_timesheet = 1, hire_date = VALUES(hire_date), $extra");
}
mitSetUserPreference(1, 'timesheet.format', 'hm');

echo "Test fixture in place (account $account).\n";
