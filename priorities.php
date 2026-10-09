<?php
/**
 * priorities.php
 * To-Do / Priority Tasks Manager
 */

require_once __DIR__ . '/database.php';

// Legacy priorities are a shared administrative board, not the new personal
// Task model. Restrict it to Head Admins until project/owner scoping is built.
requireAdminJson();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrfTokenJson();
}

function pri_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function pri_text($value, int $maxLength): string
{
    $value = trim((string)$value);
    if (str_contains($value, "\0") || strlen($value) > $maxLength) {
        throw new InvalidArgumentException('Invalid priority input');
    }
    return $value;
}

function pri_valid_due_date(string $date): bool
{
    if ($date === '') {
        return true;
    }
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    $errors = DateTime::getLastErrors();
    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

function pri_active_assignee(PDO $pdo, ?int $assigneeId): bool
{
    if ($assigneeId === null) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM pm_engineers WHERE id = :id AND active = 1 LIMIT 1');
    $stmt->execute(['id' => $assigneeId]);
    return (bool)$stmt->fetchColumn();
}

function pri_decode_json_body(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        pri_json(['success' => false, 'error' => 'Invalid JSON'], 400);
    }
    return $input;
}

function pri_version_from_input(array $input): int
{
    if (!array_key_exists('version', $input)) {
        pri_json(['success' => false, 'error' => 'Task version is required. Refresh and try again.'], 422);
    }
    $version = filter_var($input['version'], FILTER_VALIDATE_INT);
    if ($version === false || $version < 1) {
        pri_json(['success' => false, 'error' => 'Invalid task version'], 422);
    }
    return (int)$version;
}

try {
    $pdo = getDB();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($method === 'GET' && $action === '') {
        $action = 'list';
    }

    $writeActions = ['add', 'toggle', 'edit', 'delete', 'restore', 'add_engineer'];
    if (in_array($action, $writeActions, true) && $method !== 'POST') {
        pri_json(['success' => false, 'error' => 'Method Not Allowed'], 405);
    }

    /**
     * LIST
     */
    if ($action === 'list') {
        if ($method !== 'GET') {
            pri_json(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }
        $filter = $_GET['filter'] ?? 'all';

        $where = "WHERE p.deleted_at = ''";
        if ($filter === 'pending') {
            $where .= ' AND p.is_done = 0';
        } elseif ($filter === 'done') {
            $where .= ' AND p.is_done = 1';
        }

        $stmt = $pdo->prepare("
            SELECT p.*, e.name AS assignee_name
            FROM pm_priorities p
            LEFT JOIN pm_engineers e ON e.id = p.assignee_id
            $where
            ORDER BY p.is_done ASC, 
                     CASE p.priority 
                        WHEN 'critical' THEN 1 
                        WHEN 'high' THEN 2 
                        WHEN 'medium' THEN 3 
                        WHEN 'low' THEN 4 
                        ELSE 5 
                     END ASC,
                     p.created_at DESC
        ");
        $stmt->execute();
        $tasks = $stmt->fetchAll();

        // آمار
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM pm_priorities WHERE deleted_at = ''");
        $total = (int)$totalStmt->fetchColumn();

        $doneStmt = $pdo->query("SELECT COUNT(*) FROM pm_priorities WHERE deleted_at = '' AND is_done = 1");
        $done = (int)$doneStmt->fetchColumn();

        $pending = $total - $done;

        // لیست انجینرها
        $engineers = [];
        try {
            $engStmt = $pdo->query("
                SELECT id, name, role 
                FROM pm_engineers 
                WHERE active = 1 
                ORDER BY sort_order ASC, name ASC
            ");
            $engineers = $engStmt->fetchAll();
        } catch (Throwable $e) {
            // اگر جدول نداشت خالی
        }

        pri_json([
            'success' => true,
            'tasks' => $tasks,
            'stats' => [
                'total' => $total,
                'done' => $done,
                'pending' => $pending
            ],
            'engineers' => $engineers
        ]);
    }

    /**
     * ARCHIVED LIST (Head Admin only; endpoint is already admin-scoped)
     */
    if ($action === 'archived') {
        if ($method !== 'GET') {
            pri_json(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }
        $stmt = $pdo->query("
            SELECT p.*, e.name AS assignee_name, u.name AS archived_by_name
            FROM pm_priorities p
            LEFT JOIN pm_engineers e ON e.id = p.assignee_id
            LEFT JOIN pm_users u ON u.id = p.deleted_by
            WHERE p.deleted_at <> ''
            ORDER BY p.deleted_at DESC, p.id DESC
            LIMIT 500
        ");
        pri_json(['success' => true, 'tasks' => $stmt->fetchAll()]);
    }

    /**
     * ADD
     */
    if ($action === 'add') {
        $input = pri_decode_json_body();
        try {
            $title = pri_text($input['title'] ?? '', 500);
            $dueDate = pri_text($input['due_date'] ?? '', 10);
        } catch (InvalidArgumentException $e) {
            pri_json(['success' => false, 'error' => 'Invalid task input'], 422);
        }
        if ($title === '') {
            pri_json(['success' => false, 'error' => 'A valid task title is required'], 422);
        }
        if (!pri_valid_due_date($dueDate)) {
            pri_json(['success' => false, 'error' => 'Due date must use YYYY-MM-DD'], 422);
        }

        $assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;
        if ($assigneeId !== null && $assigneeId <= 0) {
            pri_json(['success' => false, 'error' => 'Invalid assignee'], 422);
        }
        if (!pri_active_assignee($pdo, $assigneeId)) {
            pri_json(['success' => false, 'error' => 'Assignee must be an active engineer'], 422);
        }

        $priority = $input['priority'] ?? 'medium';
        $validPriorities = ['critical', 'high', 'medium', 'low'];
        if (!is_string($priority) || !in_array($priority, $validPriorities, true)) {
            pri_json(['success' => false, 'error' => 'Invalid priority'], 422);
        }

        $actorId = (int)(getCurrentUser()['id'] ?? 0) ?: null;
        $stmt = $pdo->prepare("
            INSERT INTO pm_priorities (title, assignee_id, priority, due_date, created_by, updated_by)
            VALUES (:title, :assignee_id, :priority, :due_date, :created_by, :updated_by)
        ");
        $stmt->execute([
            'title' => $title,
            'assignee_id' => $assigneeId,
            'priority' => $priority,
            'due_date' => $dueDate,
            'created_by' => $actorId,
            'updated_by' => $actorId
        ]);

        $newId = (int)$pdo->lastInsertId();
        recordAuditEvent('priority', $newId, 'created', null, ['priority' => $priority]);
        pri_json([
            'success' => true,
            'message' => 'Task added',
            'id' => $newId,
            'version' => 1
        ]);
    }

    /**
     * TOGGLE DONE
     */
    if ($action === 'toggle') {
        $input = pri_decode_json_body();
        $id = (int)($input['id'] ?? 0);
        $expectedVersion = pri_version_from_input($input);
        if ($id <= 0) {
            pri_json(['success' => false, 'error' => 'Invalid task ID'], 400);
        }

        $stmt = $pdo->prepare("SELECT id, is_done, version FROM pm_priorities WHERE id = :id AND deleted_at = '' LIMIT 1");
        $stmt->execute(['id' => $id]);
        $task = $stmt->fetch();
        if (!$task) {
            pri_json(['success' => false, 'error' => 'Task not found'], 404);
        }
        if ($expectedVersion !== (int)$task['version']) {
            pri_json(['success' => false, 'error' => 'Task changed while you were viewing it'], 409);
        }

        $newDone = ((int)$task['is_done'] === 0) ? 1 : 0;
        $completedAt = ($newDone === 1) ? date('Y-m-d H:i:s') : '';
        $actorId = (int)(getCurrentUser()['id'] ?? 0) ?: null;
        $stmt = $pdo->prepare("
            UPDATE pm_priorities
            SET is_done = :is_done,
                completed_at = :completed_at,
                updated_by = :updated_by,
                updated_at = datetime('now','localtime'),
                version = version + 1
            WHERE id = :id AND version = :expected_version AND deleted_at = ''
        ");
        $stmt->execute([
            'is_done' => $newDone,
            'completed_at' => $completedAt,
            'updated_by' => $actorId,
            'id' => $id,
            'expected_version' => (int)$task['version']
        ]);
        if ($stmt->rowCount() !== 1) {
            pri_json(['success' => false, 'error' => 'Task changed while it was being updated'], 409);
        }
        recordAuditEvent('priority', $id, $newDone === 1 ? 'completed' : 'reopened');

        pri_json(['success' => true, 'message' => 'Task updated', 'version' => (int)$task['version'] + 1]);
    }

    /**
     * EDIT
     */
    if ($action === 'edit') {
        $input = pri_decode_json_body();
        $id = (int)($input['id'] ?? 0);
        $expectedVersion = pri_version_from_input($input);
        try {
            $title = pri_text($input['title'] ?? '', 500);
            $dueDate = pri_text($input['due_date'] ?? '', 10);
        } catch (InvalidArgumentException $e) {
            pri_json(['success' => false, 'error' => 'Invalid task input'], 422);
        }
        if ($id <= 0 || $title === '') {
            pri_json(['success' => false, 'error' => 'A valid task ID and title are required'], 422);
        }
        if (!pri_valid_due_date($dueDate)) {
            pri_json(['success' => false, 'error' => 'Due date must use YYYY-MM-DD'], 422);
        }

        $assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;
        if ($assigneeId !== null && ($assigneeId <= 0 || !pri_active_assignee($pdo, $assigneeId))) {
            pri_json(['success' => false, 'error' => 'Assignee must be an active engineer'], 422);
        }
        $priority = $input['priority'] ?? 'medium';
        $validPriorities = ['critical', 'high', 'medium', 'low'];
        if (!is_string($priority) || !in_array($priority, $validPriorities, true)) {
            pri_json(['success' => false, 'error' => 'Invalid priority'], 422);
        }

        $existsStmt = $pdo->prepare("SELECT id, version FROM pm_priorities WHERE id = :id AND deleted_at = '' LIMIT 1");
        $existsStmt->execute(['id' => $id]);
        $task = $existsStmt->fetch();
        if (!$task) {
            pri_json(['success' => false, 'error' => 'Task not found'], 404);
        }
        if ($expectedVersion !== (int)$task['version']) {
            pri_json(['success' => false, 'error' => 'Task changed while you were viewing it'], 409);
        }

        $actorId = (int)(getCurrentUser()['id'] ?? 0) ?: null;
        $stmt = $pdo->prepare("
            UPDATE pm_priorities
            SET title = :title,
                assignee_id = :assignee_id,
                priority = :priority,
                due_date = :due_date,
                updated_by = :updated_by,
                updated_at = datetime('now','localtime'),
                version = version + 1
            WHERE id = :id AND version = :expected_version AND deleted_at = ''
        ");
        $stmt->execute([
            'title' => $title,
            'assignee_id' => $assigneeId,
            'priority' => $priority,
            'due_date' => $dueDate,
            'updated_by' => $actorId,
            'id' => $id,
            'expected_version' => (int)$task['version']
        ]);
        if ($stmt->rowCount() !== 1) {
            pri_json(['success' => false, 'error' => 'Task changed while it was being updated'], 409);
        }
        recordAuditEvent('priority', $id, 'updated', null, ['priority' => $priority]);

        pri_json(['success' => true, 'message' => 'Task updated', 'version' => (int)$task['version'] + 1]);
    }

    /**
     * DELETE
     */
    if ($action === 'delete') {
        $input = pri_decode_json_body();
        $id = (int)($input['id'] ?? 0);
        $expectedVersion = pri_version_from_input($input);
        if ($id <= 0) {
            pri_json(['success' => false, 'error' => 'Invalid task ID'], 400);
        }

        $findStmt = $pdo->prepare("SELECT id, version FROM pm_priorities WHERE id = :id AND deleted_at = '' LIMIT 1");
        $findStmt->execute(['id' => $id]);
        $task = $findStmt->fetch();
        if (!$task) {
            pri_json(['success' => false, 'error' => 'Task not found'], 404);
        }
        if ($expectedVersion !== (int)$task['version']) {
            pri_json(['success' => false, 'error' => 'Task changed while you were viewing it'], 409);
        }

        $actorId = (int)(getCurrentUser()['id'] ?? 0) ?: null;
        $stmt = $pdo->prepare("
            UPDATE pm_priorities
            SET deleted_at = datetime('now','localtime'),
                deleted_by = :deleted_by,
                updated_by = :updated_by,
                updated_at = datetime('now','localtime'),
                version = version + 1
            WHERE id = :id AND version = :expected_version AND deleted_at = ''
        ");
        $stmt->execute([
            'deleted_by' => $actorId,
            'updated_by' => $actorId,
            'id' => $id,
            'expected_version' => (int)$task['version']
        ]);
        if ($stmt->rowCount() !== 1) {
            pri_json(['success' => false, 'error' => 'Task changed while it was being archived'], 409);
        }
        recordAuditEvent('priority', $id, 'archived');

        pri_json(['success' => true, 'message' => 'Task archived', 'version' => (int)$task['version'] + 1]);
    }

    /**
     * RESTORE
     */
    if ($action === 'restore') {
        $input = pri_decode_json_body();
        $id = (int)($input['id'] ?? 0);
        $expectedVersion = pri_version_from_input($input);
        if ($id <= 0) {
            pri_json(['success' => false, 'error' => 'Invalid task ID'], 400);
        }

        $findStmt = $pdo->prepare("SELECT id, version FROM pm_priorities WHERE id = :id AND deleted_at <> '' LIMIT 1");
        $findStmt->execute(['id' => $id]);
        $task = $findStmt->fetch();
        if (!$task) {
            pri_json(['success' => false, 'error' => 'Archived task not found'], 404);
        }
        if ($expectedVersion !== (int)$task['version']) {
            pri_json(['success' => false, 'error' => 'Task changed while you were viewing it'], 409);
        }

        $actorId = (int)(getCurrentUser()['id'] ?? 0) ?: null;
        $stmt = $pdo->prepare("
            UPDATE pm_priorities
            SET deleted_at = '',
                deleted_by = NULL,
                updated_by = :updated_by,
                updated_at = datetime('now','localtime'),
                version = version + 1
            WHERE id = :id AND version = :expected_version AND deleted_at <> ''
        ");
        $stmt->execute([
            'updated_by' => $actorId,
            'id' => $id,
            'expected_version' => (int)$task['version']
        ]);
        if ($stmt->rowCount() !== 1) {
            pri_json(['success' => false, 'error' => 'Task changed while it was being restored'], 409);
        }
        recordAuditEvent('priority', $id, 'restored');
        pri_json(['success' => true, 'message' => 'Task restored', 'version' => (int)$task['version'] + 1]);
    }

    /**
     * ADD ENGINEER
     */
    if ($action === 'add_engineer') {
        requirePermissionJson('settings');
        $input = pri_decode_json_body();
        try {
            $name = pri_text($input['name'] ?? '', 150);
        } catch (InvalidArgumentException $e) {
            pri_json(['success' => false, 'error' => 'A valid name is required'], 422);
        }
        if ($name === '') {
            pri_json(['success' => false, 'error' => 'A valid name is required'], 422);
        }

        $stmt = $pdo->prepare("SELECT id FROM pm_engineers WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $name]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $pdo->prepare("UPDATE pm_engineers SET active = 1 WHERE id = :id")->execute(['id' => $existing]);
            $newId = (int)$existing;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pm_engineers (name, role, active, sort_order) 
                VALUES (:name, 'engineer', 1, (SELECT COALESCE(MAX(sort_order),0)+1 FROM pm_engineers))
            ");
            $stmt->execute(['name' => $name]);
            $newId = (int)$pdo->lastInsertId();
        }

        recordAuditEvent('engineer', $newId, 'created_or_reactivated');
        pri_json([
            'success' => true,
            'id' => $newId,
            'name' => $name
        ]);
    }

    pri_json(['success' => false, 'error' => 'Invalid action'], 400);

} catch (Throwable $e) {
    error_log('NawAra priorities endpoint failed: ' . $e->getMessage());
    pri_json(['success' => false, 'error' => 'Server error'], 500);
}