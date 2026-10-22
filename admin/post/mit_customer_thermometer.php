<?php

/*
 * Mountaineer IT - Admin > Integrations > Customer Thermometer (admin/mit_customer_thermometer.php; functions/mit_ct.php).
 * Admins only (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_ct_connect'])) {

    validateCSRFToken();

    $key = trim((string) ($_POST['ct_api_key'] ?? ''));
    $key = $key !== '' ? $key : mitDecryptSecret((string) mitSetting('ct_api_key', ''));
    try {
        // Read the account's thermometers and lists with the key before keeping it
        $choices = ['thermometers' => mitCtChoices('thermometers', $key), 'lists' => mitCtChoices('lists', $key)];
        mitSetSetting('ct_api_key', mitEncryptSecret($key));
        mitSetSetting('ct_choices', json_encode($choices));
        logAudit('Settings', 'Edit', escapeSql("$session_name connected Customer Thermometer"));
        flashAlert('Customer Thermometer connected: ' . count($choices['thermometers']) . ' thermometer(s), ' . count($choices['lists']) . ' recipient list(s).'
            . (!$choices['thermometers'] || !$choices['lists'] ? ' Make a thermometer and a recipient list in Customer Thermometer first, then connect again.' : ''),
            $choices['thermometers'] && $choices['lists'] ? 'success' : 'warning');
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_customer_thermometer.php');
}

if (isset($_POST['mit_ct_save'])) {

    validateCSRFToken();

    $choices = json_decode((string) mitSetting('ct_choices', '{}'), true) ?: [];
    $thermometer = (string) ($_POST['ct_thermometer_id'] ?? '');
    $list = (string) ($_POST['ct_list_id'] ?? '');
    $boards = array_intersect(array_map('intval', (array) ($_POST['ct_boards'] ?? [])), array_map('intval', array_keys(mitBoards())));
    $start = (string) ($_POST['ct_start'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !strtotime($start)) {
        flashAlert('Pick a start date.', 'error');
        redirect('mit_customer_thermometer.php');
    }
    mitSetSetting('ct_thermometer_id', isset($choices['thermometers'][$thermometer]) ? $thermometer : '');
    mitSetSetting('ct_list_id', isset($choices['lists'][$list]) ? $list : '');
    mitSetSetting('ct_boards', implode(',', $boards));
    mitSetSetting('ct_start', $start);
    mitSetSetting('ct_enabled', empty($_POST['ct_enabled']) ? '0' : '1');
    logAudit('Settings', 'Edit', escapeSql("$session_name changed the Customer Thermometer settings"));
    $missing = mitCtMissing();
    flashAlert(empty($_POST['ct_enabled']) ? 'Customer Thermometer settings saved - surveys are switched off.'
        : ($missing === '' ? 'Customer Thermometer settings saved - surveys go out for tickets resolved from ' . escapeHtml($start) . '.'
            : 'Saved, but nothing is sent until you set ' . escapeHtml($missing) . '.'), $missing === '' || empty($_POST['ct_enabled']) ? 'success' : 'warning');
    redirect('mit_customer_thermometer.php');
}

if (isset($_POST['mit_ct_run'])) {

    validateCSRFToken();

    if (mitCtMissing() !== '') {
        flashAlert('Set ' . escapeHtml(mitCtMissing()) . ' first.', 'error');
        redirect('mit_customer_thermometer.php');
    }
    if (!mitCtSettings()['enabled']) {
        flashAlert('Surveys are switched off - only responses were read.', 'warning');
    }
    try {
        if (mitCtSettings()['enabled']) {
            flashAlert('Customer Thermometer: ' . escapeHtml(mitCtRun(true)) . '.');
        } else {
            mitCtFetchResponses();
        }
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_customer_thermometer.php');
}
