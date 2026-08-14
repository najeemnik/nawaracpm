<?php
/**
 * database.php
 * SQLite database layer for dynamic project management
 */

require_once __DIR__ . '/config.php';

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    try {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        createTables($pdo);
        seedDefaultData($pdo);

        return $pdo;
    } catch (Throwable $e) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function createTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_engineers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            role TEXT NOT NULL DEFAULT 'engineer',
            active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS pm_statuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL COLLATE NOCASE UNIQUE,
            percent INTEGER NOT NULL DEFAULT 0,
            color TEXT NOT NULL DEFAULT '#6b7280',
            active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS pm_sections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT '📌',
            weight REAL NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE TABLE IF NOT EXISTS pm_section_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            weight REAL NOT NULL DEFAULT 0,
            assignee_type TEXT NOT NULL DEFAULT 'none',
            has_comment INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(section_id) REFERENCES pm_sections(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS pm_projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_name TEXT NOT NULL,
            client_name TEXT NOT NULL,
            zone TEXT NOT NULL DEFAULT '',
            lead_engineer_id INTEGER NULL,
            start_date TEXT NOT NULL DEFAULT '',
            end_date TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            progress INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(lead_engineer_id) REFERENCES pm_engineers(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS pm_project_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            item_id INTEGER NOT NULL,
            status_id INTEGER NULL,
            assignee_id INTEGER NULL,
            comment TEXT NOT NULL DEFAULT '',
            report_date TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(project_id, item_id),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(item_id) REFERENCES pm_section_items(id) ON DELETE CASCADE,
            FOREIGN KEY(status_id) REFERENCES pm_statuses(id) ON DELETE SET NULL,
            FOREIGN KEY(assignee_id) REFERENCES pm_engineers(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS pm_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            permissions TEXT NOT NULL DEFAULT '{}',
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        CREATE INDEX IF NOT EXISTS idx_pm_projects_name ON pm_projects(project_name);
        CREATE INDEX IF NOT EXISTS idx_pm_projects_client ON pm_projects(client_name);
        CREATE INDEX IF NOT EXISTS idx_pm_projects_zone ON pm_projects(zone);
        CREATE INDEX IF NOT EXISTS idx_pm_values_project ON pm_project_values(project_id);
        
        CREATE TABLE IF NOT EXISTS pm_project_access (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 1,
            can_edit INTEGER NOT NULL DEFAULT 0,
            can_delete INTEGER NOT NULL DEFAULT 0,
            can_print INTEGER NOT NULL DEFAULT 0,
            can_pdf INTEGER NOT NULL DEFAULT 0,
            can_files INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(user_id, project_id)
        );
    ");
}


function seedDefaultData(PDO $pdo): void
{
    // ساخت ادمین پیش‌فرض اگر کاربری وجود نداشت
    try {
        $userCount = (int)$pdo->query("SELECT COUNT(*) FROM pm_users")->fetchColumn();
        if ($userCount === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO pm_users (name, username, password, role, permissions) 
                VALUES (:name, :username, :password, :role, :permissions)
            ");
            $stmt->execute([
                'name' => 'Administrator',
                'username' => 'admin',
                'password' => password_hash('NawAra@2025', PASSWORD_DEFAULT),
                'role' => 'admin',
                'permissions' => '{}'
            ]);
        }
    } catch (Throwable $e) {
        // Ignore
    }

    // Status های پیش‌فرض
    $count = (int)$pdo->query("SELECT COUNT(*) FROM pm_statuses")->fetchColumn();

    if ($count === 0) {
        $statuses = [
            ['Not Started', 0, '#6b7280'],
            ['In Progress', 25, '#3b82f6'],
            ['Waiting Comment', 50, '#f59e0b'],
            ['Revision', 60, '#ef4444'],
            ['Completed', 100, '#10b981'],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO pm_statuses(name, percent, color, sort_order) 
            VALUES(:name, :percent, :color, :sort_order)
        ");
        foreach ($statuses as $i => $s) {
            $stmt->execute([
                'name' => $s[0],
                'percent' => $s[1],
                'color' => $s[2],
                'sort_order' => $i + 1
            ]);
        }
    }

    // Engineers پیش‌فرض
    $count = (int)$pdo->query("SELECT COUNT(*) FROM pm_engineers")->fetchColumn();

    if ($count === 0) {
        $engineers = [
            ['Engineer 1', 'engineer'],
            ['Engineer 2', 'engineer'],
            ['Engineer 3', 'engineer'],
            ['Engineer 4', 'engineer'],
            ['Engineer 5', 'engineer'],
            ['Engineer 6', 'engineer'],
            ['Architect 1', 'architect'],
            ['Architect 2', 'architect'],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO pm_engineers(name, role, sort_order) 
            VALUES(:name, :role, :sort_order)
        ");
        foreach ($engineers as $i => $e) {
            $stmt->execute([
                'name' => $e[0],
                'role' => $e[1],
                'sort_order' => $i + 1
            ]);
        }
    }

    // Sections پیش‌فرض
    $count = (int)$pdo->query("SELECT COUNT(*) FROM pm_sections")->fetchColumn();

    if ($count === 0) {
        $sections = [
            [
                'name' => 'Architectural',
                'icon' => '📐',
                'weight' => 20,
                'items' => [
                    ['Architectural Concept', 25, 'none'],
                    ['Draft for Mojawez', 20, 'none'],
                    ['3D for Mojawez', 20, 'none'],
                    ['Final 3D', 20, 'none'],
                    ['Assigned Architect / Coordination', 5, 'architect'],
                    ['Architectural Overall Review', 10, 'none'],
                ]
            ],
            [
                'name' => 'Structure',
                'icon' => '🏗',
                'weight' => 20,
                'items' => [
                    ['Structural Design / Analysis', 60, 'engineer'],
                    ['Structural Drawings / Calculation', 25, 'engineer'],
                    ['Structure Overall Review', 15, 'none'],
                ]
            ],
            [
                'name' => 'Electrical',
                'icon' => '⚡',
                'weight' => 20,
                'items' => [
                    ['Electrical Design', 50, 'engineer'],
                    ['Electrical Drawings', 30, 'engineer'],
                    ['Electrical Overall Review', 20, 'none'],
                ]
            ],
            [
                'name' => 'Mechanical',
                'icon' => '⚙️',
                'weight' => 20,
                'items' => [
                    ['Mechanical Design', 50, 'engineer'],
                    ['Mechanical Drawings', 30, 'engineer'],
                    ['Mechanical Overall Review', 20, 'none'],
                ]
            ],
            [
                'name' => 'Soil Test',
                'icon' => '🔬',
                'weight' => 10,
                'items' => [
                    ['Soil Investigation', 70, 'engineer'],
                    ['Soil Report', 30, 'none'],
                ]
            ],
            [
                'name' => 'IFC',
                'icon' => '📋',
                'weight' => 10,
                'items' => [
                    ['IFC Architectural', 25, 'none'],
                    ['IFC Structure', 25, 'none'],
                    ['IFC Electrical', 20, 'none'],
                    ['IFC Mechanical', 20, 'none'],
                    ['Final IFC Issue', 10, 'none'],
                ]
            ],
        ];

        $sectionStmt = $pdo->prepare("
            INSERT INTO pm_sections(name, icon, weight, sort_order)
            VALUES(:name, :icon, :weight, :sort_order)
        ");

        $itemStmt = $pdo->prepare("
            INSERT INTO pm_section_items(section_id, name, weight, assignee_type, has_comment, sort_order)
            VALUES(:section_id, :name, :weight, :assignee_type, 1, :sort_order)
        ");

        foreach ($sections as $sIndex => $section) {
            $sectionStmt->execute([
                'name' => $section['name'],
                'icon' => $section['icon'],
                'weight' => $section['weight'],
                'sort_order' => $sIndex + 1
            ]);

            $sectionId = (int)$pdo->lastInsertId();

            foreach ($section['items'] as $iIndex => $item) {
                $itemStmt->execute([
                    'section_id' => $sectionId,
                    'name' => $item[0],
                    'weight' => $item[1],
                    'assignee_type' => $item[2],
                    'sort_order' => $iIndex + 1
                ]);
            }
        }
    }
}
function fetchMeta(PDO $pdo): array
{
    $engineers = $pdo->query("
        SELECT id, name, role, active, sort_order
        FROM pm_engineers
        WHERE active = 1
        ORDER BY sort_order ASC, name ASC
    ")->fetchAll();

    $statuses = $pdo->query("
        SELECT id, name, percent, color, active, sort_order
        FROM pm_statuses
        WHERE active = 1
        ORDER BY sort_order ASC, percent ASC, name ASC
    ")->fetchAll();

    $sections = $pdo->query("
        SELECT id, name, icon, weight, active, sort_order
        FROM pm_sections
        WHERE active = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll();

    $items = $pdo->query("
        SELECT id, section_id, name, weight, assignee_type, has_comment, active, sort_order
        FROM pm_section_items
        WHERE active = 1
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll();

    foreach ($sections as &$section) {
        $section['items'] = [];
        foreach ($items as $item) {
            if ((int)$item['section_id'] === (int)$section['id']) {
                $section['items'][] = $item;
            }
        }
    }
    unset($section);

    return [
        'engineers' => $engineers,
        'statuses' => $statuses,
        'sections' => $sections
    ];
}

function getDefaultStatusId(PDO $pdo): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM pm_statuses WHERE name = 'Not Started' AND active = 1 LIMIT 1");
    $stmt->execute();
    $id = $stmt->fetchColumn();

    if ($id) {
        return (int)$id;
    }

    $stmt = $pdo->query("SELECT id FROM pm_statuses WHERE active = 1 ORDER BY percent ASC LIMIT 1");
    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

function calculateProjectSummary(PDO $pdo, int $projectId): array
{
    $meta = fetchMeta($pdo);

    $statusMap = [];
    foreach ($meta['statuses'] as $s) {
        $statusMap[(int)$s['id']] = $s;
    }

    $stmt = $pdo->prepare("
        SELECT item_id, status_id
        FROM pm_project_values
        WHERE project_id = :project_id
    ");
    $stmt->execute(['project_id' => $projectId]);
    $values = $stmt->fetchAll();

    $valueMap = [];
    foreach ($values as $v) {
        $valueMap[(int)$v['item_id']] = $v;
    }

    $sectionsProgress = [];
    $overallWeighted = 0;
    $totalSectionWeight = 0;

    foreach ($meta['sections'] as $section) {
        $sectionWeight = (float)$section['weight'];
        $totalSectionWeight += $sectionWeight;

        $itemWeighted = 0;
        $totalItemWeight = 0;

        foreach ($section['items'] as $item) {
            $itemWeight = (float)$item['weight'];
            $totalItemWeight += $itemWeight;

            $itemId = (int)$item['id'];
            $statusId = isset($valueMap[$itemId]) ? (int)$valueMap[$itemId]['status_id'] : 0;
            $percent = isset($statusMap[$statusId]) ? (int)$statusMap[$statusId]['percent'] : 0;

            $itemWeighted += ($percent * $itemWeight);
        }

        $sectionPercent = $totalItemWeight > 0 ? (int)round($itemWeighted / $totalItemWeight) : 0;
        $overallWeighted += ($sectionPercent * $sectionWeight);

        $sectionsProgress[] = [
            'id' => (int)$section['id'],
            'name' => $section['name'],
            'icon' => $section['icon'],
            'percent' => $sectionPercent,
            'weight' => $sectionWeight
        ];
    }

    $overall = $totalSectionWeight > 0 ? (int)round($overallWeighted / $totalSectionWeight) : 0;

    return [
        'overall' => $overall,
        'sections' => $sectionsProgress
    ];
}

function updateProjectProgress(PDO $pdo, int $projectId): int
{
    $summary = calculateProjectSummary($pdo, $projectId);
    $progress = (int)$summary['overall'];

    $stmt = $pdo->prepare("
        UPDATE pm_projects
        SET progress = :progress, updated_at = datetime('now','localtime')
        WHERE id = :id
    ");
    $stmt->execute([
        'progress' => $progress,
        'id' => $projectId
    ]);

    return $progress;
}

function getProjectPayload(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT p.*, e.name AS lead_engineer_name
        FROM pm_projects p
        LEFT JOIN pm_engineers e ON e.id = p.lead_engineer_id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $project = $stmt->fetch();

    if (!$project) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT 
            v.*,
            st.name AS status_name,
            st.percent AS status_percent,
            st.color AS status_color,
            en.name AS assignee_name
        FROM pm_project_values v
        LEFT JOIN pm_statuses st ON st.id = v.status_id
        LEFT JOIN pm_engineers en ON en.id = v.assignee_id
        WHERE v.project_id = :project_id
    ");
    $stmt->execute(['project_id' => $id]);
    $values = $stmt->fetchAll();

    $summary = calculateProjectSummary($pdo, $id);

    $project['values'] = $values;
    $project['section_progress'] = $summary['sections'];
    $project['progress'] = $summary['overall'];
    $project['overall_status'] = overallStatusFromProgress($summary['overall']);

    return $project;
}

function overallStatusFromProgress(int $progress): string
{
    if ($progress <= 0) {
        return 'Not Started';
    }
    if ($progress >= 100) {
        return 'Completed';
    }
    if ($progress >= 60) {
        return 'In Progress';
    }
    return 'In Progress';
}

function jsonOut(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}