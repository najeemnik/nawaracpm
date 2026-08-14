<?php
/**
 * file_manager.php
 * Full File Manager per Project
 */

require_once __DIR__ . '/database.php';
requireLoginJson();
// چک permission برای پروژه
$projectId = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$user = getCurrentUser();
$isAdmin = $user && ($user['role'] ?? '') === 'admin';

if (!$isAdmin && $projectId > 0) {
    // چک کن آیا دسترسی files دارد
    $perms = json_decode($user['permissions'] ?? '{}', true) ?: [];
    $hasFilesPerm = !empty($perms['files']);
    $viewAll = !empty($perms['view_all_projects']);
    
    // اگر view_all_projects دارد و files general permission دارد
    if ($viewAll && $hasFilesPerm) {
        // OK - allowed
    } else {
        // چک کن project-specific access دارد
        try {
            $pdo = getDB();
            $stmt = $pdo->prepare("
                SELECT can_files FROM pm_project_access 
                WHERE user_id = :uid AND project_id = :pid 
                LIMIT 1
            ");
            $stmt->execute(['uid' => $user['id'], 'pid' => $projectId]);
            $canFiles = $stmt->fetchColumn();
            
            if (!$canFiles) {
                $action = $_GET['action'] ?? $_POST['action'] ?? '';
                if ($action === 'download') {
                    die('You do not have permission to access files');
                } else {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false, 
                        'error' => 'You do not have permission to access files for this project'
                    ]);
                    exit;
                }
            }
        } catch (Throwable $e) {
            // در صورت خطا اجازه نده
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Permission check failed']);
            exit;
        }
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'download') {
    if (function_exists('requireLoginPage')) {
        requireLoginPage();
    }
} else {
    if (function_exists('requireLoginJson')) {
        requireLoginJson();
    }
}

define('FM_BASE_DIR', __DIR__ . '/uploads');
define('FM_MAX_UPLOAD_SIZE', 500 * 1024 * 1024);

$blockedExtensions = [
    'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
    'pht', 'shtml', 'htaccess', 'cgi', 'pl', 'py', 'sh', 'exe', 'bat'
];

function fm_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

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
        $stmt = $pdo->prepare("SELECT id FROM pm_projects WHERE id=:id LIMIT 1");
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
        @mkdir($root, 0755, true);
    }

    foreach (fm_default_folders() as $folder) {
        $path = $root . '/' . $folder;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }
}

function fm_clean_name(string $name): string
{
    $name = trim($name);
    $name = str_replace(['/', '\\', "\0", '..'], '', $name);
    return $name;
}

function fm_safe_path(int $projectId, string $relative): ?string
{
    $root = fm_project_root($projectId);

    if (!is_dir($root)) {
        @mkdir($root, 0755, true);
    }

    $relative = str_replace('\\', '/', $relative);
    $relative = trim($relative, '/');

    if ($relative === '') {
        return realpath($root) ?: $root;
    }

    $parts = explode('/', $relative);
    $clean = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || $part === '.') continue;
        if ($part === '..') return null;
        $part = fm_clean_name($part);
        if ($part === '') continue;
        $clean[] = $part;
    }

    $target = $root . '/' . implode('/', $clean);

    $real = realpath($target);
    if ($real === false) {
        $rootReal = realpath($root);
        if ($rootReal === false) return $target;
        $normalized = $rootReal . '/' . implode('/', $clean);
        return $normalized;
    }

    $rootReal = realpath($root);
    if ($rootReal === false) return null;

    if (strpos($real, $rootReal) !== 0) {
        return null;
    }

    return $real;
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

try {
    $pdo = getDB();

    if (!is_dir(FM_BASE_DIR)) {
        @mkdir(FM_BASE_DIR, 0755, true);
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
            @mkdir($absolute, 0755, true);
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
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) fm_json(['success' => false, 'error' => 'Invalid JSON'], 400);

        $projectId = (int)($input['project_id'] ?? 0);
        $path = (string)($input['path'] ?? '');
        $folderName = fm_clean_name((string)($input['name'] ?? ''));

        if ($projectId <= 0) fm_json(['success' => false, 'error' => 'Invalid project ID'], 400);
        if (!fm_project_exists($pdo, $projectId)) fm_json(['success' => false, 'error' => 'Project not found'], 404);
        if ($folderName === '') fm_json(['success' => false, 'error' => 'Folder name is empty'], 400);

        $parent = fm_safe_path($projectId, $path);
        if ($parent === null) fm_json(['success' => false, 'error' => 'Invalid path'], 400);

        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }

        $newDir = $parent . '/' . $folderName;

        if (file_exists($newDir)) {
            fm_json(['success' => false, 'error' => 'A file/folder with this name already exists'], 400);
        }

        if (!@mkdir($newDir, 0755, true)) {
            fm_json(['success' => false, 'error' => 'Failed to create folder'], 500);
        }

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

        if (!is_dir($target)) @mkdir($target, 0755, true);
        if (!is_writable($target)) fm_json(['success' => false, 'error' => 'Upload folder is not writable'], 500);

        $names  = $_FILES['files']['name'];
        $tmps   = $_FILES['files']['tmp_name'];
        $sizes  = $_FILES['files']['size'];
        $errors = $_FILES['files']['error'];

        if (!is_array($names)) {
            $names = [$names]; $tmps = [$tmps]; $sizes = [$sizes]; $errors = [$errors];
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
            global $blockedExtensions;
            if (in_array($ext, $blockedExtensions, true)) {
                $uploadErrors[] = "$original: this file type is blocked";
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

        $name = basename($file);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private');
        header('Pragma: public');

        readfile($file);
        exit;
    }

    /**
     * RENAME
     */
    if ($action === 'rename') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) fm_json(['success' => false, 'error' => 'Invalid JSON'], 400);

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
        global $blockedExtensions;
        if (is_file($target) && in_array($ext, $blockedExtensions, true)) {
            fm_json(['success' => false, 'error' => 'This file extension is blocked'], 400);
        }

        $newPath = dirname($target) . '/' . $newName;

        if (file_exists($newPath)) {
            fm_json(['success' => false, 'error' => 'A file/folder with new name already exists'], 400);
        }

        if (!@rename($target, $newPath)) {
            fm_json(['success' => false, 'error' => 'Rename failed'], 500);
        }

        fm_json(['success' => true, 'message' => 'Renamed successfully']);
    }

    /**
     * DELETE
     */
    if ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) fm_json(['success' => false, 'error' => 'Invalid JSON'], 400);

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

        if (!fm_delete_recursive($target)) {
            fm_json(['success' => false, 'error' => 'Delete failed'], 500);
        }

        fm_json(['success' => true, 'message' => 'Deleted successfully']);
    }

    fm_json(['success' => false, 'error' => 'Invalid action'], 400);

} catch (Throwable $e) {
    fm_json(['success' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}