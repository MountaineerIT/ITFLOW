<?php

/*
 * Mountaineer IT - database update M057
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Labor cost per agent (functions/mit_profit.php): what an hour of each agent costs (wage, taxes, benefits), from a date on - a new rate
 * starts a new row, so past periods keep the rate they had. Used by ITFlow's Client Profitability report. Our own table, removed with
 * the login (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_agent_cost_rates` (
    `rate_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `effective_from` date NOT NULL,
    `hourly_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
    `created_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`rate_id`),
    UNIQUE KEY `mit_agent_cost_rates_from` (`user_id`, `effective_from`),
    CONSTRAINT `mit_agent_cost_rates_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
