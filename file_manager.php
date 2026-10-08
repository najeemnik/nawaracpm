<?php
/**
 * file_manager.php
 * Project file manager with server-side project authorization.
 */

require_once __DIR__ . '/database.php';

function fm_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fm_access_denied(string $action): void
{
    if ($action === 'download') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('You do not have permission to access these files.');
    }

    fm_json(['success' => false, 'error' => 'You do not have permission to access files for this project'], 403);
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$jsonActions = ['create_folder', 'rename', 'delete'];

if ($action === 'download') {
    requireLoginPage();
} else {
    requireLoginJson();
}

$fmJsonInput = [];
if (in_array($action, $jsonActions, true)) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    if (!is_array($decoded)) {
        fm_json(['success' => false, 'error' => 'Invalid JSON'], 400);
    }
    $fmJsonInput = $decoded;
}

// A project ID is resolved from one canonical source per action. This prevents
// authorization from checking a different ID than the mutation uses.
if (in_array($action, ['list', 'download'], true)) {
    $projectId = (int)($_GET['project_id'] ?? 0);
} elseif ($action === 'upload') {
    $projectId = (int)($_POST['project_id'] ?? 0);
} elseif (in_array($action, $jsonActions, true)) {
    $projectId = (int)($fmJsonInput['project_id'] ?? 0);
} else {
    $projectId = 0;
}

$validActions = ['list', 'create_folder', 'upload', 'download', 'rename', 'delete'];
if (!in_array($action, $validActions, true)) {
    fm_json(['success' => false, 'error' => 'Invalid action'], 400);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ((in_array($action, ['list', 'download'], true) && $method !== 'GET') ||
    (in_array($action, ['create_folder', 'upload', 'rename', 'delete'], true) && $method !== 'POST')) {
    fm_json(['success' => false, 'error' => 'Method Not Allowed'], 405);
}
if ($method === 'POST') {
    requireCsrfTokenJson();
}

if ($projectId <= 0) {
    if ($action === 'download') {
        http_response_code(400);
        exit('Invalid project ID');
    }
    fm_json(['success' => false, 'error' => 'Invalid project ID'], 400);
}

$requiredProjectPermission = in_array($action, ['create_folder', 'upload', 'rename', 'delete'], true)
    ? 'manage_files'
    : 'view_files';
if (!canDoOnProjectPermission($projectId, $requiredProjectPermission)) {
    fm_access_denied($action);
}

// APP_UPLOADS_DIR is resolved centrally by config.php. In production it is
// required to live outside the web root; this endpoint never supplies a local
// fallback of its own.
define('FM_BASE_DIR', APP_UPLOADS_DIR);
define('FM_MAX_UPLOAD_SIZE', max(1, (int)(getenv('NAWARA_MAX_UPLOAD_MB') ?: 50)) * 1024 * 1024);
define('FM_MAX_UPLOAD_FILES', max(1, (int)(getenv('NAWARA_MAX_UPLOAD_FILES') ?: 10)));
define('FM_MAX_UPLOAD_TOTAL_SIZE', max(1, (int)(getenv('NAWARA_MAX_UPLOAD_TOTAL_MB') ?: 100)) * 1024 * 1024);
define('FM_MAX_PROJECT_STORAGE', max(1, (int)(getenv('NAWARA_MAX_PROJECT_UPLOAD_MB') ?: 500)) * 1024 * 1024);
define('FM_MAX_PROJECT_FILE_COUNT', max(1, (int)(getenv('NAWARA_MAX_PROJECT_UPLOAD_FILES') ?: 5000)));

define('FM_ALLOWED_EXTENSIONS', [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt',
    'jpg', 'jpeg', 'png', 'gif', 'webp',
    'dwg', 'dxf', 'ifc', 'rvt',
    'zip', 'rar', '7z'
]);

function fm_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT name FROM sqlite_master
        WHERE type='table' AND name=:name LIMIT 1
    ");
    $stmt->execute(['name' => $table]);
    return (bool)$stmt->fetch();
}

function fm_project_exists(PDO $pdo, int $projectId): bool
{
    if (fm_table_exists($pdo, 'pm_projects')) {
        $stmt = $pdo->prepare("SELECT id FROM pm_projects WHERE id=:id AND deleted_at = '' LIMIT 1");
        $stmt->execute(['id' => $projectId]);
        if ($stmt->fetch()) return true;
    }

    if (fm_table_exists($pdo, 'projects')) {
        $stmt = $pdo->prepare("SELECT id FROM projects WHERE id=:id LIMIT 1");
        $stmt->execute(['id' => $projectId]);
        if ($stmt->fetch()) return true;
    }

    return false;
}

function fm_project_root(int $projectId): string
{
    return FM_BASE_DIR . '/project_' . $projectId;
}

function fm_default_folders(): array
{
    return [
        'Cover',
        'Architectural',
        'Structure',
        'Electrical',
        'Mechanical',
        'Project Documents',
        'DEWATS',
        'Site Plan',
    ];
}

function fm_ensure_default_folders(int $projectId): void
{
    $root = fm_project_root($projectId);

    if (!is_dir($root)) {
        @mkdir($root, 0750, true);
    }

    foreach (fm_default_folders() as $folder) {
        $path = $root . '/' . $folder;
        if (!is_dir($path)) {
            @mkdir($path, 0750, true);
        }
    }
}

function fm_clean_name(string $name): string
{
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 180) {
        return '';
    }

    // A file/folder name is a single logical component, not executable source.
    // Permit Unicode letters/numbers used by Dari and English users, plus a
    // deliberately small punctuation set. Contextual output encoding remains
    // mandatory in the browser even with this allowlist.
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._()\-]*$/u', $name)) {
        return '';
    }
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
        return '';
    }

    return $name;
}

function fm_path_is_within(string $path, string $root): bool
{
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

function fm_safe_path(int $projectId, string $relative): ?string
{
    $root = fm_project_root($projectId);
    if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
        return null;
    }

    $rootReal = realpath($root);
    if ($rootReal === false) {
        return null;
    }

    $relative = str_replace('\\', '/', $relative);
    $relative = trim($relative, '/');
    if (strlen($relative) > 2000) {
        return null;
    }
    if ($relative === '') {
        return $rootReal;
    }

    $current = $rootReal;
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
        $part = fm_clean_name($part);
        if ($part === '') {
            return null;
        }

        $candidate = $current . DIRECTORY_SEPARATOR . $part;
        // Resolve each existing component. This blocks traversal through a
        // symlink even when the final target does not exist yet.
        if (file_exists($candidate) || is_link($candidate)) {
            $resolved = realpath($candidate);
            if ($resolved === false || !fm_path_is_within($resolved, $rootReal)) {
                return null;
            }
            $current = $resolved;
        } else {
            $current = $candidate;
        }
    }

    return fm_path_is_within($current, $rootReal) ? $current : null;
}

function fm_size_text(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function fm_delete_recursive(string $path): bool
{
    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }

    if (is_dir($path)) {
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            fm_delete_recursive($path . '/' . $item);
        }
        return @rmdir($path);
    }

    return false;
}

function fm_ext(string $filename): string
{
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function fm_directory_usage(string $root): array
{
    $bytes = 0;
    $count = 0;
    if (!is_dir($root)) {
        return ['bytes' => 0, 'count' => 0];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || !$entry->isFile()) {
            continue;
        }
        $bytes += max(0, (int)$entry->getSize());
        $count++;
        if ($bytes > FM_MAX_PROJECT_STORAGE || $count > FM_MAX_PROJECT_FILE_COUNT) {
            break;
        }
    }

    return ['bytes' => $bytes, 'count' => $count];
}

function fm_has_allowed_content(string $tmpFile, string $extension): bool
{
    $mime = '';
    if (class_exists('finfo')) {
        try {
            $detected = (new finfo(FILEINFO_MIME_TYPE))->file($tmpFile);
            $mime = is_string($detected) ? strtolower($detected) : '';
        } catch (Throwable $e) {
            return false;
        }
    }

    // CAD/BIM and archive formats are often reported as application/octet-stream.
    // For recognizable active formats, require the expected family rather than
    // trusting only a filename extension.
    $allowedFamilies = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain', 'application/octet-stream'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'rar' => ['application/vnd.rar', 'application/x-rar-compressed', 'application/octet-stream'],
        '7z' => ['application/x-7z-compressed', 'application/octet-stream'],
    ];

    if ($mime === '' || !isset($allowedFamilies[$extension])) {
        return true;
    }

    return in_array($mime, $allowedFamilies[$extension], true);
}

try {
    $pdo = getDB();

    if (!is_dir(FM_BASE_DIR)) {
        @mkdir(FM_BASE_DIR, 0750, true);
    }

    /**
     * LIST
     */
    if ($action === 'list') {
        $projectId = (int)($_GET['project_id'] ?? 0);
        $path = (string)($_GET['path'] ?? '');

        if ($projectId <= 0) fm_json(['success' => false, 'error' => 'Invalid project ID'], 400);
        if (!fm_project_exists($pdo, $projectId)) fm_json(['success' => false, 'error' => 'Project not found'], 404);

        $absolute = fm_safe_path($projectId, $path);
        if ($absolute === null) fm_json(['success' => false, 'error' => 'Invalid path'], 400);

        if (!is_dir($absolute)) {
            fm_json(['success' => false, 'error' => 'Folder not found'], 404);
        }

        if ($path === '') {
            fm_ensure_default_folders($projectId);
        }

        $folders = [];
        $files = [];

        $items = @scandir($absolute) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $full = $absolute . '/' . $item;
            $itemPath = ltrim($path . '/' . $item, '/');

            // Symlinks are never listed or followed by the file manager.
            if (is_link($full)) {
                continue;
            }

            if (is_dir($full)) {
                $isDefault = ($path === '' && in_array($item, fm_default_folders(), true));

                $folders[] = [
                    'name' => $item,
                    'path' => $itemPath,
                    'modified' => date('Y-m-d H:i', @filemtime($full) ?: time()),
                    'is_default' => $isDefault
                ];
            } else {
                $size = @filesize($full) ?: 0;
                $ext = fm_ext($item);

                $files[] = [
                    'name' => $item,
                    'path' => $itemPath,
                    'ext' => $ext,
                    'size' => $size,
                    'size_text' => fm_size_text((int)$size),
                    'modified' => date('Y-m-d H:i', @filemtime($full) ?: time()),
                    'download_url' => 'file_manager.php?action=download&project_id=' . $projectId . '&path=' . urlencode($itemPath)
                ];
            }
        }

        usort($folders, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $breadcrumbs = [['name' => 'Home', 'path' => '']];
        $accum = '';
        if ($path !== '') {
            foreach (explode('/', trim($path, '/')) as $part) {
                if ($part === '') continue;
                $accum = ltrim($accum . '/' . $part, '/');
                $breadcrumbs[] = ['name' => $part, 'path' => $accum];
            }
        }

        fm_json([
            'success' => true,
            'path' => $path,
            'breadcrumbs' => $breadcrumbs,
            'folders' => $folders,
            'files' => $files
        ]);
    }

    /**
     * CREATE FOLDER
     */
    if ($action === 'create_folder') {
        $input = $fmJsonInput;

        $projectId = (int)($input['project_id'] ?? 0);
        $path = (string)($input['path'] ?? '');
        $folderName = fm_clean_name((string)($input['name'] ?? ''));

        if ($projectId <= 0) fm_json(['success' => false, 'error' => 'Invalid project ID'], 400);
        if (!fm_project_exists($pdo, $projectId)) fm_json(['success' => false, 'error' => 'Project not found'], 404);
        if ($folderName === '') fm_json(['success' => false, 'error' => 'Folder name is empty'], 400);

        $parent = fm_safe_path($projectId, $path);
        if ($parent === null) fm_json(['success' => false, 'error' => 'Invalid path'], 400);

        if (!is_dir($parent)) {
            @mkdir($parent, 0750, true);
        }

        $newDir = $parent . '/' . $folderName;

        if (file_exists($newDir)) {
            fm_json(['success' => false, 'error' => 'A file/folder with this name already exists'], 400);
        }

        if (!@mkdir($newDir, 0750, true)) {
            fm_json(['success' => false, 'error' => 'Failed to create folder'], 500);
        }

        recordAuditEvent('file_folder', null, 'created', $projectId, ['path' => ltrim($path . '/' . $folderName, '/')]);
        fm_json(['success' => true, 'message' => 'Folder created']);
    }

    /**
     * UPLOAD
     */
    if ($action === 'upload') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $path = (string)($_POST['path'] ?? '');

        if ($projectId <= 0) fm_json(['success' => false, 'error' => 'Invalid project ID'], 400);
        if (!fm_project_exists($pdo, $projectId)) fm_json(['success' => false, 'error' => 'Project not found'], 404);
        if (empty($_FILES['files'])) fm_json(['success' => false, 'error' => 'No files selected'], 400);

        $target = fm_safe_path($projectId, $path);
        if ($target === null) fm_json(['success' => false, 'error' => 'Invalid path'], 400);

        if (!is_dir($target)) @mkdir($target, 0750, true);
        if (!is_writable($target)) fm_json(['success' => false, 'error' => 'Upload folder is not writable'], 500);

        $names  = $_FILES['files']['name'];
        $tmps   = $_FILES['files']['tmp_name'];
        $sizes  = $_FILES['files']['size'];
        $errors = $_FILES['files']['error'];

        if (!is_array($names)) {
            $names = [$names]; $tmps = [$tmps]; $sizes = [$sizes]; $errors = [$errors];
        }
        if (count($names) > FM_MAX_UPLOAD_FILES) {
            fm_json(['success' => false, 'error' => 'Too many files in one upload'], 422);
        }
        $requestTotalSize = array_sum(array_map('intval', is_array($sizes) ? $sizes : []));
        if ($requestTotalSize > FM_MAX_UPLOAD_TOTAL_SIZE) {
            fm_json(['success' => false, 'error' => 'Total upload size is too large'], 422);
        }

        $usage = fm_directory_usage(fm_project_root($projectId));
        if ($usage['bytes'] + $requestTotalSize > FM_MAX_PROJECT_STORAGE) {
            fm_json(['success' => false, 'error' => 'This project has reached its storage quota'], 422);
        }
        if ($usage['count'] + count($names) > FM_MAX_PROJECT_FILE_COUNT) {
            fm_json(['success' => false, 'error' => 'This project has reached its file-count quota'], 422);
        }

        $uploaded = [];
        $uploadErrors = [];

        foreach ($names as $i => $original) {
            $original = fm_clean_name(basename((string)$original));
            $tmp = $tmps[$i] ?? '';
            $size = (int)($sizes[$i] ?? 0);
            $err  = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);

            if ($err !== UPLOAD_ERR_OK) { $uploadErrors[] = "$original: upload error"; continue; }
            if ($size <= 0) { $uploadErrors[] = "$original: empty file"; continue; }
            if ($size > FM_MAX_UPLOAD_SIZE) { $uploadErrors[] = "$original: file too large"; continue; }

            $ext = fm_ext($original);
            if ($ext === '' || !in_array($ext, FM_ALLOWED_EXTENSIONS, true)) {
                $uploadErrors[] = "$original: this file type is not allowed";
                continue;
            }
            if (!is_uploaded_file($tmp)) {
                $uploadErrors[] = "$original: invalid upload";
                continue;
            }
            if (!fm_has_allowed_content($tmp, $ext)) {
                $uploadErrors[] = "$original: file content does not match the permitted type";
                continue;
            }

            $destination = $target . '/' . $original;
            if (file_exists($destination)) {
                $base = pathinfo($original, PATHINFO_FILENAME);
                $destination = $target . '/' . $base . '_' . bin2hex(random_bytes(3)) . ($ext ? '.' . $ext : '');
            }

            if (!move_uploaded_file($tmp, $destination)) {
                $uploadErrors[] = "$original: failed to save";
                continue;
            }

            $uploaded[] = basename($destination);
        }

        if ($uploaded !== []) {
            recordAuditEvent('file', null, 'uploaded', $projectId, ['path' => $path, 'files' => $uploaded]);
        }
        fm_json([
            'success' => true,
            'message' => count($uploaded) . ' file(s) uploaded',
            'uploaded' => $uploaded,
            'errors' => $uploadErrors
        ]);
    }

    /**
     * DOWNLOAD
     */
    if ($action === 'download') {
        $projectId = (int)($_GET['project_id'] ?? 0);
        $path = (string)($_GET['path'] ?? '');

        if ($projectId <= 0) die('Invalid project ID');
        if (!fm_project_exists($pdo, $projectId)) die('Project not found');

        $file = fm_safe_path($projectId, $path);
        if ($file === null || !is_file($file)) die('File not found');

        $name = str_replace(["\r", "\n", '"'], '', basename($file));

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: private');
        header('Pragma: public');

        readfile($file);
        exit;
    }

    /**
     * RENAME
     */
    if ($action === 'rename') {
        $input = $fmJsonInput;

        $projectId = (int)($input['project_id'] ?? 0);
        $path = (string)($input['path'] ?? '');
        $newName = fm_clean_name((string)($input['new_name'] ?? ''));

        if ($projectId <= 0) fm_json(['success' => false, 'error' => 'Invalid project ID'], 400);
        if (!fm_project_exists($pdo, $projectId)) fm_json(['success' => false, 'error' => 'Project not found'], 404);
        if ($newName === '') fm_json(['success' => false, 'error' => 'New name is empty'], 400);

        $target = fm_safe_path($projectId, $path);
        if ($target === null || !file_exists($target)) fm_json(['success' => false, 'error' => 'Item not found'], 404);

        if (is_dir($target)) {
            $parts = explode('/', trim($path, '/'));
            if (count($parts) === 1 && in_array($parts[0], fm_default_folders(), true)) {
                fm_json(['success' => false, 'error' => 'Default folders cannot be renamed'], 400);
            }
        }

        $ext = fm_ext($newName);
        if (is_file($target) && ($ext === '' || !in_array($ext, FM_ALLOWED_EXTENSIONS, true))) {
            fm_json(['success' => false, 'error' => 'This file extension is not allowed'], 400);
        }

        $newPath = dirname($target) . '/' . $newName;

        if (file_exists($newPath)) {
            fm_json(['success' => false, 'error' => 'A file/folder with new name already exists'], 400);
        }

        if (!@rename($target, $newPath)) {
            fm_json(['success' => false, 'error' => 'Rename failed'], 500);
        }

        recordAuditEvent('file', null, 'renamed', $projectId, ['from' => $path, 'to' => ltrim(dirname($path) . '/' . $newName, './')]);
        fm_json(['success' => true, 'message' => 'Renamed successfully']);
    }

    /**
     * DELETE
     */
    if ($action === 'delete') {
        $input = $fmJsonInput;

        $projectId = (int)($input['project_id'] ?? 0);
        $path = (string)($input['path'] ?? '');

        if ($projectId <= 0) fm_json(['success' => false, 'error' => 'Invalid project ID'], 400);
        if (!fm_project_exists($pdo, $projectId)) fm_json(['success' => false, 'error' => 'Project not found'], 404);
        if ($path === '') fm_json(['success' => false, 'error' => 'Cannot delete root'], 400);

        $target = fm_safe_path($projectId, $path);
        if ($target === null || !file_exists($target)) fm_json(['success' => false, 'error' => 'Item not found'], 404);

        if (is_dir($target)) {
            $parts = explode('/', trim($path, '/'));
            if (count($parts) === 1 && in_array($parts[0], fm_default_folders(), true)) {
                fm_json(['success' => false, 'error' => 'Default folders cannot be deleted'], 400);
            }
        }

        $entityType = is_dir($target) ? 'file_folder' : 'file';
        if (!fm_delete_recursive($target)) {
            fm_json(['success' => false, 'error' => 'Delete failed'], 500);
        }

        recordAuditEvent($entityType, null, 'deleted', $projectId, ['path' => $path]);
        fm_json(['success' => true, 'message' => 'Deleted successfully']);
    }

    fm_json(['success' => false, 'error' => 'Invalid action'], 400);

} catch (Throwable $e) {
    error_log('NawAra file manager failed: ' . $e->getMessage());
    if ($action === 'download') {
        http_response_code(500);
        exit('Unable to process the download.');
    }
    fm_json(['success' => false, 'error' => 'Server error'], 500);
}
