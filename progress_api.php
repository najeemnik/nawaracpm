<?php
/**
 * progress_api.php — project progress forensics: EVM + S-curve (stage 4).
 *
 * GET  ?action=evm&project_id=N
 *      -> earned/planned percent, SPI, EV/PV (when a contract value exists),
 *         forecast completion date, day-slippage, late-phase flag and the
 *         snapshot series. Also captures today's snapshot (idempotent) so the
 *         S-curve grows by itself.
 * POST {action:'snapshot', project_id} — explicit snapshot (CSRF).
 *
 * Security contract:
 *  - live session required (401 otherwise)
 *  - clients never reach this endpoint (contract/forecast data is internal)
 *  - money + forecast need project edit rights (head admin or edit permission)
 *  - every project id validated; all SQL is prepared
 *
 * EVM notes (docs/09 + research):
 *  - PV baseline = smoothstep S-curve between project start_date and end_date
 *    when both exist; without dates there is no plan, so planned/SPI are null
 *  - EV  = contract_value × earned_pct/100 (contract value entered manually,
 *    0 means "not set yet" and money metrics stay null)
 *  - SPI = earned/planned (equivalent to EV/PV on the same BAC scale)
 *  - SPI loses meaning near completion (~≥70%): the API returns late_phase=true
 *    and the UI must lead with day-slippage instead
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/evm_helpers.php';

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

function progressOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function progressFail(string $error, int $status = 400): void
{
    progressOut(['success' => false, 'error' => $error], $status);
}

/** Internal-only access: no clients, edit rights on the project. */
function assertEvmAccess(int $projectId): void
{
    if ($projectId <= 0) {
        progressFail('project_id is required', 422);
    }
    $user = getCurrentUser();
    if (!$user || isClientAccount($user)) {
        progressFail('Not allowed', 403);
    }
    $stmt = getDB()->prepare("SELECT id FROM pm_projects WHERE id = :id AND deleted_at = ''");
    $stmt->execute(['id' => $projectId]);
    if (!$stmt->fetch()) {
        progressFail('Project not found', 404);
    }
    if (!isHeadAdmin($user) && !canDoOnProjectPermission($projectId, 'edit')) {
        progressFail('You do not have permission to view project forecast data', 403);
    }
}

/** Upsert today's snapshot (daily, idempotent). */
function captureProgressSnapshot(int $projectId, float $planned, float $earned): void
{
    $pdo = getDB();
    $find = $pdo->prepare('
        SELECT id FROM pm_progress_snapshots
        WHERE project_id = :project_id AND snapshot_date = :snapshot_date
        LIMIT 1
    ');
    $params = [
        'project_id' => $projectId,
        'snapshot_date' => date('Y-m-d'),
        'planned_pct' => $planned,
        'earned_pct' => $earned,
    ];
    $find->execute(['project_id' => $projectId, 'snapshot_date' => $params['snapshot_date']]);
    if ($find->fetch()) {
        $pdo->prepare("
            UPDATE pm_progress_snapshots
            SET planned_pct = :planned_pct, earned_pct = :earned_pct
            WHERE project_id = :project_id AND snapshot_date = :snapshot_date
        ")->execute($params);
        return;
    }
    $pdo->prepare("
        INSERT INTO pm_progress_snapshots
            (project_id, snapshot_date, planned_pct, earned_pct)
        VALUES (:project_id, :snapshot_date, :planned_pct, :earned_pct)
    ")->execute($params);
}

if ($action === 'evm') {
    $projectId = (int)($_GET['project_id'] ?? $input['project_id'] ?? 0);
    assertEvmAccess($projectId);

    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id, project_name, start_date, end_date, progress, progress_mode,
               contract_value, created_at
        FROM pm_projects WHERE id = :id AND deleted_at = ''
    ");
    $stmt->execute(['id' => $projectId]);
    $project = $stmt->fetch();
    if (!$project) {
        progressFail('Project not found', 404);
    }

    $today = date('Y-m-d');
    $summary = calculateProjectSummary($pdo, $projectId);
    $earned = round((float)$summary['overall'], 2);
    $planned = plannedPercentFor((string)$project['start_date'], (string)$project['end_date'], $today);

    $spi = null;
    if ($planned !== null && $planned > 0) {
        $spi = round($earned / $planned, 3);
    }

    $bac = max(0.0, (float)($project['contract_value'] ?? 0));
    $hasBac = $bac > 0;
    $ev = $hasBac ? round($bac * $earned / 100.0, 2) : null;
    $pv = ($hasBac && $planned !== null) ? round($bac * $planned / 100.0, 2) : null;
    $sv = ($ev !== null && $pv !== null) ? round($ev - $pv, 2) : null;

    // Actual cost = project expenses (stage 5) when recorded, else the manual
    // snapshot cost. CPI/EAC only appear once AC exists.
    $acStmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pm_project_expenses WHERE project_id = :id');
    $acStmt->execute(['id' => $projectId]);
    $ac = (float)$acStmt->fetchColumn();
    $acSource = $ac > 0 ? 'expenses' : null;
    if ($ac <= 0) {
        $acStmt = $pdo->prepare("
            SELECT actual_cost FROM pm_progress_snapshots
            WHERE project_id = :id AND actual_cost > 0
            ORDER BY snapshot_date DESC LIMIT 1
        ");
        $acStmt->execute(['id' => $projectId]);
        $ac = (float)($acStmt->fetchColumn() ?: 0);
        $acSource = $ac > 0 ? 'snapshot' : null;
    }
    $hasAc = $ac > 0;
    $cpi = ($hasBac && $hasAc && $ev !== null) ? round($ev / $ac, 3) : null;
    $eac = ($hasBac && $cpi !== null && $cpi > 0) ? round($bac / $cpi, 2) : null;

    // Forecast finish: stretch the remaining planned duration by 1/SPI.
    $start = (string)$project['start_date'];
    $end = (string)$project['end_date'];
    $forecast = evmForecast($start, $end, $spi);
    $forecastEnd = $forecast['forecast_end'];
    $daySlippage = $forecast['day_slippage'];

    $latePhase = $earned >= 70;

    // Keep the daily S-curve growing (idempotent).
    captureProgressSnapshot($projectId, $planned ?? 0.0, $earned);

    $seriesStmt = $pdo->prepare("
        SELECT snapshot_date, planned_pct, earned_pct, actual_cost
        FROM pm_progress_snapshots
        WHERE project_id = :id
        ORDER BY snapshot_date ASC
        LIMIT 400
    ");
    $seriesStmt->execute(['id' => $projectId]);
    $series = array_map(static function (array $row): array {
        return [
            'date' => (string)$row['snapshot_date'],
            'planned' => (float)$row['planned_pct'],
            'earned' => (float)$row['earned_pct'],
        ];
    }, $seriesStmt->fetchAll());

    progressOut([
        'success' => true,
        'evm' => [
            'project_id' => (int)$project['id'],
            'progress_mode' => (string)$project['progress_mode'],
            'today' => $today,
            'start_date' => $start,
            'end_date' => $end,
            'earned_pct' => $earned,
            'planned_pct' => $planned,
            'spi' => $spi,
            'sv' => $sv,
            'cpi' => $cpi,
            'bac' => $hasBac ? $bac : null,
            'ev' => $ev,
            'pv' => $pv,
            'ac' => $hasAc ? $ac : null,
            'ac_source' => $acSource,
            'eac' => $eac,
            'forecast_end' => $forecastEnd,
            'day_slippage' => $daySlippage,
            'late_phase' => $latePhase,
            'plan_available' => $planned !== null,
        ],
        'series' => $series,
    ]);
}

if ($action === 'snapshot') {
    $projectId = (int)($input['project_id'] ?? 0);
    assertEvmAccess($projectId);
    $summary = calculateProjectSummary(getDB(), $projectId);
    $stmt = getDB()->prepare('SELECT start_date, end_date FROM pm_projects WHERE id = :id');
    $stmt->execute(['id' => $projectId]);
    $row = $stmt->fetch() ?: ['start_date' => '', 'end_date' => ''];
    $planned = plannedPercentFor((string)$row['start_date'], (string)$row['end_date'], date('Y-m-d'));
    captureProgressSnapshot($projectId, $planned ?? 0.0, (float)$summary['overall']);
    progressOut(['success' => true]);
}

progressFail('Unknown action', 400);
