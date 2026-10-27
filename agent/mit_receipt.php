<?php

/*
 * Mountaineer IT - a reimbursement's receipt (functions/mit_reimbursements.php), shown inline. For the person
 * it belongs to, whoever may see their timesheet, and Financial access to the client (mitReimbMaySeeReceipt).
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

$row = mitReimbOn() ? mitReimbRow($_GET['id'] ?? 0) : null;
$allowed = $row && !empty($row['receipt']) && mitReimbMaySeeReceipt($row);
$path = $allowed ? dirname(__DIR__) . '/' . MIT_REIMB_RECEIPT_DIR . '/' . basename($row['receipt']) : '';
if (!$allowed || !is_file($path)) {
    http_response_code(404);
    exit('That receipt is not there, or not yours to see.');
}
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'heic' => 'image/heic'];
$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) ($row['receipt_name'] ?: 'receipt.' . $extension));
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
header('Content-Disposition: ' . (isset($types[$extension]) && $extension !== 'heic' ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
