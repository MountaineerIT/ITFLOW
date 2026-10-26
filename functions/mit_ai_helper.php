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
    . '|recovery[ _-]?codes?|backup[ _-]?codes?|wi-?fi[ _-]?keys?|psk|pre-?shared[ _-]?keys?|license[ _-]?keys?|product[ _-]?keys?|serial[ _-]?keys?';

// Words that follow a secret word in plain talk about one ("the password didn't work", "password manager") - anything else right
// after it is read as the value being given ("the NAS password purplemonkey")
const MIT_AI_PLAIN_FOLLOWERS = ['reset', 'resets', 'resetting', 'expired', 'expires', 'expiry', 'expiration', 'policy', 'policies', 'manager', 'managers',
    'prompt', 'prompts', 'prompted', 'prompting', 'change', 'changes', 'changed', 'field', 'fields', 'box', 'screen', 'page', 'window', 'dialog', 'popup',
    'pop-up', 'request', 'requests', 'requested', 'issue', 'issues', 'problem', 'problems', 'error', 'errors', 'again', 'anymore', 'still', 'too', 'also',
    'now', 'yet', 'and', 'or', 'but', 'for', 'of', 'on', 'in', 'at', 'to', 'from', 'with', 'without', 'after', 'before', 'when', 'if', 'until', 'since',
    'then', 'that', 'which', 'who', 'so', 'because', 'did', 'didn\'t', 'does', 'doesn\'t', 'do', 'don\'t', 'won\'t', 'wasn\'t', 'isn\'t', 'aren\'t',
    'can\'t', 'cannot', 'couldn\'t', 'wouldn\'t', 'not', 'never', 'is', 'was', 'were', 'are', 'be', 'been', 'being', 'has', 'had', 'have', 'need', 'needs',
    'needed', 'required', 'work', 'works', 'worked', 'working', 'locked', 'lockout', 'lockouts', 'history', 'complexity', 'requirement', 'requirements',
    'protected', 'protection', 'sync', 'syncing', 'synced', 'writeback', 'spray', 'spraying', 'hint', 'hints', 'length', 'age', 'reuse', 'rotation',
    'vault', 'management', 'recovery', 'self-service', 'sspr', 'it', 'them', 'this', 'these', 'those', 'the', 'a', 'an', 'their', 'his', 'her', 'your',
    'our', 'my', 'will', 'would', 'should', 'could', 'can', 'may', 'might', 'must', 'by', 'via', 'as', 'used', 'using', 'entry', 'entries', 'login',
    'logins', 'sign-in', 'update', 'updated', 'updates', 'missing', 'wrong', 'incorrect', 'correct', 'blank', 'empty', 'stored', 'saved', 'cached',
    'removed', 'cleared', 'all', 'only', 'set', 'setting', 'settings', 'option', 'options', 'feature', 'type', 'types', 'number', 'numbers', 'code',
    'codes', 'there', 'here', 'please', 'once', 'every', 'each', 'whenever'];

// Values that are no value: "the password is wrong", "PIN was reset"
const MIT_AI_PLAIN_VALUES = ['wrong', 'incorrect', 'correct', 'right', 'expired', 'expiring', 'invalid', 'valid', 'required', 'needed', 'missing', 'blank',
    'empty', 'not', 'still', 'set', 'reset', 'changed', 'locked', 'too', 'being', 'now', 'fine', 'working', 'ok', 'okay', 'case-sensitive', 'the', 'a',
    'an', 'their', 'his', 'her', 'your', 'our', 'my', 'in', 'on', 'stored', 'saved', 'kept', 'unknown', 'forgotten', 'lost', 'different', 'same', 'weak',
    'strong', 'long', 'short', 'complex', 'simple', 'temporary', 'updated', 'accepted', 'rejected', 'denied', 'disabled', 'enabled', 'no', 'none',
    'also', 'probably', 'likely', 'usually', 'always', 'never', 'just', 'only', 'what', 'unchanged', 'sent', 'emailed', 'texted', 'given', 'provided'];

require_once __DIR__ . '/mit_ai_ask.php'; // round 2: the Ask AI panel
require_once __DIR__ . '/mit_ai_docs.php'; // round 3: documents from a conversation

const MIT_AI_CHUNK_CHARS = 1500;      // about 350 words a passage
const MIT_AI_INDEX_BATCH = 300;       // sources looked at per cron run
const MIT_AI_EMBED_BATCH = 64;        // passages per embeddings request
const MIT_AI_SEARCH_POOL = 40;        // candidates from each search before merging
const MIT_AI_DOCUMENT_SLOTS = 3;      // places in an answer's passages kept for documents that match (tickets outnumber them)
const MIT_AI_DOCUMENT_WEIGHT = 1.5;   // documents are written to be followed - they rank ahead of a ticket that matches as well
const MIT_AI_DOCUMENT_NEAR = 0.85;    // a document found only by meaning gets a kept place when this close to the best match

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
function mitAiScrub($text, ?array &$log = null) {
    $text = (string) $text;
    $taken = 0;
    $note = function ($rule, $what) use (&$log) {
        if (is_array($log)) {
            $log[] = ['rule' => $rule, 'text' => (string) $what];
        }
    };

    if (function_exists('aiRedact')) {
        [$text, $secrets] = aiRedact($text);
        $taken += count($secrets);
        foreach ($secrets as $value) {
            $note('labelled', $value);
        }
        $text = preg_replace('/\[secret-\d+\]/', '[removed]', $text);
    }

    $lines = preg_split("/\n/", $text);
    foreach ($lines as $i => $line) {
        $sentences = preg_split('/(?<=[.!?])\s+(?=\S)/u', $line);
        foreach ($sentences as $j => $sentence) {
            if (mitAiSecretSentence($sentence)) {
                $note('sentence', $sentence);
                $sentences[$j] = '[removed - it gave a password or key]';
                $taken++;
            }
        }
        $lines[$i] = implode(' ', $sentences);
    }
    $text = implode("\n", $lines);

    $text = preg_replace_callback('/(?<=^|[\s(\[{"\'])([^\s()\[\]{}"\'<>]{8,128})(?=$|[\s)\]}"\'.,;:])/u', function ($m) use (&$taken, $note) {
        $token = rtrim($m[1], '.,;:');
        if (preg_match('/^\[removed/', $token) || mitAiTechWord($token)) {
            return $m[0];
        }
        $upper = preg_match('/\p{Lu}/u', $token);
        $lower = preg_match('/\p{Ll}/u', $token);
        $digit = preg_match('/\d/', $token);
        $symbol = preg_match('/[!@#$%^&*+=?~|`]/', $token);
        $kinds = $upper + $lower + $digit + $symbol;
        if (($symbol && $kinds >= 3) || (mb_strlen($token) >= 10 && $upper && $lower && $digit)) {
            $taken++;
            $note('word', $token);
            return '[removed]' . substr($m[1], strlen($token));
        }
        return $m[0];
    }, $text);

    return [trim($text), $taken];
}

/**
 * Is this word something techs write all the time that only looks like a password - an address, a path, an e-mail, a file or host
 * name, a version, an error or KB number, a model name, a size? (A real password has none of these shapes, or is caught by the
 * sentence rule around it.)
 */
/* $strict: true = only addresses, e-mail, paths and files count (the word right after a password word); 'near' = all but hyphenated names. */
function mitAiTechWord($token, $strict = false) {
    $token = trim((string) $token, '.,;:!?()[]{}"\'');
    if ($token === '' || preg_match('#://|/|\\\\|^www\.#i', $token)                     // address, path
        || preg_match('/^[^@\s]+@[\w-]+(?:\.[\w-]+)+$/u', $token)                           // e-mail
        || preg_match('/^[\w-]+\.(?:exe|msi|iso|zip|pdf|docx?|xlsx?|csv|txt|log|ps1|bat|cmd|cfg|conf|ini|json|xml|pst|ost)$/i', $token)) { // a file
        return true;
    }
    if ($strict === true) {
        return false; // right after a password word, anything else may be the value
    }
    if ($strict !== 'near' && preg_match('/^[\p{L}\d]+(?:-[\p{L}\d]+)+$/u', $token)) {
        return true; // hyphenated names: ThinkPad-T14s, ACME-DC01 - but near a password word they may be one (Xq7-ruby-falcon)
    }
    return preg_match('/[._]/', $token)                                                    // file, host or domain name, version
        || preg_match('/^(?:0x)?[0-9A-F]{4,}$/i', $token)                                  // hex codes, error numbers
        || preg_match('/^[A-Z]{1,8}\d+[A-Za-z]{0,3}$/', $token)                            // KB5034441, AADSTS50126, M365, W10, E5
        || preg_match('/^\d+(?:[A-Za-z]{1,4}\d*)+$/', $token)                              // 16GB, 23H2, 5GHz
        || preg_match('/^(?:Windows|Win|Server|Office|iPhone|iPad|iOS|macOS|Android|Galaxy|Surface|ThinkPad|ThinkCentre|OptiPlex|Latitude|PowerEdge|'
            . 'ProLiant|Precision|Inspiron|Vostro|EliteBook|ProBook|EliteDesk|ProDesk|MacBook|iMac|LaserJet|OfficeJet|PageWide|Exchange|SharePoint|'
            . 'OneDrive|QuickBooks|Sage|Ubuntu|Debian|CentOS|RHEL|SQL|Veeam|Hyper|vSphere|ESXi|FortiGate|SonicWall|Meraki|UniFi)\w*$/i', $token);
}

/**
 * Does this sentence give a password, key or the like? Talk about one stays ("the user needs a password for the copier", "Bob forgot
 * his password, reset it in ADUC", "the password policy needs 14 characters"); it goes when, next to a password-type word (MIT_AI_SECRET_WORDS):
 *   - a value is given: "password is X", "PIN: 1234", "pwd=X", "set the password to X", "X is the new password" (X not a plain word
 *     like "wrong" or "expired" - MIT_AI_PLAIN_VALUES);
 *   - the word right after it is not plain talk (MIT_AI_PLAIN_FOLLOWERS): "the NAS password purplemonkey";
 *   - a password-like word comes within six words after it: digits with letters or a symbol, or quoted text ("reset it, new one
 *     Welcome1") - not addresses, versions, KB / error numbers or model names (mitAiTechWord).
 */
function mitAiSecretSentence($sentence) {
    $sentence = (string) $sentence;
    $word = '\b(?:' . MIT_AI_SECRET_WORDS . ')\b';
    if (!preg_match("/$word/iu", $sentence)) {
        return false;
    }
    $plain_value = function ($value) {
        $value = mb_strtolower(trim((string) $value, '.,;:!?()[]{}"\'"'));
        return $value === '' || in_array($value, MIT_AI_PLAIN_VALUES, true);
    };
    $value_like = function ($token) {
        $token = trim((string) $token, '.,;:!?()[]{}');
        if (preg_match('/^["\'\x{201C}].{3,}["\'\x{201D}]$/u', $token)) {
            return true;
        }
        if (mb_strlen($token) < 4 || mitAiTechWord($token, 'near')) {
            return false;
        }
        return (preg_match('/\d/', $token) && preg_match('/\p{L}/u', $token)) || preg_match('/[!@#$%^&*+=?~|`]/', $token);
    };

    // "password: X", "pwd=X", "password for the NAS is X", "PIN was 1234"
    if (preg_match_all("/$word(?:\s+(?:for|of|on)\s+(?:the\s+|their\s+|his\s+|her\s+|our\s+)?[\w.@-]+(?:\s+[\w.@-]+)?)?\s*(?:(:|=|->|=>)\s*|\s+(?:is|was|=)\s+)(\S+)/iu", $sentence, $found, PREG_SET_ORDER)) {
        foreach ($found as $m) {
            if (strpos($m[2], '[removed') === 0) {
                continue; // already taken out by aiRedact
            }
            if ($m[1] !== '' || !$plain_value($m[2])) {
                return true;
            }
        }
    }
    // "set the password to X", "changed her PIN to 1234"
    if (preg_match_all("/\b(?:set|sets|setting|chang(?:e|ed|es|ing)|reset(?:s|ting)?|updat(?:e|ed|ing))\s+(?:the\s+|their\s+|his\s+|her\s+|our\s+|a\s+|an\s+)?(?:new\s+|temp(?:orary)?\s+)?(?:\w+\s+)?$word\s+(?:to|as)\s+(\S+)/iu", $sentence, $found, PREG_SET_ORDER)) {
        foreach ($found as $m) {
            if (!$plain_value($m[1])) {
                return true;
            }
        }
    }
    // "X is the new password", "Summer2024! was their PIN"
    if (preg_match_all("/(\S+)\s+(?:is|was)\s+(?:the|their|his|her|our|my|your|a)\s+(?:new\s+|temp(?:orary)?\s+|current\s+|old\s+)?(?:\w+\s+)?$word/iu", $sentence, $found, PREG_SET_ORDER)) {
        foreach ($found as $m) {
            if ($value_like($m[1])) {
                return true;
            }
        }
    }
    // The word right after it, and the next six words
    preg_match_all("/$word/iu", $sentence, $hits, PREG_OFFSET_CAPTURE);
    foreach ($hits[0] as $hit) {
        $after = mb_substr(substr($sentence, $hit[1] + strlen($hit[0])), 0, 200);
        if (preg_match('/^\s+([^\s,;.!?()]+)/u', $after, $next) && !in_array(mb_strtolower($next[1]), MIT_AI_PLAIN_FOLLOWERS, true)
            && !preg_match("/^$word$/iu", $next[1]) && !mitAiTechWord($next[1], true) && !preg_match('/^\d{1,2}$/', $next[1])) {
            return true;
        }
        foreach (array_slice(preg_split('/\s+/u', trim($after)), 0, 6) as $token) {
            if ($value_like($token)) {
                return true;
            }
        }
    }
    return (bool) preg_match('/' . $word . '[^"\'\x{201C}]{0,40}["\'\x{201C}][^"\'\x{201D}]{3,}["\'\x{201D}]/iu', $sentence);
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

/**
 * A ticket as the library keeps it: [title, client_id, signature, head, text] - or null when it doesn't belong (open, archived).
 * $open_too: also an open ticket (the one an agent asks about in the Ask AI panel - functions/mit_ai_ask.php), with its internal notes.
 */
function mitAiTicketSource($ticket_id, $open_too = false) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $settings = mitAiHelperSettings();
    if ($open_too) {
        $settings['internal'] = true;
    }
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_details,
            t.ticket_client_id, t.ticket_updated_at, t.ticket_resolved_at, t.ticket_closed_at, c.client_name
        FROM tickets t LEFT JOIN clients c ON c.client_id = t.ticket_client_id
        WHERE t.ticket_id = $ticket_id AND t.ticket_archived_at IS NULL" . ($open_too ? '' : " AND (t.ticket_resolved_at IS NOT NULL OR t.ticket_closed_at IS NOT NULL)")));
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
        'head' => "Ticket $reference" . ($client !== '' ? " ($client)" : '') . ', ' . ($ticket['ticket_resolved_at'] ? 'resolved ' . substr($ticket['ticket_resolved_at'], 0, 10) : ($ticket['ticket_closed_at'] ? 'closed' : 'open'))
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
    $source['signature'] = hash('sha256', $source['signature'] . '|' . mitAiIndexVersion()); // a new filter or switch reads it again
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
        $keep = []; // embeddings of passages whose words are the same - read again for nothing, not paid for twice
        if ($existing) {
            $source_id = intval($existing['source_id']);
            $result = mysqli_query($mysqli, "SELECT chunk_text, embedding, embed_model FROM mit_ai_chunks WHERE source_id = $source_id AND embedding IS NOT NULL");
            while ($row = mysqli_fetch_assoc($result)) {
                $keep[hash('sha256', $row['chunk_text'])] = $row;
            }
            mysqli_query($mysqli, "DELETE FROM mit_ai_chunks WHERE source_id = $source_id");
            mysqli_query($mysqli, "UPDATE mit_ai_sources SET client_id = $client_id, title = " . $esc($title) . ", signature = " . $esc($source['signature']) . ",
                scrubbed = " . intval($taken) . ", indexed_at = NOW() WHERE source_id = $source_id");
        } else {
            mysqli_query($mysqli, "INSERT INTO mit_ai_sources SET $column = $id, client_id = $client_id, title = " . $esc($title) . ", signature = " . $esc($source['signature']) . ",
                scrubbed = " . intval($taken));
            $source_id = intval(mysqli_insert_id($mysqli));
        }
        foreach ($chunks as $no => $chunk) {
            $old = $keep[hash('sha256', $chunk)] ?? null;
            mysqli_query($mysqli, "INSERT INTO mit_ai_chunks SET source_id = $source_id, client_id = $client_id, chunk_no = " . intval($no) . ", chunk_text = " . $esc($chunk)
                . ($old ? ", embedding = " . $esc($old['embedding']) . ", embed_model = " . $esc($old['embed_model']) : ''));
        }
        mysqli_commit($mysqli);
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
    return 'indexed';
}

/**
 * What the library is built with - the filter's version and the switches. A change reads every source again on the next runs
 * (passages that come out the same keep their embeddings). v2 (26.10.154): the sentence rule only takes sentences that give a value.
 */
function mitAiIndexVersion() {
    $settings = mitAiHelperSettings();
    return 'v2|' . ($settings['internal'] ? 'i' : '') . ($settings['documents'] ? 'd' : '');
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
            // Read everything again (each source's signature carries the version); passages that come out the same keep their embeddings
            foreach (['ai_helper_since', 'ai_helper_pass_started', 'ai_helper_cursor_ticket', 'ai_helper_cursor_document'] as $name) {
                mitSetSetting($name, '');
            }
            mitSetSetting('ai_helper_index_version', mitAiIndexVersion());
            $log[] = 'Library being read again (new settings or filter)';
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
        // Archived since they were read (ITFlow archives without touching the updated time, so the pass above doesn't see it)
        mysqli_query($mysqli, "DELETE s FROM mit_ai_sources s JOIN documents d ON d.document_id = s.document_id WHERE d.document_archived_at IS NOT NULL");
        mysqli_query($mysqli, "DELETE s FROM mit_ai_sources s JOIN tickets t ON t.ticket_id = s.ticket_id WHERE t.ticket_archived_at IS NOT NULL");
        if ($done) {
            mitSetSetting('ai_helper_since', $pass);
            mitSetSetting('ai_helper_pass_started', '');
        }
        if ($counts['indexed'] || $counts['removed']) {
            $log[] = "Library: {$counts['indexed']} added or updated, {$counts['removed']} taken out" . ($done ? '' : ' (more next run)');
        }
        // Keyword search still finding what is there? (InnoDB's word index can come back short after an unclean stop - rebuilt then)
        if (mitSetting('ai_helper_keyword_checked', '') < date('Y-m-d H:i:s', time() - 3600)) {
            mitSetSetting('ai_helper_keyword_checked', date('Y-m-d H:i:s'));
            if (!mitAiKeywordIndexOk()) {
                mysqli_query($mysqli, "ALTER TABLE mit_ai_chunks FORCE");
                $log[] = 'Keyword index was missing passages - rebuilt' . (mitAiKeywordIndexOk() ? '' : ', still missing some');
            }
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

/** Does the keyword index find a few passages by a word of their own? (Up to 5 picked at random; true when the library is empty.) */
function mitAiKeywordIndexOk() {
    global $mysqli;
    $max = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT MAX(chunk_id) FROM mit_ai_chunks"))[0] ?? 0);
    for ($i = 0, $tried = 0; $max && $i < 10 && $tried < 5; $i++) {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT chunk_id, chunk_text FROM mit_ai_chunks WHERE chunk_id >= " . random_int(1, $max) . " ORDER BY chunk_id LIMIT 1"));
        if (!$row || !preg_match_all('/\b[a-z]{6,20}\b/i', $row[1], $words)) {
            continue;
        }
        usort($words[0], fn($a, $b) => strlen($b) <=> strlen($a)); // the longest: least likely a stopword
        $word = mysqli_real_escape_string($mysqli, $words[0][0]);
        $tried++;
        if (!mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM mit_ai_chunks WHERE chunk_id = " . intval($row[0]) . " AND MATCH(chunk_text) AGAINST('+$word' IN BOOLEAN MODE)"))) {
            return false;
        }
    }
    return true;
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
        (SELECT COUNT(*) FROM mit_ai_sources WHERE scrubbed > 0) AS scrubbed_sources,
        (SELECT COUNT(*) FROM documents WHERE document_archived_at IS NULL) AS documents_in_itflow"));
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

/**
 * What the filter takes out of one source, read again now - for the admin page, so an admin (who can open the ticket anyway) can see
 * whether it was a real password. [title, [['rule', 'text'], ...]] or null. Never sent anywhere.
 */
function mitAiExplainSource($source_id) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT source_id, ticket_id, document_id, client_id, title FROM mit_ai_sources WHERE source_id = " . intval($source_id)
        . clientScopeSql('client_id')));
    if (!$row) {
        return null;
    }
    $source = $row['ticket_id'] ? mitAiTicketSource($row['ticket_id']) : mitAiDocumentSource($row['document_id']);
    if (!$source) {
        return null;
    }
    $log = [];
    foreach (['title', 'head', 'text'] as $part) {
        mitAiScrub($source[$part], $log);
    }
    return [$row['title'], $log, mitAiSourceUrl($row)];
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
    // ... and the documents' own best, which thousands of ticket passages would otherwise push out of the pool
    $documents = []; // chunk_id => true: a document passage that matched
    $result = mysqli_query($mysqli, "SELECT k.chunk_id, MATCH(k.chunk_text) AGAINST('$words') AS relevance FROM mit_ai_chunks k JOIN mit_ai_sources s ON s.source_id = k.source_id
        WHERE s.document_id IS NOT NULL AND MATCH(k.chunk_text) AGAINST('$words')$scope ORDER BY relevance DESC LIMIT " . MIT_AI_SEARCH_POOL);
    $rank = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $documents[intval($row['chunk_id'])] = true;
        $ranks[intval($row['chunk_id'])] = max($ranks[intval($row['chunk_id'])] ?? 0, 1 / (60 + ++$rank));
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
            $is_document = [];
            $result = mysqli_query($mysqli, "SELECT k.chunk_id, k.embedding, s.document_id IS NOT NULL FROM mit_ai_chunks k JOIN mit_ai_sources s ON s.source_id = k.source_id
                WHERE k.embed_model = '$model'$scope");
            while ($row = mysqli_fetch_row($result)) {
                if ($row[2]) {
                    $is_document[intval($row[0])] = true;
                }
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
            // Documents close in meaning to the best match, wherever they ranked among the tickets
            $best = $scores ? reset($scores) : 0;
            $rank = 0;
            foreach ($scores as $chunk_id => $score) {
                if (empty($is_document[$chunk_id])) {
                    continue;
                }
                if ($best <= 0 || $score < $best * MIT_AI_DOCUMENT_NEAR || ++$rank > MIT_AI_SEARCH_POOL) {
                    break;
                }
                $documents[$chunk_id] = true;
                if (!isset($how[$chunk_id]) || $how[$chunk_id] === 'keyword') {
                    $ranks[$chunk_id] = ($ranks[$chunk_id] ?? 0) + 1 / (60 + $rank);
                    $how[$chunk_id] = isset($how[$chunk_id]) ? 'both' : 'meaning';
                }
            }
        }
    }
    if (!$ranks) {
        return [];
    }
    foreach ($documents as $chunk_id => $yes) {
        $ranks[$chunk_id] *= MIT_AI_DOCUMENT_WEIGHT;
    }
    arsort($ranks);
    // The pool: the best overall, plus the matching documents' best, so they can take their kept places below
    $pool = array_slice(array_keys($ranks), 0, MIT_AI_SEARCH_POOL);
    $pool = array_unique(array_merge($pool, array_slice(array_keys(array_intersect_key($ranks, $documents)), 0, MIT_AI_SEARCH_POOL)));
    $ids = implode(',', array_map('intval', $pool));
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT k.chunk_id, k.chunk_text, k.client_id, s.source_id, s.ticket_id, s.document_id, s.title, c.client_name
        FROM mit_ai_chunks k JOIN mit_ai_sources s ON s.source_id = k.source_id LEFT JOIN clients c ON c.client_id = k.client_id
        WHERE k.chunk_id IN ($ids)$scope");
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[intval($row['chunk_id'])] = $row;
    }
    // Kept places first: up to MIT_AI_DOCUMENT_SLOTS matching document passages, then the rest by rank (and back in rank order)
    $order = [];
    $kept = 0;
    $seen = [];
    foreach ($ranks as $chunk_id => $score) {
        $row = $rows[$chunk_id] ?? null;
        if ($row && !empty($documents[$chunk_id]) && $kept < min(MIT_AI_DOCUMENT_SLOTS, $limit) && ($seen[$row['source_id']] ?? 0) < $per_source) {
            $seen[$row['source_id']] = ($seen[$row['source_id']] ?? 0) + 1;
            $order[$chunk_id] = $score;
            $kept++;
        }
    }
    foreach ($ranks as $chunk_id => $score) {
        $order += [$chunk_id => $score];
    }
    $out = [];
    $taken = [];
    foreach ($order as $chunk_id => $score) {
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
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return $out;
}
