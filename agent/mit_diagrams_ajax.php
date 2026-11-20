<?php

/*
 * Mountaineer IT - the draw.io editor's requests (functions/mit_diagrams.php, agent/js/mit_diagrams.js):
 *   GET  ?file=<id>                                  a diagram file's content - a PNG's data: address or draw.io XML (JSON)
 *   GET  ?version=<id>                               an earlier version's picture (image/png) - for the versions window
 *   POST file_new=1 client_id folder_id name data    a new diagram in the client's Files
 *   POST file_save=1 file_id data                    a diagram file saved again (the old one kept as a version)
 *   POST restore=1 version_id                        an earlier version put back
 *   POST document_save=1 document_id index src data  a diagram inside a document, saved straight to it
 *   GET  ?start=<client_id> | ?start_document=<id>   what the "start from" window offers: the client's locations, documents, whether AI is there
 *   POST build_assets=1 client_id locations[] workstations ips racks   a map from the client's assets (draw.io XML - nothing saved)
 *   POST build_ai=1 client_id document_id ask background images[]       Draw with AI (functions/mit_ai_diagram.php; nothing saved)
 * Signed-in agents with Support edit and the client; POSTs need the CSRF token.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

$reply = function ($code, array $data) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
};

if (!function_exists('mitDiagramsCan') || !mitDiagramsCan()) {
    $reply(403, ['error' => 'Diagrams need Support edit access.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken();
    if (isset($_POST['file_new'])) {
        [$file_id, $error] = mitDiagramFileCreate(intval($_POST['client_id'] ?? 0), intval($_POST['folder_id'] ?? 0), (string) ($_POST['name'] ?? ''), (string) ($_POST['data'] ?? ''));
        $reply($file_id ? 200 : 422, $file_id ? ['file_id' => $file_id] : ['error' => $error]);
    }
    if (isset($_POST['file_save'])) {
        $error = mitDiagramFileSave(intval($_POST['file_id'] ?? 0), (string) ($_POST['data'] ?? ''));
        $reply($error === '' ? 200 : 422, $error === '' ? ['ok' => true] : ['error' => $error]);
    }
    if (isset($_POST['restore'])) {
        $error = mitDiagramRestore(intval($_POST['version_id'] ?? 0));
        $reply($error === '' ? 200 : 422, $error === '' ? ['ok' => true] : ['error' => $error]);
    }
    if (isset($_POST['build_assets'])) {
        [$xml, $summary] = mitDiagramFromAssets(intval($_POST['client_id'] ?? 0), [
            'locations' => (array) ($_POST['locations'] ?? []), 'workstations' => !empty($_POST['workstations']),
            'ips' => !empty($_POST['ips']), 'racks' => !empty($_POST['racks']),
        ]);
        $reply($xml !== null ? 200 : 422, $xml !== null ? ['xml' => $xml, 'summary' => $summary] : ['error' => $summary]);
    }
    if (isset($_POST['build_ai'])) {
        if (!function_exists('mitAiDiagramDraft') || !mitAiDiagramCan()) {
            $reply(403, ['error' => 'Drawing with AI needs Use AI and an AI model for Documentation (Admin > AI Providers).']);
        }
        try {
            $images = mitAiImagesFromUpload($_FILES['images'] ?? null);
        } catch (RuntimeException $e) {
            $reply(422, ['error' => $e->getMessage()]);
        }
        session_write_close(); // the provider can take a while - the agent's other pages don't wait on it
        $draft = mitAiDiagramDraft([
            'client_id' => intval($_POST['client_id'] ?? 0), 'document_id' => intval($_POST['document_id'] ?? 0), 'ask' => (string) ($_POST['ask'] ?? ''),
            'images' => $images, 'background' => !empty($_POST['background']),
        ]);
        $reply(!empty($draft['ok']) ? 200 : 422, !empty($draft['ok']) ? ['xml' => $draft['xml'], 'removed' => $draft['removed']] : ['error' => $draft['error']]);
    }
    if (isset($_POST['document_save'])) {
        [$error, $src] = mitDiagramDocumentSave(intval($_POST['document_id'] ?? 0), intval($_POST['index'] ?? -1), (string) ($_POST['src'] ?? ''), (string) ($_POST['data'] ?? ''));
        $reply($error === '' ? 200 : 422, $error === '' ? ['ok' => true, 'src' => $src] : ['error' => $error]);
    }
    $reply(400, ['error' => 'Unknown request.']);
}

if (isset($_GET['start']) || isset($_GET['start_document'])) {
    // A document being edited names its client through its id
    $client_id = isset($_GET['start']) ? intval($_GET['start'])
        : intval((mitDiagramDocumentRow(intval($_GET['start_document'])) ?? [])['document_client_id'] ?? 0);
    if (!mitDiagramClientOk($client_id)) {
        $reply(404, ['error' => 'That client can\'t be opened.']);
    }
    $ai = function_exists('mitAiDiagramCan') && mitAiDiagramCan();
    $reply(200, [
        'client_id' => $client_id,
        'locations' => mitDiagramAssetLocations($client_id),
        'ai' => $ai,
        'documents' => $ai ? mitAiDiagramDocuments($client_id) : [],
        'ai_name' => function_exists('mitAiName') ? mitAiName() : 'AI',
    ]);
}

if (isset($_GET['file'])) {
    $file = mitDiagramFileRow(intval($_GET['file']));
    $kind = $file ? mitDiagramFileKind($file) : '';
    if (!$file || $kind === '') {
        $reply(404, ['error' => 'That diagram no longer exists.']);
    }
    $bytes = @file_get_contents(mitDiagramFilePath($file));
    if ($bytes === false) {
        $reply(404, ['error' => 'That diagram\'s file is missing.']);
    }
    $reply(200, [
        'kind' => $kind,
        'name' => $file['file_name'],
        'data' => $kind === 'xml' ? $bytes : 'data:image/png;base64,' . base64_encode($bytes),
    ]);
}

if (isset($_GET['version'])) {
    [$version, $file] = mitDiagramVersionRow(intval($_GET['version']));
    $path = $version ? mitDiagramVersionDir($file['file_id']) . '/' . basename($version['stored_name']) : '';
    if (!$version || !is_file($path)) {
        $reply(404, ['error' => 'That version no longer exists.']);
    }
    $png = substr($path, -4) === '.png';
    header('Content-Type: ' . ($png ? 'image/png' : 'application/xml'));
    header('Content-Disposition: ' . ($png ? 'inline' : 'attachment') . '; filename="version-' . intval($version['version_id']) . ($png ? '.png' : '.drawio') . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

$reply(400, ['error' => 'Unknown request.']);
