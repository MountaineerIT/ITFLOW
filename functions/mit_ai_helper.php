<?php

/*
 * Mountaineer IT - AI helper, part 1: the library it answers from (required from functions.php).
 *
 * Resolved / closed tickets (subject, details, replies - internal notes too unless switched off) and documents, cut into
 * passages (mit_ai_sources / mit_ai_chunks, M044) by the cron job mit_ai_index (cron/mit_ai_index.php, mitAiIndexRun), and
 * searched for the signed-in agent with mitAiSearch - only clients they can open (clientScopeSql), only with Support view and
 * ITFlow's "Use AI" permission. Keyword search (a FULLTEXT index) always; meaning-based search as well once an embeddings model is
 * set (Admin > Settings > AI helper: one of ITFlow's AI providers + a model name), the two lists merged.
 *
 * THE WALL (CLAUDE.md "AI helper"): the helper reads three things and nothing else - ticket subjects / details, ticket replies, and
 * document text (MIT_AI_READS). It never reads ITFlow's password vault or any other secret (logins, 2FA, keys, config.php); the
 * word for that vault never appears in these files, and scripts/mit_ai_wall_check.php (run by mit_verify.sh) fails the build if
 * it does. What it reads is cleaned before it is stored (mitAiScrub): ITFlow's own password filter (aiRedact) with the found
 * values dropped - never put back -, then any sentence that mentions a password-type word, then password-like strings. A source
 * that lost something is counted (mit_ai_sources.scrubbed) and listed on the admin page, so it can be moved where it belongs.
 */

// The only places the helper reads from - table => columns. Used by the indexer; the wall check reads it too.
const MIT_AI_READS = [
    'tickets' => ['ticket_subject', 'ticket_details'],
    'ticket_replies' => ['ticket_reply'],
    'documents' => ['document_name', 'document_description', 'document_content'],
];

// Words that mark a sentence as being about a secret (mitAiSecretSentence)
const MIT_AI_SECRET_WORDS = 'pass(?:word|wd|phrase|code)s?|pwd|pw|pins?|cred(?:ential)?s?|secrets?|api[ _-]?keys?|access[ _-]?keys?|secret[ _-]?keys?|private[ _-]?keys?'
    . '|recovery[ _-]?codes?|backup[ _-]?codes?|wi-?fi[ _-]?keys?|psk|pre-?shared[ _-]?keys?|license[ _-]?keys?|product[ _-]?keys?|serial[ _-]?keys?|keys? (?:is|was)';

// Ways of talking about passwords that hold none ("forgot their password", "password expired") - a sentence whose only secret words
// are in one of these stays, unless it also holds something value-like or "password is / to / :"
const MIT_AI_SAFE_PHRASES = '(?:reset(?:s|ting)?|forg(?:o|e)t(?:ten)?|chang(?:e|ed|ing)|expired?|updat(?:e|ed|ing)|sync(?:ed|ing)?|(?:re-?)?enter(?:ed|ing)?|wrong|incorrect|'
    . 'lost|new|old|temporary|self-?service|unlock(?:ed)?) (?:the |their |his |her |a |an |my |your |our |user\'?s? |account )?(?:pass(?:word|code)|pin)s?'
    . '|(?:pass(?:word|code)|pin)s? (?:reset|resets|expir(?:ed|y|ation|es)|polic(?:y|ies)|changes?|changed|managers?|prompts?|complexity|requirements?|'
    . 'portal|lock-?outs?|locked|history|age|length|sync|writeback|spray(?:ing)?|protection)'
    . '|(?:prompt(?:s|ed|ing)?|ask(?:s|ed|ing)?) (?:for )?(?:a |the |their |his |her |your )?(?:pass(?:word|code)|pin)s?'
    . '|cred(?:ential)?s? (?:manager|prompts?|pop-?ups?|dialog)|(?:windows|web) cred(?:ential)?s?';

const MIT_AI_CHUNK_CHARS = 1500;      // about 350 words a passage
const MIT_AI_INDEX_BATCH = 300;       // sources looked at per cron run
const MIT_AI_EMBED_BATCH = 64;        // passages per embeddings request
const MIT_AI_SEARCH_POOL = 40;        // candidates from each search before merging

/** Has M044 run? (One query a request.) */
function mitAiHelperReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ai_chunks'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The helper's settings (mit_settings ai_helper_*). */
function mitAiHelperSettings() {
    return [
        'enabled' => mitSetting('ai_helper_enabled', '0') === '1',
        'internal' => mitSetting('ai_helper_internal', '1') === '1',
        'documents' => mitSetting('ai_helper_documents', '1') === '1',
        'embed_provider' => intval(mitSetting('ai_helper_embed_provider', '0')),
        'embed_model' => trim((string) mitSetting('ai_helper_embed_model', '')),
    ];
}

/** May the signed-in agent use the helper? On, set up, their role has Use AI and Support view. */
function mitAiHelperCan() {
    return mitAiHelperReady() && mitAiHelperSettings()['enabled'] && function_exists('aiCan') && aiCan()
        && lookupUserPermission('module_support') >= 1;
}

/*
 * ---------- the wall: cleaning text before it is kept or sent ----------
 */

/**
 * Text with secrets taken out for good - [clean text, how many things were taken]. Three passes:
 *   1. ITFlow's aiRedact (labelled passwords, PINs, keys, tokens, private keys, 2FA links, card numbers) - each found value is
 *      dropped as [removed], never kept to be put back;
 *   2. any sentence (or line) about a secret is removed whole (mitAiSecretSentence) - stricter than ITFlow, so "the NAS password
 *      purplemonkey" goes even though nothing labels the value;
 *   3. password-like strings anywhere: 8+ characters with three of upper / lower / digit / symbol and a symbol, or 10+ with three
 *      kinds (addresses, paths, e-mail addresses and file names are left alone).
 */
function mitAiScrub($text) {
    $text = (string) $text;
    $taken = 0;

    if (function_exists('aiRedact')) {
        [$text, $secrets] = aiRedact($text);
        $taken += count($secrets);
        $text = preg_replace('/\[secret-\d+\]/', '[removed]', $text);
    }

    $lines = preg_split("/\n/", $text);
    foreach ($lines as $i => $line) {
        $sentences = preg_split('/(?<=[.!?])\s+(?=\S)/u', $line);
        foreach ($sentences as $j => $sentence) {
            if (mitAiSecretSentence($sentence)) {
                $sentences[$j] = '[removed - it mentioned a password or key]';
                $taken++;
            }
        }
        $lines[$i] = implode(' ', $sentences);
    }
    $text = implode("\n", $lines);

    $text = preg_replace_callback('/(?<=^|[\s(\[{"\'])([^\s()\[\]{}"\'<>]{8,128})(?=$|[\s)\]}"\'.,;:])/u', function ($m) use (&$taken) {
        $token = rtrim($m[1], '.,;:');
        if (preg_match('#(://|@|/|\\\\|^www\.)#', $token) || preg_match('/^\[removed/', $token)) {
            return $m[0];
        }
        $upper = preg_match('/\p{Lu}/u', $token);
        $lower = preg_match('/\p{Ll}/u', $token);
        $digit = preg_match('/\d/', $token);
        $symbol = preg_match('/[!#$%^&*+=?~|`]/', $token);
        $kinds = $upper + $lower + $digit + $symbol;
        $length = mb_strlen($token);
        if (($symbol && $kinds >= 3) || ($length >= 10 && $kinds >= 3 && !preg_match('/^[\p{L}\d]+-[\p{L}\d-]+$/u', $token))) {
            $taken++;
            return '[removed]' . substr($m[1], strlen($token));
        }
        return $m[0];
    }, $text);

    return [trim($text), $taken];
}

/**
 * Is this sentence about a secret? Yes when it has a password-type word (MIT_AI_SECRET_WORDS) outside the harmless phrases
 * (MIT_AI_SAFE_PHRASES), or a secret word followed by is / was / to / as / : / = (a value is being given), or a secret word anywhere
 * with a value-like word in the sentence (digits, symbols or mixed case: Welcome1, P@ss, hunTer2). "Bob forgot his password, reset
 * it in ADUC" stays; "reset password to Welcome1", "the NAS password purplemonkey", "pw: x" go.
 */
function mitAiSecretSentence($sentence) {
    $words = '/\b(?:' . MIT_AI_SECRET_WORDS . ')\b/iu';
    if (!preg_match($words, $sentence)) {
        return false;
    }
    if (preg_match('/\b(?:' . MIT_AI_SECRET_WORDS . ')\b\W{0,3}(?:\w+\W+){0,2}?(?:is|was|were|are|to|as|=|:|->|=>)(?:\W|$)/iu', $sentence)
        || preg_match('/(?:^|\s)(?![A-Z]+\d+\b|\d+[A-Z]+\b)(?=\S*\d)(?=\S*\p{L})\S{4,}|[!@#$%^&*+=?~|`]\S|\p{Ll}\p{Lu}|["\'\x{201C}][^"\'\x{201D}]{3,}["\'\x{201D}]/u', $sentence)) {
        return true;
    }
    $rest = preg_replace('/\b' . MIT_AI_SAFE_PHRASES . '\b/iu', ' ', $sentence);
    return (bool) preg_match($words, $rest);
}

/** HTML (or text) to plain text the way ITFlow's AI features read it. */
function mitAiText($html) {
    if (function_exists('aiHtmlToText')) {
        return aiHtmlToText((string) $html);
    }
    return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/** Text cut into passages of about MIT_AI_CHUNK_CHARS at paragraph / sentence ends, each starting with $head. */
function mitAiChunk($head, $text) {
    $room = MIT_AI_CHUNK_CHARS - mb_strlen($head) - 1;
    $pieces = [];
    foreach (preg_split("/\n{2,}|\n(?=\[(?:Asked|Reply|Internal|From the client)\])/u", trim($text)) as $para) {
        $para = trim($para);
        while (mb_strlen($para) > $room) {
            $cut = mb_strrpos(mb_substr($para, 0, $room), '. ');
            $cut = $cut !== false && $cut > $room / 2 ? $cut + 1 : $room;
            $pieces[] = trim(mb_substr($para, 0, $cut));
            $para = trim(mb_substr($para, $cut));
        }
        if ($para !== '') {
            $pieces[] = $para;
        }
    }
    $chunks = [];
    $current = '';
    foreach ($pieces as $piece) {
        if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $room) {
            $chunks[] = "$head\n$current";
            $current = '';
        }
        $current .= ($current === '' ? '' : "\n\n") . $piece;
    }
    if ($current !== '' || !$chunks) {
        $chunks[] = "$head\n$current";
    }
    return $chunks;
}

/*
 * ---------- building the library ----------
 */

/** A ticket as the library keeps it: [title, client_id, signature, text] - or null when it doesn't belong (open, archived). */
function mitAiTicketSource($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $settings = mitAiHelperSettings();
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_details,
            t.ticket_client_id, t.ticket_updated_at, t.ticket_resolved_at, t.ticket_closed_at, c.client_name
        FROM tickets t LEFT JOIN clients c ON c.client_id = t.ticket_client_id
        WHERE t.ticket_id = $ticket_id AND t.ticket_archived_at IS NULL AND (t.ticket_resolved_at IS NOT NULL OR t.ticket_closed_at IS NOT NULL)"));
    if (!$ticket) {
        return null;
    }
    $types = $settings['internal'] ? "'Public', 'Client', 'Internal'" : "'Public', 'Client'";
    $result = mysqli_query($mysqli, "SELECT ticket_reply_id, ticket_reply, ticket_reply_type, ticket_reply_created_at, ticket_reply_updated_at FROM ticket_replies
        WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply_archived_at IS NULL AND ticket_reply_type IN ($types) ORDER BY ticket_reply_created_at, ticket_reply_id");
    $reference = $ticket['ticket_prefix'] . intval($ticket['ticket_number']);
    $text = '[Asked] ' . mitAiText($ticket['ticket_details']);
    $sign = [$ticket['ticket_updated_at'], $ticket['ticket_client_id'], $ticket['ticket_resolved_at'], $ticket['ticket_closed_at'], $types];
    while ($reply = mysqli_fetch_assoc($result)) {
        $label = ['Public' => 'Reply', 'Internal' => 'Internal', 'Client' => 'From the client'][$reply['ticket_reply_type']] ?? 'Reply';
        $body = mitAiText($reply['ticket_reply']);
        if ($body !== '') {
            $text .= "\n\n[$label] $body";
        }
        $sign[] = $reply['ticket_reply_id'] . ':' . ($reply['ticket_reply_updated_at'] ?: $reply['ticket_reply_created_at']);
    }
    $client = trim((string) $ticket['client_name']);
    return [
        'title' => mb_substr("$reference - " . $ticket['ticket_subject'], 0, 255),
        'client_id' => intval($ticket['ticket_client_id']),
        'signature' => hash('sha256', implode('|', $sign)),
        'head' => "Ticket $reference" . ($client !== '' ? " ($client)" : '') . ', ' . ($ticket['ticket_resolved_at'] ? 'resolved ' . substr($ticket['ticket_resolved_at'], 0, 10) : 'closed')
            . ': ' . $ticket['ticket_subject'],
        'text' => $text,
    ];
}

/** A document as the library keeps it - or null (archived, or documents switched off). */
function mitAiDocumentSource($document_id) {
    global $mysqli;
    if (!mitAiHelperSettings()['documents']) {
        return null;
    }
    $document = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT d.document_id, d.document_name, d.document_description, d.document_content, d.document_client_id,
            d.document_created_at, d.document_updated_at, c.client_name
        FROM documents d LEFT JOIN clients c ON c.client_id = d.document_client_id
        WHERE d.document_id = " . intval($document_id) . " AND d.document_archived_at IS NULL"));
    if (!$document) {
        return null;
    }
    $client = trim((string) $document['client_name']);
    $description = trim((string) $document['document_description']);
    return [
        'title' => mb_substr((string) $document['document_name'], 0, 255),
        'client_id' => intval($document['document_client_id']),
        'signature' => hash('sha256', implode('|', [$document['document_updated_at'] ?: $document['document_created_at'], $document['document_client_id'], md5((string) $document['document_content'])])),
        'head' => 'Document "' . $document['document_name'] . '"' . ($client !== '' ? " ($client)" : ''),
        'text' => ($description !== '' ? mitAiText($description) . "\n\n" : '') . mitAiText($document['document_content']),
    ];
}

/** Put one ticket or document into the library (or take it out). Returns 'indexed' | 'unchanged' | 'removed' | 'skipped'. */
function mitAiIndexSource($kind, $id) {
    global $mysqli;
    $id = intval($id);
    $column = $kind === 'ticket' ? 'ticket_id' : 'document_id';
    $source = $kind === 'ticket' ? mitAiTicketSource($id) : mitAiDocumentSource($id);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT source_id, signature FROM mit_ai_sources WHERE $column = $id"));
    if (!$source) {
        if ($existing) {
            mysqli_query($mysqli, "DELETE FROM mit_ai_sources WHERE source_id = " . intval($existing['source_id']));
            return 'removed';
        }
        return 'skipped';
    }
    if ($existing && $existing['signature'] === $source['signature']) {
        return 'unchanged';
    }

    [$text, $taken] = mitAiScrub($source['text']);
    [$head] = mitAiScrub($source['head']);
    [$title] = mitAiScrub($source['title']);
    $chunks = mitAiChunk($head, $text);
    $client_id = intval($source['client_id']);
    $esc = function ($value) use ($mysqli) {
        return "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    };

    mysqli_begin_transaction($mysqli);
    try {
        if ($existing) {
            $source_id = intval($existing['source_id']);
            mysqli_query($mysqli, "DELETE FROM mit_ai_chunks WHERE source_id = $source_id");
            mysqli_query($mysqli, "UPDATE mit_ai_sources SET client_id = $client_id, title = " . $esc($title) . ", signature = " . $esc($source['signature']) . ",
                scrubbed = " . intval($taken) . ", indexed_at = NOW() WHERE source_id = $source_id");
        } else {
            mysqli_query($mysqli, "INSERT INTO mit_ai_sources SET $column = $id, client_id = $client_id, title = " . $esc($title) . ", signature = " . $esc($source['signature']) . ",
                scrubbed = " . intval($taken));
            $source_id = intval(mysqli_insert_id($mysqli));
        }
        foreach ($chunks as $no => $chunk) {
            mysqli_query($mysqli, "INSERT INTO mit_ai_chunks SET source_id = $source_id, client_id = $client_id, chunk_no = " . intval($no) . ", chunk_text = " . $esc($chunk));
        }
        mysqli_commit($mysqli);
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
    return 'indexed';
}

/** What the index is built with - a change (internal notes, documents) rebuilds it. */
function mitAiIndexVersion() {
    $settings = mitAiHelperSettings();
    return 'v1|' . ($settings['internal'] ? 'i' : '') . ($settings['documents'] ? 'd' : '');
}

/** Start the library again from nothing at the next run (Admin > Settings > AI helper "Rebuild", or a settings change). */
function mitAiIndexReset() {
    global $mysqli;
    if (!mitAiHelperReady()) {
        return;
    }
    mysqli_query($mysqli, "DELETE FROM mit_ai_sources");
    foreach (['ai_helper_since', 'ai_helper_pass_started', 'ai_helper_cursor_ticket', 'ai_helper_cursor_document'] as $name) {
        mitSetSetting($name, '');
    }
}

/**
 * One cron run (cron/mit_ai_index.php): look at what changed since the last finished pass - tickets whose row or replies changed,
 * documents changed - a batch at a time (cursors kept between runs), and put each in or take it out; then embed passages that have
 * none. A pass started at NOW() covers everything changed from the last pass's start; when the cursors run out it becomes `since`.
 * Returns lines for the log.
 */
function mitAiIndexRun($budget_seconds = 50) {
    global $mysqli;
    $log = [];
    if (!mitAiHelperReady() || !mitAiHelperSettings()['enabled']) {
        return $log;
    }
    if (!mitLock('ai_index', 0)) {
        return ['Another index run is still going'];
    }
    try {
        if (mitSetting('ai_helper_index_version', '') !== mitAiIndexVersion()) {
            mitAiIndexReset();
            mitSetSetting('ai_helper_index_version', mitAiIndexVersion());
            $log[] = 'Library started again (new settings)';
        }
        $started = microtime(true);
        $since = mitSetting('ai_helper_since', '') ?: '1970-01-01 00:00:00';
        $pass = mitSetting('ai_helper_pass_started', '');
        if ($pass === '') {
            $pass = mysqli_fetch_row(mysqli_query($mysqli, "SELECT DATE_SUB(NOW(), INTERVAL 1 MINUTE)"))[0];
            mitSetSetting('ai_helper_pass_started', $pass);
            mitSetSetting('ai_helper_cursor_ticket', '0');
            mitSetSetting('ai_helper_cursor_document', '0');
        }
        $since_sql = mysqli_real_escape_string($mysqli, $since);
        $counts = ['indexed' => 0, 'unchanged' => 0, 'removed' => 0, 'skipped' => 0];
        $left = MIT_AI_INDEX_BATCH;
        $done = true;

        foreach (['ticket', 'document'] as $kind) {
            $cursor = intval(mitSetting("ai_helper_cursor_$kind", '0'));
            if ($cursor < 0) {
                continue; // this kind is done for the pass
            }
            if ($kind === 'ticket') {
                $sql = "SELECT ticket_id FROM tickets WHERE ticket_id > $cursor AND (COALESCE(ticket_updated_at, ticket_created_at) >= '$since_sql'
                        OR ticket_id IN (SELECT ticket_reply_ticket_id FROM ticket_replies WHERE COALESCE(ticket_reply_updated_at, ticket_reply_created_at) >= '$since_sql'))
                    ORDER BY ticket_id LIMIT " . intval($left + 1);
            } else {
                $sql = "SELECT document_id FROM documents WHERE document_id > $cursor AND COALESCE(document_updated_at, document_created_at) >= '$since_sql'
                    ORDER BY document_id LIMIT " . intval($left + 1);
            }
            $ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, $sql)), 0));
            $more = count($ids) > $left;
            foreach (array_slice($ids, 0, $left) as $id) {
                $counts[mitAiIndexSource($kind, $id)]++;
                $cursor = $id;
                $left--;
                if (microtime(true) - $started > $budget_seconds) {
                    $more = true;
                    break;
                }
            }
            if ($more) {
                mitSetSetting("ai_helper_cursor_$kind", $cursor);
                $done = false;
                break;
            }
            mitSetSetting("ai_helper_cursor_$kind", '-1');
        }
        // Documents switched off: their sources go
        if (!mitAiHelperSettings()['documents']) {
            mysqli_query($mysqli, "DELETE FROM mit_ai_sources WHERE document_id IS NOT NULL");
        }
        if ($done) {
            mitSetSetting('ai_helper_since', $pass);
            mitSetSetting('ai_helper_pass_started', '');
        }
        if ($counts['indexed'] || $counts['removed']) {
            $log[] = "Library: {$counts['indexed']} added or updated, {$counts['removed']} taken out" . ($done ? '' : ' (more next run)');
        }
        $embedded = mitAiEmbedPending(max(5, $budget_seconds - (microtime(true) - $started)));
        if ($embedded !== '') {
            $log[] = $embedded;
        }
        mitSetSetting('ai_helper_last_run', date('Y-m-d H:i:s'));
    } finally {
        mitUnlock('ai_index');
    }
    return $log;
}

/** Numbers for the admin page. */
function mitAiIndexStats() {
    global $mysqli;
    if (!mitAiHelperReady()) {
        return [];
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
        (SELECT COUNT(*) FROM mit_ai_sources WHERE ticket_id IS NOT NULL) AS tickets,
        (SELECT COUNT(*) FROM mit_ai_sources WHERE document_id IS NOT NULL) AS documents,
        (SELECT COUNT(*) FROM mit_ai_chunks) AS chunks,
        (SELECT COUNT(*) FROM mit_ai_chunks WHERE embedding IS NOT NULL) AS embedded,
        (SELECT COUNT(*) FROM mit_ai_sources WHERE scrubbed > 0) AS scrubbed_sources"));
    return array_map('intval', $row) + [
        'last_run' => (string) mitSetting('ai_helper_last_run', ''),
        'catching_up' => mitSetting('ai_helper_pass_started', '') !== '' && mitSetting('ai_helper_since', '') === '',
    ];
}

/** Sources that had something taken out - newest first, only clients the agent can open. */
function mitAiScrubbedSources($limit = 50) {
    global $mysqli;
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT s.source_id, s.ticket_id, s.document_id, s.client_id, s.title, s.scrubbed, s.indexed_at, c.client_name FROM mit_ai_sources s LEFT JOIN clients c ON c.client_id = s.client_id
        WHERE s.scrubbed > 0" . clientScopeSql('s.client_id') . " ORDER BY s.indexed_at DESC LIMIT " . intval($limit));
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row + ['url' => mitAiSourceUrl($row)];
    }
    return $rows;
}

/** Where a source opens. */
function mitAiSourceUrl(array $source) {
    if (!empty($source['ticket_id'])) {
        return '/agent/' . ticketUrl($source['ticket_id'], $source['client_id'] ?? 0);
    }
    return '/agent/document.php?' . (intval($source['client_id'] ?? 0) ? 'client_id=' . intval($source['client_id']) . '&' : '') . 'document_id=' . intval($source['document_id']);
}

/*
 * ---------- embeddings (meaning-based search) ----------
 */

/** The provider and model set for embeddings, or null. Uses ITFlow's AI provider (its address and key) with our model name. */
function mitAiEmbedConfig() {
    $settings = mitAiHelperSettings();
    if (!$settings['embed_provider'] || $settings['embed_model'] === '' || !function_exists('aiProviderRow')) {
        return null;
    }
    $provider = aiProviderRow($settings['embed_provider']);
    if (!$provider) {
        return null;
    }
    $kind = (string) ($provider['ai_provider_kind'] ?? 'custom');
    $chat = function_exists('aiProviderUrl') ? aiProviderUrl($kind, $provider['ai_provider_api_url']) : (string) $provider['ai_provider_api_url'];
    $url = preg_replace('#/chat/completions(\?|$)#', '/embeddings$1', $chat);
    if ($url === $chat) {
        return null; // not an OpenAI-style address - no embeddings there
    }
    return ['provider' => $provider, 'kind' => $kind, 'url' => $url, 'model' => $settings['embed_model']];
}

/** Vectors for some texts - [ok, vectors (unit length) | error]. Recorded in ITFlow's AI Usage as "AI helper - search library". */
function mitAiEmbed(array $texts, $config = null) {
    $config = $config ?: mitAiEmbedConfig();
    if (!$config) {
        return [false, 'No embeddings model is set'];
    }
    $started = microtime(true);
    $ch = curl_init($config['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['model' => $config['model'], 'input' => array_values($texts)]),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => aiProviderHeaders($config['kind'], $config['provider']['ai_provider_api_key']),
    ]);
    $response = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    curl_close($ch);
    $json = is_string($response) ? json_decode($response, true) : null;
    $usage = ['feature' => 'mit_ai_embed', 'ms' => intval(round((microtime(true) - $started) * 1000)),
        'model' => ['ai_provider_id' => $config['provider']['ai_provider_id'], 'ai_provider_name' => $config['provider']['ai_provider_name'], 'ai_model_name' => $config['model']]];
    if ($status < 200 || $status > 299 || !isset($json['data']) || !is_array($json['data'])) {
        $error = $response === false ? 'Could not reach the provider' : "HTTP $status" . (is_array($json['error'] ?? null) && is_scalar($json['error']['message'] ?? null) ? ' - ' . $json['error']['message'] : '');
        if (function_exists('aiUsageRecord')) {
            aiUsageRecord($usage + ['ok' => false, 'error' => $error]);
        }
        return [false, $error];
    }
    if (function_exists('aiUsageRecord')) {
        aiUsageRecord($usage + ['ok' => true, 'tokens_in' => intval($json['usage']['prompt_tokens'] ?? $json['usage']['total_tokens'] ?? 0)]);
    }
    $vectors = [];
    foreach ($json['data'] as $i => $item) {
        $vector = array_map('floatval', (array) ($item['embedding'] ?? []));
        $length = sqrt(array_sum(array_map(function ($v) { return $v * $v; }, $vector))) ?: 1.0;
        $vectors[intval($item['index'] ?? $i)] = array_map(function ($v) use ($length) { return $v / $length; }, $vector);
    }
    ksort($vectors);
    return [true, array_values($vectors)];
}

/** Embed passages without a vector for the current model, for up to $seconds. Returns a log line or ''. */
function mitAiEmbedPending($seconds = 30) {
    global $mysqli;
    $config = mitAiEmbedConfig();
    if (!$config) {
        return '';
    }
    $model = mysqli_real_escape_string($mysqli, $config['model']);
    $started = microtime(true);
    $done = 0;
    while (microtime(true) - $started < $seconds) {
        $rows = mysqli_fetch_all(mysqli_query($mysqli, "SELECT chunk_id, chunk_text FROM mit_ai_chunks WHERE embedding IS NULL OR embed_model <> '$model'
            ORDER BY chunk_id LIMIT " . MIT_AI_EMBED_BATCH), MYSQLI_ASSOC);
        if (!$rows) {
            break;
        }
        [$ok, $vectors] = mitAiEmbed(array_column($rows, 'chunk_text'), $config);
        if (!$ok || count($vectors) !== count($rows)) {
            return 'Embeddings failed: ' . ($ok ? 'the provider answered for ' . count($vectors) . ' of ' . count($rows) : $vectors);
        }
        foreach ($rows as $i => $row) {
            $blob = mysqli_real_escape_string($mysqli, pack('g*', ...$vectors[$i]));
            mysqli_query($mysqli, "UPDATE mit_ai_chunks SET embedding = '$blob', embed_model = '$model' WHERE chunk_id = " . intval($row['chunk_id']));
        }
        $done += count($rows);
    }
    return $done ? "Embedded $done passage(s)" : '';
}

/*
 * ---------- searching ----------
 */

/** The SQL that keeps a search to what the signed-in agent may see: their clients, and documents only when included. */
function mitAiScopeSql($alias = 'k') {
    return clientScopeSql("$alias.client_id");
}

/**
 * The passages that best answer $query for the signed-in agent - each ['chunk_id', 'source_id', 'kind' ticket|document, 'title',
 * 'client_id', 'client_name', 'url', 'text', 'score', 'how' keyword|meaning|both]. $opts: limit (8), client_id (only that client's
 * and those with no client), per_source (2). Empty when the agent may not use the helper. Keyword search always; meaning-based
 * search too when an embeddings model is set; merged by reciprocal rank.
 */
function mitAiSearch($query, array $opts = []) {
    global $mysqli;
    $query = trim((string) $query);
    if ($query === '' || !mitAiHelperCan()) {
        return [];
    }
    [$query] = mitAiScrub($query); // a password typed into the question is not sent anywhere either
    $limit = max(1, min(30, intval($opts['limit'] ?? 8)));
    $per_source = max(1, intval($opts['per_source'] ?? 2));
    $scope = mitAiScopeSql('k') . (!empty($opts['client_id']) ? ' AND k.client_id IN (0, ' . intval($opts['client_id']) . ')' : '');

    $ranks = [];
    $how = [];
    // Keyword: the FULLTEXT index (natural language, so common words weigh little)
    $words = mysqli_real_escape_string($mysqli, mb_substr($query, 0, 500));
    $result = mysqli_query($mysqli, "SELECT k.chunk_id, MATCH(k.chunk_text) AGAINST('$words') AS relevance FROM mit_ai_chunks k
        WHERE MATCH(k.chunk_text) AGAINST('$words')$scope ORDER BY relevance DESC LIMIT " . MIT_AI_SEARCH_POOL);
    $rank = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $ranks[intval($row['chunk_id'])] = 1 / (60 + ++$rank);
        $how[intval($row['chunk_id'])] = 'keyword';
    }
    // Meaning: cosine against every passage the agent may see, embedded with the current model
    $config = mitAiEmbedConfig();
    if ($config) {
        [$ok, $vectors] = mitAiEmbed([$query], $config);
        if ($ok && !empty($vectors[0])) {
            $q = $vectors[0];
            $model = mysqli_real_escape_string($mysqli, $config['model']);
            $scores = [];
            $result = mysqli_query($mysqli, "SELECT k.chunk_id, k.embedding FROM mit_ai_chunks k WHERE k.embed_model = '$model'$scope");
            while ($row = mysqli_fetch_row($result)) {
                $v = unpack('g*', $row[1]);
                if (count($v) !== count($q)) {
                    continue;
                }
                $dot = 0.0;
                $i = 0;
                foreach ($v as $x) {
                    $dot += $x * $q[$i++];
                }
                $scores[intval($row[0])] = $dot;
            }
            arsort($scores);
            $rank = 0;
            foreach (array_slice($scores, 0, MIT_AI_SEARCH_POOL, true) as $chunk_id => $score) {
                $ranks[$chunk_id] = ($ranks[$chunk_id] ?? 0) + 1 / (60 + ++$rank);
                $how[$chunk_id] = isset($how[$chunk_id]) ? 'both' : 'meaning';
            }
        }
    }
    if (!$ranks) {
        return [];
    }
    arsort($ranks);
    $ids = implode(',', array_map('intval', array_slice(array_keys($ranks), 0, MIT_AI_SEARCH_POOL)));
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT k.chunk_id, k.chunk_text, k.client_id, s.source_id, s.ticket_id, s.document_id, s.title, c.client_name
        FROM mit_ai_chunks k JOIN mit_ai_sources s ON s.source_id = k.source_id LEFT JOIN clients c ON c.client_id = k.client_id
        WHERE k.chunk_id IN ($ids)$scope");
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[intval($row['chunk_id'])] = $row;
    }
    $out = [];
    $taken = [];
    foreach ($ranks as $chunk_id => $score) {
        $row = $rows[$chunk_id] ?? null;
        if (!$row || ($taken[$row['source_id']] ?? 0) >= $per_source) {
            continue;
        }
        $taken[$row['source_id']] = ($taken[$row['source_id']] ?? 0) + 1;
        $out[] = [
            'chunk_id' => $chunk_id,
            'source_id' => intval($row['source_id']),
            'kind' => $row['ticket_id'] ? 'ticket' : 'document',
            'title' => (string) $row['title'],
            'client_id' => intval($row['client_id']),
            'client_name' => (string) $row['client_name'],
            'url' => mitAiSourceUrl($row),
            'text' => (string) $row['chunk_text'],
            'score' => round($score, 5),
            'how' => $how[$chunk_id] ?? 'keyword',
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
