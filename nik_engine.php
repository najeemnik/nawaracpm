<?php
/**
 * nik_engine.php — NiK (نیک) deterministic Q&A engine (stage 5).
 *
 * Colloquial Dari/Pashto/English question → intent + entities → answer built
 * from live DB/EVM data, permission-aware (finance & rankings are admin-only).
 * Memory: per-user notes («به خاطر بسپار …») + last-mentioned project for
 * follow-ups («همو پروژه»). Unanswered questions are logged for learning.
 *
 * Philosophy (docs/09): deterministic code decides; an LLM can be layered on
 * top later — the API surface (intent/answer/suggestions) is ready for it.
 */

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/evm_helpers.php';

const NIK_SUGGESTIONS = [
    'پیشرفت پروژه‌ها چطور است؟',
    'کدام پروژه عقب است؟',
    'کارهای من چیست؟',
    'گزارش مالی بده',
    'به خاطر بسپار: جلسه ساعت ۹ صبح',
    'چه کسانی بیشترین امتیاز را دارند؟',
    'امروز چی تاریخ است؟',
];

function nikNormalize(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(["\xD9\x8A", "\xD9\x83"], ["\xD9\x8A", "\xD9\x83"], $s); // ي ك kept
    $s = str_replace(['ي', 'ك', 'ٱ'], ['ی', 'ک', 'ا'], $s);
    $s = str_replace(["\xD8\xA0", "\xE2\x80\x8C", "\xE2\x80\x8D"], ['', ' ', ''], $s); // zwnj
    $s = preg_replace('/[.,!؟؟?;:؟«»()‎\s]+/u', ' ', $s) ?? '';
    return trim($s);
}

function nikLang(string $norm): string
{
    $psTokens = ['زما', 'ما ', 'څومره', 'کوم', 'دڅه', 'ماننه', 'بل', 'چې', 'په یاد', 'ښه', 'خبرې', 'کارونه'];
    foreach ($psTokens as $t) {
        if (mb_strpos($norm, mb_strtolower($t, 'UTF-8')) !== false) {
            return 'ps';
        }
    }
    if (preg_match('/\b(progress|project|task|report|money|score|today|forecast|expenses|payment)\b/', $norm)) {
        return 'en';
    }
    return 'fa';
}

/** Find a project mentioned in the question (or null). */
function nikFindProject(PDO $pdo, string $norm): ?array
{
    $projects = $pdo->query("
        SELECT id, project_name, start_date, end_date, progress_mode, contract_value
        FROM pm_projects WHERE deleted_at = '' ORDER BY id
    ")->fetchAll();
    foreach ($projects as $project) {
        $name = nikNormalize((string)$project['project_name']);
        if ($name !== '' && mb_strpos($norm, $name) !== false) {
            return $project;
        }
    }
    // word-level match (len >= 3) to catch partial mentions
    foreach ($projects as $project) {
        foreach (explode(' ', nikNormalize((string)$project['project_name'])) as $word) {
            if (mb_strlen($word) >= 3 && mb_strpos($norm, $word) !== false) {
                return $project;
            }
        }
    }
    return null;
}

function nikFindPerson(PDO $pdo, string $norm): ?array
{
    $people = $pdo->query("
        SELECT id, name, username FROM pm_users
        WHERE active = 1 AND account_type != 'client' ORDER BY name
    ")->fetchAll();
    foreach ($people as $person) {
        $name = nikNormalize((string)$person['name']);
        if ($name !== '' && mb_strpos($norm, $name) !== false) {
            return $person;
        }
        foreach (explode(' ', $name) as $word) {
            if (mb_strlen($word) >= 4 && mb_strpos($norm, $word) !== false) {
                return $person;
            }
        }
    }
    return null;
}

function nikMemoryGet(PDO $pdo, int $owner, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT memo_value FROM nik_memory WHERE owner_user_id = :o AND memo_key = :k LIMIT 1');
    $stmt->execute(['o' => $owner, 'k' => $key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
}

function nikMemorySet(PDO $pdo, int $owner, string $key, string $value, ?int $actor): void
{
    $stmt = $pdo->prepare("
        INSERT INTO nik_memory (owner_user_id, memo_key, memo_value, created_by, created_at)
        VALUES (:o, :k, :v, :a, datetime('now','localtime'))
        ON CONFLICT(owner_user_id, memo_key) DO UPDATE SET
            memo_value = excluded.memo_value,
            created_at = datetime('now','localtime')
    ");
    $stmt->execute(['o' => $owner, 'k' => $key, 'v' => $value, 'a' => $actor]);
}

function nikProjectFacts(PDO $pdo, array $project): array
{
    $pid = (int)$project['id'];
    $summary = calculateProjectSummary($pdo, $pid);
    $today = date('Y-m-d');
    $planned = plannedPercentFor((string)$project['start_date'], (string)$project['end_date'], $today);
    $spi = ($planned !== null && $planned > 0) ? round($summary['overall'] / $planned, 3) : null;
    $forecast = evmForecast((string)$project['start_date'], (string)$project['end_date'], $spi);
    return [
        'progress' => (int)$summary['overall'],
        'planned' => $planned,
        'spi' => $spi,
        'forecast_end' => $forecast['forecast_end'],
        'day_slippage' => $forecast['day_slippage'],
        'mode' => (string)$project['progress_mode'],
    ];
}

function nikMoney(float $v): string
{
    return number_format($v, 0, '.', ',');
}

/** Main entry: ask a question, get {answer, intent, suggestions}. */
function nikAnswerQuestion(PDO $pdo, array $user, string $question): array
{
    $userId = (int)$user['id'];
    $isAdmin = isHeadAdmin($user);
    $isClient = isClientAccount($user);
    $norm = nikNormalize($question);
    $lang = nikLang($norm);
    $suggestions = NIK_SUGGESTIONS;

    if ($norm === '') {
        return ['answer' => 'بله؟ چی بگویی 🤔', 'intent' => 'empty', 'suggestions' => $suggestions];
    }

    $project = nikFindProject($pdo, $norm);
    if ($project !== null) {
        nikMemorySet($pdo, $userId, 'last_project', (string)$project['id'], $userId);
    } elseif (preg_match('/^(همو|همان|همین|همی|ही same|the same)\b|\bهمو\b|\bهمان\b|\bهمی\b/u', $norm)) {
        $lastId = (int)(nikMemoryGet($pdo, $userId, 'last_project') ?? 0);
        if ($lastId > 0) {
            $stmt = $pdo->prepare("SELECT id, project_name, start_date, end_date, progress_mode, contract_value FROM pm_projects WHERE id = :id AND deleted_at = ''");
            $stmt->execute(['id' => $lastId]);
            $project = $stmt->fetch() ?: null;
        }
    }
    $person = nikFindPerson($pdo, $norm);

    /* ---------------------------- remember --------------------------- */
    if (preg_match('/(به خاطر بسپار|یاد بگیر|یاد داشته کن|remember| memorize|په یاد کړ)/u', $question)) {
        $note = preg_replace('/(به خاطر بسپار|یاد بگیر|یاد داشته کن|remember| memorize|په یاد کړ)/u', '', $question, 1);
        $note = trim((string)$note);
        $note = preg_replace('/^[:,،\s]+/u', '', $note) ?? $note;
        $note = trim((string)$note);
        if ($note === '') {
            return ['answer' => 'چی را یاد بگیرم؟ مثلاً بگو: «به خاطر بسپار: جلسه ساعت ۹»', 'intent' => 'remember', 'suggestions' => $suggestions];
        }
        nikMemorySet($pdo, $userId, 'note_' . substr(md5($note), 0, 10), $note, $userId);
        return ['answer' => 'حله، یادم ماند ✅ «' . mb_substr($note, 0, 120) . '» — هر وقت بگویی «چه یادته؟» برات میگم.', 'intent' => 'remember', 'suggestions' => $suggestions];
    }
    if (preg_match('/(چه یادته|یادته چی|what do you remember)/u', $norm)) {
        $stmt = $pdo->prepare("SELECT memo_value FROM nik_memory WHERE owner_user_id = :o AND memo_key LIKE 'note_%' ORDER BY id DESC LIMIT 10");
        $stmt->execute(['o' => $userId]);
        $notes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$notes) {
            return ['answer' => 'هنوز چیزی یادم نگرفتی. بگو: «به خاطر بسپار: …»', 'intent' => 'recall', 'suggestions' => $suggestions];
        }
        return ['answer' => 'آره، اینها یادمه: ' . implode(' | ', $notes), 'intent' => 'recall', 'suggestions' => $suggestions];
    }

    /* ------------------------------ help ----------------------------- */
    if (preg_match('/(کی هستی|تو کی|کمک|چه میتوانی|چطور کار|who are you|help|څه دی)/u', $norm)) {
        $answer = $lang === 'en'
            ? 'I am NiK 🤖 — I read live data from this system: project progress & forecasts, your tasks, and (for admins) finance and score stats. Ask me colloquially!'
            : ($lang === 'ps'
                ? 'زه نیک یم 🤖 — د دی سیسټ معلومات لرونکی: پروژو پرمختګ، له ویلو پیش‌بینی، زما کارونه او (د اډمین لپاره) خلکو نمرې. پوښتنه وکړه!'
                : 'من نیکم 🤖 — از همین سیستم معلومات می‌خوانم: پیشرفت و پیش‌بینی پروژه‌ها، کارهای تو، و برای ادمین: مالی و امتیازها. هر طوری بپرس، می‌فهمم!');
        return ['answer' => $answer, 'intent' => 'help', 'suggestions' => $suggestions];
    }

    /* ---------------------------- date ------------------------------- */
    if (preg_match('/(امروز چی|تاریخ امروز|چی روز است|today.*date|what.*today|نېټه)/u', $norm)) {
        $faDays = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
        $dow = (int)date('w');
        return ['answer' => 'امروز ' . date('Y-m-d') . ' است (' . $faDays[$dow] . ') 📅', 'intent' => 'date', 'suggestions' => $suggestions];
    }

    /* --------------------------- my score ---------------------------- */
    if (preg_match('/(نمره من|اسکور من|امتیاز من|my score)/u', $norm)) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM pm_task_score_events WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        $score = round((float)$stmt->fetchColumn(), 2);
        return ['answer' => 'نمره فعلی تو: ' . $score, 'intent' => 'my_score', 'suggestions' => $suggestions];
    }

    /* --------------------------- my tasks ---------------------------- */
    if (preg_match('/(کارهای من|تسک.*من|من چی|کارم چی|my tasks|what should i|زما کارونه|امروز چه)/u', $norm) || ($isClient === false && preg_match('/^(کار|تسک)\b/u', $norm) && $person === null && $project === null)) {
        $stmt = $pdo->prepare("
            SELECT t.title, t.status, t.due_at, p.project_name
            FROM pm_tasks t
            INNER JOIN pm_task_assignees a ON a.task_id = t.id
            LEFT JOIN pm_projects p ON p.id = t.project_id
            WHERE a.user_id = :u AND t.status NOT IN ('completed','cancelled')
            ORDER BY (t.due_at = '') ASC, t.due_at ASC
            LIMIT 5
        ");
        $stmt->execute(['u' => $userId]);
        $tasks = $stmt->fetchAll();
        if (!$tasks) {
            return ['answer' => 'الان هیچ کار باز نداری — آفرین! 🎉', 'intent' => 'my_tasks', 'suggestions' => $suggestions];
        }
        $lines = [];
        foreach ($tasks as $i => $t) {
            $lines[] = ($i + 1) . ') ' . $t['title'] . ($t['due_at'] ? ' 📅' . $t['due_at'] : '') . ($t['project_name'] ? ' — ' . $t['project_name'] : '');
        }
        return ['answer' => 'کارهای باز تو (' . count($tasks) . ' تا):' . "\n" . implode("\n", $lines), 'intent' => 'my_tasks', 'suggestions' => $suggestions];
    }

    /* --------------------------- overdue ----------------------------- */
    if (preg_match('/(عقب مانده|دیر شده|overdue|late tasks|ځنډ)/u', $norm)) {
        $sql = "
            SELECT t.title, t.due_at, p.project_name, u.name
            FROM pm_tasks t
            LEFT JOIN pm_projects p ON p.id = t.project_id
            LEFT JOIN pm_task_assignees a ON a.task_id = t.id
            LEFT JOIN pm_users u ON u.id = a.user_id
            WHERE t.status NOT IN ('completed','cancelled')
              AND t.due_at != '' AND t.due_at < :today
        ";
        if (!$isAdmin) {
            $sql .= ' AND a.user_id = :u';
        }
        $sql .= ' GROUP BY t.id ORDER BY t.due_at ASC LIMIT 5';
        $stmt = $pdo->prepare($sql);
        $params = ['today' => date('Y-m-d')];
        if (!$isAdmin) {
            $params['u'] = $userId;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return ['answer' => 'هیچ کار عقب‌مانده نداریم — عالیه! 💪', 'intent' => 'overdue', 'suggestions' => $suggestions];
        }
        $lines = array_map(static fn($r) => '• ' . $r['title'] . ' (📅' . $r['due_at'] . ')' . ($isAdmin && $r['name'] ? ' ← ' . $r['name'] : ''), $rows);
        return ['answer' => count($rows) . ' کار از تاریخ گذشته:' . "\n" . implode("\n", $lines), 'intent' => 'overdue', 'suggestions' => $suggestions];
    }

    /* --------------------------- finance ----------------------------- */
    if (preg_match('/(پول|مصرف|درآمد|پرداخت|برداشت|سود|مالی|مبلغ|money|expense|payment|withdraw|finance|net)/u', $norm)) {
        if (!$isAdmin) {
            return ['answer' => 'معذرت، معلومات مالی برای ادمین‌ها محفوظ است 🙈 در عوض از پیشرفت پروژه‌ها بپرس!', 'intent' => 'finance', 'suggestions' => $suggestions];
        }
        $income = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM pm_project_payments')->fetchColumn();
        $expenses = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM pm_project_expenses')->fetchColumn();
        $withdrawals = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM pm_withdrawals')->fetchColumn();
        $partners = (int)$pdo->query("SELECT COUNT(*) FROM pm_users WHERE role='admin' AND active=1")->fetchColumn();
        $net = round($income - $expenses, 2);
        $remaining = round($net - $withdrawals, 2);
        $share = $partners > 0 ? round($remaining / $partners, 2) : null;
        if ($project !== null) {
            $pid = (int)$project['id'];
            $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pm_project_payments WHERE project_id = :p');
            $st->execute(['p' => $pid]);
            $pin = (float)$st->fetchColumn();
            $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM pm_project_expenses WHERE project_id = :p');
            $st->execute(['p' => $pid]);
            $pex = (float)$st->fetchColumn();
            return ['answer' => 'پروژه «' . $project['project_name'] . '»:' . "\n"
                . '• درآمد: ' . nikMoney($pin) . "\n"
                . '• مصارف: ' . nikMoney($pex) . "\n"
                . '• باقیمانده: ' . nikMoney(round($pin - $pex, 2)) . "\n"
                . '(کل شرکت — درآمد ' . nikMoney($income) . '، مصارف ' . nikMoney($expenses) . '، برداشت ' . nikMoney($withdrawals) . ')',
                'intent' => 'finance', 'suggestions' => $suggestions];
        }
        return ['answer' => 'خلاصه مالی شرکت 💰' . "\n"
            . '• درآمد (پرداخت کلاینت‌ها): ' . nikMoney($income) . "\n"
            . '• مصارف: ' . nikMoney($expenses) . "\n"
            . '• برداشت‌ها: ' . nikMoney($withdrawals) . "\n"
            . '• باقیمانده: ' . nikMoney($remaining) . "\n"
            . '• شرکا: ' . $partners . ($share !== null ? ' — سهم هر شرکت: ' . nikMoney($share) : ''),
            'intent' => 'finance', 'suggestions' => $suggestions];
    }

    /* ---------------- rankings / who did what (admin) ---------------- */
    if (preg_match('/(امتیاز|نمره|بهترین|کی بیشتر|score|rank|best|چه کسی.*کار)/u', $norm)) {
        if (!$isAdmin) {
            return ['answer' => 'امتیاز دیگران محفوظ است. نمره خودت را بپرس: «نمره من چنده؟»', 'intent' => 'ranking', 'suggestions' => $suggestions];
        }
        if ($person !== null) {
            $stmt = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM pm_task_score_events WHERE user_id = :u');
            $stmt->execute(['u' => (int)$person['id']]);
            $score = round((float)$stmt->fetchColumn(), 2);
            $stmt = $pdo->prepare("
                SELECT t.title, e.points, e.created_at FROM pm_task_score_events e
                LEFT JOIN pm_tasks t ON t.id = e.task_id
                WHERE e.user_id = :u ORDER BY e.id DESC LIMIT 5
            ");
            $stmt->execute(['u' => (int)$person['id']]);
            $recent = $stmt->fetchAll();
            $lines = array_map(static fn($r) => '• ' . ($r['title'] ?? 'task#' . $r['task_id'] ?? '') . ' (+' . $r['points'] . ')', $recent);
            return ['answer' => $person['name'] . ' — نمره کل: ' . $score . ($lines ? "\n" . 'آخرین کارها:' . "\n" . implode("\n", $lines) : ''),
                'intent' => 'ranking', 'suggestions' => $suggestions];
        }
        $stmt = $pdo->query("
            SELECT u.name, COALESCE(SUM(e.points),0) AS score
            FROM pm_users u LEFT JOIN pm_task_score_events e ON e.user_id = u.id
            WHERE u.active = 1 AND u.account_type != 'client' AND u.role != 'admin'
            GROUP BY u.id ORDER BY score DESC, u.name COLLATE NOCASE LIMIT 5
        ");
        $rows = $stmt->fetchAll();
        $lines = [];
        foreach ($rows as $i => $r) {
            $lines[] = ($i + 1) . ') ' . $r['name'] . ' — ' . round((float)$r['score'], 2);
        }
        return ['answer' => 'جدول امتیازها 🏆' . "\n" . implode("\n", $lines), 'intent' => 'ranking', 'suggestions' => $suggestions];
    }

    /* -------------------------- progress ----------------------------- */
    if (preg_match('/(فیصد|پیشرفت|چقدر.*(شد|رفت)|progress|percent|کمپلیت|مکمل شد)/u', $norm)) {
        if ($project !== null) {
            $f = nikProjectFacts($pdo, $project);
            $spiTxt = $f['spi'] === null ? '' : ' | SPI ' . $f['spi'] . ($f['spi'] >= 0.95 ? ' (خوب ✅)' : ($f['spi'] >= 0.85 ? ' ( متوسط ⚠️)' : ' (عقب 🔴)'));
            $planTxt = $f['planned'] === null ? 'تاریخ پروژه ثبت نشده' : 'برنامه امروز: ' . $f['planned'] . '%';
            $fc = $f['forecast_end'] ? ' | پیش‌بینی ختم: ' . $f['forecast_end'] . ($f['day_slippage'] > 0 ? ' (+' . $f['day_slippage'] . ' روز دیرتر)' : ' (سروقت)') : '';
            return ['answer' => '«' . $project['project_name'] . '» تاحال ' . $f['progress'] . '% پیش رفته.' . "\n"
                . $planTxt . $spiTxt . $fc,
                'intent' => 'progress', 'suggestions' => $suggestions];
        }
        $projects = $pdo->query("SELECT id, project_name FROM pm_projects WHERE deleted_at='' ORDER BY id")->fetchAll();
        $lines = [];
        foreach ($projects as $pr) {
            $summary = calculateProjectSummary($pdo, (int)$pr['id']);
            $lines[] = '• ' . $pr['project_name'] . ': ' . $summary['overall'] . '%';
        }
        return ['answer' => 'پیشرفت همه پروژه‌ها 📈' . "\n" . ($lines ? implode("\n", $lines) : 'پروژه‌ای نیست'),
            'intent' => 'progress', 'suggestions' => $suggestions];
    }

    /* -------------------------- forecast ----------------------------- */
    if (preg_match('/(پیش.?بینی|تحویل|چند روز|روز دیر|forecast|eta|delivery|سږه)/u', $norm)) {
        $projects = $project !== null
            ? [$project]
            : $pdo->query("SELECT id, project_name, start_date, end_date, progress_mode, contract_value FROM pm_projects WHERE deleted_at='' ORDER BY id")->fetchAll();
        $lines = [];
        foreach ($projects as $pr) {
            $f = nikProjectFacts($pdo, $pr);
            $lines[] = '• ' . $pr['project_name'] . ': ' . ($f['forecast_end'] ?? '—')
                . ($f['day_slippage'] !== null ? ($f['day_slippage'] > 0 ? ' (+' . $f['day_slippage'] . ' روز)' : ' (سروقت)') : '');
        }
        return ['answer' => 'پیش‌بینی تحویل 📅' . "\n" . implode("\n", $lines), 'intent' => 'forecast', 'suggestions' => $suggestions];
    }

    /* ------------------------ employees stats (admin) ---------------- */
    if (preg_match('/(آمار کارمند|کارمندان|کی چه کار|employee.*stat|work done)/u', $norm)) {
        if (!$isAdmin) {
            return ['answer' => 'آمار دیگران محفوظ است 🙈', 'intent' => 'employees', 'suggestions' => $suggestions];
        }
        $stmt = $pdo->query("
            SELECT u.name, COUNT(DISTINCT t.id) AS n
            FROM pm_users u
            INNER JOIN pm_task_assignees a ON a.user_id = u.id
            INNER JOIN pm_tasks t ON t.id = a.task_id AND t.status = 'completed'
            WHERE u.active = 1 AND u.account_type != 'client' AND u.role != 'admin'
            GROUP BY u.id ORDER BY n DESC, u.name COLLATE NOCASE LIMIT 6
        ");
        $rows = $stmt->fetchAll();
        $lines = array_map(static fn($r, $i) => ($i + 1) . ') ' . $r['name'] . ' — ' . $r['n'] . ' کار تکمیل‌شده', $rows, array_keys($rows));
        return ['answer' => 'آمار کارها 👷' . "\n" . ($lines ? implode("\n", $lines) : 'هنوز کاری تکمیل نشده'),
            'intent' => 'employees', 'suggestions' => $suggestions];
    }

    /* --------------------------- projects ---------------------------- */
    if (preg_match('/(پروژه ها|پروژه‌ها|کدام پروژه|لیست پروژه|projects list|how many project)/u', $norm)) {
        $rows = $pdo->query("SELECT project_name FROM pm_projects WHERE deleted_at='' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        return ['answer' => count($rows) . ' پروژه داریم: ' . implode('، ', $rows), 'intent' => 'projects', 'suggestions' => $suggestions];
    }

    /* ---------------------------- fallback --------------------------- */
    return [
        'answer' => 'راستش را بگو، منظورت را کامل نفهمیدم 🤔 — یکی از اینها را بپرس یا ساده‌تر بگو (مثلاً: «پیشرفت کابل پلازا چقدره؟» یا «کارهای من چیه؟»).',
        'intent' => 'fallback',
        'suggestions' => $suggestions,
        'unanswered' => true,
    ];
}
