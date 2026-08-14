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

try {
    $pdo = getDB();

    // Meta
    if (isset($_GET['meta'])) {
        jsonOut([
            'success' => true,
            'meta' => fetchMeta($pdo)
        ]);
    }

    // یک پروژه خاص
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id > 0) {
        // چک permission دیدن این پروژه
        $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $isAdmin = $user && ($user['role'] ?? '') === 'admin';
        
        if (!$isAdmin && $user) {
            $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
            $viewAll = !empty($perms['view_all_projects']);
            
            if (!$viewAll) {
                $stmt = $pdo->prepare("
                    SELECT can_view FROM pm_project_access 
                    WHERE user_id = :uid AND project_id = :pid LIMIT 1
                ");
                $stmt->execute(['uid' => $user['id'], 'pid' => $id]);
                $canView = $stmt->fetchColumn();
                
                if (!$canView) {
                    jsonOut(['error' => 'You do not have permission to view this project'], 403);
                }
            }
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

    // فیلتر بر اساس دسترسی کاربر
    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    $isAdmin = $user && ($user['role'] ?? '') === 'admin';
    
    $whereAccess = '';
    
    if (!$isAdmin && $user) {
        $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
        $viewAll = !empty($perms['view_all_projects']);
        
        if (!$viewAll) {
            // فقط پروژه‌های مجاز
            $stmt = $pdo->prepare("
                SELECT project_id FROM pm_project_access 
                WHERE user_id = :uid AND can_view = 1
            ");
            $stmt->execute(['uid' => $user['id']]);
            $allowedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($allowedIds)) {
                jsonOut(['success' => true, 'projects' => []]);
            }
            
            $ids = implode(',', array_map('intval', $allowedIds));
            $whereAccess = " AND p.id IN ($ids)";
        }
    }

    $search = trim($_GET['search'] ?? '');

    if ($search !== '') {
        $like = '%' . $search . '%';
        $stmt = $pdo->prepare("
            SELECT p.*, e.name AS lead_engineer_name
            FROM pm_projects p
            LEFT JOIN pm_engineers e ON e.id = p.lead_engineer_id
            WHERE (p.project_name LIKE :s1
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
            WHERE 1=1 $whereAccess
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
    jsonOut(['error' => 'Server error: ' . $e->getMessage()], 500);
}


/**
 * گرفتن دسترسی‌های کاربر برای یک پروژه
 */
function getUserAccessForProject($pdo, $projectId) {
    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    
    if (!$user) {
        return ['view'=>false, 'edit'=>false, 'delete'=>false, 'print'=>false, 'pdf'=>false, 'files'=>false];
    }
    
    // Admin همه چیز
    if (($user['role'] ?? '') === 'admin') {
        return ['view'=>true, 'edit'=>true, 'delete'=>true, 'print'=>true, 'pdf'=>true, 'files'=>true];
    }
    
    $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
    $viewAll = !empty($perms['view_all_projects']);
    
    if ($viewAll) {
        // از general permissions استفاده کن
        return [
            'view' => true,
            'edit' => !empty($perms['edit']),
            'delete' => !empty($perms['delete']),
            'print' => !empty($perms['print']),
            'pdf' => !empty($perms['pdf']),
            'files' => !empty($perms['files'])
        ];
    }
    
    // project-specific access
    try {
        $stmt = $pdo->prepare("
            SELECT can_view, can_edit, can_delete, can_print, can_pdf, can_files
            FROM pm_project_access 
            WHERE user_id = :uid AND project_id = :pid LIMIT 1
        ");
        $stmt->execute(['uid' => $user['id'], 'pid' => $projectId]);
        $access = $stmt->fetch();
        
        if (!$access) {
            return ['view'=>false, 'edit'=>false, 'delete'=>false, 'print'=>false, 'pdf'=>false, 'files'=>false];
        }
        
        return [
            'view' => (bool)$access['can_view'],
            'edit' => (bool)$access['can_edit'],
            'delete' => (bool)$access['can_delete'],
            'print' => (bool)$access['can_print'],
            'pdf' => (bool)$access['can_pdf'],
            'files' => (bool)$access['can_files']
        ];
    } catch (Throwable $e) {
        return ['view'=>false, 'edit'=>false, 'delete'=>false, 'print'=>false, 'pdf'=>false, 'files'=>false];
    }
}