<?php
/**
 * tasks_ui_markup.php — shared Nawara Tasks UI markup.
 *
 * Emits the Tasks modal, the task form/detail slide-panels and the board
 * styles used by BOTH surfaces:
 *   - index.php (CPM): modal opened from the toolbar "Tasks" button
 *   - tasks.php (Nawara Tasks): the same overlay used as the page's main view
 *
 * No inline JavaScript here (production CSP allows scripts from 'self' only);
 * behaviour lives in tasks-ui.js which is included by both pages.
 */

if (!defined('NAWARA_TASKS_UI_MARKUP')) {
    define('NAWARA_TASKS_UI_MARKUP', true);

    function tasks_ui_markup(): void
    {
        ?>
<!-- Nawara Tasks UI (shared markup) -->
<style>
    #tkStats { grid-template-columns: repeat(7, 1fr); }
    .tk-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .tk-select, .tk-search {
        border: 1px solid var(--border); border-radius: 10px; padding: 8px 10px;
        font-size: .85rem; font-weight: 700; background: #fff; color: var(--txt);
        outline: none;
    }
    .tk-search { flex: 1; min-width: 140px; font-weight: 600; }
    .tk-views { display: flex; gap: 6px; margin-left: auto; }
    .tk-board { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(230px, 1fr); gap: 10px; overflow-x: auto; padding-bottom: 6px; }
    .tk-col { background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 10px; min-height: 120px; }
    .tk-col h4 { margin: 0 0 8px; font-size: .74rem; text-transform: uppercase; letter-spacing: .6px; color: var(--txt3); display: flex; justify-content: space-between; }
    .tk-col-cards { display: flex; flex-direction: column; gap: 8px; }
    .tk-card {
        background: #fff; border: 1px solid var(--border); border-radius: 12px;
        padding: 10px; cursor: pointer; transition: var(--t);
    }
    .tk-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: var(--border2); }
    .tk-card .tk-card-title { font-weight: 800; font-size: .86rem; margin-bottom: 6px; word-break: break-word; }
    .tk-card .pri-task-meta { font-size: .72rem; }
    .tk-chip { display: inline-block; padding: 3px 9px; border-radius: 99px; font-size: .68rem; font-weight: 900; text-transform: uppercase; letter-spacing: .3px; }
    .tk-chip-draft { background: #f1f5f9; color: #475569; }
    .tk-chip-assigned { background: #e0f2fe; color: #075985; }
    .tk-chip-in_progress { background: #dbeafe; color: #1d4ed8; }
    .tk-chip-blocked { background: #fee2e2; color: #991b1b; }
    .tk-chip-submitted_for_review { background: #fef3c7; color: #92400e; }
    .tk-chip-revision_requested { background: #ffedd5; color: #9a3412; }
    .tk-chip-awaiting_client_approval { background: #ede9fe; color: #6d28d9; }
    .tk-chip-completed { background: #d1fae5; color: #065f46; }
    .tk-chip-cancelled { background: #f1f5f9; color: #94a3b8; }
    .tk-flag { font-size: .7rem; font-weight: 800; color: var(--txt3); }
    .tk-row-status { display: flex; align-items: center; justify-content: center; font-size: 1rem; }
    .tk-num { font-weight: 900; color: var(--txt3); font-size: .8rem; }
    /* Detail panel */
    .tk-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin: 10px 0 14px; }
    .tk-meta-cell { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 8px 10px; }
    .tk-meta-cell .k { font-size: .68rem; text-transform: uppercase; letter-spacing: .5px; color: var(--txt3); font-weight: 800; }
    .tk-meta-cell .v { font-size: .86rem; font-weight: 800; margin-top: 2px; word-break: break-word; }
    .tk-actions { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
    .tk-sec { border-top: 1px solid var(--border); padding-top: 12px; margin-top: 14px; }
    .tk-sec h4 { margin: 0 0 8px; font-size: .8rem; text-transform: uppercase; letter-spacing: .5px; color: var(--txt3); }
    .tk-desc { white-space: pre-wrap; font-size: .9rem; color: var(--txt2); }
    .tk-check-row, .tk-file-row, .tk-comment-row, .tk-act-row {
        display: flex; align-items: flex-start; gap: 8px; padding: 7px 0;
        border-bottom: 1px dashed var(--border); font-size: .86rem;
    }
    .tk-check-row:last-child, .tk-file-row:last-child, .tk-comment-row:last-child, .tk-act-row:last-child { border-bottom: 0; }
    .tk-check-row .done { text-decoration: line-through; color: var(--txt3); }
    .tk-inline { display: flex; gap: 8px; margin-top: 8px; }
    .tk-inline input[type="text"], .tk-inline textarea { flex: 1; }
    .tk-inline textarea { border: 1px solid var(--border); border-radius: 10px; padding: 8px 10px; font-family: inherit; resize: vertical; min-height: 38px; }
    .tk-muted { color: var(--txt3); font-size: .82rem; }
    .tk-x { border: 0; background: transparent; cursor: pointer; color: var(--txt3); font-weight: 900; }
    /* Form panel */
    .tk-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 14px; }
    .tk-form-grid .full { grid-column: 1 / -1; }
    .tk-toggles { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 8px; margin-top: 10px; }
    .tk-toggle { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 8px 10px; font-size: .8rem; font-weight: 700; cursor: pointer; }
    .tk-toggle input { accent-color: var(--primary); }
    .tk-toggle.on { border-color: var(--primary); background: #eef2ff; }
    select[multiple] { min-height: 84px; }
    @media (max-width: 768px) {
        .tk-form-grid { grid-template-columns: 1fr; }
        .tk-board { grid-auto-columns: minmax(200px, 86vw); }
        #tkStats { grid-template-columns: repeat(3, 1fr); }
    }
</style>

<div class="overlay" id="tasksModal">
    <div class="modal modal-lg">
        <div class="modal-head">
            <h2>✅ Nawara Tasks</h2>
            <button class="modal-x" data-action="close-overlay" data-overlay="tasksModal">✕</button>
        </div>
        <div class="modal-body">
            <div class="pri-stats" id="tkStats"></div>
            <div class="pri-filter-bar tk-toolbar">
                <select id="tkProject" class="tk-select" data-action="tk-filter-project" aria-label="Project filter"></select>
                <select id="tkStatus" class="tk-select" data-action="tk-filter-status" aria-label="Status filter">
                    <option value="">All statuses</option>
                    <option value="blocked">Blocked</option>
                    <option value="submitted_for_review">In Review</option>
                    <option value="revision_requested">Revision</option>
                    <option value="awaiting_client_approval">Client Approval</option>
                    <option value="in_progress">In Progress</option>
                    <option value="assigned">Assigned</option>
                    <option value="draft">Draft</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
                <input id="tkSearch" class="tk-search" type="text" placeholder="Search tasks..." data-action="tk-filter-search">
                <span class="tk-views">
                    <button type="button" class="pri-filter-btn active" data-action="tk-view" data-view="list">☰ List</button>
                    <button type="button" class="pri-filter-btn" data-action="tk-view" data-view="board">▦ Board</button>
                </span>
            </div>
            <div id="tkList" class="pri-task-list"></div>
            <div id="tkBoard" class="tk-board" style="display:none;"></div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" type="button" data-action="close-overlay" data-overlay="tasksModal">Close</button>
            <button class="btn btn-primary" type="button" data-action="tk-new">＋ New Task</button>
        </div>
    </div>
</div>

<!-- Task create/edit slide panel -->
<div class="pri-form-overlay" id="taskFormPanel">
    <div class="pri-form-panel">
        <div class="pri-form-head">
            <div class="pri-form-head-text">
                <h3 id="tkFormTitle">New Task</h3>
                <p id="tkFormSub">Define the work, who does it, and the review rules</p>
            </div>
            <button class="pri-form-close" type="button" data-action="tk-form-close">✕</button>
        </div>
        <div class="pri-form-body">
            <div class="tk-form-grid">
                <div class="fg full" id="tkFProjectWrap">
                    <label>Project *</label>
                    <select id="tkFProject"></select>
                </div>
                <div class="fg full">
                    <label>Task Title *</label>
                    <input type="text" id="tkFTitle" maxlength="300" placeholder="e.g. Pour slab level 3">
                </div>
                <div class="fg full">
                    <label>Description</label>
                    <textarea id="tkFDesc" rows="3" maxlength="5000" placeholder="Scope, quantities, drawings..."></textarea>
                </div>
                <div class="fg">
                    <label>Priority</label>
                    <select id="tkFPriority">
                        <option value="critical">🔴 Critical</option>
                        <option value="high">🟠 High</option>
                        <option value="medium" selected>🟡 Medium</option>
                        <option value="low">🟢 Low</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Assignment Mode</label>
                    <select id="tkFMode">
                        <option value="single">Single assignee</option>
                        <option value="shared">Shared (any may submit)</option>
                        <option value="all_assignees_required">Every member submits</option>
                    </select>
                </div>
                <div class="fg">
                    <label>Responsible</label>
                    <select id="tkFAssignee"><option value="">— unassigned (draft) —</option></select>
                </div>
                <div class="fg" id="tkFContribWrap" style="display:none;">
                    <label>Contributors (multi-select)</label>
                    <select id="tkFContributors" multiple></select>
                </div>
                <div class="fg" id="tkFReviewWrap" style="display:none;">
                    <label>Reviewers (optional)</label>
                    <select id="tkFReviewers" multiple></select>
                </div>
                <div class="fg">
                    <label>Section (links progress)</label>
                    <select id="tkFSection"><option value="">— none —</option></select>
                </div>
                <div class="fg">
                    <label>Item (links progress)</label>
                    <select id="tkFItem" disabled><option value="">— none —</option></select>
                </div>
                <div class="fg">
                    <label>Start Date</label>
                    <input type="date" id="tkFStart">
                </div>
                <div class="fg">
                    <label>Due Date</label>
                    <input type="date" id="tkFDue">
                </div>
                <div class="fg" id="tkFWeightWrap" style="display:none;">
                    <label>Progress Weight (%)</label>
                    <input type="number" id="tkFWeight" min="0" max="10000" step="0.5" value="0">
                </div>
            </div>
            <div class="tk-toggles">
                <label class="tk-toggle"><input type="checkbox" id="tkFReview" checked> Review Required</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFReqComment"> Comment on submit</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFReqFile"> File on submit</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFNotifyAdmin" checked> Notify admin on done</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFProgress"> Counts toward project %</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFClientVisible"> Visible to client</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFClientApproval"> Client approval required</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFClientComments"> Client comments allowed</label>
                <label class="tk-toggle"><input type="checkbox" id="tkFClientFiles"> Client file downloads</label>
            </div>
        </div>
        <div class="pri-form-foot">
            <button class="btn btn-secondary" type="button" data-action="tk-form-close">Cancel</button>
            <button class="btn btn-primary" type="button" data-action="tk-save">💾 Save Task</button>
        </div>
    </div>
</div>

<!-- Task detail slide panel -->
<div class="pri-form-overlay" id="taskDetailPanel">
    <div class="pri-form-panel">
        <div class="pri-form-head">
            <div class="pri-form-head-text">
                <h3 id="tkDetailTitle">Task</h3>
                <p id="tkDetailSub"></p>
            </div>
            <button class="pri-form-close" type="button" data-action="tk-detail-close">✕</button>
        </div>
        <div class="pri-form-body">
            <div id="tkDetailChips"></div>
            <div class="tk-meta-grid" id="tkDetailMeta"></div>
            <div class="tk-actions" id="tkDetailActions"></div>
            <div class="tk-sec"><h4>Description</h4><div class="tk-desc" id="tkDetailDesc"></div></div>
            <div class="tk-sec">
                <h4>Checklist</h4>
                <div id="tkChecklist"></div>
                <div class="tk-inline" id="tkChecklistAdd" style="display:none;">
                    <input type="text" id="tkChecklistInput" maxlength="300" placeholder="Add checklist item...">
                    <button class="btn btn-sm btn-primary" type="button" data-action="tk-chk-add">＋ Add</button>
                </div>
            </div>
            <div class="tk-sec">
                <h4>Comments</h4>
                <div id="tkComments"></div>
                <div class="tk-inline">
                    <textarea id="tkCommentBody" maxlength="4000" placeholder="Write a comment..."></textarea>
                    <button class="btn btn-sm btn-primary" type="button" data-action="tk-comment-add">Send</button>
                </div>
                <div class="tk-inline" id="tkBlockWrap" style="display:none;">
                    <select id="tkBlockReason" class="tk-select">
                        <option value="missing_input">Missing input / information</option>
                        <option value="waiting_client">Waiting for client</option>
                        <option value="waiting_approval">Waiting for approval</option>
                        <option value="dependency">Dependency not ready</option>
                        <option value="technical">Technical issue</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="tk-sec">
                <h4>Attachments</h4>
                <div id="tkFiles"></div>
                <div class="tk-inline">
                    <input type="file" id="tkFileInput" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
                    <button class="btn btn-sm btn-primary" type="button" data-action="tk-upload">⬆ Upload</button>
                </div>
                <div class="tk-muted" id="tkUploadHint">Images are compressed in your browser before upload (max 10&nbsp;MB).</div>
            </div>
            <div class="tk-sec">
                <h4>Activity</h4>
                <div id="tkActivity"></div>
            </div>
        </div>
        <div class="pri-form-foot">
            <button class="btn btn-secondary" type="button" data-action="tk-detail-close">Close</button>
        </div>
    </div>
</div>

<!-- NiK assistant widget (shared: CPM + Tasks) -->
<button type="button" class="nik-fab" id="nikFab" title="NiK — دستیار هوشمند">🤖</button>
<div class="nik-panel" id="nikPanel" aria-hidden="true">
    <div class="nik-head">
        <span class="nik-title">🤖 نیک — NiK</span>
        <span class="nik-actions">
            <button type="button" class="nik-mini" id="nikSpeakBtn" title="بلند خواندن آخرین جواب">🔊</button>
            <button type="button" class="nik-mini" id="nikCloseBtn" title="بستن">✕</button>
        </span>
    </div>
    <div class="nik-messages" id="nikMessages"></div>
    <div class="nik-suggest" id="nikSuggest"></div>
    <form class="nik-input" id="nikForm">
        <input type="text" id="nikText" autocomplete="off" placeholder="از نیک بپرس... مثلاً: پیشرفت کابل پلازا چقدره؟">
        <button type="submit" class="btn btn-primary btn-sm">➤</button>
    </form>
</div>
        <?php
    }
}
