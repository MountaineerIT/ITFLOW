<?php

/*
 * Mountaineer IT - cron alert settings (functions/mit_cron_health.php), from Admin > Maintenance > Cron.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_cron_alerts'])) {

    validateCSRFToken();

    $to = trim((string) ($_POST['mit_cron_alert_to'] ?? ''));
    $addresses = array_filter(array_map('trim', preg_split('/[\s,;]+/', $to)));
    $bad = array_filter($addresses, function ($email) { return !filter_var($email, FILTER_VALIDATE_EMAIL); });
    if ($bad) {
        flashAlert('Not an email address: ' . escapeHtml(implode(', ', $bad)), 'error');
        redirect();
    }

    mitSetSetting('cron_alert_email', !empty($_POST['mit_cron_alert_email']) ? '1' : '0');
    mitSetSetting('cron_alert_to', implode(', ', $addresses));

    logAudit("Settings", "Edit", "$session_name edited cron alert settings");

    flashAlert("Cron alert settings saved");

    redirect();

}

if (isset($_POST['mit_test_cron_alert'])) {

    validateCSRFToken();

    if (empty($config_smtp_provider)) {
        flashAlert('Set up outgoing mail first (Settings > Mail).', 'error');
        redirect();
    }
    $recipients = mitCronAlertRecipients();
    if (!$recipients) {
        flashAlert('Nobody to send to - add an address, or give an admin an email address.', 'error');
        redirect();
    }
    $mail = [];
    foreach ($recipients as $email => $name) {
        $mail[] = ['from' => escapeSql($config_mail_from_email), 'from_name' => escapeSql($config_mail_from_name),
            'recipient' => escapeSql($email), 'recipient_name' => escapeSql($name), 'subject' => 'ITFlow cron alerts: test',
            'body' => mitMailBody('<p>This is a test from Admin &gt; Maintenance &gt; Cron. Cron alerts will come to this address.</p>')];
    }
    addToMailQueue($mail);

    flashAlert('Test queued for ' . escapeHtml(implode(', ', array_keys($recipients))) . ' - it goes out with the mail queue, within a minute while cron runs.');

    redirect();

}

if (isset($_GET['mit_renew_cron_key'])) {

    validateCSRFToken();

    mitCronHealthKey(true);

    logAudit("Settings", "Edit", "$session_name made a new cron health address");

    flashAlert("New health address made - update your monitor");

    redirect();

}
