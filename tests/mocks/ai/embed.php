<?php
/*
 * Mountaineer IT - the stand-ins' embeddings (ai-mock and ollama-mock): a text becomes a bag of "concepts" - words of a synonym group
 * count as the same concept - hashed into MOCK_EMBED_DIMS numbers. Texts about the same thing in other words ("documents will not come
 * out of the printer" / "printing stuck in the queue") come out close; unrelated ones far apart. Deterministic, no model.
 */
const MOCK_EMBED_DIMS = 256;

/** word => concept. Each group: texts using any of these words are about the same thing. */
function mockEmbedGroups() {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $groups = [
        'print' => ['print', 'prints', 'printing', 'printed', 'printer', 'printers', 'spooler', 'spool', 'queue', 'queued', 'jobs', 'job', 'plotter', 'plotters', 'toner', 'paper'],
        'scan' => ['scan', 'scans', 'scanner', 'scanning', 'scanned', 'copier', 'copiers', 'mfp'],
        'email' => ['email', 'emails', 'e-mail', 'mail', 'mails', 'mailbox', 'mailboxes', 'smtp', 'relay', 'inbox', 'send', 'sending', 'sent', 'arrive', 'arriving'],
        'mfa' => ['mfa', '2fa', 'authenticator', 'prompt', 'prompts', 'sign-in', 'signin', 'sign', 'login', 'logon', 'token', 'loop', 'looping', 'loops'],
        'outlook' => ['outlook', 'exchange', 'office', 'm365', '365'],
        'label' => ['label', 'labels', 'labeler', 'labelprinter'],
        'blank' => ['blank', 'empty', 'nothing', 'white'],
        'stuck' => ['stuck', 'hung', 'hang', 'hangs', 'frozen', 'freeze', 'sit', 'sits', 'stopped', 'stops', 'stop', 'never', 'not', "won't", 'wont', 'will'],
        'offline' => ['offline', 'down', 'unreachable', 'disconnected'],
        'network' => ['network', 'dhcp', 'ip', 'lease', 'reservation', 'cable', 'port', 'switch', 'wifi', 'wireless'],
        'fix' => ['fix', 'fixed', 'fixes', 'resolve', 'resolved', 'solution', 'works', 'working', 'restart', 'restarted', 'reboot', 'cleared', 'clear'],
        'out' => ['out', 'come', 'comes', 'coming'],
    ];
    $map = [];
    foreach ($groups as $concept => $words) {
        foreach ($words as $word) {
            $map[$word] = $concept;
        }
    }
    return $map;
}

/** One text's vector (unit length). */
function mockEmbed($text) {
    static $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'is', 'it', 'its', 'this', 'that', 'with', 'from', 'at', 'by', 'be', 'was',
        'were', 'are', 'we', 'do', 'does', 'did', 'how', 'what', 'why', 'i', 'you', 'they', 'them', 'there', 'search_document', 'search_query', 'ticket', 'document'];
    $map = mockEmbedGroups();
    $vector = array_fill(0, MOCK_EMBED_DIMS, 0.0);
    $vector[0] = 0.05; // never all zero
    preg_match_all("/[a-z0-9][a-z0-9'_-]*/", strtolower((string) $text), $m);
    foreach ($m[0] as $word) {
        if (in_array($word, $stop, true) || strlen($word) < 2) {
            continue;
        }
        if (isset($map[$word])) {
            $key = 'concept:' . $map[$word];
            $weight = 1.0;
        } else {
            $key = 'word:' . (strlen($word) > 4 ? rtrim($word, 's') : $word);
            $weight = 0.35; // words of no group count, but less than a shared concept
        }
        $vector[1 + (crc32($key) % (MOCK_EMBED_DIMS - 1))] += $weight;
    }
    $length = sqrt(array_sum(array_map(fn($v) => $v * $v, $vector))) ?: 1.0;
    return array_map(fn($v) => round($v / $length, 6), $vector);
}

/** The OpenAI-style /v1/embeddings answer for a request body. */
function mockEmbeddingsAnswer(array $body) {
    $input = $body['input'] ?? [];
    $texts = is_array($input) ? array_values($input) : [(string) $input];
    $data = [];
    $tokens = 0;
    foreach ($texts as $i => $text) {
        $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => mockEmbed((string) $text)];
        $tokens += max(1, intval(ceil(strlen((string) $text) / 4)));
    }
    return ['object' => 'list', 'data' => $data, 'model' => (string) ($body['model'] ?? ''), 'usage' => ['prompt_tokens' => $tokens, 'total_tokens' => $tokens]];
}

/** Record a request (path + body) in a stand-in's state, under a lock - the web server and a background worker write at once. */
function mockRecord($name, $path, $body, $keep = 300) {
    $file = mockStateFile($name);
    $lock = fopen("$file.lock", 'c');
    @chmod("$file.lock", 0666);
    flock($lock, LOCK_EX);
    $state = mockLoad($name, []);
    $state['calls'][] = ['path' => $path, 'body' => $body, 'at' => date('c')];
    $state['calls'] = array_slice($state['calls'], -$keep);
    mockSave($name, $state);
    flock($lock, LOCK_UN);
    fclose($lock);
}

/** Change a stand-in's state under the same lock. */
function mockUpdate($name, callable $change) {
    $file = mockStateFile($name);
    $lock = fopen("$file.lock", 'c');
    @chmod("$file.lock", 0666);
    flock($lock, LOCK_EX);
    $state = $change(mockLoad($name, []));
    mockSave($name, $state);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $state;
}

/** The calls, as JSON (slashes and accents left as they are, so a test can look for text in it). */
function mockCallsOut($name) {
    header('Content-Type: application/json');
    echo json_encode(array_values(mockLoad($name, [])['calls'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
