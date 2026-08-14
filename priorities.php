<?php
/**
 * priorities.php
 * To-Do / Priority Tasks Manager
 */

require_once __DIR__ . '/database.php';

if (function_exists('requireLoginJson')) {
    requireLoginJson();
}

function pri_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function pri_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_priorities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            assignee_id INTEGER NULL,
            priority TEXT NOT NULL DEFAULT 'medium',
            is_done INTEGER NOT NULL DEFAULT 0,
            due_date TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            completed_at TEXT NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_priorities_done ON pm_priorities(is_done)");
}

try {
    $pdo = getDB();
    pri_ensure_table($pdo);

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === '') {
        $action = 'list';
    }

    /**
     * LIST
     */
    if ($action === 'list') {
        $filter = $_GET['filter'] ?? 'all';

        $where = '';
        if ($filter === 'pending') {
            $where = 'WHERE p.is_done = 0';
        } elseif ($filter === 'done') {
            $where = 'WHERE p.is_done = 1';
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
        $totalStmt = $pdo->query("SELECT COUNT(*) FROM pm_priorities");
        $total = (int)$totalStmt->fetchColumn();

        $doneStmt = $pdo->query("SELECT COUNT(*) FROM pm_priorities WHERE is_done = 1");
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
     * ADD
     */
    if ($action === 'add') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) pri_json(['success' => false, 'error' => 'Invalid JSON'], 400);

        $title = trim($input['title'] ?? '');
        if ($title === '') pri_json(['success' => false, 'error' => 'Task title is required'], 400);

        $assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;
        $priority = $input['priority'] ?? 'medium';
        $dueDate = trim($input['due_date'] ?? '');

        $validPriorities = ['critical', 'high', 'medium', 'low'];
        if (!in_array($priority, $validPriorities, true)) {
            $priority = 'medium';
        }

        $stmt = $pdo->prepare("
            INSERT INTO pm_priorities (title, assignee_id, priority, due_date)
            VALUES (:title, :assignee_id, :priority, :due_date)
        ");
        $stmt->execute([
            'title' => $title,
            'assignee_id' => $assigneeId,
            'priority' => $priority,
            'due_date' => $dueDate
        ]);

        pri_json([
            'success' => true,
            'message' => 'Task added',
            'id' => (int)$pdo->lastInsertId()
        ]);
    }

    /**
     * TOGGLE DONE
     */
    if ($action === 'toggle') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) pri_json(['success' => false, 'error' => 'Invalid JSON'], 400);

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) pri_json(['success' => false, 'error' => 'Invalid task ID'], 400);

        $stmt = $pdo->prepare("SELECT id, is_done FROM pm_priorities WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $task = $stmt->fetch();

        if (!$task) pri_json(['success' => false, 'error' => 'Task not found'], 404);

        $newDone = ((int)$task['is_done'] === 0) ? 1 : 0;
        $completedAt = ($newDone === 1) ? date('Y-m-d H:i') : '';

        $stmt = $pdo->prepare("
            UPDATE pm_priorities 
            SET is_done = :is_done, completed_at = :completed_at 
            WHERE id = :id
        ");
        $stmt->execute([
            'is_done' => $newDone,
            'completed_at' => $completedAt,
            'id' => $id
        ]);

        pri_json(['success' => true, 'message' => 'Task updated']);
    }

    /**
     * EDIT
     */
    if ($action === 'edit') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) pri_json(['success' => false, 'error' => 'Invalid JSON'], 400);

        $id = (int)($input['id'] ?? 0);
        $title = trim($input['title'] ?? '');

        if ($id <= 0) pri_json(['success' => false, 'error' => 'Invalid task ID'], 400);
        if ($title === '') pri_json(['success' => false, 'error' => 'Title is required'], 400);

        $assigneeId = !empty($input['assignee_id']) ? (int)$input['assignee_id'] : null;
        $priority = $input['priority'] ?? 'medium';
        $dueDate = trim($input['due_date'] ?? '');

        $validPriorities = ['critical', 'high', 'medium', 'low'];
        if (!in_array($priority, $validPriorities, true)) $priority = 'medium';

        $stmt = $pdo->prepare("
            UPDATE pm_priorities 
            SET title = :title, 
                assignee_id = :assignee_id, 
                priority = :priority, 
                due_date = :due_date 
            WHERE id = :id
        ");
        $stmt->execute([
            'title' => $title,
            'assignee_id' => $assigneeId,
            'priority' => $priority,
            'due_date' => $dueDate,
            'id' => $id
        ]);

        pri_json(['success' => true, 'message' => 'Task updated']);
    }

    /**
     * DELETE
     */
    if ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) pri_json(['success' => false, 'error' => 'Invalid JSON'], 400);

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) pri_json(['success' => false, 'error' => 'Invalid task ID'], 400);

        $stmt = $pdo->prepare("DELETE FROM pm_priorities WHERE id = :id");
        $stmt->execute(['id' => $id]);

        pri_json(['success' => true, 'message' => 'Task deleted']);
    }

    /**
     * ADD ENGINEER
     */
    if ($action === 'add_engineer') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) pri_json(['success' => false, 'error' => 'Invalid JSON'], 400);

        $name = trim($input['name'] ?? '');
        if ($name === '') pri_json(['success' => false, 'error' => 'Name is required'], 400);

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

        pri_json([
            'success' => true,
            'id' => $newId,
            'name' => $name
        ]);
    }

    pri_json(['success' => false, 'error' => 'Invalid action'], 400);

} catch (Throwable $e) {
    pri_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}