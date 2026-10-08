<?php
/**
 * delete_project.php
 *
 * Legacy route name retained for compatibility. Projects are archived (soft
 * deleted) so construction records, files and audit history remain recoverable.
 */

require_once __DIR__ . '/database.php';
requireLoginJson();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Method Not Allowed'], 405);
}
requireCsrfTokenJson();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonOut(['error' => 'Invalid JSON'], 400);
}

$id = (int)($input['id'] ?? 0);
$expectedVersion = (int)($input['version'] ?? 0);
$reason = trim((string)($input['reason'] ?? ''));
if ($id <= 0 || $expectedVersion < 1) {
    jsonOut(['error' => 'A valid project ID and version are required'], 422);
}
if (strlen($reason) > 500 || str_contains($reason, "\0")) {
    jsonOut(['error' => 'Archive reason is invalid or too long'], 422);
}

requireProjectActionJson($id, 'delete');

try {
    $pdo = getDB();
    $user = getCurrentUser();

    $pdo->beginTransaction();
    try {
        $projectStmt = $pdo->prepare("
            SELECT id, project_name, version
            FROM pm_projects
            WHERE id = :id AND deleted_at = ''
            LIMIT 1
        ");
        $projectStmt->execute(['id' => $id]);
        $project = $projectStmt->fetch();
        if (!$project) {
            throw new RuntimeException('Project not found');
        }
        if ((int)$project['version'] !== $expectedVersion) {
            throw new RuntimeException('Project version conflict');
        }

        $archiveStmt = $pdo->prepare("
            UPDATE pm_projects
            SET deleted_at = datetime('now','localtime'),
                deleted_by = :deleted_by,
                deletion_reason = :reason,
                updated_by = :updated_by,
                updated_at = datetime('now','localtime'),
                version = version + 1
            WHERE id = :id AND version = :expected_version AND deleted_at = ''
        ");
        $archiveStmt->execute([
            'deleted_by' => (int)($user['id'] ?? 0) ?: null,
            'reason' => $reason,
            'updated_by' => (int)($user['id'] ?? 0) ?: null,
            'id' => $id,
            'expected_version' => $expectedVersion
        ]);
        if ($archiveStmt->rowCount() !== 1) {
            throw new RuntimeException('Project archive conflict');
        }

        recordAuditEvent('project', $id, 'archived', $id, [
            'project_name' => (string)$project['project_name'],
            'reason_provided' => $reason !== ''
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    jsonOut([
        'success' => true,
        'message' => 'Project archived successfully. Files and history were retained.',
        'id' => $id,
        'version' => $expectedVersion + 1
    ]);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Project not found') {
        jsonOut(['error' => 'Project not found'], 404);
    }
    if ($e->getMessage() === 'Project archive conflict') {
        jsonOut(['error' => 'Project changed while it was being archived. Refresh and try again.'], 409);
    }
    error_log('NawAra project archive failed: ' . $e->getMessage());
    jsonOut(['error' => 'Project could not be archived'], 500);
} catch (Throwable $e) {
    error_log('NawAra project archive failed: ' . $e->getMessage());
    jsonOut(['error' => 'Project could not be archived'], 500);
}
