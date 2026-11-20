<?php
/*
 * Diagrams with draw.io (functions/mit_diagrams.php, 26.11.8.10). Through our functions and ITFlow's own: what the editor sends is checked (a real
 * PNG carrying the diagram, draw.io XML for a .drawio file); a new diagram file; saving keeps the bytes before as a version, the newest
 * MIT_DIAGRAM_VERSIONS_KEPT only (their files go with them); restoring puts one back and keeps what was there; a .drawio file saved as XML; a
 * document's diagram saved in place (the picture written to uploads/documents/ by ITFlow's saveBase64Images, ITFlow's version kept, a stale page
 * refused); ITFlow's document purifier keeps the diagram; a client-limited agent can't reach another client's diagram; the version folder is
 * closed to the web. Removes what it makes.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitDiagramsReady') || !mitDiagramsReady()) {
    echo "  skip - diagrams (M066) not here\n";
    exit(0);
}
$root = getcwd();
[$client_id] = scenarioMakeClient('ZZ Diagrams ' . time());
$made = ['files' => [], 'documents' => []];

/** A PNG the way draw.io's xmlpng writes one: a picture with a tEXt "mxfile" chunk holding the diagram. */
$png = function ($label) {
    $img = imagecreatetruecolor(40, 20);
    imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
    ob_start();
    imagepng($img);
    $raw = ob_get_clean();
    $text = 'mxfile' . "\0" . rawurlencode('<mxfile><diagram name="' . $label . '"><mxGraphModel><root><mxCell id="0"/></root></mxGraphModel></diagram></mxfile>');
    $chunk = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text));
    $iend = strrpos($raw, 'IEND') - 4;
    return 'data:image/png;base64,' . base64_encode(substr($raw, 0, $iend) . $chunk . substr($raw, $iend));
};
$plain_png = function () {
    $img = imagecreatetruecolor(10, 10);
    ob_start();
    imagepng($img);
    return 'data:image/png;base64,' . base64_encode(ob_get_clean());
};

try {
    // What the editor sends
    scenarioCheck('a picture without a diagram is refused', mitDiagramDecode($plain_png(), 'png')[1] !== '', true);
    scenarioCheck('... and something that is no PNG', mitDiagramDecode('data:image/png;base64,' . base64_encode('<svg onload=alert(1)>mxfile</svg>'), 'png')[1] !== '', true);
    scenarioCheck('... an SVG address', mitDiagramDecode('data:image/svg+xml;base64,' . base64_encode('<svg/>'), 'png')[1] !== '', true);
    scenarioCheck('a draw.io PNG is taken', mitDiagramDecode($png('A'), 'png')[1], '');
    scenarioCheck('XML that is not draw.io\'s is refused', mitDiagramDecode('<html></html>', 'xml')[1] !== '', true);

    // A new diagram file
    [$file_id, $error] = mitDiagramFileCreate($client_id, 0, 'Network / map', $png('A'));
    $made['files'][] = $file_id;
    scenarioCheck("a new diagram file ($error)", $file_id > 0, true);
    $file = mitDiagramFileRow($file_id);
    scenarioCheck('... named <name>.drawio.png, slashes taken out', $file['file_name'], 'Network map.drawio.png');
    scenarioCheck('... the editor opens it', mitDiagramFileKind($file), 'png');
    $path = mitDiagramFilePath($file);
    scenarioCheck('... on disk with the diagram', strpos((string) file_get_contents($path), 'mxfile') !== false, true);

    // Saves keep the one before - the newest MIT_DIAGRAM_VERSIONS_KEPT only
    scenarioCheck('a save', mitDiagramFileSave($file_id, $png('B')), '');
    scenarioCheck('... kept the one before', count(mitDiagramVersions($file_id)), 1);
    $first_version = mitDiagramVersions($file_id)[0];
    $first_path = mitDiagramVersionDir($file_id) . '/' . $first_version['stored_name'];
    scenarioCheck('... its bytes are the first picture', strpos((string) file_get_contents($first_path), rawurlencode('name="A"')) !== false, true);
    for ($i = 0; $i < MIT_DIAGRAM_VERSIONS_KEPT + 2; $i++) {
        mitDiagramFileSave($file_id, $png('C' . $i));
    }
    scenarioCheck('only the newest ' . MIT_DIAGRAM_VERSIONS_KEPT . ' are kept', count(mitDiagramVersions($file_id)), MIT_DIAGRAM_VERSIONS_KEPT);
    scenarioCheck('... the oldest one\'s file went with it', is_file($first_path), false);
    scenarioCheck('the version folder is closed to the web', trim((string) @file_get_contents($root . '/uploads/' . MIT_DIAGRAM_DIR . '/.htaccess')), 'Require all denied');

    // Restore: the version comes back, what was there is kept
    $newest = mitDiagramVersions($file_id)[0];
    $was = file_get_contents($path);
    scenarioCheck('putting a version back', mitDiagramRestore($newest['version_id']), '');
    scenarioCheck('... its picture is the file again', file_get_contents($path), file_get_contents(mitDiagramVersionDir($file_id) . '/' . mitDiagramVersions($file_id)[1]['stored_name']));
    scenarioCheck('... and what was there is the newest version', file_get_contents(mitDiagramVersionDir($file_id) . '/' . mitDiagramVersions($file_id)[0]['stored_name']), $was);

    // A .drawio file (draw.io XML, uploaded the usual way) is saved back as XML
    $ref = randomString(32) . '.drawio';
    file_put_contents("$root/uploads/clients/$client_id/$ref", '<mxfile><diagram name="X"/></mxfile>');
    mysqli_query($mysqli, "INSERT INTO files SET file_reference_name = '$ref', file_name = 'Rack.drawio', file_ext = 'drawio', file_mime_type = 'text/xml', file_size = 30, file_client_id = $client_id");
    $xml_id = $made['files'][] = intval(mysqli_insert_id($mysqli));
    scenarioCheck('a .drawio file opens as XML', mitDiagramFileKind(mitDiagramFileRow($xml_id)), 'xml');
    scenarioCheck('... a picture is refused for it', mitDiagramFileSave($xml_id, $png('D')) !== '', true);
    scenarioCheck('... XML saved', mitDiagramFileSave($xml_id, '<mxfile><diagram name="Y"/></mxfile>'), '');
    scenarioCheck('... written as XML, the old one a version', [file_get_contents("$root/uploads/clients/$client_id/$ref"), count(mitDiagramVersions($xml_id))], ['<mxfile><diagram name="Y"/></mxfile>', 1]);
    scenarioCheck('an ordinary picture is not a diagram', mitDiagramFileKind(['file_name' => 'photo.png', 'file_ext' => 'png']), '');

    // A document with a diagram, saved in place from its page
    mysqli_query($mysqli, "INSERT INTO documents SET document_name = 'ZZ site map', document_content = '<p>Site</p><p><img class=\"mit-drawio\" alt=\"Diagram\" src=\"/uploads/documents/0/old.png\"></p>', document_content_raw = 'ZZ site map', document_client_id = $client_id, document_created_by = $session_user_id");
    $document_id = $made['documents'][] = intval(mysqli_insert_id($mysqli));
    [$error] = mitDiagramDocumentSave($document_id, 0, '/uploads/documents/0/other.png', $png('E'));
    scenarioCheck('a page opened on another version of the document is refused', $error !== '', true);
    [$error, $src] = mitDiagramDocumentSave($document_id, 0, '/uploads/documents/0/old.png', $png('E'));
    scenarioCheck("the document's diagram saved ($error)", $error, '');
    $content = scenarioValue("SELECT document_content FROM documents WHERE document_id = $document_id");
    scenarioCheck('... written by ITFlow\'s saveBase64Images to uploads/documents/<id>/', preg_match('#^/uploads/documents/' . $document_id . '/img_[0-9a-f]+\.png$#', $src) === 1, true);
    scenarioCheck('... the content points at it, the class kept, the text around it kept', [strpos($content, $src) !== false, strpos($content, 'mit-drawio') !== false, strpos($content, '<p>Site</p>') !== false], [true, true, true]);
    scenarioCheck('... the picture is still a diagram', strpos((string) file_get_contents($root . $src), 'mxfile') !== false, true);
    scenarioCheck('... ITFlow\'s version of before', scenarioValue("SELECT COUNT(*) FROM document_versions WHERE document_version_document_id = $document_id AND document_version_content LIKE '%old.png%'"), '1');
    scenarioCheck('ITFlow\'s purifier (document page, portal) keeps the diagram', preg_match('#<img[^>]+class="mit-drawio"#', documentHtmlPurify($content)) === 1 && strpos(documentHtmlPurify($content), $src) !== false, true);
    [$error, $src2] = mitDiagramDocumentSave($document_id, 0, $src, $png('F'));
    scenarioCheck('a second save from the same editor names the new address', [$error, $src2 !== $src], ['', true]);

    // A client-limited agent can't reach this client's diagrams
    $GLOBALS['session_is_admin'] = false;
    $GLOBALS['client_deny_array'] = [$client_id];
    scenarioCheck('a client-limited agent: no file', mitDiagramFileRow($file_id), null);
    scenarioCheck('... no save', mitDiagramFileSave($file_id, $png('G')) !== '', true);
    scenarioCheck('... no new diagram there', mitDiagramFileCreate($client_id, 0, 'X', $png('G'))[0], 0);
    scenarioCheck('... no document save', mitDiagramDocumentSave($document_id, 0, '', $png('G'))[0] !== '', true);
} finally {
    $GLOBALS['session_is_admin'] = true;
    $GLOBALS['client_deny_array'] = [];
    foreach ($made['files'] as $id) {
        $id = intval($id);
        $ref = scenarioValue("SELECT file_reference_name FROM files WHERE file_id = $id");
        if ($ref) {
            @unlink("$root/uploads/clients/$client_id/" . basename($ref));
        }
        foreach (glob($root . '/uploads/' . MIT_DIAGRAM_DIR . "/$id/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($root . '/uploads/' . MIT_DIAGRAM_DIR . "/$id");
        mysqli_query($mysqli, "DELETE FROM files WHERE file_id = $id");
    }
    foreach ($made['documents'] as $id) {
        foreach (glob("$root/uploads/documents/$id/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir("$root/uploads/documents/$id");
        mysqli_query($mysqli, "DELETE FROM document_versions WHERE document_version_document_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM documents WHERE document_id = " . intval($id));
    }
    @rmdir("$root/uploads/clients/$client_id");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
