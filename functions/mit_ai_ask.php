<?php

/*
 * Mountaineer IT - AI helper, round 2: the Ask AI panel (required from functions/mit_ai_helper.php).
 *
 * An agent asks a question from the top bar (includes/mit_timers_nav.php -> mitAiPanelNav, agent/js/mit_ai_helper.js, requests to
 * agent/mit_ai_ajax.php) - on a ticket page "about this ticket" too. mitAiAsk: the question cleaned (mitAiScrub), the library searched
 * for the agent (mitAiSearch - their clients only), the current ticket read the same way (mitAiTicketSource, open too), the last few
 * turns of the conversation, all sent through ITFlow's callAiApi (its Tickets model, General as fallback; secrets 'remove' - nothing
 * is ever put back) as feature `mit_ai_helper`; the answer cleaned again and kept with the sources it cited. Conversations
 * (mit_ai_conversations / mit_ai_messages, M045) are the agent's own - nobody else opens them. A monthly token limit (mit_settings
 * ai_helper_monthly_tokens, 0 = none) counts this and the library's embeddings (ITFlow's ai_usage).
 *
 * THE WALL (CLAUDE.md "AI helper"): the model gets nothing but the cleaned question, the cleaned passages and the cleaned current
 * ticket - no tools, no database. It is told never to write out a password and where they are kept instead.
 */

const MIT_AI_HISTORY_TURNS = 6;          // earlier messages sent with a question
const MIT_AI_TICKET_CHARS = 8000;        // the current ticket, at most (its start and latest)
const MIT_AI_QUESTION_CHARS = 2000;
const MIT_AI_FEATURES = ['mit_ai_helper', 'mit_ai_embed', 'mit_ai_document'];

/** Has M045 run? */
function mitAiAskReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mitAiHelperReady() && mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ai_messages'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Can the signed-in agent ask? On, set up, Use AI, Support view, and a model for Tickets (or General). */
function mitAiAskCan() {
    return mitAiAskReady() && mitAiHelperCan() && function_exists('aiReady') && aiReady('Tickets');
}

/** Tokens the helper used this month (answers + embeddings), from ITFlow's AI Usage. */
function mitAiTokensThisMonth() {
    global $mysqli;
    $features = "'" . implode("', '", MIT_AI_FEATURES) . "'";
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(ai_usage_tokens_in + ai_usage_tokens_out), 0) FROM ai_usage
        WHERE ai_usage_feature IN ($features) AND ai_usage_created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"))[0] ?? 0);
}

/** What the helper is called - "Ask Bob" (mit_settings ai_helper_name; "AI" when not set). Letters, digits, spaces and . - ' only, 30 at most. */
function mitAiName() {
    $name = trim(preg_replace('/\s+/u', ' ', preg_replace("/[^\p{L}\p{N} .'-]/u", '', (string) mitSetting('ai_helper_name', ''))));
    return $name === '' ? 'AI' : mb_substr($name, 0, 30);
}

/** The monthly token limit (0 = none). */
function mitAiTokenLimit() {
    return max(0, intval(mitSetting('ai_helper_monthly_tokens', '0')));
}

/** The ticket an agent may ask about - its row (id, client, reference) or null. Same rights as opening it: Support view + its client. */
function mitAiAskTicket($ticket_id) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_client_id FROM tickets
        WHERE ticket_id = " . intval($ticket_id) . " AND ticket_archived_at IS NULL"));
    if (!$row || lookupUserPermission('module_support') < 1 || (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id']))) {
        return null;
    }
    return $row;
}

/** The agent's conversations, newest first. */
function mitAiConversations($limit = 30) {
    global $mysqli, $session_user_id;
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT c.conversation_id, c.title, c.ticket_id, c.updated_at FROM mit_ai_conversations c
        WHERE c.user_id = " . intval($session_user_id) . " ORDER BY c.updated_at DESC, c.conversation_id DESC LIMIT " . intval($limit));
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = ['id' => intval($row['conversation_id']), 'title' => (string) $row['title'], 'ticket_id' => intval($row['ticket_id']), 'updated_at' => $row['updated_at']];
    }
    return $rows;
}

/** One of the agent's own conversations with its messages (answers as HTML), or null. */
function mitAiConversation($conversation_id) {
    global $mysqli, $session_user_id;
    $conversation = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT conversation_id, title, ticket_id FROM mit_ai_conversations
        WHERE conversation_id = " . intval($conversation_id) . " AND user_id = " . intval($session_user_id)));
    if (!$conversation) {
        return null;
    }
    $messages = [];
    $result = mysqli_query($mysqli, "SELECT message_id, role, content, sources, feedback, created_at FROM mit_ai_messages
        WHERE conversation_id = " . intval($conversation_id) . " ORDER BY message_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $messages[] = mitAiMessageOut($row);
    }
    return ['id' => intval($conversation['conversation_id']), 'title' => (string) $conversation['title'], 'ticket_id' => intval($conversation['ticket_id']),
        'messages' => $messages];
}

/** A stored message as the panel draws it. Sources are shown only while the agent may still open them. */
function mitAiMessageOut(array $row) {
    $sources = [];
    foreach ((array) json_decode((string) $row['sources'], true) as $source) {
        if (!empty($source['client_id']) && !hasClientAccess($source['client_id'])) {
            continue;
        }
        $sources[] = $source;
    }
    return [
        'id' => intval($row['message_id']),
        'role' => $row['role'],
        'html' => $row['role'] === 'assistant' ? mitAiAnswerHtml($row['content'], $sources) : nl2br(escapeHtml($row['content'])),
        // cited = the answer names it as [n]; the rest were looked at
        'sources' => array_map(fn($s) => ['n' => intval($s['n']), 'title' => (string) $s['title'], 'url' => (string) $s['url'], 'kind' => (string) $s['kind'],
            'client' => (string) ($s['client_name'] ?? ''), 'client_id' => intval($s['client_id'] ?? 0), 'cited' => $row['role'] === 'assistant' && strpos((string) $row['content'], '[' . intval($s['n']) . ']') !== false], $sources),
        'feedback' => $row['feedback'] === null ? 0 : intval($row['feedback']),
    ];
}

/**
 * An answer as safe HTML: escaped first, then a little Markdown (headings, **bold**, `code`, ``` blocks, - and 1. lists) and each [n]
 * linked to its source. Never raw HTML from the model.
 */
function mitAiAnswerHtml($text, array $sources = []) {
    $urls = [];
    foreach ($sources as $source) {
        $urls[intval($source['n'])] = $source;
    }
    $blocks = preg_split('/```[a-z]*\n?/i', (string) $text);
    $html = '';
    foreach ($blocks as $i => $block) {
        if ($i % 2) {
            $html .= '<pre class="mit-ai-code"><code>' . escapeHtml(rtrim($block)) . '</code></pre>';
            continue;
        }
        $list = '';
        foreach (preg_split("/\n/", escapeHtml($block)) as $line) {
            $line = rtrim($line);
            $line = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);
            $line = preg_replace('/`([^`]+)`/', '<code>$1</code>', $line);
            $line = preg_replace_callback('/\[(\d{1,2})\]/', function ($m) use ($urls) {
                $source = $urls[intval($m[1])] ?? null;
                return $source ? '<a href="' . escapeHtml($source['url']) . '" class="mit-ai-cite" title="' . escapeHtml($source['title']) . '" target="_blank" rel="noopener">[' . intval($m[1]) . ']</a>' : $m[0];
            }, $line);
            if (preg_match('/^\s*(?:[-*]|\d+[.)])\s+(.*)$/', $line, $item)) {
                $tag = preg_match('/^\s*\d/', $line) ? 'ol' : 'ul';
                if ($list !== $tag) {
                    $html .= ($list ? "</$list>" : '') . "<$tag>";
                    $list = $tag;
                }
                $html .= '<li>' . $item[1] . '</li>';
                continue;
            }
            if ($list) {
                $html .= "</$list>";
                $list = '';
            }
            if (preg_match('/^#{1,4}\s+(.*)$/', $line, $heading)) {
                $html .= '<div class="fw-semibold mt-2">' . $heading[1] . '</div>';
            } elseif (trim($line) !== '') {
                $html .= '<p class="mb-2">' . $line . '</p>';
            }
        }
        $html .= $list ? "</$list>" : '';
    }
    return $html;
}

/** What the helper is told before every question. */
function mitAiSystemPrompt() {
    $name = mitAiName();
    return ($name !== 'AI' ? "Your name is $name. " : '') . "You are the technicians' helper inside ITFlow, the IT service company's ticketing system. Answer the technician's question with "
        . "practical steps they can follow, using the passages from past tickets and documents you are given, and the current ticket when there is one.\n"
        . "- After each sentence that uses a passage, cite it as [1], [2] (its number). Never cite a number you were not given.\n"
        . "- Passages starting with Document are the company's written documentation; prefer them to past tickets when they cover the question.\n"
        . "- If the passages don't cover the question, say so plainly, then give general troubleshooting advice clearly marked as general.\n"
        . "- Passages can come from different clients; say which client a fix came from when it matters, and don't assume one client's setup applies to another.\n"
        . "- Never ask for, guess, repeat or make up a password, PIN, key, token or code. When one is needed, say it is kept in ITFlow's password vault "
        . "for that client. Text shown as [removed] was taken out on purpose - don't remark on it.\n"
        . "- Be concise. Use short paragraphs or numbered steps.";
}

/**
 * Ask: [ok, conversation_id, messages (the question and the answer as the panel draws them) | error]. $conversation_id 0 = a new one,
 * about $ticket_id when given (and the agent may open it). The question is cleaned before anything else happens to it.
 */
function mitAiAsk($conversation_id, $question, $ticket_id = 0) {
    global $mysqli, $session_user_id;
    if (!mitAiAskCan()) {
        return [false, 0, 'The AI helper is not available to you - it is off, or no AI model is set up, or your role has no Use AI permission.'];
    }
    $limit = mitAiTokenLimit();
    if ($limit && mitAiTokensThisMonth() >= $limit) {
        return [false, 0, 'The AI helper has used this month\'s token limit (Admin > Integrations > AI helper). It starts again on the 1st.'];
    }
    [$question] = mitAiScrub(mb_substr(trim((string) $question), 0, MIT_AI_QUESTION_CHARS));
    if ($question === '') {
        return [false, 0, 'Type a question.'];
    }

    // The conversation - the agent's own - and the ticket it is about
    $conversation_id = intval($conversation_id);
    if ($conversation_id) {
        $conversation = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT conversation_id, ticket_id FROM mit_ai_conversations
            WHERE conversation_id = $conversation_id AND user_id = " . intval($session_user_id)));
        if (!$conversation) {
            return [false, 0, 'That conversation is not yours, or no longer there.'];
        }
        $ticket_id = intval($conversation['ticket_id']);
    }
    $ticket = $ticket_id ? mitAiAskTicket($ticket_id) : null;

    // What goes with the question: the current ticket, the library's best passages, the conversation so far
    $context = '';
    $client_id = 0;
    if ($ticket) {
        $client_id = intval($ticket['ticket_client_id']);
        $current = mitAiTicketSource($ticket['ticket_id'], true);
        if ($current) {
            [$text] = mitAiScrub($current['text']);
            [$head] = mitAiScrub($current['head']);
            if (mb_strlen($text) > MIT_AI_TICKET_CHARS) {
                $text = mb_substr($text, 0, intval(MIT_AI_TICKET_CHARS / 3)) . "\n[...]\n" . mb_substr($text, -intval(MIT_AI_TICKET_CHARS * 2 / 3));
            }
            $context .= "The current ticket - $head\n$text\n\n";
        }
    }
    $search = $question . ($ticket ? ' ' . mitAiScrub($ticket['ticket_subject'])[0] : '');
    $hits = array_values(array_filter(mitAiSearch($search, ['limit' => 8]), function ($hit) use ($ticket_id) {
        return !($hit['kind'] === 'ticket' && $ticket_id && strpos($hit['url'], 'ticket_id=' . intval($ticket_id)) !== false);
    }));
    $sources = [];
    if ($hits) {
        $context .= "Passages from past tickets and documents:\n";
        foreach ($hits as $i => $hit) {
            $n = $i + 1;
            $context .= "[$n] " . $hit['text'] . "\n\n";
            $sources[] = ['n' => $n, 'title' => $hit['title'], 'url' => $hit['url'], 'kind' => $hit['kind'], 'client_id' => $hit['client_id'],
                'client_name' => $hit['client_name'], 'source_id' => $hit['source_id'], 'chunk_id' => $hit['chunk_id']];
        }
    } else {
        $context .= "No passages from past tickets or documents matched this question.\n";
    }

    $messages = [['role' => 'system', 'content' => mitAiSystemPrompt()]];
    if ($conversation_id) {
        $history = mysqli_fetch_all(mysqli_query($mysqli, "SELECT role, content FROM mit_ai_messages WHERE conversation_id = $conversation_id
            ORDER BY message_id DESC LIMIT " . MIT_AI_HISTORY_TURNS), MYSQLI_ASSOC);
        foreach (array_reverse($history) as $turn) {
            $messages[] = ['role' => $turn['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $turn['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => "Question: $question\n\n$context"];

    $model = getAiModel('Tickets');
    $result = callAiApi($model, $messages, ['feature' => 'mit_ai_helper', 'client_id' => $client_id, 'secrets' => 'remove']);
    if (empty($result['ok'])) {
        return [false, $conversation_id, $result['error'] ?? 'The AI provider did not answer.'];
    }
    [$answer] = mitAiScrub(trim((string) $result['content']));

    // Keep the turn
    $esc = fn($v) => "'" . mysqli_real_escape_string($mysqli, (string) $v) . "'";
    if (!$conversation_id) {
        mysqli_query($mysqli, "INSERT INTO mit_ai_conversations SET user_id = " . intval($session_user_id) . ", ticket_id = " . ($ticket ? intval($ticket['ticket_id']) : 'NULL')
            . ", title = " . $esc(mb_substr($question, 0, 120)));
        $conversation_id = intval(mysqli_insert_id($mysqli));
    } else {
        mysqli_query($mysqli, "UPDATE mit_ai_conversations SET updated_at = NOW() WHERE conversation_id = $conversation_id");
    }
    mysqli_query($mysqli, "INSERT INTO mit_ai_messages SET conversation_id = $conversation_id, role = 'user', content = " . $esc($question));
    $question_id = intval(mysqli_insert_id($mysqli));
    mysqli_query($mysqli, "INSERT INTO mit_ai_messages SET conversation_id = $conversation_id, role = 'assistant', content = " . $esc($answer) . ", sources = " . $esc(json_encode($sources)));
    $answer_id = intval(mysqli_insert_id($mysqli));
    $out = [];
    $rows = mysqli_query($mysqli, "SELECT message_id, role, content, sources, feedback, created_at FROM mit_ai_messages WHERE message_id IN ($question_id, $answer_id) ORDER BY message_id");
    while ($row = mysqli_fetch_assoc($rows)) {
        $out[] = mitAiMessageOut($row);
    }
    return [true, $conversation_id, $out];
}

/** Thumbs up (1), down (-1) or neither (0) on one of the agent's answers. */
function mitAiFeedback($message_id, $value) {
    global $mysqli, $session_user_id;
    $value = max(-1, min(1, intval($value)));
    mysqli_query($mysqli, "UPDATE mit_ai_messages m JOIN mit_ai_conversations c ON c.conversation_id = m.conversation_id
        SET m.feedback = " . ($value ? $value : 'NULL') . " WHERE m.message_id = " . intval($message_id) . " AND m.role = 'assistant' AND c.user_id = " . intval($session_user_id));
    return mysqli_affected_rows($mysqli) >= 0 && (bool) mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM mit_ai_messages m JOIN mit_ai_conversations c
        ON c.conversation_id = m.conversation_id WHERE m.message_id = " . intval($message_id) . " AND c.user_id = " . intval($session_user_id)));
}

/** Delete one of the agent's conversations. */
function mitAiDeleteConversation($conversation_id) {
    global $mysqli, $session_user_id;
    mysqli_query($mysqli, "DELETE FROM mit_ai_conversations WHERE conversation_id = " . intval($conversation_id) . " AND user_id = " . intval($session_user_id));
    return mysqli_affected_rows($mysqli) > 0;
}

/** Thumbs up / down and questions asked, for the admin page. */
function mitAiAskStats($days = 30) {
    global $mysqli;
    if (!mitAiAskReady()) {
        return [];
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(role = 'user') AS questions, SUM(feedback = 1) AS up, SUM(feedback = -1) AS down,
        COUNT(DISTINCT conversation_id) AS conversations FROM mit_ai_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . intval($days) . " DAY)"));
    return array_map('intval', $row ?: []);
}

/**
 * The top bar's Ask AI button and its panel (required inside includes/mit_timers_nav.php - no line of its own in ITFlow's files).
 * On an agent's ticket page it offers "about this ticket".
 */
function mitAiPanelNav() {
    if (!mitAiAskCan()) {
        return '';
    }
    $name = escapeHtml(mitAiName());
    $ticket = '';
    if (basename($_SERVER['PHP_SELF'] ?? '') === 'ticket.php' && strpos($_SERVER['PHP_SELF'] ?? '', '/agent/') !== false && !empty($_GET['ticket_id'])) {
        $row = mitAiAskTicket(intval($_GET['ticket_id']));
        if ($row) {
            $ticket = ' data-ticket-id="' . intval($row['ticket_id']) . '" data-ticket-label="' . escapeHtml($row['ticket_prefix'] . intval($row['ticket_number'])) . '"';
        }
    }
    return '<li class="nav-item" id="mitAiNav">'
        . '<a class="nav-link" href="#" role="button" data-bs-toggle="offcanvas" data-bs-target="#mitAiPanel" aria-controls="mitAiPanel" title="Ask ' . $name . '" data-mit-ai-open>'
        . '<i class="fas fa-robot" aria-hidden="true"></i><span class="d-none d-xl-inline ms-1">Ask ' . $name . '</span></a>'
        // hidden until the script moves it out of the top bar - Bootstrap shows an offcanvas inside an expanded navbar as part of the bar
        . '<div class="offcanvas offcanvas-end mit-ai-panel" tabindex="-1" id="mitAiPanel" aria-labelledby="mitAiPanelTitle" data-endpoint="/agent/mit_ai_ajax.php" data-name="' . $name . '" hidden'
        . ' data-csrf="' . escapeHtml($_SESSION['csrf_token'] ?? '') . '"' . $ticket . '>'
        . '<div class="offcanvas-header border-bottom py-2"><h5 class="offcanvas-title me-auto" id="mitAiPanelTitle"><i class="fas fa-robot me-2"></i>Ask ' . $name . '</h5>'
        . '<button type="button" class="btn btn-sm btn-light me-1" data-mit-ai-new title="New conversation"><i class="fas fa-plus"></i></button>'
        . (lookupUserPermission('module_support') >= 2 ? '<button type="button" class="btn btn-sm btn-light me-1" data-mit-ai-writeup title="Write this conversation up as a document" hidden>'
            . '<i class="fas fa-file-medical"></i></button>' : '')
        . '<button type="button" class="btn btn-sm btn-light me-2" data-mit-ai-history title="Earlier conversations"><i class="fas fa-history"></i></button>'
        . '<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>'
        . '<div class="offcanvas-body d-flex flex-column p-0">'
        . '<div class="mit-ai-list border-bottom" data-mit-ai-list hidden></div>'
        . '<div class="mit-ai-thread flex-grow-1 overflow-auto p-3" data-mit-ai-thread><div class="text-muted small" data-mit-ai-empty>Ask about a problem - answers come '
        . 'from your team\'s past tickets and documents, with links to each. Passwords are never read or shown.</div></div>'
        . '<form class="border-top p-2" data-mit-ai-form>'
        . ($ticket ? '<div class="form-check small mb-1"><input class="form-check-input" type="checkbox" id="mitAiAbout" data-mit-ai-about checked>'
            . '<label class="form-check-label" for="mitAiAbout">About this ticket</label></div>' : '')
        . '<div class="d-flex gap-2"><textarea class="form-control form-control-sm" rows="2" maxlength="' . MIT_AI_QUESTION_CHARS . '" placeholder="Ask a question..." data-mit-ai-question></textarea>'
        . '<button type="submit" class="btn btn-primary btn-sm" data-mit-ai-send title="Ask"><i class="fas fa-paper-plane"></i></button></div></form>'
        . '</div></div>'
        . '<script src="' . mitAsset('/agent/js/mit_ai_helper.js') . '" defer></script>'
        . '</li>';
}
