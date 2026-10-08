<?php
/**
 * load_projects.php
 */

require_once __DIR__ . '/database.php';

// چک Login
if (function_exists('requireLoginJson')) {
    requireLoginJson();
}

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOut(['error' => 'Method Not Allowed'], 405);
}

$currentUser = getCurrentUser();
// The legacy workspace has no publish-gated client projection yet. Never
// expose its internal project payload, metadata, or staff details to clients.
if (isClientAccount($currentUser)) {
    if (isset($_GET['id']) || isset($_GET['meta'])) {
        jsonOut(['error' => 'Client workspace is not available yet'], 403);
    }
    jsonOut(['success' => true, 'projects' => []]);
}

try {
    $pdo = getDB();

    // Archive review and restore are deliberately a Head Admin lifecycle
    // workflow. Archived projects stay invisible to ordinary project lists.
    if (isset($_GET['archived'])) {
        if ((string)$_GET['archived'] !== '1' || isset($_GET['id']) || isset($_GET['meta'])) {
            jsonOut(['error' => 'Invalid archive request'], 400);
        }
        requireAdminJson();
        $search = trim((string)($_GET['search'] ?? ''));
        if (strlen($search) > 200) {
            jsonOut(['error' => 'Search is too long'], 422);
        }
        $params = [];
        $whereSearch = '';
        if ($search !== '') {
            $whereSearch = ' AND (p.project_name LIKE :search OR p.client_name LIKE :search OR p.zone LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        $stmt = $pdo->prepare("
            SELECT p.id, p.project_name, p.client_name, p.zone, p.deleted_at,
                   p.deletion_reason, p.version, p.updated_at,
                   u.name AS deleted_by_name
            FROM pm_projects p
            LEFT JOIN pm_users u ON u.id = p.deleted_by
            WHERE p.deleted_at <> '' {$whereSearch}
            ORDER BY p.deleted_at DESC, p.id DESC
            LIMIT 500
        ");
        $stmt->execute($params);
        jsonOut(['success' => true, 'projects' => $stmt->fetchAll()]);
    }

    // Global template metadata is available only to internal users with at
    // least one authorized project (or a global project-management privilege).
    if (isset($_GET['meta'])) {
        $allowedForMeta = getAllowedProjectIds();
        if ($allowedForMeta === [] && !canDo('settings') && !canDo('add')) {
            jsonOut(['error' => 'You do not have permission to view project metadata'], 403);
        }
        jsonOut([
            'success' => true,
            'meta' => fetchMeta($pdo)
        ]);
    }

    // یک پروژه خاص
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        if (!canViewProject($id)) {
            jsonOut(['error' => 'You do not have permission to view this project'], 403);
        }

        $project = getProjectPayload($pdo, $id);

        if (!$project) {
            jsonOut(['error' => 'Project not found'], 404);
        }

        // اضافه کردن user_access
        $project['user_access'] = getUserAccessForProject($pdo, $id);

        jsonOut([
            'success' => true,
            'project' => $project,
            'meta' => fetchMeta($pdo)
        ]);
    }

    // Scope every project query to the server-side authorization result.
    $allowedProjectIds = getAllowedProjectIds();
    $whereAccess = '';
    if ($allowedProjectIds !== ['*']) {
        if ($allowedProjectIds === []) {
            jsonOut(['success' => true, 'projects' => []]);
        }
        $whereAccess = ' AND p.id IN (' . implode(',', array_map('intval', $allowedProjectIds)) . ')';
    }

    $search = trim((string)($_GET['search'] ?? ''));
    if (strlen($search) > 200) {
        jsonOut(['error' => 'Search is too long'], 422);
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $pdo->prepare("
            SELECT p.*, e.name AS lead_engineer_name
            FROM pm_projects p
            LEFT JOIN pm_engineers e ON e.id = p.lead_engineer_id
            WHERE p.deleted_at = ''
              AND (p.project_name LIKE :s1
                OR p.client_name LIKE :s2
                OR p.zone LIKE :s3)
                $whereAccess
            ORDER BY p.updated_at DESC, p.id DESC
        ");
        $stmt->execute(['s1' => $like, 's2' => $like, 's3' => $like]);
    } else {
        $stmt = $pdo->prepare("
            SELECT p.*, e.name AS lead_engineer_name
            FROM pm_projects p
            LEFT JOIN pm_engineers e ON e.id = p.lead_engineer_id
            WHERE p.deleted_at = '' $whereAccess
            ORDER BY p.updated_at DESC, p.id DESC
        ");
        $stmt->execute();
    }

    $projects = $stmt->fetchAll();

    foreach ($projects as &$project) {
        $summary = calculateProjectSummary($pdo, (int)$project['id']);
        $project['progress'] = $summary['overall'];
        $project['section_progress'] = $summary['sections'];
        $project['overall_status'] = overallStatusFromProgress((int)$summary['overall']);
        $project['user_access'] = getUserAccessForProject($pdo, (int)$project['id']);
    }
    unset($project);

    jsonOut(['success' => true, 'projects' => $projects]);

} catch (Throwable $e) {
    error_log('NawAra project load failed: ' . $e->getMessage());
    jsonOut(['error' => 'Server error'], 500);
}


/**
 * گرفتن دسترسی‌های کاربر برای یک پروژه
 */
function getUserAccessForProject($pdo, $projectId) {
    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;

    if (!$user || isClientAccount($user)) {
        return ['view'=>false, 'edit'=>false, 'delete'=>false, 'print'=>false, 'pdf'=>false, 'files'=>false];
    }

    // Always ask the canonical membership resolver. Legacy pm_project_access
    // is never used to make an authorization decision here.
    return [
        'view' => canDoOnProject((int)$projectId, 'view'),
        'edit' => canDoOnProject((int)$projectId, 'edit'),
        'delete' => canDoOnProject((int)$projectId, 'delete'),
        'print' => canDoOnProject((int)$projectId, 'print'),
        'pdf' => canDoOnProject((int)$projectId, 'pdf'),
        'files' => canDoOnProject((int)$projectId, 'files')
    ];
}