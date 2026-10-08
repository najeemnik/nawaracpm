/**
 * extras-ui.js — Reports (راپورها) + Employees statistics (admin).
 * Loaded on index.php only. Self-contained: own helpers + own delegated
 * listener (same pattern as tasks-ui.js), so script.js stays untouched.
 */
(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    function csrfToken() {
        const meta = document.querySelector('meta[name="nawara-csrf-token"]');
        return meta ? meta.content : '';
    }

    async function api(url, opts = {}) {
        const headers = Object.assign({}, opts.headers || {});
        if (opts.json !== undefined) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = csrfToken();
            opts.body = JSON.stringify(opts.json);
            delete opts.json;
        }
        const res = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts, { headers }));
        let data = null;
        try { data = await res.json(); } catch (e) { data = { success: false, error: 'Bad response' }; }
        if (!res.ok && (!data || data.success === false)) {
            const err = new Error((data && data.error) || ('HTTP ' + res.status));
            err.status = res.status;
            throw err;
        }
        return data;
    }

    function toast(msg, type) {
        if (typeof window.toast === 'function' && window.toast !== toast) {
            window.toast(msg, type || 'inf');
            return;
        }
        let box = document.getElementById('nikToastHost');
        if (!box) {
            box = document.createElement('div');
            box.id = 'nikToastHost';
            box.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
            document.body.appendChild(box);
        }
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'background:#0f172a;color:#fff;padding:10px 14px;border-radius:10px;font-size:.85rem;box-shadow:0 8px 24px #0003;max-width:320px;';
        if (type === 'err') t.style.background = '#b91c1c';
        box.appendChild(t);
        setTimeout(() => t.remove(), 3600);
    }

    const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });

    /* ============================== REPORTS ============================== */

    async function openReports() {
        const sel = $('repProject');
        try {
            const d = await api('load_projects.php');
            const projects = d.projects || [];
            sel.innerHTML = '<option value="0">All projects</option>'
                + projects.map((p) => `<option value="${p.id}">${esc(p.project_name)}</option>`).join('');
        } catch (e) { /* keep the All option */ }
        if (typeof openOverlay === 'function') openOverlay('reportsModal');
    }

    async function generateReport() {
        const from = ($('repFrom') || {}).value || '';
        const to = ($('repTo') || {}).value || '';
        const pid = ($('repProject') || {}).value || '0';
        const qs = new URLSearchParams({ action: 'generate' });
        if (from) qs.set('from', from);
        if (to) qs.set('to', to);
        if (pid && pid !== '0') qs.set('project_id', pid);
        const body = $('repBody');
        body.innerHTML = '<div class="tk-muted">Generating…</div>';
        try {
            const d = await api('reports_api.php?' + qs.toString());
            body.innerHTML = renderReport(d);
        } catch (e) {
            body.innerHTML = `<div style="color:#dc2626;">${esc(e.message)}</div>`;
            toast(e.message, 'err');
        }
    }

    function card(label, value, color) {
        return `<div class="rep-card" style="border-color:${color}44;">
            <div class="rep-card-v" style="color:${color}">${value}</div>
            <div class="rep-card-k">${label}</div></div>`;
    }

    function tableHtml(head, rows) {
        if (!rows.length) rows = [['—']];
        return `<table class="rep-table"><thead><tr>${head.map((h) => `<th>${h}</th>`).join('')}</tr></thead>
            <tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
    }

    function renderReport(d) {
        const s = d.summary || {};
        const meta = d.meta || {};
        let html = `<div class="rep-print-area">`;
        html += `<h3 class="rep-title">📊 ${meta.from || '…'} → ${meta.to || '…'} ${meta.project_id ? `· project #${meta.project_id}` : ''} <small>(${esc(meta.generated_at || '')})</small></h3>`;

        html += '<div class="rep-cards">'
            + card('Projects', s.projects ?? 0, '#6366f1')
            + card('Avg progress', (s.avg_progress ?? 0) + '%', '#2563eb')
            + card('Tasks done', `${s.tasks_done ?? 0}/${s.tasks_total ?? 0}`, '#059669')
            + card('Income', money(s.income), '#059669')
            + card('Expenses', money(s.expenses), '#dc2626')
            + card('Withdrawals', money(s.withdrawals), '#d97706')
            + card('Remaining', money(s.remaining), '#7c3aed')
            + card('Partner share', s.share_per_partner == null ? '—' : money(s.share_per_partner), '#0f172a')
            + '</div>';

        html += '<h4>📈 Project progress &amp; forecast</h4>' + tableHtml(
            ['Project', 'Client', 'Progress', 'Mode', 'Planned', 'SPI', 'Forecast', 'Slip (days)'],
            (d.progress || []).map((p, i) => {
                const evm = (d.evm || [])[i] || {};
                return [
                    esc(p.name), esc(p.client), `${p.progress}%`, esc(p.mode),
                    evm.planned == null ? '—' : `${evm.planned}%`,
                    evm.spi == null ? '—' : Number(evm.spi).toFixed(2),
                    esc(evm.forecast_end || '—'),
                    evm.day_slippage == null ? '—' : (evm.day_slippage > 0 ? `+${evm.day_slippage}` : String(evm.day_slippage)),
                ];
            })
        );

        const snapRows = (d.snapshots || []).map((r) => [
            esc(r.snapshot_date), esc(r.project_name),
            `${Number(r.planned_pct).toFixed(1)}%`, `${Number(r.earned_pct).toFixed(1)}%`,
        ]);
        html += '<h4>🗓 Progress snapshots</h4>' + tableHtml(['Date', 'Project', 'Planned', 'Earned'], snapRows);

        const byStatus = (d.tasks && d.tasks.by_status) || {};
        html += '<h4>✅ Tasks</h4><div class="rep-chips">'
            + Object.entries(byStatus).map(([k, v]) => `<span class="rep-chip">${esc(k)}: <b>${v}</b></span>`).join('')
            + '</div>';
        html += tableHtml(['Employee', 'Completed'],
            ((d.tasks && d.tasks.done_by_employee) || []).map((r) => [esc(r.name), r.completed]));
        html += tableHtml(['Task', 'Project', 'Status', 'Assignees', 'Due'],
            ((d.tasks && d.tasks.list) || []).map((t) => [
                esc(t.title), esc(t.project_name || '—'), esc(t.status), esc(t.assignees || '—'), esc(t.due_at || '—'),
            ]));

        const fin = d.finance || {};
        html += '<h4>💰 Finance</h4>';
        html += '<div class="rep-sub">Payments (درآمد)</div>' + tableHtml(
            ['Date', 'Project', 'Amount', 'Note'],
            (fin.payments || []).map((r) => [esc(r.paid_at), `#${r.project_id}`, money(r.amount), esc(r.note)]));
        html += '<div class="rep-sub">Expenses (مصارف)</div>' + tableHtml(
            ['Date', 'Project', 'Category', 'Amount', 'Note'],
            (fin.expenses || []).map((r) => [esc(r.spent_at), `#${r.project_id}`, esc(r.category || '—'), money(r.amount), esc(r.note)]));
        html += '<div class="rep-sub">Withdrawals (برداشت‌ها)</div>' + tableHtml(
            ['Date', 'Amount', 'Note'],
            (fin.withdrawals || []).map((r) => [esc(r.taken_at), money(r.amount), esc(r.note)]));

        html += '<h4>🏆 Employees score</h4>' + tableHtml(
            ['Employee', 'Points', 'Awards'],
            (d.employees || []).map((r) => [esc(r.name), Number(r.score).toFixed(2), r.awards]));

        html += '</div>';
        return html;
    }

    function printReport() {
        const area = document.querySelector('#reportsModal .rep-print-area');
        if (!area || !area.innerHTML.trim()) {
            toast('اول راپور بسازید (Generate)', 'err');
            return;
        }
        window.print();
    }

    /* ============================ EMPLOYEES ============================== */

    async function openEmployees() {
        if (typeof openOverlay === 'function') openOverlay('employeesModal');
        await loadEmployeeStats();
    }

    let EMP_ROWS = [];

    async function loadEmployeeStats() {
        const box = $('empPeople');
        box.innerHTML = '<div class="tk-muted">Loading…</div>';
        try {
            const d = await api('employee_api.php?action=stats');
            EMP_ROWS = d.rows || [];
            box.innerHTML = tableHtml(
                ['Employee', 'Completed', 'Open', 'Score', ''],
                EMP_ROWS.map((r) => [
                    esc(r.name) + ` <small class="tk-muted">@${esc(r.username)}</small>`,
                    r.completed, r.open, Number(r.score).toFixed(2),
                    `<button class="btn btn-soft btn-sm" data-action="emp-detail" data-user-id="${r.id}">View</button>`,
                ])
            ) || '<div class="tk-muted">No employees.</div>';
        } catch (e) {
            box.innerHTML = `<div style="color:#dc2626;">${esc(e.message)}</div>`;
        }
    }

    async function openEmployeeDetail(userId) {
        const box = $('empPeople');
        box.innerHTML = '<div class="tk-muted">Loading…</div>';
        try {
            const d = await api('employee_api.php?action=detail&user_id=' + encodeURIComponent(userId));
            const p = d.person || {};
            let html = `<button class="btn btn-soft btn-sm" data-action="emp-back">← Back</button>
                <h4 style="margin-top:10px;">${esc(p.name)} <small class="tk-muted">@${esc(p.username)}</small> — score <b>${Number(d.score).toFixed(2)}</b></h4>`;
            html += '<div class="rep-sub">Score events (امتیازها)</div>' + tableHtml(
                ['When', 'Task', 'Project', 'Points', 'Progress'],
                (d.score_events || []).map((e) => [
                    esc(e.created_at), esc(e.title || '#' + e.task_id), esc(e.project_name || '—'),
                    Number(e.points).toFixed(2),
                    `${Number(e.progress_before).toFixed(0)}% → ${Number(e.progress_after).toFixed(0)}%`,
                ]));
            html += '<div class="rep-sub">Tasks (کارها)</div>' + tableHtml(
                ['Task', 'Project', 'Status', 'Member', 'Completed'],
                (d.tasks || []).map((t) => [
                    esc(t.title), esc(t.project_name || '—'), esc(t.status), esc(t.member_status), esc(t.completed_at || '—'),
                ]));
            box.innerHTML = html;
        } catch (e) {
            box.innerHTML = `<div style="color:#dc2626;">${esc(e.message)}</div>`;
        }
    }

    async function loadEmployeeProgress() {
        const box = $('empProgress');
        box.innerHTML = '<div class="tk-muted">Loading…</div>';
        try {
            const d = await api('employee_api.php?action=project_progress');
            box.innerHTML = tableHtml(
                ['Project', 'Progress', 'Planned', 'SPI', 'Forecast', 'Slip', 'Mode'],
                (d.rows || []).map((r) => [
                    esc(r.name),
                    `<div class="rep-bar"><div style="width:${Math.min(100, r.progress)}%"></div><span>${r.progress}%</span></div>`,
                    r.planned == null ? '—' : `${r.planned}%`,
                    r.spi == null ? '—' : Number(r.spi).toFixed(2),
                    esc(r.forecast_end || '—'),
                    r.day_slippage == null ? '—' : (r.day_slippage > 0 ? `+${r.day_slippage}` : String(r.day_slippage)),
                    esc(r.mode),
                ])
            );
        } catch (e) {
            box.innerHTML = `<div style="color:#dc2626;">${esc(e.message)}</div>`;
        }
    }

    function showEmpTab(tab) {
        document.querySelectorAll('#employeesModal .emp-tabs .tab-btn').forEach((b) => {
            b.classList.toggle('active', b.dataset.tab === tab);
        });
        $('empPeople').style.display = tab === 'people' ? '' : 'none';
        $('empProgress').style.display = tab === 'progress' ? '' : 'none';
        if (tab === 'progress') loadEmployeeProgress();
    }

    /* ============================ LISTENER =============================== */

    document.addEventListener('click', (event) => {
        const el = event.target.closest('[data-action]');
        if (!el) return;
        switch (el.dataset.action) {
            case 'open-reports': event.preventDefault(); openReports(); break;
            case 'rep-generate': event.preventDefault(); generateReport(); break;
            case 'rep-print': event.preventDefault(); printReport(); break;
            case 'open-employees': event.preventDefault(); openEmployees(); break;
            case 'emp-tab': event.preventDefault(); showEmpTab(el.dataset.tab || 'people'); break;
            case 'emp-detail': event.preventDefault(); openEmployeeDetail(Number(el.dataset.userId) || 0); break;
            case 'emp-back': event.preventDefault(); loadEmployeeStats(); break;
            default: break;
        }
    }, false);
})();
