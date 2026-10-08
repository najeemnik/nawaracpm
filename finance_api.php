<?php
/**
 * finance_api.php — project/company money ledger (stage 5, admin only).
 *
 * GET  action=list&type=payment|expense|withdrawal[&project_id][&from][&to]
 * GET  action=summary[&project_id]  — totals + partners share
 * POST action=add|update|delete     — CSRF required, admin only
 *
 * Partners = active full-access admins (role = 'admin'), per approved scope.
 * Expenses per project feed the EVM engine as AC (see progress_api.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';

requireAdminJson();

header('Content-Type: application/json; charset=UTF-8');

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    $input = [];
}
$action = (string)($_GET['action'] ?? $input['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'POST') {
    requireCsrfTokenJson();
}

function financeOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function financeFail(string $error, int $status = 400): void
{
    financeOut(['success' => false, 'error' => $error], $status);
}

const FINANCE_TYPES = ['payment', 'expense', 'withdrawal'];
const FINANCE_TABLES = [
    'payment' => 'pm_project_payments',
    'expense' => 'pm_project_expenses',
    'withdrawal' => 'pm_withdrawals',
];

function financeTable(string $type): string
{
    if (!in_array($type, FINANCE_TYPES, true)) {
        financeFail('Unknown ledger type', 422);
    }
    return FINANCE_TABLES[$type];
}

function financeProjectId(array $source): int
{
    $pid = (int)($source['project_id'] ?? 0);
    if ($pid <= 0) {
        financeFail('project_id is required', 422);
    }
    $stmt = getDB()->prepare("SELECT id FROM pm_projects WHERE id = :id AND deleted_at = ''");
    $stmt->execute(['id' => $pid]);
    if (!$stmt->fetch()) {
        financeFail('Project not found', 404);
    }
    return $pid;
}

function financeDate(array $source, string $field, bool $required): ?string
{
    $value = trim((string)($source[$field] ?? ''));
    if ($value === '') {
        if ($required) {
            return date('Y-m-d');
        }
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($d === false || $d->format('Y-m-d') !== $value) {
        financeFail('Date must be YYYY-MM-DD', 422);
    }
    return $value;
}

function financeAmount(array $source): float
{
    $amount = filter_var($source['amount'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0 || $amount > 1e12) {
        financeFail('Amount must be a positive number', 422);
    }
    return round((float)$amount, 2);
}

function financeNote(array $source): string
{
    $note = trim((string)($source['note'] ?? ''));
    if (mb_strlen($note) > 500) {
        financeFail('Note is too long (max 500)', 422);
    }
    return $note;
}

function financeRow(string $type, int $id): array
{
    $table = financeTable($type);
    $stmt = getDB()->prepare("SELECT * FROM {$table} WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        financeFail('Ledger entry not found', 404);
    }
    return $row;
}

function partnersCount(): int
{
    $row = getDB()->query("SELECT COUNT(*) AS n FROM pm_users WHERE role = 'admin' AND active = 1")->fetch();
    return (int)($row['n'] ?? 0);
}

/* --------------------------------------------------------------- GET ---- */
if ($method === 'GET') {
    $pdo = getDB();

    if ($action === 'list') {
        $type = (string)($_GET['type'] ?? 'payment');
        $table = financeTable($type);
        $where = [];
        $params = [];
        if ($type !== 'withdrawal') {
            $pid = (int)($_GET['project_id'] ?? 0);
            if ($pid > 0) {
                $where[] = 'project_id = :pid';
                $params['pid'] = $pid;
            }
        }
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));
        $dateCol = $type === 'payment' ? 'paid_at' : ($type === 'expense' ? 'spent_at' : 'taken_at');
        if ($from !== '') {
            $where[] = "{$dateCol} >= :from";
            $params['from'] = $from;
        }
        if ($to !== '') {
            $where[] = "{$dateCol} <= :to";
            $params['to'] = $to;
        }
        $sql = "SELECT * FROM {$table}"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY {$dateCol} DESC, id DESC LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        financeOut(['success' => true, 'type' => $type, 'rows' => $stmt->fetchAll()]);
    }

    if ($action === 'summary') {
        $pdo = getDB();
        $pid = (int)($_GET['project_id'] ?? 0);
        $whereProject = '';
        $params = [];
        if ($pid > 0) {
            financeProjectId(['project_id' => $pid]);
            $whereProject = ' WHERE project_id = :pid';
            $params['pid'] = $pid;
        }
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS s FROM pm_project_payments{$whereProject}");
        $stmt->execute($params);
        $income = (float)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS s FROM pm_project_expenses{$whereProject}");
        $stmt->execute($params);
        $expenses = (float)$stmt->fetchColumn();

        // Withdrawals are company-level (no project scope).
        $withdrawals = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) AS s FROM pm_withdrawals')->fetchColumn();

        $partners = partnersCount();
        $net = round($income - $expenses, 2);
        $remaining = round($net - $withdrawals, 2);
        financeOut([
            'success' => true,
            'scope' => $pid > 0 ? 'project' : 'company',
            'project_id' => $pid > 0 ? $pid : null,
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'withdrawals' => round($withdrawals, 2),
            'net' => $net,
            'remaining' => $remaining,
            'partners' => $partners,
            'share_per_partner' => $partners > 0 ? round($remaining / $partners, 2) : null,
        ]);
    }

    financeFail('Unknown action', 400);
}

/* -------------------------------------------------------------- POST ---- */
if ($method === 'POST') {
    $pdo = getDB();
    $type = (string)($input['type'] ?? '');
    $table = financeTable($type);
    $dateField = $type === 'payment' ? 'paid_at' : ($type === 'expense' ? 'spent_at' : 'taken_at');
    $isProject = $type !== 'withdrawal';
    $actorId = (int)(getCurrentUser()['id'] ?? 0) ?: null;

    if ($action === 'add') {
        $amount = financeAmount($input);
        $when = financeDate($input, 'date', true);
        $note = financeNote($input);
        $pid = $isProject ? financeProjectId($input) : null;
        $category = $type === 'expense' ? mb_substr(trim((string)($input['category'] ?? '')), 0, 80) : '';

        if ($isProject && $type === 'expense') {
            $stmt = $pdo->prepare("
                INSERT INTO {$table} (project_id, amount, {$dateField}, note, category, created_by)
                VALUES (:pid, :amount, :when, :note, :category, :actor)
            ");
            $stmt->execute(['pid' => $pid, 'amount' => $amount, 'when' => $when, 'note' => $note, 'category' => $category, 'actor' => $actorId]);
        } elseif ($isProject) {
            $stmt = $pdo->prepare("
                INSERT INTO {$table} (project_id, amount, {$dateField}, note, created_by)
                VALUES (:pid, :amount, :when, :note, :actor)
            ");
            $stmt->execute(['pid' => $pid, 'amount' => $amount, 'when' => $when, 'note' => $note, 'actor' => $actorId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO {$table} (amount, {$dateField}, note, created_by)
                VALUES (:amount, :when, :note, :actor)
            ");
            $stmt->execute(['amount' => $amount, 'when' => $when, 'note' => $note, 'actor' => $actorId]);
        }
        $newId = (int)$pdo->lastInsertId();
        recordAuditEvent('finance', $newId, 'created', $pid, ['type' => $type, 'amount' => $amount]);
        financeOut(['success' => true, 'id' => $newId]);
    }

    if ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        $row = financeRow($type, $id);
        $amount = financeAmount($input + ['amount' => $row['amount']]);
        $when = financeDate($input + ['date' => $row[$dateField]], 'date', true);
        $note = financeNote($input + ['note' => $row['note']]);
        if ($isProject) {
            financeProjectId(['project_id' => $row['project_id']]);
        }
        if ($type === 'expense') {
            $category = mb_substr(trim((string)($input['category'] ?? $row['category'] ?? '')), 0, 80);
            $stmt = $pdo->prepare("UPDATE {$table} SET amount = :amount, {$dateField} = :when, note = :note, category = :category, updated_at = datetime('now','localtime') WHERE id = :id");
            $stmt->execute(['amount' => $amount, 'when' => $when, 'note' => $note, 'category' => $category, 'id' => $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE {$table} SET amount = :amount, {$dateField} = :when, note = :note, updated_at = datetime('now','localtime') WHERE id = :id");
            $stmt->execute(['amount' => $amount, 'when' => $when, 'note' => $note, 'id' => $id]);
        }
        recordAuditEvent('finance', $id, 'updated', $isProject ? (int)$row['project_id'] : null, ['type' => $type, 'amount' => $amount]);
        financeOut(['success' => true]);
    }

    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        $row = financeRow($type, $id);
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        $stmt->execute(['id' => $id]);
        recordAuditEvent('finance', $id, 'deleted', $isProject ? (int)$row['project_id'] : null, ['type' => $type, 'amount' => $row['amount']]);
        financeOut(['success' => true]);
    }

    financeFail('Unknown action', 400);
}

financeFail('Method not allowed', 405);
