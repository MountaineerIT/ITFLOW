<?php

/*
 * Mountaineer IT - database update M044
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * AI helper (functions/mit_ai_helper.php): the library it searches - resolved / closed tickets and documents, cut into passages
 * that were cleaned of passwords before they were stored. One row per source (a ticket or a document - each goes with it), its
 * passages under it. Nothing here is ever read from the password vault - see the "AI helper" wall in CLAUDE.md.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ai_sources` (
    `source_id` int(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` int(11) DEFAULT NULL,
    `document_id` int(11) DEFAULT NULL,
    `client_id` int(11) NOT NULL DEFAULT 0,
    `title` varchar(255) NOT NULL DEFAULT '',
    `signature` varchar(64) NOT NULL DEFAULT '',
    `scrubbed` int(11) NOT NULL DEFAULT 0,
    `indexed_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`source_id`),
    UNIQUE KEY `mit_ai_sources_ticket` (`ticket_id`),
    UNIQUE KEY `mit_ai_sources_document` (`document_id`),
    KEY `mit_ai_sources_client` (`client_id`),
    CONSTRAINT `mit_ai_sources_ticket_fk` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_ai_sources_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ai_chunks` (
    `chunk_id` int(11) NOT NULL AUTO_INCREMENT,
    `source_id` int(11) NOT NULL,
    `client_id` int(11) NOT NULL DEFAULT 0,
    `chunk_no` int(11) NOT NULL DEFAULT 0,
    `chunk_text` text NOT NULL,
    `embedding` mediumblob DEFAULT NULL,
    `embed_model` varchar(200) DEFAULT NULL,
    PRIMARY KEY (`chunk_id`),
    KEY `mit_ai_chunks_source` (`source_id`, `chunk_no`),
    KEY `mit_ai_chunks_client` (`client_id`),
    FULLTEXT KEY `mit_ai_chunks_text` (`chunk_text`),
    CONSTRAINT `mit_ai_chunks_source_fk` FOREIGN KEY (`source_id`) REFERENCES `mit_ai_sources` (`source_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
