<?php

/*
 * Mountaineer IT - database update M066
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Diagrams (functions/mit_diagrams.php - draw.io inside ITFlow, the user's request):
 *   mit_diagram_versions - the earlier saves of a diagram kept in ITFlow's Files (a client file edited in draw.io): the bytes it had
 *                          before a save, in uploads/mit_diagrams/<file_id>/ (stored_name), who saved over it and when. FK files CASCADE.
 *                          Diagrams inside documents need nothing here - ITFlow keeps every version of a document itself.
 * Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_diagram_versions` (
    `version_id` int(11) NOT NULL AUTO_INCREMENT,
    `file_id` int(11) NOT NULL,
    `stored_name` varchar(100) NOT NULL,
    `file_name` varchar(200) NOT NULL DEFAULT '',
    `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
    `saved_by` int(11) NOT NULL DEFAULT 0,
    `saved_at` datetime NOT NULL DEFAULT current_timestamp(),
    `replaced_by` int(11) NOT NULL DEFAULT 0,
    `replaced_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`version_id`),
    KEY `mit_diagram_versions_file` (`file_id`, `version_id`),
    CONSTRAINT `mit_diagram_versions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
