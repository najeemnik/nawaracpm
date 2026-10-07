<?php
/**
 * delete_project.php
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

if ($id <= 0) {
    jsonOut(['error' => 'Invalid project ID'], 400);
}

requireProjectActionJson($id, 'delete');

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id FROM pm_projects WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);

    if (!$stmt->fetch()) {
        jsonOut(['error' => 'Project not found'], 404);
    }

    $pdo->beginTransaction();
    try {
        // pm_project_access is a legacy table without foreign keys. Explicitly
        // remove its rows so a deleted project cannot leave stale permissions.
        $accessStmt = $pdo->prepare("DELETE FROM pm_project_access WHERE project_id = :id");
        $accessStmt->execute(['id' => $id]);

        $stmt = $pdo->prepare("DELETE FROM pm_projects WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    recordAuditEvent('project', $id, 'deleted');
    jsonOut([
        'success' => true,
        'message' => 'Project deleted successfully'
    ]);

} catch (Throwable $e) {
    error_log('NawAra project deletion failed: ' . $e->getMessage());
    jsonOut(['error' => 'Server error'], 500);
}