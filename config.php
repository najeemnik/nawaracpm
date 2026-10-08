<?php
/**
 * config.php
 * Shared application, session, storage and authorization configuration.
 */

$appEnv = strtolower(trim((string)(getenv('NAWARA_APP_ENV') ?: 'production')));
if (!in_array($appEnv, ['development', 'staging', 'production'], true)) {
    $appEnv = 'development';
}
define('APP_ENV', $appEnv);
define('APP_ROOT', __DIR__);

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
ini_set('expose_php', '0');

define('APP_NAME', 'NawAra Studio ');
define('APP_SUBTITLE', 'Projects Progress Report');
define('APP_VERSION', '1.2.0 - Copyright © 2026 Ahmad Najeem Nik');

date_default_timezone_set('Asia/Kabul');

/**
 * Production storage is intentionally fail-closed. SQLite, WAL/SHM files and
 * user uploads must never fall back into the directory served by the web server.
 */
function nawaraConfigurationError(string $message): void
{
    error_log('NawAra configuration error: ' . $message);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo 'Server configuration error.';
    exit;
}

function nawaraPathIsAbsolute(string $path): bool
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    return str_starts_with($path, '/');
}

function nawaraPathIsInside(string $path, string $root): bool
{
    $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

    return $normalizedPath === $normalizedRoot
        || str_starts_with($normalizedPath . '/', $normalizedRoot . '/');
}

function nawaraResolvePrivateStorageDirectory(string $environmentVariable, string $developmentFallback, string $label): string
{
    $configured = trim((string)(getenv($environmentVariable) ?: ''));
    if ($configured === '') {
        if (APP_ENV === 'production') {
            nawaraConfigurationError($environmentVariable . ' is required in production');
        }
        $candidate = $developmentFallback;
    } else {
        if (!nawaraPathIsAbsolute($configured)) {
            nawaraConfigurationError($environmentVariable . ' must be an absolute path');
        }
        $candidate = rtrim($configured, DIRECTORY_SEPARATOR);
    }

    if (!is_dir($candidate) && !@mkdir($candidate, 0750, true) && !is_dir($candidate)) {
        nawaraConfigurationError('Unable to create ' . $label . ' directory');
    }

    $resolved = realpath($candidate);
    if ($resolved === false || !is_dir($resolved)) {
        nawaraConfigurationError('Unable to resolve ' . $label . ' directory');
    }

    if (APP_ENV === 'production' && nawaraPathIsInside($resolved, APP_ROOT)) {
        nawaraConfigurationError($label . ' directory must be outside the application document root');
    }

    if (!is_writable($resolved)) {
        nawaraConfigurationError($label . ' directory is not writable by PHP');
    }

    @chmod($resolved, 0750);
    return $resolved;
}

$dataDir = nawaraResolvePrivateStorageDirectory(
    'NAWARA_DATA_DIR',
    APP_ROOT . '/data',
    'private data'
);
$uploadsDir = nawaraResolvePrivateStorageDirectory(
    'NAWARA_UPLOADS_DIR',
    APP_ROOT . '/uploads',
    'private uploads'
);

define('APP_DATA_DIR', $dataDir);
define('APP_UPLOADS_DIR', $uploadsDir);
define('DB_PATH', APP_DATA_DIR . '/database.sqlite');

/**
 * Private error log.
 *
 * PHP must never print errors to the browser in production, but silently
 * discarding them would also hide attacks and failures. Errors are therefore
 * written to a log file inside the private data directory, which is outside
 * the web root and excluded from version control.
 */
$nawaraLogDir = rtrim(trim((string)(getenv('NAWARA_LOG_DIR') ?: '')), '/');
if ($nawaraLogDir === '') {
    $nawaraLogDir = APP_DATA_DIR . '/logs';
}
if (!is_dir($nawaraLogDir) && !@mkdir($nawaraLogDir, 0750, true) && !is_dir($nawaraLogDir)) {
    nawaraConfigurationError('Unable to create the private log directory');
}
$nawaraResolvedLogDir = realpath($nawaraLogDir);
if ($nawaraResolvedLogDir === false || !is_dir($nawaraResolvedLogDir) || !is_writable($nawaraResolvedLogDir)) {
    nawaraConfigurationError('The private log directory is not writable by PHP');
}
define('APP_LOG_DIR', $nawaraResolvedLogDir);
ini_set('error_log', APP_LOG_DIR . '/php-error.log');

/**
 * Cross-subdomain session cookie.
 *
 * Nawara Studio — CPM and Nawara Tasks are installed as two separate PWAs on
 * two subdomains. A single login must work for both, so the session cookie is
 * optionally scoped to the shared parent domain, for example ".nawara.af".
 *
 * When the variable is empty the cookie stays host-only, which is the safer
 * default for single-domain installations.
 */
$sessionCookieDomain = strtolower(trim((string)(getenv('NAWARA_SESSION_COOKIE_DOMAIN') ?: '')));
if ($sessionCookieDomain !== ''
    && !preg_match('/^\.[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $sessionCookieDomain)
) {
    nawaraConfigurationError(
        'NAWARA_SESSION_COOKIE_DOMAIN must be a dot-prefixed parent domain, for example .example.com'
    );
}

/**
 * SESSION
 */
$configuredSecureCookie = strtolower(trim((string)(getenv('NAWARA_SESSION_SECURE') ?: '')));
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$secureCookie = in_array($configuredSecureCookie, ['1', 'true', 'yes'], true) || $isHttps;
if (APP_ENV === 'production' && !$secureCookie) {
    nawaraConfigurationError('Production requires HTTPS and a Secure session cookie');
}

define('NAWARA_SESSION_IDLE_TIMEOUT', max(300, (int)(getenv('NAWARA_SESSION_IDLE_TIMEOUT') ?: 1800)));
define('NAWARA_SESSION_ABSOLUTE_TIMEOUT', max(
    NAWARA_SESSION_IDLE_TIMEOUT,
    (int)(getenv('NAWARA_SESSION_ABSOLUTE_TIMEOUT') ?: 28800)
));

if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = max(900, (int)(getenv('NAWARA_SESSION_LIFETIME') ?: 86400));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => $sessionCookieDomain,
        'httponly' => true,
        'secure' => $secureCookie,
        'samesite' => 'Lax'
    ]);
    session_start();
}

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: no-store, private');

    if (APP_ENV === 'production') {
        // Inline styles remain temporarily permitted for the legacy layout, but
        // scripts and event handlers are deliberately restricted to local files.
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; manifest-src 'self'; worker-src 'self'");
        header('X-Frame-Options: DENY');
        if ($secureCookie) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
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

    $now = time();
    $issuedAt = (int)($_SESSION['auth_issued_at'] ?? 0);
    $lastActivityAt = (int)($_SESSION['auth_last_activity_at'] ?? 0);
    if ($issuedAt <= 0 || $lastActivityAt <= 0
        || ($now - $issuedAt) > NAWARA_SESSION_ABSOLUTE_TIMEOUT
        || ($now - $lastActivityAt) > NAWARA_SESSION_IDLE_TIMEOUT) {
        clearCurrentSession();
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
        $_SESSION['auth_last_activity_at'] = $now;

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
    $path = APP_ROOT . '/' . $file;
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

/** Global role=admin is the documented Head Admin role. A project_admin is a
 * project membership role and never becomes a global administrator. */
function isHeadAdmin(?array $user = null): bool
{
    $user = $user ?? getCurrentUser();
    return $user !== null && ($user['role'] ?? '') === 'admin';
}

function permissionMap($permissions): array
{
    if (is_array($permissions)) {
        return $permissions;
    }

    $decoded = json_decode((string)$permissions, true);
    return is_array($decoded) ? $decoded : [];
}

function canDo(string $permission): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    if (isHeadAdmin($user)) {
        return true;
    }

    // The existing dashboard is internal-only. Client permissions are exposed
    // only by the publish-gated client portal, never legacy endpoints.
    if (isClientAccount($user)) {
        return false;
    }

    $permissions = permissionMap($user['permissions'] ?? '{}');
    return !empty($permissions[$permission]);
}

function getProjectMembership(int $projectId, ?int $userId = null): ?array
{
    $user = getCurrentUser();
    $userId = $userId ?? (int)($user['id'] ?? 0);
    if ($projectId <= 0 || $userId <= 0) {
        return null;
    }

    try {
        $stmt = getDB()->prepare("
            SELECT m.project_id, m.user_id, m.membership_role, m.permissions, m.active
            FROM pm_project_members m
            INNER JOIN pm_projects p ON p.id = m.project_id
            WHERE m.project_id = :project_id
              AND m.user_id = :user_id
              AND m.active = 1
              AND p.deleted_at = ''
            LIMIT 1
        ");
        $stmt->execute(['project_id' => $projectId, 'user_id' => $userId]);
        $membership = $stmt->fetch();
        return $membership ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function projectIsActive(int $projectId): bool
{
    if ($projectId <= 0) {
        return false;
    }

    try {
        $stmt = getDB()->prepare("SELECT 1 FROM pm_projects WHERE id = :id AND deleted_at = '' LIMIT 1");
        $stmt->execute(['id' => $projectId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function membershipAllows(array $membership, string $permission): bool
{
    $permissions = permissionMap($membership['permissions'] ?? '{}');
    if (!empty($permissions[$permission])) {
        return true;
    }

    // Compatibility aliases are read only. All new project APIs must use the
    // canonical permission names in the first branch above.
    $aliases = [
        'view_project' => ['view'],
        'edit_project' => ['edit', 'edit_tasks', 'update_any_task'],
        'delete_project' => ['delete'],
        'print_reports' => ['print', 'view_reports'],
        'download_reports' => ['pdf', 'view_reports'],
        'view_files' => ['files'],
        'manage_files' => ['files'],
    ];

    foreach ($aliases[$permission] ?? [] as $alias) {
        if (!empty($permissions[$alias])) {
            return true;
        }
    }

    return false;
}

/**
 * Canonical project authorization for all new APIs. pm_project_members is the
 * source of truth; pm_project_access is retained only as a legacy UI adapter.
 */
function canDoOnProjectPermission(int $projectId, string $permission): bool
{
    $user = getCurrentUser();
    if (!$user || $projectId <= 0 || isClientAccount($user) || !projectIsActive($projectId)) {
        return false;
    }

    if (isHeadAdmin($user)) {
        return true;
    }

    $globalPermissions = permissionMap($user['permissions'] ?? '{}');
    if (!empty($globalPermissions['view_all_projects'])) {
        $globalAliases = [
            'view_project' => 'view_all_projects',
            'edit_project' => 'edit',
            'delete_project' => 'delete',
            'print_reports' => 'print',
            'download_reports' => 'pdf',
            'view_files' => 'files',
            'manage_files' => 'files',
        ];
        $globalPermission = $globalAliases[$permission] ?? $permission;
        if (!empty($globalPermissions[$globalPermission])) {
            return true;
        }
    }

    $membership = getProjectMembership($projectId, (int)$user['id']);
    return $membership !== null && membershipAllows($membership, $permission);
}

function canViewProject(int $projectId): bool
{
    return canDoOnProjectPermission($projectId, 'view_project');
}

/** Valid actions retained for legacy endpoints. */
function canDoOnProject(int $projectId, string $action): bool
{
    $map = [
        'view' => 'view_project',
        'edit' => 'edit_project',
        'delete' => 'delete_project',
        'print' => 'print_reports',
        'pdf' => 'download_reports',
        'files' => 'view_files',
    ];

    return isset($map[$action]) && canDoOnProjectPermission($projectId, $map[$action]);
}

function requireProjectPermissionJson(int $projectId, string $permission): void
{
    requireLoginJson();
    if (!canDoOnProjectPermission($projectId, $permission)) {
        sendAuthJsonError('You do not have permission for this project action', 403);
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

/** Returns project IDs the current internal user may view. ['*'] means all. */
function getAllowedProjectIds(): array
{
    $user = getCurrentUser();
    if (!$user || isClientAccount($user)) {
        return [];
    }

    if (isHeadAdmin($user)) {
        return ['*'];
    }

    $permissions = permissionMap($user['permissions'] ?? '{}');
    if (!empty($permissions['view_all_projects'])) {
        return ['*'];
    }

    try {
        $stmt = getDB()->prepare("
            SELECT m.project_id, m.permissions
            FROM pm_project_members m
            INNER JOIN pm_projects p ON p.id = m.project_id
            WHERE m.user_id = :user_id
              AND m.active = 1
              AND p.deleted_at = ''
        ");
        $stmt->execute(['user_id' => (int)$user['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $projectIds = [];
        foreach ($rows as $row) {
            if (membershipAllows($row, 'view_project')) {
                $projectIds[] = (int)$row['project_id'];
            }
        }
        return array_values(array_unique($projectIds));
    } catch (Throwable $e) {
        return [];
    }
}
