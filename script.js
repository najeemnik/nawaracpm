'use strict';

// ============================================
// SAFE DEFAULTS
// ============================================
if (typeof window.CURRENT_USER === 'undefined' || !window.CURRENT_USER) {
    window.CURRENT_USER = {
        name: 'Admin',
        role: 'admin',
        permissions: {}
    };
}
if (!window.CURRENT_USER.permissions) {
    window.CURRENT_USER.permissions = {};
}

// ============================================
// HELPERS
// ============================================
const $ = id => document.getElementById(id);

let META = { engineers: [], statuses: [], sections: [] };
let PROJECTS = [];
let CURRENT_PROJECT = null;
let ALL_USERS = [];
let USER_PROJECT_ACCESS_LOADED = false;

const esc = s => {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
};

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
        <button class="toast-cls" onclick="this.parentElement.remove()">✕</button>
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
        const safeName = String(p.project_name || '').replace(/'/g, "\\'");
        
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
                <td><span class="pname" onclick="openDetail(${p.id})">${esc(p.project_name)}</span></td>
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
                        <button class="btn btn-sm btn-open" onclick="openDetail(${p.id})" title="Open">👁</button>
                        ${canEdit ? `<button class="btn btn-sm btn-edit" onclick="editProject(${p.id})" title="Edit">✏️</button>` : ''}
                        ${canFiles ? `<button class="btn btn-sm btn-files" onclick="openFiles(${p.id}, '${safeName}')" title="Files">📁</button>` : ''}
                        ${canDelete ? `<button class="btn btn-sm btn-del" onclick="deleteProject(${p.id}, '${safeName}')" title="Delete">🗑</button>` : ''}
                        ${canPrint ? `<button class="btn btn-sm btn-print" onclick="doPrint(${p.id})" title="Print">🖨</button>` : ''}
                        ${canPdf ? `<button class="btn btn-sm btn-pdf" onclick="doPdf(${p.id})" title="PDF">📄</button>` : ''}
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
                    <select class="engineer-select item-assignee" data-item="${item.id}" onchange="handleEngineerAdd(this)">
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
                            <select class="status-select item-status" data-item="${item.id}" onchange="handleStatusAdd(this); updateLiveSectionProgress();">
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
                <div class="fsec-head" onclick="toggleSectionBody(this)">
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
    const fields = ['projectId','projectName','clientName','zone','startDate','endDate','description'];
    fields.forEach(id => { const el = $(id); if (el) el.value = id === 'projectId' ? '0' : ''; });
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
    if (!name) { toast('Project name is required', 'err'); $('projectName').focus(); return; }
    if (!client) { toast('Client name is required', 'err'); $('clientName').focus(); return; }

    const payload = {
        action: 'save_project',
        project: {
            id: parseInt($('projectId').value) || 0,
            project_name: name,
            client_name: client,
            zone: $('zone').value.trim(),
            lead_engineer_id: $('leadEngineer').value && !String($('leadEngineer').value).startsWith('__') ? $('leadEngineer').value : '',
            start_date: $('startDate').value,
            end_date: $('endDate').value,
            description: $('description').value.trim()
        },
        values: collectProjectValues()
    };

    const d = await postJSON(payload);
    if (d.success) {
        toast('Project saved successfully', 'ok');
        closeProjectForm();
        await loadProjects();
    } else {
        toast(d.error || 'Save failed', 'err');
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
    const body = $('detailBody');
    if (body) body.innerHTML = info + secProgress + sectionsHtml;

    // دکمه Edit بر اساس permission
    const editBtn = $('detailEditBtn');
    if (editBtn) {
        if (canEdit) {
            editBtn.style.display = 'inline-flex';
            editBtn.onclick = () => { closeDetail(); editProject(p.id); };
        } else {
            editBtn.style.display = 'none';
        }
    }

    openOverlay('detailModal');
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
function deleteProject(id, name) {
    if (!confirm(`Delete "${name}"?`)) return;
    api('delete_project.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
    }).then(d => {
        if (d.success) { toast('Deleted', 'ok'); loadProjects(); }
        else toast(d.error || 'Failed', 'err');
    });
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
                <button class="btn btn-primary" onclick="saveEngineersSettings()">Save Engineers</button>
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
            <button class="add-mini" onclick="addStatusRow()">＋ Add Status</button>
            <button class="btn btn-primary" style="margin-left:8px;" onclick="saveStatusSettings()">Save</button>
        </div>`;
}

function statusRowHTML(s = {}) {
    return `<div class="settings-row status-setting-row" data-id="${s.id || 0}">
        <input type="text" class="set-status-name" value="${esc(s.name || '')}">
        <input type="number" class="set-status-percent" min="0" max="100" value="${esc(s.percent ?? 0)}">
        <input type="color" class="set-status-color" value="${esc(s.color || '#6366f1')}">
        <button class="icon-btn" onclick="this.closest('.status-setting-row').remove()">×</button>
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
            <button class="add-mini" onclick="addSectionSettingsCard()">＋ Add Section</button>
            <button class="btn btn-primary" style="margin-left:8px;" onclick="saveSectionSettings()">Save</button>
        </div>`;
}

function sectionSettingsHTML(section = {}) {
    return `<div class="settings-section-card" data-id="${section.id || 0}">
        <div class="settings-section-head">
            <input type="text" class="set-section-icon" value="${esc(section.icon || '📌')}">
            <input type="text" class="set-section-name" value="${esc(section.name || '')}">
            <input type="number" class="set-section-weight" value="${esc(section.weight ?? 0)}">
            <button class="icon-btn" onclick="this.closest('.settings-section-card').remove()">×</button>
        </div>
        <div class="settings-items">${(section.items || []).map(item => itemSettingsHTML(item)).join('')}</div>
        <button class="add-mini" onclick="addItemSettingsRow(this)">＋ Add Item</button>
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
        <button class="icon-btn" onclick="this.closest('.settings-item-row').remove()">×</button>
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
// PRIORITIES
// ============================================
let PRI_ENGINEERS = [];
let PRI_FILTER = 'all';

async function openPriorities() {
    openOverlay('prioritiesModal');
    await priLoadTasks();
}

async function priLoadTasks() {
    const list = $('priTaskList');
    if (!list) return;
    list.innerHTML = '<div class="files-empty">Loading...</div>';
    try {
        const data = await api('priorities.php?action=list&filter=' + encodeURIComponent(PRI_FILTER));
        if (!data.success) { list.innerHTML = `<div class="files-empty">${esc(data.error || 'Failed')}</div>`; return; }
        PRI_ENGINEERS = data.engineers || [];
        priRenderAssigneeSelect();
        priRenderStats(data.stats);
        priRenderTasks(data.tasks || []);
    } catch (e) {
        list.innerHTML = `<div class="files-empty">Error: ${esc(e.message)}</div>`;
    }
}

function priRenderStats(stats) {
    const box = $('priStats');
    if (!box || !stats) return;
    const pct = stats.total > 0 ? Math.round((stats.done / stats.total) * 100) : 0;
    box.innerHTML = `
        <div class="pri-stat-card pri-stat-total"><span class="pri-stat-num">${stats.total}</span><span class="pri-stat-lbl">Total</span></div>
        <div class="pri-stat-card pri-stat-pending"><span class="pri-stat-num">${stats.pending}</span><span class="pri-stat-lbl">Pending</span></div>
        <div class="pri-stat-card pri-stat-done"><span class="pri-stat-num">${stats.done}</span><span class="pri-stat-lbl">Done</span></div>
        <div class="pri-stat-card pri-stat-progress"><span class="pri-stat-num">${pct}%</span><span class="pri-stat-lbl">Progress</span></div>`;
}

function priRenderAssigneeSelect() {
    const select = $('priNewAssignee');
    if (!select) return;
    let html = '<option value="">Select...</option>';
    PRI_ENGINEERS.forEach(e => { html += `<option value="${e.id}">${esc(e.name)}</option>`; });
    html += '<option value="__add_new__">＋ Add New</option>';
    select.innerHTML = html;
}

function priRenderTasks(tasks) {
    const list = $('priTaskList');
    if (!list) return;
    if (!tasks.length) { list.innerHTML = '<div class="files-empty">No tasks yet.</div>'; return; }
    list.innerHTML = tasks.map((task, i) => {
        const isDone = parseInt(task.is_done) === 1;
        return `
            <div class="pri-task-card ${isDone ? 'pri-task-done' : ''}">
                <div class="pri-task-check" onclick="priToggle(${task.id})">${isDone ? '☑' : '☐'}</div>
                <div class="pri-task-number">#${i + 1}</div>
                <div class="pri-task-body">
                    <div class="pri-task-title">${esc(task.title)}</div>
                    <div class="pri-task-meta">
                        <span class="pri-badge pri-badge-${task.priority}">${task.priority}</span>
                        ${task.assignee_name ? `<span>👤 ${esc(task.assignee_name)}</span>` : ''}
                        ${task.due_date ? `<span>📅 ${esc(task.due_date)}</span>` : ''}
                    </div>
                </div>
                <div class="pri-task-actions">
                    <button class="btn btn-sm btn-del" onclick="priDelete(${task.id})">🗑</button>
                </div>
            </div>`;
    }).join('');
}

async function priToggle(id) {
    const data = await api('priorities.php?action=toggle', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id}) });
    if (data.success) await priLoadTasks();
}

async function priDelete(id) {
    if (!confirm('Delete this task?')) return;
    const data = await api('priorities.php?action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id}) });
    if (data.success) { toast('Deleted', 'ok'); await priLoadTasks(); }
}

function priFilter(filter, btn) {
    PRI_FILTER = filter;
    document.querySelectorAll('.pri-filter-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    priLoadTasks();
}

async function priAddTask() {
    const titleEl = $('priNewTitle');
    const title = titleEl ? titleEl.value.trim() : '';
    if (!title) { toast('Task description is required', 'err'); return; }
    
    const assigneeEl = $('priNewAssignee');
    if (assigneeEl && assigneeEl.value === '__add_new__') {
        const name = prompt('Enter new person name:');
        if (!name || !name.trim()) { assigneeEl.value = ''; return; }
        const data = await api('priorities.php?action=add_engineer', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({name:name.trim()}) });
        if (data.success) { toast('Added', 'ok'); await priLoadTasks(); }
        return;
    }
    
    const payload = {
        title,
        assignee_id: assigneeEl ? assigneeEl.value : '',
        priority: $('priNewPriority') ? $('priNewPriority').value : 'medium',
        due_date: $('priNewDueDate') ? $('priNewDueDate').value : ''
    };
    
    const data = await api('priorities.php?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
    if (data.success) {
        toast('Task added', 'ok');
        if (titleEl) titleEl.value = '';
        if ($('priNewDueDate')) $('priNewDueDate').value = '';
        await priLoadTasks();
        priHideAddForm();
    } else {
        toast(data.error || 'Failed', 'err');
    }
}

function priShowAddForm() {
    const form = $('priAddForm');
    if (form) form.classList.add('open');
}

function priHideAddForm() {
    const form = $('priAddForm');
    if (form) form.classList.remove('open');
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
                    <button class="btn btn-sm btn-edit" onclick="editUser(${u.id})">✏️</button>
                    ${parseInt(u.active) === 1
                        ? `<button class="btn btn-sm btn-del" onclick="deleteUser(${u.id})" title="Deactivate">⛔</button>`
                        : `<button class="btn btn-sm btn-open" onclick="activateUser(${u.id})" title="Activate">↻</button>`}
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
                return `<span class="${isLast ? 'fm-crumb current' : 'fm-crumb'}" onclick="fmOpenFolder('${esc(c.path)}')">${esc(c.name)}</span>`;
            }).join('<span class="fm-sep">/</span>');
        }

        if ((!data.folders || !data.folders.length) && (!data.files || !data.files.length)) {
            box.innerHTML = '<div class="files-empty">This folder is empty.</div>';
            return;
        }

        let html = '<div class="fm-grid">';

        (data.folders || []).forEach(folder => {
            if (folder.is_default) {
                html += `
                    <div class="fm-item fm-folder fm-folder-default" ondblclick="fmOpenFolder('${esc(folder.path)}')">
                        <div class="fm-icon">📁</div>
                        <div class="fm-name">${esc(folder.name)}</div>
                        <div class="fm-meta">Default folder</div>
                        <div class="fm-actions">
                            <button class="btn btn-sm btn-open" onclick="event.stopPropagation();fmOpenFolder('${esc(folder.path)}')">Open</button>
                        </div>
                    </div>`;
            } else {
                html += `
                    <div class="fm-item fm-folder" ondblclick="fmOpenFolder('${esc(folder.path)}')">
                        <div class="fm-icon">📁</div>
                        <div class="fm-name">${esc(folder.name)}</div>
                        <div class="fm-meta">${esc(folder.modified)}</div>
                        <div class="fm-actions">
                            <button class="btn btn-sm btn-open" onclick="event.stopPropagation();fmOpenFolder('${esc(folder.path)}')">Open</button>
                            <button class="btn btn-sm btn-edit" onclick="event.stopPropagation();fmRename('${esc(folder.path)}','${esc(folder.name)}')">Rename</button>
                            <button class="btn btn-sm btn-del" onclick="event.stopPropagation();fmDelete('${esc(folder.path)}','${esc(folder.name)}',true)">Delete</button>
                        </div>
                    </div>`;
            }
        });

        (data.files || []).forEach(file => {
            html += `
                <div class="fm-item fm-file">
                    <div class="fm-icon">${fmFileIcon(file.ext)}</div>
                    <div class="fm-name">${esc(file.name)}</div>
                    <div class="fm-meta">${esc(String(file.ext||'').toUpperCase())} • ${esc(file.size_text)} • ${esc(file.modified)}</div>
                    <div class="fm-actions">
                        <a class="btn btn-sm btn-files" href="${esc(file.download_url)}" target="_blank">Download</a>
                        <button class="btn btn-sm btn-edit" onclick="fmRename('${esc(file.path)}','${esc(file.name)}')">Rename</button>
                        <button class="btn btn-sm btn-del" onclick="fmDelete('${esc(file.path)}','${esc(file.name)}',false)">Delete</button>
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
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', async () => {
    await loadMeta();
    const lead = $('leadEngineer');
    if (lead) lead.innerHTML = engineerOptions('');
    await loadProjects();
});