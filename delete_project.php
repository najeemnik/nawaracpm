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

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonOut(['error' => 'Invalid JSON'], 400);
}

$id = (int)($input['id'] ?? 0);

if ($id <= 0) {
    jsonOut(['error' => 'Invalid project ID'], 400);
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT id FROM pm_projects WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);

    if (!$stmt->fetch()) {
        jsonOut(['error' => 'Project not found'], 404);
    }

    $stmt = $pdo->prepare("DELETE FROM pm_projects WHERE id = :id");
    $stmt->execute(['id' => $id]);

    jsonOut([
        'success' => true,
        'message' => 'Project deleted successfully'
    ]);

} catch (Throwable $e) {
    jsonOut(['error' => 'Server error: ' . $e->getMessage()], 500);
}