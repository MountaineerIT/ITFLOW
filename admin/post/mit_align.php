<?php

/*
 * Mountaineer IT - Admin > Integrations > MSP Align (admin/mit_align.php; functions/mit_align.php). Admins only (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_align_save'])) {

    validateCSRFToken();

    $url = mitAlignCleanUrl($_POST['align_url'] ?? '');
    if ($url === '') {
        flashAlert('Type MSP Align\'s address, starting with https://', 'error');
        redirect('mit_align.php');
    }
    $key = trim((string) ($_POST['align_key'] ?? ''));
    $key = $key !== '' ? $key : mitDecryptSecret((string) mitSetting('align_key', ''));
    if ($key === '') {
        flashAlert('Paste an MSP Align API key.', 'error');
        redirect('mit_align.php');
    }
    try {
        // GET /api/v1 describes the key itself - test it before keeping it
        $me = mitAlignCall('', [], $key, $url)['data'] ?? [];
        mitSetSetting('align_url', $url);
        mitSetSetting('align_key', mitEncryptSecret($key));
        mitSetSetting('align_enabled', empty($_POST['align_enabled']) ? '0' : '1');
        mitSetSetting('align_replace_attention', empty($_POST['align_replace_attention']) ? '0' : '1');
        mitSetSetting('align_scopes', json_encode(array_values(array_map('strval', (array) ($me['scopes'] ?? [])))));
        mitSetSetting('align_key_name', mb_substr((string) ($me['name'] ?? 'API key'), 0, 120));
        logAudit('Settings', 'Edit', escapeSql("$session_name connected MSP Align ($url)"));
        flashAlert('MSP Align connected - the first copy runs within the hour, or press Sync now.');
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_align.php');
}

if (isset($_POST['mit_align_sync'])) {

    validateCSRFToken();

    try {
        // Let go of the session while it calls out, so the person's other pages don't wait for it
        session_write_close();
        try {
            $result = mitAlignSync();
        } finally {
            session_start();
        }
        flashAlert('MSP Align: ' . escapeHtml($result) . '.');
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_align.php');
}
