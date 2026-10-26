// Mountaineer IT - the Ask AI panel (functions/mit_ai_ask.php mitAiPanelNav, requests to agent/mit_ai_ajax.php).
// Asks, draws the answers the server already made safe (escaped + a little Markdown, sources linked), thumbs up / down, earlier
// conversations. The agent's own question is drawn from its text, never as HTML.
(function () {
    'use strict';
    const panel = document.getElementById('mitAiPanel');
    if (!panel || panel.dataset.ready) {
        return;
    }
    panel.dataset.ready = '1';
    document.body.appendChild(panel); // out of the navbar, so the offcanvas sits over the page

    const endpoint = panel.dataset.endpoint;
    const csrf = panel.dataset.csrf;
    const ticketId = panel.dataset.ticketId || '';
    const ticketLabel = panel.dataset.ticketLabel || '';
    const thread = panel.querySelector('[data-mit-ai-thread]');
    const list = panel.querySelector('[data-mit-ai-list]');
    const form = panel.querySelector('[data-mit-ai-form]');
    const question = panel.querySelector('[data-mit-ai-question]');
    const send = panel.querySelector('[data-mit-ai-send]');
    const about = panel.querySelector('[data-mit-ai-about]');
    const empty = thread.innerHTML;
    let conversationId = 0;
    let busy = false;

    const style = document.createElement('style');
    style.textContent = `
        .mit-ai-panel { --bs-offcanvas-width: min(34rem, 100vw); }
        .mit-ai-msg { border-radius: .6rem; padding: .55rem .75rem; margin-bottom: .75rem; max-width: 100%; overflow-wrap: anywhere; }
        .mit-ai-msg.mit-ai-question { background: var(--bs-primary-bg-subtle); margin-left: 2.5rem; }
        .mit-ai-msg.mit-ai-answer { background: var(--bs-tertiary-bg); margin-right: 1rem; }
        .mit-ai-msg ul, .mit-ai-msg ol { padding-left: 1.2rem; margin-bottom: .5rem; }
        .mit-ai-msg p:last-child { margin-bottom: 0; }
        .mit-ai-code { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .4rem; padding: .5rem; font-size: .8rem; white-space: pre-wrap; }
        .mit-ai-sources { border-top: 1px solid var(--bs-border-color); margin-top: .5rem; padding-top: .4rem; font-size: .8rem; }
        .mit-ai-sources a { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mit-ai-list { max-height: 45%; overflow: auto; }
        .mit-ai-list .list-group-item { cursor: pointer; }
    `;
    document.head.appendChild(style);

    const escape = (text) => String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function post(fields) {
        const body = new FormData();
        body.append('csrf_token', csrf);
        Object.entries(fields).forEach(([k, v]) => body.append(k, v));
        return fetch(endpoint, { method: 'POST', body }).then((r) => r.json().then((json) => ({ ok: r.ok, json })));
    }

    function get(query) {
        return fetch(endpoint + '?' + query).then((r) => r.json().then((json) => ({ ok: r.ok, json })));
    }

    function messageEl(message) {
        const el = document.createElement('div');
        el.className = 'mit-ai-msg ' + (message.role === 'assistant' ? 'mit-ai-answer' : 'mit-ai-question');
        el.dataset.mitAiMessage = message.role;
        el.innerHTML = message.html; // made safe by the server (mitAiAnswerHtml / escaped question)
        if (message.role === 'assistant') {
            if (message.sources && message.sources.length) {
                const link = (s) => `<a href="${escape(s.url)}" target="_blank" rel="noopener" title="${escape(s.title)}">`
                    + `[${s.n}] <i class="fas fa-fw ${s.kind === 'ticket' ? 'fa-life-ring' : 'fa-file-alt'} text-muted"></i> ${escape(s.title)}${s.client ? ' <span class="text-muted">- ' + escape(s.client) + '</span>' : ''}</a>`;
                const cited = message.sources.filter((s) => s.cited);
                const rest = message.sources.filter((s) => !s.cited);
                const sources = document.createElement('div');
                sources.className = 'mit-ai-sources';
                sources.dataset.mitAiSources = '';
                sources.innerHTML = (cited.length ? '<div class="text-muted mb-1">Sources</div>' + cited.map(link).join('') : '')
                    + (rest.length ? `<details class="mt-1" data-mit-ai-looked><summary class="text-muted">${cited.length ? 'Also looked at' : 'Looked at'} (${rest.length})</summary>${rest.map(link).join('')}</details>` : '');
                el.appendChild(sources);
            }
            const rate = document.createElement('div');
            rate.className = 'mt-2 d-flex gap-1 justify-content-end';
            rate.innerHTML = `<button type="button" class="btn btn-sm btn-link p-0 px-1 text-muted" data-mit-ai-rate="1" title="Helpful"><i class="fa${message.feedback === 1 ? 's' : 'r'} fa-thumbs-up"></i></button>`
                + `<button type="button" class="btn btn-sm btn-link p-0 px-1 text-muted" data-mit-ai-rate="-1" title="Not helpful"><i class="fa${message.feedback === -1 ? 's' : 'r'} fa-thumbs-down"></i></button>`;
            rate.dataset.messageId = message.id;
            rate.dataset.value = message.feedback || 0;
            el.appendChild(rate);
        }
        return el;
    }

    function show(messages, replace) {
        if (replace) {
            thread.innerHTML = '';
        }
        thread.querySelector('[data-mit-ai-empty]')?.remove();
        messages.forEach((m) => thread.appendChild(messageEl(m)));
        thread.scrollTop = thread.scrollHeight;
    }

    function reset() {
        conversationId = 0;
        thread.innerHTML = empty;
        if (about) {
            about.closest('.form-check').hidden = false;
        }
        list.hidden = true;
        question.focus();
    }

    function load(id) {
        get('conversation=' + encodeURIComponent(id)).then(({ ok, json }) => {
            if (!ok) {
                return;
            }
            conversationId = json.id;
            list.hidden = true;
            if (about) {
                about.closest('.form-check').hidden = true; // a conversation keeps the ticket it started with
            }
            show(json.messages, true);
        });
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const text = question.value.trim();
        if (!text || busy) {
            return;
        }
        busy = true;
        send.disabled = true;
        thread.querySelector('[data-mit-ai-empty]')?.remove();
        const mine = messageEl({ role: 'user', html: escape(text).replace(/\n/g, '<br>') });
        const waiting = document.createElement('div');
        waiting.className = 'mit-ai-msg mit-ai-answer text-muted';
        waiting.dataset.mitAiWaiting = '';
        waiting.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Looking through past tickets and documents...';
        thread.append(mine, waiting);
        thread.scrollTop = thread.scrollHeight;
        question.value = '';
        const fields = { ask: '1', conversation_id: conversationId, question: text };
        if (!conversationId && ticketId && (!about || about.checked)) {
            fields.ticket_id = ticketId;
        }
        post(fields).then(({ ok, json }) => {
            mine.remove();
            waiting.remove();
            if (!ok) {
                const error = document.createElement('div');
                error.className = 'alert alert-warning small';
                error.dataset.mitAiError = '';
                error.textContent = json.error || 'The AI helper could not answer.';
                thread.appendChild(error);
                question.value = text;
                return;
            }
            conversationId = json.conversation_id;
            if (about) {
                about.closest('.form-check').hidden = true;
            }
            show(json.messages, false);
        }).catch(() => {
            waiting.textContent = 'The AI helper could not be reached.';
            question.value = text;
        }).finally(() => {
            busy = false;
            send.disabled = false;
            question.focus();
        });
    });

    question.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    thread.addEventListener('click', (event) => {
        const button = event.target.closest('[data-mit-ai-rate]');
        if (!button) {
            return;
        }
        const box = button.parentElement;
        const value = String(box.dataset.value) === button.dataset.mitAiRate ? 0 : Number(button.dataset.mitAiRate);
        post({ feedback: '1', message_id: box.dataset.messageId, value }).then(({ ok }) => {
            if (!ok) {
                return;
            }
            box.dataset.value = value;
            box.querySelectorAll('[data-mit-ai-rate] i').forEach((icon) => {
                const on = Number(icon.parentElement.dataset.mitAiRate) === value;
                icon.classList.toggle('fas', on);
                icon.classList.toggle('far', !on);
            });
        });
    });

    panel.querySelector('[data-mit-ai-new]').addEventListener('click', reset);

    panel.querySelector('[data-mit-ai-history]').addEventListener('click', () => {
        if (!list.hidden) {
            list.hidden = true;
            return;
        }
        get('list=1').then(({ ok, json }) => {
            if (!ok) {
                return;
            }
            list.innerHTML = json.conversations.length ? '<div class="list-group list-group-flush">' + json.conversations.map((c) => `<div class="list-group-item d-flex align-items-center gap-2" data-mit-ai-conversation="${c.id}">`
                + `<span class="flex-grow-1 text-truncate">${c.ticket_id ? '<i class="fas fa-life-ring text-muted me-1"></i>' : ''}${escape(c.title)}</span>`
                + `<small class="text-muted text-nowrap">${escape((c.updated_at || '').slice(0, 10))}</small>`
                + `<button type="button" class="btn btn-sm btn-link text-danger p-0" data-mit-ai-delete="${c.id}" title="Delete"><i class="fas fa-trash-alt"></i></button></div>`).join('') + '</div>'
                : '<div class="p-3 text-muted small">No earlier conversations.</div>';
            list.hidden = false;
        });
    });

    list.addEventListener('click', (event) => {
        const del = event.target.closest('[data-mit-ai-delete]');
        if (del) {
            event.stopPropagation();
            post({ delete: '1', conversation_id: del.dataset.mitAiDelete }).then(({ ok }) => {
                if (ok) {
                    del.closest('[data-mit-ai-conversation]').remove();
                    if (Number(del.dataset.mitAiDelete) === conversationId) {
                        reset();
                    }
                }
            });
            return;
        }
        const row = event.target.closest('[data-mit-ai-conversation]');
        if (row) {
            load(row.dataset.mitAiConversation);
        }
    });

    panel.addEventListener('shown.bs.offcanvas', () => question.focus());
    if (ticketLabel && about) {
        about.nextElementSibling.textContent = 'About this ticket (' + ticketLabel + ')';
    }
}());
