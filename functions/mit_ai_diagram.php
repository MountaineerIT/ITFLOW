<?php
/*
 * Mountaineer IT - AI helper, round 4: Draw with AI (Nov 19, 26.11.8.11 - the user's request: give AI a fire map / floor plan or a client's earlier
 * document and have it build the map). Required from functions/mit_ai_helper.php; a file of the AI helper, so the password wall applies
 * (CLAUDE.md, scripts/mit_ai_wall_check.php): it reads only one of the client's documents (document text, cleaned by mitAiScrub) and what the
 * agent types (cleaned too). Pictures the agent gives it (a floor plan, a fire escape map, a photo of a whiteboard, an old diagram) go to the
 * provider as they are - a picture can't be scrubbed: the user allowed it as for Ask AI pictures (Nov 19), with the same warning beside them;
 * they are never kept by us unless the agent ticks "Use it as the background", which puts the picture into the diagram itself (locked,
 * at the back) so the walls are exactly the plan and AI's rooms / devices sit on top at the picture's coordinates.
 *
 * The answer is draw.io XML. It never reaches the editor as it came: mitAiDiagramClean rebuilds it from draw.io's own elements and attributes
 * only - plain-text labels (tags dropped, html=0, passwords scrubbed), styles without images, links or addresses, at most MIT_AI_DIAGRAM_CELLS
 * cells. The editor opens it for the agent to check; nothing is saved until they press Save. ITFlow's Documentation model (General as fallback),
 * feature mit_ai_diagram - counted in the helper's monthly limit.
 */

const MIT_AI_DIAGRAM_CELLS = 600;
const MIT_AI_DIAGRAM_DOC_CHARS = 12000;
const MIT_AI_DIAGRAM_ASK_CHARS = 2000;

/** May the signed-in agent draw with AI? Use AI, diagrams (Support edit), and a model for Documentation (or General). */
function mitAiDiagramCan() {
    return function_exists('mitDiagramsCan') && mitDiagramsCan() && function_exists('aiReady') && aiReady('Documentation');
}

/** The client's documents to draw from: [id, name] (unarchived, newest first). */
function mitAiDiagramDocuments($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    $out = [];
    $result = mysqli_query($mysqli, "SELECT document_id, document_name FROM documents WHERE document_client_id = $client_id AND document_archived_at IS NULL
        ORDER BY COALESCE(document_updated_at, document_created_at) DESC LIMIT 200");
    while ($row = mysqli_fetch_assoc($result)) {
        $out[] = ['id' => intval($row['document_id']), 'name' => $row['document_name']];
    }
    return $out;
}

/** The instructions for the model. */
function mitAiDiagramSystemPrompt() {
    return 'You make draw.io diagrams for an IT service company - network maps, site maps and floor plans. Answer with ONLY one draw.io <mxGraphModel>'
        . ' element (<mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/> ... </root></mxGraphModel>) - no explanation, no code fence.'
        . ' Each shape is <mxCell id="..." value="label" style="..." vertex="1" parent="1"><mxGeometry x=".." y=".." width=".." height=".." as="geometry"/></mxCell>;'
        . ' each connection is <mxCell id="..." value="" style="endArrow=none;edgeStyle=orthogonalEdgeStyle;" edge="1" parent="1" source="id" target="id">'
        . '<mxGeometry relative="1" as="geometry"/></mxCell>. Labels are plain text (put line breaks as &#xa;). For devices use draw.io network shapes:'
        . ' style "shape=mxgraph.networks.NAME;labelPosition=center;verticalLabelPosition=bottom;align=center;verticalAlign=top;fillColor=#2875E2;strokeColor=#ffffff;"'
        . ' with NAME one of firewall, router, switch, wireless_hub, modem, server, nas_filer, virtual_server, ups_enterprise, patch_panel, printer, phone_2,'
        . ' pc, laptop, monitor, security_camera, cloud, rack. Rooms and areas are rectangles ("rounded=0;fillColor=#F5F9FF;strokeColor=#6C8EBF;verticalAlign=top;")'
        . ' and groups like networks or VLANs are dashed containers ("rounded=1;dashed=1;container=1;verticalAlign=top;align=left;"). Lay it out clearly'
        . ' left to right or top to bottom without overlaps. Use only what the material shows or the technician asks for; where something is a guess,'
        . ' end its label with (check). Never use images, links or HTML. Never write a password, PIN, key or code - such things are in ITFlow\'s password vault.';
}

/*
 * A draft diagram: $opts = client_id, document_id (0 = none), ask (what the technician typed), images (data: addresses - mitAiImagesFromUpload),
 * background (1 = the first picture becomes the diagram's locked background). Returns ['ok' => true, 'xml', 'removed'] or ['ok' => false, 'error'].
 */
function mitAiDiagramDraft(array $opts) {
    global $mysqli;
    if (!mitAiDiagramCan()) {
        return ['ok' => false, 'error' => 'Drawing with AI needs Use AI, Support edit and an AI model for Documentation (Admin > AI Providers).'];
    }
    $limit = mitAiTokenLimit();
    if ($limit && mitAiTokensThisMonth() >= $limit) {
        return ['ok' => false, 'error' => 'The AI helper has used this month\'s token limit. It starts again on the 1st.'];
    }
    $client_id = intval($opts['client_id'] ?? 0);
    if (!mitDiagramClientOk($client_id)) {
        return ['ok' => false, 'error' => 'Pick a client you can open.'];
    }
    $images = array_values((array) ($opts['images'] ?? []));
    $removed = 0;
    [$ask, $taken] = mitAiScrub(mb_substr(trim((string) ($opts['ask'] ?? '')), 0, MIT_AI_DIAGRAM_ASK_CHARS));
    $removed += $taken;
    $text = '';
    $document_id = intval($opts['document_id'] ?? 0);
    if ($document_id) {
        $document = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT document_name, document_description, document_content FROM documents
            WHERE document_id = $document_id AND document_client_id = $client_id AND document_archived_at IS NULL"));
        if (!$document) {
            return ['ok' => false, 'error' => 'That document is not one of this client\'s.'];
        }
        $body = trim(mitAiText((string) $document['document_description']) . "\n\n" . mitAiText((string) $document['document_content']));
        [$body, $taken] = mitAiScrub(mb_substr($body, 0, MIT_AI_DIAGRAM_DOC_CHARS));
        [$name] = mitAiScrub((string) $document['document_name']);
        $removed += $taken;
        $text .= "The client's document \"$name\":\n$body\n\n";
    }
    if ($ask === '' && $text === '' && !$images) {
        return ['ok' => false, 'error' => 'Give it something to draw from - a picture, a document, or a few words on what you want.'];
    }
    $background = null;
    if ($images && !empty($opts['background'])) {
        $size = @getimagesizefromstring((string) base64_decode(substr($images[0], strpos($images[0], ',') + 1)));
        if ($size) {
            $background = ['data' => $images[0], 'w' => $size[0], 'h' => $size[1]];
        }
    }
    $prompt = ($ask !== '' ? "What the technician wants: $ask\n\n" : "Draw the network or site map this material describes.\n\n") . $text;
    if ($images) {
        $prompt .= $background
            ? "The first picture is a floor plan / site plan. It will sit behind your diagram at x 0 to {$background['w']} and y 0 to {$background['h']} (its own pixels)."
                . ' Do not redraw its walls: place labelled rooms, areas and devices over it at the matching coordinates, small enough not to hide the plan.'
            : 'Read the picture' . (count($images) === 1 ? '' : 's') . ' (a floor plan, fire escape map, site photo or an earlier diagram) and draw it as a clean diagram.';
    }
    if ($images) {
        $content = [['type' => 'text', 'text' => $prompt]];
        foreach ($images as $data) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $data]];
        }
    } else {
        $content = $prompt;
    }
    $model = getAiModel('Documentation');
    $model['ai_provider_redact'] = 1;
    $result = callAiApi($model, [
        ['role' => 'system', 'content' => mitAiDiagramSystemPrompt()],
        ['role' => 'user', 'content' => $content],
    ], ['feature' => 'mit_ai_diagram', 'client_id' => $client_id, 'secrets' => 'remove']);
    if (empty($result['ok'])) {
        return ['ok' => false, 'error' => ($result['error'] ?? 'The AI provider did not answer.')
            . ($images ? ' Pictures need a model that can read them - pick one on Admin > AI Providers (Documentation), or draw without the picture.' : '')];
    }
    [$xml, $error, $cleaned] = mitAiDiagramClean((string) $result['content'], $background);
    if ($xml === null) {
        return ['ok' => false, 'error' => $error];
    }
    return ['ok' => true, 'xml' => $xml, 'removed' => $removed + $cleaned];
}

/** A style string with only safe keys: no images, links, addresses or scripts; html=0. */
function mitAiDiagramStyle($style) {
    $out = [];
    foreach (explode(';', (string) $style) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $key = strtolower(strstr($part, '=', true) ?: $part);
        $value = strpos($part, '=') !== false ? substr($part, strpos($part, '=') + 1) : '';
        if (in_array($key, ['image', 'link', 'href', 'tooltip', 'html', 'imagesrc', 'placeholders', 'url'], true) || $value === 'image'
            || preg_match('#(javascript|data|https?|ftp|file):|//|<|>|\(|\)|\\\\#i', $value) || !preg_match('/^[A-Za-z0-9_.]+$/', $key)) {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9_.#%,\- ]*$/', $value)) {
            continue;
        }
        $out[] = $value === '' ? $key : "$key=$value";
    }
    $out[] = 'html=0';
    return implode(';', $out) . ';';
}

/*
 * The model's answer rebuilt as a clean draw.io model: [xml, problem, how many labels had something taken out]. Only mxGraphModel / root /
 * mxCell / mxGeometry / mxPoint / Array and their own attributes; ids are re-checked, parents / ends that don't exist are fixed or dropped.
 * $background ([data, w, h]) goes in first, locked at the back.
 */
function mitAiDiagramClean($answer, $background = null) {
    if (!preg_match('#<mxGraphModel\b.*</mxGraphModel>#s', $answer, $m)) {
        return [null, 'The AI did not answer with a diagram - try again, or say a little more about what to draw.', 0];
    }
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($m[0], LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    if (!$ok) {
        return [null, 'The AI\'s diagram could not be read - try again.', 0];
    }
    $cells = [];
    $taken = 0;
    foreach ($dom->getElementsByTagName('mxCell') as $cell) {
        if (count($cells) >= MIT_AI_DIAGRAM_CELLS) {
            break;
        }
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $cell->getAttribute('id'));
        if ($id === '' || $id === '0' || $id === '1' || isset($cells[$id]) || $id === 'mitbg') {
            continue;
        }
        $value = (string) $cell->getAttribute('value');
        $plain = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '<div>', '</div>'], "\n", $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        [$plain, $scrubbed] = mitAiScrub(mb_substr($plain, 0, 300));
        $taken += $scrubbed + (strpos($value, '<') !== false ? 1 : 0);
        $geometry = null;
        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'mxGeometry') {
                $geometry = $child;
            }
        }
        $g = ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0, 'relative' => '', 'points' => []];
        if ($geometry) {
            foreach (['x', 'y', 'width', 'height'] as $k) {
                $g[$k] = is_numeric($geometry->getAttribute($k)) ? max(-100000, min(100000, round(floatval($geometry->getAttribute($k)), 1))) : 0;
            }
            $g['relative'] = $geometry->getAttribute('relative') === '1' ? '1' : '';
            foreach ($geometry->getElementsByTagName('mxPoint') as $point) {
                if ($point->parentNode->nodeName === 'Array' && count($g['points']) < 50) {
                    $g['points'][] = [round(floatval($point->getAttribute('x')), 1), round(floatval($point->getAttribute('y')), 1)];
                }
            }
        }
        $cells[$id] = [
            'value' => $plain, 'style' => mitAiDiagramStyle($cell->getAttribute('style')), 'edge' => $cell->getAttribute('edge') === '1',
            'parent' => preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $cell->getAttribute('parent')),
            'source' => preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $cell->getAttribute('source')),
            'target' => preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $cell->getAttribute('target')), 'g' => $g,
        ];
    }
    if (!$cells) {
        return [null, 'The AI\'s diagram was empty - try again, or say a little more about what to draw.', $taken];
    }
    $attr = 'mitDiagramAttr';
    $xml = '<mxGraphModel grid="1" gridSize="10" page="0"><root><mxCell id="0"/><mxCell id="1" parent="0"/>';
    if ($background) {
        $image = str_replace(';base64', '', $background['data']); // draw.io's form in a style: data:image/png,<base64>
        $xml .= '<mxCell id="mitbg" value="" style="' . $attr("shape=image;image=$image;imageAspect=0;locked=1;movable=0;resizable=0;rotatable=0;editable=0;html=0;")
            . '" vertex="1" parent="1"><mxGeometry x="0" y="0" width="' . intval($background['w']) . '" height="' . intval($background['h']) . '" as="geometry"/></mxCell>';
    }
    foreach ($cells as $id => $c) {
        $parent = isset($cells[$c['parent']]) && !$cells[$c['parent']]['edge'] && $c['parent'] !== $id ? $c['parent'] : '1';
        if ($c['edge']) {
            if (!isset($cells[$c['source']]) && !isset($cells[$c['target']])) {
                continue;
            }
            $ends = (isset($cells[$c['source']]) ? ' source="' . $c['source'] . '"' : '') . (isset($cells[$c['target']]) ? ' target="' . $c['target'] . '"' : '');
            $points = '';
            if ($c['g']['points']) {
                $points = '<Array as="points">' . implode('', array_map(fn($p) => '<mxPoint x="' . $p[0] . '" y="' . $p[1] . '"/>', $c['g']['points'])) . '</Array>';
            }
            $xml .= '<mxCell id="' . $id . '" value="' . $attr($c['value']) . '" style="' . $attr($c['style']) . '" edge="1" parent="' . $parent . '"' . $ends . '>'
                . '<mxGeometry relative="1" as="geometry">' . $points . '</mxGeometry></mxCell>';
        } else {
            $w = $c['g']['width'] > 0 ? $c['g']['width'] : 80;
            $h = $c['g']['height'] > 0 ? $c['g']['height'] : 40;
            $xml .= '<mxCell id="' . $id . '" value="' . $attr($c['value']) . '" style="' . $attr($c['style']) . '" vertex="1" parent="' . $parent . '">'
                . '<mxGeometry x="' . $c['g']['x'] . '" y="' . $c['g']['y'] . '" width="' . $w . '" height="' . $h . '" as="geometry"/></mxCell>';
        }
    }
    return [$xml . '</root></mxGraphModel>', '', $taken];
}
