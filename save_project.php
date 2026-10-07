<?php
/**
 * save_project.php
 */

require_once __DIR__ . '/database.php';
requireLoginJson();

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(['error' => 'Method Not Allowed'], 405);
}
requireCsrfTokenJson();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonOut(['error' => 'Invalid JSON'], 400);
}

$action = $input['action'] ?? 'save_project';

$settingsActions = ['add_engineer', 'add_status', 'save_engineers', 'save_statuses', 'save_sections'];
if (in_array($action, $settingsActions, true)) {
    requirePermissionJson('settings');
}

if ($action === 'save_project') {
    $projectForAuthorization = $input['project'] ?? [];
    if (!is_array($projectForAuthorization)) {
        jsonOut(['error' => 'Invalid project payload'], 400);
    }
    $projectIdForAuthorization = (int)($projectForAuthorization['id'] ?? 0);

    if ($projectIdForAuthorization > 0) {
        requireProjectActionJson($projectIdForAuthorization, 'edit');
    } else {
        requirePermissionJson('add');
    }
}

try {
    $pdo = getDB();

    if ($action === 'add_engineer') {
        $name = trim($input['name'] ?? '');

        if ($name === '') {
            jsonOut(['error' => 'Engineer name is required'], 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM pm_engineers WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $stmt = $pdo->prepare("UPDATE pm_engineers SET active = 1 WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $newId = (int)$id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pm_engineers(name, role, active, sort_order)
                VALUES(:name, 'engineer', 1, (SELECT COALESCE(MAX(sort_order),0)+1 FROM pm_engineers))
            ");
            $stmt->execute(['name' => $name]);
            $newId = (int)$pdo->lastInsertId();
        }

        jsonOut([
            'success' => true,
            'id' => $newId,
            'name' => $name,
            'meta' => fetchMeta($pdo)
        ]);
    }

    if ($action === 'add_status') {
        $name = trim($input['name'] ?? '');
        $percent = max(0, min(100, (int)($input['percent'] ?? 0)));
        $color = trim($input['color'] ?? '#6366f1');

        if ($name === '') {
            jsonOut(['error' => 'Status name is required'], 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM pm_statuses WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $name]);
        $id = $stmt->fetchColumn();

        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE pm_statuses
                SET active = 1, percent = :percent, color = :color
                WHERE id = :id
            ");
            $stmt->execute([
                'percent' => $percent,
                'color' => $color,
                'id' => $id
            ]);
            $newId = (int)$id;
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pm_statuses(name, percent, color, active, sort_order)
                VALUES(:name, :percent, :color, 1, (SELECT COALESCE(MAX(sort_order),0)+1 FROM pm_statuses))
            ");
            $stmt->execute([
                'name' => $name,
                'percent' => $percent,
                'color' => $color
            ]);
            $newId = (int)$pdo->lastInsertId();
        }

        jsonOut([
            'success' => true,
            'id' => $newId,
            'name' => $name,
            'meta' => fetchMeta($pdo)
        ]);
    }

    if ($action === 'save_engineers') {
        $names = $input['names'] ?? [];

        if (!is_array($names)) {
            jsonOut(['error' => 'Invalid engineers list'], 400);
        }

        $pdo->beginTransaction();

        $pdo->exec("UPDATE pm_engineers SET active = 0");

        $selectStmt = $pdo->prepare("SELECT id FROM pm_engineers WHERE name = :name LIMIT 1");
        $insertStmt = $pdo->prepare("
            INSERT INTO pm_engineers(name, role, active, sort_order)
            VALUES(:name, 'engineer', 1, :sort_order)
        ");
        $updateStmt = $pdo->prepare("
            UPDATE pm_engineers
            SET active = 1, sort_order = :sort_order
            WHERE id = :id
        ");

        $order = 1;
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }

            $selectStmt->execute(['name' => $name]);
            $id = $selectStmt->fetchColumn();

            if ($id) {
                $updateStmt->execute([
                    'sort_order' => $order,
                    'id' => $id
                ]);
            } else {
                $insertStmt->execute([
                    'name' => $name,
                    'sort_order' => $order
                ]);
            }

            $order++;
        }

        $pdo->commit();

        jsonOut([
            'success' => true,
            'message' => 'Engineers saved',
            'meta' => fetchMeta($pdo)
        ]);
    }

    if ($action === 'save_statuses') {
        $statuses = $input['statuses'] ?? [];

        if (!is_array($statuses)) {
            jsonOut(['error' => 'Invalid statuses list'], 400);
        }

        $pdo->beginTransaction();

        $pdo->exec("UPDATE pm_statuses SET active = 0");

        $insertStmt = $pdo->prepare("
            INSERT INTO pm_statuses(name, percent, color, active, sort_order)
            VALUES(:name, :percent, :color, 1, :sort_order)
        ");

        $updateStmt = $pdo->prepare("
            UPDATE pm_statuses
            SET name = :name,
                percent = :percent,
                color = :color,
                active = 1,
                sort_order = :sort_order
            WHERE id = :id
        ");

        $order = 1;
        foreach ($statuses as $s) {
            $id = (int)($s['id'] ?? 0);
            $name = trim($s['name'] ?? '');
            $percent = max(0, min(100, (int)($s['percent'] ?? 0)));
            $color = trim($s['color'] ?? '#6b7280');

            if ($name === '') {
                continue;
            }

            if ($id > 0) {
                $updateStmt->execute([
                    'name' => $name,
                    'percent' => $percent,
                    'color' => $color,
                    'sort_order' => $order,
                    'id' => $id
                ]);
            } else {
                $insertStmt->execute([
                    'name' => $name,
                    'percent' => $percent,
                    'color' => $color,
                    'sort_order' => $order
                ]);
            }

            $order++;
        }

        $ids = $pdo->query("SELECT id FROM pm_projects")->fetchAll();
        foreach ($ids as $row) {
            updateProjectProgress($pdo, (int)$row['id']);
        }

        $pdo->commit();

        jsonOut([
            'success' => true,
            'message' => 'Statuses saved',
            'meta' => fetchMeta($pdo)
        ]);
    }

    if ($action === 'save_sections') {
        $sections = $input['sections'] ?? [];

        if (!is_array($sections)) {
            jsonOut(['error' => 'Invalid sections list'], 400);
        }

        $pdo->beginTransaction();

        $pdo->exec("UPDATE pm_sections SET active = 0");
        $pdo->exec("UPDATE pm_section_items SET active = 0");

        $insertSection = $pdo->prepare("
            INSERT INTO pm_sections(name, icon, weight, active, sort_order)
            VALUES(:name, :icon, :weight, 1, :sort_order)
        ");

        $updateSection = $pdo->prepare("
            UPDATE pm_sections
            SET name = :name,
                icon = :icon,
                weight = :weight,
                active = 1,
                sort_order = :sort_order
            WHERE id = :id
        ");

        $insertItem = $pdo->prepare("
            INSERT INTO pm_section_items(section_id, name, weight, assignee_type, has_comment, active, sort_order)
            VALUES(:section_id, :name, :weight, :assignee_type, :has_comment, 1, :sort_order)
        ");

        $updateItem = $pdo->prepare("
            UPDATE pm_section_items
            SET section_id = :section_id,
                name = :name,
                weight = :weight,
                assignee_type = :assignee_type,
                has_comment = :has_comment,
                active = 1,
                sort_order = :sort_order
            WHERE id = :id
        ");

        foreach ($sections as $sIndex => $section) {
            $sectionId = (int)($section['id'] ?? 0);
            $name = trim($section['name'] ?? '');
            $icon = trim($section['icon'] ?? '📌');
            $weight = (float)($section['weight'] ?? 0);

            if ($name === '') {
                continue;
            }

            if ($sectionId > 0) {
                $updateSection->execute([
                    'name' => $name,
                    'icon' => $icon,
                    'weight' => $weight,
                    'sort_order' => $sIndex + 1,
                    'id' => $sectionId
                ]);
            } else {
                $insertSection->execute([
                    'name' => $name,
                    'icon' => $icon,
                    'weight' => $weight,
                    'sort_order' => $sIndex + 1
                ]);
                $sectionId = (int)$pdo->lastInsertId();
            }

            $items = $section['items'] ?? [];
            foreach ($items as $iIndex => $item) {
                $itemId = (int)($item['id'] ?? 0);
                $itemName = trim($item['name'] ?? '');
                $itemWeight = (float)($item['weight'] ?? 0);
                $assigneeType = trim($item['assignee_type'] ?? 'none');
                $hasComment = !empty($item['has_comment']) ? 1 : 0;

                if ($itemName === '') {
                    continue;
                }

                if (!in_array($assigneeType, ['none', 'engineer', 'architect', 'any'], true)) {
                    $assigneeType = 'none';
                }

                if ($itemId > 0) {
                    $updateItem->execute([
                        'section_id' => $sectionId,
                        'name' => $itemName,
                        'weight' => $itemWeight,
                        'assignee_type' => $assigneeType,
                        'has_comment' => $hasComment,
                        'sort_order' => $iIndex + 1,
                        'id' => $itemId
                    ]);
                } else {
                    $insertItem->execute([
                        'section_id' => $sectionId,
                        'name' => $itemName,
                        'weight' => $itemWeight,
                        'assignee_type' => $assigneeType,
                        'has_comment' => $hasComment,
                        'sort_order' => $iIndex + 1
                    ]);
                }
            }
        }

        $ids = $pdo->query("SELECT id FROM pm_projects")->fetchAll();
        foreach ($ids as $row) {
            updateProjectProgress($pdo, (int)$row['id']);
        }

        $pdo->commit();

        jsonOut([
            'success' => true,
            'message' => 'Sections saved',
            'meta' => fetchMeta($pdo)
        ]);
    }

    if ($action === 'save_project') {
        $project = $input['project'] ?? [];
        $values = $input['values'] ?? [];

        if (!is_array($project) || !is_array($values)) {
            jsonOut(['error' => 'Invalid project payload'], 400);
        }

        $projectName = trim((string)($project['project_name'] ?? ''));
        $clientName = trim((string)($project['client_name'] ?? ''));

        if ($projectName === '' || strlen($projectName) > 300) {
            jsonOut(['error' => 'A valid project name is required'], 422);
        }

        if ($clientName === '' || strlen($clientName) > 300) {
            jsonOut(['error' => 'A valid client name is required'], 422);
        }

        $id = (int)($project['id'] ?? 0);
        $isNewProject = $id === 0;
        $leadEngineerId = !empty($project['lead_engineer_id']) ? (int)$project['lead_engineer_id'] : null;

        if ($id > 0) {
            $existsStmt = $pdo->prepare("SELECT id FROM pm_projects WHERE id = :id LIMIT 1");
            $existsStmt->execute(['id' => $id]);
            if (!$existsStmt->fetch()) {
                jsonOut(['error' => 'Project not found'], 404);
            }
        }

        $pdo->beginTransaction();

        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE pm_projects
                SET project_name = :project_name,
                    client_name = :client_name,
                    zone = :zone,
                    lead_engineer_id = :lead_engineer_id,
                    start_date = :start_date,
                    end_date = :end_date,
                    description = :description,
                    updated_at = datetime('now','localtime')
                WHERE id = :id
            ");
            $stmt->execute([
                'project_name' => $projectName,
                'client_name' => $clientName,
                'zone' => trim($project['zone'] ?? ''),
                'lead_engineer_id' => $leadEngineerId,
                'start_date' => trim($project['start_date'] ?? ''),
                'end_date' => trim($project['end_date'] ?? ''),
                'description' => trim($project['description'] ?? ''),
                'id' => $id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pm_projects(project_name, client_name, zone, lead_engineer_id, start_date, end_date, description)
                VALUES(:project_name, :client_name, :zone, :lead_engineer_id, :start_date, :end_date, :description)
            ");
            $stmt->execute([
                'project_name' => $projectName,
                'client_name' => $clientName,
                'zone' => trim($project['zone'] ?? ''),
                'lead_engineer_id' => $leadEngineerId,
                'start_date' => trim($project['start_date'] ?? ''),
                'end_date' => trim($project['end_date'] ?? ''),
                'description' => trim($project['description'] ?? ''),
            ]);
            $id = (int)$pdo->lastInsertId();

            // A non-admin who is allowed to create a project must retain view/edit
            // access to the project they just created.
            $creator = getCurrentUser();
            $creatorPermissions = json_decode($creator['permissions'] ?? '{}', true) ?: [];
            if (($creator['role'] ?? '') !== 'admin' && empty($creatorPermissions['view_all_projects'])) {
                $accessStmt = $pdo->prepare("
                    INSERT OR IGNORE INTO pm_project_access
                        (user_id, project_id, can_view, can_edit, can_delete, can_print, can_pdf, can_files)
                    VALUES (:user_id, :project_id, 1, 1, :can_delete, :can_print, :can_pdf, :can_files)
                ");
                $accessStmt->execute([
                    'user_id' => (int)$creator['id'],
                    'project_id' => $id,
                    'can_delete' => !empty($creatorPermissions['delete']) ? 1 : 0,
                    'can_print' => !empty($creatorPermissions['print']) ? 1 : 0,
                    'can_pdf' => !empty($creatorPermissions['pdf']) ? 1 : 0,
                    'can_files' => !empty($creatorPermissions['files']) ? 1 : 0
                ]);

                $memberRole = (($creator['account_type'] ?? 'employee') === 'client')
                    ? 'client'
                    : 'employee';
                $memberStmt = $pdo->prepare("
                    INSERT OR IGNORE INTO pm_project_members
                        (project_id, user_id, membership_role, permissions, active, created_by)
                    VALUES (:project_id, :user_id, :membership_role, :permissions, 1, :created_by)
                ");
                $memberStmt->execute([
                    'project_id' => $id,
                    'user_id' => (int)$creator['id'],
                    'membership_role' => $memberRole,
                    'permissions' => json_encode($creatorPermissions, JSON_UNESCAPED_UNICODE),
                    'created_by' => (int)$creator['id']
                ]);
            }
        }

        $defaultStatusId = getDefaultStatusId($pdo);

        $selectValue = $pdo->prepare("
            SELECT id
            FROM pm_project_values
            WHERE project_id = :project_id AND item_id = :item_id
            LIMIT 1
        ");

        $insertValue = $pdo->prepare("
            INSERT INTO pm_project_values(project_id, item_id, status_id, assignee_id, comment, report_date)
            VALUES(:project_id, :item_id, :status_id, :assignee_id, :comment, :report_date)
        ");

        $updateValue = $pdo->prepare("
            UPDATE pm_project_values
            SET status_id = :status_id,
                assignee_id = :assignee_id,
                comment = :comment,
                report_date = :report_date,
                updated_at = datetime('now','localtime')
            WHERE id = :id
        ");

        foreach ($values as $v) {
            $itemId = (int)($v['item_id'] ?? 0);

            if ($itemId <= 0) {
                continue;
            }

            $statusId = !empty($v['status_id']) ? (int)$v['status_id'] : $defaultStatusId;
            $assigneeId = !empty($v['assignee_id']) ? (int)$v['assignee_id'] : null;
            $comment = trim($v['comment'] ?? '');
            $reportDate = trim($v['report_date'] ?? '');

            if ($reportDate === '') {
                $reportDate = date('Y-m-d\TH:i');
            }

            $selectValue->execute([
                'project_id' => $id,
                'item_id' => $itemId
            ]);
            $valueId = $selectValue->fetchColumn();

            if ($valueId) {
                $updateValue->execute([
                    'status_id' => $statusId,
                    'assignee_id' => $assigneeId,
                    'comment' => $comment,
                    'report_date' => $reportDate,
                    'id' => $valueId
                ]);
            } else {
                $insertValue->execute([
                    'project_id' => $id,
                    'item_id' => $itemId,
                    'status_id' => $statusId,
                    'assignee_id' => $assigneeId,
                    'comment' => $comment,
                    'report_date' => $reportDate
                ]);
            }
        }

        $progress = updateProjectProgress($pdo, $id);
        $pdo->commit();
        recordAuditEvent('project', $id, $isNewProject ? 'created' : 'updated', $id, ['progress' => $progress]);

        jsonOut([
            'success' => true,
            'message' => 'Project saved successfully',
            'id' => $id,
            'progress' => $progress
        ]);
    }

    jsonOut(['error' => 'Unknown action'], 400);

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('NawAra project save failed: ' . $e->getMessage());
    jsonOut(['error' => 'Server error'], 500);
}