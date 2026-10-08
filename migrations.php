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
    $allowedTables = ['pm_users', 'pm_projects', 'pm_audit_log', 'pm_priorities', 'pm_tasks', 'pm_project_values'];
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

/**
 * Phase 2.5 security remediation. This migration is additive: it establishes
 * archival/version columns, canonical membership compatibility, useful indexes
 * and database-level guards for future task APIs without deleting legacy data.
 */
function securityRemediationMigration(PDO $pdo): void
{
    dbAddColumnIfMissing($pdo, 'pm_projects', 'owner_user_id', 'INTEGER NULL');
    dbAddColumnIfMissing($pdo, 'pm_projects', 'updated_by', 'INTEGER NULL');
    dbAddColumnIfMissing($pdo, 'pm_projects', 'version', 'INTEGER NOT NULL DEFAULT 1');
    dbAddColumnIfMissing($pdo, 'pm_projects', 'deleted_at', "TEXT NOT NULL DEFAULT ''");
    dbAddColumnIfMissing($pdo, 'pm_projects', 'deleted_by', 'INTEGER NULL');
    dbAddColumnIfMissing($pdo, 'pm_projects', 'deletion_reason', "TEXT NOT NULL DEFAULT ''");
    dbAddColumnIfMissing($pdo, 'pm_audit_log', 'request_id', "TEXT NOT NULL DEFAULT ''");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_pm_projects_active_updated
            ON pm_projects(deleted_at, updated_at DESC, id DESC);
        CREATE INDEX IF NOT EXISTS idx_pm_project_access_project
            ON pm_project_access(project_id);
        CREATE INDEX IF NOT EXISTS idx_pm_login_attempts_attempted_at
            ON pm_login_attempts(attempted_at);
    ");

    // The legacy access table remains a compatibility mirror only. Prevent any
    // new orphan rows while preserving legacy data for operator-led review.
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_pm_project_access_valid_insert
        BEFORE INSERT ON pm_project_access
        FOR EACH ROW
        WHEN NOT EXISTS (SELECT 1 FROM pm_users WHERE id = NEW.user_id)
          OR NOT EXISTS (SELECT 1 FROM pm_projects WHERE id = NEW.project_id)
        BEGIN
            SELECT RAISE(ABORT, 'Project access requires an existing user and project');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_project_access_valid_update
        BEFORE UPDATE OF user_id, project_id ON pm_project_access
        FOR EACH ROW
        WHEN NOT EXISTS (SELECT 1 FROM pm_users WHERE id = NEW.user_id)
          OR NOT EXISTS (SELECT 1 FROM pm_projects WHERE id = NEW.project_id)
        BEGIN
            SELECT RAISE(ABORT, 'Project access requires an existing user and project');
        END;
    ");

    // Add canonical legacy permissions to memberships created by migration 002.
    // This does not overwrite a project-specific permission that was already
    // deliberately set in pm_project_members.
    $members = $pdo->query("
        SELECT m.id, m.membership_role, m.permissions,
               a.can_view, a.can_edit, a.can_delete, a.can_print, a.can_pdf, a.can_files
        FROM pm_project_members m
        LEFT JOIN pm_project_access a
          ON a.project_id = m.project_id AND a.user_id = m.user_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $updateMember = $pdo->prepare("
        UPDATE pm_project_members
        SET permissions = :permissions, updated_at = datetime('now','localtime')
        WHERE id = :id
    ");
    foreach ($members as $member) {
        $permissions = json_decode((string)($member['permissions'] ?? '{}'), true);
        if (!is_array($permissions)) {
            $permissions = [];
        }
        if ($member['can_view'] !== null) {
            $permissions['view_project'] = (int)$member['can_view'] === 1;
            $permissions['edit_project'] = (int)$member['can_edit'] === 1;
            $permissions['delete_project'] = (int)$member['can_delete'] === 1;
            $permissions['print_reports'] = (int)$member['can_print'] === 1;
            $permissions['download_reports'] = (int)$member['can_pdf'] === 1;
            $permissions['view_files'] = (int)$member['can_files'] === 1;
            $permissions['manage_files'] = (int)$member['can_files'] === 1;
        } elseif (($member['membership_role'] ?? '') !== 'client') {
            $permissions['view_project'] = $permissions['view_project'] ?? true;
        }
        $encoded = json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to normalize project membership permissions');
        }
        $updateMember->execute(['permissions' => $encoded, 'id' => (int)$member['id']]);
    }

    // Relational invariants for the task foundation. The task API is added in a
    // later phase, but malformed cross-project objects must be impossible now.
    $pdo->exec("
        CREATE TRIGGER IF NOT EXISTS trg_pm_tasks_item_section_insert
        BEFORE INSERT ON pm_tasks
        FOR EACH ROW
        WHEN NEW.item_id IS NOT NULL AND (
            NEW.section_id IS NULL OR NOT EXISTS (
                SELECT 1 FROM pm_section_items
                WHERE id = NEW.item_id AND section_id = NEW.section_id
            )
        )
        BEGIN
            SELECT RAISE(ABORT, 'Task item must belong to its task section');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_tasks_item_section_update
        BEFORE UPDATE OF item_id, section_id ON pm_tasks
        FOR EACH ROW
        WHEN NEW.item_id IS NOT NULL AND (
            NEW.section_id IS NULL OR NOT EXISTS (
                SELECT 1 FROM pm_section_items
                WHERE id = NEW.item_id AND section_id = NEW.section_id
            )
        )
        BEGIN
            SELECT RAISE(ABORT, 'Task item must belong to its task section');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_tasks_parent_project_insert
        BEFORE INSERT ON pm_tasks
        FOR EACH ROW
        WHEN NEW.parent_task_id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM pm_tasks parent
            WHERE parent.id = NEW.parent_task_id AND parent.project_id = NEW.project_id
        )
        BEGIN
            SELECT RAISE(ABORT, 'Parent task must belong to the same project');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_tasks_parent_project_update
        BEFORE UPDATE OF parent_task_id, project_id ON pm_tasks
        FOR EACH ROW
        WHEN NEW.parent_task_id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM pm_tasks parent
            WHERE parent.id = NEW.parent_task_id AND parent.project_id = NEW.project_id
        )
        BEGIN
            SELECT RAISE(ABORT, 'Parent task must belong to the same project');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_tasks_client_review_insert
        BEFORE INSERT ON pm_tasks
        FOR EACH ROW
        WHEN NEW.client_approval_required = 1 AND NEW.review_required <> 1
        BEGIN
            SELECT RAISE(ABORT, 'Client approval requires internal review');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_tasks_client_review_update
        BEFORE UPDATE OF client_approval_required, review_required ON pm_tasks
        FOR EACH ROW
        WHEN NEW.client_approval_required = 1 AND NEW.review_required <> 1
        BEGIN
            SELECT RAISE(ABORT, 'Client approval requires internal review');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_task_assignees_member_insert
        BEFORE INSERT ON pm_task_assignees
        FOR EACH ROW
        WHEN NOT EXISTS (
            SELECT 1
            FROM pm_tasks t
            INNER JOIN pm_users u ON u.id = NEW.user_id AND u.active = 1
            WHERE t.id = NEW.task_id
              AND (
                u.role = 'admin' OR EXISTS (
                    SELECT 1 FROM pm_project_members m
                    WHERE m.project_id = t.project_id AND m.user_id = NEW.user_id
                      AND m.active = 1 AND m.membership_role IN ('project_admin', 'employee')
                )
              )
        )
        BEGIN
            SELECT RAISE(ABORT, 'Task assignee must be an active internal project member');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_task_assignees_member_update
        BEFORE UPDATE OF task_id, user_id ON pm_task_assignees
        FOR EACH ROW
        WHEN NOT EXISTS (
            SELECT 1
            FROM pm_tasks t
            INNER JOIN pm_users u ON u.id = NEW.user_id AND u.active = 1
            WHERE t.id = NEW.task_id
              AND (
                u.role = 'admin' OR EXISTS (
                    SELECT 1 FROM pm_project_members m
                    WHERE m.project_id = t.project_id AND m.user_id = NEW.user_id
                      AND m.active = 1 AND m.membership_role IN ('project_admin', 'employee')
                )
              )
        )
        BEGIN
            SELECT RAISE(ABORT, 'Task assignee must be an active internal project member');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_task_reviewers_member_insert
        BEFORE INSERT ON pm_task_reviewers
        FOR EACH ROW
        WHEN NOT EXISTS (
            SELECT 1
            FROM pm_tasks t
            INNER JOIN pm_users u ON u.id = NEW.user_id AND u.active = 1
            WHERE t.id = NEW.task_id
              AND (
                u.role = 'admin' OR EXISTS (
                    SELECT 1 FROM pm_project_members m
                    WHERE m.project_id = t.project_id AND m.user_id = NEW.user_id
                      AND m.active = 1 AND m.membership_role IN ('project_admin', 'employee')
                )
              )
        )
        BEGIN
            SELECT RAISE(ABORT, 'Task reviewer must be an active internal project member');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_task_client_recipients_member_insert
        BEFORE INSERT ON pm_task_client_recipients
        FOR EACH ROW
        WHEN NOT EXISTS (
            SELECT 1
            FROM pm_tasks t
            INNER JOIN pm_users u ON u.id = NEW.user_id
            INNER JOIN pm_project_members m
              ON m.project_id = t.project_id AND m.user_id = NEW.user_id
            WHERE t.id = NEW.task_id
              AND u.active = 1
              AND u.account_type = 'client'
              AND m.active = 1
              AND m.membership_role = 'client'
        )
        BEGIN
            SELECT RAISE(ABORT, 'Task client recipient must be an active client project member');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_task_client_comments_published
        BEFORE INSERT ON pm_task_comments
        FOR EACH ROW
        WHEN NEW.visibility = 'client' AND NOT EXISTS (
            SELECT 1 FROM pm_tasks WHERE id = NEW.task_id AND client_visible = 1
        )
        BEGIN
            SELECT RAISE(ABORT, 'Client-visible comments require a published task');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_task_dependencies_same_project
        BEFORE INSERT ON pm_task_dependencies
        FOR EACH ROW
        WHEN (SELECT project_id FROM pm_tasks WHERE id = NEW.task_id)
             <> (SELECT project_id FROM pm_tasks WHERE id = NEW.depends_on_task_id)
        BEGIN
            SELECT RAISE(ABORT, 'Task dependencies must remain inside one project');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_task_dependencies_no_cycle
        BEFORE INSERT ON pm_task_dependencies
        FOR EACH ROW
        BEGIN
            SELECT RAISE(ABORT, 'Task dependency cycle is not allowed')
            WHERE EXISTS (
                WITH RECURSIVE dependency_tree(id) AS (
                    SELECT NEW.depends_on_task_id
                    UNION
                    SELECT d.depends_on_task_id
                    FROM pm_task_dependencies d
                    INNER JOIN dependency_tree tree ON tree.id = d.task_id
                )
                SELECT 1 FROM dependency_tree WHERE id = NEW.task_id
            );
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_users_keep_last_admin_on_update
        BEFORE UPDATE OF role, active ON pm_users
        FOR EACH ROW
        WHEN OLD.role = 'admin' AND OLD.active = 1
          AND (NEW.role <> 'admin' OR NEW.active <> 1)
          AND (SELECT COUNT(*) FROM pm_users WHERE role = 'admin' AND active = 1) <= 1
        BEGIN
            SELECT RAISE(ABORT, 'The last active admin cannot lose access');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_users_keep_last_admin_on_delete
        BEFORE DELETE ON pm_users
        FOR EACH ROW
        WHEN OLD.role = 'admin' AND OLD.active = 1
          AND (SELECT COUNT(*) FROM pm_users WHERE role = 'admin' AND active = 1) <= 1
        BEGIN
            SELECT RAISE(ABORT, 'The last active admin cannot be deleted');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_audit_log_append_only_update
        BEFORE UPDATE ON pm_audit_log
        FOR EACH ROW
        BEGIN
            SELECT RAISE(ABORT, 'Audit log is append-only');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_audit_log_append_only_delete
        BEFORE DELETE ON pm_audit_log
        FOR EACH ROW
        BEGIN
            SELECT RAISE(ABORT, 'Audit log is append-only');
        END;
    ");
}

/**
 * The legacy priority board remains available, but its data lifecycle must be
 * explicit and auditable rather than created ad hoc by a web request.
 */
function prioritiesIntegrityMigration(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_priorities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            assignee_id INTEGER NULL,
            priority TEXT NOT NULL DEFAULT 'medium'
                CHECK(priority IN ('critical','high','medium','low')),
            is_done INTEGER NOT NULL DEFAULT 0 CHECK(is_done IN (0,1)),
            due_date TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            completed_at TEXT NOT NULL DEFAULT '',
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            version INTEGER NOT NULL DEFAULT 1,
            deleted_at TEXT NOT NULL DEFAULT '',
            deleted_by INTEGER NULL,
            FOREIGN KEY(assignee_id) REFERENCES pm_engineers(id) ON DELETE SET NULL,
            FOREIGN KEY(created_by) REFERENCES pm_users(id) ON DELETE SET NULL,
            FOREIGN KEY(updated_by) REFERENCES pm_users(id) ON DELETE SET NULL,
            FOREIGN KEY(deleted_by) REFERENCES pm_users(id) ON DELETE SET NULL
        );
    ");

    // Existing priority boards predate migrations, so preserve all rows while
    // adding provenance and archive metadata in place.
    dbAddColumnIfMissing($pdo, 'pm_priorities', 'created_by', 'INTEGER NULL');
    dbAddColumnIfMissing($pdo, 'pm_priorities', 'updated_by', 'INTEGER NULL');
    dbAddColumnIfMissing($pdo, 'pm_priorities', 'updated_at', "TEXT NOT NULL DEFAULT ''");
    dbAddColumnIfMissing($pdo, 'pm_priorities', 'version', 'INTEGER NOT NULL DEFAULT 1');
    dbAddColumnIfMissing($pdo, 'pm_priorities', 'deleted_at', "TEXT NOT NULL DEFAULT ''");
    dbAddColumnIfMissing($pdo, 'pm_priorities', 'deleted_by', 'INTEGER NULL');

    $pdo->exec("
        UPDATE pm_priorities
        SET updated_at = created_at
        WHERE updated_at = '';

        UPDATE pm_priorities
        SET version = 1
        WHERE version IS NULL OR version < 1;

        CREATE INDEX IF NOT EXISTS idx_pm_priorities_active_board
            ON pm_priorities(deleted_at, is_done, priority, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_pm_priorities_assignee_active
            ON pm_priorities(assignee_id, deleted_at, is_done);

        CREATE TRIGGER IF NOT EXISTS trg_pm_priorities_assignee_insert
        BEFORE INSERT ON pm_priorities
        FOR EACH ROW
        WHEN NEW.assignee_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM pm_engineers WHERE id = NEW.assignee_id AND active = 1)
        BEGIN
            SELECT RAISE(ABORT, 'Priority assignee must be an active engineer');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_priorities_assignee_update
        BEFORE UPDATE OF assignee_id ON pm_priorities
        FOR EACH ROW
        WHEN NEW.assignee_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM pm_engineers WHERE id = NEW.assignee_id AND active = 1)
        BEGIN
            SELECT RAISE(ABORT, 'Priority assignee must be an active engineer');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_priorities_archive_only_delete
        BEFORE DELETE ON pm_priorities
        FOR EACH ROW
        BEGIN
            SELECT RAISE(ABORT, 'Priorities must be archived instead of physically deleted');
        END;
    ");
}

/**
 * Client collaboration and delivery-tracking foundation.
 *
 * pm_tasks already models internal work. These tables add the three pieces the
 * approved product design still needs before any Task API is built: dated
 * project milestones, actual work time per employee, and one publish-gated
 * surface through which a project owner sees updates, files and approvals.
 */
function clientCollaborationFoundationMigration(PDO $pdo): void
{
    // Actual start/completion time is tracked per task. Work logs below keep
    // the detailed per-session history behind it.
    dbAddColumnIfMissing($pdo, 'pm_tasks', 'actual_start_at', "TEXT NOT NULL DEFAULT ''");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_milestones (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            section_id INTEGER NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            target_date TEXT NOT NULL DEFAULT '',
            progress_threshold INTEGER NULL
                CHECK(progress_threshold IS NULL
                      OR (progress_threshold >= 0 AND progress_threshold <= 100)),
            status TEXT NOT NULL DEFAULT 'planned'
                CHECK(status IN ('planned','in_progress','reached','missed','cancelled')),
            reached_at TEXT NOT NULL DEFAULT '',
            notify_client INTEGER NOT NULL DEFAULT 0 CHECK(notify_client IN (0,1)),
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            deleted_at TEXT NOT NULL DEFAULT '',
            deleted_by INTEGER NULL,
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(section_id) REFERENCES pm_sections(id) ON DELETE SET NULL,
            FOREIGN KEY(created_by) REFERENCES pm_users(id) ON DELETE SET NULL,
            FOREIGN KEY(deleted_by) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_milestones_project_status
            ON pm_milestones(project_id, status, target_date);
        CREATE INDEX IF NOT EXISTS idx_pm_milestones_deleted
            ON pm_milestones(deleted_at, project_id);

        CREATE TABLE IF NOT EXISTS pm_work_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            started_at TEXT NOT NULL,
            ended_at TEXT NOT NULL DEFAULT '',
            minutes INTEGER NOT NULL DEFAULT 0 CHECK(minutes >= 0),
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_pm_work_logs_task_user
            ON pm_work_logs(task_id, user_id, started_at);
        CREATE INDEX IF NOT EXISTS idx_pm_work_logs_user_started
            ON pm_work_logs(user_id, started_at);

        CREATE TABLE IF NOT EXISTS pm_client_updates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            task_id INTEGER NULL,
            milestone_id INTEGER NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            published_by INTEGER NULL,
            published_at TEXT NOT NULL DEFAULT '',
            approval_required INTEGER NOT NULL DEFAULT 0 CHECK(approval_required IN (0,1)),
            comments_enabled INTEGER NOT NULL DEFAULT 0 CHECK(comments_enabled IN (0,1)),
            files_enabled INTEGER NOT NULL DEFAULT 0 CHECK(files_enabled IN (0,1)),
            decision TEXT NOT NULL DEFAULT 'not_requested'
                CHECK(decision IN ('not_requested','awaiting_approval','approved','changes_requested')),
            decision_comment TEXT NOT NULL DEFAULT '',
            decided_by INTEGER NULL,
            decided_at TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            deleted_at TEXT NOT NULL DEFAULT '',
            deleted_by INTEGER NULL,
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE SET NULL,
            FOREIGN KEY(milestone_id) REFERENCES pm_milestones(id) ON DELETE SET NULL,
            FOREIGN KEY(published_by) REFERENCES pm_users(id) ON DELETE SET NULL,
            FOREIGN KEY(decided_by) REFERENCES pm_users(id) ON DELETE SET NULL,
            FOREIGN KEY(deleted_by) REFERENCES pm_users(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_pm_client_updates_project_published
            ON pm_client_updates(project_id, published_at DESC, deleted_at);
        CREATE INDEX IF NOT EXISTS idx_pm_client_updates_decision
            ON pm_client_updates(decision, published_at);

        -- A project owner must never be shown an unpublished draft, and must
        -- never be asked to approve something that was not published to them.
        CREATE TRIGGER IF NOT EXISTS trg_pm_client_updates_approval_needs_publish_insert
        BEFORE INSERT ON pm_client_updates
        FOR EACH ROW
        WHEN NEW.approval_required = 1 AND NEW.published_at = ''
        BEGIN
            SELECT RAISE(ABORT, 'Client approval requires a published update');
        END;

        CREATE TRIGGER IF NOT EXISTS trg_pm_client_updates_approval_needs_publish_update
        BEFORE UPDATE OF approval_required, published_at ON pm_client_updates
        FOR EACH ROW
        WHEN NEW.approval_required = 1 AND NEW.published_at = ''
        BEGIN
            SELECT RAISE(ABORT, 'Client approval requires a published update');
        END;

        -- A rejection without a reason is operationally useless and leads to
        -- disputes on construction deliverables.
        CREATE TRIGGER IF NOT EXISTS trg_pm_client_updates_reason_required
        BEFORE UPDATE OF decision ON pm_client_updates
        FOR EACH ROW
        WHEN NEW.decision = 'changes_requested' AND TRIM(NEW.decision_comment) = ''
        BEGIN
            SELECT RAISE(ABORT, 'A reason is required when changes are requested');
        END;

        -- A milestone can only be marked reached once.
        CREATE TRIGGER IF NOT EXISTS trg_pm_milestones_reached_timestamp
        BEFORE UPDATE OF status ON pm_milestones
        FOR EACH ROW
        WHEN NEW.status IN ('reached','missed') AND NEW.reached_at = ''
        BEGIN
            SELECT RAISE(ABORT, 'Reached and missed milestones require a timestamp');
        END;
    ");
}

function progressForecastMigration(PDO $pdo): void
{
    // Per-project progress engine: manual (legacy status picking) vs
    // task_driven (item percent derived from approved tasks).
    dbAddColumnIfMissing($pdo, 'pm_projects', 'progress_mode', "TEXT NOT NULL DEFAULT 'manual'");
    dbAddColumnIfMissing($pdo, 'pm_projects', 'contract_value', 'REAL NOT NULL DEFAULT 0');

    // Exact task-derived percent per item (-1 = no linked progress tasks).
    dbAddColumnIfMissing($pdo, 'pm_project_values', 'task_percent', 'REAL NOT NULL DEFAULT -1');

    // Daily planned-vs-earned history for the S-curve and EVM forecasts.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_progress_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            snapshot_date TEXT NOT NULL,
            planned_pct REAL NOT NULL DEFAULT 0,
            earned_pct REAL NOT NULL DEFAULT 0,
            actual_cost REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(project_id, snapshot_date),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pm_progress_snapshots_project
        ON pm_progress_snapshots(project_id, snapshot_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pm_tasks_project_item_progress
        ON pm_tasks(project_id, item_id, affects_project_progress, status)');

    // Existing projects keep the legacy manual behaviour (column default).
    $pdo->exec("
        UPDATE pm_projects
        SET progress_mode = 'manual'
        WHERE progress_mode NOT IN ('manual', 'task_driven')
    ");
}

function stage5FinanceReportsNikMigration(PDO $pdo): void
{
    // ---- Finance (admin-only): payments, expenses, withdrawals ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_project_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            amount REAL NOT NULL CHECK (amount > 0),
            paid_at TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pm_payments_project
        ON pm_project_payments(project_id, paid_at)');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_project_expenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            category TEXT NOT NULL DEFAULT '',
            amount REAL NOT NULL CHECK (amount > 0),
            spent_at TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pm_expenses_project
        ON pm_project_expenses(project_id, spent_at)');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_withdrawals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            amount REAL NOT NULL CHECK (amount > 0),
            taken_at TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");

    // ---- Employee score events (award = progress points raised on approve) ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pm_task_score_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            points REAL NOT NULL DEFAULT 0,
            progress_before REAL NOT NULL DEFAULT 0,
            progress_after REAL NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(task_id, user_id),
            FOREIGN KEY(project_id) REFERENCES pm_projects(id) ON DELETE CASCADE,
            FOREIGN KEY(task_id) REFERENCES pm_tasks(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES pm_users(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pm_score_events_user
        ON pm_task_score_events(user_id)');

    // ---- NiK chatbot: conversation log + memory (owner 0 = global/admin) ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nik_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            content TEXT NOT NULL,
            intent TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nik_messages_user
        ON nik_messages(user_id, id)');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nik_memory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER NOT NULL DEFAULT 0,
            memo_key TEXT NOT NULL,
            memo_value TEXT NOT NULL,
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            UNIQUE(owner_user_id, memo_key)
        )
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
        '20261008_007_security_design_remediation' => 'securityRemediationMigration',
        '20261008_008_priorities_integrity' => 'prioritiesIntegrityMigration',
        '20261008_009_client_collaboration_foundation' => 'clientCollaborationFoundationMigration',
        '20261009_010_progress_forecast_engine' => 'progressForecastMigration',
        '20261009_011_finance_reports_nik' => 'stage5FinanceReportsNikMigration',
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
