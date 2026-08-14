<?php
/**
 * users.php
 */

require_once __DIR__ . '/database.php';

// چک Login
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'auth' => false, 'error' => 'Not logged in']);
    exit;
}

// چک Admin
$currentUser = getCurrentUser();
if (!$currentUser || ($currentUser['role'] ?? '') !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

// گرفتن action
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: [];
$action = $_GET['action'] ?? $input['action'] ?? '';

try {
    $pdo = getDB();

    // ============================
    // LIST USERS
    // ============================
    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT id, name, username, role, permissions, active 
            FROM pm_users 
            ORDER BY id ASC
        ");
        $users = $stmt->fetchAll();
        
        foreach ($users as &$u) {
            $u['permissions'] = json_decode($u['permissions'] ?? '{}', true) ?: [];
        }
        
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }

    // ============================
    // SAVE USER (with access)
    // ============================
    if ($action === 'save') {
        $id = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $role = ($input['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $perms = json_encode($input['permissions'] ?? [], JSON_UNESCAPED_UNICODE);
        $projectAccess = $input['project_access'] ?? [];

        if (!$name || !$username) {
            echo json_encode(['success' => false, 'error' => 'Name and Username are required']);
            exit;
        }

        // چک تکراری نبودن
        $stmt = $pdo->prepare("SELECT id FROM pm_users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'This username already exists']);
            exit;
        }

        $pdo->beginTransaction();

        try {
            if ($id > 0) {
                // ویرایش
                if ($password !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE pm_users 
                        SET name=?, username=?, password=?, role=?, permissions=? 
                        WHERE id=?
                    ");
                    $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, $perms, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE pm_users 
                        SET name=?, username=?, role=?, permissions=? 
                        WHERE id=?
                    ");
                    $stmt->execute([$name, $username, $role, $perms, $id]);
                }
                $userId = $id;
            } else {
                // جدید
                if (!$password) {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Password is required for new user']);
                    exit;
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO pm_users (name, username, password, role, permissions) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, $perms]);
                $userId = (int)$pdo->lastInsertId();
            }

            // حذف دسترسی‌های قدیمی
            $stmt = $pdo->prepare("DELETE FROM pm_project_access WHERE user_id = ?");
            $stmt->execute([$userId]);

            // اضافه کردن دسترسی‌های جدید
            if (is_array($projectAccess) && !empty($projectAccess)) {
                $insertAccess = $pdo->prepare("
                    INSERT INTO pm_project_access 
                    (user_id, project_id, can_view, can_edit, can_delete, can_print, can_pdf, can_files)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                // گرفتن general permissions برای اعمال به project access
                $permsArr = json_decode($perms, true) ?: [];

                foreach ($projectAccess as $projectId) {
                    $projectId = (int)$projectId;
                    if ($projectId <= 0) continue;
                    
                    $insertAccess->execute([
                        $userId,
                        $projectId,
                        1, // view (چون در لیست انتخاب شده)
                        !empty($permsArr['edit']) ? 1 : 0,
                        !empty($permsArr['delete']) ? 1 : 0,
                        !empty($permsArr['print']) ? 1 : 0,
                        !empty($permsArr['pdf']) ? 1 : 0,
                        !empty($permsArr['files']) ? 1 : 0
                    ]);
                }
            }

            $pdo->commit();
            echo json_encode([
                'success' => true, 
                'message' => $id > 0 ? 'User updated' : 'User created',
                'id' => $userId
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ============================
    // GET USER ACCESS
    // ============================
    if ($action === 'get_user_access') {
        $userId = (int)($_GET['user_id'] ?? 0);
        
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid user']);
            exit;
        }

        $projects = $pdo->query("
            SELECT id, project_name, client_name 
            FROM pm_projects 
            ORDER BY project_name ASC
        ")->fetchAll();
        
        $stmt = $pdo->prepare("SELECT * FROM pm_project_access WHERE user_id = ?");
        $stmt->execute([$userId]);
        $accesses = $stmt->fetchAll();
        
        $accessMap = [];
        foreach ($accesses as $a) {
            $accessMap[(int)$a['project_id']] = $a;
        }

        $result = [];
        foreach ($projects as $p) {
            $acc = $accessMap[(int)$p['id']] ?? null;
            $result[] = [
                'id' => $p['id'],
                'name' => $p['project_name'],
                'client' => $p['client_name'],
                'access' => $acc ? [
                    'view' => (int)$acc['can_view'],
                    'edit' => (int)$acc['can_edit'],
                    'delete' => (int)$acc['can_delete'],
                    'print' => (int)$acc['can_print'],
                    'pdf' => (int)$acc['can_pdf'],
                    'files' => (int)$acc['can_files']
                ] : null
            ];
        }
        
        echo json_encode(['success' => true, 'projects' => $result]);
        exit;
    }

    // ============================
    // DELETE USER
    // ============================
    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            echo json_encode(['success' => false, 'error' => 'You cannot delete yourself']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM pm_users WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'User deleted']);
        exit;
    }

    // Default
    echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}