<?php
/**
 * config.php
 * Shared application, session and authorization configuration.
 */

$appEnv = strtolower(trim((string)(getenv('NAWARA_APP_ENV') ?: 'production')));
if (!in_array($appEnv, ['development', 'staging', 'production'], true)) {
    $appEnv = 'development';
}
define('APP_ENV', $appEnv);

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

define('APP_NAME', 'NawAra Studio ');
define('APP_SUBTITLE', 'Projects Progress Report');
define('APP_VERSION', '1.2.0 - Copyright © 2026 Ahmad Najeem Nik');

date_default_timezone_set('Asia/Kabul');

$configuredDataDir = trim((string)(getenv('NAWARA_DATA_DIR') ?: ''));
$dataDir = $configuredDataDir !== ''
    ? rtrim($configuredDataDir, DIRECTORY_SEPARATOR)
    : __DIR__ . '/data';

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0750, true);
}

define('APP_DATA_DIR', $dataDir);
define('DB_PATH', APP_DATA_DIR . '/database.sqlite');

/**
 * SESSION
 */
if (session_status() === PHP_SESSION_NONE) {
    $configuredSecureCookie = strtolower(trim((string)(getenv('NAWARA_SESSION_SECURE') ?: '')));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $secureCookie = in_array($configuredSecureCookie, ['1', 'true', 'yes'], true) || $isHttps;
    $sessionLifetime = max(900, (int)(getenv('NAWARA_SESSION_LIFETIME') ?: 86400));

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'httponly' => true,
        'secure' => $secureCookie,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
}

/**
 * Authentication helpers
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

function getCurrentUser(): ?array
{
    return $_SESSION['user_data'] ?? null;
}

function getCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfTokenJson(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!isValidCsrfToken($token)) {
        sendAuthJsonError('Invalid request token', 403);
    }
}

function clearCurrentSession(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        // Rotate to an anonymous session instead of sending an expired cookie.
        // This invalidates the old server-side session while allowing the same
        // response to render a fresh CSRF-protected login form.
        if (session_regenerate_id(true)) {
            $_SESSION = [];
            return;
        }

        session_destroy();
    }
}

/**
 * Refreshes the user from the database on protected requests. This makes a
 * deactivated account, a password reset, or a changed authorization version
 * effective without waiting for the browser session to expire.
 */
function refreshCurrentUserSession(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    if (!function_exists('getDB')) {
        return getCurrentUser();
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT id, name, username, role, permissions, active,
                   account_type, auth_version, created_at, updated_at
            FROM pm_users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int)$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user || (int)$user['active'] !== 1) {
            clearCurrentSession();
            return null;
        }

        $storedVersion = $_SESSION['auth_version'] ?? null;
        // Sessions created before the authorization-version mechanism are
        // deliberately invalidated on deployment rather than trusted once.
        if ($storedVersion === null || (int)$storedVersion !== (int)$user['auth_version']) {
            clearCurrentSession();
            return null;
        }

        $_SESSION['user_data'] = $user;
        $_SESSION['auth_version'] = (int)$user['auth_version'];

        return $user;
    } catch (Throwable $e) {
        // Fail closed if the account cannot be verified.
        clearCurrentSession();
        return null;
    }
}

function sendAuthJsonError(string $message, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'auth' => $status === 401 ? false : true, 'error' => $message]);
    exit;
}

function requireLoginJson(): void
{
    if (!refreshCurrentUserSession()) {
        sendAuthJsonError('Unauthorized', 401);
    }
}

function requireLoginPage(): void
{
    if (!refreshCurrentUserSession()) {
        header('Location: index.php');
        exit;
    }
}

function hasPermission(string $permission): bool
{
    return canDo($permission);
}

function requirePermissionJson(string $permission): void
{
    requireLoginJson();

    if (!canDo($permission)) {
        sendAuthJsonError('You do not have permission for this action', 403);
    }
}

function requireAdminJson(): void
{
    requireLoginJson();
    $user = getCurrentUser();

    if (($user['role'] ?? '') !== 'admin') {
        sendAuthJsonError('Admin access required', 403);
    }
}

function app_asset_version(string $file): string
{
    $path = __DIR__ . '/' . $file;
    return file_exists($path) ? (string)filemtime($path) : APP_VERSION;
}

/**
 * Best-effort security audit record. Audit failures must never undo the action
 * being recorded, but are written to the server error log for operators.
 */
function recordAuditEvent(string $entityType, ?int $entityId, string $action, ?int $projectId = null, array $details = []): void
{
    try {
        if (!function_exists('getDB')) {
            return;
        }

        $detailsJson = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($detailsJson === false || strlen($detailsJson) > 8000) {
            $detailsJson = '{"truncated":true}';
        }
        $user = getCurrentUser();
        $stmt = getDB()->prepare("
            INSERT INTO pm_audit_log
                (actor_user_id, project_id, entity_type, entity_id, action, details, ip_address, user_agent)
            VALUES
                (:actor_user_id, :project_id, :entity_type, :entity_id, :action, :details, :ip_address, :user_agent)
        ");
        $stmt->execute([
            'actor_user_id' => !empty($user['id']) ? (int)$user['id'] : null,
            'project_id' => $projectId,
            'entity_type' => substr($entityType, 0, 80),
            'entity_id' => $entityId,
            'action' => substr($action, 0, 120),
            'details' => $detailsJson,
            'ip_address' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512)
        ]);
    } catch (Throwable $e) {
        error_log('NawAra audit log failed: ' . $e->getMessage());
    }
}

/* ============================================
   Project authorization
   ============================================ */

function isClientAccount(?array $user = null): bool
{
    $user = $user ?? getCurrentUser();
    return $user !== null
        && ($user['role'] ?? '') !== 'admin'
        && ($user['account_type'] ?? 'employee') === 'client';
}

function canDo(string $permission): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    // The existing dashboard is internal-only. Client permissions are exposed
    // only by the future publish-gated client portal, not legacy endpoints.
    if (($user['account_type'] ?? 'employee') === 'client') {
        return false;
    }

    $permissions = json_decode($user['permissions'] ?? '{}', true) ?: [];
    return isset($permissions[$permission]) && $permissions[$permission] === true;
}

function canViewProject(int $projectId): bool
{
    $user = getCurrentUser();
    if (!$user || $projectId <= 0) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    if (($user['account_type'] ?? 'employee') === 'client') {
        return false;
    }

    $permissions = json_decode($user['permissions'] ?? '{}', true) ?: [];
    if (!empty($permissions['view_all_projects'])) {
        return true;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT can_view
            FROM pm_project_access
            WHERE user_id = :uid AND project_id = :pid
            LIMIT 1
        ");
        $stmt->execute([
            'uid' => (int)$user['id'],
            'pid' => $projectId
        ]);

        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Valid actions: view, edit, delete, print, pdf, files.
 */
function canDoOnProject(int $projectId, string $action): bool
{
    if ($action === 'view') {
        return canViewProject($projectId);
    }

    $allowedActions = ['edit', 'delete', 'print', 'pdf', 'files'];
    if (!in_array($action, $allowedActions, true)) {
        return false;
    }

    $user = getCurrentUser();
    if (!$user || $projectId <= 0 || !canViewProject($projectId)) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $permissions = json_decode($user['permissions'] ?? '{}', true) ?: [];
    if (!empty($permissions['view_all_projects'])) {
        return !empty($permissions[$action]);
    }

    try {
        $pdo = getDB();
        $column = 'can_' . $action;
        $stmt = $pdo->prepare("
            SELECT {$column}
            FROM pm_project_access
            WHERE user_id = :uid AND project_id = :pid
            LIMIT 1
        ");
        $stmt->execute([
            'uid' => (int)$user['id'],
            'pid' => $projectId
        ]);

        return (int)$stmt->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

function requireProjectActionJson(int $projectId, string $action): void
{
    requireLoginJson();

    if (!canDoOnProject($projectId, $action)) {
        sendAuthJsonError('You do not have permission for this project action', 403);
    }
}

function requireProjectActionPage(int $projectId, string $action): void
{
    requireLoginPage();

    if (!canDoOnProject($projectId, $action)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'You do not have permission for this project action.';
        exit;
    }
}

/**
 * Returns project IDs that the current user may view. ['*'] means all.
 */
function getAllowedProjectIds(): array
{
    $user = getCurrentUser();
    if (!$user) {
        return [];
    }

    if (($user['role'] ?? '') === 'admin') {
        return ['*'];
    }

    if (($user['account_type'] ?? 'employee') === 'client') {
        return [];
    }

    $permissions = json_decode($user['permissions'] ?? '{}', true) ?: [];
    if (!empty($permissions['view_all_projects'])) {
        return ['*'];
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            SELECT project_id
            FROM pm_project_access
            WHERE user_id = :uid AND can_view = 1
        ");
        $stmt->execute(['uid' => (int)$user['id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}
