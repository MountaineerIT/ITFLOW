<?php

/*
 * Mountaineer IT - Microsoft sends people back here after they sign in to connect their
 * Outlook calendar (Account > Outlook calendar, functions/mit_outlook.php). Registered as a
 * redirect address on ITFlow's Microsoft 365 app.
 */

require_once "../../config.php";
require_once "../../functions.php";
require_once "../../includes/check_login.php";

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['mit_outlook_state'] ?? '');
$fresh = time() <= intval($_SESSION['mit_outlook_state_expires'] ?? 0);
unset($_SESSION['mit_outlook_state'], $_SESSION['mit_outlook_state_expires']);

if ($expected === '' || !$fresh || !hash_equals($expected, $state)) {
    flashAlert("That Outlook sign-in had expired or did not start here - click Connect Outlook again.", 'error');
    redirect('mit_outlook.php');
}

if (!empty($_GET['error'])) {
    flashAlert("Microsoft did not connect Outlook: " . escapeHtml($_GET['error_description'] ?? $_GET['error']), 'error');
    redirect('mit_outlook.php');
}

try {
    $link = mitOutlookConnect($session_user_id, (string) ($_GET['code'] ?? ''));
} catch (Throwable $e) {
    flashAlert("Could not connect Outlook: " . escapeHtml($e->getMessage()), 'error');
    redirect('mit_outlook.php');
}
logAudit("User Account", "Edit", "$session_name connected their Outlook calendar (" . escapeSql($link['account_email']) . ")");

try {
    $summary = mitOutlookSync($session_user_id, true);
    flashAlert("Outlook connected as " . escapeHtml($link['account_email']) . " - " . escapeHtml(strtolower($summary)) . ".");
} catch (Throwable $e) {
    flashAlert("Outlook connected as " . escapeHtml($link['account_email']) . ", but the first sync failed: " . escapeHtml($e->getMessage()), 'warning');
}
// Another account than the person's own (a shared or service mailbox the browser was signed in to) gets their bookings,
// and its appointments become their busy times - say so, so they can reconnect with their own
if (strcasecmp(trim((string) $link['account_email']), trim((string) $session_email)) !== 0) {
    flashAlert("Note: that is not your ITFlow email (" . escapeHtml($session_email) . ") - if it is not your own calendar, click Disconnect and connect again with your own account.", 'warning');
}
redirect('mit_outlook.php');
