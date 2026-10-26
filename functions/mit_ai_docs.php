<?php

/*
 * Mountaineer IT - AI helper, round 3: documentation from an Ask AI conversation (required from functions/mit_ai_helper.php).
 *
 * "Write up" in the panel (agent/js/mit_ai_helper.js) opens agent/modals/mit_ai/document.php - ITFlow's own New Document form
 * (modals/document/document_form.php, saved by ITFlow's add_document) filled in with a draft from mitAiDocumentDraft: the conversation
 * (questions and answers, stored cleaned) and the full text of the tickets and documents its answers cited, read again now - only
 * those of clients the agent can open -, cleaned, sent through ITFlow's callAiApi with its Documentation model (General as fallback),
 * secrets removed for good; the HTML that comes back is cleaned once more and purified. Nothing is kept until the agent creates the
 * document. No tables of its own.
 */

const MIT_AI_DOC_SOURCE_CHARS = 5000;   // each cited ticket / document, at most
const MIT_AI_DOC_TOTAL_CHARS = 24000;   // all of them together

/** The client a conversation's document goes with by default: its ticket's, else the one most cited, else the company's own. */
function mitAiDocumentDefaultClient(array $conversation) {
    global $mysqli, $config_internal_client_id;
    if (!empty($conversation['ticket_id'])) {
        $client = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = " . intval($conversation['ticket_id'])))[0] ?? 0);
        if ($client && hasClientAccess($client)) {
            return $client;
        }
    }
    $counts = [];
    foreach ($conversation['messages'] as $message) {
        foreach ($message['sources'] as $source) {
            if (!empty($source['cited']) && !empty($source['client_id'])) {
                $counts[intval($source['client_id'])] = ($counts[intval($source['client_id'])] ?? 0) + 1;
            }
        }
    }
    arsort($counts);
    foreach (array_keys($counts) as $client) {
        if (hasClientAccess($client)) {
            return $client;
        }
    }
    return intval($config_internal_client_id ?? 0);
}

/** Clients the agent may keep a document with, id => name (the company's own first). */
function mitAiDocumentClients() {
    global $mysqli, $config_internal_client_id;
    $clients = [];
    $result = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . clientScopeSql('client_id') . " ORDER BY client_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $clients[intval($row['client_id'])] = $row['client_name'];
    }
    $own = intval($config_internal_client_id ?? 0);
    if ($own && isset($clients[$own])) {
        $clients = [$own => $clients[$own] . ' (our own)'] + $clients;
    }
    return $clients;
}

/**
 * A document drafted from one of the agent's conversations, for $client_id's documentation (or the company's own) - ['ok', 'title',
 * 'description', 'html', 'removed'] or ['ok' => false, 'error'].
 */
function mitAiDocumentDraft($conversation_id, $client_id) {
    global $mysqli, $config_internal_client_id;
    if (!mitAiAskCan() || lookupUserPermission('module_support') < 2) {
        return ['ok' => false, 'error' => 'Writing documents needs the AI helper and Support edit.'];
    }
    $limit = mitAiTokenLimit();
    if ($limit && mitAiTokensThisMonth() >= $limit) {
        return ['ok' => false, 'error' => 'The AI helper has used this month\'s token limit. It starts again on the 1st.'];
    }
    $conversation = mitAiConversation($conversation_id);
    if (!$conversation || !$conversation['messages']) {
        return ['ok' => false, 'error' => 'That conversation is not yours, or has nothing in it yet.'];
    }
    $client_id = intval($client_id);
    if (!$client_id || !hasClientAccess($client_id)) {
        return ['ok' => false, 'error' => 'Pick a client you can open to keep the document with.'];
    }
    $own = $client_id === intval($config_internal_client_id ?? 0);
    $client_name = (string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id"))[0];

    // The conversation, as it was kept (cleaned), then the full text of what its answers cited
    $text = "The conversation:\n";
    $cited = [];
    foreach (mysqli_fetch_all(mysqli_query($mysqli, "SELECT role, content, sources FROM mit_ai_messages WHERE conversation_id = " . intval($conversation['id'])
        . " ORDER BY message_id"), MYSQLI_ASSOC) as $turn) {
        $text .= ($turn['role'] === 'assistant' ? 'Helper: ' : 'Technician: ') . $turn['content'] . "\n\n";
        foreach ((array) json_decode((string) $turn['sources'], true) as $source) {
            if (strpos($turn['content'], '[' . intval($source['n']) . ']') !== false && !empty($source['source_id'])) {
                $cited[intval($source['source_id'])] = true;
            }
        }
    }
    $used = 0;
    $read = [];
    if (!empty($conversation['ticket_id']) && mitAiAskTicket($conversation['ticket_id'])) {
        $read[] = mitAiTicketSource($conversation['ticket_id'], true);
    }
    if ($cited) {
        $result = mysqli_query($mysqli, "SELECT ticket_id, document_id, client_id FROM mit_ai_sources WHERE source_id IN (" . implode(',', array_keys($cited)) . ")"
            . clientScopeSql('client_id'));
        while ($row = mysqli_fetch_assoc($result)) {
            $read[] = $row['ticket_id'] ? mitAiTicketSource($row['ticket_id']) : mitAiDocumentSource($row['document_id']);
        }
    }
    $sources = '';
    foreach (array_filter($read) as $source) {
        [$body] = mitAiScrub($source['text']);
        [$head] = mitAiScrub($source['head']);
        $body = mb_substr($body, 0, MIT_AI_DOC_SOURCE_CHARS);
        if ($used + mb_strlen($body) > MIT_AI_DOC_TOTAL_CHARS) {
            break;
        }
        $used += mb_strlen($body);
        $sources .= "--- $head\n$body\n\n";
    }
    if ($sources !== '') {
        $text .= "The tickets and documents it drew on:\n$sources";
    }

    $model = getAiModel('Documentation');
    if (!$model) {
        return ['ok' => false, 'error' => 'No AI model is set up for Documentation or General (Admin > AI Providers).'];
    }
    $model['ai_provider_redact'] = 1; // a document is kept: secrets out whatever the provider is set to
    $for = $own ? 'the company\'s own knowledge base - general, so any technician can follow it for any client; no client names'
        : "the documentation of the client \"$client_name\" - keep to what applies to them";
    $result = callAiApi($model, [
        ['role' => 'system', 'content' => 'You write knowledge base articles for an IT service company, from a technician\'s conversation with an assistant and the tickets and'
            . " documents it drew on. This one is for $for. Write what to do the next time this happens - not the story: no dates, no ticket numbers,"
            . ' no who-said-what. Use only what the material supports; where a step or detail should be confirmed, add [check].'
            . ' Never write a password, PIN, key, token or code - where one is needed, say it is in ITFlow\'s password vault for the client.'
            . ' Answer in HTML only, in this shape: <h1>a short title</h1><p><em>one sentence on what it covers</em></p>'
            . ' <h2>Symptoms</h2> (what it looks like - error messages word for word) <h2>Cause</h2> (only when the material says) <h2>Resolution</h2> (numbered steps in an <ol>)'
            . ' <h2>If that doesn\'t work</h2> and <h2>Prevention</h2> only when the material gives something for them. No introduction, no code fences, no <html> or <body>.'],
        ['role' => 'user', 'content' => $text],
    ], ['feature' => 'mit_ai_document', 'client_id' => $own ? 0 : $client_id, 'secrets' => 'remove']);
    if (empty($result['ok'])) {
        return ['ok' => false, 'error' => $result['error'] ?? 'The AI provider did not answer.'];
    }
    // Cleaned again - one block a line, so taking out a sentence never takes a tag with it - then purified as ITFlow does
    $html = preg_replace('#(</(?:p|li|h[1-6]|div|tr|pre|ol|ul|blockquote)>|<br\s*/?>)#i', "$1\n", (string) $result['content']);
    [$html, $taken] = mitAiScrub($html);
    [$title, $description, $html] = aiDocumentSplit(aiCleanHtml($html));
    if (trim(strip_tags($html)) === '') {
        return ['ok' => false, 'error' => 'The AI provider answered with nothing to keep - try again.'];
    }
    [$title] = mitAiScrub($title !== '' ? $title : $conversation['title']);
    [$description] = mitAiScrub($description);
    return ['ok' => true, 'title' => mb_substr($title, 0, 200), 'description' => mb_substr($description, 0, 250), 'html' => $html,
        'removed' => intval($result['removed'] ?? 0) + $taken];
}
