<?php
/*
 * Mountaineer IT - diagrams with draw.io (Nov 19, 26.11.8.10 - the user's request: network maps and site maps kept up to date inside ITFlow's
 * documentation; the user chose: both Files and documents, draw.io served from this server, earlier versions kept, the portal shows them as it shows
 * any file or document).
 *
 * The editor is draw.io's own web app (Apache 2.0, https://github.com/jgraph/drawio), a pinned release kept in /mit_drawio/ (MIT_DRAWIO_VERSION;
 * scripts/mit_drawio_update.sh fetches another) and opened in its embed mode (proto=json, stealth=1 - nothing it does reaches another site) in a
 * full-screen frame by agent/js/mit_diagrams.js. Every diagram is saved as an editable PNG: the picture with the diagram's own data inside it
 * (draw.io's xmlpng), so it shows as an ordinary image everywhere - ITFlow's file viewer, documents, the client portal, PDFs, MSP Align's portal
 * (which reads ITFlow's documents through ITFlow's API) - and opens in the editor again.
 *
 *  - Files: "New diagram" in the client Files page's Upload menu makes an ITFlow file "<name>.drawio.png" (mitDiagramFileCreate); a diagram file's
 *    ⋮ menu has "Edit diagram" and "Earlier versions" (also a .drawio file uploaded the usual way - saved back as draw.io XML). Each save keeps the
 *    bytes it replaces (mitDiagramFileSave -> mit_diagram_versions, M066; files in uploads/mit_diagrams/<file_id>/, Require all denied - served by
 *    agent/mit_diagrams_ajax.php to agents who can open the client). Restoring keeps the current one as a version too.
 *  - Documents: a Diagram button in the document editor (TinyMCE, added by the script to editors of document forms) inserts or edits a diagram -
 *    an <img class="mit-drawio"> whose data ITFlow's own save writes to uploads/documents/<id>/ (saveBase64Images) - and on the document page each
 *    diagram has an "Edit diagram" button that saves straight to the document (mitDiagramDocumentSave), keeping the version before it as ITFlow's
 *    Edit does. ITFlow keeps every document version, so nothing of ours is needed for their history.
 *
 * Permission: Support edit and the client (as ITFlow's own file and document edits). Hooks: one require in functions.php, the nav line in our
 * includes/mit_timers_nav.php (mitDiagramsNav - the script and its settings), two lines in agent/files.php (mitDiagramNewMenuItem in the Upload
 * menu, mitDiagramFileMenu in filesItemMenu), mit_diagrams in functions/backup.php's guard list.
 */

const MIT_DRAWIO_VERSION = '32.7.4';
const MIT_DIAGRAM_MAX_BYTES = 20 * 1024 * 1024;
const MIT_DIAGRAM_VERSIONS_KEPT = 30;
const MIT_DIAGRAM_DIR = 'mit_diagrams';
const MIT_DIAGRAM_SUFFIX = '.drawio.png';

/** Whether M066 has run. */
function mitDiagramsReady() {
    static $ready = null;
    if ($ready === null) {
        global $mysqli;
        $ready = (bool) mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_diagram_versions'"));
    }
    return $ready;
}

/** Whether the editor is here (the vendored app). */
function mitDiagramsEditorHere() {
    return is_file(dirname(__DIR__) . '/mit_drawio/index.html');
}

/** Whether the signed-in agent may make and change diagrams (Support edit). */
function mitDiagramsCan() {
    return function_exists('lookupUserPermission') && intval(lookupUserPermission('module_support')) >= 2 && mitDiagramsEditorHere();
}

/** Whether the signed-in agent can open this client (their client limits). */
function mitDiagramClientOk($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    return $client_id > 0 && (bool) mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM clients WHERE client_id = $client_id " . clientScopeSql('client_id')));
}

/** The editor's address - draw.io's embed mode, English, nothing outside this server. */
function mitDiagramEditorUrl() {
    return '/mit_drawio/index.html?' . http_build_query([
        'embed' => 1, 'proto' => 'json', 'spin' => 1, 'lang' => 'en', 'stealth' => 1, 'offline' => 1, 'pwa' => 0,
        'libraries' => 1, 'libs' => 'general;basic;arrows2;network2;network;rack;office;floorplan', 'configure' => 0, 'v' => MIT_DRAWIO_VERSION,
    ]);
}

/** Whether a file row is a diagram the editor opens: an editable PNG we made ("x.drawio.png") or draw.io's own .drawio file. */
function mitDiagramFileKind(array $file) {
    $name = strtolower((string) ($file['file_name'] ?? $file['name'] ?? ''));
    $ext = strtolower((string) ($file['file_ext'] ?? pathinfo($name, PATHINFO_EXTENSION)));
    if ($ext === 'drawio') {
        return 'xml';
    }
    if ($ext === 'png' && substr($name, -strlen(MIT_DIAGRAM_SUFFIX)) === MIT_DIAGRAM_SUFFIX) {
        return 'png';
    }
    return '';
}

/** A client file row (any agent with the client), or null. */
function mitDiagramFileRow($file_id) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM files WHERE file_id = " . intval($file_id)));
    if (!$row || !mitDiagramClientOk($row['file_client_id'])) {
        return null;
    }
    return $row;
}

/** Where a client file is on disk. */
function mitDiagramFilePath(array $file) {
    return dirname(__DIR__) . '/uploads/clients/' . intval($file['file_client_id']) . '/' . basename((string) $file['file_reference_name']);
}

/** The folder earlier versions of a file are kept in (made, with its deny file, when needed). */
function mitDiagramVersionDir($file_id, $make = false) {
    $base = dirname(__DIR__) . '/uploads/' . MIT_DIAGRAM_DIR;
    $dir = $base . '/' . intval($file_id);
    if ($make) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_file("$base/.htaccess")) {
            @file_put_contents("$base/.htaccess", "Require all denied\n");
        }
    }
    return $dir;
}

/*
 * Check what the editor sent: a data: address (PNG) or draw.io XML. Returns [bytes, error]. A PNG must be a real PNG carrying draw.io's diagram
 * (its "mxfile" text chunk), so what is saved always opens in the editor again.
 */
function mitDiagramDecode($data, $kind) {
    $data = (string) $data;
    if ($kind === 'xml') {
        $xml = trim($data);
        if ($xml === '' || strlen($xml) > MIT_DIAGRAM_MAX_BYTES || !preg_match('/^<(mxfile|mxGraphModel)\b/', $xml)) {
            return [null, 'That is not a draw.io diagram.'];
        }
        return [$xml, ''];
    }
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $data, $m)) {
        return [null, 'The editor sent no picture.'];
    }
    $bytes = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
    if ($bytes === false || $bytes === '' || strlen($bytes) > MIT_DIAGRAM_MAX_BYTES) {
        return [null, $bytes !== false && strlen((string) $bytes) > MIT_DIAGRAM_MAX_BYTES ? 'That diagram is too large (20 MB at most).' : 'The picture could not be read.'];
    }
    $info = @getimagesizefromstring($bytes);
    if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n" || !$info || ($info[2] ?? 0) !== IMAGETYPE_PNG) {
        return [null, 'The picture could not be read.'];
    }
    if (strpos($bytes, 'mxfile') === false) {
        return [null, 'The picture holds no diagram - it could not be opened again.'];
    }
    return [$bytes, ''];
}

/** A diagram file's name: what was typed, ending ".drawio.png". */
function mitDiagramFileName($name) {
    $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', (string) $name));
    $name = preg_replace('/\s+/u', ' ', $name);
    $name = preg_replace('/(\.drawio)?(\.png)?$/i', '', $name);
    $name = trim(mb_substr($name, 0, 180));
    return ($name === '' ? 'Diagram' : $name) . MIT_DIAGRAM_SUFFIX;
}

/** A new diagram in a client's Files. Returns [file_id, error]. */
function mitDiagramFileCreate($client_id, $folder_id, $name, $data) {
    global $mysqli, $session_user_id, $session_name;
    $client_id = intval($client_id);
    if (!mitDiagramsCan() || !mitDiagramClientOk($client_id)) {
        return [0, 'You can\'t add diagrams for that client.'];
    }
    [$bytes, $error] = mitDiagramDecode($data, 'png');
    if ($error !== '') {
        return [0, $error];
    }
    $folder_id = function_exists('folderOwnedId') ? folderOwnedId($folder_id, $client_id) : 0;
    $file_name = mitDiagramFileName($name);
    $dir = dirname(__DIR__) . "/uploads/clients/$client_id";
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $reference = randomString(32) . '.png';
    if (@file_put_contents("$dir/$reference", $bytes) === false) {
        return [0, 'The diagram could not be written - check the uploads folder.'];
    }
    $size = strlen($bytes);
    mysqli_query($mysqli, "INSERT INTO files SET file_reference_name = '$reference', file_name = '" . escapeSql($file_name) . "', file_description = 'draw.io diagram',
        file_ext = 'png', file_mime_type = 'image/png', file_size = $size, file_created_by = " . intval($session_user_id) . ", file_folder_id = $folder_id, file_client_id = $client_id");
    $file_id = intval(mysqli_insert_id($mysqli));
    logAudit('File', 'Create', escapeSql(stripslashes((string) $session_name) . " made diagram $file_name"), $client_id, $file_id);
    return [$file_id, ''];
}

/*
 * Save a diagram file again: what it held is kept as a version first (the newest MIT_DIAGRAM_VERSIONS_KEPT), then the file is written in place -
 * the same file, name and links. Returns '' or a problem.
 */
function mitDiagramFileSave($file_id, $data, $note = 'Diagram saved') {
    global $mysqli, $session_user_id, $session_name;
    $file = mitDiagramFileRow($file_id);
    if (!$file || !mitDiagramsCan() || !mitDiagramsReady()) {
        return 'That diagram can\'t be changed here.';
    }
    if ($file['file_archived_at']) {
        return 'That file is archived - restore it first.';
    }
    $kind = mitDiagramFileKind($file);
    if ($kind === '') {
        return 'That file is not a diagram.';
    }
    [$bytes, $error] = mitDiagramDecode($data, $kind);
    if ($error !== '') {
        return $error;
    }
    $path = mitDiagramFilePath($file);
    $lock = 'diagram_file_' . intval($file['file_id']);
    if (function_exists('mitLock') && !mitLock($lock, 10)) {
        return 'Someone else is saving that diagram - try again.';
    }
    try {
        mitDiagramKeepVersion($file, $path);
        if (@file_put_contents($path, $bytes) === false) {
            return 'The diagram could not be written - check the uploads folder.';
        }
        $size = strlen($bytes);
        mysqli_query($mysqli, "UPDATE files SET file_size = $size WHERE file_id = " . intval($file['file_id']));
        logAudit('File', 'Edit', escapeSql(stripslashes((string) $session_name) . " $note: " . $file['file_name']), intval($file['file_client_id']), intval($file['file_id']));
    } finally {
        if (function_exists('mitUnlock')) {
            mitUnlock($lock);
        }
    }
    return '';
}

/** Keep what a diagram file holds now as a version (before it is replaced), and let the oldest go past the limit. */
function mitDiagramKeepVersion(array $file, $path) {
    global $mysqli, $session_user_id;
    if (!is_file($path)) {
        return;
    }
    $file_id = intval($file['file_id']);
    $dir = mitDiagramVersionDir($file_id, true);
    $stored = randomString(24) . '.' . (mitDiagramFileKind($file) === 'xml' ? 'drawio' : 'png');
    if (!@copy($path, "$dir/$stored")) {
        return;
    }
    $last = mysqli_fetch_row(mysqli_query($mysqli, "SELECT replaced_by, replaced_at FROM mit_diagram_versions WHERE file_id = $file_id ORDER BY version_id DESC LIMIT 1"));
    $saved_by = $last ? intval($last[0]) : intval($file['file_created_by']);
    $saved_at = $last ? $last[1] : ($file['file_updated_at'] ?: $file['file_created_at']);
    mysqli_query($mysqli, "INSERT INTO mit_diagram_versions SET file_id = $file_id, stored_name = '" . escapeSql($stored) . "', file_name = '" . escapeSql($file['file_name']) . "',
        file_size = " . intval(filesize("$dir/$stored")) . ", saved_by = $saved_by, saved_at = '" . escapeSql($saved_at) . "', replaced_by = " . intval($session_user_id ?? 0) . ", replaced_at = NOW()");
    // Only the newest are kept
    $old = mysqli_query($mysqli, "SELECT version_id, stored_name FROM mit_diagram_versions WHERE file_id = $file_id ORDER BY version_id DESC LIMIT 1000 OFFSET " . MIT_DIAGRAM_VERSIONS_KEPT);
    while ($row = mysqli_fetch_assoc($old)) {
        @unlink($dir . '/' . basename($row['stored_name']));
        mysqli_query($mysqli, "DELETE FROM mit_diagram_versions WHERE version_id = " . intval($row['version_id']));
    }
}

/** A diagram file's earlier versions, newest first. */
function mitDiagramVersions($file_id) {
    global $mysqli;
    $rows = [];
    if (!mitDiagramsReady()) {
        return $rows;
    }
    $result = mysqli_query($mysqli, "SELECT v.*, s.user_name AS saved_name, r.user_name AS replaced_name FROM mit_diagram_versions v
        LEFT JOIN users s ON s.user_id = v.saved_by LEFT JOIN users r ON r.user_id = v.replaced_by
        WHERE v.file_id = " . intval($file_id) . " ORDER BY v.version_id DESC");
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

/** One version, with its file checked (the agent can open its client). [version row, file row] or [null, null]. */
function mitDiagramVersionRow($version_id) {
    global $mysqli;
    if (!mitDiagramsReady()) {
        return [null, null];
    }
    $version = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_diagram_versions WHERE version_id = " . intval($version_id)));
    $file = $version ? mitDiagramFileRow($version['file_id']) : null;
    return $file ? [$version, $file] : [null, null];
}

/** Put an earlier version back (what is there now is kept as a version too). Returns '' or a problem. */
function mitDiagramRestore($version_id) {
    [$version, $file] = mitDiagramVersionRow($version_id);
    if (!$version) {
        return 'That version no longer exists.';
    }
    $path = mitDiagramVersionDir($file['file_id']) . '/' . basename($version['stored_name']);
    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return 'That version\'s file is missing.';
    }
    $data = mitDiagramFileKind($file) === 'xml' ? $bytes : 'data:image/png;base64,' . base64_encode($bytes);
    return mitDiagramFileSave($file['file_id'], $data, 'restored the version of ' . date('M j, Y g:i A', strtotime($version['replaced_at'])) . ' of diagram');
}

/** A document row (any agent with its client), or null. */
function mitDiagramDocumentRow($document_id) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT document_id, document_name, document_description, document_content, document_client_id, document_archived_at,
        document_created_at, document_created_by, document_updated_at, document_updated_by FROM documents WHERE document_id = " . intval($document_id)));
    return $row && mitDiagramClientOk($row['document_client_id']) ? $row : null;
}

/** The diagrams in a document's HTML, in order - DOMElement list (img with the mit-drawio class). */
function mitDiagramImages(DOMDocument $dom) {
    $found = [];
    foreach ($dom->getElementsByTagName('img') as $img) {
        if (preg_match('/(^|\s)mit-drawio(\s|$)/', (string) $img->getAttribute('class'))) {
            $found[] = $img;
        }
    }
    return $found;
}

/*
 * Save a diagram straight into a document (its page's "Edit diagram"): the $index-th diagram, which must still show $src (else someone changed
 * the document since the page was opened). The document's content before is kept as a version, as ITFlow's Edit does; the picture is written
 * to uploads/documents/<id>/ by ITFlow's saveBase64Images. Returns [problem or '', the diagram's new address - a later save in the same editor
 * names it].
 */
function mitDiagramDocumentSave($document_id, $index, $src, $data) {
    global $mysqli, $session_user_id, $session_name;
    $row = mitDiagramDocumentRow($document_id);
    if (!$row || !mitDiagramsCan()) {
        return ['That document can\'t be changed here.', ''];
    }
    if ($row['document_archived_at']) {
        return ['That document is archived.', ''];
    }
    [$bytes, $error] = mitDiagramDecode($data, 'png');
    if ($error !== '') {
        return [$error, ''];
    }
    $document_id = intval($row['document_id']);
    $lock = "diagram_document_$document_id";
    if (function_exists('mitLock') && !mitLock($lock, 10)) {
        return ['Someone else is saving that document - try again.', ''];
    }
    try {
        $row = mitDiagramDocumentRow($document_id); // read again under the lock
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="mit-diagram-root">' . $row['document_content'] . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $images = mitDiagramImages($dom);
        $img = $images[intval($index)] ?? null;
        if (!$img || ($src !== '' && $img->getAttribute('src') !== $src)) {
            return ['The document changed since this page was opened - reload it and edit the diagram again.', ''];
        }
        $img->setAttribute('src', 'data:image/png;base64,' . base64_encode($bytes));
        $root = $dom->getElementById('mit-diagram-root');
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }
        $html = saveBase64Images($html, dirname(__DIR__) . '/uploads/documents/', 'uploads/documents/', $document_id);

        // What it said before, kept as a version (ITFlow's documentKeepVersion, in agent/post/document.php)
        $by = intval($row['document_updated_by']) ?: intval($row['document_created_by']);
        $at = escapeSql($row['document_updated_at'] ?: $row['document_created_at']);
        mysqli_query($mysqli, "INSERT INTO document_versions SET document_version_name = '" . escapeSql($row['document_name']) . "',
            document_version_description = '" . escapeSql((string) $row['document_description']) . "',
            document_version_content = '" . mysqli_real_escape_string($mysqli, (string) $row['document_content']) . "',
            document_version_created_by = $by, document_version_created_at = '$at', document_version_document_id = $document_id");
        mysqli_query($mysqli, "UPDATE documents SET document_content = '" . mysqli_real_escape_string($mysqli, $html) . "',
            document_content_raw = '" . escapeSql(documentContentRaw($row['document_name'], $html)) . "', document_updated_by = " . intval($session_user_id) . "
            WHERE document_id = $document_id");
        logAudit('Document', 'Edit', escapeSql(stripslashes((string) $session_name) . ' edited a diagram in document ' . $row['document_name'] . ', previous version kept'), intval($row['document_client_id']), $document_id);
        // Its new address, for the next save from the same editor
        $after = new DOMDocument();
        libxml_use_internal_errors(true);
        $after->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $now = mitDiagramImages($after)[intval($index)] ?? null;
        return ['', $now ? (string) $now->getAttribute('src') : ''];
    } finally {
        if (function_exists('mitUnlock')) {
            mitUnlock($lock);
        }
    }
}

/** The top bar's part: the script and what it needs (our includes/mit_timers_nav.php). Nothing for agents who can't edit diagrams. */
function mitDiagramsNav() {
    if (!mitDiagramsCan()) {
        return '';
    }
    return '<li class="nav-item d-none" id="mitDiagrams" data-editor="' . escapeHtml(mitDiagramEditorUrl()) . '" data-endpoint="/agent/mit_diagrams_ajax.php"'
        . ' data-csrf="' . escapeHtml($_SESSION['csrf_token'] ?? '') . '">'
        . '<script src="' . mitAsset('/agent/js/mit_diagrams.js') . '" defer></script></li>';
}

/** "New diagram" in the Files page's Upload menu (agent/files.php). */
function mitDiagramNewMenuItem($client_id, $folder_id) {
    if (!mitDiagramsCan()) {
        return '';
    }
    return '<a class="dropdown-item" href="#" data-mit-diagram-new data-client-id="' . intval($client_id) . '" data-folder-id="' . intval($folder_id) . '">'
        . '<i class="fas fa-fw fa-project-diagram me-2"></i>New diagram</a>';
}

/** A diagram file's ⋮ items (agent/files.php filesItemMenu): Edit diagram, Earlier versions. */
function mitDiagramFileMenu(array $item) {
    if (($item['kind'] ?? '') !== 'file' || !empty($item['archived']) || !mitDiagramsCan()) {
        return '';
    }
    $file = (array) ($item['row'] ?? []);
    $kind = mitDiagramFileKind($file + ['file_name' => $item['name'] ?? '']);
    if ($kind === '') {
        return '';
    }
    $id = intval($item['id']);
    $count = mitDiagramsReady() ? count(mitDiagramVersions($id)) : 0;
    return '<div class="dropdown-divider"></div>'
        . '<a class="dropdown-item" href="#" data-mit-diagram-edit data-file-id="' . $id . '" data-kind="' . $kind . '"><i class="fas fa-fw fa-project-diagram me-2"></i>Edit diagram</a>'
        . ($count ? '<a class="dropdown-item ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/mit_diagram/versions.php?id=' . $id . '"><i class="fas fa-fw fa-history me-2"></i>Earlier versions <span class="badge text-bg-secondary ms-1">' . $count . '</span></a>' : '');
}
