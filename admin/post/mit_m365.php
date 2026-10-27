<?php

/*
 * Mountaineer IT - Microsoft 365 app setup (functions/mit_m365.php), from Admin > Settings > Mail.
 * Only loaded for admins (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Step 1 - start the admin's sign-in: a code to enter at microsoft.com/devicelogin
if (isset($_POST['mit_m365_start'])) {

    validateCSRFToken();

    $start = mitM365DeviceCodeStart();
    if (!empty($start['error'])) {
        mitM365Json(['error' => $start['error']], 502);
    }
    $_SESSION['mit_m365_device_code'] = $start['device_code'];
    $_SESSION['mit_m365_device_expires'] = time() + intval($start['expires_in'] ?? 900);
    // 'update': bring the existing app up to today's permissions; 'calendar': make the calendar-only app for Outlook;
    // otherwise create the app - never a second one over the first (Outlook links and the mailbox are tied to it)
    // 'calendar_update': bring the calendar-only app up to today's permissions
    $purpose = in_array($_POST['purpose'] ?? '', ['update', 'calendar', 'calendar_update'], true) ? $_POST['purpose'] : 'create';
    if ($purpose === 'create' && mitM365App()) {
        mitM365Json(['error' => 'ITFlow already has its Microsoft 365 app - Remove it first to set up a new one.'], 409);
    }
    $_SESSION['mit_m365_device_purpose'] = $purpose;

    mitM365Json([
        'user_code' => $start['user_code'],
        'verification_uri' => $start['verification_uri'] ?? 'https://microsoft.com/devicelogin',
        'interval' => max(3, intval($start['interval'] ?? 5)),
        'expires_in' => intval($start['expires_in'] ?? 900),
    ]);
}

// Step 2 - asked every few seconds until the admin has signed in, then creates the app
if (isset($_POST['mit_m365_poll'])) {

    validateCSRFToken();

    $device_code = $_SESSION['mit_m365_device_code'] ?? '';
    if ($device_code === '' || time() > intval($_SESSION['mit_m365_device_expires'] ?? 0)) {
        mitM365Json(['error' => 'The sign-in code has expired - start again.'], 410);
    }

    $poll = mitM365DeviceCodePoll($device_code);
    if (!empty($poll['pending'])) {
        mitM365Json(['pending' => true, 'slow_down' => !empty($poll['slow_down'])]);
    }
    $purpose = $_SESSION['mit_m365_device_purpose'] ?? 'create';
    unset($_SESSION['mit_m365_device_code'], $_SESSION['mit_m365_device_expires'], $_SESSION['mit_m365_device_purpose']);
    if (!empty($poll['error'])) {
        mitM365Json(['error' => $poll['error'], 'blocked' => !empty($poll['blocked'])], 400);
    }

    if ($purpose === 'calendar') {
        @set_time_limit(120);
        try {
            $calendar = mitM365ProvisionCalendar($poll['token']);
        } catch (Throwable $e) {
            logApp("Microsoft 365", "error", "Calendar app setup failed: " . $e->getMessage());
            mitM365Json(['error' => $e->getMessage()], 400);
        }
        logAudit("Settings", "Edit", "$session_name set up the calendar-only Microsoft 365 app for Outlook (" . escapeSql($calendar['client_id']) . ")");
        flashAlert("Calendar-only app created. Staff who had connected Outlook are asked to <strong>Reconnect</strong> once (Account > Outlook calendar); their calendars sync again after that.");
        mitM365Json(['ok' => true]);
    }
    if ($purpose === 'calendar_update') {
        try {
            $calendar = mitM365UpdateCalendarPermissions($poll['token']);
        } catch (Throwable $e) {
            logApp("Microsoft 365", "error", "Updating the calendar app's permissions failed: " . $e->getMessage());
            mitM365Json(['error' => $e->getMessage()], 400);
        }
        logAudit("Settings", "Edit", "$session_name updated the calendar-only Microsoft 365 app permissions (" . escapeSql($calendar['client_id']) . ")");
        flashAlert("Calendar app permissions updated.");
        mitM365Json(['ok' => true]);
    }
    if ($purpose === 'create' && mitM365App()) {
        mitM365Json(['error' => 'ITFlow already has its Microsoft 365 app - Remove it first to set up a new one.'], 409);
    }

    if ($purpose === 'update') {
        try {
            $app = mitM365UpdatePermissions($poll['token']);
        } catch (Throwable $e) {
            logApp("Microsoft 365", "error", "Updating the app's permissions failed: " . $e->getMessage());
            mitM365Json(['error' => $e->getMessage()], 400);
        }
        logAudit("Settings", "Edit", "$session_name updated the Microsoft 365 app permissions (" . escapeSql($app['client_id']) . ")");
        flashAlert("Microsoft 365 app permissions updated - ITFlow now sends and receives mail through Microsoft Graph.");
        mitM365Json(['ok' => true]);
    }

    // Creating the app can take a little while - Microsoft needs a moment to see it
    @set_time_limit(120);
    try {
        $app = mitM365Provision($poll['token'], stripslashes($session_name));  // $session_name is kept SQL-escaped
    } catch (Throwable $e) {
        logApp("Microsoft 365", "error", "App setup failed: " . $e->getMessage());
        mitM365Json(['error' => $e->getMessage()], 400);
    }

    logAudit("Settings", "Edit", "$session_name set up the Microsoft 365 app " . escapeSql($app['name']) . " (" . escapeSql($app['client_id']) . ")");
    flashAlert("Microsoft 365 app <strong>" . escapeHtml($app['name']) . "</strong> created. Now connect the mailbox ITFlow should use.");
    mitM365Json(['ok' => true]);
}

// Replace the certificate now
if (isset($_POST['mit_m365_renew'])) {

    validateCSRFToken();

    try {
        mitM365RenewCertificate();
        logAudit("Settings", "Edit", "$session_name replaced the Microsoft 365 app certificate");
        flashAlert("Certificate replaced - it now expires " . escapeHtml(date('M j, Y', strtotime(mitSetting('m365_cert_expires', 'now')))) . ".");
    } catch (Throwable $e) {
        flashAlert("Could not replace the certificate: " . escapeHtml($e->getMessage()), 'error');
    }
    redirect('settings_mail.php?tab=oauth');
}

// Stop using the app here (the registration stays in the tenant until deleted there)
if (isset($_GET['mit_m365_remove'])) {

    validateCSRFToken();

    $app = mitM365App();
    mitM365Forget();
    logAudit("Settings", "Edit", "$session_name removed the Microsoft 365 app from ITFlow");
    flashAlert("ITFlow no longer uses the Microsoft 365 app" . ($app ? " <strong>" . escapeHtml($app['name']) . "</strong>. You can also delete it in the Microsoft Entra admin center under App registrations." : '.'), 'warning');
    redirect('settings_mail.php?tab=oauth');
}

// Manual setup - the certificate to upload to a hand-made app registration
if (isset($_GET['mit_m365_certificate'])) {

    validateCSRFToken();

    $pending = mitM365PendingCertificate();
    header('Content-Type: application/x-x509-ca-cert');
    header('Content-Disposition: attachment; filename="itflow-microsoft-365.cer"');
    header('Cache-Control: no-store');
    echo $pending['cert_pem'];
    exit();
}

// Manual setup - use the hand-made app registration
if (isset($_POST['mit_m365_manual'])) {

    validateCSRFToken();

    try {
        $app = mitM365UseManualApp(trim($_POST['tenant_id'] ?? ''), trim($_POST['client_id'] ?? ''), trim($_POST['object_id'] ?? ''), stripslashes($session_name));
        logAudit("Settings", "Edit", "$session_name connected a hand-made Microsoft 365 app (" . escapeSql($app['client_id']) . ")");
        flashAlert("Microsoft 365 app connected. Now connect the mailbox ITFlow should use.");
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('settings_mail.php?tab=oauth');
}
