<?php
/**
 * tools/rebuild-test-users.php
 * ============================
 * Stage 1 utility: clean rebuild of the TEST accounts in the database.
 *
 * The current pm_users rows are development/test accounts (user approved the
 * rebuild). This tool replaces them with a single fresh administrator whose
 * initial credentials are written to a git-ignored file, never to stdout, logs
 * or version control.
 *
 * What it does (one transaction):
 *   1. Deletes every non-administrator user.
 *   2. Deletes every administrator except the first one (id ASC) — required
 *      because trg_pm_users_keep_last_admin_on_delete blocks removing the
 *      final active administrator.
 *   3. Resets the kept administrator: fresh password hash, username "admin",
 *      account_type=admin, auth_version bumped (invalidates old sessions).
 *   4. Clears legacy/project membership grants that pointed at test users.
 *   5. Records the rebuild in pm_audit_log.
 *
 * What it never does: touch projects, sections, statuses, engineers, weights,
 * progress values, tasks, priorities or files.
 *
 * Usage (development):
 *   NAWARA_REBUILD_TEST_USERS=yes php tools/rebuild-test-users.php
 *
 * Safety rails:
 *   - refuses to run over HTTP (CLI only)
 *   - refuses without the exact confirmation environment variable
 *   - refuses if the schema migrations have not been applied
 *   - refuses if there is no active administrator to keep
 *   - rolls back everything on any error
 */

declare(strict_types=1);

// 'cli' = real command line on a server; 'wasm' = the local PHP-Wasm test
// runtime used by the project's test harness. Every web SAPI
// (fpm-fcgi, apache2handler, cgi-fcgi, litespeed, ...) is rejected.
if (!in_array(PHP_SAPI, ['cli', 'wasm'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

require_once dirname(__DIR__) . '/database.php';

// STDOUT/STDERR constants only exist in the real CLI SAPI; the PHP-Wasm test
// runtime and some hosts provide php:// streams instead.
$toolErr = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
$toolOut = defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');

$confirm = (string)(getenv('NAWARA_REBUILD_TEST_USERS') ?: '');
if ($confirm !== 'yes') {
    fwrite($toolErr, "Refusing to run: set NAWARA_REBUILD_TEST_USERS=yes to confirm.\n");
    exit(1);
}

if (!databaseSchemaIsCurrent(getDB())) {
    fwrite($toolErr, "Refusing to run: schema migrations are not fully applied.\n");
    exit(1);
}

$pdo = getDB();

$keep = $pdo->query(
    "SELECT id FROM pm_users WHERE role = 'admin' AND active = 1 ORDER BY id ASC LIMIT 1"
)->fetchColumn();

if (!$keep) {
    fwrite($toolErr, "Refusing to run: no active administrator exists to keep.\n");
    exit(1);
}
$keepId = (int)$keep;

$initialPassword = bin2hex(random_bytes(16)); // 32 chars, exceeds the 12-char minimum
$passwordHash = password_hash($initialPassword, PASSWORD_DEFAULT);
if ($passwordHash === false) {
    fwrite($toolErr, "Password hashing failed.\n");
    exit(1);
}

$credentialsFile = trim((string)(getenv('NAWARA_CREDENTIALS_FILE') ?: ''));
if ($credentialsFile === '') {
    $credentialsFile = APP_ROOT . '/backups/initial-admin-credentials.txt';
}
$credentialsDir = dirname($credentialsFile);
if (!is_dir($credentialsDir) && !@mkdir($credentialsDir, 0750, true) && !is_dir($credentialsDir)) {
    fwrite($toolErr, "Cannot create credentials directory: {$credentialsDir}\n");
    exit(1);
}

try {
    $pdo->beginTransaction();

    $deletedUsers = (int)$pdo->exec("DELETE FROM pm_users WHERE role != 'admin'");
    $deletedAdmins = (int)$pdo->exec(
        "DELETE FROM pm_users WHERE role = 'admin' AND id != {$keepId}"
    );

    $stmt = $pdo->prepare("
        UPDATE pm_users
        SET name = :name,
            username = :username,
            password = :password,
            role = 'admin',
            account_type = 'admin',
            permissions = '{}',
            active = 1,
            auth_version = auth_version + 1,
            password_changed_at = datetime('now','localtime'),
            updated_at = datetime('now','localtime'),
            last_login_at = ''
        WHERE id = :id
    ");
    $stmt->execute([
        'name' => 'Administrator',
        'username' => 'admin',
        'password' => $passwordHash,
        'id' => $keepId,
    ]);

    $deletedMembers = (int)$pdo->exec('DELETE FROM pm_project_members');
    $deletedAccess = (int)$pdo->exec('DELETE FROM pm_project_access');

    $stmt = $pdo->prepare("
        INSERT INTO pm_audit_log (entity_type, entity_id, action, actor_user_id, details, created_at)
        VALUES ('user', :entity_id, 'users.rebuilt', :actor, :details, datetime('now','localtime'))
    ");
    $stmt->execute([
        'entity_id' => $keepId,
        'actor' => $keepId,
        'details' => json_encode([
            'scope' => 'test-user-rebuild',
            'deleted_users' => $deletedUsers,
            'deleted_admins' => $deletedAdmins,
            'deleted_memberships' => $deletedMembers + $deletedAccess,
            'kept_admin_id' => $keepId,
        ], JSON_UNESCAPED_UNICODE),
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite($toolErr, 'Rebuild failed and was rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

$payload = implode("\n", [
    'Nawara Studio — CPM : initial administrator credentials',
    'Generated: ' . date('c'),
    '',
    'URL user: admin',
    'Password: ' . $initialPassword,
    '',
    'Store this file somewhere safe, sign in, then delete it.',
    'This file is excluded from version control via .gitignore.',
    '',
]) . "\n";

if (@file_put_contents($credentialsFile, $payload, LOCK_EX) === false) {
    // The rebuild itself already committed; surface the password once so it is
    // not lost, but keep it out of the application error log.
    fwrite($toolOut, "\nWARNING: could not write {$credentialsFile}.\n");
    fwrite($toolOut, "Administrator password (save it now): {$initialPassword}\n");
    exit(1);
}
@chmod($credentialsFile, 0600);

$remaining = (int)$pdo->query('SELECT COUNT(*) FROM pm_users')->fetchColumn();

fwrite($toolOut, 'Rebuild complete.' . "\n");
fwrite($toolOut, "  users deleted: {$deletedUsers} non-admin, {$deletedAdmins} admin" . "\n");
fwrite($toolOut, "  memberships cleared: " . ($deletedMembers + $deletedAccess) . "\n");
fwrite($toolOut, "  remaining users: {$remaining} (admin id {$keepId})" . "\n");
fwrite($toolOut, "  credentials file: {$credentialsFile}" . "\n");
