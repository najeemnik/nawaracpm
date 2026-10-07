# NawAra Construction Project Management

NawAra is being evolved incrementally into a simple, installable construction-project workspace with NawAra SQLite remaining the authoritative database.

## Current foundation

- Existing projects, sections, weighted progress, statuses and priorities are retained.
- Versioned additive SQLite migrations establish Tasks, reviews, multi-assignee records, client publication controls, comments, checklists, dependencies, task attachments, notifications and audit history.
- Accounts support Admin / Head Engineer, Employee and Client / Project Owner types.
- Legacy internal endpoints are protected with server-side project authorization, CSRF protection, session invalidation and audit records.
- Client accounts are intentionally blocked from the legacy internal workspace until the separate publish-gated client portal is implemented.

## Documentation

- [Task workflow (Dari)](docs/01_TASK_WORKFLOW_FA.md)
- [Permission matrix (Dari)](docs/02_PERMISSION_MATRIX_FA.md)
- [Notification matrix (Dari)](docs/03_NOTIFICATION_MATRIX_FA.md)
- [Secure deployment guide (Dari)](docs/04_DEPLOYMENT_SECURITY_FA.md)
- [Stage 2 test report (Dari)](docs/05_STAGE2_TEST_REPORT_FA.md)

## Deployment note

Before production deployment, read the secure deployment guide. In particular, configure `NAWARA_DATA_DIR` and `NAWARA_UPLOADS_DIR` outside the web document root, enable HTTPS and set a strong `NAWARA_INITIAL_ADMIN_PASSWORD` for a genuinely empty installation.
