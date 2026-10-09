<?php
/**
 * nik_api.php — NiK (نیک) assistant endpoint (stage 5, logged-in users).
 *
 * POST {action:'ask', q}   → {answer, intent, suggestions} (CSRF protected,
 *                            speech handled by the browser). The question and
 *                            answer are stored in nik_messages (memory).
 * GET  action=history      → last 30 messages + suggestion chips.
 *
 * Permission checks live in nik_engine::nikAnswerQuestion() (finance and
 * rankings are admin-only; tasks/progress are per-user scoped).
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/nik_engine.php';

requireLoginJson();

header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function nikOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function nikFail(string $error, int $status = 400): void
{
    nikOut(['success' => false, 'error' => $error], $status);
}

$pdo = getDB();
$user = getCurrentUser();

if ($user === null) {
    nikFail('Authentication required', 401);
}

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? 'history');
    if ($action !== 'history') {
        nikFail('Unknown action', 400);
    }
    $stmt = $pdo->prepare("
        SELECT role, content, created_at
        FROM nik_messages
        WHERE user_id = :u
        ORDER BY id DESC
        LIMIT 30
    ");
    $stmt->execute(['u' => (int)$user['id']]);
    $rows = $stmt->fetchAll();
    nikOut([
        'success' => true,
        'messages' => array_reverse($rows),
        'suggestions' => NIK_SUGGESTIONS,
    ]);
}

if ($method !== 'POST') {
    nikFail('Method not allowed', 405);
}
requireCsrfTokenJson();

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($payload)) {
    nikFail('Invalid JSON', 400);
}
$action = (string)($payload['action'] ?? 'ask');
if ($action !== 'ask') {
    nikFail('Unknown action', 400);
}
$question = trim((string)($payload['q'] ?? ''));
if ($question === '' || mb_strlen($question, 'UTF-8') > 500) {
    nikFail('Question must be 1-500 characters', 422);
}

$result = nikAnswerQuestion($pdo, $user, $question);

// Remember the exchange (memory)
$now = date('Y-m-d\TH:i');
$ins = $pdo->prepare("
    INSERT INTO nik_messages (user_id, role, content, intent, created_at)
    VALUES (:u, 'user', :c, '', :t)
");
$ins->execute(['u' => (int)$user['id'], 'c' => $question, 't' => $now]);
$ins = $pdo->prepare("
    INSERT INTO nik_messages (user_id, role, content, intent, created_at)
    VALUES (:u, 'assistant', :c, :i, :t)
");
$ins->execute([
    'u' => (int)$user['id'],
    'c' => $result['answer'],
    'i' => (string)($result['intent'] ?? ''),
    't' => $now,
]);

nikOut([
    'success' => true,
    'answer' => $result['answer'],
    'intent' => $result['intent'],
    'suggestions' => $result['suggestions'] ?? NIK_SUGGESTIONS,
]);
