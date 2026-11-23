<?php

/*
 * Mountaineer IT - "Write up for the Knowledge Base" from the Ask AI panel (functions/mit_ai_docs.php): ITFlow's New Article form
 * (modals/kb/kb_article_form.php, created by ITFlow's add_kb_article in agent/post/kb.php) filled in with a general draft of one of the
 * agent's conversations and what it cited - no client, people or system names (an article is for every client). Save as draft or
 * Publish (the user's choice, Nov 22) - the two buttons set ITFlow's own Published switch; who can read it stays ITFlow's field (agents
 * only to start). Nothing is kept until one of them is pressed.
 */

require_once '../../../includes/modal_header.php';

if (!function_exists('mitAiAskCan') || !mitAiAskCan() || lookupUserPermission('module_support') < 2) {
    permissionRefuse('Writing articles needs the AI helper and Support edit.');
}
$kb_closed = function_exists('kbClosedText') ? kbClosedText(2) : 'The Knowledge Base is not here.';
if ($kb_closed !== '') {
    exit(json_encode(['error' => $kb_closed]));
}
$conversation = mitAiConversation(intval($_GET['conversation_id'] ?? 0));
if (!$conversation) {
    permissionRefuse('That conversation is not yours, or no longer there.');
}
$conversation_id = intval($conversation['id']);
$mit_ai_name = mitAiName();
$ticket_id = intval($conversation['ticket_id']);

$kb_form_mode = 'add';
$kb_form = [
    'kb_article_title' => mb_substr((string) $conversation['title'], 0, 200),
    'kb_article_review_months' => 12,
    'kb_article_published_at' => null, // a draft until Publish is pressed
];

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-book-medical me-2"></i>Write up for the Knowledge Base</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="/agent/post.php" method="post" autocomplete="off" data-mit-ai-kb-form data-name="<?= escapeHtml($mit_ai_name) ?>" data-conversation-id="<?= $conversation_id ?>">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="add_kb_article" value="1">
    <?php if ($ticket_id) { ?><input type="hidden" name="from_ticket_id" value="<?= $ticket_id ?>"><?php } ?>
    <div class="modal-body">
        <div class="alert alert-info py-2 small d-flex align-items-center gap-2" data-mit-ai-kb-status>
            <i class="fas fa-spinner fa-spin"></i><span><?= escapeHtml($mit_ai_name) ?> is writing it up for any client, from the conversation and what it used...</span>
        </div>
        <?php require __DIR__ . '/../kb/kb_article_form.php'; ?>
    </div>
    <div class="modal-footer">
        <button type="submit" class="btn btn-outline-primary fw-bold" data-mit-ai-kb-save="draft" disabled><i class="fas fa-pencil-alt me-2"></i>Save as draft</button>
        <button type="submit" class="btn btn-primary fw-bold" data-mit-ai-kb-save="publish" disabled><i class="fas fa-check me-2"></i>Publish</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
(function () {
    var form = document.querySelector('[data-mit-ai-kb-form]:not([data-ready])');
    if (!form) {
        return;
    }
    form.setAttribute('data-ready', '1');
    var conversation = form.getAttribute('data-conversation-id');
    var key = 'mitAiKbDraft:' + conversation;
    var name = form.getAttribute('data-name') || 'AI';
    var status = form.querySelector('[data-mit-ai-kb-status]');
    var saves = form.querySelectorAll('[data-mit-ai-kb-save]');
    var textarea = form.querySelector('textarea[name="content"]');
    var published = form.querySelector('input[type="checkbox"][name="published"]');

    function say(tone, icon, text) {
        status.className = 'alert alert-' + tone + ' py-2 small d-flex align-items-center gap-2';
        status.innerHTML = '<i class="fas fa-fw ' + icon + '"></i><span></span>';
        status.querySelector('span').textContent = text;
    }

    function ready(on) {
        saves.forEach(function (button) { button.disabled = !on; });
    }

    function editor(then, tries) {
        var found = window.tinymce ? tinymce.get().filter(function (ed) { return ed.getElement() === textarea; })[0] : null;
        if (found && found.initialized) {
            then(found);
        } else if ((tries || 0) < 100) {
            setTimeout(function () { editor(then, (tries || 0) + 1); }, 100);
        } else {
            then(null);
        }
    }

    function fill(answer) {
        form.querySelector('[name="title"]').value = answer.title;
        form.querySelector('[name="summary"]').value = answer.description;
        editor(function (ed) {
            if (ed) {
                ed.setContent(answer.html); // purified by the server (aiCleanHtml)
            } else {
                textarea.value = answer.html;
            }
            say('success', 'fa-check-circle', 'Drafted by ' + name + (answer.removed ? ' - ' + answer.removed + ' password(s) or key(s) left out' : '')
                + '. Check it for anything about one client, then save it as a draft or publish it.');
            status.setAttribute('data-mit-ai-kb-ready', '');
            ready(true);
        });
    }

    var cached = null;
    try { cached = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) { cached = null; }
    if (cached && cached.html) {
        fill(cached);
    } else {
        var body = new URLSearchParams({ draft: '1', kb: '1', conversation_id: conversation, client_id: '0', csrf_token: form.querySelector('[name="csrf_token"]').value });
        fetch('/agent/mit_ai_ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                if (!answer.ok) {
                    throw new Error(answer.error || '');
                }
                try { sessionStorage.setItem(key, JSON.stringify(answer)); } catch (e) { /* private window */ }
                fill(answer);
            })
            .catch(function (error) {
                say('warning', 'fa-exclamation-triangle', (error && error.message ? error.message : 'It couldn\'t be drafted.') + ' You can still write it here yourself.');
                ready(true);
            });
    }

    // The button pressed decides ITFlow's Published switch
    saves.forEach(function (button) {
        button.addEventListener('click', function () {
            if (published) {
                published.checked = button.getAttribute('data-mit-ai-kb-save') === 'publish';
            }
        });
    });
    form.addEventListener('submit', function () {
        try { sessionStorage.removeItem(key); } catch (e) { /* nothing kept */ }
    });
})();
</script>

<?php
require_once '../../../includes/modal_footer.php';
