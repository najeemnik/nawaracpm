'use strict';

// ============================================
// SAFE BOOTSTRAP (no inline script required by CSP)
// ============================================
function readBootstrapJson(name, fallback) {
    const meta = document.querySelector(`meta[name="${name}"]`);
    if (!meta || !meta.content) return fallback;
    try {
        const parsed = JSON.parse(meta.content);
        return parsed && typeof parsed === 'object' ? parsed : fallback;
    } catch (_) {
        return fallback;
    }
}

window.CURRENT_USER = readBootstrapJson('nawara-current-user', {
    name: 'Unknown',
    role: 'user',
    permissions: {}
});
if (!window.CURRENT_USER.permissions || typeof window.CURRENT_USER.permissions !== 'object') {
    window.CURRENT_USER.permissions = {};
}
const csrfMeta = document.querySelector('meta[name="nawara-csrf-token"]');
window.NAWARA_CSRF_TOKEN = csrfMeta ? csrfMeta.content : '';

// ============================================
// HELPERS
// ============================================
const $ = id => document.getElementById(id);

let META = { engineers: [], statuses: [], sections: [] };
let PROJECTS = [];
let CURRENT_PROJECT = null;
let ALL_USERS = [];
let USER_PROJECT_ACCESS_LOADED = false;

// Safe for both HTML text and quoted HTML attribute contexts. Do not use the
// browser's text-node serializer here: it may leave quotes unescaped because
// they are harmless in a text node but dangerous when interpolated into an
// attribute in an HTML template string.
const esc = s => String(s ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
}[char]));

const nowLocalInput = () => {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
};

const progColor = p =>
    p >= 80 ? '#10b981' : p >= 50 ? '#3b82f6' : p >= 25 ? '#f59e0b' : '#ef4444';

function statusClass(name) {
    const n = (name || '').toLowerCase();
    if (n.includes('completed')) return 'b-ok';
    if (n.includes('revision')) return 'b-rv';
    if (n.includes('waiting')) return 'b-wc';
    if (n.includes('progress')) return 'b-ip';
    return 'b-ns';
}

function badge(name) {
    return `<span class="badge ${statusClass(name)}">${esc(name || 'Not Started')}</span>`;
}

function loading(on) {
    const el = $('loadingBox');
    if (el) el.classList.toggle('on', !!on);
}

function toast(msg, type = 'inf') {
    const icons = { ok: '✅', err: '❌', inf: 'ℹ️' };
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.innerHTML = `
        <span>${icons[type] || icons.inf}</span>
        <span class="toast-msg">${esc(msg)}</span>
        <button class="toast-cls" type="button" data-action="toast-close">✕</button>
    `;
    const box = $('toastBox');
    if (box) box.appendChild(el);
    setTimeout(() => el.remove(), 4200);
}

// ============================================
// API
// ============================================
async function api(url, opts = {}) {
    loading(true);
    try {
        const request = { ...opts };
        const method = (request.method || 'GET').toUpperCase();
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            const headers = new Headers(request.headers || {});
            if (typeof window.NAWARA_CSRF_TOKEN === 'string' && window.NAWARA_CSRF_TOKEN) {
                headers.set('X-CSRF-Token', window.NAWARA_CSRF_TOKEN);
            }
            request.headers = headers;
        }

        const res = await fetch(url, request);
        const data = await res.json();
        if (data && data.auth === false) {
            window.location.href = 'index.php';
            return data;
        }
        return data;
    } catch (e) {
        toast('Network error: ' + e.message, 'err');
        throw e;
    } finally {
        loading(false);
    }
}

async function postJSON(payload) {
    return await api('save_project.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
}

async function loadMeta() {
    const d = await api('load_projects.php?meta=1');
    if (d.success) META = d.meta;
}

async function loadProjects(search = '') {
    const url = 'load_projects.php' + (search ? '?search=' + encodeURIComponent(search) : '');
    const d = await api(url);
    if (!d.success) {
        toast(d.error || 'Load failed', 'err');
        return;
    }
    PROJECTS = d.projects || [];
    renderProjects();
    updateStats();
}

function updateStats() {
    const t = $('totalProjects');
    const c = $('completedProjects');
    const a = $('activeProjects');
    if (t) t.textContent = PROJECTS.length;
    if (c) c.textContent = PROJECTS.filter(p => parseInt(p.progress) >= 100).length;
    if (a) a.textContent = PROJECTS.filter(p => parseInt(p.progress) > 0 && parseInt(p.progress) < 100).length;
}

function sectionProgressHTML(sections) {
    if (!sections || !sections.length) return '-';
    return `<div class="sec-prog-list">
        ${sections.map(s => {
            const pct = parseInt(s.percent) || 0;
            return `<div class="sec-prog-item">
                <div class="sec-prog-label">
                    <span>${esc(s.icon)} ${esc(s.name)}</span>
                    <span class="sec-prog-pct">${pct}%</span>
                </div>
                <div class="sec-prog-bar">
                    <div class="sec-prog-fill" style="width:${pct}%;background:${progColor(pct)}"></div>
                </div>
            </div>`;
        }).join('')}
    </div>`;
}

// ============================================
// RENDER PROJECTS (با چک permissions)
// ============================================
function renderProjects() {
    const tbody = $('projectsTableBody');
    const empty = $('emptyState');
    const count = $('projectCount');
    const responsive = document.querySelector('.table-responsive');

    if (!tbody) return;

    if (count) count.textContent = PROJECTS.length + ' project' + (PROJECTS.length !== 1 ? 's' : '');

    // چک permission Add برای Empty State
    const isAdmin = CURRENT_USER.role === 'admin';
    const canAdd = isAdmin || (CURRENT_USER.permissions && CURRENT_USER.permissions.add);
    const emptyStateBtn = document.querySelector('#emptyState button');
    if (emptyStateBtn) {
        emptyStateBtn.style.display = canAdd ? 'inline-flex' : 'none';
    }

    if (!PROJECTS.length) {
        tbody.innerHTML = '';
        if (empty) empty.style.display = 'block';
        if (responsive) responsive.style.display = 'none';
        return;
    }

    if (empty) empty.style.display = 'none';
    if (responsive) responsive.style.display = 'block';

    tbody.innerHTML = PROJECTS.map((p, i) => {
        const progress = parseInt(p.progress) || 0;
        const projectId = parseInt(p.id, 10) || 0;
        const projectVersion = parseInt(p.version, 10) || 0;

        // چک دسترسی
        const access = p.user_access || {};
        const canEdit = isAdmin || access.edit;
        const canDelete = isAdmin || access.delete;
        const canPrint = isAdmin || access.print;
        const canPdf = isAdmin || access.pdf;
        const canFiles = isAdmin || access.files;

        return `
            <tr>
                <td>${i + 1}</td>
                <td><button type="button" class="pname" data-action="open-detail" data-project-id="${projectId}">${esc(p.project_name)}</button></td>
                <td>${esc(p.client_name)}</td>
                <td class="hide-sm">${esc(p.zone)}</td>
                <td class="hide-sm">${esc(p.lead_engineer_name || '-')}</td>
                <td>
                    <div class="prog-wrap">
                        <div class="prog-bar">
                            <div class="prog-fill" style="width:${progress}%;background:${progColor(progress)}"></div>
                        </div>
                        <span class="prog-pct">${progress}%</span>
                    </div>
                </td>
                <td>${badge(p.overall_status)}</td>
                <td>${sectionProgressHTML(p.section_progress)}</td>
                <td>
                    <div class="acts">
                        <button type="button" class="btn btn-sm btn-open" data-action="open-detail" data-project-id="${projectId}" title="Open">👁</button>
                        ${canEdit ? `<button type="button" class="btn btn-sm btn-edit" data-action="edit-project" data-project-id="${projectId}" title="Edit">✏️</button>` : ''}
                        ${canFiles ? `<button type="button" class="btn btn-sm btn-files" data-action="open-project-files" data-project-id="${projectId}" title="Files">📁</button>` : ''}
                        ${canDelete ? `<button type="button" class="btn btn-sm btn-del" data-action="archive-project" data-project-id="${projectId}" data-project-version="${projectVersion}" title="Archive">🗄</button>` : ''}
                        ${canPrint ? `<button type="button" class="btn btn-sm btn-print" data-action="print-project" data-project-id="${projectId}" title="Print">🖨</button>` : ''}
                        ${canPdf ? `<button type="button" class="btn btn-sm btn-pdf" data-action="pdf-project" data-project-id="${projectId}" title="PDF">📄</button>` : ''}
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}
// ============================================
// ENGINEER & STATUS OPTIONS
// ============================================
function engineerOptions(selected = '') {
    let html = `<option value="">Select...</option>`;
    META.engineers.forEach(e => {
        html += `<option value="${e.id}" ${String(selected) === String(e.id) ? 'selected' : ''}>${esc(e.name)}</option>`;
    });
    html += `<option value="__add_new__">＋ Add New Engineer</option>`;
    return html;
}

function statusOptions(selected = '') {
    let html = '';
    META.statuses.forEach(s => {
        html += `<option value="${s.id}" ${String(selected) === String(s.id) ? 'selected' : ''}>${esc(s.name)} (${s.percent}%)</option>`;
    });
    html += `<option value="__add_new_status__">＋ Add New Status</option>`;
    return html;
}

async function handleEngineerAdd(selectEl) {
    if (selectEl.value !== '__add_new__') return;
    const name = prompt('Enter new engineer name:');
    if (!name || !name.trim()) { selectEl.value = ''; return; }
    const d = await postJSON({ action: 'add_engineer', name: name.trim() });
    if (d.success) {
        META = d.meta;
        document.querySelectorAll('.engineer-select').forEach(sel => {
            const old = sel.value;
            sel.innerHTML = engineerOptions(d.id || old);
        });
        toast('Engineer added', 'ok');
    } else {
        toast(d.error || 'Failed', 'err');
    }
}

async function handleStatusAdd(selectEl) {
    if (selectEl.value !== '__add_new_status__') return;
    const name = prompt('Enter new status name:');
    if (!name || !name.trim()) { selectEl.value = ''; return; }
    let percent = parseInt(prompt('Enter percent (0-100):', '0'));
    if (isNaN(percent)) percent = 0;
    percent = Math.max(0, Math.min(100, percent));
    const d = await postJSON({ action: 'add_status', name: name.trim(), percent, color: progColor(percent) });
    if (d.success) {
        META = d.meta;
        document.querySelectorAll('.status-select').forEach(sel => {
            const old = sel.value;
            sel.innerHTML = statusOptions(d.id || old);
        });
        toast('Status added', 'ok');
    } else {
        toast(d.error || 'Failed', 'err');
    }
}

function valueMapFromProject(project) {
    const map = {};
    if (!project || !project.values) return map;
    project.values.forEach(v => { map[parseInt(v.item_id)] = v; });
    return map;
}

// ============================================
// BUILD SECTIONS FORM
// ============================================
function buildSectionsForm(project = null) {
    const container = $('dynamicSections');
    if (!container) return;
    const values = valueMapFromProject(project);

    container.innerHTML = META.sections.map(section => {
        const itemsHtml = section.items.map(item => {
            const v = values[parseInt(item.id)] || {};
            const statusId = v.status_id || '';
            const assigneeId = v.assignee_id || '';
            const comment = v.comment || '';
            const reportDate = v.report_date || nowLocalInput();

            const assigneeHtml = item.assignee_type !== 'none' ? `
                <div class="fg">
                    <label>Assigned ${item.assignee_type === 'architect' ? 'Architect' : 'Engineer'}</label>
                    <select class="engineer-select item-assignee" data-item="${item.id}" data-action="engineer-select">
                        ${engineerOptions(assigneeId)}
                    </select>
                </div>` : '';

            const commentHtml = parseInt(item.has_comment) ? `
                <div class="fg full">
                    <label>Comment</label>
                    <textarea class="item-comment" data-item="${item.id}" rows="2">${esc(comment)}</textarea>
                </div>` : '';

            return `
                <div class="item-card" data-item="${item.id}">
                    <div class="item-head">
                        <div class="item-title">${esc(item.name)}</div>
                        <div class="item-weight">Weight: ${esc(item.weight)}%</div>
                    </div>
                    <div class="fgrid">
                        <div class="fg">
                            <label>Status</label>
                            <select class="status-select item-status" data-item="${item.id}" data-action="status-select">
                                ${statusOptions(statusId)}
                            </select>
                        </div>
                        ${assigneeHtml}
                        <div class="fg">
                            <label>Report Date & Time</label>
                            <input type="datetime-local" class="item-report-date" data-item="${item.id}" value="${esc(reportDate)}">
                        </div>
                        ${commentHtml}
                    </div>
                </div>`;
        }).join('');

        return `
            <div class="fsec project-section" data-section="${section.id}">
                <div class="fsec-head" data-action="toggle-section" role="button" tabindex="0">
                    <span>${esc(section.icon)}</span>
                    <h3>${esc(section.name)}</h3>
                    <span class="section-live-pct" id="section-live-${section.id}">0%</span>
                    <span class="section-live-pct">Weight: ${esc(section.weight)}%</span>
                    <span class="arr">▼</span>
                </div>
                <div class="fsec-body">${itemsHtml}</div>
            </div>`;
    }).join('');

    updateLiveSectionProgress();
}

function toggleSectionBody(head) {
    const body = head.nextElementSibling;
    const arr = head.querySelector('.arr');
    if (body) body.classList.toggle('hidden');
    if (arr) arr.classList.toggle('up');
}

function getStatusPercentById(id) {
    const st = META.statuses.find(s => String(s.id) === String(id));
    return st ? parseInt(st.percent) || 0 : 0;
}

function updateLiveSectionProgress() {
    META.sections.forEach(section => {
        let totalWeight = 0;
        let weighted = 0;
        section.items.forEach(item => {
            const select = document.querySelector(`.item-status[data-item="${item.id}"]`);
            const pct = select ? getStatusPercentById(select.value) : 0;
            const weight = parseFloat(item.weight) || 0;
            totalWeight += weight;
            weighted += pct * weight;
        });
        const sectionPct = totalWeight > 0 ? Math.round(weighted / totalWeight) : 0;
        const el = $(`section-live-${section.id}`);
        if (el) {
            el.textContent = sectionPct + '%';
            el.style.background = progColor(sectionPct);
            el.style.color = '#fff';
        }
    });
}

// ============================================
// PROJECT FORM
// ============================================
async function openProjectForm() {
    await loadMeta();
    CURRENT_PROJECT = null;
    const fields = ['projectId','projectName','clientName','zone','startDate','endDate','description','contractValue'];
    fields.forEach(id => { const el = $(id); if (el) el.value = id === 'projectId' ? '0' : ''; });
    const modeSel = $('progressMode');
    if (modeSel) modeSel.value = 'task_driven'; // approved default for new projects
    const lead = $('leadEngineer');
    if (lead) lead.innerHTML = engineerOptions('');
    buildSectionsForm(null);
    const t = $('modalTitle');
    if (t) t.textContent = '➕ Add New Project';
    openOverlay('projectModal');
}

async function editProject(id) {
    await loadMeta();
    const d = await api('load_projects.php?id=' + id);
    if (!d.success) { toast(d.error || 'Not found', 'err'); return; }
    CURRENT_PROJECT = d.project;

    $('projectId').value = CURRENT_PROJECT.id;
    $('projectName').value = CURRENT_PROJECT.project_name || '';
    $('clientName').value = CURRENT_PROJECT.client_name || '';
    $('zone').value = CURRENT_PROJECT.zone || '';
    $('startDate').value = CURRENT_PROJECT.start_date || '';
    $('endDate').value = CURRENT_PROJECT.end_date || '';
    $('description').value = CURRENT_PROJECT.description || '';
    const modeField = $('progressMode');
    if (modeField) modeField.value = CURRENT_PROJECT.progress_mode === 'task_driven' ? 'task_driven' : 'manual';
    const cvField = $('contractValue');
    if (cvField) cvField.value = (CURRENT_PROJECT.contract_value || CURRENT_PROJECT.contract_value === 0)
        ? Number(CURRENT_PROJECT.contract_value) : '';
    $('leadEngineer').innerHTML = engineerOptions(CURRENT_PROJECT.lead_engineer_id || '');

    buildSectionsForm(CURRENT_PROJECT);
    const t = $('modalTitle');
    if (t) t.textContent = '✏️ Update Project';
    openOverlay('projectModal');
}

function collectProjectValues() {
    const values = [];
    META.sections.forEach(section => {
        section.items.forEach(item => {
            const statusEl = document.querySelector(`.item-status[data-item="${item.id}"]`);
            const assigneeEl = document.querySelector(`.item-assignee[data-item="${item.id}"]`);
            const commentEl = document.querySelector(`.item-comment[data-item="${item.id}"]`);
            const dateEl = document.querySelector(`.item-report-date[data-item="${item.id}"]`);
            values.push({
                item_id: item.id,
                status_id: statusEl && !String(statusEl.value).startsWith('__') ? statusEl.value : '',
                assignee_id: assigneeEl && !String(assigneeEl.value).startsWith('__') ? assigneeEl.value : '',
                comment: commentEl ? commentEl.value.trim() : '',
                report_date: dateEl ? dateEl.value : nowLocalInput()
            });
        });
    });
    return values;
}

async function saveProject() {
    const name = $('projectName').value.trim();
    const client = $('clientName').value.trim();
    const projectId = parseInt($('projectId').value, 10) || 0;
    const version = projectId > 0 ? parseInt(CURRENT_PROJECT && CURRENT_PROJECT.version, 10) : 0;
    if (!name) { toast('Project name is required', 'err'); $('projectName').focus(); return; }
    if (!client) { toast('Client name is required', 'err'); $('clientName').focus(); return; }
    if (projectId > 0 && (!Number.isInteger(version) || version < 1)) {
        toast('This project must be reloaded before it can be updated.', 'err');
        return;
    }

    const payload = {
        action: 'save_project',
        project: {
            id: projectId,
            version,
            project_name: name,
            client_name: client,
            zone: $('zone').value.trim(),
            lead_engineer_id: $('leadEngineer').value && !String($('leadEngineer').value).startsWith('__') ? $('leadEngineer').value : '',
            start_date: $('startDate').value,
            end_date: $('endDate').value,
            description: $('description').value.trim(),
            progress_mode: ($('progressMode') || {}).value || undefined,
            contract_value: ($('contractValue') || {}).value || ''
        },
        values: collectProjectValues()
    };

    const d = await postJSON(payload);
    if (d.success) {
        if (CURRENT_PROJECT && Number.isInteger(parseInt(d.version, 10))) {
            CURRENT_PROJECT.version = parseInt(d.version, 10);
        }
        toast('Project saved successfully', 'ok');
        closeProjectForm();
        await loadProjects();
    } else {
        const conflict = /project (changed|version is required)/i.test(String(d.error || ''));
        toast(conflict ? 'This project changed elsewhere. Reload it before saving again.' : (d.error || 'Save failed'), 'err');
    }
}

function closeProjectForm() { closeOverlay('projectModal'); }

// ============================================
// PROJECT DETAIL
// ============================================
async function openDetail(id) {
    const d = await api('load_projects.php?id=' + id);
    if (!d.success) { toast(d.error || 'Not found', 'err'); return; }
    
    const p = d.project;
    const meta = d.meta;
    const valueMap = {};
    p.values.forEach(v => valueMap[parseInt(v.item_id)] = v);

    const isAdmin = CURRENT_USER.role === 'admin';
    const access = p.user_access || {};
    const canEdit = isAdmin || access.edit;

    const info = `
        <div class="dg">
            <div class="di"><div class="dlbl">Project Name</div><div class="dval">${esc(p.project_name)}</div></div>
            <div class="di"><div class="dlbl">Client</div><div class="dval">${esc(p.client_name)}</div></div>
            <div class="di"><div class="dlbl">Zone</div><div class="dval">${esc(p.zone || '-')}</div></div>
            <div class="di"><div class="dlbl">Lead Engineer</div><div class="dval">${esc(p.lead_engineer_name || '-')}</div></div>
            <div class="di"><div class="dlbl">Start Date</div><div class="dval">${esc(p.start_date || '-')}</div></div>
            <div class="di"><div class="dlbl">End Date</div><div class="dval">${esc(p.end_date || '-')}</div></div>
            ${p.description ? `<div class="di full"><div class="dlbl">Description</div><div class="dval">${esc(p.description)}</div></div>` : ''}
        </div>
        <div class="dsec">Overall Progress: ${parseInt(p.progress) || 0}%</div>
        <div class="prog-wrap" style="margin-bottom:16px;">
            <div class="prog-bar">
                <div class="prog-fill" style="width:${parseInt(p.progress)||0}%;background:${progColor(parseInt(p.progress)||0)}"></div>
            </div>
            <span class="prog-pct">${parseInt(p.progress)||0}%</span>
        </div>`;

    const secProgress = `
        <div class="dsec">📊 Section Progress</div>
        ${(p.section_progress || []).map(s => `
            <div class="detail-sec-bar">
                <div class="detail-sec-info">
                    <span>${esc(s.icon)} ${esc(s.name)}</span>
                    <span>${parseInt(s.percent)||0}% <small style="color:#94a3b8;">Weight: ${esc(s.weight)}%</small></span>
                </div>
                <div class="detail-sec-track">
                    <div class="detail-sec-fill" style="width:${parseInt(s.percent)||0}%;background:${progColor(parseInt(s.percent)||0)}"></div>
                </div>
            </div>`).join('')}`;

    const sectionsHtml = meta.sections.map(section => `
        <div class="dsec">${esc(section.icon)} ${esc(section.name)} <small style="color:#94a3b8;">(${esc(section.weight)}%)</small></div>
        ${section.items.map(item => {
            const v = valueMap[parseInt(item.id)] || {};
            return `<div class="drow">
                <div><strong>${esc(item.name)}</strong><br><small>Weight: ${esc(item.weight)}%</small></div>
                <div>${badge(v.status_name || 'Not Started')}</div>
                <div>${esc(v.assignee_name || '-')}</div>
                <div><small>${esc(v.report_date || '-')}</small><br>${esc(v.comment || '')}</div>
            </div>`;
        }).join('')}`).join('');

    const t = $('detailTitle');
    if (t) t.textContent = '📋 ' + p.project_name;
    const forecastHtml = `
        <div class="dsec">🎯 Forecast &amp; Earned Value</div>
        <div id="detailEvm" class="tk-muted">Loading forecast…</div>`;
    const body = $('detailBody');
    if (body) body.innerHTML = info + secProgress + forecastHtml + sectionsHtml;
    loadEvmCard(parseInt(p.id, 10) || 0);

    // دکمه Edit بر اساس permission
    const editBtn = $('detailEditBtn');
    if (editBtn) {
        if (canEdit) {
            editBtn.style.display = 'inline-flex';
            editBtn.dataset.action = 'edit-detail-project';
            editBtn.dataset.projectId = String(parseInt(p.id) || 0);
        } else {
            editBtn.style.display = 'none';
            delete editBtn.dataset.action;
            delete editBtn.dataset.projectId;
        }
    }

    openOverlay('detailModal');
}

function evmChartSvg(series, planAvailable) {
    if (!Array.isArray(series) || series.length < 2) {
        return '<div class="tk-muted" style="margin-top:8px;">The S-curve grows as daily snapshots accumulate.</div>';
    }
    const W = 560, H = 170, pad = 28;
    const n = series.length;
    const x = (i) => pad + (n < 2 ? 0 : (i / (n - 1)) * (W - 2 * pad));
    const y = (v) => H - pad - (Math.max(0, Math.min(100, Number(v) || 0)) / 100) * (H - 2 * pad);
    const path = (key) => series.map((pt, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(pt[key]).toFixed(1)}`).join(' ');
    const grid = [0, 25, 50, 75, 100].map(v =>
        `<line x1="${pad}" y1="${y(v)}" x2="${W - pad}" y2="${y(v)}" stroke="#e2e8f0" stroke-width="1"/>
         <text x="4" y="${y(v) + 4}" font-size="9" fill="#94a3b8">${v}</text>`).join('');
    const planned = planAvailable
        ? `<path d="${path('planned')}" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 4"/>`
        : '';
    const earnedDots = series.map((pt, i) =>
        `<circle cx="${x(i).toFixed(1)}" cy="${y(pt.earned).toFixed(1)}" r="2.5" fill="#2563eb"/>`).join('');
    return `
        <svg viewBox="0 0 ${W} ${H}" style="width:100%;height:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;margin-top:8px;" role="img" aria-label="Planned versus earned progress">
            ${grid}
            ${planned}
            <path d="${path('earned')}" fill="none" stroke="#2563eb" stroke-width="2.5"/>
            ${earnedDots}
        </svg>
        <div style="display:flex;gap:14px;font-size:.75rem;color:#64748b;margin-top:4px;">
            <span>— <span style="color:#2563eb;font-weight:800;">Earned (actual)</span></span>
            ${planAvailable ? '<span>┄ <span style="color:#94a3b8;font-weight:800;">Planned (S-curve)</span></span>' : ''}
        </div>`;
}

async function loadEvmCard(projectId) {
    const box = $('detailEvm');
    if (!box || !projectId) return;
    try {
        const d = await api('progress_api.php?action=evm&project_id=' + projectId);
        if (!d.success) {
            box.innerHTML = `<span class="tk-muted">${esc(d.error || 'Forecast unavailable')}</span>`;
            return;
        }
        const e = d.evm;
        const cell = (k, v, color) => `
            <div class="tk-meta-cell" style="min-width:130px;">
                <div class="k">${k}</div>
                <div class="v" style="${color ? 'color:' + color + ';' : ''}">${v}</div>
            </div>`;
        const spiTxt = e.spi === null ? '—' : Number(e.spi).toFixed(2);
        const spiColor = e.spi === null ? '' : (e.spi >= 0.95 ? '#059669' : (e.spi >= 0.85 ? '#d97706' : '#dc2626'));
        const money = (v) => v === null ? '—' : Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 });
        const slip = e.day_slippage === null ? '—'
            : (e.day_slippage === 0 ? 'on time' : (e.day_slippage > 0 ? '+' + e.day_slippage + ' d late' : e.day_slippage + ' d early'));
        box.innerHTML = `
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                ${cell('Earned %', Number(e.earned_pct).toFixed(1) + '%', '#2563eb')}
                ${cell('Planned %', e.planned_pct === null ? 'no plan dates' : Number(e.planned_pct).toFixed(1) + '%')}
                ${cell('SPI', spiTxt, spiColor)}
                ${e.bac !== null ? cell('EV / PV', money(e.ev) + ' / ' + money(e.pv)) : ''}
                ${e.cpi !== null ? cell('CPI / EAC', Number(e.cpi).toFixed(2) + ' / ' + money(e.eac)) : ''}
                ${cell('Forecast finish', e.forecast_end || '—', (e.day_slippage || 0) > 0 ? '#dc2626' : '#059669')}
                ${cell('Schedule slip', slip, (e.day_slippage || 0) > 0 ? '#dc2626' : '#059669')}
            </div>
            ${e.late_phase ? '<div class="tk-muted" style="color:#d97706;font-weight:700;">⚠ Late phase (≥70% complete): SPI loses meaning here — trust the day-slippage above.</div>' : ''}
            ${e.bac === null ? '<div class="tk-muted">Contract value not set yet — money metrics (EV/PV/CPI/EAC) appear once it is entered.</div>' : ''}
            ${evmChartSvg(d.series, !!e.plan_available)}`;
    } catch (err) {
        box.innerHTML = '<span class="tk-muted">Forecast unavailable</span>';
    }
}

function closeDetail() { closeOverlay('detailModal'); }

// ============================================
// OVERLAY HELPERS
// ============================================
function openOverlay(id) {
    const el = $(id);
    if (el) { el.classList.add('open'); document.body.style.overflow = 'hidden'; }
}

function closeOverlay(id) {
    const el = $(id);
    if (el) { el.classList.remove('open'); document.body.style.overflow = ''; }
}

function openUpdateModal() {
    if (!PROJECTS.length) { toast('No projects available', 'inf'); return; }
    const sel = $('updateProjectSelect');
    if (sel) {
        sel.innerHTML = PROJECTS.map(p => `<option value="${p.id}">${esc(p.project_name)} - ${esc(p.client_name)}</option>`).join('');
    }
    openOverlay('updateModal');
}

function editSelectedProject() {
    const sel = $('updateProjectSelect');
    if (!sel) return;
    closeOverlay('updateModal');
    editProject(sel.value);
}

// ============================================
// DELETE, PRINT, PDF
// ============================================
function deleteProject(id, name, version) {
    if (!Number.isSafeInteger(version) || version < 1) {
        toast('This project must be refreshed before it can be archived.', 'err');
        return;
    }
    if (!confirm(`Archive "${name}"? You can restore it through the Archive workflow.`)) return;
    api('delete_project.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, version })
    }).then(d => {
        if (d.success) { toast('Project archived', 'ok'); loadProjects(); }
        else toast(d.error || 'Failed', 'err');
    });
}

async function openArchivedProjects() {
    openOverlay('archivedProjectsModal');
    await loadArchivedProjects();
}

async function loadArchivedProjects() {
    const tbody = $('archivedProjectsTableBody');
    const empty = $('archivedProjectsEmpty');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="files-empty">Loading...</td></tr>';
    if (empty) empty.style.display = 'none';

    try {
        const data = await api('load_projects.php?archived=1');
        if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="5" class="files-empty">${esc(data.error || 'Failed to load archive')}</td></tr>`;
            return;
        }
        const archived = Array.isArray(data.projects) ? data.projects : [];
        if (!archived.length) {
            tbody.innerHTML = '';
            if (empty) empty.style.display = 'block';
            return;
        }
        tbody.innerHTML = archived.map(project => {
            const id = parseInt(project.id, 10) || 0;
            const version = parseInt(project.version, 10) || 0;
            return `<tr>
                <td>${esc(project.project_name)}</td>
                <td>${esc(project.client_name)}</td>
                <td>${esc(project.deleted_at || '-')}<br><small>${esc(project.deleted_by_name || '')}</small></td>
                <td>${esc(project.deletion_reason || '-')}</td>
                <td><button type="button" class="btn btn-sm btn-open" data-action="restore-project" data-project-id="${id}" data-project-version="${version}">Restore</button></td>
            </tr>`;
        }).join('');
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="files-empty">${esc(e.message)}</td></tr>`;
    }
}

async function restoreArchivedProject(id, version) {
    if (!Number.isSafeInteger(id) || id <= 0 || !Number.isSafeInteger(version) || version < 1) {
        toast('Invalid archived project', 'err');
        return;
    }
    if (!confirm('Restore this project and its existing access assignments?')) return;
    const data = await api('restore_project.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, version })
    });
    if (data.success) {
        toast('Project restored', 'ok');
        await Promise.all([loadArchivedProjects(), loadProjects()]);
    } else {
        toast(data.error || 'Project restore failed', 'err');
    }
}

function doPrint(id) {
    const w = window.open('generate_pdf.php?id=' + id + '&mode=print', '_blank');
    if (w) w.addEventListener('load', () => setTimeout(() => w.print(), 700));
}

function doPdf(id) { window.open('generate_pdf.php?id=' + id + '&mode=pdf', '_blank'); }

// ============================================
// SEARCH
// ============================================
function handleSearch(e) {
    if (e.key === 'Enter') searchProjects();
    if (!$('searchInput').value.trim()) loadProjects();
}
function searchProjects() { loadProjects($('searchInput').value.trim()); }

// ============================================
// SETTINGS
// ============================================
async function openSettings() {
    await loadMeta();
    renderSettings();
    openOverlay('settingsModal');
}

function showSettingsTab(tab) {
    document.querySelectorAll('#settingsModal .tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#settingsModal .settings-panel').forEach(p => p.classList.remove('active'));
    if (event && event.target) event.target.classList.add('active');
    const panel = $('settings-' + tab);
    if (panel) panel.classList.add('active');
}

function renderSettings() {
    renderEngineerSettings();
    renderStatusSettings();
    renderSectionSettings();
}

function renderEngineerSettings() {
    const box = $('settings-engineers');
    if (!box) return;
    box.innerHTML = `
        <div class="settings-box">
            <h3 style="margin-bottom:10px;">Engineers / Architects</h3>
            <textarea id="engineersText" rows="12" style="width:100%;border:1px solid #e2e8f0;border-radius:12px;padding:12px;font-family:inherit;">${META.engineers.map(e => esc(e.name)).join('\n')}</textarea>
            <div style="margin-top:12px;">
                <button type="button" class="btn btn-primary" data-action="save-engineers-settings">Save Engineers</button>
            </div>
        </div>`;
}

async function saveEngineersSettings() {
    const names = $('engineersText').value.split('\n').map(x => x.trim()).filter(Boolean);
    const d = await postJSON({ action: 'save_engineers', names });
    if (d.success) { META = d.meta; toast('Saved', 'ok'); renderEngineerSettings(); }
    else toast(d.error || 'Failed', 'err');
}

function renderStatusSettings() {
    const box = $('settings-statuses');
    if (!box) return;
    box.innerHTML = `
        <div class="settings-box">
            <h3 style="margin-bottom:10px;">Statuses</h3>
            <div id="statusRows">${META.statuses.map(s => statusRowHTML(s)).join('')}</div>
            <button type="button" class="add-mini" data-action="add-status-row">＋ Add Status</button>
            <button type="button" class="btn btn-primary" style="margin-left:8px;" data-action="save-status-settings">Save</button>
        </div>`;
}

function statusRowHTML(s = {}) {
    return `<div class="settings-row status-setting-row" data-id="${s.id || 0}">
        <input type="text" class="set-status-name" value="${esc(s.name || '')}">
        <input type="number" class="set-status-percent" min="0" max="100" value="${esc(s.percent ?? 0)}">
        <input type="color" class="set-status-color" value="${esc(s.color || '#6366f1')}">
        <button type="button" class="icon-btn" data-action="remove-closest" data-remove-selector=".status-setting-row">×</button>
    </div>`;
}

function addStatusRow() { $('statusRows').insertAdjacentHTML('beforeend', statusRowHTML({})); }

async function saveStatusSettings() {
    const statuses = [...document.querySelectorAll('.status-setting-row')].map(row => ({
        id: parseInt(row.dataset.id) || 0,
        name: row.querySelector('.set-status-name').value.trim(),
        percent: parseInt(row.querySelector('.set-status-percent').value) || 0,
        color: row.querySelector('.set-status-color').value
    })).filter(s => s.name);
    const d = await postJSON({ action: 'save_statuses', statuses });
    if (d.success) { META = d.meta; toast('Saved', 'ok'); renderStatusSettings(); loadProjects(); }
    else toast(d.error || 'Failed', 'err');
}

function renderSectionSettings() {
    const box = $('settings-sections');
    if (!box) return;
    box.innerHTML = `
        <div class="settings-box">
            <h3 style="margin-bottom:10px;">Sections & Weights</h3>
            <div id="sectionSettingsList">${META.sections.map(s => sectionSettingsHTML(s)).join('')}</div>
            <button type="button" class="add-mini" data-action="add-section-card">＋ Add Section</button>
            <button type="button" class="btn btn-primary" style="margin-left:8px;" data-action="save-section-settings">Save</button>
        </div>`;
}

function sectionSettingsHTML(section = {}) {
    return `<div class="settings-section-card" data-id="${section.id || 0}">
        <div class="settings-section-head">
            <input type="text" class="set-section-icon" value="${esc(section.icon || '📌')}">
            <input type="text" class="set-section-name" value="${esc(section.name || '')}">
            <input type="number" class="set-section-weight" value="${esc(section.weight ?? 0)}">
            <button type="button" class="icon-btn" data-action="remove-closest" data-remove-selector=".settings-section-card">×</button>
        </div>
        <div class="settings-items">${(section.items || []).map(item => itemSettingsHTML(item)).join('')}</div>
        <button type="button" class="add-mini" data-action="add-item-row">＋ Add Item</button>
    </div>`;
}

function itemSettingsHTML(item = {}) {
    return `<div class="settings-item-row" data-id="${item.id || 0}">
        <input type="text" class="set-item-name" value="${esc(item.name || '')}">
        <input type="number" class="set-item-weight" value="${esc(item.weight ?? 0)}">
        <select class="set-item-assignee">
            <option value="none" ${item.assignee_type==='none'?'selected':''}>No Assignee</option>
            <option value="engineer" ${item.assignee_type==='engineer'?'selected':''}>Engineer</option>
            <option value="architect" ${item.assignee_type==='architect'?'selected':''}>Architect</option>
        </select>
        <select class="set-item-comment">
            <option value="1" ${parseInt(item.has_comment??1)?'selected':''}>Comment</option>
            <option value="0" ${!parseInt(item.has_comment??1)?'selected':''}>No Comment</option>
        </select>
        <button type="button" class="icon-btn" data-action="remove-closest" data-remove-selector=".settings-item-row">×</button>
    </div>`;
}

function addSectionSettingsCard() {
    $('sectionSettingsList').insertAdjacentHTML('beforeend', sectionSettingsHTML({ id:0, icon:'📌', name:'', weight:0, items:[] }));
}

function addItemSettingsRow(btn) {
    btn.closest('.settings-section-card').querySelector('.settings-items')
       .insertAdjacentHTML('beforeend', itemSettingsHTML({ id:0, name:'', weight:0, assignee_type:'none', has_comment:1 }));
}

async function saveSectionSettings() {
    const sections = [...document.querySelectorAll('.settings-section-card')].map(card => ({
        id: parseInt(card.dataset.id) || 0,
        icon: card.querySelector('.set-section-icon').value.trim() || '📌',
        name: card.querySelector('.set-section-name').value.trim(),
        weight: parseFloat(card.querySelector('.set-section-weight').value) || 0,
        items: [...card.querySelectorAll('.settings-item-row')].map(row => ({
            id: parseInt(row.dataset.id) || 0,
            name: row.querySelector('.set-item-name').value.trim(),
            weight: parseFloat(row.querySelector('.set-item-weight').value) || 0,
            assignee_type: row.querySelector('.set-item-assignee').value,
            has_comment: row.querySelector('.set-item-comment').value === '1'
        })).filter(i => i.name)
    })).filter(s => s.name);

    const d = await postJSON({ action: 'save_sections', sections });
    if (d.success) { META = d.meta; toast('Saved', 'ok'); renderSectionSettings(); loadProjects(); }
    else toast(d.error || 'Failed', 'err');
}

// ============================================
// USER MANAGEMENT
// ============================================
async function openUsersModal() {
    openOverlay('usersModal');
    loadUsers();
}

async function loadUsers() {
    const d = await api('users.php?action=list');
    if (!d.success) return;
    ALL_USERS = d.users;
    const tbody = $('usersTableBody');
    if (tbody) {
        tbody.innerHTML = ALL_USERS.map(u => `
            <tr>
                <td>${esc(u.name)}</td>
                <td>${esc(u.username)}</td>
                <td><span class="badge ${u.role==='admin'?'b-ip':'b-ok'}">${esc(u.role === 'admin' ? 'Admin / Head Engineer' : (u.account_type === 'client' ? 'Client / Owner' : 'Employee'))}</span></td>
                <td><span class="badge ${parseInt(u.active)===1?'b-ok':'b-rv'}">${parseInt(u.active)===1 ? 'Active' : 'Inactive'}</span></td>
                <td>
                    <button type="button" class="btn btn-sm btn-edit" data-action="edit-user" data-user-id="${parseInt(u.id) || 0}">✏️</button>
                    ${parseInt(u.active) === 1
                        ? `<button type="button" class="btn btn-sm btn-del" data-action="deactivate-user" data-user-id="${parseInt(u.id) || 0}" title="Deactivate">⛔</button>`
                        : `<button type="button" class="btn btn-sm btn-open" data-action="activate-user" data-user-id="${parseInt(u.id) || 0}" title="Activate">↻</button>`}
                </td>
            </tr>`).join('');
    }
}

function showUserTab(tab, btn) {
    document.querySelectorAll('.user-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('#userFormModal .tab-btn').forEach(b => b.classList.remove('active'));
    const tabEl = document.getElementById('usertab-' + tab);
    if (tabEl) tabEl.classList.add('active');
    if (btn) btn.classList.add('active');
    if (tab === 'perms') {
        setTimeout(() => { loadSpecificProjects(); toggleProjectsList(); }, 100);
    }
}

function openUserForm() {
    USER_PROJECT_ACCESS_LOADED = false;
    ['u_id','u_name','u_username','u_password'].forEach(id => { const el = $(id); if (el) el.value = id === 'u_id' ? '0' : ''; });
    const role = $('u_role'); if (role) role.value = 'user';
    const accountType = $('u_account_type'); if (accountType) accountType.value = 'employee';
    ['view_all_projects','add','edit','delete','print','pdf','files','priorities','settings'].forEach(p => {
        const el = $('p_' + p); if (el) el.checked = false;
    });
    togglePerms();
    const infoBtn = document.querySelector('#userFormModal .tab-btn');
    if (infoBtn) showUserTab('info', infoBtn);
    openOverlay('userFormModal');
}

function editUser(id) {
    USER_PROJECT_ACCESS_LOADED = false;
    const u = ALL_USERS.find(x => x.id == id);
    if (!u) return;
    $('u_id').value = u.id;
    $('u_name').value = u.name;
    $('u_username').value = u.username;
    $('u_password').value = '';
    $('u_role').value = u.role;
    const accountType = $('u_account_type'); if (accountType) accountType.value = u.account_type || (u.role === 'admin' ? 'admin' : 'employee');
    ['view_all_projects','add','edit','delete','print','pdf','files','priorities','settings'].forEach(p => {
        const el = $('p_' + p);
        if (el) el.checked = u.permissions && u.permissions[p] === true;
    });
    togglePerms();
    const infoBtn = document.querySelector('#userFormModal .tab-btn');
    if (infoBtn) showUserTab('info', infoBtn);
    openOverlay('userFormModal');
}

function togglePerms() {
    const box = $('u_perms_box');
    const role = $('u_role') ? $('u_role').value : 'user';
    const accountType = $('u_account_type');
    if (accountType) {
        if (role === 'admin') {
            accountType.value = 'admin';
            accountType.disabled = true;
        } else {
            if (accountType.value === 'admin') accountType.value = 'employee';
            accountType.disabled = false;
        }
    }
    if (!box) return;
    const isAdmin = role === 'admin';
    box.style.opacity = isAdmin ? '0.4' : '1';
    box.style.pointerEvents = isAdmin ? 'none' : 'auto';
}

function toggleProjectsList() {
    const viewAll = $('p_view_all_projects') && $('p_view_all_projects').checked;
    const box = $('specificProjectsBox');
    if (box) {
        box.style.opacity = viewAll ? '0.4' : '1';
        box.style.pointerEvents = viewAll ? 'none' : 'auto';
    }
}

async function loadSpecificProjects() {
    const userId = $('u_id') ? $('u_id').value : '0';
    const box = $('specificProjectsList');
    if (!box) return;

    if (!userId || userId === '0') {
        box.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:20px;">⚠️ First save the user, then set project access.</div>';
        return;
    }

    box.innerHTML = '<div style="color:#94a3b8;text-align:center;">Loading...</div>';

    try {
        const data = await api('users.php?action=get_user_access&user_id=' + encodeURIComponent(userId));
        if (!data.success) { box.innerHTML = '<div style="color:red;">Error: ' + (data.error || 'Failed') + '</div>'; return; }
        USER_PROJECT_ACCESS_LOADED = true;

        if (!data.projects || !data.projects.length) {
            box.innerHTML = '<div style="color:#94a3b8;text-align:center;">No projects available</div>';
            return;
        }

        box.innerHTML = data.projects.map(p => {
            const isChecked = p.access && p.access.view ? 'checked' : '';
            return `
                <label style="display:flex;align-items:center;gap:10px;padding:10px;border-bottom:1px solid #e2e8f0;cursor:pointer;">
                    <input type="checkbox" class="project-check" data-project="${p.id}" ${isChecked}>
                    <div>
                        <div style="font-weight:900;color:#0f172a;">${esc(p.name)}</div>
                        <div style="font-size:.75rem;color:#94a3b8;">${esc(p.client)}</div>
                    </div>
                </label>`;
        }).join('');
    } catch (e) {
        box.innerHTML = '<div style="color:red;">Error: ' + e.message + '</div>';
    }
}

window.saveUser = async function() {
    const id = $('u_id') ? $('u_id').value : '0';
    const name = $('u_name') ? $('u_name').value.trim() : '';
    const username = $('u_username') ? $('u_username').value.trim() : '';
    const password = $('u_password') ? $('u_password').value : '';
    const role = $('u_role') ? $('u_role').value : 'user';
    const accountType = $('u_account_type') ? $('u_account_type').value : 'employee';

    if (!name) { alert('Name is required'); return; }
    if (!username) { alert('Username is required'); return; }
    if (id === '0' && password.length < 10) { alert('A password of at least 10 characters is required for a new user'); return; }
    if (id !== '0' && password && password.length < 10) { alert('A new password must be at least 10 characters'); return; }

    const permissions = {};
    ['view_all_projects','add','edit','delete','print','pdf','files','priorities','settings'].forEach(p => {
        const el = $('p_' + p);
        permissions[p] = el ? el.checked : false;
    });

    // گرفتن پروژه‌های انتخاب شده
    const selectedProjects = [];
    document.querySelectorAll('.project-check:checked').forEach(cb => {
        selectedProjects.push(cb.dataset.project);
    });

    const payload = {
        action: 'save',
        id, name, username, password, role,
        account_type: accountType,
        permissions
    };
    // Do not accidentally remove existing assignments when the administrator
    // saves only the profile tab. Once the access tab is loaded, an explicit
    // empty selection deliberately clears access.
    if (USER_PROJECT_ACCESS_LOADED) {
        payload.project_access = selectedProjects;
    }

    try {
        const data = await api('users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (data.success) {
            toast(data.message || 'User saved', 'ok');
            $('u_id').value = data.id;
            loadUsers();
            closeOverlay('userFormModal');
        } else {
            alert('Error: ' + (data.error || 'Unknown'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
};

async function deleteUser(id) {
    if (!confirm('Deactivate this user? Their active sessions will be revoked.')) return;
    const d = await api('users.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete',id}) });
    if (d.success) { toast('User deactivated', 'ok'); loadUsers(); }
    else toast(d.error || 'Failed', 'err');
}

async function activateUser(id) {
    const d = await api('users.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'activate',id}) });
    if (d.success) { toast('User activated', 'ok'); loadUsers(); }
    else toast(d.error || 'Failed', 'err');
}

// ============================================
// USER MENU
// ============================================
function toggleUserMenu() {
    const menu = document.querySelector('.user-menu');
    if (menu) menu.classList.toggle('open');
}

document.addEventListener('click', function(e) {
    const menu = document.querySelector('.user-menu');
    if (menu && !menu.contains(e.target)) menu.classList.remove('open');
});

// ============================================
// FILES MANAGER
// ============================================
let FM_PROJECT_ID = 0;
let FM_CURRENT_PATH = '';

function openFiles(projectId, projectName = '') {
    FM_PROJECT_ID = projectId;
    FM_CURRENT_PATH = '';
    const pid = $('filesProjectId'); if (pid) pid.value = projectId;
    const cp = $('filesCurrentPath'); if (cp) cp.value = '';
    const title = $('filesTitle'); if (title) title.textContent = '📁 Files - ' + (projectName || 'Project #' + projectId);
    openOverlay('filesModal');
    fmLoadCurrentFolder();
}

function closeFilesModal() { closeOverlay('filesModal'); }
function fmReload() { fmLoadCurrentFolder(); }
function fmGoHome() { FM_CURRENT_PATH = ''; const cp = $('filesCurrentPath'); if (cp) cp.value = ''; fmLoadCurrentFolder(); }
function fmOpenFolder(path) { FM_CURRENT_PATH = path; const cp = $('filesCurrentPath'); if (cp) cp.value = path; fmLoadCurrentFolder(); }

async function fmLoadCurrentFolder() {
    const box = $('fmContent');
    if (!box) return;
    box.innerHTML = '<div class="files-empty">Loading...</div>';

    // مخفی کردن Upload در Home
    const isHome = (FM_CURRENT_PATH === '' || FM_CURRENT_PATH === null);
    const uploadBtn = document.querySelector('label[for="fmUploadInput"]');
    const newFolderBtn = document.querySelector('.fm-toolbar .btn-primary');
    if (uploadBtn) uploadBtn.style.display = isHome ? 'none' : '';
    if (newFolderBtn) newFolderBtn.style.display = isHome ? 'none' : '';

    try {
        loading(true);
        const url = 'file_manager.php?action=list&project_id=' + encodeURIComponent(FM_PROJECT_ID) + '&path=' + encodeURIComponent(FM_CURRENT_PATH);
        const data = await api(url);

        if (data && data.auth === false) { window.location.href = 'index.php'; return; }
        if (!data.success) { box.innerHTML = `<div class="files-empty">${esc(data.error || 'Failed')}</div>`; return; }

        const crumbBox = $('fmBreadcrumbs');
        if (crumbBox && data.breadcrumbs) {
            crumbBox.innerHTML = data.breadcrumbs.map((c, idx) => {
                const isLast = idx === data.breadcrumbs.length - 1;
                return `<button type="button" class="${isLast ? 'fm-crumb current' : 'fm-crumb'}" data-action="fm-open" data-fm-path="${esc(c.path)}">${esc(c.name)}</button>`;
            }).join('<span class="fm-sep">/</span>');
        }

        if ((!data.folders || !data.folders.length) && (!data.files || !data.files.length)) {
            box.innerHTML = '<div class="files-empty">This folder is empty.</div>';
            return;
        }

        let html = '<div class="fm-grid">';

        (data.folders || []).forEach(folder => {
            const folderPath = esc(folder.path);
            const folderName = esc(folder.name);
            if (folder.is_default) {
                html += `
                    <div class="fm-item fm-folder fm-folder-default" data-double-action="fm-open" data-fm-path="${folderPath}">
                        <div class="fm-icon">📁</div>
                        <div class="fm-name">${folderName}</div>
                        <div class="fm-meta">Default folder</div>
                        <div class="fm-actions">
                            <button type="button" class="btn btn-sm btn-open" data-action="fm-open" data-fm-path="${folderPath}">Open</button>
                        </div>
                    </div>`;
            } else {
                html += `
                    <div class="fm-item fm-folder" data-double-action="fm-open" data-fm-path="${folderPath}">
                        <div class="fm-icon">📁</div>
                        <div class="fm-name">${folderName}</div>
                        <div class="fm-meta">${esc(folder.modified)}</div>
                        <div class="fm-actions">
                            <button type="button" class="btn btn-sm btn-open" data-action="fm-open" data-fm-path="${folderPath}">Open</button>
                            <button type="button" class="btn btn-sm btn-edit" data-action="fm-rename" data-fm-path="${folderPath}" data-fm-name="${folderName}">Rename</button>
                            <button type="button" class="btn btn-sm btn-del" data-action="fm-delete" data-fm-path="${folderPath}" data-fm-name="${folderName}" data-fm-folder="1">Delete</button>
                        </div>
                    </div>`;
            }
        });

        (data.files || []).forEach(file => {
            const filePath = esc(file.path);
            const fileName = esc(file.name);
            html += `
                <div class="fm-item fm-file">
                    <div class="fm-icon">${fmFileIcon(file.ext)}</div>
                    <div class="fm-name">${fileName}</div>
                    <div class="fm-meta">${esc(String(file.ext||'').toUpperCase())} • ${esc(file.size_text)} • ${esc(file.modified)}</div>
                    <div class="fm-actions">
                        <a class="btn btn-sm btn-files" href="${esc(file.download_url)}" target="_blank" rel="noopener">Download</a>
                        <button type="button" class="btn btn-sm btn-edit" data-action="fm-rename" data-fm-path="${filePath}" data-fm-name="${fileName}">Rename</button>
                        <button type="button" class="btn btn-sm btn-del" data-action="fm-delete" data-fm-path="${filePath}" data-fm-name="${fileName}" data-fm-folder="0">Delete</button>
                    </div>
                </div>`;
        });

        html += '</div>';
        box.innerHTML = html;
    } catch (e) {
        box.innerHTML = `<div class="files-empty">Error: ${esc(e.message)}</div>`;
    } finally {
        loading(false);
    }
}

function fmFileIcon(ext) {
    ext = String(ext || '').toLowerCase();
    if (['jpg','jpeg','png','gif','webp'].includes(ext)) return '🖼';
    if (['zip','rar','7z'].includes(ext)) return '🗜';
    if (['pdf'].includes(ext)) return '📕';
    if (['doc','docx'].includes(ext)) return '📘';
    if (['xls','xlsx','csv'].includes(ext)) return '📗';
    if (['dwg','dxf'].includes(ext)) return '📐';
    return '📄';
}

async function fmCreateFolder() {
    const name = prompt('New folder name:');
    if (!name || !name.trim()) return;
    loading(true);
    try {
        const data = await api('file_manager.php?action=create_folder', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ project_id:FM_PROJECT_ID, path:FM_CURRENT_PATH, name:name.trim() }) });
        if (data.success) { toast('Folder created', 'ok'); fmLoadCurrentFolder(); }
        else toast(data.error || 'Failed', 'err');
    } catch(e) { toast('Error: '+e.message,'err'); }
    finally { loading(false); }
}

async function fmUploadFiles(fileList) {
    if (!fileList || !fileList.length) return;
    const formData = new FormData();
    formData.append('action','upload');
    formData.append('project_id', FM_PROJECT_ID);
    formData.append('path', FM_CURRENT_PATH);
    for (let i = 0; i < fileList.length; i++) formData.append('files[]', fileList[i]);
    loading(true);
    try {
        const data = await api('file_manager.php?action=upload', { method:'POST', body:formData });
        if (data.success) { toast(data.message || 'Uploaded', 'ok'); fmLoadCurrentFolder(); }
        else toast(data.error || 'Failed', 'err');
        const input = $('fmUploadInput'); if (input) input.value = '';
    } catch(e) { toast('Error: '+e.message,'err'); }
    finally { loading(false); }
}

async function fmRename(path, currentName) {
    const newName = prompt('New name:', currentName);
    if (!newName || !newName.trim() || newName === currentName) return;
    loading(true);
    try {
        const data = await api('file_manager.php?action=rename', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ project_id:FM_PROJECT_ID, path, new_name:newName.trim() }) });
        if (data.success) { toast('Renamed', 'ok'); fmLoadCurrentFolder(); }
        else toast(data.error || 'Failed', 'err');
    } catch(e) { toast('Error: '+e.message,'err'); }
    finally { loading(false); }
}

async function fmDelete(path, name, isFolder) {
    if (!confirm((isFolder ? 'Delete folder "' : 'Delete file "') + name + '"?')) return;
    loading(true);
    try {
        const data = await api('file_manager.php?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ project_id:FM_PROJECT_ID, path }) });
        if (data.success) { toast('Deleted', 'ok'); fmLoadCurrentFolder(); }
        else toast(data.error || 'Failed', 'err');
    } catch(e) { toast('Error: '+e.message,'err'); }
    finally { loading(false); }
}

// ============================================
// CSP-SAFE EVENT DELEGATION
// ============================================
const NAWARA_OVERLAYS = new Set([
    'updateModal', 'settingsModal', 'tasksModal', 'taskFormPanel', 'taskDetailPanel', 'usersModal', 'userFormModal', 'filesModal', 'detailModal', 'projectModal', 'archivedProjectsModal', 'reportsModal', 'employeesModal'
]);
const NAWARA_REMOVE_SELECTORS = new Set([
    '.status-setting-row', '.settings-section-card', '.settings-item-row'
]);

function delegatedProjectId(el) {
    const id = Number.parseInt(el.dataset.projectId || '', 10);
    return Number.isSafeInteger(id) && id > 0 ? id : 0;
}

function delegatedUserId(el) {
    const id = Number.parseInt(el.dataset.userId || '', 10);
    return Number.isSafeInteger(id) && id > 0 ? id : 0;
}

function delegatedProject(id) {
    return PROJECTS.find(project => Number(project.id) === id) || null;
}

async function dispatchNawaraAction(el, event) {
    const action = el.dataset.action;
    if (!action) return;

    switch (action) {
        case 'toast-close':
            el.closest('.toast')?.remove();
            return;
        case 'toggle-user-menu':
            toggleUserMenu();
            return;
        case 'open-project-form':
            await openProjectForm();
            return;
        case 'close-project-form':
            closeProjectForm();
            return;
        case 'save-project':
        case 'project-form':
            await saveProject();
            return;
        case 'open-update-modal':
            openUpdateModal();
            return;
        case 'edit-selected-project':
            editSelectedProject();
            return;
        case 'open-detail': {
            const id = delegatedProjectId(el);
            if (id) await openDetail(id);
            return;
        }
        case 'edit-project': {
            const id = delegatedProjectId(el);
            if (id) await editProject(id);
            return;
        }
        case 'edit-detail-project': {
            const id = delegatedProjectId(el);
            if (id) {
                closeDetail();
                await editProject(id);
            }
            return;
        }
        case 'archive-project': {
            const id = delegatedProjectId(el);
            const project = delegatedProject(id);
            const version = Number.parseInt(el.dataset.projectVersion || '', 10);
            if (project) deleteProject(id, String(project.project_name || 'this project'), Number.isSafeInteger(version) ? version : 0);
            return;
        }
        case 'open-archived-projects':
            await openArchivedProjects();
            return;
        case 'restore-project': {
            const id = delegatedProjectId(el);
            const version = Number.parseInt(el.dataset.projectVersion || '', 10);
            await restoreArchivedProject(id, Number.isSafeInteger(version) ? version : 0);
            return;
        }
        case 'open-project-files': {
            const id = delegatedProjectId(el);
            const project = delegatedProject(id);
            if (project) openFiles(id, String(project.project_name || ''));
            return;
        }
        case 'print-project': {
            const id = delegatedProjectId(el);
            if (id) doPrint(id);
            return;
        }
        case 'pdf-project': {
            const id = delegatedProjectId(el);
            if (id) doPdf(id);
            return;
        }
        case 'close-detail':
            closeDetail();
            return;
        case 'close-files-modal':
            closeFilesModal();
            return;
        case 'close-overlay': {
            const overlay = el.dataset.overlay || '';
            if (NAWARA_OVERLAYS.has(overlay)) closeOverlay(overlay);
            return;
        }
        case 'refresh-projects':
            await loadProjects();
            return;
        case 'search-projects':
            searchProjects();
            return;
        case 'open-settings':
            await openSettings();
            return;
        case 'show-settings-tab': {
            const tab = el.dataset.tab || '';
            if (['engineers', 'statuses', 'sections'].includes(tab)) showSettingsTab(tab);
            return;
        }
        case 'save-engineers-settings':
            await saveEngineersSettings();
            return;
        case 'add-status-row':
            addStatusRow();
            return;
        case 'save-status-settings':
            await saveStatusSettings();
            return;
        case 'add-section-card':
            addSectionSettingsCard();
            return;
        case 'add-item-row':
            addItemSettingsRow(el);
            return;
        case 'save-section-settings':
            await saveSectionSettings();
            return;
        case 'remove-closest': {
            const selector = el.dataset.removeSelector || '';
            if (NAWARA_REMOVE_SELECTORS.has(selector)) el.closest(selector)?.remove();
            return;
        }
        case 'toggle-section':
            toggleSectionBody(el);
            return;
        case 'open-users-modal':
            await openUsersModal();
            return;
        case 'open-user-form':
            openUserForm();
            return;
        case 'edit-user': {
            const id = delegatedUserId(el);
            if (id) editUser(id);
            return;
        }
        case 'deactivate-user': {
            const id = delegatedUserId(el);
            if (id) await deleteUser(id);
            return;
        }
        case 'activate-user': {
            const id = delegatedUserId(el);
            if (id) await activateUser(id);
            return;
        }
        case 'show-user-tab': {
            const tab = el.dataset.tab || '';
            if (['info', 'perms'].includes(tab)) showUserTab(tab, el);
            return;
        }
        case 'save-user':
            await window.saveUser();
            return;
        case 'file-create-folder':
            await fmCreateFolder();
            return;
        case 'file-reload':
            fmReload();
            return;
        case 'file-home':
            fmGoHome();
            return;
        case 'fm-open':
            fmOpenFolder(el.dataset.fmPath || '');
            return;
        case 'fm-rename':
            await fmRename(el.dataset.fmPath || '', el.dataset.fmName || '');
            return;
        case 'fm-delete':
            await fmDelete(el.dataset.fmPath || '', el.dataset.fmName || '', el.dataset.fmFolder === '1');
            return;
        default:
            return;
    }
}

function delegatedActionError(error) {
    console.error('NawAra action failed:', error);
    toast('The requested action could not be completed.', 'err');
}

document.addEventListener('click', event => {
    const el = event.target instanceof Element ? event.target.closest('[data-action]') : null;
    if (!el || ['FORM', 'INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)) return;
    // Click actions are controls only. Form and field actions are handled by
    // their own submit/change listeners so normal native field behavior stays intact.
    event.preventDefault();
    void dispatchNawaraAction(el, event).catch(delegatedActionError);
});

document.addEventListener('change', event => {
    const el = event.target instanceof Element ? event.target.closest('[data-action]') : null;
    if (!el) return;
    const action = el.dataset.action;
    if (action === 'engineer-select') {
        void handleEngineerAdd(el).catch(delegatedActionError);
    } else if (action === 'status-select') {
        void handleStatusAdd(el).then(updateLiveSectionProgress).catch(delegatedActionError);
    } else if (action === 'toggle-permissions') {
        togglePerms();
    } else if (action === 'toggle-project-list') {
        toggleProjectsList();
    } else if (action === 'file-upload-input') {
        void fmUploadFiles(el.files).catch(delegatedActionError);
        el.value = '';
    }
});

document.addEventListener('keyup', event => {
    const el = event.target instanceof Element ? event.target.closest('[data-action="project-search-input"]') : null;
    if (el) handleSearch(event);
});

document.addEventListener('submit', event => {
    const el = event.target instanceof Element ? event.target.closest('[data-action="project-form"]') : null;
    if (!el) return;
    event.preventDefault();
    void dispatchNawaraAction(el, event).catch(delegatedActionError);
});

document.addEventListener('dblclick', event => {
    if (event.target instanceof Element && event.target.closest('.fm-actions')) return;
    const el = event.target instanceof Element ? event.target.closest('[data-double-action="fm-open"]') : null;
    if (!el) return;
    fmOpenFolder(el.dataset.fmPath || '');
});

document.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const el = event.target instanceof Element ? event.target.closest('[data-action]') : null;
    if (!el || !(el.getAttribute('role') === 'button' || el.dataset.action === 'toggle-section')) return;
    event.preventDefault();
    void dispatchNawaraAction(el, event).catch(delegatedActionError);
});

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', async () => {
    await loadMeta();
    const lead = $('leadEngineer');
    if (lead) lead.innerHTML = engineerOptions('');
    await loadProjects();
});