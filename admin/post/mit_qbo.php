<?php

/*
 * Mountaineer IT - Admin > Integrations > QuickBooks Online (admin/mit_qbo.php; functions/mit_qbo.php). Admins only (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_qbo_save_settings'])) {

    validateCSRFToken();

    $client_id = mb_substr(trim((string) ($_POST['qbo_client_id'] ?? '')), 0, 200);
    $secret = trim((string) ($_POST['qbo_client_secret'] ?? ''));
    if ($client_id !== mitQboSettings()['client_id'] && mitQboConnected()) {
        flashAlert('Disconnect before changing the Intuit app.', 'error');
        redirect('mit_qbo.php');
    }
    mitSetSetting('qbo_client_id', $client_id);
    if ($secret !== '') {
        mitSetSetting('qbo_client_secret', mitEncryptSecret($secret));
    }
    if (!mitQboConnected() && isset($_POST['qbo_env'])) {
        mitSetSetting('qbo_env', $_POST['qbo_env'] === 'sandbox' ? 'sandbox' : 'production');
    }
    mitSetSetting('qbo_tax_style', ($_POST['qbo_tax_style'] ?? '') === 'line' ? 'line' : 'us');
    mitSetSetting('qbo_no_tax_code', preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($_POST['qbo_no_tax_code'] ?? '')));
    mitSetSetting('qbo_fallback_item', preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($_POST['qbo_fallback_item'] ?? '')));
    $account_id = intval($_POST['qbo_payment_account'] ?? 0);
    if ($account_id && !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM accounts WHERE account_id = $account_id"))) {
        $account_id = 0;
    }
    mitSetSetting('qbo_payment_account', $account_id);
    mitSetSetting('qbo_email_default', empty($_POST['qbo_email_default']) ? '0' : '1');
    mitSetSetting('qbo_create_customers', empty($_POST['qbo_create_customers']) ? '0' : '1');
    logAudit('Settings', 'Edit', escapeSql(stripslashes($session_name) . " changed the QuickBooks Online settings"));
    flashAlert('QuickBooks Online settings saved');
    redirect('mit_qbo.php');
}

if (isset($_POST['mit_qbo_connect'])) {

    validateCSRFToken();

    if (mitQboDevProblem(true) !== '') {
        flashAlert(mitQboDevProblem(true), 'error');
        redirect('mit_qbo.php');
    }
    $state = bin2hex(random_bytes(24));
    $_SESSION['mit_qbo_state'] = $state;
    $_SESSION['mit_qbo_state_expires'] = time() + 600;
    header('Location: ' . mitQboAuthorizeUrl($state));
    exit;
}

if (isset($_POST['mit_qbo_disconnect'])) {

    validateCSRFToken();

    $company = mitQboSettings()['company_name'];
    mitQboDisconnect();
    logAudit('Settings', 'Edit', escapeSql(stripslashes($session_name) . " disconnected QuickBooks Online ($company)"));
    flashAlert('QuickBooks Online disconnected. Mappings, batches and approvals are kept; ITFlow emails invoices again.');
    redirect('mit_qbo.php');
}

if (isset($_POST['mit_qbo_test'])) {

    validateCSRFToken();

    try {
        $info = mitQboApi('GET', '/companyinfo/' . rawurlencode(mitQboSettings()['realm_id']));
        $name = (string) ($info['CompanyInfo']['CompanyName'] ?? '');
        mitSetSetting('qbo_company_name', $name);
        flashAlert('QuickBooks Online answers: ' . escapeHtml($name));
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_qbo.php');
}

if (isset($_POST['mit_qbo_sync_now'])) {

    validateCSRFToken();

    try {
        // Let go of the session while it calls out, so the person's other pages don't wait for it
        session_write_close();
        try {
            $report = mitQboPaymentSync();
        } finally {
            session_start();
        }
        flashAlert("Checked {$report['checked']} invoice" . ($report['checked'] === 1 ? '' : 's') . " in QuickBooks - {$report['changed']} changed in ITFlow."
            . ($report['errors'] ? ' ' . escapeHtml(implode(' ', array_slice($report['errors'], 0, 3))) : ''), $report['errors'] ? 'warning' : 'success');
    } catch (Throwable $e) {
        mitSetSetting('qbo_last_error', mb_substr($e->getMessage(), 0, 500));
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_qbo.php');
}

if (isset($_POST['mit_qbo_auto_match'])) {

    validateCSRFToken();

    $kind = (string) ($_POST['kind'] ?? '');
    if (!isset(MIT_QBO_MAP_TABLES[$kind])) {
        redirect('mit_qbo.php');
    }
    try {
        $matched = mitQboAutoMatch($kind);
        flashAlert("$matched matched by name.");
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect("mit_qbo.php?tab=$kind");
}

if (isset($_POST['mit_qbo_save_map']) || isset($_POST['mit_qbo_create_customer'])) {

    validateCSRFToken();

    $kind = (string) ($_POST['kind'] ?? '');
    if (!isset(MIT_QBO_MAP_TABLES[$kind])) {
        redirect('mit_qbo.php');
    }
    try {
        $list = mitQboList($kind);
        $map = mitQboMap($kind);
        $changed = 0;
        foreach ((array) ($_POST['map'] ?? []) as $id => $qbo_id) {
            $id = intval($id);
            $qbo_id = (string) $qbo_id;
            if ($qbo_id === ($map[$id]['qbo_id'] ?? '')) {
                continue;
            }
            // Only ids QuickBooks offered (or clearing one)
            if ($qbo_id !== '' && !isset($list[$qbo_id])) {
                continue;
            }
            mitQboSetMap($kind, $id, $qbo_id, $list[$qbo_id]['name'] ?? '');
            $changed++;
        }
        if (isset($_POST['mit_qbo_create_customer']) && $kind === 'customers') {
            $client_id = intval($_POST['mit_qbo_create_customer']);
            mitQboCreateCustomer($client_id);
            flashAlert('Customer made (or linked by name) in QuickBooks.');
        } else {
            flashAlert("$changed mapping" . ($changed === 1 ? '' : 's') . ' saved.');
        }
        if ($changed) {
            logAudit('Settings', 'Edit', escapeSql(stripslashes($session_name) . " changed $changed QuickBooks Online $kind mapping" . ($changed === 1 ? '' : 's')));
        }
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect("mit_qbo.php?tab=$kind");
}
