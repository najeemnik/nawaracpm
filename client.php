<?php
/**
 * client.php — Nawara Client (project owner portal)
 *
 * Stage 2 delivers the surface boundary only: client accounts land here and
 * can never open the internal workspace. Published progress, reports, files,
 * milestones and approval controls arrive in stage 9 — everything on this
 * surface stays publish-gated and read-only until then.
 *
 * Security notes:
 *  - Requires a live session; anonymous visitors return to the login page.
 *  - Non-client accounts are bounced back to their own surface; a client
 *    session can never satisfy the internal dashboard guards.
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

// Surface boundary: this portal serves client accounts only.
if (!canUseClientPortal($currentUser)) {
    header('Location: ' . landingPageForCurrentUser($currentUser));
    exit;
}

/** Projects this client account belongs to (active memberships only). */
function clientPageMemberships(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.project_name, p.client_name, p.zone, p.progress
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
        error_log('NawAra client page membership load failed: ' . $e->getMessage());
        return [];
    }
}

$memberships = clientPageMemberships(getDB(), (int)($currentUser['id'] ?? 0));
$e = static fn(?string $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nawara Client</title>
    <link rel="stylesheet" href="style.css?v=<?php echo app_asset_version('style.css'); ?>">
    <style>
        body.client-surface {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(165deg, #111827 0%, #1f2937 55%, #172554 100%);
            font-family: 'Segoe UI', Tahoma, sans-serif;
            color: #e5e7eb;
        }
        .client-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 22px;
            background: rgba(17, 24, 39, .8);
            border-bottom: 1px solid rgba(148, 163, 184, .18);
        }
        .client-brand { display: flex; align-items: center; gap: 12px; }
        .client-logo {
            width: 42px; height: 42px; border-radius: 12px;
            background: linear-gradient(135deg, #34d399, #059669);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
        }
        .client-brand h1 { font-size: 1.15rem; margin: 0; }
        .client-brand p { margin: 2px 0 0; font-size: .8rem; color: #9ca3af; }
        .client-btn {
            border: 0;
            border-radius: 10px;
            padding: 9px 16px;
            font-weight: 800;
            font-size: .85rem;
            cursor: pointer;
            background: rgba(148, 163, 184, .16);
            color: #e5e7eb;
            border: 1px solid rgba(148, 163, 184, .35);
        }
        .client-main { max-width: 880px; margin: 0 auto; padding: 30px 22px 60px; }
        .client-hello h2 { margin: 0 0 6px; font-size: 1.5rem; }
        .client-hello p { margin: 0 0 26px; color: #9ca3af; }
        .client-card {
            background: rgba(31, 41, 55, .85);
            border: 1px solid rgba(148, 163, 184, .2);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
        }
        .client-card h3 { margin: 0 0 4px; font-size: 1.05rem; }
        .client-card .meta { margin: 0; color: #9ca3af; font-size: .86rem; }
        .progress-track {
            margin-top: 14px;
            height: 10px;
            background: rgba(148, 163, 184, .2);
            border-radius: 999px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #34d399, #10b981);
            border-radius: 999px;
        }
        .progress-label { display: flex; justify-content: space-between; font-size: .8rem; color: #9ca3af; margin-top: 6px; }
        .client-empty, .client-soon {
            background: rgba(31, 41, 55, .7);
            border: 1px dashed rgba(148, 163, 184, .4);
            border-radius: 16px;
            padding: 30px 24px;
            text-align: center;
            color: #9ca3af;
            margin-bottom: 16px;
        }
        .client-soon strong { display: block; color: #e5e7eb; margin-bottom: 8px; }
        .client-note { margin-top: 24px; text-align: center; font-size: .8rem; color: #6b7280; }
    </style>
</head>
<body class="client-surface">
<header class="client-topbar">
    <div class="client-brand">
        <div class="client-logo">🏗</div>
        <div>
            <h1>Nawara Client</h1>
            <p><?php echo $e($currentUser['username']); ?> · Project Owner Portal</p>
        </div>
    </div>
    <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
        <button type="submit" name="logout" class="client-btn">Logout</button>
    </form>
</header>

<main class="client-main">
    <div class="client-hello">
        <h2>Welcome, <?php echo $e($currentUser['name']); ?> 👋</h2>
        <p>Your project progress and reports will appear here.</p>
    </div>

    <?php if ($memberships === []): ?>
        <div class="client-empty">
            No projects are shared with you yet. Your Head Engineer will publish updates here.
        </div>
    <?php else: ?>
        <?php foreach ($memberships as $project): ?>
            <div class="client-card">
                <h3><?php echo $e($project['project_name']); ?></h3>
                <p class="meta">
                    Client: <?php echo $e($project['client_name']); ?>
                    <?php if ($project['zone'] !== ''): ?> · Zone: <?php echo $e($project['zone']); ?><?php endif; ?>
                </p>
                <div class="progress-track">
                    <div class="progress-fill" style="width: <?php echo (int)$project['progress']; ?>%;"></div>
                </div>
                <div class="progress-label">
                    <span>Overall progress</span>
                    <span><?php echo (int)$project['progress']; ?>%</span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="client-soon">
        <strong>Reports, files and approvals — coming in stage 9</strong>
        Only content your Head Engineer publishes will ever appear on this screen.
    </div>

    <p class="client-note">Nawara Client will be installable as its own app (PWA).</p>
</main>
</body>
</html>
