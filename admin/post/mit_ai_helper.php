<?php

/*
 * Mountaineer IT - AI helper settings (functions/mit_ai_helper.php), from Admin > Integrations > AI helper.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_ai_helper_save']) || isset($_POST['mit_ai_helper_embed_test'])) {

    validateCSRFToken();

    $before = mitAiHelperSettings();
    $provider = intval($_POST['ai_helper_embed_provider'] ?? 0);
    if ($provider && !aiProviderRow($provider)) {
        $provider = 0;
    }
    $model = mb_substr(trim((string) ($_POST['ai_helper_embed_model'] ?? '')), 0, 200);
    mitSetSetting('ai_helper_enabled', empty($_POST['ai_helper_enabled']) ? '0' : '1');
    mitSetSetting('ai_helper_internal', empty($_POST['ai_helper_internal']) ? '0' : '1');
    mitSetSetting('ai_helper_documents', empty($_POST['ai_helper_documents']) ? '0' : '1');
    mitSetSetting('ai_helper_embed_provider', $provider);
    mitSetSetting('ai_helper_embed_model', $model);
    $after = mitAiHelperSettings();

    $changes = [];
    foreach (['enabled' => 'AI helper', 'internal' => 'internal notes', 'documents' => 'documents'] as $key => $label) {
        if ($before[$key] !== $after[$key]) {
            $changes[] = $label . ($after[$key] ? ' on' : ' off');
        }
    }
    if ($before['embed_provider'] !== $after['embed_provider'] || $before['embed_model'] !== $after['embed_model']) {
        $changes[] = 'embeddings ' . ($after['embed_provider'] && $after['embed_model'] !== '' ? 'model ' . $after['embed_model'] : 'off');
    }
    if ($changes) {
        logAudit("Settings", "Edit", "$session_name changed the AI helper: " . escapeSql(implode(', ', $changes)));
    }

    if (isset($_POST['mit_ai_helper_embed_test'])) {
        [$ok, $vectors] = mitAiEmbed(['Outlook keeps asking the user to sign in again']);
        if ($ok) {
            flashAlert('Embeddings work - ' . count($vectors[0] ?? []) . ' numbers per passage.');
        } else {
            flashAlert('Embeddings did not work: ' . escapeHtml((string) $vectors), 'error');
        }
        redirect();
    }

    flashAlert('AI helper saved' . ($changes ? ' - ' . escapeHtml(implode(', ', $changes)) : ''));
    redirect();

}

if (isset($_POST['mit_ai_helper_rebuild'])) {

    validateCSRFToken();

    mitAiIndexReset();
    logAudit("Settings", "Edit", "$session_name rebuilt the AI helper library");
    flashAlert('The library is being built again - it catches up over the next runs of the AI Helper Library cron job.');
    redirect();

}
