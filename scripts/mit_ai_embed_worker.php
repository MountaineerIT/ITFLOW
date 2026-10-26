<?php
/*
 * Mountaineer IT - the AI helper's embeddings for a model on this server (Ollama), in the background: started by the AI Helper Library
 * cron job (functions/mit_ai_helper.php mitAiEmbedInBackground) so the slow part doesn't hold up ITFlow's other cron jobs, which run one
 * after another. Embeds for MIT_AI_EMBED_WORKER_SECONDS, one run at a time (the 'ai_embed' lock), then stops - the next cron run starts
 * another while passages are left. Can also be run by hand: php scripts/mit_ai_embed_worker.php
 */
if (php_sapi_name() !== 'cli') {
    die("Run this from the command line.\n");
}
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'includes/inc_set_timezone.php';
require_once 'functions.php';

if (!mitDbReady() || !mitAiHelperReady() || !mitAiHelperSettings()['enabled']) {
    exit(0);
}
$line = mitAiEmbedPending(MIT_AI_EMBED_WORKER_SECONDS);
if ($line !== '') {
    echo $line . "\n";
}
exit(str_starts_with($line, 'Embeddings failed') ? 1 : 0);
