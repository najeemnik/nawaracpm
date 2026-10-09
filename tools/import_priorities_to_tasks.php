<?php
/**
 * tools/import_priorities_to_tasks.php
 * ====================================
 * Stage 3 utility: copy the legacy pm_priorities rows into pm_tasks without
 * deleting or modifying the legacy table (approved plan item: "Priorities
 * becomes Tasks — migrate the data, do not drop it").
 *
 * Mapping rules:
 *   - title / priority / due_date -> task fields (unknown priority -> medium)
 *   - assignee_id (pm_engineers)  -> pm_users by exact name match on an
 *                                    active internal account; otherwise the
 *                                    task is created unassigned
 *   - is_done = 1 -> status 'completed' (completed_at kept from the legacy row)
 *   - is_done = 0 -> status 'assigned' (or 'draft' when no user matched)
 *   - review_required = 0, affects_project_progress = 0 (historical items)
 *
 * Idempotency: each imported priority is stamped with
 *   pm_task_activity(event_type='task_created', previous_value='priority:<id>')
 * and re-runs skip priorities that already carry that stamp.
 *
 * Usage:
 *   php tools/import_priorities_to_tasks.php --project=1 [--dry-run]
 *   (NAWARA_IMPORT_PROJECT_ID=1 also works)
 *
 * Safety rails: CLI/wasm only, explicit project required, legacy table is
 * never written to, everything runs in one transaction.
 */

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'wasm'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

require_once dirname(__DIR__) . '/database.php';

$toolErr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
$toolOut = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');

$projectId = 0;
$dryRun = false;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--project=')) {
        $projectId = (int)substr($arg, strlen('--project='));
    }
}
if ($projectId <= 0) {
    $projectId = (int)(getenv('NAWARA_IMPORT_PROJECT_ID') ?: 0);
}
if ($projectId <= 0) {
    fwrite($toolErr, "Usage: import_priorities_to_tasks.php --project=<id> [--dry-run]\n");
    exit(1);
}

$pdo = getDB();

$stmt = $pdo->prepare("SELECT id, project_name FROM pm_projects WHERE id = ? AND deleted_at = ''");
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    fwrite($toolErr, "Project {$projectId} does not exist or is archived.\n");
    exit(1);
}

$legacy = $pdo->query("
    SELECT id, title, assignee_id, priority, is_done, due_date, created_at, completed_at
    FROM pm_priorities
    WHERE deleted_at = ''
    ORDER BY id ASC
")->fetchAll();

if ($legacy === []) {
    echo "No active legacy priorities found. Nothing to import.\n";
    exit(0);
}

$engineerNameToUserId = [];
$userStmt = $pdo->query("
    SELECT id, name FROM pm_users
    WHERE active = 1 AND account_type != 'client'
");
foreach ($userStmt->fetchAll() as $user) {
    $engineerNameToUserId[mb_strtolower(trim($user['name']))] = (int)$user['id'];
}

$engineerStmt = $pdo->query('SELECT id, name FROM pm_engineers');
$engineerById = [];
foreach ($engineerStmt->fetchAll() as $engineer) {
    $engineerById[(int)$engineer['id']] = (string)$engineer['name'];
}

$validPriorities = ['critical', 'high', 'medium', 'low'];
$imported = 0;
$skipped = 0;
$unmatchedAssignees = [];

try {
    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    $stampStmt = $pdo->prepare("
        SELECT 1 FROM pm_task_activity
        WHERE event_type = 'task_created' AND previous_value = :stamp
        LIMIT 1
    ");
    $insertTask = $pdo->prepare("
        INSERT INTO pm_tasks (
            project_id, title, description, status, priority, assignment_mode,
            review_required, require_file_on_submit, require_comment_on_submit,
            notify_admin_on_complete, affects_project_progress, progress_weight,
            client_visible, client_approval_required, client_comments_enabled,
            client_files_downloadable, client_notify_on_publish,
            due_at, completed_at, created_by, updated_by, version,
            created_at, updated_at
        ) VALUES (
            :project_id, :title, :description, :status, :priority, 'single',
            0, 0, 0, 1, 0, 0,
            0, 0, 0, 0, 1,
            :due_at, :completed_at, :created_by, :updated_by, 1,
            :created_at, :updated_at
        )
    ");
    $insertAssignee = $pdo->prepare("
        INSERT INTO pm_task_assignees
            (task_id, user_id, assignment_role, required_to_submit, member_status,
             completed_at, created_at, updated_at)
        VALUES (:task_id, :user_id, 'responsible', 1, :member_status,
                :completed_at, datetime('now','localtime'), datetime('now','localtime'))
    ");
    $insertActivity = $pdo->prepare("
        INSERT INTO pm_task_activity
            (project_id, task_id, actor_user_id, event_type, previous_value, new_value, created_at)
        VALUES (:project_id, :task_id, :actor, 'task_created', :stamp, :new_value, datetime('now','localtime'))
    ");

    foreach ($legacy as $row) {
        $stamp = 'priority:' . (int)$row['id'];
        $stampStmt->execute(['stamp' => $stamp]);
        if ($stampStmt->fetch()) {
            $skipped++;
            continue;
        }

        $priority = in_array($row['priority'], $validPriorities, true) ? $row['priority'] : 'medium';
        $isDone = (int)$row['is_done'] === 1;

        $userId = null;
        if ($row['assignee_id'] !== null) {
            $engineerName = $engineerById[(int)$row['assignee_id']] ?? '';
            $userId = $engineerNameToUserId[mb_strtolower(trim($engineerName))] ?? null;
            if ($userId === null) {
                $unmatchedAssignees[] = $engineerName !== '' ? $engineerName : ('#' . $row['assignee_id']);
            }
        }

        $status = $isDone ? 'completed' : ($userId !== null ? 'assigned' : 'draft');
        $completedAt = $isDone
            ? (($row['completed_at'] !== '' && $row['completed_at'] !== null)
                ? $row['completed_at'] : $row['created_at'])
            : '';

        if ($dryRun) {
            echo "DRY  #{$row['id']} '{$row['title']}' -> {$status}"
                . ($userId !== null ? " (assignee user {$userId})" : ' (unassigned)') . "\n";
            $imported++;
            continue;
        }

        $insertTask->execute([
            'project_id' => $projectId,
            'title' => mb_substr(trim($row['title']), 0, 300),
            'description' => 'Imported from the legacy Priorities list.',
            'status' => $status,
            'priority' => $priority,
            'due_at' => $row['due_date'] ?? '',
            'completed_at' => $completedAt,
            'created_by' => null,
            'updated_by' => null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['created_at'],
        ]);
        $taskId = (int)$pdo->lastInsertId();

        if ($userId !== null) {
            $insertAssignee->execute([
                'task_id' => $taskId,
                'user_id' => $userId,
                'member_status' => $isDone ? 'completed' : 'assigned',
                'completed_at' => $completedAt,
            ]);
        }

        $insertActivity->execute([
            'project_id' => $projectId,
            'task_id' => $taskId,
            'actor' => null,
            'stamp' => $stamp,
            'new_value' => json_encode([
                'imported_from' => 'pm_priorities',
                'priority_id' => (int)$row['id'],
                'legacy_assignee' => $engineerById[(int)$row['assignee_id']] ?? null,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $imported++;
    }

    if (!$dryRun) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite($toolErr, 'Import failed and was rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

echo ($dryRun ? '[dry-run] ' : '') . "Imported {$imported} task(s) into project #{$projectId} "
    . "({$project['project_name']}); skipped {$skipped} already imported.\n";
if ($unmatchedAssignees !== []) {
    echo 'Unmatched legacy assignees (task created unassigned): '
        . implode(', ', array_unique($unmatchedAssignees)) . "\n";
}
echo "Legacy pm_priorities rows were NOT modified.\n";
