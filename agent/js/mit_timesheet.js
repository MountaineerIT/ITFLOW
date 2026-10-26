/*
 * Mountaineer IT - the timesheet page (agent/mit_timesheet.php, functions/mit_timesheet_view.php): draws the
 * week tabs and a row a day from #mitTsData, opens a day on the side to fill in - blocks of work (start - end, any number:
 * a meal break or a split shift between them) on California rules (the meal checks as mitTsCaAnalyze() does them on the server), ticket time, hours by charge
 * code with notes - and saves it as it is typed (agent/mit_timesheet_ajax.php), taking back what the server
 * worked out. Submitting waits for the last save; on California rules it opens the signing window first.
 */
(function () {
    const D = JSON.parse(document.getElementById('mitTsData').textContent);
    const hm = D.format === 'hm';
    const weeksEl = document.getElementById('mitTsWeeks');
    const daysEl = document.getElementById('mitTsDays');
    const panelEl = document.getElementById('mitTsDay');
    const panel = bootstrap.Offcanvas.getOrCreateInstance(panelEl);
    const body = panelEl.querySelector('[data-mit-day-body]');
    const savedEl = document.getElementById('mitTsSaved');
    const readOnly = D.locked !== '';
    const ISSUES = {
        1: { missing: 'No meal period - one is due for a day over 5 hours', late: 'Meal period started after the 5th hour', short: 'Meal period was under 30 minutes' },
        2: { missing: 'No 2nd meal period - one is due for a day over 10 hours', late: '2nd meal period started after the 10th hour', short: '2nd meal period was under 30 minutes' },
    };
    let week = Math.min(D.open_week || 0, D.weeks.length - 1);
    let current = null;      // the date open on the side
    let codesAtOpen = null;  // the hours on codes when it was opened - the lost time row goes down from there
    let timer = null;        // the save waiting for typing to stop
    let saving = null;       // the save on its way
    let again = false;       // something changed while saving
    let removed = new Set(); // codes taken off the open day

    // ---------- small helpers ----------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function hours(m, zero) {
        m = Math.round(m || 0);
        if (!m) return zero ? (hm ? '0:00' : '0') : '';
        return hm ? Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0') : String(+(m / 60).toFixed(2));
    }
    // "7.5", "7:30", "7h30", "450m" -> minutes, as mitTsParseHours() reads them; null when it cannot be read
    function parse(text) {
        text = String(text || '').trim().toLowerCase().replace(',', '.');
        if (text === '') return 0;
        let m = text.match(/^(\d{1,2}):(\d{1,2})$/) || text.match(/^(\d{1,2})h\s*(\d{1,2})?m?$/);
        if (m) return +(m[2] || 0) < 60 ? +m[1] * 60 + +(m[2] || 0) : null;
        m = text.match(/^(\d{1,4})\s*m(in)?$/);
        if (m) return +m[1];
        return text !== '.' && /^\d{0,2}(\.\d{1,2})?$/.test(text) ? Math.round(parseFloat(text) * 60) : null;
    }
    function clock(t) {
        if (!t) return '';
        const [h, m] = t.split(':').map(Number);
        return (h % 12 || 12) + ':' + String(m).padStart(2, '0') + (h < 12 ? ' AM' : ' PM');
    }
    function chip(color, label, m, tone) {
        return '<span class="mit-ts-chip' + (tone ? ' mit-ts-chip-' + tone : '') + '">' + (color ? '<i style="background:' + esc(color) + '"></i>' : '') + esc(label) + (m !== null ? ' <b>' + hours(m, true) + '</b>' : '') + '</span>';
    }

    // ---------- the meal rules, as on the server ----------
    // A day's blocks [{start, end, gap}] as minute pairs [start, end, gap] with times past midnight rolled over - the same
    // rules as mitTsCaParseBlocks() on the server. {list, error}: an empty list with the reason when the blocks don't read.
    const MAX_BLOCKS = 12;
    function read(blocks) {
        const rows = blocks.filter((b) => b.start || b.end);
        if (rows.some((b) => !b.start || !b.end)) return { list: [], error: 'Each block needs a start and an end' };
        if (rows.length > MAX_BLOCKS) return { list: [], error: 'At most ' + MAX_BLOCKS + ' blocks a day' };
        const minutes = (time) => { const [h, m] = time.split(':').map(Number); return h * 60 + m; };
        const list = [];
        let day = 0, first = null, lastEnd = null;
        for (const b of rows) {
            let start = minutes(b.start) + day * 1440;
            if (lastEnd !== null && start < lastEnd) {
                day++;
                start += 1440;
                if (start - lastEnd > 720) return { list: [], error: 'Blocks go in time order - ' + clock(b.start) + ' starts before the block above it ends' };
            }
            let end = minutes(b.end) + day * 1440;
            if (end === start) return { list: [], error: 'A block cannot start and end at the same time' };
            if (end < start) { day++; end += 1440; }
            first = first === null ? start : first;
            if (end - first > 1440 || day > 1) return { list: [], error: 'The day spans more than 24 hours' };
            list.push([start, end, list.length ? (b.gap === 'off' ? 'off' : 'meal') : '']);
            lastEnd = end;
        }
        return { list: list, error: '' };
    }
    function periods(blocks) {
        return read(blocks).list;
    }
    function analyze(blocks) {
        let worked = 0, lastEnd = null;
        const gaps = [];
        periods(blocks).forEach(function (p) {
            if (lastEnd !== null) gaps.push({ before: worked, minutes: Math.max(0, p[0] - lastEnd), kind: p[2] });
            worked += Math.max(0, p[1] - p[0]);
            lastEnd = p[1];
        });
        const meals = { 1: null, 2: null };
        let used = -1;
        [[1, 300], [2, 600]].forEach(function ([n, by]) {
            if (worked <= by) return;
            let status = 'missing';
            for (let i = used + 1; i < gaps.length; i++) {
                if (gaps[i].minutes >= 30 && gaps[i].before <= by) { status = 'ok'; used = i; break; }
            }
            if (status !== 'ok' && used + 1 < gaps.length) { used++; status = gaps[used].before <= by ? 'short' : 'late'; }
            meals[n] = status;
        });
        return { worked: worked, meals: meals, mealMinutes: gaps.reduce((s, g) => s + (g.kind === 'off' ? 0 : g.minutes), 0),
            offMinutes: gaps.reduce((s, g) => s + (g.kind === 'off' ? g.minutes : 0), 0) };
    }

    // ---------- the week tabs and the day rows ----------
    function weekTotal(dates) {
        return dates.reduce((s, d) => s + (D.days[d] ? D.days[d].total : 0), 0);
    }
    function drawWeeks() {
        weeksEl.innerHTML = D.weeks.map(function (dates, i) {
            const first = D.days[dates[0]], last = D.days[dates[dates.length - 1]];
            const label = first.label + (dates.length > 1 ? ' - ' + (last.label.split(' ')[0] === first.label.split(' ')[0] ? last.label.split(' ')[1] : last.label) : '');
            const total = weekTotal(dates);
            return '<li class="nav-item"><button type="button" class="nav-link' + (i === week ? ' active' : '') + '" data-week="' + i + '">' + esc(label)
                + '<span class="mit-ts-week-total">' + (total ? hours(total) + ' h' : '-') + '</span></button></li>';
        }).join('');
    }
    function dayRow(date) {
        const d = D.days[date];
        const parts = [];
        let times = '';
        const flags = [];
        if (d.ca) {
            const bl = d.ca.blocks || [];
            if (bl.length) {
                const a = analyze(bl);
                times = '<span class="mit-ts-times">' + clock(bl[0].start) + ' - ' + clock(bl[bl.length - 1].end) + '</span>'
                    + (a.mealMinutes || !a.offMinutes ? '<span class="text-body-secondary"> · ' + (a.mealMinutes ? a.mealMinutes + ' min meal' : 'no meal') + '</span>' : '')
                    + (a.offMinutes ? '<span class="text-body-secondary"> · split shift, ' + hours(a.offMinutes) + ' h off</span>' : '');
            }
            [1, 2].forEach(function (n) {
                const s = d.ca.meals[n];
                if (s && s !== 'ok') {
                    const a = d.ca.answers['meal' + n];
                    flags.push(a ? chip('', a === 'chose' ? 'Meal ' + (n === 2 ? '2 ' : '') + s + ' - chose' : 'Meal premium', null, a === 'chose' ? 'muted' : 'danger')
                        : chip('', 'Meal ' + (n === 2 ? '2 ' : '') + s + ' - say why', null, 'danger'));
                }
            });
            if (d.ca.answers.rest_missed) flags.push(chip('', 'Rest premium', null, 'danger'));
        } else if (d.rest_missed) {
            flags.push(chip('', 'Rest break missed', null, 'warning'));
        }
        // All time logged on tickets as one - the billable part is in the summary at the top
        if (d.tickets.billable + d.tickets.other) parts.push(chip('#198754', 'Ticket time', d.tickets.billable + d.tickets.other));
        Object.entries(d.codes).forEach(function ([id, e]) {
            const c = D.codes[id] || { name: 'Code ' + id, color: '#6c757d' };
            if (e.m) parts.push(chip(c.color, c.name, e.m));
        });
        if (d.break_paid) parts.push(chip('#20c997', 'Paid breaks', d.break_paid));
        if (d.ca && d.ca.worked) {
            // On California rules the times are the hours worked - more than that on tickets and work codes is a mistake somewhere
            const spent = d.tickets.billable + d.tickets.other + Object.entries(d.codes).reduce((s, [id, e]) => s + ((D.codes[id] || {}).kind === 'leave' ? 0 : e.m), 0);
            if (spent > d.ca.worked) flags.push(chip('', 'Codes over hours worked', null, 'warning'));
        }
        Object.entries(d.filled || {}).forEach(function ([id, m]) {
            const c = D.codes[id] || { name: 'Code ' + id, color: '#6c757d' };
            if (m) parts.push(chip(c.color, c.name + ' (not on a code)', m));
        });
        if (d.unallocated && (parts.length || d.ca)) parts.push(chip('', 'Not on a code', d.unallocated, 'muted'));
        if (d.auto_lost) flags.push(chip('', 'Lost time (auto)', d.auto_lost, 'warning'));
        if (d.under && !d.auto_lost) flags.push(chip('', 'Under ' + hours(d.expected) + ' h', null, 'warning'));
        // Ticket entries that clash: two at once, or outside the clocked work times
        const overlaps = (d.entries || []).filter((e) => e.overlap).length;
        const outside = (d.entries || []).filter((e) => e.outside).length;
        if (overlaps) flags.push(chip('', 'Ticket times overlap', null, 'warning'));
        if (outside) flags.push(chip('', outside + ' ticket ' + (outside === 1 ? 'entry' : 'entries') + ' outside your times', null, 'warning'));
        const empty = !times && !parts.length;
        const off = !d.expected;
        return '<button type="button" class="list-group-item list-group-item-action mit-ts-day' + (d.today ? ' is-today' : '') + (off ? ' is-off' : '') + (date === current ? ' active-day' : '') + '" data-date="' + date + '">'
            + '<div class="mit-ts-day-date"><div class="mit-ts-dow">' + d.dow + '</div><div class="mit-ts-dnum">' + esc(d.label) + '</div></div>'
            + '<div class="mit-ts-day-main">'
            + (d.closure ? '<span class="badge text-bg-success-subtle text-success-emphasis me-2">' + esc(d.closure) + '</span>' : '')
            + (times ? '<div class="mb-1">' + times + '</div>' : '')
            + (parts.length || flags.length ? '<div class="mit-ts-chips">' + parts.join('') + flags.join('') + '</div>' : '')
            + (empty && !d.closure ? '<span class="text-body-tertiary small">' + (d.future ? '' : (readOnly ? 'Nothing logged' : (off ? 'Day off' : 'Add your time'))) + '</span>' : '')
            + '</div>'
            + '<div class="mit-ts-day-total">' + (d.total ? hours(d.total) : '<span class="text-body-tertiary">-</span>') + '</div>'
            + '<i class="fas fa-chevron-right text-body-tertiary ms-2"></i></button>';
    }
    function drawDays() {
        daysEl.innerHTML = D.weeks[week].map(dayRow).join('');
        const total = weekTotal(D.weeks[week]);
        daysEl.insertAdjacentHTML('beforeend', '<div class="list-group-item mit-ts-week-foot"><span>Week total</span><b>' + hours(total, true) + ' h</b></div>');
    }
    function draw() {
        drawWeeks();
        drawDays();
    }

    // ---------- one day, on the side ----------
    function usableCodes(date, have) {
        const d = D.days[date];
        return Object.entries(D.codes).filter(function ([id, c]) {
            if (!c.usable || have.has(id)) return false;
            if (d.future && D.future === 'none') return false;
            if (d.future && D.future === 'leave') return c.kind === 'leave';
            return true;
        });
    }
    function codeRow(id, e) {
        const c = D.codes[id] || { name: 'Code ' + id, color: '#6c757d', note: 0 };
        const dis = readOnly ? ' disabled' : '';
        return '<div class="mit-ts-code" data-code="' + id + '">'
            + '<div class="d-flex align-items-center gap-2"><span class="mit-ts-dot" style="background:' + esc(c.color) + '"></span>'
            + '<span class="flex-grow-1">' + esc(c.name) + (c.paid ? '' : ' <span class="badge text-bg-light border">Unpaid</span>') + (c.kind === 'leave' ? ' <span class="badge text-bg-light border">Leave</span>' : '') + '</span>'
            + '<input type="text" inputmode="decimal" class="form-control form-control-sm text-end mit-ts-hours" data-hours value="' + hours(e.m) + '" placeholder="0" aria-label="' + esc(c.name) + ' hours"' + dis + '>'
            + (readOnly ? '' : '<button type="button" class="btn btn-sm btn-link text-body-secondary px-1" data-note-toggle title="Note"><i class="far fa-comment-dots"></i></button>'
                + '<button type="button" class="btn btn-sm btn-link text-body-secondary px-1" data-remove title="Take off"><i class="fas fa-times"></i></button>') + '</div>'
            // The note shows when there is one, or the code needs one; otherwise behind the note button
            + '<input type="text" class="form-control form-control-sm mt-1" data-note maxlength="255" value="' + esc(e.note) + '" placeholder="' + (c.note ? 'Note (needed)' : 'Note') + '"' + dis + (e.note || c.note ? '' : ' hidden') + '>'
            + '</div>';
    }
    function openDay(date) {
        flush();
        current = date;
        codesAtOpen = null;
        removed = new Set();
        const d = D.days[date];
        panelEl.querySelector('#mitTsDayTitle').textContent = d.long;
        panelEl.querySelector('[data-mit-day-sub]').textContent = (d.closure ? d.closure + ' · ' : '') + (d.expected ? 'Normal day ' + hours(d.expected) + ' h' : 'Day off');
        let html = '';
        const dis = readOnly ? ' disabled' : '';
        if (d.ca) {
            const bl = (d.ca.blocks || []).length ? d.ca.blocks : [{ start: '', end: '', gap: '' }];
            const timeDis = readOnly || (d.future && !(d.ca.blocks || []).length) ? ' disabled' : '';
            html += '<section class="mb-4"><h6 class="mit-ts-section">Work times</h6><div data-blocks>' + bl.map((b, i) => blockRow(b, i, timeDis)).join('') + '</div>'
                + (readOnly || timeDis ? '' : '<button type="button" class="btn btn-sm btn-link px-0" data-add-block><i class="fas fa-plus me-1"></i>Add another block <span class="text-body-secondary">(after a meal, or a split shift)</span></button>')
                + '<div class="d-flex justify-content-between align-items-center mt-2 small"><span class="text-body-secondary">Hours worked</span><b data-worked></b></div>'
                + '<div data-issues></div>'
                + '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" data-answer="rest_missed" id="mitTsRest"' + (d.ca.answers.rest_missed ? ' checked' : '') + (readOnly || !D.own ? ' disabled' : '') + '>'
                + '<label class="form-check-label small" for="mitTsRest">I could not take a rest break this day</label></div>'
                + (!D.own && !readOnly ? '<div class="mt-3"><label class="form-label small mb-1">Why are you changing their times? <span class="text-danger">*</span></label><input type="text" class="form-control form-control-sm" data-reason maxlength="255" placeholder="They see this and are asked to acknowledge it"></div>' : '')
                + '</section>';
        }
        html += '<section><h6 class="mit-ts-section">' + (d.ca ? 'What the time was spent on' : 'Hours') + '</h6>';
        if (d.tickets.billable || d.tickets.other) {
            html += '<a href="#" class="mit-ts-auto ajax-modal" data-modal-url="modals/mit_timesheet/day.php?user_id=' + D.user_id + '&date=' + date + '">'
                + '<span>' + chip('#198754', 'Ticket time', d.tickets.billable + d.tickets.other) + '</span>'
                + '<span class="small ms-auto">The tickets <i class="fas fa-external-link-alt"></i></span></a>';
        }
        // Each ticket entry with when it was worked (ticket notes' times) - its times can be put right here
        html += entriesHtml(d);
        html += '<div data-codes>' + Object.entries(d.codes).map(([id, e]) => codeRow(id, e)).join('') + '</div>';
        // Unaccounted time, as a row with the codes so it is plain what the day is charged with - it shrinks as time is added
        if (d.auto_lost) {
            html += '<div class="mit-ts-code mit-ts-code-auto" data-auto-lost>'
                + '<div class="d-flex align-items-center gap-2"><span class="mit-ts-dot" style="background:#ffc107"></span>'
                + '<span class="flex-grow-1">Lost time <span class="badge text-bg-warning">Automatic</span>' + (D.lost_paid ? '' : ' <span class="badge text-bg-light border">Unpaid</span>')
                + '<span class="d-block small text-body-secondary">The part of the normal day not on ticket time or a code - it goes down as you add time</span></span>'
                + '<span class="fw-semibold mit-ts-auto-hours" data-auto-lost-hours>' + hours(d.auto_lost) + '</span></div></div>';
        }
        if (!readOnly) {
            html += '<select class="form-select form-select-sm mt-2" data-add aria-label="Add time to a code"></select>';
        }
        if (d.break_paid) html += '<div class="small text-body-secondary mt-2"><i class="fas fa-coffee me-1"></i>Paid breaks added for you: ' + hours(d.break_paid) + ' h</div>';
        // Short rest breaks are part of the hours logged - the person says when they could not take one (California days ask above)
        if (!d.ca && !d.future) {
            html += '<div class="form-check mt-3" data-mit-rest-any><input class="form-check-input" type="checkbox" data-answer="rest_missed" id="mitTsRestAny"' + (d.rest_missed ? ' checked' : '') + (readOnly || !D.own ? ' disabled' : '') + '>'
                + '<label class="form-check-label small" for="mitTsRestAny">I could not take a rest break this day</label></div>';
        }
        html += '</section>';
        body.innerHTML = html;
        fillAdd();
        live();
        status(readOnly ? '<i class="fas fa-lock me-1"></i>Read only' : '');
        draw();
        panel.show();
    }
    function entryRow(e, i) {
        const when = e.start ? esc(e.start) + ' - ' + esc(e.end) : '<span class="text-body-tertiary" title="This note has no times - it counts as ending when it was posted">no times</span>';
        const warn = (e.overlap ? '<span class="badge text-bg-warning ms-1">Overlaps</span>' : '') + (e.outside ? '<span class="badge text-bg-warning ms-1">Outside your times</span>' : '');
        const name = e.url ? '<a href="' + esc(e.url) + '" target="_blank" rel="noopener">' + esc(e.ticket) + '</a>' : esc(e.ticket);
        const edit = !readOnly && e.editable;
        return '<li class="d-flex gap-2 py-1 border-bottom align-items-center' + (e.overlap || e.outside ? ' mit-ts-entry-warn' : '') + '" data-entry="' + i + '">'
            + (edit ? '<button type="button" class="btn btn-link btn-sm p-0 text-nowrap font-monospace text-reset text-decoration-none" data-entry-edit title="Change the times">' + when + ' <i class="fas fa-pencil-alt small text-body-tertiary"></i></button>'
                : '<span class="text-nowrap font-monospace">' + when + '</span>')
            + '<span class="flex-grow-1 text-truncate">' + name + warn + '</span><span class="text-nowrap">' + hours(e.minutes) + '</span></li>';
    }
    function entriesHtml(d) {
        if (!(d.entries || []).length) return '';
        return '<ul class="list-unstyled small mb-3 mit-ts-entries" data-ts-entries>' + d.entries.map(entryRow).join('') + '</ul>';
    }
    // A ticket entry's start and end, typed in its row; "After the one before" starts it where the entry before it ends
    function entryForm(li) {
        const i = +li.dataset.entry;
        const e = D.days[current].entries[i];
        const before = D.days[current].entries.slice(0, i).filter((x) => x.end).pop();
        const after = before && (!e.start || e.start < before.end) ? before.end : '';
        li.innerHTML = '<div class="w-100" data-entry-form>'
            + '<div class="text-truncate mb-1">' + esc(e.ticket) + '</div>'
            + '<div class="d-flex flex-wrap align-items-center gap-1">'
            + '<input type="time" class="form-control form-control-sm" style="width: 7.5rem" data-entry-start value="' + esc(e.start || '') + '" aria-label="Start">'
            + '<span>-</span><input type="time" class="form-control form-control-sm" style="width: 7.5rem" data-entry-end value="' + esc(e.end || '') + '" aria-label="End">'
            + '<button type="button" class="btn btn-sm btn-primary" data-entry-save>Save</button>'
            + '<button type="button" class="btn btn-sm btn-light" data-entry-cancel>Cancel</button></div>'
            + (after ? '<button type="button" class="btn btn-link btn-sm px-0" data-entry-after="' + esc(after) + '">Start it at ' + esc(after) + ', after the one before (same length)</button>' : '')
            + '<div class="small text-body-secondary">The note on the ticket changes too - its time follows the start and end.</div>'
            + '<div class="small text-danger" data-entry-error></div></div>';
        li.querySelector('[data-entry-start]').focus();
    }
    function saveEntry(li) {
        const e = D.days[current].entries[+li.dataset.entry];
        const start = li.querySelector('[data-entry-start]').value;
        const end = li.querySelector('[data-entry-end]').value;
        const error = li.querySelector('[data-entry-error]');
        if (!start || !end) { error.textContent = 'Type a start and an end.'; return; }
        const date = current;
        const form = new FormData();
        form.append('save_entry', '1');
        form.append('csrf_token', D.csrf);
        form.append('user_id', D.user_id);
        form.append('start', D.start);
        form.append('date', date);
        form.append('reply_id', e.reply_id);
        form.append('work_start', start);
        form.append('work_end', end);
        li.querySelectorAll('button, input').forEach((el) => { el.disabled = true; });
        // Hours typed on codes go first, then the panel is drawn again from what the server worked out
        flush().then(() => fetch('mit_timesheet_ajax.php', { method: 'POST', body: form, credentials: 'same-origin' }))
            .then((r) => r.json())
            .then(function (r) {
                if (r.error) throw new Error(r.error);
                if (!r.ok) throw new Error(r.message);
                D.days[date] = r.day;
                document.getElementById('mitTsSummary').innerHTML = r.summary;
                document.getElementById('mitTsLeave').innerHTML = r.leave;
                if (D.ca) {
                    document.getElementById('mitTsChanges').innerHTML = r.changes;
                    D.problems = r.problems;
                    const list = document.querySelector('[data-mit-ca-problems]');
                    if (list) list.innerHTML = problemsHtml();
                }
                if (current === date) openDay(date);
                const at = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                status('<i class="fas fa-check-circle text-success me-1"></i>Ticket time saved ' + at);
                topStatus('<i class="fas fa-check-circle text-success me-1"></i>All changes saved');
            })
            .catch(function (x) {
                li.querySelectorAll('button, input').forEach((el) => { el.disabled = false; });
                error.textContent = x.message || 'Could not save - check your connection';
            });
    }
    function fillAdd() {
        const add = body.querySelector('[data-add]');
        if (!add) return;
        const have = new Set([...body.querySelectorAll('[data-code]')].map((r) => r.dataset.code));
        const options = usableCodes(current, have);
        add.innerHTML = '<option value="">+ Add time to...</option>' + options.map(([id, c]) => '<option value="' + id + '">' + esc(c.name) + '</option>').join('');
        add.hidden = !options.length;
    }
    // What the day adds up to as it is typed - the server's figures replace it after each save
    function live() {
        const d = D.days[current];
        let codes = 0;
        body.querySelectorAll('[data-code]').forEach(function (row) {
            const m = parse(row.querySelector('[data-hours]').value);
            const c = D.codes[row.dataset.code] || {};
            row.querySelector('[data-hours]').classList.toggle('is-invalid', m === null || (c.max > 0 && m > c.max));
            if (m && (!d.ca || c.kind === 'leave')) codes += m;
        });
        let total;
        if (d.ca) {
            const a = analyze(blocks());
            body.querySelector('[data-worked]').textContent = a.worked ? hours(a.worked, true) + ' h' : '-';
            const issues = body.querySelector('[data-issues]');
            issues.innerHTML = [1, 2].map(function (n) {
                const s = a.meals[n];
                if (!s || s === 'ok') return '';
                const given = d.ca.answers['meal' + n] || '';
                return '<div class="mit-ts-issue mt-2"><div class="small mb-1"><i class="fas fa-exclamation-triangle me-1"></i>' + ISSUES[n][s] + '</div>'
                    + '<select class="form-select form-select-sm' + (given ? '' : ' is-invalid') + '" data-answer="meal' + n + '"' + (readOnly || !D.own ? ' disabled' : '') + '>'
                    + '<option value="">Why? - choose</option>' + Object.entries(D.answers).map(([v, l]) => '<option value="' + v + '"' + (v === given ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div>';
            }).join('');
            total = a.worked + codes;
        } else {
            total = d.tickets.billable + d.tickets.other + codes + d.break_paid;
        }
        // Lost time goes down by what has been added since the day was opened (the server's figure replaces it after a save)
        const lostRow = body.querySelector('[data-auto-lost]');
        if (lostRow) {
            if (codesAtOpen === null) codesAtOpen = codes;
            const lost = Math.max(0, d.auto_lost - Math.max(0, codes - codesAtOpen));
            lostRow.querySelector('[data-auto-lost-hours]').textContent = hours(lost);
            lostRow.hidden = !lost;
            total += lost; // as in the day's row (lost time is in the day's hours, paid or not)
        }
        panelEl.querySelector('[data-mit-day-total]').textContent = hours(total, true) + ' h';
    }
    // One block of work: Start - End, and before every block but the first, what the gap was
    function blockRow(b, i, dis) {
        return '<div class="mit-ts-block" data-block>'
            + (i ? '<div class="d-flex align-items-center gap-2 my-2 small"><span class="text-body-secondary">Then</span>'
                + '<select class="form-select form-select-sm w-auto" data-gap aria-label="The time before this block"' + dis + '>'
                + '<option value="meal"' + (b.gap !== 'off' ? ' selected' : '') + '>a meal break</option>'
                + '<option value="off"' + (b.gap === 'off' ? ' selected' : '') + '>off between shifts (split shift)</option></select></div>' : '')
            + '<div class="row g-2 align-items-end"><div class="col"><label class="form-label small mb-1">Start</label><input type="time" step="60" class="form-control" data-block-start value="' + esc(b.start) + '"' + dis + '></div>'
            + '<div class="col"><label class="form-label small mb-1">End</label><input type="time" step="60" class="form-control" data-block-end value="' + esc(b.end) + '"' + dis + '></div>'
            + (dis ? '' : '<div class="col-auto"><button type="button" class="btn btn-link text-body-secondary px-1" data-remove-block title="Take this block off"><i class="fas fa-times"></i></button></div>')
            + '</div></div>';
    }
    function blocks() {
        return [...body.querySelectorAll('[data-block]')].map(function (row) {
            const gap = row.querySelector('[data-gap]');
            return { start: row.querySelector('[data-block-start]').value, end: row.querySelector('[data-block-end]').value, gap: gap ? gap.value : '' };
        });
    }
    function status(html, tone) {
        const el = panelEl.querySelector('[data-mit-day-status]');
        el.innerHTML = html;
        el.className = 'small me-auto ' + (tone === 'error' ? 'text-danger' : 'text-body-secondary');
    }
    function topStatus(html) {
        savedEl.innerHTML = html;
    }

    // ---------- saving ----------
    function changed() {
        if (readOnly || !current) return;
        live();
        clearTimeout(timer);
        topStatus('<i class="fas fa-circle-notch fa-spin me-1"></i>Saving...');
        status('Saving...');
        timer = setTimeout(save, 800);
    }
    function problem() {
        const bad = [...body.querySelectorAll('[data-hours].is-invalid')][0];
        if (bad) return 'Type hours as e.g. 7.5 or 7:30' + (D.codes[bad.closest('[data-code]').dataset.code].max ? ' (at most ' + hours(D.codes[bad.closest('[data-code]').dataset.code].max) + ' a day)' : '');
        const note = [...body.querySelectorAll('[data-code]')].find(function (row) {
            return D.codes[row.dataset.code]?.note && parse(row.querySelector('[data-hours]').value) > 0 && !row.querySelector('[data-note]').value.trim();
        });
        if (note) return D.codes[note.dataset.code].name + ' needs a note';
        const bl = D.days[current].ca ? blocks() : [];
        const problem = read(bl).error;
        if (problem) return problem;
        const reason = body.querySelector('[data-reason]');
        const filled = (list) => JSON.stringify(list.filter((b) => b.start || b.end).map((b, i) => [b.start, b.end, i ? (b.gap === 'off' ? 'off' : 'meal') : '']));
        if (reason && !reason.value.trim() && filled(bl) !== filled(D.days[current].ca.blocks || [])) return 'Say why you are changing their times';
        return '';
    }
    function save() {
        clearTimeout(timer);
        timer = null;
        if (!current) return Promise.resolve();
        if (saving) { again = true; return saving; }
        const why = problem();
        if (why) {
            status('<i class="fas fa-exclamation-circle me-1"></i>' + esc(why) + ' - not saved yet', 'error');
            topStatus('<i class="fas fa-exclamation-circle text-danger me-1"></i>Not saved - ' + esc(why));
            return Promise.resolve();
        }
        const date = current;
        const form = new FormData();
        form.append('save_day', '1');
        form.append('csrf_token', D.csrf);
        form.append('user_id', D.user_id);
        form.append('start', D.start);
        form.append('date', date);
        if (D.days[date].ca) {
            blocks().forEach(function (b, i) {
                form.append('times[blocks][' + i + '][start]', b.start);
                form.append('times[blocks][' + i + '][end]', b.end);
                form.append('times[blocks][' + i + '][gap]', b.gap);
            });
            body.querySelectorAll('[data-answer]').forEach(function (el) {
                form.append('answers[' + el.dataset.answer + ']', el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value);
            });
            const reason = body.querySelector('[data-reason]');
            if (reason) form.append('reason', reason.value);
        } else {
            const rest = body.querySelector('[data-mit-rest-any] [data-answer]');
            if (rest && !rest.disabled) form.append('answers[rest_missed]', rest.checked ? '1' : '');
        }
        body.querySelectorAll('[data-code]').forEach(function (row) {
            form.append('cells[' + row.dataset.code + ']', row.querySelector('[data-hours]').value);
            form.append('notes[' + row.dataset.code + ']', row.querySelector('[data-note]').value);
        });
        removed.forEach((id) => form.append('cells[' + id + ']', ''));
        saving = fetch('mit_timesheet_ajax.php', { method: 'POST', body: form, credentials: 'same-origin' })
            .then((r) => r.json())
            .then(function (r) {
                if (r.error) throw new Error(r.error);
                if (r.day) D.days[date] = r.day;
                removed = new Set();
                document.getElementById('mitTsSummary').innerHTML = r.summary;
                document.getElementById('mitTsLeave').innerHTML = r.leave;
                if (D.ca) {
                    document.getElementById('mitTsChanges').innerHTML = r.changes;
                    D.problems = r.problems;
                    const list = document.querySelector('[data-mit-ca-problems]');
                    if (list) list.innerHTML = problemsHtml();
                }
                draw();
                const at = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                if (r.ok) {
                    status('<i class="fas fa-check-circle text-success me-1"></i>Saved ' + at);
                    topStatus('<i class="fas fa-check-circle text-success me-1"></i>All changes saved');
                    if (current === date && D.days[date].ca) live();
                } else {
                    status('<i class="fas fa-exclamation-circle me-1"></i>' + esc(r.message), 'error');
                    topStatus('<i class="fas fa-exclamation-circle text-danger me-1"></i>Not saved - ' + esc(r.message));
                }
            })
            .catch(function (e) {
                status('<i class="fas fa-exclamation-circle me-1"></i>' + esc(e.message || 'Could not save - check your connection'), 'error');
                topStatus('<i class="fas fa-exclamation-circle text-danger me-1"></i>Not saved');
            })
            .finally(function () {
                saving = null;
                if (again) { again = false; return save(); }
            });
        return saving;
    }
    // Save now what is waiting (before leaving a day or submitting)
    function flush() {
        if (timer) return save();
        return saving || Promise.resolve();
    }
    function problemsHtml() {
        if (!D.problems.length) return '';
        return '<div class="alert alert-warning small"><strong>Before you can submit:</strong><ul class="mb-0">' + D.problems.map((p) => '<li>' + esc(p) + '</li>').join('') + '</ul></div>';
    }

    // ---------- events ----------
    weeksEl.addEventListener('click', function (e) {
        const b = e.target.closest('[data-week]');
        if (!b) return;
        week = +b.dataset.week;
        draw();
    });
    daysEl.addEventListener('click', function (e) {
        const row = e.target.closest('[data-date]');
        if (row) openDay(row.dataset.date);
    });
    panelEl.querySelectorAll('[data-mit-day-step]').forEach(function (b) {
        b.addEventListener('click', function () {
            const all = D.weeks.flat();
            const i = all.indexOf(current) + +b.dataset.mitDayStep;
            if (i < 0 || i >= all.length) return;
            week = D.weeks.findIndex((w) => w.includes(all[i]));
            openDay(all[i]);
        });
    });
    panelEl.addEventListener('hide.bs.offcanvas', function () {
        flush();
    });
    panelEl.addEventListener('hidden.bs.offcanvas', function () {
        current = null;
        draw();
    });
    body.addEventListener('keydown', function (e) {
        const li = e.target.closest('[data-entry-form]') && e.target.closest('[data-entry]');
        if (!li) return;
        if (e.key === 'Enter') { e.preventDefault(); saveEntry(li); }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); li.outerHTML = entryRow(D.days[current].entries[+li.dataset.entry], +li.dataset.entry); }
    });
    body.addEventListener('input', function (e) {
        if (e.target.matches('[data-hours], [data-note], [data-block-start], [data-block-end], [data-reason]')) changed();
    });
    body.addEventListener('change', function (e) {
        if (e.target.matches('[data-gap]')) changed();
        if (e.target.matches('[data-answer]')) {
            if (e.target.dataset.answer !== 'rest_missed') {
                D.days[current].ca.answers[e.target.dataset.answer] = e.target.value;
                e.target.classList.toggle('is-invalid', e.target.value === '');
            }
            changed();
        }
        if (e.target.matches('[data-add]') && e.target.value) {
            const id = e.target.value;
            removed.delete(id);
            body.querySelector('[data-codes]').insertAdjacentHTML('beforeend', codeRow(id, { m: 0, note: '' }));
            body.querySelector('[data-code="' + id + '"] [data-hours]').focus();
            fillAdd();
        }
    });
    body.addEventListener('click', function (e) {
        if (e.target.closest('[data-entry-edit]')) entryForm(e.target.closest('[data-entry]'));
        if (e.target.closest('[data-entry-cancel]')) {
            const li = e.target.closest('[data-entry]');
            li.outerHTML = entryRow(D.days[current].entries[+li.dataset.entry], +li.dataset.entry);
        }
        if (e.target.closest('[data-entry-save]')) saveEntry(e.target.closest('[data-entry]'));
        if (e.target.closest('[data-entry-after]')) {
            const li = e.target.closest('[data-entry]');
            const from = e.target.closest('[data-entry-after]').dataset.entryAfter;
            const m = D.days[current].entries[+li.dataset.entry].minutes;
            const [h, mm] = from.split(':').map(Number);
            const to = (h * 60 + mm + m) % 1440;
            li.querySelector('[data-entry-start]').value = from;
            li.querySelector('[data-entry-end]').value = String(Math.floor(to / 60)).padStart(2, '0') + ':' + String(to % 60).padStart(2, '0');
        }
        if (e.target.closest('[data-remove]')) {
            const row = e.target.closest('[data-code]');
            removed.add(row.dataset.code);
            row.remove();
            fillAdd();
            changed();
        }
        if (e.target.closest('[data-note-toggle]')) {
            const note = e.target.closest('[data-code]').querySelector('[data-note]');
            note.hidden = false;
            note.focus();
        }
        if (e.target.closest('[data-add-block]')) {
            const list = body.querySelector('[data-blocks]');
            if (list.children.length >= MAX_BLOCKS) return;
            list.insertAdjacentHTML('beforeend', blockRow({ start: '', end: '', gap: 'meal' }, list.children.length, ''));
            list.lastElementChild.querySelector('[data-block-start]').focus();
        }
        if (e.target.closest('[data-remove-block]')) {
            const list = body.querySelector('[data-blocks]');
            const row = e.target.closest('[data-block]');
            if (list.children.length > 1) {
                row.remove();
                // The first block has no gap before it
                const first = list.firstElementChild.querySelector('[data-gap]');
                if (first) first.closest('.d-flex').remove();
            } else {
                row.querySelectorAll('input').forEach((input) => { input.value = ''; });
            }
            changed();
        }
    });
    window.addEventListener('beforeunload', function (e) {
        if (timer || saving) {
            flush();
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Submitting: the last save first; on California rules, the signing window
    const form = document.getElementById('mitTsForm');
    const sign = document.getElementById('mitCaSign');
    if (sign) {
        // The window lives in the fixed bottom bar, whose own stacking layer would put the grey backdrop over it:
        // it moves to the page body, and its fields stay part of the form through their form attribute
        sign.querySelectorAll('input, textarea, select, button[type=submit]').forEach((el) => el.setAttribute('form', form.id));
        document.body.appendChild(sign);
    }
    form.addEventListener('submit', function (e) {
        const by = e.submitter;
        if (!by || !by.matches('[data-mit-ts-submit]') || form.dataset.ready === '1') return;
        e.preventDefault();
        flush().then(function () {
            if (sign) {
                const list = sign.querySelector('[data-mit-ca-problems]');
                if (list) list.innerHTML = problemsHtml();
                bootstrap.Modal.getOrCreateInstance(sign).show();
            } else {
                form.dataset.ready = '1';
                form.requestSubmit(by);
            }
        });
    });

    draw();
})();
