<?php

/*
 * Mountaineer IT - Admin > Integrations > Usage connectors (admin/mit_usage_connectors.php; functions/mit_usage_connectors.php).
 * Admins only (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_usage_connector_run'])) {

    validateCSRFToken();

    $connector = isset(MIT_USAGE_CONNECTORS[$_POST['connector'] ?? '']) ? $_POST['connector'] : '';
    if ($connector === '') {
        redirect('mit_usage_connectors.php');
    }
    try {
        // Let go of the session while it calls out, so the person's other pages don't wait for it
        session_write_close();
        try {
            $report = mitUsageRunConnectors($connector);
        } finally {
            session_start();
        }
        if (!isset($report[$connector])) {
            flashAlert('Nothing to run - set ' . MIT_USAGE_CONNECTORS[$connector] . ' up first.', 'warning');
        } else {
            [$counted, $problems] = $report[$connector];
            flashAlert(MIT_USAGE_CONNECTORS[$connector] . ": $counted counted." . ($problems ? ' ' . escapeHtml(implode(' · ', $problems)) : ''), $problems ? 'warning' : 'success');
        }
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect("mit_usage_connectors.php?tab=$connector");
}

if (isset($_POST['mit_usage_ninjaone_save'])) {

    validateCSRFToken();

    mitSetSetting('usage_ninjaone_on', empty($_POST['usage_ninjaone_on']) ? '0' : '1');
    logAudit('Settings', 'Edit', escapeSql("$session_name changed the NinjaOne usage connector"));
    flashAlert('Saved');
    redirect('mit_usage_connectors.php?tab=ninjaone');
}

if (isset($_POST['mit_usage_bitwarden_region'])) {

    validateCSRFToken();

    mitSetSetting('usage_bitwarden_region', ($_POST['usage_bitwarden_region'] ?? '') === 'eu' ? 'eu' : 'us');
    flashAlert('Saved');
    redirect('mit_usage_connectors.php?tab=bitwarden');
}

if (isset($_POST['mit_usage_bitwarden_link'])) {

    validateCSRFToken();

    $why = mitUsageBitwardenLink(intval($_POST['client_id'] ?? 0), (string) ($_POST['api_client_id'] ?? ''), (string) ($_POST['api_secret'] ?? ''), (string) ($_POST['external_name'] ?? ''));
    if ($why !== '') {
        flashAlert(escapeHtml($why), 'error');
    } else {
        logAudit('Settings', 'Edit', escapeSql("$session_name linked a Bitwarden organization for usage billing"), intval($_POST['client_id'] ?? 0));
        flashAlert('Bitwarden organization linked - its key works.');
    }
    redirect('mit_usage_connectors.php?tab=bitwarden');
}

if (isset($_GET['mit_usage_unlink'])) {

    validateCSRFToken();

    $link_id = intval($_GET['mit_usage_unlink']);
    $connector = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT connector FROM mit_usage_links WHERE link_id = $link_id"))[0] ?? 'bitwarden');
    mysqli_query($mysqli, "DELETE FROM mit_usage_links WHERE link_id = $link_id");
    logAudit('Settings', 'Edit', escapeSql("$session_name unlinked a $connector usage connector"));
    flashAlert('Unlinked - the counts already recorded stay.');
    redirect('mit_usage_connectors.php?tab=' . (isset(MIT_USAGE_CONNECTORS[$connector]) ? $connector : 'bitwarden'));
}

if (isset($_POST['mit_usage_ingram_save']) || isset($_POST['mit_usage_ingram_customers'])) {

    validateCSRFToken();

    $base = rtrim(trim((string) ($_POST['usage_ingram_base'] ?? '')), '/');
    if ($base !== '' && (!preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[^\s?#]*)?$#i', $base) || !mitUsageIngramBaseOk($base))) {
        flashAlert('The API address must be a public https address.', 'error');
        redirect('mit_usage_connectors.php?tab=ingram');
    }
    $marketplace = preg_replace('/[^a-z]/', '', strtolower((string) ($_POST['usage_ingram_marketplace'] ?? 'us'))) ?: 'us';
    $password = (string) ($_POST['usage_ingram_password'] ?? '');
    $key = trim((string) ($_POST['usage_ingram_key'] ?? ''));
    // The saved password and key are only ever sent where they were saved for - a new address needs them typed again
    $moved = $base !== (string) mitSetting('usage_ingram_base', '') || $marketplace !== (string) mitSetting('usage_ingram_marketplace', 'us');
    if ($moved && (string) mitSetting('usage_ingram_password', '') !== '' && ($password === '' || $key === '')) {
        flashAlert('The API address or marketplace changed - type the password and subscription key again.', 'error');
        redirect('mit_usage_connectors.php?tab=ingram');
    }
    $old = [mitSetting('usage_ingram_base', ''), mitSetting('usage_ingram_marketplace', 'us'), mitSetting('usage_ingram_login', '')];
    mitSetSetting('usage_ingram_base', $base);
    mitSetSetting('usage_ingram_marketplace', $marketplace);
    mitSetSetting('usage_ingram_login', mb_substr(trim((string) ($_POST['usage_ingram_login'] ?? '')), 0, 200));
    if ($password !== '') {
        mitSetSetting('usage_ingram_password', mitEncryptSecret($password));
    }
    if ($key !== '') {
        mitSetSetting('usage_ingram_key', mitEncryptSecret($key));
    }
    // Another login or address: the kept token is no good
    if ($password !== '' || $key !== '' || $old !== [mitSetting('usage_ingram_base', ''), mitSetting('usage_ingram_marketplace', 'us'), mitSetting('usage_ingram_login', '')]) {
        mitSetSetting('usage_ingram_token', '');
        mitSetSetting('usage_ingram_token_expires', 0);
    }
    logAudit('Settings', 'Edit', escapeSql("$session_name changed the Ingram Micro usage connector"));
    if (isset($_POST['mit_usage_ingram_customers'])) {
        try {
            $customers = mitUsageIngramCustomers(true);
            flashAlert(count($customers) . ' Ingram customer' . (count($customers) === 1 ? '' : 's') . ' read - link them to clients.');
        } catch (Throwable $e) {
            flashAlert(escapeHtml($e->getMessage()), 'error');
        }
    } else {
        flashAlert('Saved');
    }
    redirect('mit_usage_connectors.php?tab=ingram');
}

if (isset($_POST['mit_usage_ingram_match'])) {

    validateCSRFToken();

    flashAlert(mitUsageIngramAutoMatch() . ' matched by name.');
    redirect('mit_usage_connectors.php?tab=ingram');
}

if (isset($_POST['mit_usage_ingram_map'])) {

    validateCSRFToken();

    $customers = mitUsageIngramCustomers();
    $current = array_column(mitUsageLinks('ingram'), 'external_id', 'client_id');
    $changed = 0;
    foreach ((array) ($_POST['map'] ?? []) as $client_id => $customer_id) {
        $client_id = intval($client_id);
        $customer_id = (string) $customer_id;
        if ($customer_id === (string) ($current[$client_id] ?? '')) {
            continue;
        }
        if ($customer_id !== '' && !isset($customers[$customer_id])) {
            continue;
        }
        mitUsageIngramSetLink($client_id, $customer_id, $customers[$customer_id] ?? '');
        $changed++;
    }
    if ($changed) {
        logAudit('Settings', 'Edit', escapeSql("$session_name changed $changed Ingram Micro customer link" . ($changed === 1 ? '' : 's')));
    }
    flashAlert("$changed link" . ($changed === 1 ? '' : 's') . ' saved.');
    redirect('mit_usage_connectors.php?tab=ingram');
}
