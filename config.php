<?php
/**
 * config.php
 * NawAra Studio
 */

// موقتاً نمایش خطا برای debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('APP_NAME', 'NawAra Studio ');
define('APP_SUBTITLE', 'Projects Progress Report');
define('APP_VERSION', '1.1.2 - Copyright © 2026 Ahmad Najeem Nik');

date_default_timezone_set('Asia/Kabul');

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

define('DB_PATH', $dataDir . '/database.sqlite');

/**
 * SESSION
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Login Functions
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
}

function getCurrentUser(): ?array {
    return $_SESSION['user_data'] ?? null;
}

function hasPermission(string $perm): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    
    $perms = json_decode($user['permissions'] ?? '{}', true);
    return isset($perms[$perm]) && $perms[$perm] === true;
}

function requireLoginJson(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['auth' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

function requireLoginPage(): void {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

function requireAdminJson(): void {
    requireLoginJson();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
}

function app_asset_version(string $file): string {
    $path = __DIR__ . '/' . $file;
    return file_exists($path) ? (string)filemtime($path) : APP_VERSION;
}

/* ============================================
   Advanced Permissions (New)
   ============================================ */

/**
 * چک کن کاربر چه اجازه‌ای دارد
 */
function canDo(string $permission): bool
{
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Admin همه کارها را می‌تواند انجام دهد
    if (($user['role'] ?? '') === 'admin') return true;
    
    $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
    return isset($perms[$permission]) && $perms[$permission] === true;
}

/**
 * چک کن کاربر می‌تواند این پروژه را ببیند یا نه
 */
function canViewProject(int $projectId): bool
{
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Admin همه پروژه‌ها را می‌بیند
    if (($user['role'] ?? '') === 'admin') return true;
    
    $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
    
    // اگر اجازه دیدن همه پروژه‌ها را دارد
    if (!empty($perms['view_all_projects'])) return true;
    
    // در غیر این صورت چک کن دسترسی خاص دارد یا نه
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT can_view 
            FROM pm_project_access 
            WHERE user_id = :uid 
            AND project_id = :pid 
            LIMIT 1
        ");
        $stmt->execute([
            'uid' => $user['id'],
            'pid' => $projectId
        ]);
        $result = $stmt->fetchColumn();
        return $result == 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * چک کن کاربر در این پروژه چه کاری می‌تواند انجام دهد
 * action می‌تواند باشد: view, edit, delete, print, pdf, files
 */
function canDoOnProject(int $projectId, string $action): bool
{
    $user = getCurrentUser();
    if (!$user) return false;
    
    // Admin همه کارها را می‌تواند
    if (($user['role'] ?? '') === 'admin') return true;
    
    $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
    
    // اگر همه پروژه‌ها را می‌بیند، general permission را چک کن
    if (!empty($perms['view_all_projects'])) {
        return isset($perms[$action]) && $perms[$action] === true;
    }
    
    // در غیر این صورت، دسترسی خاص این پروژه را چک کن
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT * 
            FROM pm_project_access 
            WHERE user_id = :uid 
            AND project_id = :pid 
            LIMIT 1
        ");
        $stmt->execute([
            'uid' => $user['id'],
            'pid' => $projectId
        ]);
        $access = $stmt->fetch();
        
        if (!$access) return false;
        
        $column = 'can_' . $action;
        return isset($access[$column]) && $access[$column] == 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * لیست ID پروژه‌هایی که کاربر می‌تواند ببیند را برمی‌گرداند
 * اگر ['*'] برگشت یعنی همه پروژه‌ها
 */
function getAllowedProjectIds(): array
{
    $user = getCurrentUser();
    if (!$user) return [];
    
    // Admin همه را می‌بیند
    if (($user['role'] ?? '') === 'admin') return ['*'];
    
    $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
    
    // اگر همه پروژه‌ها را می‌بیند
    if (!empty($perms['view_all_projects'])) return ['*'];
    
    // در غیر این صورت لیست ID ها را بگیر
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT project_id 
            FROM pm_project_access 
            WHERE user_id = :uid 
            AND can_view = 1
        ");
        $stmt->execute(['uid' => $user['id']]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}