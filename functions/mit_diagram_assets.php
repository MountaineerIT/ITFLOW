<?php
/*
 * Mountaineer IT - a network map built from a client's assets (Nov 19, 26.11.8.11 - the user's request: "building out network maps from current
 * assets"). No AI: ITFlow already keeps what a map needs, so it is drawn from that exactly - its locations (one box each), its networks (a zone
 * each, with VLAN, subnet and gateway), its assets (a draw.io network icon by ITFlow's asset type, with name, make / model and IP) and the
 * port-to-port connections recorded between their interfaces (asset_interface_links -> a cable each, the two ports on it). Each rack of the
 * client gets its own page: its units at their heights, front and rear. The result is draw.io XML the editor opens (agent/js/mit_diagrams.js
 * "From this client's assets"); nothing is saved until the agent saves. Required from functions/mit_diagrams.php.
 *
 * Options: locations (ids - none = all), workstations (laptops, desktops, phones, tablets - left out by default so a large site stays
 * readable), ips (shown by default), racks (pages, by default).
 */

const MIT_DIAGRAM_SLOT_W = 150;
const MIT_DIAGRAM_SLOT_H = 140;
const MIT_DIAGRAM_ROW_MAX = 6;
const MIT_DIAGRAM_RACK_U = 18;

/** draw.io's network icon for an ITFlow asset type: [shape, width, height] (draw.io's "Network" library, mxgraph.networks.*). */
function mitDiagramAssetShape($asset_type) {
    $map = [
        'Firewall/Router' => ['firewall', 60, 60], 'Switch' => ['switch', 100, 30], 'Access Point' => ['wireless_hub', 60, 40],
        'Modem/ONT' => ['modem', 80, 30], 'Server' => ['server', 40, 70], 'NAS' => ['nas_filer', 80, 50], 'Virtual Machine' => ['virtual_server', 50, 60],
        'UPS' => ['ups_enterprise', 50, 70], 'PDU' => ['patch_panel', 100, 25], 'Printer' => ['printer', 60, 60], 'Phone' => ['phone_2', 50, 50],
        'Mobile Phone' => ['mobile', 30, 50], 'Tablet' => ['tablet', 50, 40], 'Laptop' => ['laptop', 60, 40], 'Desktop' => ['pc', 60, 60],
        'Display' => ['monitor', 60, 50], 'Camera' => ['security_camera', 60, 40], 'NVR/DVR' => ['server_storage', 60, 60],
    ];
    $type = function_exists('assetTypeName') ? (assetTypeName($asset_type) ?: (string) $asset_type) : (string) $asset_type;
    return $map[$type] ?? ['desktop_pc', 50, 50];
}

/** The row an asset type sits in inside its network's zone: 0 the internet edge (modem, firewall), 1 switches, 2 the rest. */
function mitDiagramAssetTier($asset_type) {
    $tiers = [
        'Modem/ONT' => 0, 'Firewall/Router' => 1, 'Switch' => 2, 'Access Point' => 3, 'Server' => 4, 'NAS' => 4, 'Virtual Machine' => 4, 'NVR/DVR' => 4,
        'UPS' => 5, 'PDU' => 5, 'Printer' => 6, 'Camera' => 6, 'Display' => 6, 'Phone' => 6, 'Laptop' => 7, 'Desktop' => 7, 'Mobile Phone' => 7, 'Tablet' => 7,
    ];
    $type = function_exists('assetTypeName') ? (assetTypeName($asset_type) ?: (string) $asset_type) : (string) $asset_type;
    $tier = $tiers[$type] ?? 6;
    return $tier <= 1 ? 0 : ($tier === 2 ? 1 : 2); // three rows: the internet edge, switches, then everything on them
}

/** Whether an asset type is a person's device (left out unless asked for). */
function mitDiagramIsWorkstation($asset_type) {
    $type = function_exists('assetTypeName') ? (assetTypeName($asset_type) ?: (string) $asset_type) : (string) $asset_type;
    return in_array($type, ['Laptop', 'Desktop', 'Mobile Phone', 'Tablet'], true);
}

/** A value for an XML attribute - newlines kept (draw.io shows them as line breaks). */
function mitDiagramAttr($text) {
    return str_replace(["\r\n", "\n", "\r"], '&#xa;', htmlspecialchars((string) $text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

/** One vertex. */
function mitDiagramCell($id, $value, $style, $parent, $x, $y, $w, $h) {
    return '<mxCell id="' . $id . '" value="' . mitDiagramAttr($value) . '" style="' . mitDiagramAttr($style) . '" vertex="1" parent="' . $parent . '">'
        . '<mxGeometry x="' . round($x) . '" y="' . round($y) . '" width="' . round($w) . '" height="' . round($h) . '" as="geometry"/></mxCell>';
}

/** A client's locations (that the options keep) and what the map is drawn from. */
function mitDiagramAssetData($client_id, array $opts) {
    global $mysqli;
    $client_id = intval($client_id);
    // The locations picked (0 = assets with no location); none picked = all
    $want = array_values(array_unique(array_map('intval', array_filter((array) ($opts['locations'] ?? []), 'is_numeric'))));
    $locations = [];
    $result = mysqli_query($mysqli, "SELECT location_id, location_name FROM locations WHERE location_client_id = $client_id AND location_archived_at IS NULL ORDER BY location_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $locations[intval($row['location_id'])] = $row['location_name'];
    }
    $networks = [];
    $result = mysqli_query($mysqli, "SELECT network_id, network_name, network_vlan, network, network_subnet, network_gateway FROM networks
        WHERE network_client_id = $client_id AND network_archived_at IS NULL ORDER BY network_vlan IS NULL, network_vlan, network_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $networks[intval($row['network_id'])] = $row;
    }
    $assets = [];
    $result = mysqli_query($mysqli, "SELECT asset_id, asset_type, asset_name, asset_make, asset_model, asset_location_id FROM assets
        WHERE asset_client_id = $client_id AND asset_archived_at IS NULL ORDER BY asset_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $location_id = isset($locations[intval($row['asset_location_id'])]) ? intval($row['asset_location_id']) : 0;
        if ($want && !in_array($location_id, $want, true)) {
            continue;
        }
        if (empty($opts['workstations']) && mitDiagramIsWorkstation($row['asset_type'])) {
            continue;
        }
        $row['location_id'] = $location_id;
        $row['ip'] = '';
        $row['network_id'] = 0;
        $assets[intval($row['asset_id'])] = $row;
    }
    // Each asset's address and network: its primary interface's, else the first with one
    $interfaces = [];
    if ($assets) {
        $result = mysqli_query($mysqli, "SELECT interface_id, interface_name, interface_ip, interface_network_id, interface_primary, interface_asset_id FROM asset_interfaces
            WHERE interface_archived_at IS NULL AND interface_asset_id IN (" . implode(',', array_keys($assets)) . ") ORDER BY interface_primary DESC, interface_id");
        while ($row = mysqli_fetch_assoc($result)) {
            $asset_id = intval($row['interface_asset_id']);
            $interfaces[intval($row['interface_id'])] = $row;
            $ip = trim((string) $row['interface_ip']);
            if ($assets[$asset_id]['ip'] === '' && $ip !== '' && !in_array(strtolower($ip), ['dhcp', 'dynamic'], true)) {
                $assets[$asset_id]['ip'] = $ip;
            }
            if (!$assets[$asset_id]['network_id'] && isset($networks[intval($row['interface_network_id'])])) {
                $assets[$asset_id]['network_id'] = intval($row['interface_network_id']);
            }
        }
    }
    // Cables between two drawn assets
    $links = [];
    if ($interfaces) {
        $ids = implode(',', array_keys($interfaces));
        $result = mysqli_query($mysqli, "SELECT interface_link_id, interface_a_id, interface_b_id FROM asset_interface_links WHERE interface_a_id IN ($ids) AND interface_b_id IN ($ids)");
        $seen = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $a = $interfaces[intval($row['interface_a_id'])];
            $b = $interfaces[intval($row['interface_b_id'])];
            $pair = [intval($a['interface_asset_id']), intval($b['interface_asset_id'])];
            if ($pair[0] === $pair[1]) {
                continue;
            }
            $key = implode('-', [min($pair), max($pair), $a['interface_name'], $b['interface_name']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $links[] = ['a' => $pair[0], 'b' => $pair[1], 'port_a' => (string) $a['interface_name'], 'port_b' => (string) $b['interface_name']];
        }
    }
    $racks = [];
    if (!array_key_exists('racks', $opts) || !empty($opts['racks'])) {
        $result = mysqli_query($mysqli, "SELECT rack_id, rack_name, rack_units, rack_units_descending, rack_location_id FROM racks
            WHERE rack_client_id = $client_id AND rack_archived_at IS NULL ORDER BY rack_name");
        while ($row = mysqli_fetch_assoc($result)) {
            if ($want && !in_array(intval($row['rack_location_id']), $want, true)) {
                continue;
            }
            $row['units'] = [];
            $racks[intval($row['rack_id'])] = $row;
        }
        if ($racks) {
            $result = mysqli_query($mysqli, "SELECT u.unit_rack_id, u.unit_start_number, u.unit_end_number, u.unit_device, u.unit_face, u.unit_type, a.asset_name
                FROM rack_units u LEFT JOIN assets a ON a.asset_id = u.unit_asset_id
                WHERE u.unit_archived_at IS NULL AND u.unit_rack_id IN (" . implode(',', array_keys($racks)) . ") ORDER BY u.unit_start_number");
            while ($row = mysqli_fetch_assoc($result)) {
                $racks[intval($row['unit_rack_id'])]['units'][] = $row;
            }
        }
    }
    return ['locations' => $locations, 'networks' => $networks, 'assets' => $assets, 'links' => $links, 'racks' => $racks];
}

/*
 * The map as draw.io XML (an <mxfile> - the network map, then a page per rack). Returns [xml, summary] - summary counts what was drawn, for the
 * editor's message - or [null, problem].
 */
function mitDiagramFromAssets($client_id, array $opts = []) {
    if (!mitDiagramsCan() || !mitDiagramClientOk($client_id)) {
        return [null, 'You can\'t make diagrams for that client.'];
    }
    $data = mitDiagramAssetData($client_id, $opts);
    if (!$data['assets'] && !$data['racks']) {
        return [null, empty($opts['workstations']) ? 'This client has no assets to map apart from workstations - tick "Include workstations and phones", or add assets first.'
            : 'This client has no assets to map yet.'];
    }
    $show_ip = !array_key_exists('ips', $opts) || !empty($opts['ips']);

    // Location -> network -> tier -> assets
    $tree = [];
    foreach ($data['assets'] as $asset) {
        $tree[$asset['location_id']][$asset['network_id']][mitDiagramAssetTier($asset['asset_type'])][] = $asset;
    }
    uksort($tree, function ($a, $b) use ($data) {
        return $a === 0 ? 1 : ($b === 0 ? -1 : strcasecmp($data['locations'][$a], $data['locations'][$b]));
    });

    $cells = '';
    $y = 20;
    $n = 0;
    foreach ($tree as $location_id => $networks) {
        $order = array_flip(array_keys($data['networks']));
        uksort($networks, function ($a, $b) use ($networks, $order) {
            // The zone where the internet comes in (a modem) first, then one holding a firewall, then the networks in their order, "Not on a network" last
            $rank = function ($tiers) {
                foreach ($tiers[0] ?? [] as $asset) {
                    if ((function_exists('assetTypeName') ? assetTypeName($asset['asset_type']) : $asset['asset_type']) === 'Modem/ONT') {
                        return 0; // the internet comes in here
                    }
                }
                return isset($tiers[0]) ? 1 : 2;
            };
            $edge_a = $rank($networks[$a]);
            $edge_b = $rank($networks[$b]);
            if ($edge_a !== $edge_b) {
                return $edge_a <=> $edge_b;
            }
            if ($a === 0 || $b === 0) {
                return $a === 0 ? 1 : -1;
            }
            return ($order[$a] ?? 0) <=> ($order[$b] ?? 0);
        });
        $zones = '';
        $zone_y = 40;
        $width = 0;
        $location_cell = 'loc' . $location_id;
        foreach ($networks as $network_id => $tiers) {
            ksort($tiers);
            $zone_cell = 'net' . $location_id . '_' . $network_id;
            $devices = '';
            $row_y = 34;
            $cols = 1;
            foreach ($tiers as $assets) {
                foreach (array_chunk($assets, MIT_DIAGRAM_ROW_MAX) as $row) {
                    $cols = max($cols, count($row));
                    foreach ($row as $i => $asset) {
                        [$shape, $w, $h] = mitDiagramAssetShape($asset['asset_type']);
                        $label = $asset['asset_name'];
                        $model = trim($asset['asset_make'] . ' ' . $asset['asset_model']);
                        if ($model !== '') {
                            $label .= "\n" . $model;
                        }
                        if ($show_ip && $asset['ip'] !== '') {
                            $label .= "\n" . $asset['ip'];
                        }
                        $x = 20 + $i * MIT_DIAGRAM_SLOT_W + (MIT_DIAGRAM_SLOT_W - $w) / 2;
                        $style = "shape=mxgraph.networks.$shape;html=0;whiteSpace=nowrap;fontSize=11;labelPosition=center;verticalLabelPosition=bottom;align=center;verticalAlign=top;"
                            . 'fillColor=#2875E2;strokeColor=#ffffff;sketch=0;';
                        $devices .= mitDiagramCell('a' . intval($asset['asset_id']), $label, $style, $zone_cell, $x, $row_y + (70 - $h), $w, $h);
                        $n++;
                    }
                    $row_y += MIT_DIAGRAM_SLOT_H;
                }
            }
            $net = $data['networks'][$network_id] ?? null;
            $title = $net ? $net['network_name'] . ($net['network_vlan'] !== null && $net['network_vlan'] !== '' ? ' - VLAN ' . $net['network_vlan'] : '')
                . ($show_ip ? ' - ' . trim($net['network'] . ($net['network_gateway'] ? ', gateway ' . $net['network_gateway'] : '')) : '') : 'Not on a network';
            $zone_w = max(40 + $cols * MIT_DIAGRAM_SLOT_W, 40 + mb_strlen($title) * 7.5);
            $zone_h = $row_y + 6;
            $width = max($width, $zone_w);
            $zones .= mitDiagramCell($zone_cell, $title, 'rounded=1;arcSize=4;dashed=1;html=0;container=1;collapsible=0;verticalAlign=top;align=left;spacingLeft=10;spacingTop=4;'
                . 'fontStyle=1;fontSize=12;fillColor=' . ($net ? '#F5F9FF' : '#F7F7F7') . ';strokeColor=' . ($net ? '#6C8EBF' : '#999999') . ';', $location_cell, 20, $zone_y, $zone_w, $zone_h) . $devices;
            $zone_y += $zone_h + 24;
        }
        $title = $location_id ? $data['locations'][$location_id] : 'No location';
        $cells .= mitDiagramCell($location_cell, $title, 'swimlane;startSize=30;html=0;container=1;collapsible=0;fontStyle=1;fontSize=14;fillColor=#DAE8FC;strokeColor=#6C8EBF;swimlaneFillColor=#FFFFFF;',
            '1', 20, $y, $width + 40, $zone_y) . $zones;
        $y += $zone_y + 50;
    }
    $e = 0;
    foreach ($data['links'] as $link) {
        $label = trim($link['port_a'] . ($link['port_a'] !== '' && $link['port_b'] !== '' ? ' - ' : '') . $link['port_b']);
        $cells .= '<mxCell id="l' . (++$e) . '" value="' . mitDiagramAttr($label) . '" style="endArrow=none;html=0;edgeStyle=orthogonalEdgeStyle;rounded=1;strokeWidth=2;strokeColor=#6C8EBF;fontSize=10;labelBackgroundColor=#FFFFFF;"'
            . ' edge="1" parent="1" source="a' . $link['a'] . '" target="a' . $link['b'] . '"><mxGeometry relative="1" as="geometry"/></mxCell>';
    }
    $pages = '<diagram id="mit-network" name="Network map"><mxGraphModel grid="1" gridSize="10" page="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>' . $cells . '</root></mxGraphModel></diagram>';

    foreach ($data['racks'] as $rack) {
        $pages .= mitDiagramRackPage($rack);
    }
    $summary = $n . ' device' . ($n === 1 ? '' : 's') . ', ' . count($data['links']) . ' connection' . (count($data['links']) === 1 ? '' : 's')
        . ($data['racks'] ? ', ' . count($data['racks']) . ' rack' . (count($data['racks']) === 1 ? '' : 's') . ' (a page each)' : '');
    return ['<mxfile host="ITFlow">' . $pages . '</mxfile>', $summary];
}

/** A rack's page: its frame with the U numbers, its units at their heights - the front, and the rear beside it when anything is mounted there. */
function mitDiagramRackPage(array $rack) {
    $units = max(1, intval($rack['rack_units']) ?: 42);
    $down = !empty($rack['rack_units_descending']); // U1 at the top
    $rear = array_filter($rack['units'], fn($u) => strtolower((string) $u['unit_face']) === 'rear');
    $id = 'r' . intval($rack['rack_id']);
    $cells = '';
    foreach ($rear ? ['front' => 40, 'rear' => 360] : ['front' => 40] as $face => $x) {
        $frame = "{$id}_$face";
        $cells .= mitDiagramCell($frame, $rack['rack_name'] . ($rear ? ' - ' . $face : ''), 'rounded=0;html=0;verticalAlign=top;fontStyle=1;spacingTop=-22;fillColor=#333333;strokeColor=#222222;fontColor=#000000;',
            '1', $x, 50, 260, $units * MIT_DIAGRAM_RACK_U + 20);
        for ($u = 1; $u <= $units; $u++) {
            $pos = $down ? $u - 1 : $units - $u;
            $cells .= mitDiagramCell("{$frame}_u$u", (string) $u, 'text;html=0;align=right;fontSize=8;fontColor=#BBBBBB;', '1', $x + 2, 60 + $pos * MIT_DIAGRAM_RACK_U, 22, MIT_DIAGRAM_RACK_U);
        }
        foreach ($rack['units'] as $i => $unit) {
            if ((strtolower((string) $unit['unit_face']) === 'rear') !== ($face === 'rear')) {
                continue;
            }
            $start = max(1, intval($unit['unit_start_number']));
            $end = min($units, max($start, intval($unit['unit_end_number'])));
            $top = $down ? $start - 1 : $units - $end;
            $label = trim((string) ($unit['unit_device'] ?: $unit['asset_name'] ?: $unit['unit_type'] ?: 'Device'));
            $cells .= mitDiagramCell("{$frame}_d$i", $label, 'rounded=1;arcSize=6;html=0;whiteSpace=wrap;fontSize=10;fillColor=#DAE8FC;strokeColor=#6C8EBF;',
                '1', $x + 28, 60 + $top * MIT_DIAGRAM_RACK_U + 1, 222, ($end - $start + 1) * MIT_DIAGRAM_RACK_U - 2);
        }
    }
    return '<diagram id="mit-rack-' . intval($rack['rack_id']) . '" name="' . mitDiagramAttr('Rack: ' . $rack['rack_name']) . '"><mxGraphModel grid="1" gridSize="10" page="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>'
        . $cells . '</root></mxGraphModel></diagram>';
}

/** The client's locations for the "From assets" choices: id => name (0 = No location, when assets have none). */
function mitDiagramAssetLocations($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    $out = [];
    $result = mysqli_query($mysqli, "SELECT l.location_id, l.location_name, COUNT(a.asset_id) AS n FROM locations l
        LEFT JOIN assets a ON a.asset_location_id = l.location_id AND a.asset_archived_at IS NULL
        WHERE l.location_client_id = $client_id AND l.location_archived_at IS NULL GROUP BY l.location_id ORDER BY l.location_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $out[] = ['id' => intval($row['location_id']), 'name' => $row['location_name'], 'assets' => intval($row['n'])];
    }
    // Assets with no location (or one archived) - a choice of their own
    $none = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM assets a LEFT JOIN locations l ON l.location_id = a.asset_location_id AND l.location_archived_at IS NULL
        WHERE a.asset_client_id = $client_id AND a.asset_archived_at IS NULL AND l.location_id IS NULL"))[0] ?? 0);
    if ($none) {
        $out[] = ['id' => 0, 'name' => 'No location', 'assets' => $none];
    }
    return $out;
}
