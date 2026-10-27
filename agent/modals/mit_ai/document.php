<?php

/*
 * Mountaineer IT - "Write up" from the Ask AI panel (functions/mit_ai_docs.php): ITFlow's New Document form
 * (modals/document/document_form.php, created by ITFlow's add_document in agent/post/document.php) filled in with a draft of one of the
 * agent's conversations and the tickets / documents it cited. "Keep it with" picks the client whose documentation it goes in (the
 * company's own for general how-tos); changing it opens the window again for that client and keeps the draft (Redraft writes it for
 * the new client). Nothing is kept until Create document.
 */

require_once '../../../includes/modal_header.php';

if (!function_exists('mitAiAskCan') || !mitAiAskCan() || lookupUserPermission('module_support') < 2) {
    permissionRefuse('Writing documents needs the AI helper and Support edit.');
}
$conversation = mitAiConversation(intval($_GET['conversation_id'] ?? 0));
if (!$conversation) {
    permissionRefuse('That conversation is not yours, or no longer there.');
}
$clients = mitAiDocumentClients();
$client_id = intval($_GET['client_id'] ?? 0) ?: mitAiDocumentDefaultClient($conversation);
if (!$client_id || !isset($clients[$client_id])) {
    echo json_encode(['error' => 'Pick a client to keep the document with - or set your own company\'s client record in Admin > Settings > Company.']);
    exit;
}
$conversation_id = intval($conversation['id']);
$mit_ai_name = mitAiName();
$ticket_id = intval($conversation['ticket_id']);

$document_form_mode = 'add';
$document_name = escapeHtml($conversation['title']);
$document_description = $document_content = '';
$document_folder_id = 0;
$document_client_visible = 0;

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-file-medical me-2"></i>Write up as a document</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="/agent/post.php" method="post" autocomplete="off" data-mit-ai-doc-form data-name="<?= escapeHtml($mit_ai_name) ?>" data-conversation-id="<?= $conversation_id ?>" data-client-id="<?= $client_id ?>">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="client_id" value="<?= $client_id ?>">
    <?php if ($ticket_id) { ?><input type="hidden" name="from_ticket_id" value="<?= $ticket_id ?>"><?php } ?>
    <div class="modal-body">
        <div class="row g-2 align-items-end mb-3">
            <div class="col-md-8">
                <label class="form-label">Keep it with</label>
                <select class="form-select" data-mit-ai-doc-client>
                    <?php foreach ($clients as $id => $name) { ?>
                        <option value="<?= intval($id) ?>" <?= $id === $client_id ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-4 text-md-end">
                <button type="button" class="btn btn-outline-secondary" data-mit-ai-doc-redraft hidden><i class="fas fa-redo me-2"></i>Redraft for this client</button>
            </div>
        </div>
        <div class="alert alert-info py-2 small d-flex align-items-center gap-2" data-mit-ai-doc-status>
            <i class="fas fa-spinner fa-spin"></i><span><?= escapeHtml($mit_ai_name) ?> is writing it up from the conversation and the tickets and documents it used...</span>
        </div>
        <?php require __DIR__ . '/../document/document_form.php'; ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_document" class="btn btn-primary text-bold" data-mit-ai-doc-save disabled><i class="fa fa-check me-2"></i>Create document</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
(function () {
    var form = document.querySelector('[data-mit-ai-doc-form]:not([data-ready])');
    if (!form) {
        return;
    }
    form.setAttribute('data-ready', '1');
    var conversation = form.getAttribute('data-conversation-id');
    var client = form.getAttribute('data-client-id');
    var key = 'mitAiDraft:' + conversation;
    var name = form.getAttribute('data-name') || 'AI';
    var status = form.querySelector('[data-mit-ai-doc-status]');
    var save = form.querySelector('[data-mit-ai-doc-save]');
    var redraft = form.querySelector('[data-mit-ai-doc-redraft]');
    var textarea = form.querySelector('textarea[name="content"]');

    function say(tone, icon, text) {
        status.className = 'alert alert-' + tone + ' py-2 small d-flex align-items-center gap-2';
        status.innerHTML = '<i class="fas fa-fw ' + icon + '"></i><span></span>';
        status.querySelector('span').textContent = text;
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

    function fill(answer, note) {
        form.querySelector('[name="name"]').value = answer.title;
        form.querySelector('[name="description"]').value = answer.description;
        editor(function (ed) {
            if (ed) {
                ed.setContent(answer.html); // purified by the server (aiCleanHtml)
            } else {
                textarea.value = answer.html;
            }
            say('success', 'fa-check-circle', note + (answer.removed ? ' - ' + answer.removed + ' password(s) or key(s) left out' : '') + '. Check it, change what you like, then create it.');
            status.setAttribute('data-mit-ai-doc-ready', '');
            save.disabled = false;
        });
    }

    function draft() {
        save.disabled = true;
        say('info', 'fa-spinner fa-spin', name + ' is writing it up from the conversation and the tickets and documents it used...');
        var body = new URLSearchParams({ draft: '1', conversation_id: conversation, client_id: client, csrf_token: form.querySelector('[name="csrf_token"]').value });
        fetch('/agent/mit_ai_ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
            .then(function (response) { return response.json(); })
            .then(function (answer) {
                if (!answer.ok) {
                    throw new Error(answer.error || '');
                }
                try { sessionStorage.setItem(key, JSON.stringify({ client: client, answer: answer })); } catch (e) { /* private window */ }
                redraft.hidden = true;
                fill(answer, 'Drafted by ' + name);
            })
            .catch(function (error) {
                say('warning', 'fa-exclamation-triangle', (error && error.message ? error.message : 'It couldn\'t be drafted.') + ' You can still write it here yourself.');
                save.disabled = false;
            });
    }

    var cached = null;
    try { cached = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) { cached = null; }
    if (cached && cached.answer) {
        redraft.hidden = String(cached.client) === String(client);
        fill(cached.answer, String(cached.client) === String(client) ? 'Drafted by ' + name : 'The draft written for the other client');
    } else {
        draft();
    }
    redraft.addEventListener('click', draft);

    // Another client: the window again for it (its folders), the draft kept
    form.querySelector('[data-mit-ai-doc-client]').addEventListener('change', function () {
        var modal = form.closest('.modal');
        var link = document.createElement('a');
        link.className = 'ajax-modal d-none';
        link.setAttribute('data-modal-url', '/agent/modals/mit_ai/document.php?conversation_id=' + encodeURIComponent(conversation) + '&client_id=' + encodeURIComponent(this.value));
        link.setAttribute('data-modal-size', 'xl');
        document.body.appendChild(link);
        if (modal && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(modal).hide();
        }
        link.click();
        link.remove();
    });
    form.addEventListener('submit', function () {
        try { sessionStorage.removeItem(key); } catch (e) { /* nothing kept */ }
    });
})();
</script>

<?php
require_once '../../../includes/modal_footer.php';
