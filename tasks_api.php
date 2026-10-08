<?php
/**
 * tasks_api.php — Nawara task engine (stage 3)
 *
 * Implements the approved workflow from docs/01_TASK_WORKFLOW_FA.md:
 * creation, assignment (single / shared / all_assignees_required), status
 * transitions with Review Required gates, comments, checklist, attachments,
 * reviewer decisions and the client-approval path.
 *
 * Security contract:
 *  - every action requires a live session; writes require a valid CSRF token
 *  - every permission decision is made server-side against the membership
 *    permission map (docs/02); the UI only mirrors computed capabilities
 *  - each state transition runs inside one transaction with an optimistic
 *    version check (stale writes -> 409) and writes an activity record
 *  - employee actors can only work/submit their own assigned tasks; review,
 *    completion of review-required tasks, assignment and cancellation require
 *    reviewer/admin permission — enforced here, not in the UI
 */

require_once __DIR__ . '/database.php';

requireLoginJson();

header('Content-Type: application/json; charset=UTF-8');

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = [];
}

$action = (string)($_GET['action'] ?? $input['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    requireCsrfTokenJson();
}

function taskOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function taskFail(string $error, int $status = 400, array $extra = []): void
{
    taskOut(array_merge(['success' => false, 'error' => $error], $extra), $status);
}

/* ---------------------------------------------------------------------------
 * Loaders, validators, capability checks
 * ------------------------------------------------------------------------- */

function loadTaskOrFail(PDO $pdo, int $taskId): array
{
    if ($taskId <= 0) {
        taskFail('Invalid task', 422);
    }
    $stmt = $pdo->prepare('SELECT * FROM pm_tasks WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        taskFail('Task not found', 404);
    }
    return $task;
}

function taskAssignees(PDO $pdo, int $taskId): array
{
    $stmt = $pdo->prepare("
        SELECT a.id, a.user_id, a.assignment_role, a.required_to_submit,
               a.member_status, a.started_at, a.submitted_at, a.completed_at,
               u.name, u.username, u.active
        FROM pm_task_assignees a
        INNER JOIN pm_users u ON u.id = a.user_id
        WHERE a.task_id = :task_id
        ORDER BY CASE a.assignment_role WHEN 'responsible' THEN 0 ELSE 1 END, a.id ASC
    ");
    $stmt->execute(['task_id' => $taskId]);
    return $stmt->fetchAll();
}

function taskReviewers(PDO $pdo, int $taskId): array
{
    $stmt = $pdo->prepare("
        SELECT r.id, r.user_id, r.required, r.decision, r.decision_comment, r.decided_at,
               u.name, u.username
        FROM pm_task_reviewers r
        INNER JOIN pm_users u ON u.id = r.user_id
        WHERE r.task_id = :task_id
        ORDER BY r.id ASC
    ");
    $stmt->execute(['task_id' => $taskId]);
    return $stmt->fetchAll();
}

function assigneeIndex(array $assignees): array
{
    $map = [];
    foreach ($assignees as $assignee) {
        $map[(int)$assignee['user_id']] = $assignee;
    }
    return $map;
}

/** Project-level task administration (edit/cancel/manage assignment). */
function canManageTask(array $task): bool
{
    $pid = (int)$task['project_id'];
    return canDoOnProjectPermission($pid, 'edit_tasks')
        || canDoOnProjectPermission($pid, 'assign_tasks');
}

/** Review authority: head admin, review_tasks permission, or assigned reviewer. */
function canReviewTask(array $task, array $reviewers): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    if (isHeadAdmin($user) || canDoOnProjectPermission((int)$task['project_id'], 'review_tasks')) {
        return true;
    }
    foreach ($reviewers as $reviewer) {
        if ((int)$reviewer['user_id'] === (int)$user['id']) {
            return true;
        }
    }
    return false;
}

/** Work authority: an assignee with update_own_assignment, or task management. */
function canWorkTask(array $task, array $assignees): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    if (canManageTask($task)) {
        return true;
    }
    if (!canDoOnProjectPermission((int)$task['project_id'], 'update_own_assignment')) {
        return false;
    }
    return isset(assigneeIndex($assignees)[(int)$user['id']]);
}

/** Submit authority: an assignee with submit_for_review, or task management. */
function canSubmitTask(array $task, array $assignees): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    if (canManageTask($task)) {
        return true;
    }
    if (!canDoOnProjectPermission((int)$task['project_id'], 'submit_for_review')) {
        return false;
    }
    return isset(assigneeIndex($assignees)[(int)$user['id']]);
}

/**
 * Task visibility. Client accounts never see internal tasks. Staff need the
 * view_internal_tasks membership permission (alias: view_project).
 */
function canViewTask(array $task): bool
{
    $user = getCurrentUser();
    if (!$user || isClientAccount($user)) {
        return false;
    }
    if (isHeadAdmin($user)) {
        return true;
    }
    return canDoOnProjectPermission((int)$task['project_id'], 'view_internal_tasks');
}

function memberStatus(array $assignees, int $userId): ?string
{
    $map = assigneeIndex($assignees);
    return isset($map[$userId]) ? (string)$map[$userId]['member_status'] : null;
}

function taskActivity(
    PDO $pdo,
    array $task,
    string $eventType,
    ?int $actorId,
    string $previous = '',
    string $new = ''
): void {
    $stmt = $pdo->prepare("
        INSERT INTO pm_task_activity
            (project_id, task_id, actor_user_id, event_type, previous_value, new_value, created_at)
        VALUES (:project_id, :task_id, :actor, :event, :previous, :new, datetime('now','localtime'))
    ");
    $stmt->execute([
        'project_id' => (int)$task['project_id'],
        'task_id' => (int)$task['id'],
        'actor' => $actorId,
        'event' => $eventType,
        'previous' => $previous,
        'new' => $new,
    ]);
}

function taskBool($value): int
{
    return (!empty($value) && $value !== '0' && $value !== 0) ? 1 : 0;
}

function taskValidateDateField(string $value, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
        taskFail($label . ' must be a valid date (YYYY-MM-DD)', 422);
    }
    return $value;
}

function taskText(string $value, int $maxLength, string $label): string
{
    $value = trim($value);
    if ($value === '') {
        taskFail($label . ' is required', 422);
    }
    if (mb_strlen($value) > $maxLength || str_contains($value, "\0")) {
        taskFail($label . ' is invalid or too long', 422);
    }
    return $value;
}

function taskOptionalText(string $value, int $maxLength): string
{
    $value = trim($value);
    if (mb_strlen($value) > $maxLength || str_contains($value, "\0")) {
        taskFail('Text is invalid or too long', 422);
    }
    return $value;
}

function assertTaskStatus(string $status, array $allowed): void
{
    if (!in_array($status, $allowed, true)) {
        taskFail('Invalid ' . $status . ' for this transition', 422);
    }
}

const TASK_PRIORITIES = ['critical', 'high', 'medium', 'low'];
const TASK_ASSIGNMENT_MODES = ['single', 'shared', 'all_assignees_required'];
const TASK_BLOCK_REASONS = [
    'missing_input', 'waiting_client', 'waiting_approval',
    'dependency', 'technical', 'other',
];

/** Validates assignee/reviewer candidate user ids; returns normalized ids. */
function resolveUserIds(array $ids, bool $requireProjectMember, ?int $projectId): array
{
    $clean = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $clean[] = $id;
        }
    }
    $clean = array_values(array_unique($clean));
    if ($clean === []) {
        return [];
    }
    if (count($clean) > 100) {
        taskFail('Too many users selected', 422);
    }

    $pdo = getDB();
    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $stmt = $pdo->prepare("
        SELECT id FROM pm_users
        WHERE id IN ({$placeholders}) AND active = 1 AND account_type != 'client'
    ");
    $stmt->execute($clean);
    $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($valid) !== count($clean)) {
        taskFail('One or more selected users do not exist or are not active internal accounts', 422);
    }

    if ($requireProjectMember && $projectId !== null && $projectId > 0 && !isHeadAdmin()) {
        $stmt = $pdo->prepare("
            SELECT user_id FROM pm_project_members
            WHERE project_id = ? AND active = 1 AND user_id IN ({$placeholders})
        ");
        $stmt->execute([$projectId]);
        $members = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (count($members) !== count($valid)) {
            taskFail('Assignees must be active members of the project', 422);
        }
    }

    return $valid;
}

/** Validates section/item pairing before the DB trigger would abort. */
function resolveSectionItem($sectionId, $itemId): array
{
    $sectionId = ($sectionId !== null && $sectionId !== '' && (int)$sectionId > 0) ? (int)$sectionId : null;
    $itemId = ($itemId !== null && $itemId !== '' && (int)$itemId > 0) ? (int)$itemId : null;

    $pdo = getDB();
    if ($sectionId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM pm_sections WHERE id = ? AND active = 1');
        $stmt->execute([$sectionId]);
        if (!$stmt->fetch()) {
            taskFail('Selected section does not exist', 422);
        }
    }

    if ($itemId !== null) {
        if ($sectionId === null) {
            taskFail('An item requires its section', 422);
        }
        $stmt = $pdo->prepare('SELECT section_id FROM pm_section_items WHERE id = ? AND active = 1');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item || (int)$item['section_id'] !== $sectionId) {
            taskFail('Item must belong to the selected section', 422);
        }
    }

    return [$sectionId, $itemId];
}

function projectExistsAndActive(int $projectId): void
{
    if ($projectId <= 0) {
        taskFail('A project is required', 422);
    }
    $stmt = getDB()->prepare("SELECT id FROM pm_projects WHERE id = ? AND deleted_at = ''");
    $stmt->execute([$projectId]);
    if (!$stmt->fetch()) {
        taskFail('Project does not exist or is archived', 422);
    }
}

function assertVersion(array $task, $expected): void
{
    $expected = (int)($expected ?? 0);
    if ($expected < 1) {
        taskFail('Task version is required. Refresh and try again.', 409);
    }
    if ($expected !== (int)$task['version']) {
        taskFail('Task was changed by someone else. Refresh and try again.', 409);
    }
}

/* ---------------------------------------------------------------------------
 * Payload builders
 * ------------------------------------------------------------------------- */

function taskSummaryRow(PDO $pdo, array $task): array
{
    $assignees = taskAssignees($pdo, (int)$task['id']);

    $checklist = $pdo->prepare('
        SELECT COUNT(*) AS total,
               COALESCE(SUM(CASE WHEN is_done = 1 THEN 1 ELSE 0 END), 0) AS done
        FROM pm_task_checklist_items WHERE task_id = :id
    ');
    $checklist->execute(['id' => (int)$task['id']]);
    $check = $checklist->fetch() ?: ['total' => 0, 'done' => 0];

    $counts = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM pm_task_comments WHERE task_id = :id1) AS comments,
            (SELECT COUNT(*) FROM pm_task_attachments WHERE task_id = :id2 AND deleted_at = '') AS files,
            (SELECT COUNT(*) FROM pm_task_reviewers WHERE task_id = :id3) AS reviewers
    ");
    $counts->execute(['id1' => (int)$task['id'], 'id2' => (int)$task['id'], 'id3' => (int)$task['id']]);
    $c = $counts->fetch() ?: ['comments' => 0, 'files' => 0, 'reviewers' => 0];

    return [
        'id' => (int)$task['id'],
        'project_id' => (int)$task['project_id'],
        'section_id' => isset($task['section_id']) && $task['section_id'] !== null ? (int)$task['section_id'] : null,
        'item_id' => isset($task['item_id']) && $task['item_id'] !== null ? (int)$task['item_id'] : null,
        'title' => $task['title'],
        'description' => $task['description'],
        'status' => $task['status'],
        'priority' => $task['priority'],
        'assignment_mode' => $task['assignment_mode'],
        'review_required' => (int)$task['review_required'],
        'require_file_on_submit' => (int)$task['require_file_on_submit'],
        'require_comment_on_submit' => (int)$task['require_comment_on_submit'],
        'affects_project_progress' => (int)$task['affects_project_progress'],
        'progress_weight' => (float)$task['progress_weight'],
        'client_visible' => (int)$task['client_visible'],
        'client_approval_required' => (int)$task['client_approval_required'],
        'client_comments_enabled' => (int)$task['client_comments_enabled'],
        'client_files_downloadable' => (int)$task['client_files_downloadable'],
        'client_approval_status' => $task['client_approval_status'],
        'start_at' => $task['start_at'],
        'due_at' => $task['due_at'],
        'estimated_minutes' => (int)$task['estimated_minutes'],
        'completed_at' => $task['completed_at'],
        'submitted_at' => $task['submitted_at'],
        'actual_start_at' => $task['actual_start_at'],
        'published_to_client_at' => $task['published_to_client_at'],
        'version' => (int)$task['version'],
        'created_at' => $task['created_at'],
        'updated_at' => $task['updated_at'],
        'assignees' => array_map(static fn(array $a): array => [
            'user_id' => (int)$a['user_id'],
            'name' => $a['name'],
            'username' => $a['username'],
            'role' => $a['assignment_role'],
            'required_to_submit' => (int)$a['required_to_submit'],
            'member_status' => $a['member_status'],
        ], $assignees),
        'checklist' => ['total' => (int)($check['total'] ?? 0), 'done' => (int)($check['done'] ?? 0)],
        'counts' => [
            'comments' => (int)$c['comments'],
            'files' => (int)$c['files'],
            'reviewers' => (int)$c['reviewers'],
        ],
    ];
}

function taskCapabilities(array $task, array $assignees, array $reviewers): array
{
    $user = getCurrentUser();
    $userId = (int)($user['id'] ?? 0);
    $isAssignee = isset(assigneeIndex($assignees)[$userId]);
    $isReviewerRow = false;
    foreach ($reviewers as $reviewer) {
        if ((int)$reviewer['user_id'] === $userId) {
            $isReviewerRow = true;
        }
    }

    $canWork = canWorkTask($task, $assignees);
    $canSubmit = canSubmitTask($task, $assignees);
    $canReview = canReviewTask($task, $reviewers);
    $canManage = canManageTask($task);
    $status = (string)$task['status'];

    return [
        'is_assignee' => $isAssignee,
        'is_reviewer' => $isReviewerRow,
        'can_edit' => $canManage,
        'can_assign' => canDoOnProjectPermission((int)$task['project_id'], 'assign_tasks'),
        'can_work' => $canWork,
        'can_submit' => $canSubmit && in_array($status, ['assigned', 'in_progress'], true),
        'can_complete_direct' => $canSubmit
            && (int)$task['review_required'] === 0
            && in_array($status, ['assigned', 'in_progress'], true),
        'can_block' => $canWork && in_array($status, ['assigned', 'in_progress'], true),
        'can_unblock' => $canWork && $status === 'blocked',
        'can_review' => $canReview && $status === 'submitted_for_review',
        'can_cancel' => $canManage && in_array($status, ['draft', 'assigned', 'in_progress', 'blocked'], true),
        'can_resume' => $canWork && $status === 'revision_requested',
        'my_member_status' => memberStatus($assignees, $userId),
    ];
}

/* ---------------------------------------------------------------------------
 * GET actions
 * ------------------------------------------------------------------------- */

if ($method === 'GET') {
    $pdo = getDB();

    if ($action === 'list') {
        $mine = (string)($_GET['mine'] ?? '') === '1';
        $projectId = (int)($_GET['project_id'] ?? 0);
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $allStatuses = ['draft', 'assigned', 'in_progress', 'blocked', 'submitted_for_review',
            'revision_requested', 'awaiting_client_approval', 'completed', 'cancelled'];
        if ($statusFilter !== '' && !in_array($statusFilter, $allStatuses, true)) {
            taskFail('Invalid status filter', 422);
        }

        $user = getCurrentUser();
        if (isClientAccount($user)) {
            // Clients: only tasks explicitly published to them.
            if ($projectId <= 0) {
                taskFail('project_id is required', 422);
            }
            projectExistsAndActive($projectId);
            $stmt = $pdo->prepare("
                SELECT t.*, p.project_name
                FROM pm_tasks t
                INNER JOIN pm_projects p ON p.id = t.project_id
                WHERE p.deleted_at = '' AND t.project_id = :project_id
                  AND t.client_visible = 1 AND t.published_to_client_at != ''
                  AND EXISTS (
                      SELECT 1 FROM pm_task_client_recipients rx
                      WHERE rx.task_id = t.id AND rx.user_id = :self
                  )
                ORDER BY CASE t.status
                            WHEN 'awaiting_client_approval' THEN 0
                            WHEN 'revision_requested' THEN 1
                            WHEN 'completed' THEN 2
                            ELSE 3
                         END,
                         t.published_to_client_at DESC, t.id DESC
                LIMIT 500
            ");
            $stmt->execute(['project_id' => $projectId, 'self' => (int)$user['id']]);
            $tasks = [];
            foreach ($stmt->fetchAll() as $task) {
                $tasks[] = taskSummaryRow($pdo, $task);
            }
            taskOut(['success' => true, 'tasks' => $tasks]);
        }

        $where = [];
        $params = [];

        if ($projectId > 0) {
            projectExistsAndActive($projectId);
            if (!canDoOnProjectPermission($projectId, 'view_internal_tasks')) {
                taskFail('You do not have permission to view tasks for this project', 403);
            }
            $where[] = 't.project_id = :project_id';
            $params['project_id'] = $projectId;
        } elseif (!$mine) {
            taskFail('project_id is required', 422);
        }

        if ($statusFilter !== '') {
            $where[] = 't.status = :status';
            $params['status'] = $statusFilter;
        }

        // Employees only ever receive their own assigned tasks.
        $fullView = isHeadAdmin($user);
        if (!$fullView && $projectId > 0) {
            $fullView = canDoOnProjectPermission($projectId, 'edit_tasks')
                || canDoOnProjectPermission($projectId, 'assign_tasks')
                || canDoOnProjectPermission($projectId, 'review_tasks');
        }
        if (!$fullView || $mine) {
            $where[] = 'EXISTS (SELECT 1 FROM pm_task_assignees ax WHERE ax.task_id = t.id AND ax.user_id = :self)';
            $params['self'] = (int)$user['id'];
        }

        $whereSql = $where === [] ? '1 = 1' : implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT t.*, p.project_name
            FROM pm_tasks t
            INNER JOIN pm_projects p ON p.id = t.project_id
            WHERE p.deleted_at = '' AND {$whereSql}
            ORDER BY CASE t.status
                        WHEN 'blocked' THEN 0
                        WHEN 'submitted_for_review' THEN 1
                        WHEN 'revision_requested' THEN 2
                        WHEN 'awaiting_client_approval' THEN 3
                        WHEN 'in_progress' THEN 4
                        WHEN 'assigned' THEN 5
                        WHEN 'draft' THEN 6
                        WHEN 'completed' THEN 7
                        ELSE 8
                     END,
                     CASE t.priority
                        WHEN 'critical' THEN 0 WHEN 'high' THEN 1
                        WHEN 'medium' THEN 2 ELSE 3
                     END,
                     (t.due_at = '' OR t.due_at IS NULL) ASC, t.due_at ASC, t.id DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        $tasks = [];
        foreach ($stmt->fetchAll() as $task) {
            $tasks[] = taskSummaryRow($pdo, $task);
        }

        taskOut(['success' => true, 'tasks' => $tasks]);
    }

    if ($action === 'get') {
        $pdo = getDB();
        $task = loadTaskOrFail($pdo, (int)($_GET['id'] ?? 0));
        $viewer = getCurrentUser();
        $viewerIsClient = isClientAccount($viewer);
        if ($viewerIsClient) {
            $isPublished = (int)$task['client_visible'] === 1
                && (string)$task['published_to_client_at'] !== '';
            $stmt = $pdo->prepare('SELECT id FROM pm_task_client_recipients WHERE task_id = ? AND user_id = ?');
            $stmt->execute([(int)$task['id'], (int)$viewer['id']]);
            if (!$isPublished || !$stmt->fetch()) {
                // Do not leak the existence of internal tasks to clients.
                taskFail('Task not found', 404);
            }
        } elseif (!canViewTask($task)) {
            taskFail('You do not have permission to view this task', 403);
        }

        $assignees = taskAssignees($pdo, (int)$task['id']);
        $reviewers = taskReviewers($pdo, (int)$task['id']);

        $comments = $pdo->prepare("
            SELECT c.id, c.author_user_id, c.visibility, c.body, c.created_at, u.name AS author_name
            FROM pm_task_comments c
            INNER JOIN pm_users u ON u.id = c.author_user_id
            WHERE c.task_id = :id" . ($viewerIsClient ? " AND c.visibility = 'client' " : '') . "
            ORDER BY c.id ASC
            LIMIT 200
        ");
        $comments->execute(['id' => (int)$task['id']]);

        $checklist = $pdo->prepare('
            SELECT id, title, is_done, sort_order, completed_at
            FROM pm_task_checklist_items WHERE task_id = :id ORDER BY sort_order ASC, id ASC
        ');
        $checklist->execute(['id' => (int)$task['id']]);

        $attachments = $pdo->prepare("
            SELECT id, original_name, content_type, byte_size, visibility,
                   client_downloadable, uploaded_by_user_id, created_at
            FROM pm_task_attachments
            WHERE task_id = :id AND deleted_at = ''" . ($viewerIsClient ? " AND visibility = 'client' " : '') . "
            ORDER BY id ASC
        ");
        $attachments->execute(['id' => (int)$task['id']]);

        if ($viewerIsClient) {
            // Internal audit trail stays internal; clients see decisions via
            // recipients[] and their own comments only.
            $activityRows = [];
        } else {
            $activity = $pdo->prepare("
                SELECT a.id, a.event_type, a.previous_value, a.new_value, a.created_at,
                       a.actor_user_id, u.name AS actor_name
                FROM pm_task_activity a
                LEFT JOIN pm_users u ON u.id = a.actor_user_id
                WHERE a.task_id = :id
                ORDER BY a.id DESC
                LIMIT 200
            ");
            $activity->execute(['id' => (int)$task['id']]);
            $activityRows = $activity->fetchAll();
        }

        $recipients = $pdo->prepare("
            SELECT r.id, r.user_id, r.approval_required, r.comments_enabled, r.decision,
                   r.published_at, r.decided_at, u.name
            FROM pm_task_client_recipients r
            INNER JOIN pm_users u ON u.id = r.user_id
            WHERE r.task_id = :id" . ($viewerIsClient ? ' AND r.user_id = :viewer_id' : '') . "
        ");
        $recipients->execute($viewerIsClient
            ? ['id' => (int)$task['id'], 'viewer_id' => (int)$viewer['id']]
            : ['id' => (int)$task['id']]);

        $project = $pdo->prepare('SELECT id, project_name FROM pm_projects WHERE id = ?');
        $project->execute([(int)$task['project_id']]);
        $projectRow = $project->fetch() ?: ['id' => 0, 'project_name' => ''];

        $blocking = $pdo->prepare("
            SELECT new_value, created_at FROM pm_task_activity
            WHERE task_id = :id AND event_type = 'blocked'
            ORDER BY id DESC LIMIT 1
        ");
        $blocking->execute(['id' => (int)$task['id']]);
        $lastBlock = $blocking->fetch();

        $detail = taskSummaryRow($pdo, $task);
        $detail['project_name'] = $projectRow['project_name'];
        $detail['block_reason'] = ($task['status'] === 'blocked' && $lastBlock)
            ? json_decode((string)$lastBlock['new_value'], true) : null;

        taskOut([
            'success' => true,
            'task' => $detail,
            'reviewers' => array_map(static fn(array $r): array => [
                'user_id' => (int)$r['user_id'],
                'name' => $r['name'],
                'username' => $r['username'],
                'required' => (int)$r['required'],
                'decision' => $r['decision'],
                'decision_comment' => $r['decision_comment'],
                'decided_at' => $r['decided_at'],
            ], $reviewers),
            'comments' => $comments->fetchAll(),
            'checklist' => $checklist->fetchAll(),
            'attachments' => $attachments->fetchAll(),
            'activity' => $activityRows,
            'recipients' => $recipients->fetchAll(),
            'capabilities' => taskCapabilities($task, $assignees, $reviewers),
        ]);
    }

    if ($action === 'assignable_users') {
        $pdo = getDB();
        $projectId = (int)($_GET['project_id'] ?? 0);
        if ($projectId <= 0) {
            taskFail('project_id is required', 422);
        }
        if (!isHeadAdmin() && !canDoOnProjectPermission($projectId, 'assign_tasks')) {
            taskFail('You do not have permission to manage assignments', 403);
        }

        if (isHeadAdmin()) {
            $stmt = $pdo->query("
                SELECT id, name, username FROM pm_users
                WHERE active = 1 AND account_type != 'client'
                ORDER BY name ASC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.username
                FROM pm_project_members m
                INNER JOIN pm_users u ON u.id = m.user_id
                WHERE m.project_id = :pid AND m.active = 1
                  AND u.active = 1 AND u.account_type != 'client'
                ORDER BY u.name ASC
            ");
            $stmt->execute(['pid' => $projectId]);
        }
        taskOut(['success' => true, 'users' => $stmt->fetchAll()]);
    }

    if ($action === 'attachment') {
        $pdo = getDB();
        $attachmentId = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM pm_task_attachments WHERE id = ? AND deleted_at = ''");
        $stmt->execute([$attachmentId]);
        $attachment = $stmt->fetch();
        if (!$attachment) {
            taskFail('Attachment not found', 404);
        }
        $task = loadTaskOrFail($pdo, (int)$attachment['task_id']);

        $user = getCurrentUser();
        if (isClientAccount($user)) {
            $stmt = $pdo->prepare('SELECT id FROM pm_task_client_recipients WHERE task_id = ? AND user_id = ?');
            $stmt->execute([(int)$task['id'], (int)$user['id']]);
            $isRecipient = (bool)$stmt->fetch();
            if (!$isRecipient || !(int)$task['client_files_downloadable']
                || $attachment['visibility'] !== 'client') {
                taskFail('You do not have permission to download this file', 403);
            }
        } elseif (!canViewTask($task)) {
            taskFail('You do not have permission to download this file', 403);
        }

        $path = APP_UPLOADS_DIR . '/' . $attachment['storage_key'];
        if (!is_file($path)) {
            taskFail('Attachment file is missing', 410);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            taskFail('Attachment could not be read', 500);
        }

        taskOut([
            'success' => true,
            'name' => $attachment['original_name'],
            'content_type' => $attachment['content_type'],
            'data' => base64_encode($bytes),
        ]);
    }

    taskFail('Unknown action', 400);
}

/* ---------------------------------------------------------------------------
 * POST actions
 * ------------------------------------------------------------------------- */

$pdo = getDB();
$actor = getCurrentUser();
$actorId = (int)($actor['id'] ?? 0);

/* ------------------------------- create ---------------------------------- */

if ($action === 'create') {
    $projectId = (int)($input['project_id'] ?? 0);
    projectExistsAndActive($projectId);
    if (!canDoOnProjectPermission($projectId, 'create_tasks')) {
        taskFail('You do not have permission to create tasks', 403);
    }

    $title = taskText((string)($input['title'] ?? ''), 300, 'Title');
    $description = taskOptionalText((string)($input['description'] ?? ''), 5000);
    $priority = (string)($input['priority'] ?? 'medium');
    if (!in_array($priority, TASK_PRIORITIES, true)) {
        taskFail('Invalid priority', 422);
    }
    $assignmentMode = (string)($input['assignment_mode'] ?? 'single');
    if (!in_array($assignmentMode, TASK_ASSIGNMENT_MODES, true)) {
        taskFail('Invalid assignment mode', 422);
    }
    $dueAt = taskValidateDateField((string)($input['due_at'] ?? ''), 'Due date');
    $startAt = taskValidateDateField((string)($input['start_at'] ?? ''), 'Start date');
    [$sectionId, $itemId] = resolveSectionItem($input['section_id'] ?? null, $input['item_id'] ?? null);

    $reviewRequired = taskBool($input['review_required'] ?? 1);
    $clientApproval = taskBool($input['client_approval_required'] ?? 0);
    if ($clientApproval && !$reviewRequired) {
        taskFail('Client approval requires internal review', 422);
    }

    $responsibleId = (int)($input['responsible_id'] ?? 0);
    $contributorIds = is_array($input['contributor_ids'] ?? null) ? $input['contributor_ids'] : [];
    $reviewerIds = is_array($input['reviewer_ids'] ?? null) ? $input['reviewer_ids'] : [];

    $responsible = $responsibleId > 0 ? resolveUserIds([$responsibleId], true, $projectId) : [];
    $contributors = resolveUserIds($contributorIds, true, $projectId);
    $reviewers = resolveUserIds($reviewerIds, false, null);

    // Unassigned tasks start as drafts and are bound to people later through
    // the assign action; when people are given at creation time they must
    // match the chosen mode.
    if ($assignmentMode === 'single' && $contributors !== []) {
        taskFail('Single mode cannot have contributors', 422);
    }
    foreach ($contributors as $cid) {
        if ($cid === (int)($responsible[0] ?? 0)) {
            taskFail('The responsible assignee cannot also be a contributor', 422);
        }
    }

    $estimated = max(0, (int)($input['estimated_minutes'] ?? 0));
    $progressWeight = (float)($input['progress_weight'] ?? 0);
    if ($progressWeight < 0 || $progressWeight > 10000) {
        taskFail('Progress weight is out of range', 422);
    }

    $initialStatus = ($responsible !== [] || $contributors !== []) ? 'assigned' : 'draft';

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO pm_tasks (
                project_id, section_id, item_id, title, description, status, priority,
                assignment_mode, review_required, require_file_on_submit,
                require_comment_on_submit, notify_admin_on_complete,
                affects_project_progress, progress_weight,
                client_visible, client_approval_required, client_comments_enabled,
                client_files_downloadable, client_notify_on_publish,
                start_at, due_at, estimated_minutes, created_by, updated_by, version
            ) VALUES (
                :project_id, :section_id, :item_id, :title, :description, :status, :priority,
                :assignment_mode, :review_required, :require_file_on_submit,
                :require_comment_on_submit, :notify_admin_on_complete,
                :affects_project_progress, :progress_weight,
                :client_visible, :client_approval_required, :client_comments_enabled,
                :client_files_downloadable, :client_notify_on_publish,
                :start_at, :due_at, :estimated_minutes, :created_by, :updated_by, 1
            )
        ");
        $stmt->execute([
            'project_id' => $projectId,
            'section_id' => $sectionId,
            'item_id' => $itemId,
            'title' => $title,
            'description' => $description,
            'status' => $initialStatus,
            'priority' => $priority,
            'assignment_mode' => $assignmentMode,
            'review_required' => $reviewRequired,
            'require_file_on_submit' => taskBool($input['require_file_on_submit'] ?? 0),
            'require_comment_on_submit' => taskBool($input['require_comment_on_submit'] ?? 0),
            'notify_admin_on_complete' => taskBool($input['notify_admin_on_complete'] ?? 1),
            'affects_project_progress' => taskBool($input['affects_project_progress'] ?? 0),
            'progress_weight' => $progressWeight,
            'client_visible' => taskBool($input['client_visible'] ?? 0),
            'client_approval_required' => $clientApproval,
            'client_comments_enabled' => taskBool($input['client_comments_enabled'] ?? 0),
            'client_files_downloadable' => taskBool($input['client_files_downloadable'] ?? 0),
            'client_notify_on_publish' => taskBool($input['client_notify_on_publish'] ?? 1),
            'start_at' => $startAt,
            'due_at' => $dueAt,
            'estimated_minutes' => $estimated,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
        $taskId = (int)$pdo->lastInsertId();

        $insertAssignee = $pdo->prepare("
            INSERT INTO pm_task_assignees
                (task_id, user_id, assignment_role, required_to_submit, member_status,
                 started_at, created_at, updated_at)
            VALUES (:task_id, :user_id, :role, :required, :member_status,
                    '', datetime('now','localtime'), datetime('now','localtime'))
        ");
        $members = [];
        foreach ($responsible as $uid) {
            $members[] = [$uid, 'responsible', 1];
        }
        foreach ($contributors as $uid) {
            $members[] = [$uid, 'contributor', $assignmentMode === 'all_assignees_required' ? 1 : 0];
        }
        foreach ($members as [$uid, $role, $required]) {
            $insertAssignee->execute([
                'task_id' => $taskId,
                'user_id' => $uid,
                'role' => $role,
                'required' => $required,
                'member_status' => 'assigned',
            ]);
        }

        $insertReviewer = $pdo->prepare("
            INSERT INTO pm_task_reviewers (task_id, user_id, required, decision, created_at)
            VALUES (:task_id, :user_id, 1, 'pending', datetime('now','localtime'))
        ");
        foreach ($reviewers as $uid) {
            $insertReviewer->execute(['task_id' => $taskId, 'user_id' => $uid]);
        }

        $newTask = loadTaskOrFail($pdo, $taskId);
        taskActivity($pdo, $newTask, 'task_created', $actorId, '', json_encode([
            'title' => $title,
            'status' => $initialStatus,
            'assignees' => count($members),
            'review_required' => $reviewRequired,
        ]));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'ABORT')) {
            taskFail('Task data violates a database rule: ' . $e->getMessage(), 422);
        }
        error_log('NawAra task create failed: ' . $e->getMessage());
        taskFail('Task could not be created', 500);
    }

    recordAuditEvent('task', $taskId, 'created', $projectId, ['title' => $title]);
    $created = loadTaskOrFail($pdo, $taskId);
    taskOut(['success' => true, 'id' => $taskId, 'task' => taskSummaryRow($pdo, $created)]);
}

/* ------------------------------- update ---------------------------------- */

if ($action === 'update') {
    $taskId = (int)($input['id'] ?? 0);
    $task = loadTaskOrFail($pdo, $taskId);
    assertVersion($task, $input['version'] ?? 0);
    if (!canManageTask($task)) {
        taskFail('You do not have permission to edit this task', 403);
    }
    if (in_array($task['status'], ['completed', 'cancelled'], true)) {
        taskFail('Completed or cancelled tasks cannot be edited', 422);
    }

    $projectId = (int)$task['project_id'];
    $title = taskText((string)($input['title'] ?? $task['title']), 300, 'Title');
    $description = taskOptionalText((string)($input['description'] ?? $task['description']), 5000);
    $priority = (string)($input['priority'] ?? $task['priority']);
    if (!in_array($priority, TASK_PRIORITIES, true)) {
        taskFail('Invalid priority', 422);
    }
    $assignmentMode = (string)($input['assignment_mode'] ?? $task['assignment_mode']);
    if (!in_array($assignmentMode, TASK_ASSIGNMENT_MODES, true)) {
        taskFail('Invalid assignment mode', 422);
    }
    $dueAt = taskValidateDateField((string)($input['due_at'] ?? $task['due_at']), 'Due date');
    $startAt = taskValidateDateField((string)($input['start_at'] ?? $task['start_at']), 'Start date');
    [$sectionId, $itemId] = resolveSectionItem(
        array_key_exists('section_id', $input) ? $input['section_id'] : $task['section_id'],
        array_key_exists('item_id', $input) ? $input['item_id'] : $task['item_id']
    );

    $reviewRequired = array_key_exists('review_required', $input)
        ? taskBool($input['review_required']) : (int)$task['review_required'];
    $clientApproval = array_key_exists('client_approval_required', $input)
        ? taskBool($input['client_approval_required']) : (int)$task['client_approval_required'];
    if ($clientApproval && !$reviewRequired) {
        taskFail('Client approval requires internal review', 422);
    }

    $progressWeight = (float)($input['progress_weight'] ?? $task['progress_weight']);
    if ($progressWeight < 0 || $progressWeight > 10000) {
        taskFail('Progress weight is out of range', 422);
    }

    $changed = [];
    $compare = [
        'title' => $title, 'description' => $description, 'priority' => $priority,
        'assignment_mode' => $assignmentMode, 'due_at' => $dueAt, 'start_at' => $startAt,
    ];
    foreach ($compare as $field => $value) {
        if ((string)$task[$field] !== (string)$value) {
            $changed[] = $field;
        }
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE pm_tasks SET
                title = :title, description = :description, priority = :priority,
                assignment_mode = :assignment_mode, section_id = :section_id, item_id = :item_id,
                start_at = :start_at, due_at = :due_at,
                review_required = :review_required,
                require_file_on_submit = :require_file,
                require_comment_on_submit = :require_comment,
                notify_admin_on_complete = :notify_admin,
                affects_project_progress = :affects,
                progress_weight = :weight,
                client_visible = :client_visible,
                client_approval_required = :client_approval,
                client_comments_enabled = :client_comments,
                client_files_downloadable = :client_files,
                client_notify_on_publish = :client_notify,
                estimated_minutes = :estimated,
                updated_by = :actor, version = version + 1,
                updated_at = datetime('now','localtime')
            WHERE id = :id AND version = :expected_version
        ");
        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
            'assignment_mode' => $assignmentMode,
            'section_id' => $sectionId,
            'item_id' => $itemId,
            'start_at' => $startAt,
            'due_at' => $dueAt,
            'review_required' => $reviewRequired,
            'require_file' => taskBool($input['require_file_on_submit'] ?? $task['require_file_on_submit']),
            'require_comment' => taskBool($input['require_comment_on_submit'] ?? $task['require_comment_on_submit']),
            'notify_admin' => taskBool($input['notify_admin_on_complete'] ?? $task['notify_admin_on_complete']),
            'affects' => taskBool($input['affects_project_progress'] ?? $task['affects_project_progress']),
            'weight' => $progressWeight,
            'client_visible' => taskBool($input['client_visible'] ?? $task['client_visible']),
            'client_approval' => $clientApproval,
            'client_comments' => taskBool($input['client_comments_enabled'] ?? $task['client_comments_enabled']),
            'client_files' => taskBool($input['client_files_downloadable'] ?? $task['client_files_downloadable']),
            'client_notify' => taskBool($input['client_notify_on_publish'] ?? $task['client_notify_on_publish']),
            'estimated' => max(0, (int)($input['estimated_minutes'] ?? $task['estimated_minutes'])),
            'actor' => $actorId,
            'id' => $taskId,
            'expected_version' => (int)$task['version'],
        ]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            taskFail('Task was changed by someone else. Refresh and try again.', 409);
        }

        // Assignment mode change resets member progress to match task status.
        if ($assignmentMode !== (string)$task['assignment_mode']) {
            if ($assignmentMode === 'single') {
                $rowCount = $pdo->prepare('SELECT COUNT(*) FROM pm_task_assignees WHERE task_id = ?');
                $rowCount->execute([$taskId]);
                if ((int)$rowCount->fetchColumn() > 1) {
                    $pdo->rollBack();
                    taskFail('Single mode needs exactly one assignee — reassign the task instead', 422);
                }
            }
            $memberStatus = ['draft' => 'assigned', 'assigned' => 'assigned', 'in_progress' => 'in_progress',
                'blocked' => 'blocked', 'submitted_for_review' => 'submitted',
                'revision_requested' => 'in_progress', 'completed' => 'completed'];
            $resetTo = $memberStatus[(string)$task['status']] ?? 'assigned';
            $pdo->prepare("
                UPDATE pm_task_assignees SET member_status = ?, updated_at = datetime('now','localtime')
                WHERE task_id = ?
            ")->execute([$resetTo, $taskId]);
        }

        $fresh = loadTaskOrFail($pdo, $taskId);
        taskActivity($pdo, $fresh, 'task_updated', $actorId,
            json_encode(array_intersect_key($task, array_flip(array_keys($compare)))),
            json_encode(array_intersect_key(['title' => $title, 'description' => $description,
                'priority' => $priority, 'assignment_mode' => $assignmentMode,
                'due_at' => $dueAt, 'start_at' => $startAt], array_flip($changed ?: []))));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'ABORT')) {
            taskFail('Task data violates a database rule: ' . $e->getMessage(), 422);
        }
        error_log('NawAra task update failed: ' . $e->getMessage());
        taskFail('Task could not be updated', 500);
    }

    recordAuditEvent('task', $taskId, 'updated', $projectId, ['changed' => $changed]);
    $updated = loadTaskOrFail($pdo, $taskId);
    taskOut(['success' => true, 'task' => taskSummaryRow($pdo, $updated)]);
}

/* -------------------------------- assign --------------------------------- */

if ($action === 'assign') {
    $taskId = (int)($input['id'] ?? 0);
    $task = loadTaskOrFail($pdo, $taskId);
    assertVersion($task, $input['version'] ?? 0);
    if (!canDoOnProjectPermission((int)$task['project_id'], 'assign_tasks')) {
        taskFail('You do not have permission to manage assignments', 403);
    }
    if (in_array($task['status'], ['completed', 'cancelled'], true)) {
        taskFail('This task is closed and cannot be reassigned', 422);
    }

    $projectId = (int)$task['project_id'];
    $assignmentMode = (string)($input['assignment_mode'] ?? $task['assignment_mode']);
    if (!in_array($assignmentMode, TASK_ASSIGNMENT_MODES, true)) {
        taskFail('Invalid assignment mode', 422);
    }

    $responsibleId = (int)($input['responsible_id'] ?? 0);
    $contributorIds = is_array($input['contributor_ids'] ?? null) ? $input['contributor_ids'] : [];
    $reviewerIds = is_array($input['reviewer_ids'] ?? null) ? $input['reviewer_ids'] : null;

    $responsible = $responsibleId > 0 ? resolveUserIds([$responsibleId], true, $projectId) : [];
    $contributors = resolveUserIds($contributorIds, true, $projectId);

    // Unassigned tasks start as drafts and are bound to people later through
    // the assign action; when people are given at creation time they must
    // match the chosen mode.
    if ($assignmentMode === 'single' && $contributors !== []) {
        taskFail('Single mode cannot have contributors', 422);
    }

    $memberStatus = ['draft' => 'assigned', 'assigned' => 'assigned', 'in_progress' => 'in_progress',
        'blocked' => 'blocked', 'submitted_for_review' => 'submitted',
        'revision_requested' => 'in_progress', 'completed' => 'completed'];
    $resetTo = $memberStatus[(string)$task['status']] ?? 'assigned';

    try {
        $pdo->beginTransaction();

        $previous = taskAssignees($pdo, $taskId);
        $pdo->prepare('DELETE FROM pm_task_assignees WHERE task_id = ?')->execute([$taskId]);

        $insertAssignee = $pdo->prepare("
            INSERT INTO pm_task_assignees
                (task_id, user_id, assignment_role, required_to_submit, member_status,
                 created_at, updated_at)
            VALUES (:task_id, :user_id, :role, :required, :member_status,
                    datetime('now','localtime'), datetime('now','localtime'))
        ");
        foreach ($responsible as $uid) {
            $insertAssignee->execute([
                'task_id' => $taskId, 'user_id' => $uid, 'role' => 'responsible',
                'required' => 1, 'member_status' => $resetTo,
            ]);
        }
        foreach ($contributors as $uid) {
            $insertAssignee->execute([
                'task_id' => $taskId, 'user_id' => $uid, 'role' => 'contributor',
                'required' => $assignmentMode === 'all_assignees_required' ? 1 : 0,
                'member_status' => $resetTo,
            ]);
        }

        if ($reviewerIds !== null) {
            $reviewers = resolveUserIds($reviewerIds, false, null);
            $pdo->prepare('DELETE FROM pm_task_reviewers WHERE task_id = ?')->execute([$taskId]);
            $insertReviewer = $pdo->prepare("
                INSERT INTO pm_task_reviewers (task_id, user_id, required, decision, created_at)
                VALUES (:task_id, :user_id, 1, 'pending', datetime('now','localtime'))
            ");
            foreach ($reviewers as $uid) {
                $insertReviewer->execute(['task_id' => $taskId, 'user_id' => $uid]);
            }
        }

        $newStatus = (string)$task['status'];
        if ($newStatus === 'draft' && ($responsible !== [] || $contributors !== [])) {
            $newStatus = 'assigned';
        }

        $stmt = $pdo->prepare("
            UPDATE pm_tasks
            SET assignment_mode = :mode, status = :status, updated_by = :actor,
                version = version + 1, updated_at = datetime('now','localtime')
            WHERE id = :id AND version = :expected_version
        ");
        $stmt->execute([
            'mode' => $assignmentMode,
            'status' => $newStatus,
            'actor' => $actorId,
            'id' => $taskId,
            'expected_version' => (int)$task['version'],
        ]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            taskFail('Task was changed by someone else. Refresh and try again.', 409);
        }

        $fresh = loadTaskOrFail($pdo, $taskId);
        taskActivity($pdo, $fresh, 'assignees_changed', $actorId,
            json_encode(array_map(static fn(array $a): int => (int)$a['user_id'], $previous)),
            json_encode(array_values(array_map('intval', array_merge($responsible, $contributors)))));

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('NawAra task assign failed: ' . $e->getMessage());
        taskFail('Assignment could not be saved', 500);
    }

    recordAuditEvent('task', $taskId, 'assignees_changed', $projectId, [
        'responsible' => $responsible, 'contributors' => $contributors,
    ]);
    $updated = loadTaskOrFail($pdo, $taskId);
    taskOut(['success' => true, 'task' => taskSummaryRow($pdo, $updated)]);
}

/* ------------------------------ transition ------------------------------- */

if ($action === 'transition') {
    $taskId = (int)($input['id'] ?? 0);
    $to = (string)($input['to'] ?? '');
    $reason = (string)($input['reason'] ?? '');

    $task = loadTaskOrFail($pdo, $taskId);
    assertVersion($task, $input['version'] ?? 0);

    $assignees = taskAssignees($pdo, $taskId);
    $reviewers = taskReviewers($pdo, $taskId);
    $from = (string)$task['status'];
    $pid = (int)$task['project_id'];
    $mode = (string)$task['assignment_mode'];
    $meId = $actorId;

    if ($reason !== '' && !in_array($reason, TASK_BLOCK_REASONS, true)) {
        taskFail('Invalid block reason', 422);
    }

    $allowedFrom = [
        'in_progress' => ['assigned', 'blocked', 'revision_requested'],
        'blocked' => ['assigned', 'in_progress'],
        'submitted_for_review' => ['assigned', 'in_progress'],
        'completed' => ['assigned', 'in_progress', 'submitted_for_review'],
        'awaiting_client_approval' => ['submitted_for_review'],
        'revision_requested' => ['submitted_for_review', 'awaiting_client_approval'],
        'cancelled' => ['draft', 'assigned', 'in_progress', 'blocked'],
    ];
    if (!isset($allowedFrom[$to])) {
        taskFail('Unknown transition', 422);
    }
    assertTaskStatus($from, array_merge($allowedFrom[$to], [$to]));
    if (!in_array($from, $allowedFrom[$to], true)) {
        taskFail("Cannot move a task from {$from} to {$to}", 422);
    }

    // ---- actor policy per destination ----
    $isDirectComplete = false;
    switch ($to) {
        case 'in_progress':
        case 'blocked':
            if (!canWorkTask($task, $assignees)) {
                taskFail('You are not allowed to update this task', 403);
            }
            break;

        case 'submitted_for_review':
            if (!canSubmitTask($task, $assignees)) {
                taskFail('You are not allowed to submit this task', 403);
            }
            break;

        case 'completed':
            if ($from === 'submitted_for_review') {
                if (!canReviewTask($task, $reviewers)) {
                    taskFail('Only a reviewer can approve this task', 403);
                }
            } else {
                // Direct completion path (Review Required = off).
                if ((int)$task['review_required'] === 1) {
                    taskFail('This task requires review before completion', 403);
                }
                if (!canSubmitTask($task, $assignees)) {
                    taskFail('You are not allowed to complete this task', 403);
                }
                $isDirectComplete = true;
            }
            if ((int)$task['client_approval_required'] === 1) {
                taskFail('This task also requires client approval. Use Approve & Send to Client.', 422);
            }
            break;

        case 'awaiting_client_approval':
            if (!canReviewTask($task, $reviewers)) {
                taskFail('Only a reviewer can approve this task', 403);
            }
            if ((int)$task['client_approval_required'] !== 1) {
                taskFail('This task does not require client approval', 422);
            }
            break;

        case 'revision_requested':
            if (!canReviewTask($task, $reviewers)) {
                taskFail('Only a reviewer can request a revision', 403);
            }
            break;

        case 'cancelled':
            if (!canManageTask($task)) {
                taskFail('You are not allowed to cancel this task', 403);
            }
            break;
    }

    // ---- submit gates ----
    if ($to === 'submitted_for_review') {
        if ($assignees === []) {
            taskFail('Assign someone before submitting this task', 422);
        }
        if ((int)$task['require_comment_on_submit'] === 1) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM pm_task_comments WHERE task_id = ?');
            $count->execute([$taskId]);
            if ((int)$count->fetchColumn() === 0) {
                taskFail('Add at least one comment before submitting', 422);
            }
        }
        if ((int)$task['require_file_on_submit'] === 1) {
            $count = $pdo->prepare("SELECT COUNT(*) FROM pm_task_attachments WHERE task_id = ? AND deleted_at = ''");
            $count->execute([$taskId]);
            if ((int)$count->fetchColumn() === 0) {
                taskFail('Upload at least one file before submitting', 422);
            }
        }

        if ($mode === 'all_assignees_required') {
            $mine = memberStatus($assignees, $meId);
            if ($mine === null && !canManageTask($task)) {
                taskFail('Only assigned members can submit their part', 403);
            }
            $pending = 0;
            foreach ($assignees as $assignee) {
                $isMe = (int)$assignee['user_id'] === $meId;
                if ((int)$assignee['required_to_submit'] === 1
                    && (string)$assignee['member_status'] !== 'submitted'
                    && !$isMe) {
                    $pending++;
                }
            }
            // The actor's own part is marked submitted by the update below.
            if ($pending > 0) {
                // Partial submit: record member progress only, task stays put.
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("
                        UPDATE pm_task_assignees
                        SET member_status = 'submitted', submitted_at = datetime('now','localtime'),
                            updated_at = datetime('now','localtime')
                        WHERE task_id = :task_id AND user_id = :user_id
                    ");
                    $stmt->execute(['task_id' => $taskId, 'user_id' => $meId]);
                    $fresh = loadTaskOrFail($pdo, $taskId);
                    taskActivity($pdo, $fresh, 'member_submitted', $meId, '', json_encode(['pending' => $pending]));
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('NawAra member submit failed: ' . $e->getMessage());
                    taskFail('Submit failed', 500);
                }
                $final = loadTaskOrFail($pdo, $taskId);
                taskOut([
                    'success' => true,
                    'partial' => true,
                    'pending_members' => $pending,
                    'task' => taskSummaryRow($pdo, $final),
                ]);
            }
            // All required members have submitted (actor included by update).
        }
    }

    // ---- revision semantics ----
    if ($to === 'revision_requested') {
        $comment = (string)($input['comment'] ?? '');
        if ($comment !== '') {
            $comment = taskOptionalText($comment, 4000);
        }
    }

    // ---- apply ----
    $now = date('Y-m-d H:i:s');
    $updates = [
        'status' => $to,
        'updated_by' => $actorId,
        'version' => (int)$task['version'] + 1,
        'updated_at' => $now,
    ];
    $activityExtra = [];
    $auditAction = 'status_changed';

    switch ($to) {
        case 'in_progress':
            if ($from === 'assigned' && $task['actual_start_at'] === '') {
                $updates['actual_start_at'] = $now;
            }
            if ($from === 'revision_requested') {
                $activityExtra['resumed_from'] = 'revision_requested';
            } elseif ($from === 'blocked') {
                $activityExtra['unblocked'] = true;
            }
            break;

        case 'blocked':
            if ($reason === '') {
                taskFail('A block reason is required', 422);
            }
            $activityExtra['reason'] = $reason;
            $auditAction = 'blocked';
            break;

        case 'submitted_for_review':
            $updates['submitted_at'] = $now;
            $auditAction = 'submitted_for_review';
            break;

        case 'completed':
            $updates['completed_at'] = $now;
            $updates['client_approval_status'] = (int)$task['client_approval_required'] === 1
                ? 'approved' : $task['client_approval_status'];
            $auditAction = 'completed';
            break;

        case 'awaiting_client_approval':
            $updates['submitted_at'] = $task['submitted_at'] !== '' ? $task['submitted_at'] : $now;
            $updates['published_to_client_at'] = $now;
            $updates['client_approval_status'] = 'awaiting_approval';
            $auditAction = 'published_to_client';
            break;

        case 'revision_requested':
            $updates['submitted_at'] = '';
            $updates['client_approval_status'] = (string)$task['client_approval_status'] === 'awaiting_approval'
                ? 'changes_requested' : $task['client_approval_status'];
            $auditAction = 'revision_requested';
            break;

        case 'cancelled':
            $auditAction = 'cancelled';
            break;
    }

    // ---- member status bookkeeping ----
    // '__actor_only__' marks transitions that must only update the actor's
    // own member row (all_assignees_required mode).
    $memberUpdates = [];
    $actorOnly = false;
    switch ($to) {
        case 'in_progress':
            $memberUpdates = ["member_status = 'in_progress'"];
            break;
        case 'blocked':
            $memberUpdates = ["member_status = 'blocked'"];
            if ($mode === 'all_assignees_required') {
                $actorOnly = true;
            }
            break;
        case 'submitted_for_review':
            $memberUpdates = ["member_status = 'submitted'", "submitted_at = datetime('now','localtime')"];
            break;
        case 'completed':
            $memberUpdates = ["member_status = 'completed'", "completed_at = datetime('now','localtime')"];
            break;
        case 'revision_requested':
            $memberUpdates = ["member_status = 'in_progress'", "submitted_at = ''"];
            break;
    }

    try {
        $pdo->beginTransaction();

        // Re-read inside the transaction to serialize version checks.
        $stmt = $pdo->prepare('SELECT * FROM pm_tasks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $taskId]);
        $freshTask = $stmt->fetch();
        if (!$freshTask || (int)$freshTask['version'] !== (int)$task['version']) {
            $pdo->rollBack();
            taskFail('Task was changed by someone else. Refresh and try again.', 409);
        }

        $setClauses = [];
        $params = ['id' => $taskId, 'expected_version' => (int)$task['version'], 'actor' => $actorId];
        foreach ($updates as $field => $value) {
            // version/updated_by/updated_at are appended explicitly below.
            if (in_array($field, ['version', 'updated_by', 'updated_at'], true)) {
                continue;
            }
            $setClauses[] = "{$field} = :{$field}";
            $params[$field] = $value;
        }
        $setClauses[] = 'updated_by = :actor';
        $setClauses[] = 'version = version + 1';
        $setClauses[] = "updated_at = datetime('now','localtime')";

        $sql = 'UPDATE pm_tasks SET ' . implode(', ', $setClauses)
            . ' WHERE id = :id AND version = :expected_version';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            taskFail('Task was changed by someone else. Refresh and try again.', 409);
        }

        // member rows
        if ($to === 'blocked' && $actorOnly) {
            $pdo->prepare("
                UPDATE pm_task_assignees
                SET member_status = 'blocked', updated_at = datetime('now','localtime')
                WHERE task_id = ? AND user_id = ?
            ")->execute([$taskId, $meId]);
        } elseif ($memberUpdates !== []) {
            $set = implode(', ', $memberUpdates);
            $pdo->prepare("
                UPDATE pm_task_assignees
                SET {$set}, updated_at = datetime('now','localtime')
                WHERE task_id = ?
            ")->execute([$taskId]);
        }

        // On approve-with-client: create recipient rows for project clients.
        if ($to === 'awaiting_client_approval') {
            $insertRecipient = $pdo->prepare("
                INSERT OR IGNORE INTO pm_task_client_recipients
                    (task_id, user_id, approval_required, comments_enabled, decision,
                     published_at, created_at)
                VALUES (:task_id, :user_id, 1, :comments, 'awaiting_approval', :published, datetime('now','localtime'))
            ");
            $clients = $pdo->prepare("
                SELECT m.user_id FROM pm_project_members m
                INNER JOIN pm_users u ON u.id = m.user_id
                WHERE m.project_id = ? AND m.active = 1
                  AND u.active = 1 AND u.account_type = 'client'
            ");
            $clients->execute([$pid]);
            foreach ($clients->fetchAll(PDO::FETCH_COLUMN) as $clientUserId) {
                $insertRecipient->execute([
                    'task_id' => $taskId,
                    'user_id' => (int)$clientUserId,
                    'comments' => (int)$task['client_comments_enabled'],
                    'published' => $now,
                ]);
            }
        }

        $fresh = loadTaskOrFail($pdo, $taskId);
        taskActivity($pdo, $fresh, 'status_changed', $actorId, $from . ($reason !== '' ? ":{$reason}" : ''),
            $to . (!empty($activityExtra) ? ':' . json_encode($activityExtra) : ''));
        if ($to === 'blocked') {
            taskActivity($pdo, $fresh, 'blocked', $actorId, '', json_encode(['reason' => $reason]));
        }
        if ($to === 'revision_requested') {
            $comment = trim((string)($input['comment'] ?? ''));
            if ($comment !== '') {
                $insertComment = $pdo->prepare("
                    INSERT INTO pm_task_comments (task_id, author_user_id, visibility, body, created_at, updated_at)
                    VALUES (:task_id, :author, 'internal', :body, datetime('now','localtime'), datetime('now','localtime'))
                ");
                $insertComment->execute(['task_id' => $taskId, 'author' => $actorId, 'body' => $comment]);
                taskActivity($pdo, $fresh, 'comment_added', $actorId, '', 'review comment');
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'ABORT')) {
            taskFail('Transition violates a database rule: ' . $e->getMessage(), 422);
        }
        error_log('NawAra task transition failed: ' . $e->getMessage());
        taskFail('Transition failed', 500);
    }

    recordAuditEvent('task', $taskId, $auditAction, $pid, [
        'from' => $from, 'to' => $to,
        'review_required' => (int)$task['review_required'],
        'client_approval_required' => (int)$task['client_approval_required'],
        'is_direct_complete' => $isDirectComplete,
    ]);

    $final = loadTaskOrFail($pdo, $taskId);
    taskOut(['success' => true, 'task' => taskSummaryRow($pdo, $final), 'from' => $from, 'to' => $to]);
}

/* ------------------------------ add_comment ------------------------------ */

if ($action === 'add_comment') {
    $taskId = (int)($input['id'] ?? 0);
    $task = loadTaskOrFail($pdo, $taskId);
    $user = getCurrentUser();

    $body = taskText((string)($input['body'] ?? ''), 4000, 'Comment');

    if (isClientAccount($user)) {
        if (!(int)$task['client_comments_enabled']) {
            taskFail('Comments are disabled for this task', 403);
        }
        $stmt = $pdo->prepare('SELECT id FROM pm_task_client_recipients WHERE task_id = ? AND user_id = ?');
        $stmt->execute([$taskId, $actorId]);
        if (!$stmt->fetch()) {
            taskFail('You do not have permission to comment on this task', 403);
        }
        $visibility = 'client';
    } else {
        if (!canViewTask($task)) {
            taskFail('You do not have permission to comment on this task', 403);
        }
        $visibility = 'internal';
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO pm_task_comments (task_id, author_user_id, visibility, body, created_at, updated_at)
            VALUES (:task_id, :author, :visibility, :body, datetime('now','localtime'), datetime('now','localtime'))
        ");
        $stmt->execute([
            'task_id' => $taskId, 'author' => $actorId,
            'visibility' => $visibility, 'body' => $body,
        ]);
        $commentId = (int)$pdo->lastInsertId();
        taskActivity($pdo, $task, 'comment_added', $actorId, '', (string)$commentId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('NawAra task comment failed: ' . $e->getMessage());
        taskFail('Comment could not be saved', 500);
    }

    taskOut(['success' => true, 'id' => $commentId]);
}

/* ------------------------------- checklist ------------------------------- */

if (in_array($action, ['checklist_add', 'checklist_toggle', 'checklist_remove'], true)) {
    $taskId = (int)($input['id'] ?? 0);
    $task = loadTaskOrFail($pdo, $taskId);
    $assignees = taskAssignees($pdo, $taskId);

    $isManage = canManageTask($task);
    $isWorker = canWorkTask($task, $assignees);
    if (!$isManage && !$isWorker) {
        taskFail('You do not have permission to change this checklist', 403);
    }

    try {
        if ($action === 'checklist_add') {
            if (!$isManage) {
                taskFail('Only managers can add checklist items', 403);
            }
            $title = taskText((string)($input['title'] ?? ''), 300, 'Checklist item');
            $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM pm_task_checklist_items WHERE task_id = ?');
            $max->execute([$taskId]);
            $sort = (int)$max->fetchColumn() + 1;
            $stmt = $pdo->prepare("
                INSERT INTO pm_task_checklist_items (task_id, title, is_done, sort_order, created_at)
                VALUES (:task_id, :title, 0, :sort, datetime('now','localtime'))
            ");
            $stmt->execute(['task_id' => $taskId, 'title' => $title, 'sort' => $sort]);
            $itemIdOut = (int)$pdo->lastInsertId();
        } elseif ($action === 'checklist_toggle') {
            $itemId = (int)($input['item_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM pm_task_checklist_items WHERE id = ? AND task_id = ?');
            $stmt->execute([$itemId, $taskId]);
            $item = $stmt->fetch();
            if (!$item) {
                taskFail('Checklist item not found', 404);
            }
            $newDone = (int)$item['is_done'] === 1 ? 0 : 1;
            $stmt = $pdo->prepare("
                UPDATE pm_task_checklist_items
                SET is_done = :done, completed_by = :actor,
                    completed_at = CASE WHEN :done = 1 THEN datetime('now','localtime') ELSE '' END
                WHERE id = :id
            ");
            $stmt->execute(['done' => $newDone, 'actor' => $newDone === 1 ? $actorId : null, 'id' => $itemId]);
            $itemIdOut = $itemId;
            taskActivity($pdo, $task, 'checklist_toggled', $actorId,
                $item['title'], $newDone === 1 ? 'done' : 'open');
        } else {
            if (!$isManage) {
                taskFail('Only managers can remove checklist items', 403);
            }
            $itemId = (int)($input['item_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT title FROM pm_task_checklist_items WHERE id = ? AND task_id = ?');
            $stmt->execute([$itemId, $taskId]);
            $item = $stmt->fetch();
            if (!$item) {
                taskFail('Checklist item not found', 404);
            }
            $pdo->prepare('DELETE FROM pm_task_checklist_items WHERE id = ?')->execute([$itemId]);
            $itemIdOut = $itemId;
            taskActivity($pdo, $task, 'checklist_removed', $actorId, $item['title'], '');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('NawAra checklist failed: ' . $e->getMessage());
        taskFail('Checklist change failed', 500);
    }

    taskOut(['success' => true, 'item_id' => $itemIdOut]);
}

/* ------------------------------ attachments ------------------------------ */

if ($action === 'attachment_upload') {
    $taskId = (int)($input['id'] ?? 0);
    $task = loadTaskOrFail($pdo, $taskId);
    $assignees = taskAssignees($pdo, $taskId);

    if (!canWorkTask($task, $assignees) && !canViewTask($task)) {
        taskFail('You do not have permission to upload files to this task', 403);
    }

    $originalName = basename((string)($input['filename'] ?? ''));
    $originalName = taskText($originalName, 255, 'Filename');
    $contentType = strtolower(trim((string)($input['content_type'] ?? '')));

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/gif' => 'gif', 'application/pdf' => 'pdf',
    ];
    if (!isset($allowed[$contentType])) {
        taskFail('Only JPEG, PNG, WEBP, GIF images and PDF files are allowed', 422);
    }

    $dataB64 = (string)($input['data'] ?? '');
    if ($dataB64 === '') {
        taskFail('File content is required', 422);
    }
    $bytes = base64_decode($dataB64, true);
    if ($bytes === false) {
        taskFail('File content is not valid base64', 422);
    }
    if (strlen($bytes) < 1) {
        taskFail('File is empty', 422);
    }
    if (strlen($bytes) > 10 * 1024 * 1024) {
        taskFail('File is larger than 10 MB', 422);
    }

    $visibility = (string)($input['visibility'] ?? 'internal');
    if (!in_array($visibility, ['internal', 'client'], true)) {
        taskFail('Invalid attachment visibility', 422);
    }
    if ($visibility === 'client' && !canManageTask($task)) {
        taskFail('Only managers can mark files client-visible', 403);
    }

    $ext = $allowed[$contentType];
    $relativeKey = 'tasks/' . $taskId . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    $absoluteDir = APP_UPLOADS_DIR . '/tasks/' . $taskId;
    if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) {
        taskFail('Upload storage is unavailable', 500);
    }
    $absolutePath = APP_UPLOADS_DIR . '/' . $relativeKey;
    if (@file_put_contents($absolutePath, $bytes, LOCK_EX) === false) {
        taskFail('File could not be stored', 500);
    }
    @chmod($absolutePath, 0640);

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO pm_task_attachments
                (task_id, uploaded_by_user_id, original_name, storage_key, content_type,
                 byte_size, sha256, visibility, client_downloadable, created_at)
            VALUES (:task_id, :uploader, :name, :key, :content_type,
                    :bytes, :sha256, :visibility, 0, datetime('now','localtime'))
        ");
        $stmt->execute([
            'task_id' => $taskId,
            'uploader' => $actorId,
            'name' => $originalName,
            'key' => $relativeKey,
            'content_type' => $contentType,
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'visibility' => $visibility,
        ]);
        $attachmentId = (int)$pdo->lastInsertId();
        taskActivity($pdo, $task, 'attachment_added', $actorId, '', json_encode([
            'name' => $originalName, 'bytes' => strlen($bytes),
        ]));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($absolutePath);
        error_log('NawAra attachment upload failed: ' . $e->getMessage());
        taskFail('File could not be attached', 500);
    }

    taskOut(['success' => true, 'id' => $attachmentId, 'bytes' => strlen($bytes)]);
}

if ($action === 'attachment_delete') {
    $taskId = (int)($input['id'] ?? 0);
    $attachmentId = (int)($input['attachment_id'] ?? 0);
    $task = loadTaskOrFail($pdo, $taskId);

    $stmt = $pdo->prepare("SELECT * FROM pm_task_attachments WHERE id = ? AND task_id = ? AND deleted_at = ''");
    $stmt->execute([$attachmentId, $taskId]);
    $attachment = $stmt->fetch();
    if (!$attachment) {
        taskFail('Attachment not found', 404);
    }
    if (!canManageTask($task) && (int)$attachment['uploaded_by_user_id'] !== $actorId) {
        taskFail('You can only remove your own files', 403);
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare("
            UPDATE pm_task_attachments
            SET deleted_at = datetime('now','localtime') WHERE id = ?
        ")->execute([$attachmentId]);
        taskActivity($pdo, $task, 'attachment_removed', $actorId, $attachment['original_name'], '');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('NawAra attachment delete failed: ' . $e->getMessage());
        taskFail('File could not be removed', 500);
    }

    $path = APP_UPLOADS_DIR . '/' . $attachment['storage_key'];
    if (is_file($path)) {
        @unlink($path);
    }

    taskOut(['success' => true]);
}

/* --------------------------- client decision ----------------------------- */

if ($action === 'client_decision') {
    $taskId = (int)($input['id'] ?? 0);
    $task = loadTaskOrFail($pdo, $taskId);
    $user = getCurrentUser();

    if (!isClientAccount($user)) {
        taskFail('Only client accounts can decide on published tasks', 403);
    }
    if ((string)$task['status'] !== 'awaiting_client_approval') {
        taskFail('This task is not awaiting your decision', 422);
    }

    $decision = (string)($input['decision'] ?? '');
    if (!in_array($decision, ['approve', 'request_changes'], true)) {
        taskFail('Invalid decision', 422);
    }
    $comment = taskOptionalText((string)($input['comment'] ?? ''), 4000);

    $stmt = $pdo->prepare('SELECT * FROM pm_task_client_recipients WHERE task_id = ? AND user_id = ?');
    $stmt->execute([$taskId, $actorId]);
    $recipient = $stmt->fetch();
    if (!$recipient) {
        taskFail('You are not a recipient of this deliverable', 403);
    }
    if (!in_array((string)$recipient['decision'], ['not_requested', 'awaiting_approval'], true)) {
        taskFail('You have already decided on this deliverable', 422);
    }
    // A reason with "request changes" is always allowed (it is part of the
    // decision); free-standing approval comments honor the toggle.
    if ($decision === 'approve' && $comment !== '' && !(int)$recipient['comments_enabled']) {
        taskFail('Comments are disabled for this deliverable', 403);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE pm_task_client_recipients
            SET decision = :decision, decision_comment = :comment, decided_at = datetime('now','localtime')
            WHERE id = :id
        ");
        $stmt->execute([
            'decision' => $decision === 'approve' ? 'approved' : 'changes_requested',
            'comment' => $comment,
            'id' => (int)$recipient['id'],
        ]);

        if ($comment !== '') {
            $insertComment = $pdo->prepare("
                INSERT INTO pm_task_comments (task_id, author_user_id, visibility, body, created_at, updated_at)
                VALUES (:task_id, :author, 'client', :body, datetime('now','localtime'), datetime('now','localtime'))
            ");
            $insertComment->execute(['task_id' => $taskId, 'author' => $actorId, 'body' => $comment]);
        }

        $from = (string)$task['status'];
        if ($decision === 'approve') {
            $pdo->prepare("
                UPDATE pm_tasks
                SET status = 'completed', client_approval_status = 'approved',
                    completed_at = datetime('now','localtime'), version = version + 1,
                    updated_at = datetime('now','localtime')
                WHERE id = :id AND status = 'awaiting_client_approval'
            ")->execute(['id' => $taskId]);
            $pdo->prepare("
                UPDATE pm_task_assignees
                SET member_status = 'completed', completed_at = datetime('now','localtime'),
                    updated_at = datetime('now','localtime')
                WHERE task_id = ?
            ")->execute([$taskId]);
            $to = 'completed';
        } else {
            $pdo->prepare("
                UPDATE pm_tasks
                SET status = 'revision_requested', client_approval_status = 'changes_requested',
                    submitted_at = '', version = version + 1,
                    updated_at = datetime('now','localtime')
                WHERE id = :id AND status = 'awaiting_client_approval'
            ")->execute(['id' => $taskId]);
            $pdo->prepare("
                UPDATE pm_task_assignees
                SET member_status = 'in_progress', submitted_at = '', updated_at = datetime('now','localtime')
                WHERE task_id = ?
            ")->execute([$taskId]);
            $to = 'revision_requested';
        }

        $fresh = loadTaskOrFail($pdo, $taskId);
        taskActivity($pdo, $fresh, 'client_decision', $actorId, $from, $to . ':' . $decision);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('NawAra client decision failed: ' . $e->getMessage());
        taskFail('Decision could not be recorded', 500);
    }

    recordAuditEvent('task', $taskId, 'client_' . $decision, (int)$task['project_id'], []);
    $final = loadTaskOrFail($pdo, $taskId);
    taskOut(['success' => true, 'task' => taskSummaryRow($pdo, $final), 'decision' => $decision]);
}

taskFail('Unknown action', 400);
