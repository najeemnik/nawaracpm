<?php
/**
 * users.php
 * Secure user, project-access and account-type management.
 */

require_once __DIR__ . '/database.php';
requireAdminJson();

header('Content-Type: application/json; charset=UTF-8');

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = [];
}

$action = $_GET['action'] ?? $input['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    requireCsrfTokenJson();
}

function usersOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeUserPermissions($permissions): array
{
    $allowed = [
        'view_all_projects', 'add', 'edit', 'delete', 'print', 'pdf', 'files',
        'priorities', 'settings', 'manage_users', 'manage_templates',
        'manage_notifications', 'view_audit_log'
    ];

    $source = is_array($permissions) ? $permissions : [];
    $normalized = [];
    foreach ($allowed as $permission) {
        $normalized[$permission] = !empty($source[$permission]);
    }

    return $normalized;
}

function membershipPermissionsFromUserPermissions(array $permissions, string $accountType): array
{
    if ($accountType === 'client') {
        return [
            'view_client_summary' => true,
            'view_published_reports' => false,
            'view_published_files' => false,
            'view_published_timeline' => false,
            'comment_on_published_items' => false,
            'approve_client_deliverables' => false,
        ];
    }

    return [
        'view_project' => true,
        'edit_project' => !empty($permissions['edit']),
        'delete_project' => !empty($permissions['delete']),
        'print_reports' => !empty($permissions['print']),
        'download_reports' => !empty($permissions['pdf']),
        'view_files' => !empty($permissions['files']),
        'manage_files' => !empty($permissions['files']),
        // Task permissions start deny-by-default and are granted only by the
        // dedicated task/member management API in the next implementation stage.
        'view_internal_tasks' => false,
        'create_tasks' => false,
        'edit_tasks' => false,
        'assign_tasks' => false,
        'update_own_assignment' => false,
        'update_any_task' => false,
        'submit_for_review' => false,
        'review_tasks' => false,
        'approve_tasks' => false,
        'request_revision' => false,
        'upload_files' => !empty($permissions['files']),
        'publish_files_to_client' => false,
        'manage_client_access' => false,
    ];
}

function fetchExistingProjectIds(PDO $pdo, array $projectIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn($id) => $id > 0)));
    if ($ids === []) {
        return [];
    }
    if (count($ids) > 500) {
        // The caller returns a validation response before reaching this guard.
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // Archived projects deliberately cannot gain new access grants. Their
    // historical membership remains intact for a future restore operation.
    $stmt = $pdo->prepare("SELECT id FROM pm_projects WHERE deleted_at = '' AND id IN ({$placeholders})");
    $stmt->execute($ids);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

try {
    $pdo = getDB();

    if ($action === 'list') {
        if ($method !== 'GET') {
            usersOut(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }

        $stmt = $pdo->query("
            SELECT id, name, username, role, account_type, permissions, active,
                   created_at, updated_at, last_login_at
            FROM pm_users
            ORDER BY active DESC, id ASC
        ");
        $users = $stmt->fetchAll();

        foreach ($users as &$user) {
            $user['permissions'] = json_decode($user['permissions'] ?? '{}', true) ?: [];
        }
        unset($user);

        usersOut(['success' => true, 'users' => $users]);
    }

    if ($action === 'save') {
        if ($method !== 'POST') {
            usersOut(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }

        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $role = ($input['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $accountType = (string)($input['account_type'] ?? ($role === 'admin' ? 'admin' : 'employee'));
        if (!in_array($accountType, ['admin', 'employee', 'client'], true)) {
            $accountType = 'employee';
        }
        if ($role === 'admin') {
            $accountType = 'admin';
        } elseif ($accountType === 'admin') {
            // An account-type label must never be usable to imply a global
            // role. Head Admin authority is role=admin only.
            $accountType = 'employee';
        }

        $permissions = normalizeUserPermissions($input['permissions'] ?? []);
        $permissionsJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
        $projectAccessProvided = array_key_exists('project_access', $input);
        $projectAccess = $input['project_access'] ?? [];

        if ($name === '' || $username === '') {
            usersOut(['success' => false, 'error' => 'Name and username are required'], 422);
        }
        if (strlen($name) > 300 || strlen($username) < 3 || strlen($username) > 64 ||
            preg_match('/[\x00-\x20\x7F]/', $username)) {
            usersOut(['success' => false, 'error' => 'Invalid name or username'], 422);
        }
        if (($id === 0 && strlen($password) < 10) || ($id > 0 && $password !== '' && strlen($password) < 10)) {
            usersOut(['success' => false, 'error' => 'Passwords must be at least 10 characters'], 422);
        }

        $duplicate = $pdo->prepare('SELECT id FROM pm_users WHERE username = ? AND id != ? LIMIT 1');
        $duplicate->execute([$username, $id]);
        if ($duplicate->fetch()) {
            usersOut(['success' => false, 'error' => 'This username already exists'], 409);
        }

        if ($id > 0) {
            $existingStmt = $pdo->prepare('SELECT id, role, active FROM pm_users WHERE id = ? LIMIT 1');
            $existingStmt->execute([$id]);
            $existingUser = $existingStmt->fetch();
            if (!$existingUser) {
                usersOut(['success' => false, 'error' => 'User not found'], 404);
            }
            if ($existingUser['role'] === 'admin' && (int)$existingUser['active'] === 1 && $role !== 'admin') {
                $activeAdminCount = (int)$pdo->query("SELECT COUNT(*) FROM pm_users WHERE role = 'admin' AND active = 1")->fetchColumn();
                if ($activeAdminCount <= 1) {
                    usersOut(['success' => false, 'error' => 'The last active admin cannot lose admin access'], 422);
                }
            }
        }

        if ($projectAccessProvided && !is_array($projectAccess)) {
            usersOut(['success' => false, 'error' => 'Invalid project access list'], 422);
        }
        $requestedProjectIds = is_array($projectAccess)
            ? array_values(array_unique(array_filter(array_map('intval', $projectAccess), static fn($projectId) => $projectId > 0)))
            : [];
        if (count($requestedProjectIds) > 500) {
            usersOut(['success' => false, 'error' => 'Too many project assignments'], 422);
        }
        $validProjectIds = fetchExistingProjectIds($pdo, $requestedProjectIds);
        if ($projectAccessProvided && count($validProjectIds) !== count($requestedProjectIds)) {
            usersOut(['success' => false, 'error' => 'One or more selected projects do not exist'], 422);
        }

        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                if ($password !== '') {
                    if (strlen($password) < 10) {
                        throw new RuntimeException('Password must be at least 10 characters');
                    }
                    $stmt = $pdo->prepare("
                        UPDATE pm_users
                        SET name = ?, username = ?, password = ?, role = ?, account_type = ?,
                            permissions = ?, auth_version = auth_version + 1,
                            password_changed_at = datetime('now','localtime'),
                            updated_at = datetime('now','localtime')
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $name, $username, password_hash($password, PASSWORD_DEFAULT), $role,
                        $accountType, $permissionsJson, $id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE pm_users
                        SET name = ?, username = ?, role = ?, account_type = ?, permissions = ?,
                            auth_version = auth_version + 1,
                            updated_at = datetime('now','localtime')
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $username, $role, $accountType, $permissionsJson, $id]);
                }
                $userId = $id;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO pm_users
                        (name, username, password, role, account_type, permissions, auth_version,
                         password_changed_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, 1, datetime('now','localtime'), datetime('now','localtime'))
                ");
                $stmt->execute([
                    $name, $username, password_hash($password, PASSWORD_DEFAULT), $role,
                    $accountType, $permissionsJson
                ]);
                $userId = (int)$pdo->lastInsertId();
            }

            $membershipRole = $accountType === 'client'
                ? 'client'
                : ($role === 'admin' ? 'project_admin' : 'employee');
            $membershipPermissionsJson = json_encode(
                membershipPermissionsFromUserPermissions($permissions, $accountType),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($membershipPermissionsJson === false) {
                throw new RuntimeException('Unable to encode project membership permissions');
            }

            // An update that does not explicitly include project_access keeps
            // its existing assignments. An explicit [] intentionally clears it.
            if ($id === 0 || $projectAccessProvided) {
                // Keep the legacy table working while the new PWA moves to
                // pm_project_members. This also removes old access rows on update.
                $pdo->prepare('DELETE FROM pm_project_access WHERE user_id = ?')->execute([$userId]);
                $pdo->prepare('DELETE FROM pm_project_members WHERE user_id = ?')->execute([$userId]);

                $insertAccess = $pdo->prepare("
                    INSERT INTO pm_project_access
                        (user_id, project_id, can_view, can_edit, can_delete, can_print, can_pdf, can_files)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insertMembership = $pdo->prepare("
                    INSERT INTO pm_project_members
                        (project_id, user_id, membership_role, permissions, active, created_by)
                    VALUES (?, ?, ?, ?, 1, ?)
                ");

                $currentAdmin = getCurrentUser();
                $createdBy = (int)($currentAdmin['id'] ?? 0) ?: null;

                foreach ($validProjectIds as $projectId) {
                    $insertAccess->execute([
                        $userId,
                        $projectId,
                        1,
                        !empty($permissions['edit']) ? 1 : 0,
                        !empty($permissions['delete']) ? 1 : 0,
                        !empty($permissions['print']) ? 1 : 0,
                        !empty($permissions['pdf']) ? 1 : 0,
                        !empty($permissions['files']) ? 1 : 0
                    ]);
                    $insertMembership->execute([
                        $projectId,
                        $userId,
                        $membershipRole,
                        $membershipPermissionsJson,
                        $createdBy
                    ]);
                }
            } elseif ($id > 0) {
                // Preserve project membership while keeping its effective
                // legacy/new-model action flags synchronized with the user.
                $pdo->prepare("
                    UPDATE pm_project_access
                    SET can_edit = ?, can_delete = ?, can_print = ?, can_pdf = ?, can_files = ?
                    WHERE user_id = ?
                ")->execute([
                    !empty($permissions['edit']) ? 1 : 0,
                    !empty($permissions['delete']) ? 1 : 0,
                    !empty($permissions['print']) ? 1 : 0,
                    !empty($permissions['pdf']) ? 1 : 0,
                    !empty($permissions['files']) ? 1 : 0,
                    $userId
                ]);
                $pdo->prepare("
                    UPDATE pm_project_members
                    SET membership_role = ?, permissions = ?, updated_at = datetime('now','localtime')
                    WHERE user_id = ?
                ")->execute([$membershipRole, $membershipPermissionsJson, $userId]);
            }

            $pdo->commit();
            recordAuditEvent(
                'user',
                $userId,
                $id > 0 ? 'updated' : 'created',
                null,
                ['role' => $role, 'account_type' => $accountType, 'project_access_updated' => $id === 0 || $projectAccessProvided, 'project_access_count' => count($validProjectIds)]
            );
            usersOut([
                'success' => true,
                'message' => $id > 0 ? 'User updated. Existing sessions were revoked.' : 'User created',
                'id' => $userId
            ]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('NawAra user save failed: ' . $e->getMessage());
            usersOut(['success' => false, 'error' => 'User could not be saved'], 500);
        }
    }

    if ($action === 'get_user_access') {
        if ($method !== 'GET') {
            usersOut(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }

        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            usersOut(['success' => false, 'error' => 'Invalid user'], 422);
        }

        $projects = $pdo->query("
            SELECT id, project_name, client_name
            FROM pm_projects
            WHERE deleted_at = ''
            ORDER BY project_name ASC
        ")->fetchAll();

        $stmt = $pdo->prepare("
            SELECT project_id, permissions, active
            FROM pm_project_members
            WHERE user_id = ? AND active = 1
        ");
        $stmt->execute([$userId]);
        $memberships = $stmt->fetchAll();

        $membershipMap = [];
        foreach ($memberships as $membership) {
            $membershipMap[(int)$membership['project_id']] = $membership;
        }

        $result = [];
        foreach ($projects as $project) {
            $membership = $membershipMap[(int)$project['id']] ?? null;
            $result[] = [
                'id' => (int)$project['id'],
                'name' => $project['project_name'],
                'client' => $project['client_name'],
                'access' => $membership ? [
                    'view' => membershipAllows($membership, 'view_project'),
                    'edit' => membershipAllows($membership, 'edit_project'),
                    'delete' => membershipAllows($membership, 'delete_project'),
                    'print' => membershipAllows($membership, 'print_reports'),
                    'pdf' => membershipAllows($membership, 'download_reports'),
                    'files' => membershipAllows($membership, 'view_files')
                ] : null
            ];
        }

        usersOut(['success' => true, 'projects' => $result]);
    }

    if ($action === 'activate') {
        if ($method !== 'POST') {
            usersOut(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }

        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            usersOut(['success' => false, 'error' => 'Invalid user'], 422);
        }

        $stmt = $pdo->prepare("
            UPDATE pm_users
            SET active = 1, auth_version = auth_version + 1, updated_at = datetime('now','localtime')
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            usersOut(['success' => false, 'error' => 'User not found'], 404);
        }
        recordAuditEvent('user', $id, 'activated');
        usersOut(['success' => true, 'message' => 'User activated']);
    }

    if ($action === 'delete') {
        if ($method !== 'POST') {
            usersOut(['success' => false, 'error' => 'Method Not Allowed'], 405);
        }

        $id = (int)($input['id'] ?? 0);
        $currentUser = getCurrentUser();
        if ($id <= 0) {
            usersOut(['success' => false, 'error' => 'Invalid user'], 422);
        }
        if ($id === (int)($currentUser['id'] ?? 0)) {
            usersOut(['success' => false, 'error' => 'You cannot deactivate yourself'], 422);
        }

        $targetStmt = $pdo->prepare('SELECT id, role, active FROM pm_users WHERE id = ? LIMIT 1');
        $targetStmt->execute([$id]);
        $target = $targetStmt->fetch();
        if (!$target) {
            usersOut(['success' => false, 'error' => 'User not found'], 404);
        }

        if ($target['role'] === 'admin' && (int)$target['active'] === 1) {
            $activeAdminCount = (int)$pdo->query("SELECT COUNT(*) FROM pm_users WHERE role = 'admin' AND active = 1")->fetchColumn();
            if ($activeAdminCount <= 1) {
                usersOut(['success' => false, 'error' => 'The last active admin cannot be deactivated'], 422);
            }
        }

        $stmt = $pdo->prepare("
            UPDATE pm_users
            SET active = 0, auth_version = auth_version + 1, updated_at = datetime('now','localtime')
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        recordAuditEvent('user', $id, 'deactivated');

        usersOut(['success' => true, 'message' => 'User deactivated and sessions revoked']);
    }

    usersOut(['success' => false, 'error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('NawAra users endpoint failed: ' . $e->getMessage());
    usersOut(['success' => false, 'error' => 'Server error'], 500);
}
