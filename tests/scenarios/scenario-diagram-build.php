<?php
/*
 * New diagrams built for the agent (26.11.8.11): from the client's assets (functions/mit_diagram_assets.php - no AI) and Draw with AI
 * (functions/mit_ai_diagram.php, against the test install's AI stand-in, which answers a diagram request with an outside image, a link, HTML and
 * a password on purpose).
 *  - Assets: two locations, a VLAN network with its gateway, a firewall / switch / access point / server / laptop with IPs, a cable between the
 *    firewall and the switch, a rack with a front and a rear unit: each drawn where it belongs with draw.io's network icons, the cable with its
 *    ports, workstations left out unless asked, IPs only when asked, one location only when picked, the rack on its own page; another client's
 *    assets never; a client-limited agent refused.
 *  - AI: what is sent (the cleaned request, the picked document - scrubbed -, the picture), the answer rebuilt clean (no HTML, image, link or
 *    password; a cable to nowhere dropped), the first picture as a locked background; a document of another client refused; the monthly limit.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitDiagramFromAssets')) {
    echo "  skip - diagrams from assets not here\n";
    exit(0);
}
$stamp = date('His');
[$client_id] = scenarioMakeClient("ZZ Map $stamp");
[$other_id] = scenarioMakeClient("ZZ Map other $stamp");
$made = ['assets' => [], 'locations' => [], 'networks' => [], 'racks' => [], 'documents' => []];
$q = function ($sql) use ($mysqli) {
    mysqli_query($mysqli, $sql);
    return intval(mysqli_insert_id($mysqli));
};
$model = function ($xml) {
    $dom = new DOMDocument();
    return @$dom->loadXML((string) $xml) ? $dom : null;
};
$cell = function (DOMDocument $dom, $id) {
    foreach ($dom->getElementsByTagName('mxCell') as $c) {
        if ($c->getAttribute('id') === $id) {
            return $c;
        }
    }
    return null;
};
$saved = [];
$provider_id = 0;
try {
    // A client with two sites
    $hq = $made['locations'][] = $q("INSERT INTO locations SET location_name = 'ZZ HQ', location_client_id = $client_id");
    $shop = $made['locations'][] = $q("INSERT INTO locations SET location_name = 'ZZ Shop', location_client_id = $client_id");
    $lan = $made['networks'][] = $q("INSERT INTO networks SET network_name = 'Office LAN', network_vlan = 10, network = '10.10.0.0/24', network_gateway = '10.10.0.1', network_location_id = $hq, network_client_id = $client_id");
    $asset = function ($type, $name, $location, $ip = '', $network = 0) use ($q, $client_id, &$made) {
        $id = $made['assets'][] = $q("INSERT INTO assets SET asset_type = '$type', asset_name = '$name', asset_make = 'Make', asset_model = 'M-$name', asset_location_id = $location, asset_client_id = $client_id");
        $iface = $q("INSERT INTO asset_interfaces SET interface_name = '" . ($type === 'Switch' ? 'port1' : 'eth1') . "', interface_ip = '$ip', interface_primary = 1, interface_network_id = " . ($network ?: 'NULL') . ", interface_asset_id = $id");
        return [$id, $iface];
    };
    [$fw, $fw_if] = $asset('Firewall/Router', 'FW-01', $hq, '10.10.0.1', $lan);
    [$sw, $sw_if] = $asset('Switch', 'SW-01', $hq, '10.10.0.2', $lan);
    [$ap] = $asset('Access Point', 'AP-LOBBY', $hq, '10.10.0.20', $lan);
    [$srv] = $asset('Server', 'SRV-01', $hq, '10.10.0.5', $lan);
    [$lt] = $asset('Laptop', 'LT-01', $hq, '10.10.0.99', $lan);
    [$pos] = $asset('Printer', 'PRN-SHOP', $shop, '192.168.5.9');
    $q("INSERT INTO asset_interface_links SET interface_a_id = $fw_if, interface_b_id = $sw_if");
    [$foreign] = $asset('Server', 'NOT-OURS', 0);
    mysqli_query($mysqli, "UPDATE assets SET asset_client_id = $other_id WHERE asset_id = $foreign");
    $rack = $made['racks'][] = $q("INSERT INTO racks SET rack_name = 'ZZ Rack A', rack_units = 12, rack_location_id = $hq, rack_client_id = $client_id");
    $q("INSERT INTO rack_units SET unit_start_number = 10, unit_end_number = 11, unit_device = 'Core switch', unit_face = 'front', unit_rack_id = $rack, unit_asset_id = $sw");
    $q("INSERT INTO rack_units SET unit_start_number = 1, unit_end_number = 2, unit_device = 'Rear PDU', unit_face = 'rear', unit_rack_id = $rack");
    // ITFlow 4.1.3: a holder (chassis) with two devices in it - named inside its box, not drawn over it
    $holder = $q("INSERT INTO rack_units SET unit_start_number = 5, unit_end_number = 6, unit_device = 'Blade chassis', unit_face = 'front', unit_rack_id = $rack");
    if (mitColumnReady('rack_units', 'unit_parent_id')) {
        foreach (['Node one', 'Node two'] as $i => $node) {
            $q("INSERT INTO rack_units SET unit_start_number = 5, unit_end_number = 6, unit_device = '$node', unit_face = 'front', unit_rack_id = $rack, unit_parent_id = $holder, unit_position = " . ($i + 1));
        }
    }

    [$xml, $summary] = mitDiagramFromAssets($client_id);
    $dom = $model($xml);
    scenarioCheck("the map is draw.io XML ($summary)", $dom instanceof DOMDocument, true);
    $pages = [];
    foreach ($dom->getElementsByTagName('diagram') as $d) {
        $pages[] = $d->getAttribute('name');
    }
    scenarioCheck('a network page and one for the rack', $pages, ['Network map', 'Rack: ZZ Rack A']);
    scenarioCheck('the firewall as draw.io\'s firewall, labelled with its model and IP', [strpos($cell($dom, "a$fw")->getAttribute('style'), 'mxgraph.networks.firewall') !== false,
        $cell($dom, "a$fw")->getAttribute('value')], [true, "FW-01\nMake M-FW-01\n10.10.0.1"]);
    scenarioCheck('the switch and access point in the LAN zone, inside HQ', [$cell($dom, "a$sw")->getAttribute('parent'), $cell($dom, "net{$hq}_$lan")->getAttribute('parent')], ["net{$hq}_$lan", "loc$hq"]);
    scenarioCheck('the zone names its VLAN, subnet and gateway', $cell($dom, "net{$hq}_$lan")->getAttribute('value'), 'Office LAN - VLAN 10 - 10.10.0.0/24, gateway 10.10.0.1');
    $edge = null;
    foreach ($dom->getElementsByTagName('mxCell') as $c) {
        if ($c->getAttribute('edge') === '1') {
            $edge = $c;
        }
    }
    scenarioCheck('the recorded cable, with its ports', $edge ? [$edge->getAttribute('source'), $edge->getAttribute('target'), $edge->getAttribute('value')] : null, ["a$fw", "a$sw", 'eth1 - port1']);
    scenarioCheck('the shop printer in the shop, not on a network', $cell($dom, "a$pos")->getAttribute('parent'), "net{$shop}_0");
    scenarioCheck('workstations left out by default', $cell($dom, "a$lt"), null);
    scenarioCheck('another client\'s assets never', $cell($dom, "a$foreign"), null);
    scenarioCheck('the rack: the front unit at U10-11, the rear one beside it', [strpos($xml, 'Core switch') !== false, strpos($xml, 'Rear PDU') !== false, strpos($xml, 'ZZ Rack A - rear') !== false], [true, true, true]);
    if (mitColumnReady('rack_units', 'unit_parent_id')) {
        scenarioCheck('a holder names what is in it, in its own box', strpos($xml, 'Blade chassis (Node one, Node two)') !== false, true);
        scenarioCheck('... and its devices are not boxes of their own over it', substr_count($xml, 'Node one'), 1);
    }

    [$xml] = mitDiagramFromAssets($client_id, ['workstations' => 1, 'ips' => 0, 'locations' => [$hq], 'racks' => 0]);
    $dom = $model($xml);
    scenarioCheck('workstations when asked', $cell($dom, "a$lt") !== null, true);
    scenarioCheck('no IPs when asked', [$cell($dom, "a$fw")->getAttribute('value'), $cell($dom, "net{$hq}_$lan")->getAttribute('value')], ["FW-01\nMake M-FW-01", 'Office LAN - VLAN 10']);
    scenarioCheck('one location when picked, no rack page', [$cell($dom, "a$pos"), $dom->getElementsByTagName('diagram')->length], [null, 1]);
    scenarioCheck('a client with nothing to map says so', mitDiagramFromAssets($other_id, ['locations' => [999999]])[0], null);
    // Assets with no location: a choice of their own (id 0), kept when picked
    mysqli_query($mysqli, "UPDATE assets SET asset_location_id = 0 WHERE asset_id = $srv");
    $choices = array_column(mitDiagramAssetLocations($client_id), 'assets', 'id');
    scenarioCheck('"No location" is offered with its count', $choices[0] ?? null, 1);
    [$xml] = mitDiagramFromAssets($client_id, ['locations' => [$hq, $shop, 0]]);
    scenarioCheck('... and drawn when picked', $cell($model($xml), "a$srv")->getAttribute('parent'), "net0_$lan");
    [$xml] = mitDiagramFromAssets($client_id, ['locations' => [$hq]]);
    scenarioCheck('... left out when not', $cell($model($xml), "a$srv"), null);

    $GLOBALS['session_is_admin'] = false;
    $GLOBALS['client_deny_array'] = [$client_id];
    scenarioCheck('a client-limited agent: refused', mitDiagramFromAssets($client_id)[0], null);
    $GLOBALS['session_is_admin'] = true;
    $GLOBALS['client_deny_array'] = [];

    // Draw with AI
    $mock = 'http://127.0.0.1/ai-mock/index.php';
    if (!function_exists('mitAiDiagramDraft') || @file_get_contents("$mock/_clear") === false) {
        echo "  skip - Draw with AI: no AI stand-in on this test install\n";
    } else {
        foreach (['ai_helper_monthly_tokens'] as $name) {
            $saved[$name] = mitSetting($name);
        }
        mitSetSetting('ai_helper_monthly_tokens', '0');
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_name LIKE 'ZZ %stand-in%'");
        $provider_id = $q("INSERT INTO ai_providers SET ai_provider_name = 'ZZ Diagram stand-in $stamp', ai_provider_kind = 'custom', ai_provider_api_url = '$mock/v1/chat/completions', ai_provider_api_key = 'mock', ai_provider_redact = 1");
        $q("INSERT INTO ai_models SET ai_model_name = 'mock-chat', ai_model_use_case = 'General', ai_model_ai_provider_id = $provider_id");
        $doc = $made['documents'][] = $q("INSERT INTO documents SET document_name = 'ZZ Site notes', document_content = '<p>Fiber modem in the closet. The NAS password is canarysitepw77.</p>', document_content_raw = '', document_client_id = $client_id");
        $other_doc = $made['documents'][] = $q("INSERT INTO documents SET document_name = 'ZZ Other notes', document_content = '<p>x</p>', document_content_raw = '', document_client_id = $other_id");
        $sent = function () use ($mock) {
            $calls = json_decode((string) @file_get_contents("$mock/_calls"), true) ?: [];
            $chats = array_values(array_filter($calls, fn($c) => str_ends_with($c['path'], '/chat/completions')));
            return $chats ? json_encode(end($chats)['body']) : '';
        };
        $img = imagecreatetruecolor(400, 300);
        ob_start();
        imagepng($img);
        $picture = mitAiImageData((string) ob_get_clean());

        $draft = mitAiDiagramDraft(['client_id' => $client_id, 'document_id' => $doc, 'ask' => 'Network map, the door code is 4471 password: hunter22', 'images' => [$picture], 'background' => 1]);
        scenarioCheck('Draw with AI answers (' . ($draft['error'] ?? '') . ')', !empty($draft['ok']), true);
        $body = $sent();
        scenarioCheck('the document went, without its password', [strpos($body, 'Fiber modem') !== false, strpos($body, 'canarysitepw77')], [true, false]);
        scenarioCheck('the request was cleaned too', strpos($body, 'hunter22'), false);
        scenarioCheck('the picture went as it is', strpos($body, 'image_url') !== false, true);
        $dom = $model($draft['xml'] ?? '');
        scenarioCheck('a clean draw.io model came back', $dom instanceof DOMDocument, true);
        $xml = $draft['xml'] ?? '';
        scenarioCheck('no HTML, outside image, link or script', [strpos($xml, 'onerror'), strpos($xml, 'evil.example'), strpos($xml, 'javascript'), stripos($xml, 'link=')], [false, false, false, false]);
        scenarioCheck('no password from the answer', strpos($xml, 'MockDiagramLeak7'), false);
        scenarioCheck('labels as plain text', $cell($dom, 'sw')->getAttribute('value'), 'Core switch');
        scenarioCheck('styles carry html=0, the outside image gone', [strpos($cell($dom, 'sw')->getAttribute('style'), 'html=0') !== false, strpos($cell($dom, 'evil')->getAttribute('style'), 'image')], [true, false]);
        scenarioCheck('the cable to a missing shape is still drawn from its one end only', [$cell($dom, 'e1')->getAttribute('target'), $cell($dom, 'e2')->hasAttribute('target')], ['sw', false]);
        $bg = $cell($dom, 'mitbg');
        scenarioCheck('the picture is the locked background, its own size', $bg ? [strpos($bg->getAttribute('style'), 'locked=1') !== false, strpos($bg->getAttribute('style'), 'image=data:image/png,') !== false,
            $bg->getElementsByTagName('mxGeometry')->item(0)->getAttribute('width')] : null, [true, true, '400']);
        scenarioCheck('the stand-in saw one picture and the request', strpos($cell($dom, 'info')->getAttribute('value'), 'Pictures: 1 | Asked: Network map') === 0, true);
        scenarioCheck('another client\'s document refused', mitAiDiagramDraft(['client_id' => $client_id, 'document_id' => $other_doc])['ok'], false);
        scenarioCheck('nothing to draw from - said so', mitAiDiagramDraft(['client_id' => $client_id])['ok'], false);
        scenarioCheck('usage counted in the helper\'s month', intval(scenarioValue("SELECT COUNT(*) FROM ai_usage WHERE ai_usage_feature = 'mit_ai_diagram' AND ai_usage_provider_id = $provider_id")) > 0, true);
        mitSetSetting('ai_helper_monthly_tokens', '1');
        scenarioCheck('the monthly limit applies', mitAiDiagramDraft(['client_id' => $client_id, 'ask' => 'x'])['ok'], false);
        scenarioCheck('a cleaner refusal: no diagram in the answer', mitAiDiagramClean('Sorry, I cannot.')[0], null);
    }
} finally {
    $GLOBALS['session_is_admin'] = true;
    $GLOBALS['client_deny_array'] = [];
    foreach ($saved as $name => $value) {
        mitSetSetting($name, $value ?? '');
    }
    if ($provider_id) {
        mysqli_query($mysqli, "DELETE FROM ai_usage WHERE ai_usage_provider_id = $provider_id");
        mysqli_query($mysqli, "DELETE FROM ai_models WHERE ai_model_ai_provider_id = $provider_id");
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_id = $provider_id");
    }
    foreach ($made['racks'] as $id) {
        mysqli_query($mysqli, "DELETE FROM rack_units WHERE unit_rack_id = $id AND unit_parent_id IS NOT NULL");
        mysqli_query($mysqli, "DELETE FROM rack_units WHERE unit_rack_id = $id");
        mysqli_query($mysqli, "DELETE FROM racks WHERE rack_id = $id");
    }
    foreach ($made['assets'] as $id) {
        mysqli_query($mysqli, "DELETE FROM assets WHERE asset_id = $id"); // interfaces and their links go with it
    }
    foreach (['networks' => 'network_id', 'locations' => 'location_id', 'documents' => 'document_id'] as $table => $pk) {
        foreach ($made[$table] as $id) {
            mysqli_query($mysqli, "DELETE FROM $table WHERE $pk = $id");
        }
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
