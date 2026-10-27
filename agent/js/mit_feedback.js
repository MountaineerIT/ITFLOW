// Mountaineer IT - the Bugs & ideas panel (functions/mit_feedback.php mitFeedbackNav, requests to agent/mit_feedback_ajax.php).
// Send a bug report or feature request (a screenshot pasted with Ctrl+V or picked), see every report, Open / Done; admins mark done,
// reopen and delete. Everything typed by people is drawn as text, never as HTML. ?mit_feedback=<id> (the bell's link) opens it.
(function () {
    'use strict';
    const panel = document.getElementById('mitFeedbackPanel');
    if (!panel || panel.dataset.ready) {
        return;
    }
    panel.dataset.ready = '1';
    document.body.appendChild(panel); // out of the navbar, so the offcanvas sits over the page
    panel.hidden = false;

    const endpoint = panel.dataset.endpoint;
    const csrf = panel.dataset.csrf;
    const admin = panel.dataset.admin === '1';
    const form = panel.querySelector('[data-mit-feedback-form]');
    const list = panel.querySelector('[data-mit-feedback-list]');
    const preview = panel.querySelector('[data-mit-feedback-preview]');
    const fileInput = form.querySelector('input[type=file]');
    const error = form.querySelector('[data-mit-feedback-error]');
    const send = form.querySelector('[data-mit-feedback-send]');
    const badge = document.querySelector('[data-mit-feedback-badge]');
    const openCount = panel.querySelector('[data-mit-feedback-count="open"]');
    const page = location.pathname + location.search;
    let tab = 'open';
    let shot = null; // the screenshot to send (a File or a pasted Blob)
    let highlight = 0;

    const style = document.createElement('style');
    style.textContent = `
        .mit-feedback-panel { --bs-offcanvas-width: min(34rem, 100vw); }
        .mit-feedback-item { border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .6rem .75rem; margin-bottom: .5rem; background: var(--bs-body-bg); }
        .mit-feedback-item.mit-feedback-hit { box-shadow: 0 0 0 .2rem var(--bs-primary-border-subtle); }
        .mit-feedback-item .mit-feedback-title { font-weight: 600; overflow-wrap: anywhere; }
        .mit-feedback-item .mit-feedback-details { white-space: pre-wrap; overflow-wrap: anywhere; font-size: .875rem; margin-top: .35rem; }
        .mit-feedback-item img { max-width: 100%; max-height: 14rem; border: 1px solid var(--bs-border-color); border-radius: .35rem; margin-top: .4rem; }
        .mit-feedback-note { background: var(--bs-success-bg-subtle); border-radius: .35rem; padding: .35rem .5rem; margin-top: .4rem; font-size: .85rem; white-space: pre-wrap; }
        [data-mit-feedback-preview] img { max-width: 100%; max-height: 10rem; border-radius: .35rem; border: 1px solid var(--bs-border-color); }
    `;
    document.head.appendChild(style);

    const escape = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const when = (value) => {
        if (!value) {
            return '';
        }
        const date = new Date(String(value).replace(' ', 'T'));
        return isNaN(date) ? value : date.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    };

    function post(fields) {
        const body = fields instanceof FormData ? fields : new FormData();
        if (!(fields instanceof FormData)) {
            Object.entries(fields).forEach(([k, v]) => body.append(k, v));
        }
        body.append('csrf_token', csrf);
        return fetch(endpoint, { method: 'POST', body }).then((r) => r.json().catch(() => ({})).then((json) => ({ ok: r.ok, json })));
    }

    function setBadge(open) {
        if (openCount) {
            openCount.textContent = open ? String(open) : '';
        }
        if (badge && admin) {
            badge.textContent = String(open);
            badge.hidden = !open;
        }
    }

    function itemHtml(item) {
        const kind = item.kind === 'feature'
            ? '<span class="badge text-bg-primary me-1"><i class="fas fa-lightbulb me-1"></i>Feature</span>'
            : '<span class="badge text-bg-danger me-1"><i class="fas fa-bug me-1"></i>Bug</span>';
        const pageLink = item.page ? ` · <a href="${escape(item.page)}" title="The page it was sent from">${escape(item.page.length > 40 ? item.page.slice(0, 40) + '...' : item.page)}</a>` : '';
        let actions = '';
        if (admin && item.status !== 'done' && tab === 'open') {
            actions += '<button type="button" class="btn btn-sm btn-success" data-act="done"><i class="fas fa-check me-1"></i>Mark done</button>';
        }
        if (admin && tab === 'done') {
            actions += '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="reopen"><i class="fas fa-undo me-1"></i>Reopen</button>';
        }
        if (admin || (item.mine && tab === 'open')) {
            actions += '<button type="button" class="btn btn-sm btn-outline-danger" data-act="delete" title="Delete"><i class="fas fa-trash"></i></button>';
        }
        const done = tab === 'done'
            ? `<div class="mit-feedback-note"><i class="fas fa-check-circle text-success me-1"></i>Done ${escape(when(item.done_at))}${item.done_by ? ' by ' + escape(item.done_by) : ''}${item.done_note ? '\n' + escape(item.done_note) : ''}</div>`
            : '';
        return `<div class="mit-feedback-item${item.id === highlight ? ' mit-feedback-hit' : ''}" data-id="${item.id}" data-mit-feedback-item>
            <div class="d-flex align-items-start gap-2"><div class="flex-grow-1">${kind}<span class="mit-feedback-title">${escape(item.title)}</span></div>
            <span class="text-muted small text-nowrap">#${item.id}</span></div>
            <div class="small text-muted">${escape(item.by)} · ${escape(when(item.at))}${pageLink}</div>
            ${item.details ? `<div class="mit-feedback-details">${escape(item.details)}</div>` : ''}
            ${item.image ? `<a href="${escape(item.image)}" target="_blank" rel="noopener"><img src="${escape(item.image)}" alt="Screenshot" loading="lazy"></a>` : ''}
            ${item.version ? `<div class="small text-muted mt-1">Version ${escape(item.version)}</div>` : ''}
            ${done}
            ${actions ? `<div class="d-flex gap-2 mt-2" data-mit-feedback-actions>${actions}</div>` : ''}
        </div>`;
    }

    function load() {
        return fetch(`${endpoint}?list=${tab}`).then((r) => r.json()).then((json) => {
            setBadge(json.open || 0);
            const items = json.items || [];
            list.innerHTML = items.length ? items.map(itemHtml).join('')
                : `<div class="text-muted small p-2">${tab === 'open' ? 'Nothing open - send a bug or an idea with New.' : 'Nothing marked done yet.'}</div>`;
            const hit = highlight && list.querySelector(`[data-id="${highlight}"]`);
            if (hit) {
                hit.scrollIntoView({ block: 'center' });
            }
            return items;
        }).catch(() => {
            list.innerHTML = '<div class="text-danger small p-2">Could not load the reports.</div>';
            return [];
        });
    }

    function showTab(name) {
        tab = name;
        panel.querySelectorAll('[data-mit-feedback-tab]').forEach((b) => b.classList.toggle('active', b.dataset.mitFeedbackTab === name));
        return load();
    }

    function setShot(file) {
        shot = file;
        if (!file) {
            preview.hidden = true;
            preview.innerHTML = '';
            fileInput.value = '';
            return;
        }
        const url = URL.createObjectURL(file);
        preview.innerHTML = `<div class="d-flex align-items-start gap-2"><img src="${url}" alt="Screenshot to send">`
            + '<button type="button" class="btn btn-sm btn-light" data-mit-feedback-unshot title="Leave the screenshot out"><i class="fas fa-times"></i></button></div>';
        preview.hidden = false;
    }

    function openForm(show) {
        form.hidden = !show;
        error.textContent = '';
        if (show) {
            panel.querySelector('[data-mit-feedback-where]').textContent = 'Sent with this page: ' + page;
            form.querySelector('[name=title]').focus();
        }
    }

    panel.addEventListener('show.bs.offcanvas', () => showTab(tab));
    panel.querySelector('[data-mit-feedback-new]').addEventListener('click', () => openForm(form.hidden));
    form.querySelector('[data-mit-feedback-cancel]').addEventListener('click', () => {
        form.reset();
        setShot(null);
        openForm(false);
    });
    panel.querySelectorAll('[data-mit-feedback-tab]').forEach((b) => b.addEventListener('click', () => showTab(b.dataset.mitFeedbackTab)));
    fileInput.addEventListener('change', () => setShot(fileInput.files[0] || null));
    preview.addEventListener('click', (e) => {
        if (e.target.closest('[data-mit-feedback-unshot]')) {
            setShot(null);
        }
    });
    // A screenshot pasted anywhere in the panel while the form is open
    panel.addEventListener('paste', (e) => {
        if (form.hidden) {
            return;
        }
        const file = [...(e.clipboardData?.items || [])].filter((i) => i.kind === 'file' && i.type.startsWith('image/')).map((i) => i.getAsFile())[0];
        if (file) {
            e.preventDefault();
            setShot(file);
        }
    });

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const data = new FormData();
        data.append('add', '1');
        data.append('kind', form.querySelector('[name=kind]:checked').value);
        data.append('title', form.querySelector('[name=title]').value);
        data.append('details', form.querySelector('[name=details]').value);
        data.append('page', page);
        if (shot) {
            data.append('image', shot, shot.name || 'screenshot.png');
        }
        send.disabled = true;
        error.textContent = '';
        post(data).then(({ ok, json }) => {
            send.disabled = false;
            if (!ok) {
                error.textContent = json.error || 'It could not be sent - try again.';
                return;
            }
            form.reset();
            setShot(null);
            openForm(false);
            highlight = json.id || 0;
            showTab('open');
        }).catch(() => {
            send.disabled = false;
            error.textContent = 'It could not be sent - try again.';
        });
    });

    list.addEventListener('click', (e) => {
        const button = e.target.closest('[data-act]');
        if (!button) {
            return;
        }
        const item = button.closest('[data-mit-feedback-item]');
        const id = item.dataset.id;
        const act = button.dataset.act;
        if (act === 'done') {
            // A note for the sender (optional), then done
            const box = item.querySelector('[data-mit-feedback-actions]');
            box.innerHTML = '<input type="text" class="form-control form-control-sm" maxlength="5000" placeholder="Note for the sender (optional)" data-mit-feedback-note>'
                + '<button type="button" class="btn btn-sm btn-success text-nowrap" data-act="done-save"><i class="fas fa-check me-1"></i>Done</button>';
            box.querySelector('input').focus();
            return;
        }
        if (act === 'delete' && !window.confirm('Delete this report?')) {
            return;
        }
        const fields = { id };
        if (act === 'done-save') {
            fields.done = '1';
            fields.note = item.querySelector('[data-mit-feedback-note]').value;
        } else {
            fields[act] = '1';
        }
        button.disabled = true;
        post(fields).then(({ ok, json }) => {
            if (!ok) {
                window.alert(json.error || 'That did not work.');
            }
            load();
        });
    });

    // The bell's link: ?mit_feedback=<id> opens the panel on that report
    const wanted = Number(new URLSearchParams(location.search).get('mit_feedback') || 0);
    if (wanted && window.bootstrap) {
        highlight = wanted;
        window.bootstrap.Offcanvas.getOrCreateInstance(panel).show();
        load().then((items) => {
            if (!items.some((i) => i.id === wanted)) {
                showTab('done');
            }
        });
    }
})();
