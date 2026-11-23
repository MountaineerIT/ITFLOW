<?php

/*
 * Mountaineer IT - database update M070
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * The AI helper's library (functions/mit_ai_helper.php) also reads ITFlow's Knowledge Base (26.12, database 3.9.5): its published,
 * unarchived articles. A source is a ticket, a document or now an article - mit_ai_sources.kb_article_id (one source per article),
 * removed with the article (ON DELETE CASCADE, so ITFlow's deletes carry on). Our table only - portable.
 * Safe to run twice. Ours run after ITFlow's updates, so kb_articles (3.9.5) is there; without it nothing is done and the helper reads no articles (mitAiKbReady).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$m070_kb = mysqli_query($mysqli, "SHOW TABLES LIKE 'kb_articles'");
$m070_ours = mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ai_sources'");
if ($m070_kb && mysqli_num_rows($m070_kb) && $m070_ours && mysqli_num_rows($m070_ours)) {
    if (!mitColumnExists($mysqli, 'mit_ai_sources', 'kb_article_id')) {
        mysqli_query($mysqli, "ALTER TABLE mit_ai_sources ADD COLUMN kb_article_id INT(11) NULL DEFAULT NULL AFTER document_id");
    }
    if (!mitIndexExists($mysqli, 'mit_ai_sources', 'mit_ai_sources_kb')) {
        mysqli_query($mysqli, "ALTER TABLE mit_ai_sources ADD UNIQUE KEY mit_ai_sources_kb (kb_article_id)");
    }
    $m070_fk = mysqli_query($mysqli, "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'mit_ai_sources'
        AND CONSTRAINT_NAME = 'mit_ai_sources_kb_fk'");
    if (!$m070_fk || !mysqli_num_rows($m070_fk)) {
        mysqli_query($mysqli, "ALTER TABLE mit_ai_sources ADD CONSTRAINT mit_ai_sources_kb_fk FOREIGN KEY (kb_article_id) REFERENCES kb_articles (kb_article_id) ON DELETE CASCADE");
    }
}
