<?php

/*
 * Mountaineer IT - Admin > Settings > Procurement: purchase order numbering, approval and ship-to
 * (the card is admin/includes/mit_procurement_card.php, its handler admin/post/mit_procurement_settings.php).
 */

require_once "includes/inc_all_admin.php";

if (!function_exists('mitProcReady') || !mitProcReady()) {
    echo '<div class="alert alert-warning">Procurement needs the database update - Admin > Update.</div>';
} else {
    if (!mitModuleOn('procurement')) {
        echo '<div class="alert alert-info">Procurement is switched off (Settings > Modules) - these settings are kept for when it is on.</div>';
    }
    require_once "includes/mit_procurement_card.php";
}

require_once "../includes/footer.php";
