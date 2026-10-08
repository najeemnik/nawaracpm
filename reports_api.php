<?php
/**
 * reports_api.php — advanced reports over everything (stage 5, admin only).
 *
 * GET action=generate[&from=YYYY-MM-DD][&to=YYYY-MM-DD][&project_id=N]
 *
 * Returns: meta, summary, progress (incl. snapshots series), tasks
 * (by status / by employee / list), finance (payments/expenses/withdrawals
 * with totals + partners share), employees (score ranking) and an EVM block
 * per project. The client renders + prints it (reports modal).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/evm_helpers.php';

requireAdminJson();

header('Content-Type: application/json; charset=UTF-8');

$action = (string)($_GET['action'] ?? 'generate');

function reportsOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function reportsFail(string $error, int $status = 400): void
{
    reportsOut(['success' => false, 'error' => $error], $status);
}

function reportDate(string $value): string
{
    if ($value === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($d === false || $d->format('Y-m-d') !== $value) {
        reportsFail('Dates must be YYYY-MM-DD', 422);
    }
    return $value;
}

if ($action !== 'generate') {
    reportsFail('Unknown action', 400);
}

$from = reportDate(trim((string)($_GET['from'] ?? '')));
$to = reportDate(trim((string)($_GET['to'] ?? '')));
if ($from !== '' && $to !== '' && $to < $from) {
    reportsFail('to must be after from', 422);
}
$projectId = (int)($_GET['project_id'] ?? 0);
$pdo = getDB();

$projectWhere = '';
$params = [];
if ($projectId > 0) {
    $stmt = $pdo->prepare("SELECT id FROM pm_projects WHERE id = :id AND deleted_at = ''");
    $stmt->execute(['id' => $projectId]);
    if (!$stmt->fetch()) {
        reportsFail('Project not found', 404);
    }
    $projectWhere = ' AND project_id = :pid';
    $params['pid'] = $projectId;
}

/* ---- projects + progress + EVM ---- */
$projects = $pdo->query("
    SELECT id, project_name, client_name, start_date, end_date, progress, progress_mode, contract_value, deleted_at
    FROM pm_projects WHERE deleted_at = '' ORDER BY id
")->fetchAll();
$progressRows = [];
$evmRows = [];
$progressSum = 0;
$today = date('Y-m-d');
foreach ($projects as $project) {
    if ($projectId > 0 && (int)$project['id'] !== $projectId) {
        continue;
    }
    $summary = calculateProjectSummary($pdo, (int)$project['id']);
    $planned = plannedPercentFor((string)$project['start_date'], (string)$project['end_date'], $today);
    $spi = ($planned !== null && $planned > 0) ? round($summary['overall'] / $planned, 3) : null;
    $forecast = evmForecast((string)$project['start_date'], (string)$project['end_date'], $spi);
    $progressSum += $summary['overall'];
    $progressRows[] = [
        'id' => (int)$project['id'],
        'name' => $project['project_name'],
        'client' => $project['client_name'],
        'progress' => (int)$summary['overall'],
        'mode' => $project['progress_mode'],
        'start' => $project['start_date'],
        'end' => $project['end_date'],
    ];
    $evmRows[] = [
        'id' => (int)$project['id'],
        'name' => $project['project_name'],
        'progress' => (int)$summary['overall'],
        'planned' => $planned,
        'spi' => $spi,
        'forecast_end' => $forecast['forecast_end'],
        'day_slippage' => $forecast['day_slippage'],
        'bac' => (float)$project['contract_value'] > 0 ? (float)$project['contract_value'] : null,
    ];
}
$avgProgress = count($progressRows) > 0 ? (int)round($progressSum / count($progressRows)) : 0;

/* ---- snapshots series (progress over time) ---- */
$snapSql = "
    SELECT s.project_id, p.project_name, s.snapshot_date, s.planned_pct, s.earned_pct
    FROM pm_progress_snapshots s
    INNER JOIN pm_projects p ON p.id = s.project_id AND p.deleted_at = ''
    WHERE 1 = 1 {$projectWhere}
";
if ($from !== '') {
    $snapSql .= ' AND s.snapshot_date >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $snapSql .= ' AND s.snapshot_date <= :to';
    $params['to'] = $to;
}
$snapSql .= ' ORDER BY s.snapshot_date ASC LIMIT 1000';
$snapStmt = $pdo->prepare($snapSql);
$snapStmt->execute($params);
$snapshots = $snapStmt->fetchAll();

/* ---- tasks ---- */
$taskParams = [];
$taskWhere = "WHERE t.status != 'cancelled'{$projectWhere}";
if (isset($params['pid'])) {
    $taskParams['pid'] = $params['pid'];
}
$statusStmt = $pdo->prepare("
    SELECT t.status, COUNT(*) AS n
    FROM pm_tasks t {$taskWhere}
    GROUP BY t.status
");
$statusStmt->execute($taskParams);
$byStatus = [];
foreach ($statusStmt->fetchAll() as $row) {
    $byStatus[$row['status']] = (int)$row['n'];
}

$rangeClause = '';
if ($from !== '') {
    $rangeClause .= ' AND substr(COALESCE(NULLIF(a.completed_at, t.updated_at), t.updated_at), 1, 10) >= :from';
}
if ($to !== '') {
    $rangeClause .= ' AND substr(COALESCE(NULLIF(a.completed_at, t.updated_at), t.updated_at), 1, 10) <= :to';
}
$byEmpParams = $taskParams;
if (isset($params['from'])) {
    $byEmpParams['from'] = $params['from'];
}
if (isset($params['to'])) {
    $byEmpParams['to'] = $params['to'];
}
$byEmpStmt = $pdo->prepare("
    SELECT u.id, u.name, COUNT(DISTINCT t.id) AS n
    FROM pm_tasks t
    INNER JOIN pm_task_assignees a ON a.task_id = t.id
    INNER JOIN pm_users u ON u.id = a.user_id
    {$taskWhere}
      AND a.member_status = 'completed'
      AND t.status = 'completed'
      {$rangeClause}
    GROUP BY u.id, u.name
    ORDER BY n DESC
");
$byEmpStmt->execute($byEmpParams);
$doneByEmployee = array_map(static fn(array $r): array => [
    'user_id' => (int)$r['id'], 'name' => $r['name'], 'completed' => (int)$r['n'],
], $byEmpStmt->fetchAll());

$listParams = $taskParams;
$listSql = "
    SELECT t.id, t.title, t.status, t.priority, t.due_at, p.project_name,
           (SELECT GROUP_CONCAT(u.name, ', ') FROM pm_task_assignees a
            INNER JOIN pm_users u ON u.id = a.user_id WHERE a.task_id = t.id) AS assignees
    FROM pm_tasks t
    LEFT JOIN pm_projects p ON p.id = t.project_id
    {$taskWhere}
";
if ($from !== '') {
    $listSql .= ' AND substr(COALESCE(NULLIF(t.updated_at, t.created_at), t.created_at), 1, 10) >= :lfrom';
    $listParams['lfrom'] = $from;
}
if ($to !== '') {
    $listSql .= ' AND substr(COALESCE(NULLIF(t.updated_at, t.created_at), t.created_at), 1, 10) <= :lto';
    $listParams['lto'] = $to;
}
$listSql .= ' ORDER BY t.updated_at DESC LIMIT 300';
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($listParams);
$taskList = $listStmt->fetchAll();

/* ---- finance ---- */
$finParams = [];
$finWhere = '';
if ($projectId > 0) {
    $finWhere = ' WHERE project_id = :pid';
    $finParams['pid'] = $projectId;
}
$paySql = "SELECT * FROM pm_project_payments{$finWhere}";
$expSql = "SELECT * FROM pm_project_expenses{$finWhere}";
if ($from !== '') {
    $finWhere2 = ($finWhere === '' ? ' WHERE' : ' AND') . ' paid_at >= :from';
    $paySql .= $finWhere2;
    $finParams['from'] = $from;
}
if ($to !== '') {
    $finWhere2 = (strpos($paySql, 'WHERE') === false ? ' WHERE' : ' AND') . ' paid_at <= :to';
    $paySql .= $finWhere2;
    $finParams['to'] = $to;
}
$payStmt = $pdo->prepare($paySql . ' ORDER BY paid_at DESC LIMIT 500');
$payStmt->execute($finParams);
$payments = $payStmt->fetchAll();

$expParams = [];
if ($projectId > 0) {
    $expParams['pid'] = $projectId;
}
$expSql = "SELECT * FROM pm_project_expenses";
$expWhereBuilt = false;
if ($projectId > 0) {
    $expSql .= ' WHERE project_id = :pid';
    $expWhereBuilt = true;
}
if ($from !== '') {
    $expSql .= ($expWhereBuilt ? ' AND' : ' WHERE') . ' spent_at >= :expfrom';
    $expParams['expfrom'] = $from;
}
if ($to !== '') {
    $expSql .= (strpos($expSql, 'WHERE') === false ? ' WHERE' : ' AND') . ' spent_at <= :to';
    $expParams['to'] = $to;
}
$expStmt = $pdo->prepare($expSql . ' ORDER BY spent_at DESC LIMIT 500');
$expStmt->execute($expParams);
$expenses = $expStmt->fetchAll();

$wdSql = 'SELECT * FROM pm_withdrawals';
$wdParams = [];
if ($from !== '') {
    $wdSql .= ' WHERE taken_at >= :from';
    $wdParams['from'] = $from;
}
if ($to !== '') {
    $wdSql .= (strpos($wdSql, 'WHERE') === false ? ' WHERE' : ' AND') . ' taken_at <= :to';
    $wdParams['to'] = $to;
}
$wdStmt = $pdo->prepare($wdSql . ' ORDER BY taken_at DESC LIMIT 500');
$wdStmt->execute($wdParams);
$withdrawals = $wdStmt->fetchAll();

$incomeTotal = 0.0;
foreach ($payments as $row) {
    $incomeTotal += (float)$row['amount'];
}
$expensesTotal = 0.0;
foreach ($expenses as $row) {
    $expensesTotal += (float)$row['amount'];
}
$withdrawalsTotal = 0.0;
foreach ($withdrawals as $row) {
    $withdrawalsTotal += (float)$row['amount'];
}
$partners = (int)$pdo->query("SELECT COUNT(*) FROM pm_users WHERE role = 'admin' AND active = 1")->fetchColumn();
$net = round($incomeTotal - $expensesTotal, 2);
$remaining = round($net - $withdrawalsTotal, 2);

/* ---- employee score ranking ---- */
$rankParams = [];
$rankSql = "
    SELECT u.id, u.name, COALESCE(SUM(e.points), 0) AS score, COUNT(e.id) AS awards
    FROM pm_users u
    LEFT JOIN pm_task_score_events e ON e.user_id = u.id
    WHERE u.active = 1 AND u.account_type != 'client' AND u.role != 'admin'
";
if ($from !== '') {
    $rankSql .= ' AND (e.id IS NULL OR substr(e.created_at, 1, 10) >= :from)';
    $rankParams['from'] = $from;
}
if ($to !== '') {
    $rankSql .= ' AND (e.id IS NULL OR substr(e.created_at, 1, 10) <= :to)';
    $rankParams['to'] = $to;
}
$rankSql .= ' GROUP BY u.id, u.name ORDER BY score DESC, u.name COLLATE NOCASE';
$rankStmt = $pdo->prepare($rankSql);
$rankStmt->execute($rankParams);
$ranking = array_map(static fn(array $r): array => [
    'user_id' => (int)$r['id'],
    'name' => $r['name'],
    'score' => round((float)$r['score'], 2),
    'awards' => (int)$r['awards'],
], $rankStmt->fetchAll());

reportsOut([
    'success' => true,
    'meta' => [
        'from' => $from,
        'to' => $to,
        'project_id' => $projectId > 0 ? $projectId : null,
        'generated_at' => date('Y-m-d H:i'),
        'today' => $today,
    ],
    'summary' => [
        'projects' => count($progressRows),
        'avg_progress' => $avgProgress,
        'tasks_total' => array_sum($byStatus),
        'tasks_done' => $byStatus['completed'] ?? 0,
        'tasks_open' => array_sum($byStatus) - ($byStatus['completed'] ?? 0),
        'income' => round($incomeTotal, 2),
        'expenses' => round($expensesTotal, 2),
        'withdrawals' => round($withdrawalsTotal, 2),
        'net' => $net,
        'remaining' => $remaining,
        'partners' => $partners,
        'share_per_partner' => $partners > 0 ? round($remaining / $partners, 2) : null,
    ],
    'progress' => $progressRows,
    'evm' => $evmRows,
    'snapshots' => $snapshots,
    'tasks' => [
        'by_status' => $byStatus,
        'done_by_employee' => $doneByEmployee,
        'list' => $taskList,
    ],
    'finance' => [
        'payments' => $payments,
        'expenses' => $expenses,
        'withdrawals' => $withdrawals,
    ],
    'employees' => $ranking,
]);
