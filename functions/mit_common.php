<?php

/*
 * Mountaineer IT - small helpers shared by our features (the mit_*.php files in this folder).
 * Required from functions.php before them.
 */

/**
 * An HTML email body made ready for addToMailQueue(), which takes SQL-escaped values. The pieces inside
 * must already be escapeHtml()'d; this only escapes the whole for SQL. escapeSql() is not usable here:
 * it runs strip_tags() and would take out the line breaks and links (as ticketMailSchedule() does it).
 */
function mitMailBody($html) {
    return mitSqlHtml($html);
}

/**
 * HTML for a column that holds HTML (email bodies, ticket replies), escaped for SQL only - the pieces inside
 * must already be escapeHtml()'d. escapeSql() would strip_tags() it.
 */
function mitSqlHtml($html) {
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $html);
}

/** Is this product a tangible one (ITFlow keeps stock only for those)? */
function mitProductIsTangible($product_id) {
    return intval($product_id) > 0 && getFieldById('products', intval($product_id), 'product_type') === 'product';
}

/** Admin > Roles: what each level of our modules lets a role do (ITFlow's roleModuleInfo, one guarded line). */
function mitRoleModuleInfo() {
    return [
        'module_procurement' => [
            'label' => 'Procurement', 'icon' => 'fa-truck-loading', 'about' => 'Items to order, purchase orders and receiving',
            'levels' => [1 => 'See them', 2 => 'Add items, make, send and receive purchase orders', 3 => 'Approve and cancel purchase orders'],
        ],
        'module_timesheets' => [
            'label' => 'Timesheets', 'icon' => 'fa-user-clock', 'about' => 'Everyone keeps their own - this is for other people\'s',
            'levels' => [1 => 'See everyone\'s and the payroll report', 2 => 'Correct them', 3 => 'Approve them'],
        ],
        'module_dispatch' => [
            'label' => 'Dispatch', 'icon' => 'fa-th-list', 'about' => 'The dispatch board - booking needs Support at Modify too',
            'levels' => [1 => 'See the board', 2 => 'Book, move, assign and merge tickets'], 'max' => 2,
        ],
    ];
}

/**
 * Have our database updates run (mit_settings and mit_database_updates exist)? Not yet for a short while after an install or a
 * restore - our cron jobs then wait (cronJobStop, recorded as Stopped, not Failed); the consistency check reports it if it lasts.
 */
function mitDbReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('mit_settings', 'mit_database_updates')"))[0]) === 2;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Does an ITFlow table have this column (a newer ITFlow added it)? Once per request. */
function mitColumnReady($table, $column) {
    global $mysqli;
    static $known = [];
    $key = "$table.$column";
    if (!isset($known[$key])) {
        $known[$key] = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE '" . mysqli_real_escape_string($mysqli, $column) . "'")) > 0;
    }
    return $known[$key];
}

/**
 * Does ITFlow keep a stock count for this product? ITFlow 26.11 moves stock only for products with product_track_stock on
 * (billingItemSave, billingCopyLines, billingItemDelete); before it, every product (not service) had stock.
 */
function mitProductTracksStock($product_id) {
    if (!mitColumnReady('products', 'product_track_stock')) {
        return mitProductIsTangible($product_id);
    }
    return intval($product_id) > 0 && intval(getFieldById('products', intval($product_id), 'product_track_stock')) === 1;
}

/**
 * A stock movement, through ITFlow's own record (productStockMove: type, who, cost, invoice, the reorder notice) when it has one.
 * $qty: + onto the shelf, - off it. $type: received | sold | returned | adjusted | written_off.
 */
function mitStockMove($product_id, $qty, $type, $note, array $opts = []) {
    global $mysqli;
    if (!mitProductTracksStock($product_id) || round((float) $qty, 2) == 0) {
        return;
    }
    if (function_exists('productStockMove')) {
        productStockMove($product_id, $qty, $type, $opts + ['note' => $note]);
        return;
    }
    mysqli_query($mysqli, "INSERT INTO product_stock SET stock_qty = " . round((float) $qty, 2) . ", stock_note = '" . escapeSql(mb_substr($note, 0, 500)) . "', stock_product_id = " . intval($product_id));
}

/**
 * Is one of our modules switched on (Admin > Settings > Modules - admin/includes/mit_modules_fields.php)?
 * $name: procurement | timesheets | dispatch. On unless switched off; saved in mit_settings as module_<name>.
 * Procurement also needs ITFlow's invoicing module, and dispatch its ticketing module.
 */
function mitModuleOn($name) {
    global $config_module_enable_accounting, $config_module_enable_ticketing;
    if ($name === 'procurement' && intval($config_module_enable_accounting ?? 1) !== 1) {
        return false;
    }
    if ($name === 'dispatch' && intval($config_module_enable_ticketing ?? 1) !== 1) {
        return false;
    }
    return mitSetting("module_$name", '1') !== '0';
}

/**
 * Stop here when one of our modules is switched off: a page shows why and ends (after inc_all.php), a modal
 * answers with a JSON error, as ITFlow's modals do. ITFlow itself only hides a switched-off module's menus; ours
 * also close their pages, so a bookmark does not lead into a feature that is meant to be off.
 */
function mitModuleRequire($name, $modal = false) {
    if (mitModuleOn($name)) {
        return;
    }
    $text = ucfirst($name) . ' is switched off (Admin > Settings > Modules).';
    if ($modal) {
        exit(json_encode(['error' => $text]));
    }
    echo '<div class="alert alert-warning">' . escapeHtml($text) . '</div>';
    // The footer (included here, in this function's scope) reads the page's titles, which a page stopped this early has not set
    $tab_title = $GLOBALS['tab_title'] ?? ucfirst($name);
    $page_title = $GLOBALS['page_title'] ?? ucfirst($name);
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/**
 * For a handler file (agent/post/): when the module is switched off and the request is one of its own (a GET or
 * POST key starting with one of $prefixes), say so and go back. Other requests pass by untouched, since every
 * handler file is loaded for every request.
 */
function mitModuleGuard($name, array $prefixes) {
    if (mitModuleOn($name)) {
        return;
    }
    foreach (array_keys($_GET + $_POST) as $key) {
        foreach ($prefixes as $prefix) {
            if (strpos((string) $key, $prefix) === 0) {
                flashAlert(escapeHtml(ucfirst($name) . ' is switched off (Admin > Settings > Modules).'), 'error');
                redirect();
            }
        }
    }
}

/** Our admin settings pages, so ITFlow's SETTINGS menu opens on them (admin/includes/side_nav.php). */
function mitAdminSettingsPages() {
    return ['mit_procurement_settings.php', 'mit_timesheets.php', 'mit_boards.php'];
}

/** Save our module switches from Admin > Settings > Modules (hook in admin/post/settings_module.php). */
function mitModulesSave(array $post) {
    $lines = []; // what changed, in words - for ITFlow's settingsSave (its "Nothing changed" otherwise)
    foreach (['procurement', 'timesheets', 'dispatch'] as $name) {
        if (isset($post["mit_module_$name"])) {
            $on = $post["mit_module_$name"] === '1' ? '1' : '0';
            if ((string) mitSetting("module_$name", "1") !== $on) {
                $lines[] = ucfirst($name) . ($on === '1' ? ' turned on' : ' turned off');
            }
            mitSetSetting("module_$name", $on);
        }
    }
    return $lines;
}

/**
 * Sign-ins end when the password or MFA changes: each session keeps a fingerprint of the user's password hash and MFA
 * secret (mitSessionStamp), checked on every page (includes/load_user_session.php, client/includes/check_login.php).
 * A password reset - by an admin, the "reset all passwords" button or the person - or MFA turned on / off changes it, so
 * every other sign-in of that person ends at its next click. The session that made its own change is restamped
 * (mitSessionRestamp) and carries on. No table of its own - the fingerprint comes from ITFlow's users row.
 */
function mitSessionStamp($user_id) {
    global $mysqli;
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_password, user_token FROM users WHERE user_id = " . intval($user_id)));
    return $row ? substr(hash('sha256', (string) $row[0] . '|' . (string) $row[1]), 0, 32) : '';
}

/** Whose fingerprint the session holds: the user id and agent / portal (a portal sign-in carries contact_id). */
function mitSessionStampOwner($user_id) {
    return (isset($_SESSION['contact_id']) ? 'portal' : 'agent') . ':' . intval($user_id);
}

/**
 * True while this session's sign-in still stands. The first page after signing in takes the fingerprint - also when the
 * browser signed in as someone else (or the same person on the other side) without signing out first, since login.php
 * keeps the old session's data: a fingerprint taken for another sign-in is replaced, not compared.
 */
function mitSessionCheck($user_id) {
    $stamp = mitSessionStamp($user_id);
    $owner = mitSessionStampOwner($user_id);
    if (!isset($_SESSION['mit_session_stamp']) || ($_SESSION['mit_session_stamp_owner'] ?? '') !== $owner) {
        $_SESSION['mit_session_stamp'] = $stamp;
        $_SESSION['mit_session_stamp_owner'] = $owner;
        return true;
    }
    return hash_equals((string) $_SESSION['mit_session_stamp'], $stamp);
}

/** After the signed-in person changed their own password or MFA: this session carries on. */
function mitSessionRestamp($user_id) {
    if (session_status() === PHP_SESSION_ACTIVE && intval($_SESSION['user_id'] ?? 0) === intval($user_id)) {
        $_SESSION['mit_session_stamp'] = mitSessionStamp($user_id);
        $_SESSION['mit_session_stamp_owner'] = mitSessionStampOwner($user_id);
    }
}

/**
 * HTML from somewhere we don't control (an AI model's answer, written from ticket text a customer typed) made safe to
 * show: the ticket page's HTMLPurifier rules, without the class that makes our scripts load a pop-up from a link.
 */
function mitPurifyHtml($html) {
    static $purifier = null;
    if ($purifier === null) {
        require_once __DIR__ . '/../libs/htmlpurifier/HTMLPurifier.standalone.php';
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
        $config->set('Attr.ForbiddenClasses', ['ajax-modal']);
        $purifier = new HTMLPurifier($config);
    }
    return $purifier->purify((string) $html);
}

/** The tenant (tid) of a Microsoft access token, read from its payload (it came straight from Microsoft), or ''. */
function mitPortalEntraTenant($access_token) {
    $parts = explode('.', (string) $access_token);
    $payload = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    $tid = is_array($payload) ? (string) ($payload['tid'] ?? '') : '';
    return preg_match('/^[0-9a-f-]{36}$/i', $tid) ? strtolower($tid) : '';
}

/**
 * May a contact of this client sign in to the portal from this Microsoft 365 organization? The first Entra sign-in for
 * a client records its organization (mit_settings portal_entra_tenant_<client>); later ones must come from the same one.
 * An admin clears the setting to change it. No tenant read = refused.
 */
function mitPortalEntraTenantOk($client_id, $tenant) {
    $client_id = intval($client_id);
    if ($tenant === '' || !$client_id) {
        return false;
    }
    $known = (string) mitSetting("portal_entra_tenant_$client_id", '');
    if ($known === '') {
        mitSetSetting("portal_entra_tenant_$client_id", $tenant);
        return true;
    }
    return hash_equals($known, $tenant);
}

/**
 * The system mail From address and name, read from the settings as stored. Not the $config_mail_from_* globals: a
 * cron job before ours (nightly_tasks) SQL-escapes those in the shared scope, so "O'Brien" would go out as "O\'Brien".
 */
function mitMailFrom() {
    global $mysqli;
    static $from = null;
    if ($from === null) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_from_email, config_mail_from_name FROM settings WHERE company_id = 1")) ?: [];
        $from = ['email' => (string) ($row['config_mail_from_email'] ?? ''), 'name' => (string) ($row['config_mail_from_name'] ?? '')];
    }
    return $from;
}

/**
 * After a restore (setup/index.php, scripts/restore_cli.php, Admin > Backup): ITFlow's own database updates up to this
 * release, as update_cli runs them, so ours come after. Returns '' or "version: error". In a web request ITFlow's
 * load_global_settings.php has already fixed CURRENT_DATABASE_VERSION at the version before the restore, so there the
 * same steps as admin/database_updates.php are taken here (its files, in order, the version bumped after each).
 */
function mitRestoreItflowUpdates() {
    global $mysqli;
    defined('LATEST_DATABASE_VERSION') || require __DIR__ . '/../includes/database_version.php';
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_current_database_version FROM settings LIMIT 1"));
    $current = trim((string) ($row[0] ?? ''));
    if ($current === '' || !version_compare($current, LATEST_DATABASE_VERSION, '<')) {
        return '';
    }
    if (!defined('CURRENT_DATABASE_VERSION')) {
        define('CURRENT_DATABASE_VERSION', $current);
        $database_updates_applied = [];
        $database_updates_error = null;
        require __DIR__ . '/../admin/database_updates.php';
        return (string) $database_updates_error;
    }
    defined('FROM_DB_UPDATER') || define('FROM_DB_UPDATER', true);
    $files = [];
    foreach (glob(__DIR__ . '/../admin/database_updates/*.php') as $file) {
        if (preg_match('/^\d+(\.\d+)+$/', basename($file, '.php'))) {
            $files[basename($file, '.php')] = $file;
        }
    }
    uksort($files, 'version_compare');
    foreach ($files as $version => $file) {
        if (version_compare($version, $current, '<=')) {
            continue;
        }
        try {
            mitBeforeItflowUpdate($version);
            require $file;
        } catch (Throwable $e) {
            error_log("ITFlow database update to version $version failed: " . $e->getMessage());
            return "$version: " . $e->getMessage();
        }
        mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '" . escapeSql($version) . "'");
        $current = $version;
    }
    return '';
}

/**
 * A named database lock (MySQL GET_LOCK) so two requests can't do the same thing at once - a double click, two approvers.
 * $name is made safe here. Returns true when it was taken within $timeout seconds; release it with mitUnlock().
 */
function mitLock($name, $timeout = 15) {
    global $mysqli;
    $name = 'mit_' . substr(preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $name)), 0, 56);
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('$name', " . intval($timeout) . ")"));
    return intval($row[0] ?? 0) === 1;
}

function mitUnlock($name) {
    global $mysqli;
    $name = 'mit_' . substr(preg_replace('/[^a-z0-9_]/', '_', strtolower((string) $name)), 0, 56);
    mysqli_query($mysqli, "SELECT RELEASE_LOCK('$name')");
}

/**
 * The address of one of our scripts with its file time on the end (/js/x.js?v=1729...), so a browser
 * fetches it again after an update instead of running the copy it kept.
 */
function mitAsset($path) {
    $file = dirname(__DIR__) . $path;
    return escapeHtml($path . (is_file($file) ? '?v=' . filemtime($file) : ''));
}

require_once __DIR__ . '/mit_db_upgrade.php'; // before / after ITFlow's database updates
require_once dirname(__DIR__) . '/includes/mit_version.php';

/** Our build's version (26.10.1 ...) - includes/mit_version.php. */
function mitVersion() {
    return MIT_VERSION;
}

/**
 * Our release notes from MIT_CHANGELOG.md, newest first: [['version', 'date', 'items' => [lines]]].
 * A release is a "## <version> - <date>" heading followed by "- " lines.
 */
function mitChangelog() {
    $file = dirname(__DIR__) . '/MIT_CHANGELOG.md';
    $releases = [];
    foreach (is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match('/^##\s+([0-9][0-9.]*)\s*(?:-\s*(.+))?$/', $line, $m)) {
            $releases[] = ['version' => $m[1], 'date' => trim($m[2] ?? ''), 'items' => []];
        } elseif ($releases && preg_match('/^\s*-\s+(.+)$/', $line, $m)) {
            $releases[count($releases) - 1]['items'][] = trim(str_replace('`', '', $m[1]));
        }
    }
    return $releases;
}

/** Side-menu counts (agent/includes/mit_*_nav.php), kept per agent in the session for a minute - they ran on every page. The
 * key carries what shapes the count (SQL / client scope); the count's own page passes $fresh so it is always worked out anew. */
function mitNavCachedCount($key, callable $compute, $fresh = false) {
    global $session_user_id;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return $compute();
    }
    $key = intval($session_user_id) . ':' . md5($key);
    $hit = $_SESSION['mit_nav_counts'][$key] ?? null;
    if (!$fresh && is_array($hit) && $hit['at'] > time() - 60) {
        return $hit['value'];
    }
    $value = $compute();
    $_SESSION['mit_nav_counts'] = array_filter($_SESSION['mit_nav_counts'] ?? [], fn($c) => is_array($c) && $c['at'] > time() - 60);
    $_SESSION['mit_nav_counts'][$key] = ['at' => time(), 'value' => $value];
    return $value;
}

require_once __DIR__ . '/mit_backup_restore.php'; // Admin > Backup: restore on a running install
require_once __DIR__ . '/mit_dev_mode.php'; // development mode (config.php) - nothing reaches the outside world
