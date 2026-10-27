<?php

/*
 * Mountaineer IT - the old Billing > Unbilled Parts page. Parts to bill are on ITFlow's Unbilled Time page ("To Bill") since
 * 26.10.182 (functions/mit_to_bill.php), beside each client's tickets, expenses and trips; this keeps old links and bookmarks
 * working. ?client= becomes ?client_id=.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

$client = intval($_GET['client'] ?? 0);
header('Location: /agent/unbilled_time.php' . ($client ? "?client_id=$client" : ''), true, 302);
exit;
