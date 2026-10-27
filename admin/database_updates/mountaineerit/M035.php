<?php

/*
 * Mountaineer IT - database update M035
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * The Dispatch permission (functions/mit_dispatch.php): a row in ITFlow's modules list, so Admin > Roles offers it.
 * View sees the board, Edit books, moves, assigns and merges (with Support edit, as ITFlow's own ticket forms need).
 * Dispatch used to come with Support edit, so every role that has Support edit is given Dispatch Edit when the row is
 * made - nobody loses the board on the day; take it off (or set View) in Admin > Roles afterwards.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$mit_m035_module = mysqli_query($mysqli, "SELECT module_id FROM modules WHERE module_name = 'module_dispatch'");
if (mysqli_num_rows($mit_m035_module) === 0) {
    mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_dispatch', module_description = 'The Dispatch board. View sees it, Edit books, moves and assigns tickets there (also needs Support edit)'");
    $mit_m035_id = intval(mysqli_insert_id($mysqli));
    mysqli_query($mysqli, "INSERT INTO user_role_permissions (user_role_id, module_id, user_role_permission_level)
        SELECT p.user_role_id, $mit_m035_id, 2 FROM user_role_permissions p JOIN modules m ON m.module_id = p.module_id
        WHERE m.module_name = 'module_support' AND p.user_role_permission_level >= 2
        AND NOT EXISTS (SELECT 1 FROM user_role_permissions d WHERE d.user_role_id = p.user_role_id AND d.module_id = $mit_m035_id)");
}
