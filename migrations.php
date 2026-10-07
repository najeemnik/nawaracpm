<?php
/**
 * Versioned database migrations for NawAra CPM.
 *
 * Migrations are additive and transaction-wrapped. Destructive legacy cleanup is
 * deliberately kept out of automatic migrations until a reviewed backup and a
 * data-repair plan are approved.
 */

function dbTableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $allowedTables = ['pm_users'];
    if (!in_array($table, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported migration table');
    }

    $stmt = $pdo->query("PRAGMA table_info({$table})");
    foreach ($stmt->fetchAll() as $row) {
        if (($row['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

function dbAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!dbTableHasColumn($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function ensureMigrationLedger(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_schema_migrations (
            migration_id TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
}

function taskFoundationMigration(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_project_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            membership_role TEXT NOT NULL DEFAULT 'employee'
                CHECK(membership_role IN ('project_admin','employee','client')),
            permissions TEXT NOT NULL DEFAULT '{}',
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
            created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(project_id, user_id),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_project_members_user_project
            ON pm_project_members(user_id, project_id, active);
        CREATE INDEX IF NOT EXISTS idx_pm_project_members_project
            ON pm_project_members(project_id, active);
    ");

    // Copy only valid legacy access records. The legacy table currently has no
    // foreign keys, so an orphan record must never be copied into the new model.
    $pdo->exec("
        INSERT OR IGNORE INTO pm_project_members
            (project_id, user_id, membership_role, permissions, active, created_at, updated_at)
        SELECT
            a.project_id,
            a.user_id,
            CASE WHEN u.role = 'admin' THEN 'project_admin' ELSE 'employee' END,
            '{' || char(34) || 'view_project' || char(34) || ':' ||
                CASE WHEN a.can_view = 1 THEN 'true' ELSE 'false' END || ',' ||
                char(34) || 'edit_tasks' || char(34) || ':' ||
                CASE WHEN a.can_edit = 1 THEN 'true' ELSE 'false' END || ',' ||
                char(34) || 'update_any_task' || char(34) || ':' ||
                CASE WHEN a.can_edit = 1 THEN 'true' ELSE 'false' END || ',' ||
                char(34) || 'view_reports' || char(34) || ':' ||
                CASE WHEN (a.can_print = 1 OR a.can_pdf = 1) THEN 'true' ELSE 'false' END || ',' ||
                char(34) || 'view_files' || char(34) || ':' ||
                CASE WHEN a.can_files = 1 THEN 'true' ELSE 'false' END || '}',
            1,
            a.created_at,
            a.created_at
        FROM pm_project_access a
        INNER JOIN pm_users u ON u.id = a.user_id AND u.active = 1
        INNER JOIN pm_projects p ON p.id = a.project_id
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            section_id INTEGER NULL,
            item_id INTEGER NULL,
            parent_task_id INTEGER NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'draft'
                CHECK(status IN (
                    'draft','assigned','in_progress','blocked',
                    'submitted_for_review','revision_requested',
                    'awaiting_client_approval','completed','cancelled'
                )),
            priority TEXT NOT NULL DEFAULT 'medium'
                CHECK(priority IN ('critical','high','medium','low')),
            assignment_mode TEXT NOT NULL DEFAULT 'single'
                CHECK(assignment_mode IN ('single','shared','all_assignees_required')),
            review_required INTEGER NOT NULL DEFAULT 1 CHECK(review_required IN (0,1)),
            require_file_on_submit INTEGER NOT NULL DEFAULT 0 CHECK(require_file_on_submit IN (0,1)),
            require_comment_on_submit INTEGER NOT NULL DEFAULT 0 CHECK(require_comment_on_submit IN (0,1)),
            notify_admin_on_complete INTEGER NOT NULL DEFAULT 1 CHECK(notify_admin_on_complete IN (0,1)),
            affects_project_progress INTEGER NOT NULL DEFAULT 0 CHECK(affects_project_progress IN (0,1)),
            progress_weight REAL NOT NULL DEFAULT 0 CHECK(progress_weight >= 0),
            client_visible INTEGER NOT NULL DEFAULT 0 CHECK(client_visible IN (0,1)),
            client_approval_required INTEGER NOT NULL DEFAULT 0 CHECK(client_approval_required IN (0,1)),
            client_comments_enabled INTEGER NOT NULL DEFAULT 0 CHECK(client_comments_enabled IN (0,1)),
            client_files_downloadable INTEGER NOT NULL DEFAULT 0 CHECK(client_files_downloadable IN (0,1)),
            client_notify_on_publish INTEGER NOT NULL DEFAULT 1 CHECK(client_notify_on_publish IN (0,1)),
            client_approval_status TEXT NOT NULL DEFAULT 'not_requested'
                CHECK(client_approval_status IN (
                    'not_requested','not_published','awaiting_approval',
                    'approved','changes_requested'
                )),
            start_at TEXT NOT NULL DEFAULT '',
            due_at TEXT NOT NULL DEFAULT '',
            estimated_minutes INTEGER NOT NULL DEFAULT 0 CHECK(estimated_minutes >= 0),
            completed_at TEXT NOT NULL DEFAULT '',
            submitted_at TEXT NOT NULL DEFAULT '',
            published_to_client_at TEXT NOT NULL DEFAULT '',
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            version INTEGER NOT NULL DEFAULT 1 CHECK(version >= 1),
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(section_id) REFERENCES pm_sections(id) ON DELETE SET NULL,
            FOREIGN KEY(item_id) REFERENCES pm_section_items(id) ON DELETE SET NULL,
            FOREIGN KEY(parent_task_id) REFERENCES pm_tasks(id) ON DELETE SET NULL,
            FOREIGN KEY(created_by) REFERENCES pm_users(id) ON DELETE SET NULL,
            FOREIGN KEY(updated_by) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_tasks_project_status_due
            ON pm_tasks(project_id, status, due_at);
        CREATE INDEX IF NOT EXISTS idx_pm_tasks_section
            ON pm_tasks(section_id, item_id);
        CREATE INDEX IF NOT EXISTS idx_pm_tasks_client_approval
            ON pm_tasks(client_approval_status, due_at);

        CREATE TABLE IF NOT EXISTS pm_task_assignees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            assignment_role TEXT NOT NULL DEFAULT 'contributor'
                CHECK(assignment_role IN ('responsible','contributor')),
            required_to_submit INTEGER NOT NULL DEFAULT 1 CHECK(required_to_submit IN (0,1)),
            member_status TEXT NOT NULL DEFAULT 'assigned'
                CHECK(member_status IN ('assigned','in_progress','blocked','submitted','completed')),
            started_at TEXT NOT NULL DEFAULT '',
            submitted_at TEXT NOT NULL DEFAULT '',
            completed_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(task_id, user_id),
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_pm_task_assignees_user_status
            ON pm_task_assignees(user_id, member_status, task_id);

        CREATE TABLE IF NOT EXISTS pm_task_reviewers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            required INTEGER NOT NULL DEFAULT 1 CHECK(required IN (0,1)),
            decision TEXT NOT NULL DEFAULT 'pending'
                CHECK(decision IN ('pending','approved','revision_requested')),
            decision_comment TEXT NOT NULL DEFAULT '',
            decided_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(task_id, user_id),
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_pm_task_reviewers_user_decision
            ON pm_task_reviewers(user_id, decision, task_id);

        CREATE TABLE IF NOT EXISTS pm_task_client_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            approval_required INTEGER NOT NULL DEFAULT 0 CHECK(approval_required IN (0,1)),
            comments_enabled INTEGER NOT NULL DEFAULT 0 CHECK(comments_enabled IN (0,1)),
            decision TEXT NOT NULL DEFAULT 'not_requested'
                CHECK(decision IN ('not_requested','awaiting_approval','approved','changes_requested')),
            decision_comment TEXT NOT NULL DEFAULT '',
            published_at TEXT NOT NULL DEFAULT '',
            decided_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(task_id, user_id),
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_pm_task_client_recipients_user_decision
            ON pm_task_client_recipients(user_id, decision, task_id);

        CREATE TABLE IF NOT EXISTS pm_task_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            author_user_id INTEGER NULL,
            visibility TEXT NOT NULL DEFAULT 'internal'
                CHECK(visibility IN ('internal','client')),
            body TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(author_user_id) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_task_comments_task_created
            ON pm_task_comments(task_id, created_at);

        CREATE TABLE IF NOT EXISTS pm_task_checklist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            is_done INTEGER NOT NULL DEFAULT 0 CHECK(is_done IN (0,1)),
            sort_order INTEGER NOT NULL DEFAULT 0,
            completed_by INTEGER NULL,
            completed_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(completed_by) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_task_checklist_task_order
            ON pm_task_checklist_items(task_id, sort_order, id);

        CREATE TABLE IF NOT EXISTS pm_task_dependencies (
            task_id INTEGER NOT NULL,
            depends_on_task_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            PRIMARY KEY(task_id, depends_on_task_id),
            CHECK(task_id <> depends_on_task_id),
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(depends_on_task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS pm_task_activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            task_id INTEGER NOT NULL,
            actor_user_id INTEGER NULL,
            event_type TEXT NOT NULL,
            previous_value TEXT NOT NULL DEFAULT '{}',
            new_value TEXT NOT NULL DEFAULT '{}',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_task_activity_task_created
            ON pm_task_activity(task_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_pm_task_activity_project_created
            ON pm_task_activity(project_id, created_at);
    ");
}

function taskAttachmentFoundationMigration(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_task_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            uploaded_by_user_id INTEGER NULL,
            original_name TEXT NOT NULL,
            storage_key TEXT NOT NULL UNIQUE,
            content_type TEXT NOT NULL DEFAULT 'application/octet-stream',
            byte_size INTEGER NOT NULL DEFAULT 0 CHECK(byte_size >= 0),
            sha256 TEXT NOT NULL DEFAULT '',
            visibility TEXT NOT NULL DEFAULT 'internal'
                CHECK(visibility IN ('internal','client')),
            client_downloadable INTEGER NOT NULL DEFAULT 0 CHECK(client_downloadable IN (0,1)),
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            deleted_at TEXT NOT NULL DEFAULT '',
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(uploaded_by_user_id) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_task_attachments_task_visibility
            ON pm_task_attachments(task_id, visibility, deleted_at);
    ");
}

function notificationFoundationMigration(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_notification_preferences (
            user_id INTEGER PRIMARY KEY,
            in_app_enabled INTEGER NOT NULL DEFAULT 1 CHECK(in_app_enabled IN (0,1)),
            sound_enabled INTEGER NOT NULL DEFAULT 1 CHECK(sound_enabled IN (0,1)),
            push_enabled INTEGER NOT NULL DEFAULT 0 CHECK(push_enabled IN (0,1)),
            quiet_hours_start TEXT NOT NULL DEFAULT '',
            quiet_hours_end TEXT NOT NULL DEFAULT '',
            timezone TEXT NOT NULL DEFAULT 'Asia/Kabul',
            event_preferences TEXT NOT NULL DEFAULT '{}',
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS pm_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_user_id INTEGER NOT NULL,
            project_id INTEGER NULL,
            task_id INTEGER NULL,
            event_type TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            priority TEXT NOT NULL DEFAULT 'normal'
                CHECK(priority IN ('low','normal','high','critical')),
            deep_link TEXT NOT NULL DEFAULT '',
            read_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(recipient_user_id) REFERENCES pm_users(id) ON DELETE CASCADE,
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_pm_notifications_recipient_read_created
            ON pm_notifications(recipient_user_id, read_at, created_at DESC);

        CREATE TABLE IF NOT EXISTS pm_push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            endpoint TEXT NOT NULL UNIQUE,
            public_key TEXT NOT NULL,
            auth_key TEXT NOT NULL,
            user_agent TEXT NOT NULL DEFAULT '',
            last_seen_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            revoked_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_pm_push_subscriptions_user_active
            ON pm_push_subscriptions(user_id, revoked_at);

        CREATE TABLE IF NOT EXISTS pm_notification_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notification_id INTEGER NOT NULL,
            channel TEXT NOT NULL CHECK(channel IN ('in_app','push','email','sms','whatsapp','telegram')),
            delivery_status TEXT NOT NULL DEFAULT 'pending'
                CHECK(delivery_status IN ('pending','sent','failed','skipped')),
            attempts INTEGER NOT NULL DEFAULT 0 CHECK(attempts >= 0),
            delivered_at TEXT NOT NULL DEFAULT '',
            failure_reason TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(notification_id) REFERENCES pm_notifications(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_pm_notification_deliveries_status
            ON pm_notification_deliveries(delivery_status, created_at);
    ");
}

function auditFoundationMigration(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_user_id INTEGER NULL,
            project_id INTEGER NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NULL,
            action TEXT NOT NULL,
            details TEXT NOT NULL DEFAULT '{}',
            ip_address TEXT NOT NULL DEFAULT '',
            user_agent TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(actor_user_id) REFERENCES pm_users(id) ON DELETE SET NULL,
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_audit_log_project_created
            ON pm_audit_log(project_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_pm_audit_log_entity
            ON pm_audit_log(entity_type, entity_id, created_at);
    ");
}

function loginSecurityMigration(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username_hash TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            succeeded INTEGER NOT NULL DEFAULT 0 CHECK(succeeded IN (0,1)),
            attempted_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE INDEX IF NOT EXISTS idx_pm_login_attempts_username_window
            ON pm_login_attempts(username_hash, succeeded, attempted_at);
        CREATE INDEX IF NOT EXISTS idx_pm_login_attempts_ip_window
            ON pm_login_attempts(ip_hash, succeeded, attempted_at);
    ");
}

function getDatabaseMigrations(): array
{
    return [
        '20261008_001_user_security_metadata' => static function (PDO $pdo): void {
            dbAddColumnIfMissing($pdo, 'pm_users', 'account_type', "TEXT NOT NULL DEFAULT 'employee'");
            dbAddColumnIfMissing($pdo, 'pm_users', 'auth_version', 'INTEGER NOT NULL DEFAULT 1');
            dbAddColumnIfMissing($pdo, 'pm_users', 'updated_at', "TEXT NOT NULL DEFAULT ''");
            dbAddColumnIfMissing($pdo, 'pm_users', 'last_login_at', "TEXT NOT NULL DEFAULT ''");
            dbAddColumnIfMissing($pdo, 'pm_users', 'password_changed_at', "TEXT NOT NULL DEFAULT ''");

            $pdo->exec("
                UPDATE pm_users
                SET account_type = CASE WHEN role = 'admin' THEN 'admin' ELSE 'employee' END
                WHERE account_type = '' OR account_type = 'employee'
            ");
            $pdo->exec("
                UPDATE pm_users
                SET updated_at = created_at
                WHERE updated_at = ''
            ");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pm_users_active_type ON pm_users(active, account_type)');
        },
        '20261008_002_project_members_foundation' => 'taskFoundationMigration',
        '20261008_003_notification_foundation' => 'notificationFoundationMigration',
        '20261008_004_audit_foundation' => 'auditFoundationMigration',
        '20261008_005_login_security' => 'loginSecurityMigration',
        '20261008_006_task_attachment_foundation' => 'taskAttachmentFoundationMigration',
    ];
}

function runDatabaseMigrations(PDO $pdo): void
{
    ensureMigrationLedger($pdo);

    $applied = $pdo->query('SELECT migration_id FROM pm_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $appliedMap = array_fill_keys($applied, true);

    foreach (getDatabaseMigrations() as $migrationId => $migration) {
        if (isset($appliedMap[$migrationId])) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $migration($pdo);
            $stmt = $pdo->prepare('INSERT INTO pm_schema_migrations(migration_id) VALUES(:migration_id)');
            $stmt->execute(['migration_id' => $migrationId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
