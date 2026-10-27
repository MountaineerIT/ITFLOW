<?php

/*
 * Mountaineer IT - NinjaOne status for the buttons beside assets (functions/mit_ninjaone.php, agent/js/mit_ninjaone.js), JSON:
 *   GET ?status=1,2,3   asset ids -> {asset_id: {device_id, url, name, offline, last_contact, checked}} for the ones linked to a
 *                       NinjaOne device that the agent may see (Support view, the asset's client); a status older than
 *                       5 minutes is asked of NinjaOne again.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_GET['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown request.']);
    exit;
}

$asset_ids = array_slice(array_map('intval', explode(',', (string) $_GET['status'])), 0, 50);
// The NinjaOne calls below can take a while: let the agent's other requests through, and don't wait long for each
session_write_close();
$GLOBALS['mit_ninja_timeout'] = 5;
echo json_encode((object) mitNinjaAssetDevices($asset_ids, true));
