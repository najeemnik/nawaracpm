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

function projectText($value, int $maxLength): string
{
    $text = trim((string)$value);
    if (strlen($text) > $maxLength || str_contains($text, "\0")) {
        throw new InvalidArgumentException('Text field is invalid or too long');
    }
    return $text;
}

function isValidProjectDate(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function isValidProjectDateTime(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value);
    return $date !== false && $date->format('Y-m-d\\TH:i') === $value;
}

function isValidStatusColor(string $color): bool
{
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', $color);
}

class ProjectValidationException extends InvalidArgumentException {}

function hasActiveProjects(PDO $pdo): bool
{
    return (bool)$pdo->query("SELECT 1 FROM pm_projects WHERE deleted_at = '' LIMIT 1")->fetchColumn();
}

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
        $name = projectText($input['name'] ?? '', 150);

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
        if (hasActiveProjects($pdo)) {
            jsonOut(['error' => 'Status templates are locked while active projects exist. Use a reviewed template-version migration.'], 409);
        }
        $name = projectText($input['name'] ?? '', 120);
        $percent = max(0, min(100, (int)($input['percent'] ?? 0)));
        $color = projectText($input['color'] ?? '#6366f1', 7);

        if ($name === '') {
            jsonOut(['error' => 'Status name is required'], 400);
        }
        if (!isValidStatusColor($color)) {
            jsonOut(['error' => 'Status color must be a six-digit hex color'], 422);
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

        if (!is_array($names) || count($names) > 200) {
            jsonOut(['error' => 'Invalid engineers list'], 400);
        }

        $normalizedNames = [];
        foreach ($names as $candidateName) {
            $candidateName = projectText($candidateName, 150);
            if ($candidateName === '') {
                continue;
            }
            $key = strtolower($candidateName);
            if (isset($normalizedNames[$key])) {
                jsonOut(['error' => 'Engineer names must be unique'], 422);
            }
            $normalizedNames[$key] = $candidateName;
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
        foreach ($normalizedNames as $name) {
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
        if (hasActiveProjects($pdo)) {
            jsonOut(['error' => 'Status templates are locked while active projects exist. Use a reviewed template-version migration.'], 409);
        }
        $statuses = $input['statuses'] ?? [];

        if (!is_array($statuses) || count($statuses) > 100 || $statuses === []) {
            jsonOut(['error' => 'Invalid statuses list'], 400);
        }
        $seenStatusNames = [];
        foreach ($statuses as $statusCandidate) {
            if (!is_array($statusCandidate)) {
                jsonOut(['error' => 'Invalid status entry'], 422);
            }
            $candidateName = projectText($statusCandidate['name'] ?? '', 120);
            $candidateColor = projectText($statusCandidate['color'] ?? '#6b7280', 7);
            if ($candidateName === '' || !isValidStatusColor($candidateColor)) {
                jsonOut(['error' => 'Each status needs a valid name and hex color'], 422);
            }
            $key = strtolower($candidateName);
            if (isset($seenStatusNames[$key])) {
                jsonOut(['error' => 'Status names must be unique'], 422);
            }
            $seenStatusNames[$key] = true;
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
            $name = projectText($s['name'] ?? '', 120);
            $percent = max(0, min(100, (int)($s['percent'] ?? 0)));
            $color = projectText($s['color'] ?? '#6b7280', 7);

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

        $ids = $pdo->query("SELECT id FROM pm_projects WHERE deleted_at = ''")->fetchAll();
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
        if (hasActiveProjects($pdo)) {
            jsonOut(['error' => 'Section templates are locked while active projects exist. Use a reviewed template-version migration.'], 409);
        }
        $sections = $input['sections'] ?? [];

        if (!is_array($sections) || $sections === [] || count($sections) > 50) {
            jsonOut(['error' => 'Invalid sections list'], 400);
        }

        $sectionWeightTotal = 0.0;
        $seenSectionNames = [];
        foreach ($sections as $sectionCandidate) {
            if (!is_array($sectionCandidate)) {
                jsonOut(['error' => 'Invalid section entry'], 422);
            }
            $sectionName = projectText($sectionCandidate['name'] ?? '', 120);
            $sectionIcon = projectText($sectionCandidate['icon'] ?? '📌', 32);
            $sectionWeight = (float)($sectionCandidate['weight'] ?? 0);
            $itemsCandidate = $sectionCandidate['items'] ?? [];
            if ($sectionName === '' || $sectionIcon === '' || !is_finite($sectionWeight)
                || $sectionWeight < 0 || $sectionWeight > 100
                || !is_array($itemsCandidate) || $itemsCandidate === [] || count($itemsCandidate) > 200) {
                jsonOut(['error' => 'Each section needs valid name, icon, weight and items'], 422);
            }
            $sectionKey = strtolower($sectionName);
            if (isset($seenSectionNames[$sectionKey])) {
                jsonOut(['error' => 'Section names must be unique'], 422);
            }
            $seenSectionNames[$sectionKey] = true;
            $sectionWeightTotal += $sectionWeight;

            $itemWeightTotal = 0.0;
            $seenItemNames = [];
            foreach ($itemsCandidate as $itemCandidate) {
                if (!is_array($itemCandidate)) {
                    jsonOut(['error' => 'Invalid section item'], 422);
                }
                $itemNameForValidation = projectText($itemCandidate['name'] ?? '', 160);
                $itemWeightForValidation = (float)($itemCandidate['weight'] ?? 0);
                if ($itemNameForValidation === '' || !is_finite($itemWeightForValidation)
                    || $itemWeightForValidation < 0 || $itemWeightForValidation > 100) {
                    jsonOut(['error' => 'Each item needs a valid name and weight'], 422);
                }
                $itemKey = strtolower($itemNameForValidation);
                if (isset($seenItemNames[$itemKey])) {
                    jsonOut(['error' => 'Item names must be unique inside a section'], 422);
                }
                $seenItemNames[$itemKey] = true;
                $itemWeightTotal += $itemWeightForValidation;
            }
            if (abs($itemWeightTotal - 100.0) > 0.01) {
                jsonOut(['error' => 'Item weights in every section must total 100'], 422);
            }
        }
        if (abs($sectionWeightTotal - 100.0) > 0.01) {
            jsonOut(['error' => 'Section weights must total 100'], 422);
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
            $name = projectText($section['name'] ?? '', 120);
            $icon = projectText($section['icon'] ?? '📌', 32);
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
                $itemName = projectText($item['name'] ?? '', 160);
                $itemWeight = (float)($item['weight'] ?? 0);
                $assigneeType = projectText($item['assignee_type'] ?? 'none', 16);
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

        $ids = $pdo->query("SELECT id FROM pm_projects WHERE deleted_at = ''")->fetchAll();
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

        $projectName = projectText($project['project_name'] ?? '', 200);
        $clientName = projectText($project['client_name'] ?? '', 200);
        $zone = projectText($project['zone'] ?? '', 150);
        $description = projectText($project['description'] ?? '', 5000);
        $startDate = projectText($project['start_date'] ?? '', 10);
        $endDate = projectText($project['end_date'] ?? '', 10);

        if ($projectName === '') {
            jsonOut(['error' => 'A valid project name is required'], 422);
        }
        if ($clientName === '') {
            jsonOut(['error' => 'A valid client name is required'], 422);
        }
        if (!isValidProjectDate($startDate) || !isValidProjectDate($endDate)
            || ($startDate !== '' && $endDate !== '' && $startDate > $endDate)) {
            jsonOut(['error' => 'Project dates are invalid'], 422);
        }

        $progressMode = null;
        if (array_key_exists('progress_mode', $project) && $project['progress_mode'] !== null && $project['progress_mode'] !== '') {
            if (!in_array($project['progress_mode'], ['manual', 'task_driven'], true)) {
                jsonOut(['error' => 'Invalid progress mode'], 422);
            }
            $progressMode = (string)$project['progress_mode'];
        }
        $contractValue = null;
        if (array_key_exists('contract_value', $project) && $project['contract_value'] !== null && $project['contract_value'] !== '') {
            $contractValue = filter_var($project['contract_value'], FILTER_VALIDATE_FLOAT);
            if ($contractValue === false || $contractValue < 0 || $contractValue > 1e12) {
                jsonOut(['error' => 'Contract value must be a non-negative number'], 422);
            }
            $contractValue = (float)$contractValue;
        }

        $id = (int)($project['id'] ?? 0);
        $isNewProject = $id === 0;
        $expectedVersion = (int)($project['version'] ?? 0);
        $previousMode = $isNewProject ? null : projectProgressMode($pdo, $id);
        $leadEngineerId = !empty($project['lead_engineer_id']) ? (int)$project['lead_engineer_id'] : null;
        if (count($values) > 500) {
            jsonOut(['error' => 'Too many project values'], 422);
        }

        if ($leadEngineerId !== null) {
            $leadStmt = $pdo->prepare("SELECT id FROM pm_engineers WHERE id = :id AND active = 1 LIMIT 1");
            $leadStmt->execute(['id' => $leadEngineerId]);
            if (!$leadStmt->fetch()) {
                jsonOut(['error' => 'Lead engineer is invalid'], 422);
            }
        }

        if ($id > 0) {
            if ($expectedVersion < 1) {
                jsonOut(['error' => 'Project version is required. Refresh and try again.'], 409);
            }
            $existsStmt = $pdo->prepare("SELECT id FROM pm_projects WHERE id = :id AND deleted_at = '' LIMIT 1");
            $existsStmt->execute(['id' => $id]);
            if (!$existsStmt->fetch()) {
                jsonOut(['error' => 'Project not found'], 404);
            }
        }

        $pdo->beginTransaction();

        if ($id > 0) {
            $currentActor = getCurrentUser();
            $extraSet = '';
            $extraParams = [];
            if ($progressMode !== null) {
                $extraSet .= ', progress_mode = :progress_mode';
                $extraParams['progress_mode'] = $progressMode;
            }
            if ($contractValue !== null) {
                $extraSet .= ', contract_value = :contract_value';
                $extraParams['contract_value'] = $contractValue;
            }
            $stmt = $pdo->prepare("
                UPDATE pm_projects
                SET project_name = :project_name,
                    client_name = :client_name,
                    zone = :zone,
                    lead_engineer_id = :lead_engineer_id,
                    start_date = :start_date,
                    end_date = :end_date,
                    description = :description" . $extraSet . ",
                    updated_by = :updated_by,
                    version = version + 1,
                    updated_at = datetime('now','localtime')
                WHERE id = :id AND version = :expected_version AND deleted_at = ''
            ");
            $stmt->execute(array_merge([
                'project_name' => $projectName,
                'client_name' => $clientName,
                'zone' => $zone,
                'lead_engineer_id' => $leadEngineerId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'description' => $description,
                'updated_by' => (int)($currentActor['id'] ?? 0) ?: null,
                'id' => $id,
                'expected_version' => $expectedVersion
            ], $extraParams));
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Project version conflict');
            }
        } else {
            $creator = getCurrentUser();
            $stmt = $pdo->prepare("
                INSERT INTO pm_projects(
                    project_name, client_name, zone, lead_engineer_id, start_date, end_date,
                    description, progress_mode, contract_value, owner_user_id, updated_by, version
                )
                VALUES(
                    :project_name, :client_name, :zone, :lead_engineer_id, :start_date, :end_date,
                    :description, :progress_mode, :contract_value, :owner_user_id, :updated_by, 1
                )
            ");
            $stmt->execute([
                'project_name' => $projectName,
                'client_name' => $clientName,
                'zone' => $zone,
                'lead_engineer_id' => $leadEngineerId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'description' => $description,
                // Approved default: new projects are task-driven.
                'progress_mode' => $progressMode ?? 'task_driven',
                'contract_value' => $contractValue ?? 0.0,
                'owner_user_id' => (int)($creator['id'] ?? 0) ?: null,
                'updated_by' => (int)($creator['id'] ?? 0) ?: null,
            ]);
            $id = (int)$pdo->lastInsertId();

            // A non-head-admin creator becomes an explicit canonical member of
            // the new project. The legacy access record is only a compatibility
            // mirror for old UI paths.
            $creator = getCurrentUser();
            $creatorPermissions = permissionMap($creator['permissions'] ?? '{}');
            if (!isHeadAdmin($creator) && !isClientAccount($creator)) {
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

                $memberPermissions = [
                    'view_project' => true,
                    'edit_project' => true,
                    'delete_project' => !empty($creatorPermissions['delete']),
                    'print_reports' => !empty($creatorPermissions['print']),
                    'download_reports' => !empty($creatorPermissions['pdf']),
                    'view_files' => !empty($creatorPermissions['files']),
                    'manage_files' => !empty($creatorPermissions['files'])
                ];
                $memberStmt = $pdo->prepare("
                    INSERT OR IGNORE INTO pm_project_members
                        (project_id, user_id, membership_role, permissions, active, created_by)
                    VALUES (:project_id, :user_id, 'employee', :permissions, 1, :created_by)
                ");
                $memberStmt->execute([
                    'project_id' => $id,
                    'user_id' => (int)$creator['id'],
                    'permissions' => json_encode($memberPermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_by' => (int)$creator['id']
                ]);
            }
        }

        $defaultStatusId = getDefaultStatusId($pdo);
        if ($defaultStatusId === null) {
            throw new RuntimeException('No active project status is configured');
        }
        $activeItemIds = array_fill_keys(
            array_map('intval', $pdo->query("SELECT id FROM pm_section_items WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN)),
            true
        );
        $activeStatusIds = array_fill_keys(
            array_map('intval', $pdo->query("SELECT id FROM pm_statuses WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN)),
            true
        );
        $activeEngineerIds = array_fill_keys(
            array_map('intval', $pdo->query("SELECT id FROM pm_engineers WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN)),
            true
        );
        $seenValueItems = [];

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
            if (!is_array($v)) {
                throw new ProjectValidationException('Invalid project value');
            }
            $itemId = (int)($v['item_id'] ?? 0);
            if ($itemId <= 0 || !isset($activeItemIds[$itemId]) || isset($seenValueItems[$itemId])) {
                throw new ProjectValidationException('Project values contain an invalid or duplicate item');
            }
            $seenValueItems[$itemId] = true;

            $statusId = !empty($v['status_id']) ? (int)$v['status_id'] : $defaultStatusId;
            $assigneeId = !empty($v['assignee_id']) ? (int)$v['assignee_id'] : null;
            $comment = projectText($v['comment'] ?? '', 4000);
            $reportDate = projectText($v['report_date'] ?? '', 16);

            if ($reportDate === '') {
                $reportDate = date('Y-m-d\TH:i');
            }
            if (!isset($activeStatusIds[$statusId]) || ($assigneeId !== null && !isset($activeEngineerIds[$assigneeId]))
                || !isValidProjectDateTime($reportDate)) {
                throw new ProjectValidationException('Project value references or report date are invalid');
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

        if (($progressMode ?? $previousMode) === 'task_driven' && $previousMode !== 'task_driven') {
            // Switching a project to task-driven: compute item percents from
            // any progress tasks that already exist.
            syncAllTaskDrivenItems($pdo, $id);
        }
        $progress = updateProjectProgress($pdo, $id);
        $pdo->commit();
        $newVersion = $isNewProject ? 1 : $expectedVersion + 1;
        recordAuditEvent('project', $id, $isNewProject ? 'created' : 'updated', $id, [
            'progress' => $progress,
            'version' => $newVersion
        ]);

        jsonOut([
            'success' => true,
            'message' => 'Project saved successfully',
            'id' => $id,
            'progress' => $progress,
            'version' => $newVersion
        ]);
    }

    jsonOut(['error' => 'Unknown action'], 400);

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getMessage() === 'Project version conflict') {
        jsonOut(['error' => 'Project changed while you were editing it. Refresh and try again.'], 409);
    }
    error_log('NawAra project save runtime failure: ' . $e->getMessage());
    jsonOut(['error' => 'Project could not be saved'], 500);
} catch (ProjectValidationException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonOut(['error' => $e->getMessage()], 422);
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonOut(['error' => 'Invalid or oversized input'], 422);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('NawAra project save failed: ' . $e->getMessage());
    jsonOut(['error' => 'Server error'], 500);
}