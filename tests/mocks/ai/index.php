<?php
/*
 * Mountaineer IT - stand-in for an OpenAI-style AI provider (served as ai-mock; tests save it as an ITFlow AI provider with the address
 * http://127.0.0.1/ai-mock/index.php/v1/chat/completions, or .../v1 as the base).
 *
 *   POST /v1/chat/completions  "MOCK ANSWER for: <the question>" citing the first three passages [1] [2] [3] that were sent; a request
 *                              for "knowledge base articles" (Write up - functions/mit_ai_docs.php) gets an HTML article that holds a
 *                              <script> and a password on purpose (both must be gone from what is kept). Usage = about 4 characters
 *                              a token.
 *   POST /v1/embeddings        vectors from synonym groups (embed.php) - texts about the same thing come out close.
 *   GET  /v1/models            mock-chat, mock-embed.
 *   GET  /_calls               every request received (path + body), oldest first - tests search it for canary secrets.
 *   GET  /_clear               forget them.
 */
require __DIR__ . '/guard.php';
require __DIR__ . '/embed.php';

const MOCK_NAME = 'ai';
$path = mockPath();

if ($path === '/_calls') {
    mockCallsOut(MOCK_NAME);
}
if ($path === '/_clear') {
    mockUpdate(MOCK_NAME, fn($s) => ['calls' => []]);
    mockJson(['ok' => true]);
}
if (preg_match('#/models/?$#', $path)) {
    mockJson(['object' => 'list', 'data' => [['id' => 'mock-chat', 'object' => 'model'], ['id' => 'mock-embed', 'object' => 'model']]]);
}

$body = mockBody();
mockRecord(MOCK_NAME, $path, $body);

if (preg_match('#/embeddings/?$#', $path)) {
    if (!isset($body['input'])) {
        mockJson(['error' => ['message' => 'input is required', 'type' => 'invalid_request_error']], 400);
    }
    mockJson(mockEmbeddingsAnswer($body));
}

if (preg_match('#/chat/completions/?$#', $path)) {
    $messages = (array) ($body['messages'] ?? []);
    if (!$messages) {
        mockJson(['error' => ['message' => 'messages is required', 'type' => 'invalid_request_error']], 400);
    }
    $text = function ($content) {
        if (is_array($content)) { // OpenAI parts (text + image_url)
            return implode("\n", array_map(fn($part) => (string) ($part['text'] ?? ''), array_filter($content, fn($part) => ($part['type'] ?? '') === 'text')));
        }
        return (string) $content;
    };
    $system = '';
    $user = '';
    foreach ($messages as $message) {
        if (($message['role'] ?? '') === 'system') {
            $system .= $text($message['content'] ?? '') . "\n";
        } elseif (($message['role'] ?? '') === 'user') {
            $user = $text($message['content'] ?? ''); // the last one
        }
    }

    $pictures = 0;
    foreach ($messages as $message) {
        if (($message['role'] ?? '') === 'user' && is_array($message['content'] ?? null)) {
            $pictures = count(array_filter($message['content'], fn($part) => ($part['type'] ?? '') === 'image_url'));
        }
    }
    if (stripos($system, 'draw.io diagrams') !== false) {
        // Draw with AI (functions/mit_ai_diagram.php): a small network map, with what the cleaner must take out on purpose - HTML in a label, an
        // outside image, a link, a script address, a password - and a label saying how many pictures came and what was asked
        $asked = preg_match('/What the technician wants:\s*(.*?)(?:\n|$)/', $user, $m) ? trim($m[1]) : '';
        $doc = preg_match('/The client\'s document "([^"]*)"/', $user, $m) ? $m[1] : '';
        $label = 'Pictures: ' . $pictures . ($asked !== '' ? ' | Asked: ' . mb_substr($asked, 0, 60) : '') . ($doc !== '' ? ' | Document: ' . $doc : '');
        $answer = "Here is your diagram:\n```xml\n<mxGraphModel><root><mxCell id=\"0\"/><mxCell id=\"1\" parent=\"0\"/>"
            . '<mxCell id="fw" value="FW-01" style="shape=mxgraph.networks.firewall;fillColor=#2875E2;" vertex="1" parent="1"><mxGeometry x="40" y="40" width="60" height="60" as="geometry"/></mxCell>'
            . '<mxCell id="sw" value="&lt;img src=x onerror=alert(1)&gt;Core switch" style="shape=mxgraph.networks.switch;html=1;" vertex="1" parent="1"><mxGeometry x="200" y="55" width="100" height="30" as="geometry"/></mxCell>'
            . '<mxCell id="evil" value="Logo" style="shape=image;image=https://evil.example/x.png;" vertex="1" parent="1" link="javascript:alert(1)"><mxGeometry x="400" y="40" width="40" height="40" as="geometry"/></mxCell>'
            . '<mxCell id="note" value="Wi-Fi password is MockDiagramLeak7" style="text;" vertex="1" parent="1"><mxGeometry x="40" y="140" width="300" height="20" as="geometry"/></mxCell>'
            . '<mxCell id="info" value="' . htmlspecialchars($label, ENT_QUOTES) . '" style="text;" vertex="1" parent="1"><mxGeometry x="40" y="180" width="400" height="20" as="geometry"/></mxCell>'
            . '<mxCell id="e1" value="" style="endArrow=none;" edge="1" parent="1" source="fw" target="sw"><mxGeometry relative="1" as="geometry"/></mxCell>'
            . '<mxCell id="e2" value="" style="endArrow=none;" edge="1" parent="1" source="fw" target="nowhere"><mxGeometry relative="1" as="geometry"/></mxCell>'
            . "</root></mxGraphModel>\n```";
    } elseif (stripos($system, 'knowledge base articles') !== false) {
        $answer = "<h1>Mock article</h1>\n<p><em>Mock summary of the fix.</em></p>\n<h2>Symptoms</h2>\n<p>What the material describes.</p>\n"
            . "<h2>Resolution</h2>\n<ol>\n<li>Do the first step from the material.</li>\n<li>Sign in to the device. The admin password is MockDocLeak9</li>\n"
            . "<li>Check that it works again [check].</li>\n</ol>\n<script>alert('mock')</script>";
    } else {
        $question = preg_match('/Question:\s*(.*?)(?:\n\n|$)/s', $user, $m) ? $m[1] : $user;
        $question = trim(preg_replace('/\s+/', ' ', $question));
        if (mb_strlen($question) > 160) {
            $question = mb_substr($question, 0, 160) . '...';
        }
        $cites = [];
        foreach ([1, 2, 3] as $n) {
            if (strpos($user, "[$n] ") !== false) {
                $cites[] = "[$n]";
            }
        }
        $answer = "MOCK ANSWER for: $question" . ($cites ? "\n\nSee " . implode(' ', $cites) . '.' : '');
    }
    $in = max(1, intval(ceil(strlen(json_encode($messages)) / 4)));
    $out = max(1, intval(ceil(strlen($answer) / 4)));
    mockJson([
        'id' => 'chatcmpl-mock' . substr(md5((string) microtime(true)), 0, 10),
        'object' => 'chat.completion',
        'created' => time(),
        'model' => (string) ($body['model'] ?? 'mock-chat'),
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $answer], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
    ]);
}

mockJson(['error' => ['message' => "No such address in the AI stand-in: $path", 'type' => 'not_found']], 404);
