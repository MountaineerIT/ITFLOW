/*
 * Mountaineer IT - diagrams with draw.io (functions/mit_diagrams.php). Opens draw.io's own web app (/mit_drawio/, served from this server) in a
 * full-screen frame and speaks its embed protocol (postMessage, proto=json): load the diagram, and on Save export it as an editable PNG (the
 * picture with the diagram inside it - draw.io's xmlpng) or, for a .drawio file, take its XML.
 *
 *  - Files page: [data-mit-diagram-new] (Upload menu) asks for a name and makes a new diagram file; [data-mit-diagram-edit] (a diagram file's ⋮)
 *    opens it; [data-mit-diagram-restore] (Earlier versions window) puts one back. Saves go to agent/mit_diagrams_ajax.php.
 *  - The document editor (TinyMCE in a document form): a Diagram button inserts a new diagram or edits the one picked; a double-click on a diagram
 *    edits it. The picture goes into the document as <img class="mit-drawio" src="data:..."> - ITFlow's own save writes it to uploads/documents/.
 *  - A document's page: an "Edit diagram" button under each diagram saves straight to the document (document_save - the version before is kept).
 */
(function () {
    'use strict';

    var cfgEl = document.getElementById('mitDiagrams');
    if (!cfgEl) {
        return;
    }
    var cfg = { editor: cfgEl.dataset.editor, endpoint: cfgEl.dataset.endpoint, csrf: cfgEl.dataset.csrf };

    function notify(message, type) {
        if (typeof window.itflowToast === 'function') {
            window.itflowToast(message, type || 'info');
        } else {
            window.alert(message);
        }
    }

    function post(fields) {
        var body = new FormData();
        body.append('csrf_token', cfg.csrf);
        Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(cfg.endpoint, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
            return r.json().catch(function () { return { error: 'The server answered ' + r.status + '.' }; }).then(function (j) {
                if (!r.ok || j.error) {
                    throw new Error(j.error || ('The server answered ' + r.status + '.'));
                }
                return j;
            });
        });
    }

    /** An image's address as a data: address (draw.io loads a PNG that way). */
    function asDataUrl(src) {
        if (/^data:/i.test(src)) {
            return Promise.resolve(src);
        }
        return fetch(src, { credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) {
                throw new Error('The diagram could not be read (' + r.status + ').');
            }
            return r.blob();
        }).then(function (blob) {
            return new Promise(function (resolve, reject) {
                var reader = new FileReader();
                reader.onload = function () { resolve(reader.result); };
                reader.onerror = function () { reject(new Error('The diagram could not be read.')); };
                reader.readAsDataURL(blob);
            });
        });
    }

    var open = null;

    /*
     * Open the editor. opts: { kind: 'png' | 'xml', data: data: address / XML / '' (a new diagram), title, within: element to sit in (a modal,
     * so its focus trap leaves the frame alone), save: function (data) -> Promise, closed: function (saved) }. Our own bar over the frame has
     * Save, Save & close and Close (draw.io's own buttons hide in its compact layout); Ctrl+S in the editor saves too. Changes are followed through
     * draw.io's autosave messages, so Close asks before throwing them away.
     */
    function openEditor(opts) {
        if (open) {
            return;
        }
        var host = opts.within || document.body;
        var shade = document.createElement('div');
        shade.className = 'mit-drawio-shade';
        shade.setAttribute('data-mit-drawio-editor', '');
        shade.style.cssText = 'position:fixed;inset:0;z-index:2100;background:rgba(15,23,42,.55);display:flex;flex-direction:column;padding:1rem;';
        var bar = document.createElement('div');
        bar.style.cssText = 'display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:#212529;color:#fff;border-radius:10px 10px 0 0;';
        bar.innerHTML = '<i class="fas fa-project-diagram me-1"></i><strong class="me-auto text-truncate" data-mit-drawio-title></strong>'
            + '<span class="small text-white-50 me-2" data-mit-drawio-state></span>'
            + '<button type="button" class="btn btn-sm btn-primary" data-mit-drawio-save disabled><i class="fas fa-save me-1"></i>Save</button>'
            + '<button type="button" class="btn btn-sm btn-success" data-mit-drawio-save-close disabled><i class="fas fa-check me-1"></i>Save &amp; close</button>'
            + '<button type="button" class="btn btn-sm btn-light" data-mit-drawio-close><i class="fas fa-times me-1"></i>Close</button>';
        bar.querySelector('[data-mit-drawio-title]').textContent = opts.title || 'Diagram';
        var frame = document.createElement('iframe');
        frame.title = 'draw.io editor';
        frame.src = cfg.editor;
        frame.style.cssText = 'flex:1;border:0;border-radius:0 0 10px 10px;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.35);';
        shade.appendChild(bar);
        shade.appendChild(frame);
        host.appendChild(shade);
        var state = bar.querySelector('[data-mit-drawio-state]');
        var buttons = bar.querySelectorAll('[data-mit-drawio-save], [data-mit-drawio-save-close]');
        var saved = false;
        var dirty = !opts.data; // a new diagram is unsaved until it is saved
        var ready = false;
        var exiting = false;
        var busy = false;
        var xml = opts.kind === 'xml' ? (opts.data || '') : '';
        open = { shade: shade, frame: frame };

        function show() {
            buttons.forEach(function (b) { b.disabled = !ready || busy; });
            state.textContent = busy ? 'Saving...' : (dirty ? 'Not saved' : (saved ? 'Saved' : ''));
        }
        function send(message) {
            frame.contentWindow.postMessage(JSON.stringify(message), window.location.origin);
        }
        function close() {
            window.removeEventListener('message', listen);
            shade.remove();
            open = null;
            if (opts.closed) {
                opts.closed(saved);
            }
        }
        function store(data) {
            busy = true;
            show();
            Promise.resolve(opts.save(data)).then(function () {
                saved = true;
                busy = false;
                dirty = false;
                send({ action: 'status', message: 'Saved', modified: false });
                show();
                if (exiting) {
                    close();
                }
            }, function (err) {
                busy = false;
                exiting = false;
                show();
                notify('Not saved: ' + (err.message || String(err)), 'error');
            });
        }
        function startSave(andClose) {
            if (busy || !ready) {
                return;
            }
            exiting = !!andClose;
            if (opts.kind === 'xml') {
                store(xml);
            } else {
                send({ action: 'export', format: 'xmlpng', border: 10, spinKey: 'saving' }); // draw.io's own spinner (it skips an export while one shows)
            }
        }
        bar.querySelector('[data-mit-drawio-save]').addEventListener('click', function () { startSave(false); });
        bar.querySelector('[data-mit-drawio-save-close]').addEventListener('click', function () { startSave(true); });
        bar.querySelector('[data-mit-drawio-close]').addEventListener('click', function () {
            if (busy) {
                return;
            }
            if (dirty && !window.confirm('Close without saving your changes?')) {
                return;
            }
            close();
        });
        function listen(event) {
            if (event.source !== frame.contentWindow || event.origin !== window.location.origin || typeof event.data !== 'string') {
                return;
            }
            var msg;
            try {
                msg = JSON.parse(event.data);
            } catch (e) {
                return;
            }
            if (msg.event === 'init') {
                var load = { action: 'load', autosave: 1, modified: 'unsavedChanges', title: opts.title || 'Diagram' };
                if (opts.kind === 'png' && opts.data) {
                    load.xmlpng = opts.data;
                } else {
                    load.xml = opts.data || '';
                }
                send(load);
            } else if (msg.event === 'load') {
                ready = true;
                show();
            } else if (msg.event === 'autosave') {
                xml = msg.xml || xml;
                dirty = true;
                show();
            } else if (msg.event === 'save') {
                xml = msg.xml || xml;
                startSave(!!msg.exit);
            } else if (msg.event === 'export') {
                store(msg.data);
            } else if (msg.event === 'exit') {
                if (!dirty || window.confirm('Close without saving your changes?')) {
                    close();
                }
            }
        }
        window.addEventListener('message', listen);
        show();
    }

    /* Files ---------------------------------------------------------------------------------------------------------------------------- */

    function askName() {
        if (window.Swal) {
            return window.Swal.fire({
                title: 'New diagram', input: 'text', inputLabel: 'Name', inputPlaceholder: 'Network map', inputValue: 'Network map',
                showCancelButton: true, confirmButtonText: 'Open the editor',
                inputValidator: function (v) { return v && v.trim() ? null : 'Give it a name.'; }
            }).then(function (r) { return r.isConfirmed ? r.value.trim() : null; });
        }
        var name = window.prompt('Name of the new diagram', 'Network map');
        return Promise.resolve(name && name.trim() ? name.trim() : null);
    }

    document.addEventListener('click', function (event) {
        var make = event.target.closest('[data-mit-diagram-new]');
        if (make) {
            event.preventDefault();
            askName().then(function (name) {
                if (!name) {
                    return;
                }
                var fileId = 0;
                openEditor({
                    kind: 'png', data: '', title: name,
                    save: function (data) {
                        if (fileId) {
                            return post({ file_save: 1, file_id: fileId, data: data });
                        }
                        return post({ file_new: 1, client_id: make.dataset.clientId, folder_id: make.dataset.folderId || 0, name: name, data: data }).then(function (j) {
                            fileId = j.file_id;
                        });
                    },
                    closed: function (saved) {
                        if (saved) {
                            window.location.reload();
                        }
                    }
                });
            });
            return;
        }

        var edit = event.target.closest('[data-mit-diagram-edit]');
        if (edit) {
            event.preventDefault();
            var fileId = edit.dataset.fileId;
            fetch(cfg.endpoint + '?file=' + encodeURIComponent(fileId), { credentials: 'same-origin' }).then(function (r) {
                return r.json().then(function (j) {
                    if (!r.ok || j.error) {
                        throw new Error(j.error || 'The diagram could not be opened.');
                    }
                    return j;
                });
            }).then(function (j) {
                openEditor({
                    kind: j.kind, data: j.data, title: j.name,
                    save: function (data) { return post({ file_save: 1, file_id: fileId, data: data }); },
                    closed: function (saved) {
                        if (saved) {
                            window.location.reload();
                        }
                    }
                });
            }, function (err) { notify(err.message, 'error'); });
            return;
        }

        var restore = event.target.closest('[data-mit-diagram-restore]');
        if (restore) {
            event.preventDefault();
            if (!window.confirm('Put this version back? What the diagram holds now is kept as a version too.')) {
                return;
            }
            restore.disabled = true;
            post({ restore: 1, version_id: restore.getAttribute('data-mit-diagram-restore') }).then(function () {
                window.location.reload();
            }, function (err) {
                restore.disabled = false;
                notify(err.message, 'error');
            });
        }
    });

    /* The document editor -------------------------------------------------------------------------------------------------------------- */

    function isDiagram(node) {
        return !!(node && node.nodeName === 'IMG' && /(^|\s)mit-drawio(\s|$)/.test(node.className || ''));
    }

    function editInEditor(editor) {
        var node = editor.selection ? editor.selection.getNode() : null;
        var existing = isDiagram(node) ? node : null;
        var within = editor.getContainer() ? editor.getContainer().closest('.modal') : null;
        var start = existing ? asDataUrl(editor.dom.getAttrib(existing, 'src')) : Promise.resolve('');
        start.then(function (data) {
            openEditor({
                kind: 'png', data: data, title: 'Diagram', within: within,
                save: function (png) {
                    if (existing && existing.isConnected) {
                        editor.undoManager.transact(function () {
                            editor.dom.setAttrib(existing, 'src', png);
                            editor.dom.setAttrib(existing, 'data-mce-src', png);
                        });
                    } else {
                        editor.insertContent('<img class="mit-drawio" alt="Diagram" src="' + png + '">');
                        var imgs = editor.dom.select('img.mit-drawio');
                        existing = imgs.length ? imgs[imgs.length - 1] : null;
                    }
                    editor.setDirty(true);
                    editor.save();
                    return Promise.resolve();
                }
            });
        }, function (err) { notify(err.message, 'error'); });
    }

    function hookEditor(editor) {
        var el = editor.getElement ? editor.getElement() : null;
        var form = el ? el.closest('form') : null;
        if (!form || !form.querySelector('[name="add_document"], [name="edit_document"]')) {
            return;
        }
        editor.ui.registry.addButton('mitdiagram', {
            icon: 'image', text: 'Diagram', tooltip: 'Insert or edit a draw.io diagram',
            onAction: function () { editInEditor(editor); }
        });
        // The button sits on the toolbar through one line in ITFlow's js/app.js ('.tinymce' editors, when this script's #mitDiagrams is there);
        // editors outside document forms don't register it, so TinyMCE leaves it out there.
        editor.on('dblclick', function (e) {
            if (isDiagram(e.target)) {
                editor.selection.select(e.target);
                editInEditor(editor);
            }
        });
    }

    function watchEditors() {
        if (!window.tinymce || window.tinymce.mitDiagramsHooked) {
            return;
        }
        window.tinymce.mitDiagramsHooked = true;
        window.tinymce.on('AddEditor', function (added) { hookEditor(added.editor); });
    }
    watchEditors();
    document.addEventListener('DOMContentLoaded', watchEditors);

    /* A document's page ----------------------------------------------------------------------------------------------------------------- */

    function documentPage() {
        var body = document.querySelector('.itflow-document-body');
        var match = /[?&]document_id=(\d+)/.exec(window.location.search);
        if (!body || !match || !/\/agent\/document\.php$/.test(window.location.pathname)) {
            return;
        }
        var documentId = match[1];
        body.querySelectorAll('img.mit-drawio').forEach(function (img, index) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-primary d-print-none mt-1 mb-2';
            button.setAttribute('data-mit-diagram-doc-edit', String(index));
            button.innerHTML = '<i class="fas fa-project-diagram me-1"></i>Edit diagram';
            var holder = document.createElement('div');
            holder.appendChild(button);
            img.insertAdjacentElement('afterend', holder);
            button.addEventListener('click', function () {
                var src = img.getAttribute('src');
                asDataUrl(src).then(function (data) {
                    openEditor({
                        kind: 'png', data: data, title: 'Diagram',
                        save: function (png) {
                            return post({ document_save: 1, document_id: documentId, index: index, src: src, data: png }).then(function (j) {
                                src = j.src || src;
                            });
                        },
                        closed: function (saved) {
                            if (saved) {
                                window.location.reload();
                            }
                        }
                    });
                }, function (err) { notify(err.message, 'error'); });
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', documentPage);
    } else {
        documentPage();
    }

    window.mitDiagrams = { open: openEditor };
})();
