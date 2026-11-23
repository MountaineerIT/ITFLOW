<?php
/*
 * The AI helper reads ITFlow's Knowledge Base (26.12.1, M070 - the user's choice: published articles only): a published article goes into
 * the library through ITFlow's own kbArticleSave and is found by a search as kind 'kb' with its agent link, everyone's (client 0); a draft
 * stays out; an article taken back to a draft or archived (a bulk action, which may leave the updated time alone) leaves on the next run;
 * a password written in an article never reaches the library. Then the Write up draft for the Knowledge Base (mitAiDocumentDraft, kb):
 * general (no client named), through the test install's AI stand-in.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitAiKbReady') || !function_exists('kbArticleSave')) {
    echo "  skip - no Knowledge Base here\n";
    exit(0);
}
$stamp = date('His');
$saved = [];
foreach (['ai_helper_enabled', 'ai_helper_internal', 'ai_helper_documents', 'ai_helper_embed_provider', 'ai_helper_embed_model'] as $name) {
    $saved[$name] = mitSetting($name);
}
$articles = [];
$run = function () {
    mitSetSetting('ai_helper_since', '');
    mitSetSetting('ai_helper_pass_started', '');
    for ($i = 0; $i < 20; $i++) {
        mitAiIndexRun(50);
        if (mitSetting('ai_helper_pass_started', '') === '') {
            break;
        }
    }
};
$article = function ($title, $content, $published) use (&$articles) {
    $id = kbArticleSave(['title' => $title, 'summary' => 'ZZ scenario article', 'category_id' => 0, 'review_months' => 0, 'visibility' => 'agents',
        'all_clients' => false, 'client_tag_ids' => [], 'client_ids' => [], 'published' => $published, 'content' => $content]);
    $articles[] = intval($id);
    return intval($id);
};
$canary = 'ZzKbCanary' . $stamp . '!x9';
try {
    foreach (['ai_helper_enabled' => '1', 'ai_helper_internal' => '1', 'ai_helper_documents' => '1', 'ai_helper_embed_provider' => '0'] as $name => $value) {
        mitSetSetting($name, $value);
    }
    scenarioCheck('the Knowledge Base is on and the library can hold articles (M070)', mitAiKbReady(), true);
    $published = $article("ZZ Reset a frozen quixotic badge printer $stamp",
        "<h2>Resolution</h2><p>Hold the feed button for ten seconds until the quixotic badge printer beeps twice.</p><p>The admin password is $canary for the panel.</p>", true);
    $draft = $article("ZZ Draft about zebrastripe kiosks $stamp", '<p>Unfinished notes on zebrastripe kiosks.</p>', false);
    $run();

    echo "published articles are read, drafts are not\n";
    $source = fn($id) => mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT source_id, client_id FROM mit_ai_sources WHERE kb_article_id = " . intval($id)));
    scenarioCheck('the published article is in the library', (bool) $source($published), true);
    scenarioCheck('... as everyone\'s (no client)', intval($source($published)['client_id'] ?? -1), 0);
    scenarioCheck('the draft is not', (bool) $source($draft), false);
    $hits = array_values(array_filter(mitAiSearch('quixotic badge printer beeps', ['limit' => 8]), fn($h) => $h['kind'] === 'kb'));
    scenarioCheck('a search finds it, as a Knowledge Base article', count($hits) > 0, true);
    scenarioCheck('... linked to the article page', $hits[0]['url'] ?? '', "/agent/kb_article.php?kb_article_id=$published");
    scenarioCheck('the draft is not found', count(array_filter(mitAiSearch('zebrastripe kiosks', ['limit' => 8]), fn($h) => $h['kind'] === 'kb')), 0);

    echo "the password wall\n";
    $leaked = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_ai_chunks WHERE chunk_text LIKE '%" . mysqli_real_escape_string($mysqli, $canary) . "%'"))[0]);
    scenarioCheck('a password written in an article never reaches the library', $leaked, 0);
    scenarioCheck('... and the source counts something taken out', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT scrubbed FROM mit_ai_sources WHERE kb_article_id = $published"))[0] ?? 0) > 0, true);

    echo "taken back to a draft, then archived\n";
    mysqli_query($mysqli, "UPDATE kb_articles SET kb_article_published_at = NULL, kb_article_updated_at = kb_article_updated_at WHERE kb_article_id = $published");
    $run();
    scenarioCheck('back to a draft: out of the library', (bool) $source($published), false);
    mysqli_query($mysqli, "UPDATE kb_articles SET kb_article_published_at = NOW() WHERE kb_article_id = $published");
    $run();
    scenarioCheck('published again: back in', (bool) $source($published), true);
    mysqli_query($mysqli, "UPDATE kb_articles SET kb_article_archived_at = NOW(), kb_article_updated_at = kb_article_updated_at WHERE kb_article_id = $published");
    $run();
    scenarioCheck('archived: out', (bool) $source($published), false);
} finally {
    foreach ($articles as $id) {
        mysqli_query($mysqli, "DELETE FROM kb_articles WHERE kb_article_id = $id");
    }
    foreach ($saved as $name => $value) {
        $value === null ? mitDeleteSetting($name) : mitSetSetting($name, $value);
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
