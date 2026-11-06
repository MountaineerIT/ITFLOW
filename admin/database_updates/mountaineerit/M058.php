<?php

/*
 * Mountaineer IT - database update M058
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Usage counts move into ITFlow's own "Quantity from" on the line (26.11.3.7, the user's choice - functions/mit_usage.php): every line set
 * up on the old Usage billing card (mit_usage_lines) becomes a seat line of ITFlow's recurring_invoice_items - a line counted by a usage name
 * (typed / reported through the API / a connector) gets item_seat_source 'usage' with {"metric": name}, a contact-tag line ITFlow's own
 * 'contacts' tagged with that tag; the minimum comes along. Lines ITFlow already counts are left as they are. The moved rows leave
 * mit_usage_lines, so the old engine (draft hold, Usage Review) has nothing left to do for them. Data only; safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$mit_has_lines = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_usage_lines'")) === 1;
$mit_has_seats = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM recurring_invoice_items LIKE 'item_seat_source'")) === 1;

if ($mit_has_lines && $mit_has_seats) {
    // Counted by a usage name
    mysqli_query($mysqli, "UPDATE recurring_invoice_items i JOIN mit_usage_lines l ON l.recurring_item_id = i.item_id
        SET i.item_seat_source = 'usage', i.item_seat_filter = JSON_OBJECT('metric', l.line_metric), i.item_seat_minimum = COALESCE(l.line_min_qty, 0)
        WHERE l.line_source IN ('manual', 'api') AND l.line_metric <> '' AND l.line_metric NOT LIKE 'tag:%'
            AND (i.item_seat_source IS NULL OR i.item_seat_source = '')");

    // Contacts with a tag -> ITFlow's Users tagged ...
    mysqli_query($mysqli, "UPDATE recurring_invoice_items i JOIN mit_usage_lines l ON l.recurring_item_id = i.item_id
        SET i.item_seat_source = 'contacts', i.item_seat_filter = JSON_OBJECT('tag', l.line_tag_id), i.item_seat_minimum = COALESCE(l.line_min_qty, 0)
        WHERE l.line_source = 'tag' AND l.line_tag_id > 0
            AND (i.item_seat_source IS NULL OR i.item_seat_source = '')");

    // Moved (or already counted by ITFlow) - off the old card
    mysqli_query($mysqli, "DELETE l FROM mit_usage_lines l JOIN recurring_invoice_items i ON i.item_id = l.recurring_item_id
        WHERE i.item_seat_source IS NOT NULL AND i.item_seat_source <> ''");
}
