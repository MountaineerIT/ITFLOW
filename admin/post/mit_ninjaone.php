<?php

/*
 * Mountaineer IT - Admin > Integrations > NinjaOne (admin/mit_ninjaone.php; functions/mit_ninjaone.php). Admins only (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_ninja_save_settings']) || isset($_POST['mit_ninja_test'])) {

    validateCSRFToken();

    $instance = (string) ($_POST['ninja_instance'] ?? 'app');
    $web = trim((string) ($_POST['ninja_web_url'] ?? ''));
    $web_clean = mitNinjaCleanWebUrl($web);
    if ($web !== '' && $web_clean === '') {
        flashAlert('Your NinjaOne address should be an https address, like https://yourcompany.rmmservice.com.', 'error');
        redirect('mit_ninjaone.php?tab=settings');
    }
    $old_instance = mitNinjaSettings()['instance'];
    $old_client = mitNinjaSettings()['client_id'];
    mitSetSetting('ninja_instance', isset(MIT_NINJA_INSTANCES[$instance]) ? $instance : 'app');
    mitSetSetting('ninja_client_id', mb_substr(trim((string) ($_POST['ninja_client_id'] ?? '')), 0, 200));
    $secret = trim((string) ($_POST['ninja_client_secret'] ?? ''));
    if ($secret !== '') {
        mitSetSetting('ninja_client_secret', mitEncryptSecret($secret));
    }
    // New keys or region: the old token is no good
    if ($secret !== '' || $instance !== $old_instance || mitNinjaSettings()['client_id'] !== $old_client) {
        mitSetSetting('ninja_token', '');
        mitSetSetting('ninja_token_expires', 0);
    }
    mitSetSetting('ninja_web_url', $web_clean);
    mitSetSetting('ninja_sync_on', empty($_POST['ninja_sync_on']) ? '0' : '1');
    mitSetSetting('ninja_brake', max(1, min(5000, intval($_POST['ninja_brake'] ?? 100))));
    mitSetSetting('ninja_misplaced', ($_POST['ninja_misplaced'] ?? '') === 'create' ? 'create' : 'report');
    logAudit('Settings', 'Edit', escapeSql(stripslashes($session_name) . " changed the NinjaOne settings"));

    if (isset($_POST['mit_ninja_test'])) {
        [$ok, $message] = mitNinjaTest();
        flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    } else {
        flashAlert('NinjaOne settings saved');
    }
    redirect('mit_ninjaone.php?tab=settings');
}

if (isset($_POST['mit_ninja_refresh_orgs'])) {

    validateCSRFToken();

    try {
        $orgs = mitNinjaRefreshOrgs();
        flashAlert(count($orgs) . ' organization' . (count($orgs) === 1 ? '' : 's') . ' read from NinjaOne - device counts fill in on the next sync.');
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_ninjaone.php?tab=mapping');
}

if (isset($_POST['mit_ninja_save_mapping'])) {

    validateCSRFToken();

    $orgs = mitNinjaOrgs();
    $changed = 0;
    foreach ((array) ($_POST['mapping'] ?? []) as $org_id => $choice) {
        $org = $orgs[intval($org_id)] ?? null;
        if (!$org) {
            continue;
        }
        $current = $org['status'] === 'ignored' ? 'ignore' : ($org['status'] === 'mapped' ? (string) intval($org['client_id']) : '');
        if ((string) $choice !== $current) {
            mitNinjaSetMapping($org_id, (string) $choice);
            $changed++;
        }
    }
    if ($changed) {
        logAudit('Settings', 'Edit', escapeSql(stripslashes($session_name) . " changed the NinjaOne mapping of $changed organization(s)"));
    }
    flashAlert($changed ? "Mapping saved - $changed organization" . ($changed === 1 ? '' : 's') . ' changed' : 'Nothing changed');
    redirect('mit_ninjaone.php?tab=mapping');
}

if (isset($_POST['mit_ninja_import'])) {

    validateCSRFToken();

    $file = $_FILES['workflow'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
        flashAlert('Choose the workflow\'s JSON file (up to 5 MB).', 'error');
        redirect('mit_ninjaone.php?tab=mapping');
    }
    [$mapped, $ignored, $skipped, $error] = mitNinjaImportN8n(file_get_contents($file['tmp_name']));
    if ($error !== '') {
        flashAlert(escapeHtml($error), 'error');
    } else {
        logAudit('Settings', 'Edit', escapeSql(stripslashes($session_name) . " imported the NinjaOne mapping from the n8n workflow ($mapped mapped, $ignored not synced)"));
        flashAlert("Imported: $mapped organization" . ($mapped === 1 ? '' : 's') . " mapped, $ignored not synced"
            . ($skipped ? '. Skipped ' . count($skipped) . ' that point at clients not here (#' . implode(', #', array_unique($skipped)) . ')' : ''), $skipped ? 'warning' : 'success');
    }
    redirect('mit_ninjaone.php?tab=mapping');
}

if (isset($_POST['mit_ninja_run']) || isset($_POST['mit_ninja_preview'])) {

    validateCSRFToken();

    // A long sync: let it finish even if the browser gives up, and let go of the session while it runs - otherwise every
    // other page this person opens waits for it (PHP holds the session file locked for the whole request)
    ignore_user_abort(true);
    set_time_limit(900);
    session_write_close();
    try {
        $report = mitNinjaSync('manual', isset($_POST['mit_ninja_preview']));
    } finally {
        session_start();
    }
    $totals = $report['totals'] ?? [];
    if (!empty($report['braked'])) {
        flashAlert('Safety brake: nothing was written - the report lists the ' . intval($totals['would_create'] ?? 0) . ' assets it would have made and why each matched nothing.', 'error');
    } elseif (in_array($report['outcome'], ['failed', 'busy'], true)) {
        flashAlert(escapeHtml(implode(' ', $report['errors'] ?? ['The sync did not run.'])), 'error');
    } elseif (!empty($report['preview'])) {
        flashAlert('Preview done - nothing was written: ' . intval($totals['would_create'] ?? 0) . ' would be new, ' . intval($totals['would_update'] ?? 0) . ' updated, ' . intval($totals['unchanged'] ?? 0) . ' unchanged.');
    } else {
        flashAlert('Sync done: ' . intval($totals['created'] ?? 0) . ' new, ' . intval($totals['updated'] ?? 0) . ' updated, ' . intval($totals['unchanged'] ?? 0) . ' unchanged'
            . ($report['errors'] || $report['warnings'] ? ' - see the report' : ''), $report['errors'] ? 'warning' : 'success');
    }
    redirect('mit_ninjaone.php' . (!empty($report['run_id']) ? '?run=' . intval($report['run_id']) : '?tab=runs'));
}
