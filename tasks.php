<?php
/**
 * tasks.php — Nawara Tasks
 *
 * The field-side application shell: the landing surface for employees and the
 * second app available to administrators. Stage 2 delivers the shell, the
 * role-based routing and the project list; task cards arrive with the task
 * engine (stage 3) and the installable PWA packaging arrives in stage 6.
 *
 * Security notes:
 *  - Requires a live session; anonymous visitors return to the login page.
 *  - Client accounts never enter this surface (they belong to client.php).
 *  - No inline <script>: the production CSP allows scripts from 'self' only.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

getDB();

// Logout is POST-only with CSRF, same contract as index.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        clearCurrentSession();
    }
    header('Location: index.php');
    exit;
}

if (!refreshCurrentUserSession()) {
    header('Location: index.php');
    exit;
}
$currentUser = getCurrentUser();
$csrfToken = getCsrfToken();

// Surface boundary: clients belong to the client portal; everyone else may
// open Tasks (staff without CPM permission have this as their only surface).
if (canUseClientPortal($currentUser)) {
    header('Location: client.php');
    exit;
}
enforceSurfaceRouting($currentUser, 'tasks.php');

/** Project memberships visible to this user (active memberships only). */
function tasksPageMemberships(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.project_name, p.client_name, p.zone, m.membership_role
            FROM pm_project_members m
            INNER JOIN pm_projects p ON p.id = m.project_id
            WHERE m.user_id = :user_id
              AND m.active = 1
              AND p.deleted_at = ''
            ORDER BY p.project_name ASC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('NawAra tasks page membership load failed: ' . $e->getMessage());
        return [];
    }
}

$memberships = tasksPageMemberships(getDB(), (int)($currentUser['id'] ?? 0));
$mayOpenCpm = canUseCpmApp($currentUser);
$e = static fn(?string $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nawara Tasks</title>
    <link rel="stylesheet" href="style.css?v=<?php echo app_asset_version('style.css'); ?>">
    <style>
        body.tasks-surface {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(160deg, #0f172a 0%, #1e293b 100%);
            font-family: 'Segoe UI', Tahoma, sans-serif;
            color: #e2e8f0;
        }
        .tasks-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            background: rgba(15, 23, 42, .75);
            border-bottom: 1px solid rgba(148, 163, 184, .18);
            position: sticky;
            top: 0;
            backdrop-filter: blur(6px);
        }
        .tasks-brand { display: flex; align-items: center; gap: 12px; }
        .tasks-logo {
            width: 42px; height: 42px; border-radius: 12px;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
        }
        .tasks-brand h1 { font-size: 1.15rem; margin: 0; letter-spacing: .3px; }
        .tasks-brand p { margin: 2px 0 0; font-size: .8rem; color: #94a3b8; }
        .tasks-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .tasks-chip {
            background: rgba(148, 163, 184, .14);
            border: 1px solid rgba(148, 163, 184, .3);
            color: #cbd5e1;
            padding: 8px 14px;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 700;
        }
        .tasks-btn {
            display: inline-block;
            border: 0;
            border-radius: 10px;
            padding: 9px 16px;
            font-weight: 800;
            font-size: .85rem;
            cursor: pointer;
            text-decoration: none;
            transition: transform .15s ease, opacity .15s ease;
        }
        .tasks-btn:hover { transform: translateY(-1px); opacity: .92; }
        .tasks-btn.primary { background: #38bdf8; color: #06283d; }
        .tasks-btn.ghost { background: rgba(148, 163, 184, .16); color: #e2e8f0; border: 1px solid rgba(148, 163, 184, .35); }
        .tasks-main { max-width: 980px; margin: 0 auto; padding: 28px 22px 60px; }
        .tasks-hello { margin: 6px 0 24px; }
        .tasks-hello h2 { margin: 0 0 6px; font-size: 1.5rem; }
        .tasks-hello p { margin: 0; color: #94a3b8; font-size: .95rem; }
        .tasks-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        .task-card {
            background: rgba(30, 41, 59, .9);
            border: 1px solid rgba(148, 163, 184, .18);
            border-radius: 16px;
            padding: 18px;
        }
        .task-card h3 { margin: 0 0 6px; font-size: 1.02rem; }
        .task-card .meta { color: #94a3b8; font-size: .84rem; margin: 0; }
        .task-card .badge {
            display: inline-block; margin-top: 12px;
            background: rgba(56, 189, 248, .16); color: #7dd3fc;
            border: 1px solid rgba(56, 189, 248, .35);
            padding: 4px 10px; border-radius: 999px; font-size: .75rem; font-weight: 800;
        }
        .tasks-empty {
            background: rgba(30, 41, 59, .75);
            border: 1px dashed rgba(148, 163, 184, .4);
            border-radius: 16px;
            padding: 34px 26px;
            text-align: center;
            color: #94a3b8;
        }
        .tasks-empty strong { color: #e2e8f0; display: block; margin-bottom: 8px; font-size: 1.05rem; }
        .tasks-note {
            margin-top: 26px;
            font-size: .82rem;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>
<body class="tasks-surface">
<header class="tasks-topbar">
    <div class="tasks-brand">
        <div class="tasks-logo">📋</div>
        <div>
            <h1>Nawara Tasks</h1>
            <p><?php echo $e($currentUser['username']); ?> · Nawara Studio — CPM</p>
        </div>
    </div>
    <div class="tasks-actions">
        <?php if ($mayOpenCpm): ?>
            <a class="tasks-btn ghost" href="index.php">⟵ Nawara Studio — CPM</a>
        <?php endif; ?>
        <span class="tasks-chip"><?php echo $e($currentUser['role'] === 'admin' ? 'Admin' : 'Employee'); ?></span>
        <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
            <button type="submit" name="logout" class="tasks-btn ghost">Logout</button>
        </form>
    </div>
</header>

<main class="tasks-main">
    <div class="tasks-hello">
        <h2>Hello, <?php echo $e($currentUser['name']); ?> 👋</h2>
        <p>Your assigned work will appear here. Start with a project below.</p>
    </div>

    <?php if ($memberships === []): ?>
        <div class="tasks-empty">
            <strong>No projects assigned yet</strong>
            Your administrator assigns you to projects from the Users screen in Nawara Studio — CPM.
        </div>
    <?php else: ?>
        <div class="tasks-grid">
            <?php foreach ($memberships as $project): ?>
                <div class="task-card">
                    <h3><?php echo $e($project['project_name']); ?></h3>
                    <p class="meta">
                        Client: <?php echo $e($project['client_name']); ?>
                        <?php if ($project['zone'] !== ''): ?> · Zone: <?php echo $e($project['zone']); ?><?php endif; ?>
                    </p>
                    <span class="badge">0 tasks — arriving in stage 3</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="tasks-note">
        Nawara Tasks will be installable as its own app (PWA) — offline capture, photos and reminders arrive in stages 5–7.
    </p>
</main>
</body>
</html>
