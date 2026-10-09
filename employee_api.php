<?php
/**
 * employee_api.php — employee workload & score statistics (stage 5).
 *
 * GET action=stats                — admin: everyone (tasks, score, open work)
 * GET action=detail&user_id=N     — admin: full per-person breakdown
 * GET action=my_score             — any logged-in user: own bare number
 * GET action=project_progress     — admin: per-project progress + EVM line
 *
 * Score = Σ pm_task_score_events.points (progress points raised on approvals).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/evm_helpers.php';

requireLoginJson();

header('Content-Type: application/json; charset=UTF-8');

$action = (string)($_GET['action'] ?? 'stats');

function empOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function empFail(string $error, int $status = 400): void
{
    empOut(['success' => false, 'error' => $error], $status);
}

$pdo = getDB();
$currentUser = getCurrentUser();

/** Active internal people (employees + admins), excluding clients. */
function internalPeople(PDO $pdo): array
{
    return $pdo->query("
        SELECT id, name, username, role, account_type
        FROM pm_users
        WHERE active = 1
          AND account_type != 'client'
          AND role != 'admin'
        ORDER BY name COLLATE NOCASE
    ")->fetchAll();
}

function scoreTotal(PDO $pdo, int $userId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM pm_task_score_events WHERE user_id = :u');
    $stmt->execute(['u' => $userId]);
    return round((float)$stmt->fetchColumn(), 2);
}

function completedCount(PDO $pdo, int $userId, string $from, string $to): int
{
    $sql = "
        SELECT COUNT(DISTINCT t.id)
        FROM pm_tasks t
        INNER JOIN pm_task_assignees a ON a.task_id = t.id
        WHERE a.user_id = :u
          AND a.member_status = 'completed'
    ";
    $params = ['u' => $userId];
    if ($from !== '') {
        $sql .= ' AND substr(COALESCE(NULLIF(a.completed_at, t.updated_at), t.updated_at), 1, 10) >= :from';
        $params['from'] = $from;
    }
    if ($to !== '') {
        $sql .= ' AND substr(COALESCE(NULLIF(a.completed_at, t.updated_at), t.updated_at), 1, 10) <= :to';
        $params['to'] = $to;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function openCount(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT t.id)
        FROM pm_tasks t
        INNER JOIN pm_task_assignees a ON a.task_id = t.id
        WHERE a.user_id = :u
          AND t.status NOT IN ('completed', 'cancelled')
          AND a.member_status != 'completed'
    ");
    $stmt->execute(['u' => $userId]);
    return (int)$stmt->fetchColumn();
}

/* ------------------------------------------------------- my_score ------- */
if ($action === 'my_score') {
    if (isClientAccount($currentUser)) {
        empFail('Not allowed', 403);
    }
    empOut(['success' => true, 'score' => scoreTotal($pdo, (int)$currentUser['id'])]);
}

/* ----------------------------------------------------------- stats ------ */
if ($action === 'stats') {
    if (!isHeadAdmin($currentUser)) {
        empFail('Statistics are available to admins only', 403);
    }
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $people = internalPeople($pdo);
    $rows = [];
    foreach ($people as $person) {
        $uid = (int)$person['id'];
        $rows[] = [
            'id' => $uid,
            'name' => $person['name'],
            'username' => $person['username'],
            'completed' => completedCount($pdo, $uid, $from, $to),
            'open' => openCount($pdo, $uid),
            'score' => scoreTotal($pdo, $uid),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });
    empOut(['success' => true, 'rows' => $rows, 'from' => $from, 'to' => $to]);
}

/* --------------------------------------------------------- detail ------- */
if ($action === 'detail') {
    if (!isHeadAdmin($currentUser)) {
        empFail('Statistics are available to admins only', 403);
    }
    $uid = (int)($_GET['user_id'] ?? 0);
    if ($uid <= 0) {
        empFail('user_id is required', 422);
    }
    $userStmt = $pdo->prepare('SELECT id, name, username FROM pm_users WHERE id = :id AND active = 1');
    $userStmt->execute(['id' => $uid]);
    $person = $userStmt->fetch();
    if (!$person) {
        empFail('User not found', 404);
    }

    $taskStmt = $pdo->prepare("
        SELECT DISTINCT t.id, t.title, t.status, t.project_id, p.project_name,
               a.member_status, a.completed_at, t.updated_at
        FROM pm_tasks t
        INNER JOIN pm_task_assignees a ON a.task_id = t.id
        LEFT JOIN pm_projects p ON p.id = t.project_id
        WHERE a.user_id = :u AND t.status != 'cancelled'
        ORDER BY COALESCE(NULLIF(a.completed_at, ''), t.updated_at) DESC
        LIMIT 300
    ");
    $taskStmt->execute(['u' => $uid]);
    $tasks = $taskStmt->fetchAll();

    $eventStmt = $pdo->prepare("
        SELECT e.id, e.project_id, e.task_id, e.points, e.progress_before,
               e.progress_after, e.created_at, t.title, p.project_name
        FROM pm_task_score_events e
        LEFT JOIN pm_tasks t ON t.id = e.task_id
        LEFT JOIN pm_projects p ON p.id = e.project_id
        WHERE e.user_id = :u
        ORDER BY e.id DESC
        LIMIT 300
    ");
    $eventStmt->execute(['u' => $uid]);
    $events = $eventStmt->fetchAll();

    empOut([
        'success' => true,
        'person' => $person,
        'score' => scoreTotal($pdo, $uid),
        'tasks' => $tasks,
        'score_events' => $events,
    ]);
}

/* -------------------------------------------------- project_progress ---- */
if ($action === 'project_progress') {
    if (!isHeadAdmin($currentUser)) {
        empFail('Statistics are available to admins only', 403);
    }
    $today = date('Y-m-d');
    $projects = $pdo->query("
        SELECT id, project_name, start_date, end_date, progress, progress_mode, contract_value
        FROM pm_projects WHERE deleted_at = '' ORDER BY id
    ")->fetchAll();
    $rows = [];
    foreach ($projects as $project) {
        $summary = calculateProjectSummary($pdo, (int)$project['id']);
        $planned = plannedPercentFor((string)$project['start_date'], (string)$project['end_date'], $today);
        $spi = ($planned !== null && $planned > 0) ? round($summary['overall'] / $planned, 3) : null;
        $forecast = evmForecast((string)$project['start_date'], (string)$project['end_date'], $spi);
        $rows[] = [
            'id' => (int)$project['id'],
            'name' => $project['project_name'],
            'progress' => (int)$summary['overall'],
            'planned' => $planned,
            'spi' => $spi,
            'forecast_end' => $forecast['forecast_end'],
            'day_slippage' => $forecast['day_slippage'],
            'mode' => $project['progress_mode'],
        ];
    }
    empOut(['success' => true, 'today' => $today, 'rows' => $rows]);
}

empFail('Unknown action', 400);
