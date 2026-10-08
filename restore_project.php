<?php
/**
 * Restores a previously archived project without recreating its records,
 * membership, files, or audit history.
 */

require_once __DIR__ . '/database.php';
requireAdminJson();

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
if ($id <= 0 || $expectedVersion < 1) {
    jsonOut(['error' => 'A valid archived project and version are required'], 422);
}

try {
    $pdo = getDB();
    $actor = getCurrentUser();
    $pdo->beginTransaction();

    try {
        $findStmt = $pdo->prepare("
            SELECT id, project_name, version
            FROM pm_projects
            WHERE id = :id AND deleted_at <> ''
            LIMIT 1
        ");
        $findStmt->execute(['id' => $id]);
        $project = $findStmt->fetch();
        if (!$project) {
            throw new RuntimeException('Archived project not found');
        }
        if ((int)$project['version'] !== $expectedVersion) {
            throw new RuntimeException('Project version conflict');
        }

        $restoreStmt = $pdo->prepare("
            UPDATE pm_projects
            SET deleted_at = '',
                deleted_by = NULL,
                deletion_reason = '',
                updated_by = :updated_by,
                updated_at = datetime('now','localtime'),
                version = version + 1
            WHERE id = :id AND version = :expected_version AND deleted_at <> ''
        ");
        $restoreStmt->execute([
            'updated_by' => (int)($actor['id'] ?? 0) ?: null,
            'id' => $id,
            'expected_version' => $expectedVersion
        ]);
        if ($restoreStmt->rowCount() !== 1) {
            throw new RuntimeException('Project version conflict');
        }

        recordAuditEvent('project', $id, 'restored', $id, [
            'project_name' => (string)$project['project_name'],
            'previous_version' => $expectedVersion,
            'version' => $expectedVersion + 1
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
        'message' => 'Project restored successfully',
        'id' => $id,
        'version' => $expectedVersion + 1
    ]);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Archived project not found') {
        jsonOut(['error' => 'Archived project not found'], 404);
    }
    if ($e->getMessage() === 'Project version conflict') {
        jsonOut(['error' => 'Project changed while it was archived. Refresh and try again.'], 409);
    }
    error_log('NawAra project restore failed: ' . $e->getMessage());
    jsonOut(['error' => 'Project could not be restored'], 500);
} catch (Throwable $e) {
    error_log('NawAra project restore failed: ' . $e->getMessage());
    jsonOut(['error' => 'Project could not be restored'], 500);
}
