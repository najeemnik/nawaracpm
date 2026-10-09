<?php
/**
 * index.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

getDB();

// Logout is intentionally POST-only to prevent cross-site logout requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        clearCurrentSession();
    }
    header('Location: index.php');
    exit;
}

// Login Logic
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    // Bound untrusted credential payloads before bcrypt work and database logs.
    $username = substr($username, 0, 64);
    $passwordTooLong = strlen($password) > 1024;

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $loginError = 'درخواست نامعتبر است. لطفاً دوباره تلاش کنید.';
    } else {
        $pdo = getDB();
        // Store only hashes for throttling; raw IP addresses and usernames are
        // not persisted in the login-attempt table.
        $usernameHash = hash('sha256', strtolower($username));
        $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        // Avoid a full retention delete on every login. The dedicated index in
        // migration 007 keeps this bounded maintenance query inexpensive.
        if (random_int(1, 100) === 1) {
            $pdo->prepare("DELETE FROM pm_login_attempts WHERE attempted_at < datetime('now','-30 days')")->execute();
        }

        $throttleStmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN username_hash = :username_hash AND ip_hash = :ip_hash THEN 1 ELSE 0 END) AS username_ip_failures,
                SUM(CASE WHEN ip_hash = :ip_hash THEN 1 ELSE 0 END) AS ip_failures
            FROM pm_login_attempts
            WHERE succeeded = 0
              AND attempted_at >= datetime('now','-15 minutes')
        ");
        $throttleStmt->execute(['username_hash' => $usernameHash, 'ip_hash' => $ipHash]);
        $throttle = $throttleStmt->fetch() ?: [];
        $isThrottled = (int)($throttle['username_ip_failures'] ?? 0) >= 8
            || (int)($throttle['ip_failures'] ?? 0) >= 30;

        if ($isThrottled) {
            $loginError = 'تلاش‌های زیادی انجام شده است. لطفاً بعداً دوباره کوشش کنید.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM pm_users WHERE username = :un AND active = 1 LIMIT 1");
            $stmt->execute(['un' => $username]);
            $user = $stmt->fetch();
            // Verify against a valid bcrypt hash even for unknown accounts so
            // the response does not reveal whether a username exists by timing.
            $passwordHash = $user['password'] ?? '$2y$10$z7pKdikgHqWMYS9bIgDqKOB66143HfQ9Vlxss74CmDbpdoACAtF8K';
            $passwordMatches = password_verify($passwordTooLong ? '' : $password, $passwordHash);
            $loginSucceeded = !$passwordTooLong && $user !== false && $passwordMatches;

            $attemptStmt = $pdo->prepare("
                INSERT INTO pm_login_attempts(username_hash, ip_hash, succeeded)
                VALUES(:username_hash, :ip_hash, :succeeded)
            ");
            $attemptStmt->execute([
                'username_hash' => $usernameHash,
                'ip_hash' => $ipHash,
                'succeeded' => $loginSucceeded ? 1 : 0
            ]);

            if ($loginSucceeded) {
                // Never retain the password hash in the browser session.
                unset($user['password']);
                $pdo->prepare("DELETE FROM pm_login_attempts WHERE username_hash = :username_hash AND succeeded = 0")
                    ->execute(['username_hash' => $usernameHash]);

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_data'] = $user;
                $_SESSION['auth_version'] = (int)($user['auth_version'] ?? 1);
                $_SESSION['auth_issued_at'] = time();
                $_SESSION['auth_last_activity_at'] = time();
                unset($_SESSION['csrf_token']);
                getCsrfToken();

                $loginStmt = $pdo->prepare("UPDATE pm_users SET last_login_at = datetime('now','localtime') WHERE id = :id");
                $loginStmt->execute(['id' => (int)$user['id']]);

                header('Location: index.php');
                exit;
            }

            $loginError = 'نام کاربری یا رمز عبور اشتباه است.';
        }
    }
}

$logoExists = file_exists(__DIR__ . '/logo.png');
$currentUser = isLoggedIn() ? refreshCurrentUserSession() : null;

// Stage 2 surface routing: every account type opens its own app. Employees
// land on Nawara Tasks, clients on the client portal, CPM-capable staff here.
if ($currentUser !== null) {
    enforceSurfaceRouting($currentUser, 'index.php');
}

$csrfToken = getCsrfToken();

// LOGIN PAGE
if (!isLoggedIn()):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo app_asset_version('style.css'); ?>">
</head>
<body class="login-body">
    <div class="login-wrapper">
        <form method="post" class="login-card">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="login-logo">
                <?php if ($logoExists): ?><img src="logo.png" alt="Logo"><?php else: ?>NawAra<?php endif; ?>
            </div>
            <h2>Welcome</h2>
            <p>Please enter your credentials to login</p>

            <?php if ($loginError): ?>
                <div class="login-error"><?php echo $loginError; ?></div>
            <?php endif; ?>

            <div class="login-fg">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="login-fg">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" name="login" class="login-btn">Login</button>
        </form>
    </div>
</body>
</html>
<?php 
exit; 
endif; 

// چک permissions
$isAdmin = ($currentUser['role'] ?? '') === 'admin';
$userPerms = json_decode($currentUser['permissions'] ?? '{}', true) ?: [];

function hasPerm($perm) {
    global $isAdmin, $userPerms;
    if ($isAdmin) return true;
    return !empty($userPerms[$perm]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo app_asset_version('style.css'); ?>">
    <meta name="nawara-current-user" content="<?php echo htmlspecialchars((string)json_encode([
        'name' => $currentUser['name'],
        'role' => $currentUser['role'],
        'permissions' => $userPerms
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
    <meta name="nawara-csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
</head>
<body data-role="<?php echo $isAdmin ? 'admin' : 'employee'; ?>" data-user-id="<?php echo (int)($currentUser['id'] ?? 0); ?>">

<header class="app-header">
    <div class="header-content">
        <div class="logo-section">
            <div class="logo-icon">
                <?php if ($logoExists): ?><img src="logo.png" alt="Logo"><?php else: ?>NawAra<?php endif; ?>
            </div>
            <div class="logo-text">
                <h1><?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p><?php echo htmlspecialchars(APP_SUBTITLE, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>

        <div class="header-stats">
            <div class="stat-card">
                <span class="stat-num" id="totalProjects">0</span>
                <span class="stat-lbl">Total</span>
            </div>
            <div class="stat-card">
                <span class="stat-num" id="activeProjects">0</span>
                <span class="stat-lbl">Active</span>
            </div>
            <div class="stat-card">
                <span class="stat-num" id="completedProjects">0</span>
                <span class="stat-lbl">Done</span>
            </div>
            
            <div class="user-menu">
                <div class="user-badge-new" data-action="toggle-user-menu" role="button" tabindex="0">
                    <span class="user-avatar">👤</span>
                    <div class="user-info">
                        <span class="user-name"><?php echo htmlspecialchars($currentUser['name']); ?></span>
                        <span class="user-role"><?php echo htmlspecialchars($currentUser['role']); ?></span>
                    </div>
                    <span class="user-arrow">▼</span>
                </div>
                
                <div class="user-dropdown" id="userDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-avatar">👤</div>
                        <div>
                            <div class="dropdown-name"><?php echo htmlspecialchars($currentUser['name']); ?></div>
                            <div class="dropdown-role"><?php echo htmlspecialchars($currentUser['username']); ?></div>
                        </div>
                    </div>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item dropdown-surface-link" href="tasks.php">
                        <span>📋</span>
                        <span>Nawara Tasks</span>
                    </a>
                    <div class="dropdown-divider"></div>
                    <form method="post" class="logout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" name="logout" class="dropdown-item dropdown-logout">
                            <span>🚪</span>
                            <span>Logout / خروج</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="toolbar">
    <div class="toolbar-content">
        <div class="toolbar-left">
            <?php if (hasPerm('add')): ?>
            <button class="btn btn-primary" data-action="open-project-form">
                <span>＋</span> Add New Project
            </button>
            <?php endif; ?>

            <?php if (hasPerm('edit')): ?>
            <button class="btn btn-soft" data-action="open-update-modal">
                <span>🛠</span> Update Project
            </button>
            <?php endif; ?>

            <?php if ($isAdmin): ?>
            <button class="btn btn-priorities" data-action="open-tasks">
                <span>✅</span> Tasks
            </button>
            <button class="btn btn-soft" data-action="open-archived-projects" title="Restore archived projects">
                <span>🗄</span> Archive
            </button>
            <button class="btn btn-soft" data-action="open-reports" title="Reports">
                <span>📊</span> Reports
            </button>
            <button class="btn btn-soft" data-action="open-employees" title="Employee statistics">
                <span>🏆</span> Employees
            </button>
            <?php endif; ?>

            <button class="btn btn-secondary" data-action="refresh-projects">
                <span>↻</span> Refresh
            </button>
        </div>

        <div class="toolbar-right">
            <div class="search-wrap">
                <span class="search-ico">🔍</span>
                <input type="text" id="searchInput" placeholder="Search by name, client, zone..." data-action="project-search-input">
                <button class="search-go" data-action="search-projects">Search</button>
            </div>

            <?php if ($isAdmin): ?>
            <button class="settings-float" data-action="open-users-modal" title="Users Management" style="background:linear-gradient(135deg,#10b981,#059669); margin-right:8px;">
                👥
            </button>
            <?php endif; ?>

            <?php if (hasPerm('settings')): ?>
            <button class="settings-float" data-action="open-settings" title="Settings">
                ⚙️
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<main class="main-content">
    <div class="table-card">
        <div class="table-card-header">
            <h2>📋 Projects</h2>
            <span class="badge-count" id="projectCount">0 projects</span>
        </div>

        <div class="table-responsive">
            <table class="proj-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Project Name</th>
                        <th>Client</th>
                        <th class="hide-sm">Zone</th>
                        <th class="hide-sm">Lead Engineer</th>
                        <th>Overall Progress</th>
                        <th>Status</th>
                        <th>Section Progress</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="projectsTableBody"></tbody>
            </table>
        </div>

        <div class="empty-box" id="emptyState" style="display:none;">
            <div class="empty-ico"></div>
            <h3>No Projects Yet</h3>
            <p>Click the button below to add your first project</p>
            <?php if (hasPerm('add')): ?>
            <button class="btn btn-primary" data-action="open-project-form">＋ Add New Project</button>
            <?php endif; ?>
        </div>
    </div>
<!-- Footer -->
<footer class="app-footer">
    <div class="footer-glow-1"></div>
    <div class="footer-glow-2"></div>
    <div class="footer-glow-3"></div>
    
    <div class="footer-container">
        
        <!-- Logo Center -->
        <div class="footer-logo-wrapper">
            <div class="footer-logo-ring">
                <?php if ($logoExists): ?>
                    <img src="logo.png" alt="NawAra Studio" class="footer-main-logo">
                <?php else: ?>
                    <div class="footer-main-logo-fallback">🏗</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Brand Name -->
        <div class="footer-brand-section">
            <h2 class="footer-brand-name"><?php echo htmlspecialchars(APP_NAME); ?></h2>
            <p class="footer-tagline"><?php echo htmlspecialchars(APP_SUBTITLE); ?></p>
        </div>
        
        <!-- Social Media Icons -->
        <div class="footer-social-wrapper">
            <a href="#" target="_blank" class="social-btn social-fb" title="Facebook">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
            </a>
            
            <a href="#" target="_blank" class="social-btn social-ig" title="Instagram">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>
                </svg>
            </a>
            
            <a href="mailto:info@nawarastudio.com" class="social-btn social-mail" title="Email">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 12.713l-11.985-9.713h23.97l-11.985 9.713zm-5.425-1.822l-6.575-5.329v12.501l6.575-7.172zm10.85 0l6.575 7.172v-12.501l-6.575 5.329zm-1.557 1.261l-3.868 3.135-3.868-3.135-8.11 8.848h23.956l-8.11-8.848z"/>
                </svg>
            </a>
        </div>
        
        <!-- Divider Line -->
        <div class="footer-line"></div>
        
        <!-- Bottom Info -->
        <div class="footer-bottom">
            <div class="footer-copyright-text">
                © <?php echo date('Y'); ?> <strong>NawAra Studio</strong>. All rights reserved.
            </div>
            
            <div class="footer-meta">
                <span class="footer-version-badge">
                    <span class="version-live-dot"></span>
                    v<?php echo htmlspecialchars(APP_VERSION); ?>
                </span>
            </div>
        </div>
        
    </div>
</footer>
</main>

<!-- Project Modal -->
<div class="overlay" id="projectModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2 id="modalTitle">Add New Project</h2>
            <button class="modal-x" data-action="close-project-form">✕</button>
        </div>
        <div class="modal-body">
            <form id="projectForm" data-action="project-form">
                <input type="hidden" id="projectId" value="0">
                <div class="fsec">
                    <div class="fsec-head no-click">
                        <span>📝</span>
                        <h3>Project Information</h3>
                    </div>
                    <div class="fgrid">
                        <div class="fg"><label>Project Name <span class="req">*</span></label><input type="text" id="projectName"></div>
                        <div class="fg"><label>Client Name <span class="req">*</span></label><input type="text" id="clientName"></div>
                        <div class="fg"><label>Zone</label><input type="text" id="zone"></div>
                        <div class="fg"><label>Lead Engineer</label><select id="leadEngineer"></select></div>
                        <div class="fg"><label>Start Date</label><input type="date" id="startDate"></div>
                        <div class="fg"><label>End Date</label><input type="date" id="endDate"></div>
                        <div class="fg"><label>Progress Engine</label>
                            <select id="progressMode">
                                <option value="task_driven">Task-driven (automatic)</option>
                                <option value="manual">Manual (status-based)</option>
                            </select>
                        </div>
                        <div class="fg"><label>Contract Value <small style="color:#94a3b8;">(optional — enter later)</small></label>
                            <input type="number" id="contractValue" min="0" step="0.01" placeholder="0 = not set">
                        </div>
                        <div class="fg full"><label>Description</label><textarea id="description" rows="3"></textarea></div>
                    </div>
                </div>
                <div id="dynamicSections"></div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-project-form">Cancel</button>
            <button class="btn btn-primary" data-action="save-project">💾 Save Project</button>
        </div>
    </div>
</div>

<!-- Update Modal -->
<div class="overlay" id="updateModal">
    <div class="modal modal-sm">
        <div class="modal-head">
            <h2>🛠 Update Project</h2>
            <button class="modal-x" data-action="close-overlay" data-overlay="updateModal">✕</button>
        </div>
        <div class="modal-body">
            <div class="fg">
                <label>Select Project</label>
                <select id="updateProjectSelect"></select>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-overlay" data-overlay="updateModal">Cancel</button>
            <button class="btn btn-primary" data-action="edit-selected-project">Open Edit</button>
        </div>
    </div>
</div>

<!-- Archived Projects Modal (Head Admin only) -->
<div class="overlay" id="archivedProjectsModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2>🗄 Archived Projects</h2>
            <button class="modal-x" type="button" data-action="close-overlay" data-overlay="archivedProjectsModal">✕</button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:14px;color:#64748b;">Restoring keeps the project’s history, access assignments, files, and audit trail.</p>
            <div class="table-responsive">
                <table class="proj-table">
                    <thead><tr><th>Project</th><th>Client</th><th>Archived</th><th>Reason</th><th>Action</th></tr></thead>
                    <tbody id="archivedProjectsTableBody"></tbody>
                </table>
            </div>
            <div class="files-empty" id="archivedProjectsEmpty" style="display:none;">No archived projects.</div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" type="button" data-action="close-overlay" data-overlay="archivedProjectsModal">Close</button>
        </div>
    </div>
</div>

<!-- Detail Modal -->
<div class="overlay" id="detailModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2 id="detailTitle">Project Details</h2>
            <button class="modal-x" data-action="close-detail">✕</button>
        </div>
        <div class="modal-body" id="detailBody"></div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-detail">Close</button>
            <button class="btn btn-primary" id="detailEditBtn">✏️ Edit</button>
        </div>
    </div>
</div>

<!-- Reports Modal (admin) -->
<div class="overlay" id="reportsModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2>📊 Reports — راپورها</h2>
            <button class="modal-x" data-action="close-overlay" data-overlay="reportsModal">✕</button>
        </div>
        <div class="modal-body">
            <div class="rep-toolbar">
                <div class="fg"><label>From</label><input type="date" id="repFrom"></div>
                <div class="fg"><label>To</label><input type="date" id="repTo"></div>
                <div class="fg"><label>Project</label><select id="repProject"><option value="0">All projects</option></select></div>
                <button class="btn btn-primary" data-action="rep-generate">🔎 Generate</button>
                <button class="btn btn-secondary" data-action="rep-print">🖨 Print</button>
            </div>
            <div id="repBody" class="rep-body"><div class="tk-muted">Pick a range and press Generate.</div></div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-overlay" data-overlay="reportsModal">Close</button>
        </div>
    </div>
</div>

<!-- Employees Statistics Modal (admin) -->
<div class="overlay" id="employeesModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2>🏆 Employees — آمار کارمندان</h2>
            <button class="modal-x" data-action="close-overlay" data-overlay="employeesModal">✕</button>
        </div>
        <div class="modal-body">
            <div class="emp-tabs">
                <button class="tab-btn active" data-action="emp-tab" data-tab="people">👥 People</button>
                <button class="tab-btn" data-action="emp-tab" data-tab="progress">📈 Project Progress</button>
            </div>
            <div id="empPeople" class="emp-panel"></div>
            <div id="empProgress" class="emp-panel" style="display:none;"></div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-overlay" data-overlay="employeesModal">Close</button>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="overlay" id="settingsModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2>⚙️ Settings</h2>
            <button class="modal-x" data-action="close-overlay" data-overlay="settingsModal">✕</button>
        </div>
        <div class="modal-body">
            <div class="settings-tabs">
                <button class="tab-btn active" data-action="show-settings-tab" data-tab="engineers">Engineers</button>
                <button class="tab-btn" data-action="show-settings-tab" data-tab="statuses">Statuses</button>
                <button class="tab-btn" data-action="show-settings-tab" data-tab="sections">Sections & Weights</button>
            </div>
            <div class="settings-panel active" id="settings-engineers"></div>
            <div class="settings-panel" id="settings-statuses"></div>
            <div class="settings-panel" id="settings-sections"></div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-overlay" data-overlay="settingsModal">Close</button>
        </div>
    </div>
</div>

<!-- Files Modal -->
<div class="overlay" id="filesModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2 id="filesTitle">📁 Project Files</h2>
            <button class="modal-x" data-action="close-files-modal">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="filesProjectId" value="0">
            <input type="hidden" id="filesCurrentPath" value="">
            <div class="fm-toolbar">
                <button class="btn btn-primary btn-sm" data-action="file-create-folder">📂 New Folder</button>
                <label class="btn btn-soft btn-sm" for="fmUploadInput">⬆ Upload Files</label>
                <input id="fmUploadInput" type="file" multiple style="display:none;" data-action="file-upload-input">
                <button class="btn btn-secondary btn-sm" data-action="file-reload">↻ Refresh</button>
                <button class="btn btn-secondary btn-sm" data-action="file-home">🏠 Home</button>
            </div>
            <div class="fm-breadcrumbs" id="fmBreadcrumbs"></div>
            <div id="fmContent" class="fm-content"></div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-files-modal">Close</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/tasks_ui_markup.php'; tasks_ui_markup(); ?>

<!-- Users Modal -->
<div class="overlay" id="usersModal">
    <div class="modal modal-lg">
        <div class="modal-head">
            <h2>👥 User Management</h2>
            <button class="modal-x" data-action="close-overlay" data-overlay="usersModal">✕</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom:16px;">
                <button class="btn btn-primary" data-action="open-user-form">＋ Add New User</button>
            </div>
            <table class="proj-table">
                <thead><tr><th>Name</th><th>Username</th><th>Role / Group</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="usersTableBody"></tbody>
            </table>
        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-overlay" data-overlay="usersModal">Close</button>
        </div>
    </div>
</div>

<!-- User Form Modal -->
<div class="overlay" id="userFormModal">
    <div class="modal modal-xl">
        <div class="modal-head">
            <h2>👤 User Details</h2>
            <button class="modal-x" data-action="close-overlay" data-overlay="userFormModal">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="u_id" value="0">

            <div class="settings-tabs" style="margin-bottom:20px;">
                <button class="tab-btn active" data-action="show-user-tab" data-tab="info">👤 Info</button>
                <button class="tab-btn" data-action="show-user-tab" data-tab="perms">🔐 Permissions</button>
            </div>

            <!-- Tab: Info -->
            <div id="usertab-info" class="user-tab active">
                <div class="fgrid">
                    <div class="fg"><label>Full Name</label><input type="text" id="u_name"></div>
                    <div class="fg"><label>Username</label><input type="text" id="u_username"></div>
                    <div class="fg"><label>Password <small style="color:#94a3b8;">(minimum 10 characters; leave empty if no change)</small></label><input type="password" id="u_password" minlength="10"></div>
                    <div class="fg">
                        <label>System Role</label>
                        <select id="u_role" data-action="toggle-permissions">
                            <option value="user">Employee / Restricted User</option>
                            <option value="admin">Admin / Head Engineer (Full Access)</option>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Account Group</label>
                        <select id="u_account_type" data-action="toggle-permissions">
                            <option value="admin">Admin / Head Engineer</option>
                            <option value="employee">Employee</option>
                            <option value="client">Client / Project Owner</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:12px; padding:12px; background:#fef3c7; border-radius:10px; font-size:.85rem; color:#92400e;">
                    💡 Save the user first, then use the Permissions tab to assign projects. Client accounts are deliberately isolated from the legacy internal workspace until the publish-gated client portal is delivered.
                </div>
            </div>

            <!-- Tab: Permissions -->
            <div id="usertab-perms" class="user-tab">
                <div id="u_perms_box">
                    <!-- بخش 1: See All Projects -->
                    <div style="background:#fef3c7; border:2px solid #f59e0b; padding:16px; border-radius:14px; margin-bottom:20px;">
                        <label style="display:flex; gap:10px; cursor:pointer; align-items:flex-start;">
                            <input type="checkbox" id="p_view_all_projects" data-action="toggle-project-list" style="width:20px;height:20px;">
                            <div>
                                <div style="font-weight:900;">👁 See All Projects</div>
                                <div style="font-size:.85rem; color:#92400e; margin-top:4px;">
                                    اگر تیک بزنی، این کاربر همه پروژه‌ها را می‌بیند.<br>
                                    اگر تیک نزنی، فقط پروژه‌های انتخاب شده در پایین را می‌بیند.
                                </div>
                            </div>
                        </label>
                    </div>

                    <!-- بخش 2: Specific Projects -->
                    <div id="specificProjectsBox" style="background:#dbeafe; border:2px solid #3b82f6; padding:16px; border-radius:14px; margin-bottom:20px;">
                        <h3 style="margin-bottom:12px; color:#1e40af;">📌 Specific Projects Access</h3>
                        <p style="color:#1e40af; margin-bottom:12px; font-size:.85rem;">
                            انتخاب کن این کاربر به کدام پروژه‌ها دسترسی داشته باشد:
                        </p>
                        <div id="specificProjectsList" style="max-height:200px; overflow-y:auto; background:white; padding:12px; border-radius:10px;">
                            <div style="color:#94a3b8; text-align:center;">Loading...</div>
                        </div>
                    </div>

                    <!-- بخش 3: Access Permissions -->
                    <div style="background:#f0fdf4; border:2px solid #10b981; padding:16px; border-radius:14px;">
                        <h3 style="margin-bottom:12px; color:#065f46;">✅ Access Permissions:</h3>
                        <div class="perms-grid">
                            <label class="perm-item"><input type="checkbox" id="p_add"><span class="perm-title">➕ Add Project</span></label>
                            <label class="perm-item"><input type="checkbox" id="p_edit"><span class="perm-title">✏️ Edit Projects</span></label>
                            <label class="perm-item"><input type="checkbox" id="p_delete"><span class="perm-title">🗑 Delete Projects</span></label>
                            <label class="perm-item"><input type="checkbox" id="p_print"><span class="perm-title">🖨 Print Reports</span></label>
                            <label class="perm-item"><input type="checkbox" id="p_pdf"><span class="perm-title">📄 Download PDF</span></label>
                            <label class="perm-item"><input type="checkbox" id="p_files"><span class="perm-title">📁 Manage Files</span></label>
                            <label class="perm-item"><input type="checkbox" id="p_priorities"><span class="perm-title">🎯 Priorities</span></label>
                            <label class="perm-item"><input type="checkbox" id="p_settings"><span class="perm-title">⚙️ Settings</span></label>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="modal-foot">
            <button class="btn btn-secondary" data-action="close-overlay" data-overlay="userFormModal">Cancel</button>
            <button class="btn btn-primary" data-action="save-user">💾 Save User</button>
        </div>
    </div>
</div>

<div id="toastBox"></div>
<div id="loadingBox"><div class="spin-wrap"><div class="spin"></div><p>Loading...</p></div></div>

<script src="tasks-ui.js?v=<?php echo app_asset_version('tasks-ui.js'); ?>"></script>
<script src="extras-ui.js?v=<?php echo app_asset_version('extras-ui.js'); ?>"></script>
<script src="nik-ui.js?v=<?php echo app_asset_version('nik-ui.js'); ?>"></script>
<script src="script.js?v=<?php echo app_asset_version('script.js'); ?>"></script>
</body>
</html>