/**
 * tasks-ui.js — Nawara Tasks interactive engine (shared by index.php CPM
 * and tasks.php Nawara Tasks).
 *
 * Renders list/board views, task detail with role-aware action buttons
 * (capabilities come from the SERVER — tasks_api.php — this file only mirrors
 * them), create/edit forms, checklist, comments, attachments with in-browser
 * image compression, and the activity timeline.
 *
 * Standalone by design: works with or without script.js on the page.
 */
(function () {
    'use strict';

    /* ------------------------------ helpers ------------------------------ */
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    if (!window.NAWARA_CSRF_TOKEN) {
        const meta = document.querySelector('meta[name="nawara-csrf-token"]');
        const inp = document.querySelector('input[name="csrf_token"]');
        window.NAWARA_CSRF_TOKEN = meta ? meta.content : (inp ? inp.value : '');
    }

    function toast(msg, type = 'inf') {
        let box = $('toastBox');
        if (!box) {
            box = document.createElement('div');
            box.id = 'toastBox';
            document.body.appendChild(box);
        }
        const icons = { ok: '✅', err: '❌', inf: 'ℹ️' };
        const el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.innerHTML = '<span>' + (icons[type] || icons.inf) + '</span>'
            + '<span class="toast-msg">' + esc(msg) + '</span>'
            + '<button class="toast-cls" type="button" data-action="tk-toast-close">✕</button>';
        box.appendChild(el);
        setTimeout(() => el.remove(), 4600);
    }

    async function api(url, opts = {}) {
        const request = { ...opts };
        const method = (request.method || 'GET').toUpperCase();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            const headers = new Headers(request.headers || {});
            if (window.NAWARA_CSRF_TOKEN) headers.set('X-CSRF-Token', window.NAWARA_CSRF_TOKEN);
            request.headers = headers;
        }
        const res = await fetch(url, request);
        const data = await res.json();
        if (data && data.auth === false) { window.location.href = 'index.php'; return data; }
        return data;
    }

    const STATUS_LABEL = {
        draft: 'Draft', assigned: 'Assigned', in_progress: 'In Progress',
        blocked: 'Blocked', submitted_for_review: 'In Review',
        revision_requested: 'Revision', awaiting_client_approval: 'Client Approval',
        completed: 'Completed', cancelled: 'Cancelled',
    };
    const STATUS_ICON = {
        draft: '📝', assigned: '📌', in_progress: '🔨', blocked: '⛔',
        submitted_for_review: '👀', revision_requested: '🔁',
        awaiting_client_approval: '🤝', completed: '✅', cancelled: '🚫',
    };
    const BOARD_COLS = [
        { key: 'blocked', title: '⛔ Blocked' },
        { key: 'submitted_for_review', title: '👀 In Review' },
        { key: 'revision_requested', title: '🔁 Revision' },
        { key: 'awaiting_client_approval', title: '🤝 Client Approval' },
        { key: 'in_progress', title: '🔨 In Progress' },
        { key: 'assigned', title: '📌 Assigned' },
        { key: 'completed', title: '✅ Completed' },
    ];
    const PRIORITY_ICON = { critical: '🔴', high: '🟠', medium: '🟡', low: '🟢' };

    /* ------------------------------- state ------------------------------- */
    const TK = {
        projects: [], projectId: 'all', status: '', q: '', view: 'list',
        tasks: [], detail: null, editing: null, assignable: [],
        blockArmed: false, ready: false, meta: null,
    };

    async function ensureMeta() {
        if (TK.meta) return TK.meta;
        try {
            const d = await api('load_projects.php?meta=1');
            if (d.success && d.meta) TK.meta = d.meta;
        } catch (e) { /* graceful */ }
        return TK.meta;
    }

    function fillSectionSelect(selected) {
        const sel = $('tkFSection');
        if (!sel) return;
        const sections = (TK.meta && TK.meta.sections) || [];
        sel.innerHTML = '<option value="">— none —</option>'
            + sections.map((sec) => `<option value="${sec.id}">${esc(sec.icon || '')} ${esc(sec.name)}</option>`).join('');
        sel.value = selected ? String(selected) : '';
        fillItemSelect(sel.value, null);
    }

    function fillItemSelect(sectionId, selectedItem) {
        const sel = $('tkFItem');
        if (!sel) return;
        const sections = (TK.meta && TK.meta.sections) || [];
        const section = sections.find((sec) => String(sec.id) === String(sectionId));
        const items = section ? (section.items || []) : [];
        sel.innerHTML = '<option value="">— none —</option>'
            + items.map((it) => `<option value="${it.id}">${esc(it.name)} (${esc(it.weight)}%)</option>`).join('');
        sel.disabled = items.length === 0;
        if (selectedItem) {
            const has = [...sel.options].some((o) => o.value === String(selectedItem));
            if (has) sel.value = String(selectedItem);
        }
    }

    const isEmployeeSurface = () => document.body && document.body.dataset.role === 'employee';

    /* ------------------------------ loading ------------------------------ */
    async function ensureProjects() {
        if (TK.projects.length) return TK.projects;
        if (Array.isArray(window.PROJECTS) && window.PROJECTS.length) {
            TK.projects = window.PROJECTS.map((p) => ({ id: Number(p.id), name: p.project_name }));
            return TK.projects;
        }
        try {
            const d = await api('load_projects.php');
            TK.projects = (d.projects || []).map((p) => ({ id: Number(p.id), name: p.project_name }));
        } catch (e) { /* keep empty */ }
        return TK.projects;
    }

    function populateProjectFilter() {
        const sel = $('tkProject');
        if (!sel) return;
        const prev = TK.projectId;
        sel.innerHTML = '<option value="all">All my projects</option>'
            + TK.projects.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
        if ([...sel.options].some((o) => o.value === String(prev))) sel.value = String(prev);
        else { TK.projectId = 'all'; sel.value = 'all'; }
    }

    async function loadTasks() {
        await ensureProjects();
        populateProjectFilter();
        const targets = TK.projectId === 'all'
            ? TK.projects.map((p) => p.id)
            : [Number(TK.projectId)];
        if (!targets.length) { TK.tasks = []; renderAll(); return; }
        try {
            const results = await Promise.all(targets.map((pid) =>
                api('tasks_api.php?action=list&project_id=' + pid)));
            const seen = new Set();
            TK.tasks = [];
            results.forEach((d) => {
                (d.tasks || []).forEach((t) => {
                    if (!seen.has(t.id)) { seen.add(t.id); TK.tasks.push(t); }
                });
            });
        } catch (e) {
            toast('Could not load tasks', 'err');
        }
        renderAll();
    }

    function visibleTasks() {
        const q = TK.q.trim().toLowerCase();
        return TK.tasks.filter((t) => {
            if (TK.status && t.status !== TK.status) return false;
            if (q && !(String(t.title).toLowerCase().includes(q)
                || String(t.description || '').toLowerCase().includes(q))) return false;
            return true;
        });
    }

    /* ------------------------------ renders ------------------------------ */
    function renderStats() {
        const box = $('tkStats');
        if (!box) return;
        const all = TK.tasks;
        const cnt = (s) => all.filter((t) => t.status === s).length;
        const mine = all.length;
        const cells = [
            ['Total', mine, '#6366f1'],
            ['In Progress', cnt('in_progress'), '#2563eb'],
            ['Blocked', cnt('blocked'), '#dc2626'],
            ['In Review', cnt('submitted_for_review'), '#d97706'],
            ['Client', cnt('awaiting_client_approval'), '#7c3aed'],
            ['Completed', cnt('completed'), '#059669'],
        ];
        box.innerHTML = cells.map(([k, v, c]) =>
            `<div class="pri-stat-card" style="border-color:${c}33;"><div style="color:${c};font-size:1.3rem;font-weight:900;">${v}</div><div class="tk-muted">${k}</div></div>`
        ).join('')
            // Bare score card: number only, no label (stage-5 scope).
            + `<div class="pri-stat-card tk-score-card" title="score"><div style="font-size:1.3rem;font-weight:900;color:#0f172a;" id="tkMyScore">…</div></div>`;
        loadMyScore();
    }

    let myScoreLoaded = false;
    async function loadMyScore() {
        if (myScoreLoaded) return;
        myScoreLoaded = true;
        try {
            const d = await api('employee_api.php?action=my_score');
            const el = $('tkMyScore');
            if (el && d && typeof d.score === 'number') {
                el.textContent = String(d.score);
            }
        } catch (e) { /* silent: bare card stays … */ }
    }

    function taskRowHTML(t, idx) {
        const status = STATUS_LABEL[t.status] || t.status;
        const assignees = (t.assignees || []).map((a) => a.name).join(', ');
        const due = t.due_at ? `📅 ${esc(t.due_at)}` : '';
        const overdue = t.due_at && t.status !== 'completed' && t.status !== 'cancelled'
            && t.due_at < new Date().toISOString().slice(0, 10);
        return `
        <div class="pri-task-card ${t.status === 'completed' ? 'pri-task-done' : ''}" data-action="tk-open" data-id="${t.id}" role="button" tabindex="0">
            <div class="tk-row-status" title="${esc(status)}">${STATUS_ICON[t.status] || '•'}</div>
            <div class="tk-num">#${idx + 1}</div>
            <div class="pri-task-body">
                <div class="pri-task-title">${esc(t.title)}</div>
                <div class="pri-task-meta">
                    <span class="tk-chip tk-chip-${esc(t.status)}">${esc(status)}</span>
                    <span class="pri-badge pri-badge-${esc(t.priority)}">${PRIORITY_ICON[t.priority] || ''} ${esc(t.priority)}</span>
                    ${assignees ? `<span class="pri-assignee">👤 ${esc(assignees)}</span>` : '<span class="tk-muted">👤 unassigned</span>'}
                    ${due ? `<span class="${overdue ? 'pri-overdue' : 'pri-due'}">${due}${overdue ? ' (overdue)' : ''}</span>` : ''}
                    ${Number(t.review_required) ? '<span class="tk-flag">⚖ review</span>' : ''}
                    ${Number(t.client_approval_required) ? '<span class="tk-flag">🤝 client</span>' : ''}
                    ${t.project_name ? `<span class="tk-muted">📦 ${esc(t.project_name)}</span>` : ''}
                </div>
            </div>
            <div class="pri-task-actions"><span class="btn btn-sm btn-soft" aria-hidden="true">Open</span></div>
        </div>`;
    }

    function renderList() {
        const list = $('tkList');
        const board = $('tkBoard');
        if (!list || !board) return;
        const tasks = visibleTasks();
        list.style.display = TK.view === 'list' ? '' : 'none';
        board.style.display = TK.view === 'board' ? '' : 'none';
        if (TK.view === 'list') {
            list.innerHTML = tasks.length
                ? tasks.map((t, i) => taskRowHTML(t, i)).join('')
                : '<div class="files-empty">No tasks match the current filters.</div>';
            return;
        }
        board.innerHTML = BOARD_COLS.map((col) => {
            const items = tasks.filter((t) => t.status === col.key
                || (col.key === 'assigned' && t.status === 'draft'));
            return `
            <div class="tk-col">
                <h4><span>${col.title}</span><span>${items.length}</span></h4>
                <div class="tk-col-cards">
                    ${items.map((t) => `
                        <div class="tk-card" data-action="tk-open" data-id="${t.id}" role="button" tabindex="0">
                            <div class="tk-card-title">${esc(t.title)}</div>
                            <div class="pri-task-meta">
                                <span class="pri-badge pri-badge-${esc(t.priority)}">${PRIORITY_ICON[t.priority] || ''} ${esc(t.priority)}</span>
                                ${(t.assignees || []).length ? `<span class="pri-assignee">👤 ${esc(t.assignees[0].name)}</span>` : ''}
                                ${t.due_at ? `<span class="pri-due">📅 ${esc(t.due_at)}</span>` : ''}
                            </div>
                        </div>`).join('') || '<div class="tk-muted">—</div>'}
                </div>
            </div>`;
        }).join('');
    }

    function renderAll() { renderStats(); renderList(); }

    /* ------------------------------ detail ------------------------------- */
    async function openDetail(id) {
        try {
            const d = await api('tasks_api.php?action=get&id=' + Number(id));
            if (!d.success) { toast(d.error || 'Task not found', 'err'); return; }
            TK.detail = d;
            TK.blockArmed = false;
            renderDetail();
            openPanel('taskDetailPanel');
        } catch (e) { toast('Could not open task', 'err'); }
    }

    function cap(detail) { return detail.capabilities || {}; }

    function renderDetail() {
        const d = TK.detail;
        if (!d) return;
        const t = d.task;
        const c = cap(d);
        const statusLabel = STATUS_LABEL[t.status] || t.status;

        $('tkDetailTitle').textContent = t.title;
        $('tkDetailSub').textContent = (t.project_name || '') + ' · #' + t.id;
        $('tkDetailChips').innerHTML =
            `<span class="tk-chip tk-chip-${esc(t.status)}">${esc(statusLabel)}</span>
             <span class="pri-badge pri-badge-${esc(t.priority)}">${PRIORITY_ICON[t.priority] || ''} ${esc(t.priority)}</span>
             ${Number(t.review_required) ? '<span class="tk-flag">⚖ Review required</span>' : ''}
             ${Number(t.affects_project_progress) ? `<span class="tk-flag">📊 ${esc(String(t.progress_weight))}% weight</span>` : ''}`;

        const assigneeNames = (t.assignees || []).map((a) =>
            `${esc(a.name)} <span class="tk-muted">(${esc(a.role)}${a.member_status ? ' · ' + esc(a.member_status) : ''})</span>`).join('<br>') || '<span class="tk-muted">Unassigned (draft)</span>';
        const clientCell = Number(t.client_approval_required) || Number(t.client_visible)
            ? `<div class="tk-meta-cell"><div class="k">Client</div><div class="v">${Number(t.client_visible) ? 'Visible' : 'Internal'} · ${esc(t.client_approval_status || 'not_requested')}</div></div>`
            : '';
        $('tkDetailMeta').innerHTML = `
            <div class="tk-meta-cell"><div class="k">Status</div><div class="v">${esc(statusLabel)}</div></div>
            <div class="tk-meta-cell"><div class="k">Mode</div><div class="v">${esc(String(t.assignment_mode).replace('_', ' '))}</div></div>
            <div class="tk-meta-cell"><div class="k">Assignees</div><div class="v">${assigneeNames}</div></div>
            <div class="tk-meta-cell"><div class="k">Due</div><div class="v">${t.due_at ? esc(t.due_at) : '—'}</div></div>
            <div class="tk-meta-cell"><div class="k">Start</div><div class="v">${t.start_at ? esc(t.start_at) : '—'}</div></div>
            ${clientCell}`;

        renderActions(d);
        $('tkDetailDesc').textContent = t.description || '—';

        // checklist
        const chk = d.checklist || [];
        $('tkChecklist').innerHTML = chk.length ? chk.map((item) => `
            <div class="tk-check-row">
                <input type="checkbox" ${Number(item.is_done) ? 'checked' : ''} ${c.can_work || c.can_edit ? '' : 'disabled'}
                       data-action="tk-chk-toggle" data-item="${item.id}">
                <span class="${Number(item.is_done) ? 'done' : ''}" style="flex:1;">${esc(item.title)}</span>
                ${c.can_edit ? `<button class="tk-x" type="button" data-action="tk-chk-remove" data-item="${item.id}" title="Remove">✕</button>` : ''}
            </div>`).join('') : '<div class="tk-muted">No checklist items.</div>';
        $('tkChecklistAdd').style.display = c.can_edit ? '' : 'none';

        // comments (client sees only own visibility — server enforces)
        const comments = d.comments || [];
        $('tkComments').innerHTML = comments.length ? comments.map((cm) => `
            <div class="tk-comment-row">
                <strong>${esc(cm.author_name || '—')}</strong>
                <span style="flex:1;">${esc(cm.body)}</span>
                <span class="tk-muted">${esc((cm.created_at || '').slice(0, 16))}${cm.visibility === 'client' ? ' 🤝' : ''}</span>
            </div>`).join('') : '<div class="tk-muted">No comments yet.</div>';

        // block reason selector visibility
        $('tkBlockWrap').style.display = TK.blockArmed ? '' : 'none';

        // attachments
        const files = d.attachments || [];
        $('tkFiles').innerHTML = files.length ? files.map((f) => `
            <div class="tk-file-row">
                <span>${Number(f.content_type || '').toString().startsWith && /image/.test(f.content_type) ? '🖼' : '📄'}</span>
                <span style="flex:1;">${esc(f.original_name)} <span class="tk-muted">${esc(f.visibility)} · ${Math.round(Number(f.byte_size) / 1024)} KB</span></span>
                <button class="btn btn-sm btn-soft" type="button" data-action="tk-download" data-file="${f.id}">⬇</button>
                ${canDeleteFile(f, c) ? `<button class="btn btn-sm btn-del" type="button" data-action="tk-file-delete" data-file="${f.id}">🗑</button>` : ''}
            </div>`).join('') : '<div class="tk-muted">No files yet.</div>';

        // activity
        const acts = d.activity || [];
        $('tkActivity').innerHTML = acts.length ? acts.map((a) => `
            <div class="tk-act-row">
                <span style="flex:1;"><strong>${esc(a.actor_name || 'system')}</strong> — ${esc(a.event_type)}${a.new_value ? ` <span class="tk-muted">${esc(String(a.new_value).slice(0, 90))}</span>` : ''}</span>
                <span class="tk-muted">${esc((a.created_at || '').slice(0, 16))}</span>
            </div>`).join('') : '<div class="tk-muted">No activity yet.</div>';
    }

    function canDeleteFile(f) {
        const me = Number((document.body && document.body.dataset.userId) || window.NAWARA_USER_ID || 0);
        return me > 0 && Number(f.uploaded_by_user_id) === me;
    }

    function renderActions(d) {
        const t = d.task;
        const c = cap(d);
        const box = $('tkDetailActions');
        const btn = (action, label, cls = 'btn-primary', extra = '') =>
            `<button class="btn btn-sm ${cls}" type="button" data-action="${action}" ${extra}>${label}</button>`;
        const parts = [];

        if (d.isClient && t.status === 'awaiting_client_approval') {
            parts.push(btn('tk-client-decision', '✅ Approve', 'btn-primary', 'data-decision="approve"'));
            parts.push(btn('tk-client-decision', '🔁 Request Changes', 'btn-soft', 'data-decision="request_changes"'));
        } else {
            if (c.can_work && (t.status === 'assigned')) parts.push(btn('tk-transition', '▶ Start', 'btn-primary', 'data-to="in_progress"'));
            if (c.can_unblock) parts.push(btn('tk-transition', '▶ Resume', 'btn-primary', 'data-to="in_progress"'));
            if (c.can_resume) parts.push(btn('tk-transition', '▶ Resume from revision', 'btn-primary', 'data-to="in_progress"'));
            if (c.can_block) parts.push(btn('tk-block-arm', '⛔ Block', 'btn-del'));
            if (c.can_submit) parts.push(btn('tk-transition', '📤 Submit for Review', 'btn-primary', 'data-to="submitted_for_review"'));
            if (c.can_complete_direct) parts.push(btn('tk-transition', '✔ Mark Completed', 'btn-primary', 'data-to="completed"'));
            if (c.can_review) {
                if (Number(t.client_approval_required) === 1 && t.status === 'submitted_for_review') {
                    parts.push(btn('tk-transition', '✅ Approve & Send to Client', 'btn-primary', 'data-to="awaiting_client_approval"'));
                    parts.push(btn('tk-transition', '🔁 Request Revision', 'btn-soft', 'data-to="revision_requested"'));
                } else {
                    parts.push(btn('tk-transition', '✅ Approve', 'btn-primary', 'data-to="completed"'));
                    parts.push(btn('tk-transition', '🔁 Request Revision', 'btn-soft', 'data-to="revision_requested"'));
                }
            }
            if (c.can_cancel) parts.push(btn('tk-transition', '🗑 Cancel Task', 'btn-del', 'data-to="cancelled"'));
        }
        const closed = t.status === 'completed' || t.status === 'cancelled';
        if ((c.can_edit || c.can_assign) && !closed) parts.push(btn('tk-edit', '✏ Edit', 'btn-soft'));

        if (TK.blockArmed) {
            parts.push(btn('tk-block-confirm', '✔ Confirm Block', 'btn-del', 'data-to="blocked"'));
            parts.push(btn('tk-block-cancel', '✕', 'btn-secondary'));
        }

        box.innerHTML = parts.join('') || '<span class="tk-muted">No actions available for your role on this task.</span>';
        $('tkBlockWrap').style.display = TK.blockArmed ? '' : 'none';
    }

    /* ---------------------------- transitions ---------------------------- */
    async function doTransition(to) {
        const d = TK.detail;
        if (!d) return;
        const t = d.task;
        const payload = { action: 'transition', id: t.id, to, version: t.version };
        if (to === 'blocked') payload.reason = ($('tkBlockReason') || {}).value || 'other';
        const comment = ($('tkCommentBody') || {}).value ? $('tkCommentBody').value.trim() : '';
        if (to === 'revision_requested' && comment) payload.comment = comment;
        if (to === 'submitted_for_review' && comment) {
            await api('tasks_api.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'add_comment', id: t.id, body: comment }),
            });
            $('tkCommentBody').value = '';
        }
        try {
            const res = await fetch('tasks_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NAWARA_CSRF_TOKEN || '' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (!data.success) {
                toast(data.error || 'Transition failed', 'err');
                if (res.status === 409) { await loadTasks(); closePanel('taskDetailPanel'); }
                return;
            }
            if (data.partial) {
                toast(`Part submitted — waiting for ${data.pending_members} more member(s).`, 'inf');
            } else {
                toast(`Moved to ${STATUS_LABEL[data.to] || data.to}`, 'ok');
            }
            if ($('tkCommentBody')) $('tkCommentBody').value = '';
            TK.blockArmed = false;
            await openDetail(t.id);
            await loadTasks();
        } catch (e) { toast('Transition failed', 'err'); }
    }

    async function doClientDecision(decision) {
        const d = TK.detail;
        if (!d) return;
        const comment = ($('tkCommentBody') || {}).value ? $('tkCommentBody').value.trim() : '';
        try {
            const res = await fetch('tasks_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NAWARA_CSRF_TOKEN || '' },
                body: JSON.stringify({ action: 'client_decision', id: d.task.id, decision, comment }),
            });
            const data = await res.json();
            if (!data.success) { toast(data.error || 'Decision failed', 'err'); return; }
            toast(decision === 'approve' ? 'Approved ✅' : 'Changes requested 🔁', 'ok');
            if ($('tkCommentBody')) $('tkCommentBody').value = '';
            await openDetail(d.task.id);
            await loadTasks();
        } catch (e) { toast('Decision failed', 'err'); }
    }

    /* ------------------------- checklist / comments ---------------------- */
    async function afterWrite(refreshTask = true) {
        if (refreshTask && TK.detail) await openDetail(TK.detail.task.id);
        await loadTasks();
    }

    async function checklistToggle(itemId) {
        if (!TK.detail) return;
        try {
            const d = await api('tasks_api.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'checklist_toggle', id: TK.detail.task.id, item_id: itemId }),
            });
            if (!d.success) { toast(d.error || 'Checklist update failed', 'err'); return; }
            await afterWrite(true);
        } catch (e) { toast('Checklist update failed', 'err'); }
    }

    async function checklistAdd() {
        const input = $('tkChecklistInput');
        const title = input ? input.value.trim() : '';
        if (!title || !TK.detail) { toast('Write an item first', 'err'); return; }
        const d = await api('tasks_api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'checklist_add', id: TK.detail.task.id, title }),
        });
        if (!d.success) { toast(d.error || 'Could not add item', 'err'); return; }
        input.value = '';
        await afterWrite(true);
    }

    async function checklistRemove(itemId) {
        if (!TK.detail) return;
        const d = await api('tasks_api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'checklist_remove', id: TK.detail.task.id, item_id: itemId }),
        });
        if (!d.success) { toast(d.error || 'Could not remove item', 'err'); return; }
        await afterWrite(true);
    }

    async function addComment() {
        const box = $('tkCommentBody');
        const body = box ? box.value.trim() : '';
        if (!body || !TK.detail) { toast('Write a comment first', 'err'); return; }
        const d = await api('tasks_api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_comment', id: TK.detail.task.id, body }),
        });
        if (!d.success) { toast(d.error || 'Comment not allowed', 'err'); return; }
        box.value = '';
        toast('Comment added', 'ok');
        await afterWrite(true);
    }

    /* ---------------------------- attachments ---------------------------- */
    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const fr = new FileReader();
            fr.onload = () => resolve(String(fr.result).split(',')[1]);
            fr.onerror = reject;
            fr.readAsDataURL(file);
        });
    }

    /** Compress bitmap images in the browser before upload (canvas). */
    async function compressImage(file) {
        const keepAsIs = ['image/gif', 'application/pdf'];
        if (!file.type.startsWith('image/') || keepAsIs.includes(file.type)) {
            const b64 = await fileToBase64(file);
            return { b64, type: file.type, name: file.name };
        }
        try {
            const url = URL.createObjectURL(file);
            const img = await new Promise((resolve, reject) => {
                const im = new Image();
                im.onload = () => resolve(im);
                im.onerror = reject;
                im.src = url;
            });
            const maxDim = 1600;
            const scale = Math.min(1, maxDim / Math.max(img.naturalWidth, img.naturalHeight));
            const w = Math.max(1, Math.round(img.naturalWidth * scale));
            const h = Math.max(1, Math.round(img.naturalHeight * scale));
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, w, h);
            URL.revokeObjectURL(url);
            const outType = 'image/jpeg';
            const dataUrl = canvas.toDataURL(outType, 0.8);
            const b64 = dataUrl.split(',')[1];
            const bytes = Math.round(b64.length * 0.75);
            // keep the compressed copy only when it is actually smaller
            if (bytes > 0 && bytes < file.size * 0.95) {
                const ext = file.name.replace(/\.[^.]+$/, '');
                return { b64, type: outType, name: ext + '.jpg', compressed: true };
            }
        } catch (e) { /* fall back to original bytes */ }
        const b64 = await fileToBase64(file);
        return { b64, type: file.type, name: file.name };
    }

    async function uploadFile() {
        const input = $('tkFileInput');
        if (!input || !input.files || !input.files[0]) { toast('Choose a file first', 'err'); return; }
        if (!TK.detail) return;
        const file = input.files[0];
        if (file.size > 10 * 1024 * 1024) { toast('File exceeds 10 MB', 'err'); return; }
        try {
            const prepared = await compressImage(file);
            const d = await api('tasks_api.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'attachment_upload', id: TK.detail.task.id,
                    filename: prepared.name, content_type: prepared.type, data: prepared.b64,
                    visibility: 'internal',
                }),
            });
            if (!d.success) { toast(d.error || 'Upload failed', 'err'); return; }
            toast(prepared.compressed ? 'Uploaded (compressed in browser)' : 'Uploaded', 'ok');
            input.value = '';
            await afterWrite(true);
        } catch (e) { toast('Upload failed', 'err'); }
    }

    async function downloadFile(fileId) {
        try {
            const res = await fetch('tasks_api.php?action=attachment&id=' + Number(fileId));
            const d = await res.json();
            if (!d.success) { toast(d.error || 'Download refused', 'err'); return; }
            const a = document.createElement('a');
            a.href = 'data:' + (d.content_type || 'application/octet-stream') + ';base64,' + d.data;
            a.download = d.name || 'attachment';
            document.body.appendChild(a);
            a.click();
            a.remove();
        } catch (e) { toast('Download failed', 'err'); }
    }

    async function deleteFile(fileId) {
        if (!TK.detail || !confirm('Delete this attachment?')) return;
        const d = await api('tasks_api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'attachment_delete', id: TK.detail.task.id, attachment_id: Number(fileId) }),
        });
        if (!d.success) { toast(d.error || 'Delete failed', 'err'); return; }
        toast('Attachment removed', 'ok');
        await afterWrite(true);
    }

    /* ------------------------------- form -------------------------------- */
    async function loadAssignable(projectId) {
        if (!projectId) { TK.assignable = []; fillAssigneeSelects(); return; }
        try {
            const d = await api('tasks_api.php?action=assignable_users&project_id=' + Number(projectId));
            TK.assignable = d.users || [];
        } catch (e) { TK.assignable = []; }
        fillAssigneeSelects();
    }

    function fillAssigneeSelects(selected = {}) {
        const opts = ['<option value="">— none —</option>']
            .concat(TK.assignable.map((u) => `<option value="${u.id}">${esc(u.name)}</option>`)).join('');
        const multiOpts = TK.assignable.map((u) => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
        const a = $('tkFAssignee'); if (a) { a.innerHTML = opts; a.value = selected.responsible || ''; }
        const c = $('tkFContributors'); if (c) { c.innerHTML = multiOpts; (selected.contributors || []).forEach((id) => { const o = [...c.options].find((x) => x.value === String(id)); if (o) o.selected = true; }); }
        const r = $('tkFReviewers'); if (r) { r.innerHTML = multiOpts; (selected.reviewers || []).forEach((id) => { const o = [...r.options].find((x) => x.value === String(id)); if (o) o.selected = true; }); }
    }

    function syncFormMode() {
        const mode = ($('tkFMode') || {}).value || 'single';
        const contrib = $('tkFContribWrap'); if (contrib) contrib.style.display = mode === 'single' ? 'none' : '';
        const prog = $('tkFProgress'); const weight = $('tkFWeightWrap');
        if (weight) weight.style.display = prog && prog.checked ? '' : 'none';
        const approval = $('tkFClientApproval'); const visible = $('tkFClientVisible');
        if (approval && approval.checked && visible) visible.checked = true;
        if (visible && !visible.checked && approval) approval.checked = false;
    }

    async function openForm(task = null) {
        await Promise.all([ensureProjects(), ensureMeta()]);
        TK.editing = task;
        $('tkFormTitle').textContent = task ? 'Edit Task' : 'New Task';
        $('tkFormSub').textContent = task ? ('#' + task.id + ' — update fields and assignment') : 'Define the work, who does it, and the review rules';
        const projSel = $('tkFProject');
        projSel.innerHTML = TK.projects.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
        $('tkFProjectWrap').style.display = task ? 'none' : '';

        let detail = null;
        if (task) {
            detail = TK.detail && TK.detail.task.id === task.id
                ? TK.detail
                : await api('tasks_api.php?action=get&id=' + task.id);
            const t = detail.task;
            projSel.value = String(t.project_id);
            $('tkFTitle').value = t.title;
            $('tkFDesc').value = t.description || '';
            $('tkFPriority').value = t.priority;
            $('tkFMode').value = t.assignment_mode;
            $('tkFStart').value = t.start_at || '';
            $('tkFDue').value = t.due_at || '';
            fillSectionSelect(t.section_id || '');
            fillItemSelect(t.section_id || '', t.item_id || null);
            $('tkFReview').checked = !!Number(t.review_required);
            $('tkFReqComment').checked = !!Number(t.require_comment_on_submit);
            $('tkFReqFile').checked = !!Number(t.require_file_on_submit);
            $('tkFNotifyAdmin').checked = !!Number(t.notify_admin_on_complete);
            $('tkFProgress').checked = !!Number(t.affects_project_progress);
            $('tkFWeight').value = t.progress_weight || 0;
            $('tkFClientVisible').checked = !!Number(t.client_visible);
            $('tkFClientApproval').checked = !!Number(t.client_approval_required);
            $('tkFClientComments').checked = !!Number(t.client_comments_enabled);
            $('tkFClientFiles').checked = !!Number(t.client_files_downloadable);
            await loadAssignable(t.project_id);
            const responsible = (t.assignees || []).find((x) => x.role === 'responsible');
            const contributors = (t.assignees || []).filter((x) => x.role === 'contributor').map((x) => x.user_id);
            const reviewers = (detail.reviewers || []).map((x) => x.user_id);
            fillAssigneeSelects({
                responsible: responsible ? responsible.user_id : '',
                contributors, reviewers,
            });
        } else {
            $('tkFTitle').value = '';
            $('tkFDesc').value = '';
            $('tkFPriority').value = 'medium';
            $('tkFMode').value = 'single';
            $('tkFStart').value = '';
            $('tkFDue').value = '';
            $('tkFReview').checked = true;
            $('tkFReqComment').checked = false;
            $('tkFReqFile').checked = false;
            $('tkFNotifyAdmin').checked = true;
            $('tkFProgress').checked = false;
            $('tkFWeight').value = 0;
            $('tkFClientVisible').checked = false;
            $('tkFClientApproval').checked = false;
            $('tkFClientComments').checked = false;
            $('tkFClientFiles').checked = false;
            fillSectionSelect('');
            const first = TK.projects[0];
            projSel.value = first ? String(first.id) : '';
            await loadAssignable(first ? first.id : 0);
        }
        syncFormMode();
        openPanel('taskFormPanel');
    }

    function selectedIds(sel) { return [...sel.selectedOptions].map((o) => Number(o.value)).filter((n) => n > 0); }

    async function saveForm() {
        const title = $('tkFTitle').value.trim();
        if (!title) { toast('Title is required', 'err'); return; }
        const mode = $('tkFMode').value;
        const responsibleId = Number($('tkFAssignee').value) || 0;
        const contributorIds = mode === 'single' ? [] : selectedIds($('tkFContributors'));
        const reviewerIds = selectedIds($('tkFReviewers'));
        const sectionVal = ($('tkFSection') || {}).value || '';
        const itemVal = ($('tkFItem') || {}).value || '';
        const common = {
            title,
            description: $('tkFDesc').value.trim(),
            priority: $('tkFPriority').value,
            assignment_mode: mode,
            section_id: sectionVal === '' ? null : Number(sectionVal),
            item_id: itemVal === '' ? null : Number(itemVal),
            start_at: $('tkFStart').value || '',
            due_at: $('tkFDue').value || '',
            review_required: $('tkFReview').checked ? 1 : 0,
            require_comment_on_submit: $('tkFReqComment').checked ? 1 : 0,
            require_file_on_submit: $('tkFReqFile').checked ? 1 : 0,
            notify_admin_on_complete: $('tkFNotifyAdmin').checked ? 1 : 0,
            affects_project_progress: $('tkFProgress').checked ? 1 : 0,
            progress_weight: Number($('tkFWeight').value) || 0,
            client_visible: $('tkFClientVisible').checked ? 1 : 0,
            client_approval_required: $('tkFClientApproval').checked ? 1 : 0,
            client_comments_enabled: $('tkFClientComments').checked ? 1 : 0,
            client_files_downloadable: $('tkFClientFiles').checked ? 1 : 0,
        };
        if (common.client_approval_required && !common.review_required) {
            common.review_required = 1;
            $('tkFReview').checked = true;
            toast('Review Required is mandatory when client approval is on', 'inf');
        }

        try {
            if (TK.editing) {
                const t = TK.editing;
                const upd = await api('tasks_api.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update', id: t.id, version: t.version, ...common }),
                });
                if (!upd.success) { toast(upd.error || 'Update failed', 'err'); return; }
                if (responsibleId === 0 && (t.assignees || []).length > 0) {
                    toast('Assignment unchanged — pick a person to reassign (it cannot be cleared here).', 'inf');
                }
                if (responsibleId > 0) {
                    const asg = await api('tasks_api.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'assign', id: t.id, version: upd.task.version,
                            assignment_mode: mode, responsible_id: responsibleId,
                            contributor_ids: contributorIds, reviewer_ids: reviewerIds,
                        }),
                    });
                    if (!asg.success) { toast(asg.error || 'Assignment failed', 'err'); await loadTasks(); return; }
                }
                toast('Task updated', 'ok');
                closePanel('taskFormPanel');
                await loadTasks();
                await openDetail(t.id);
            } else {
                const created = await api('tasks_api.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create', project_id: Number($('tkFProject').value),
                        ...common, responsible_id: responsibleId,
                        contributor_ids: contributorIds, reviewer_ids: reviewerIds,
                    }),
                });
                if (!created.success) { toast(created.error || 'Create failed', 'err'); return; }
                toast('Task created', 'ok');
                closePanel('taskFormPanel');
                TK.projectId = String($('tkFProject').value);
                await loadTasks();
            }
        } catch (e) { toast('Save failed', 'err'); }
    }

    /* ------------------------------ panels ------------------------------- */
    function openPanel(id) { const el = $(id); if (el) el.classList.add('open'); }
    function closePanel(id) {
        const el = $(id); if (el) el.classList.remove('open');
        if (id === 'taskDetailPanel') TK.blockArmed = false;
    }
    function openTasksModal(projectId) {
        if (projectId) TK.projectId = String(projectId);
        const m = $('tasksModal');
        if (m) m.classList.add('open');
        loadTasks();
    }

    /* ------------------------------ events ------------------------------- */
    document.addEventListener('click', async (ev) => {
        const el = ev.target.closest('[data-action]');
        if (!el) return;
        const action = el.dataset.action;
        try {
            switch (action) {
                case 'open-tasks':
                    ev.preventDefault();
                    openTasksModal();
                    return;
                case 'tk-open':
                    openDetail(Number(el.dataset.id));
                    return;
                case 'tk-view': {
                    TK.view = el.dataset.view === 'board' ? 'board' : 'list';
                    document.querySelectorAll('[data-action="tk-view"]').forEach((b) =>
                        b.classList.toggle('active', b === el));
                    renderList();
                    return;
                }
                case 'tk-new': openForm(null); return;
                case 'tk-edit':
                    if (TK.detail) closePanel('taskDetailPanel');
                    openForm(TK.detail ? TK.detail.task : null);
                    return;
                case 'tk-save': saveForm(); return;
                case 'tk-form-close': closePanel('taskFormPanel'); return;
                case 'tk-detail-close': closePanel('taskDetailPanel'); return;
                case 'tk-transition': doTransition(el.dataset.to); return;
                case 'tk-block-arm': TK.blockArmed = true; if (TK.detail) renderActions(TK.detail); return;
                case 'tk-block-cancel': TK.blockArmed = false; if (TK.detail) renderActions(TK.detail); return;
                case 'tk-block-confirm': doTransition('blocked'); return;
                case 'tk-client-decision': doClientDecision(el.dataset.decision); return;
                case 'tk-comment-add': addComment(); return;
                case 'tk-chk-add': checklistAdd(); return;
                case 'tk-chk-toggle': checklistToggle(Number(el.dataset.item)); return;
                case 'tk-chk-remove': checklistRemove(Number(el.dataset.item)); return;
                case 'tk-upload': uploadFile(); return;
                case 'tk-download': downloadFile(Number(el.dataset.file)); return;
                case 'tk-file-delete': deleteFile(Number(el.dataset.file)); return;
                case 'tk-toast-close': { const t = el.closest('.toast'); if (t) t.remove(); return; }
                case 'close-overlay': {
                    const id = el.dataset.overlay;
                    if (id) { const ov = $(id); if (ov) ov.classList.remove('open'); }
                    return;
                }
                default: return;
            }
        } catch (e) {
            console.error(e);
            toast('Action failed', 'err');
        }
    });

    document.addEventListener('change', async (ev) => {
        const el = ev.target;
        if (!el || !el.dataset) return;
        if (el.dataset.action === 'tk-filter-project') { TK.projectId = el.value; await loadTasks(); return; }
        if (el.dataset.action === 'tk-filter-status') { TK.status = el.value; renderList(); return; }
        if (el.id === 'tkFProject') { await loadAssignable(Number(el.value)); return; }
        if (el.id === 'tkFSection') { fillItemSelect(el.value, null); return; }
        if (el.id === 'tkFMode') { syncFormMode(); return; }
        if (el.id === 'tkFProgress' || el.id === 'tkFClientApproval' || el.id === 'tkFClientVisible') { syncFormMode(); return; }
        if (el.dataset.action === 'tk-chk-toggle') { checklistToggle(Number(el.dataset.item)); return; }
    });

    let searchTimer = null;
    document.addEventListener('input', (ev) => {
        const el = ev.target;
        if (el && el.id === 'tkSearch') {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => { TK.q = el.value; renderList(); }, 180);
        }
    });

    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && ev.target && ev.target.dataset && ev.target.dataset.action === 'tk-open') {
            ev.preventDefault();
            openDetail(Number(ev.target.dataset.id));
        }
        if (ev.key === 'Escape') {
            ['taskFormPanel', 'taskDetailPanel'].forEach((id) => closePanel(id));
        }
    });

    // tasks.php project cards: click opens the tasks view scoped to that project
    document.addEventListener('DOMContentLoaded', () => {
        if (document.body && document.body.dataset.role === 'employee') {
            const newBtn = document.querySelector('[data-action="tk-new"]');
            if (newBtn) newBtn.style.display = 'none';
        }
        document.querySelectorAll('[data-tk-project]').forEach((card) => {
            card.style.cursor = 'pointer';
            card.addEventListener('click', () => openTasksModal(card.dataset.tkProject));
        });
        // live task counts on tasks.php project cards
        const cards = document.querySelectorAll('[data-tk-project]');
        if (cards.length) {
            ensureProjects().then(() => Promise.all([...cards].map((card) =>
                api('tasks_api.php?action=list&project_id=' + Number(card.dataset.tkProject))
                    .then((d) => {
                        const badge = card.querySelector('[data-tk-count]');
                        if (badge) {
                            const n = (d.tasks || []).length;
                            badge.textContent = n === 1 ? '1 task' : n + ' tasks';
                        }
                    }).catch(() => null)
            ))).catch(() => null);
        }
    });

    // public API
    window.TasksUI = { open: openTasksModal, load: loadTasks };
})();
