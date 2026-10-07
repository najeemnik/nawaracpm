# NawAra CPM — Permission Matrix و مدل دسترسی

**وضعیت:** مبنای مورد تأیید برای پیاده‌سازی  
**اصل امنیتی:** Permission در Backend enforce می‌شود؛ مخفی‌کردن button در UI هرگز permission محسوب نمی‌شود.

---

## 1. گروه‌های اصلی User

| گروه | هدف |
|---|---|
| `admin` | Head Engineer، System Admin یا Project Admin |
| `employee` | Engineer / Architect / Staff که روی Taskهای تعیین‌شده کار می‌کند |
| `client` | صاحب پروژه یا نمایندهٔ Client که فقط داده‌های publish‌شده را می‌بیند |

### سطح‌های Admin

| نوع Admin | scope پیش‌فرض |
|---|---|
| `head_admin` | تمام سیستم، userها، settings، templateها و همهٔ پروژه‌ها |
| `project_admin` | فقط پروژه‌های عضو شده؛ نمی‌تواند settings عمومی یا userهای سیستم را تغییر دهد مگر permission صریح داشته باشد |

این دو level می‌توانند با role و permissionهای global پیاده‌سازی شوند، بدون اینکه گروه چهارم جدا بسازیم.

---

## 2. مدل دسترسی دو لایه

### Global Permissions

برای اعمالی که مربوط کل سیستم‌اند:

```text
manage_users
manage_global_settings
manage_templates
view_all_projects
manage_all_projects
manage_notifications
view_audit_log
```

### Project-scoped Permissions

برای هر project membership:

```text
view_project
view_project_summary
view_internal_tasks
create_tasks
edit_tasks
assign_tasks
reassign_tasks
update_own_assignment
update_any_task
submit_for_review
review_tasks
approve_tasks
request_revision
manage_project_members
view_reports
publish_reports
view_files
upload_files
manage_files
publish_files_to_client
manage_client_access
view_activity_log
```

Client permissionها جدا و محدودند:

```text
view_client_summary
view_published_reports
view_published_files
view_published_timeline
comment_on_published_items
approve_client_deliverables
```

---

## 3. Access Ruleها

1. User بدون project membership هیچ دادهٔ آن project را نمی‌بیند.
2. Client فقط داده‌هایی را می‌بیند که `visible_to_client=true` و publish شده باشند.
3. Employee به‌صورت پیش‌فرض فقط Taskهای assigned به خود یا teamهای خودش را می‌بیند.
4. Project Admin فقط projectهای membership خودش را اداره می‌کند.
5. Head Admin همه چیز را می‌بیند، اما همهٔ عمل‌های حساس او نیز audit می‌شوند.
6. Permission پایین‌تر هرگز permission بالاتر را imply نمی‌کند؛ مثلاً `view` به معنای `edit` نیست.
7. Backend در هر request user active، role، project membership و permission را بررسی می‌کند.

---

## 4. Permission Matrix اصلی

| عمل | Head Admin | Project Admin | Employee | Client |
|---|:---:|:---:|:---:|:---:|
| دیدن همهٔ پروژه‌ها | ✓ | فقط assigned | فقط assigned | فقط assigned client projects |
| ساخت پروژه | ✓ | با permission | ✕ | ✕ |
| ویرایش اطلاعات پروژه | ✓ | با permission | ✕ | ✕ |
| حذف پروژه | ✓ | با permission خاص | ✕ | ✕ |
| ساخت Task | ✓ | ✓ | فقط اگر مجاز باشد | ✕ |
| assign/reassign Task | ✓ | ✓ | ✕ | ✕ |
| دیدن Task داخلی | ✓ | ✓ در project خود | فقط assigned/team task | ✕ مگر explicitly published |
| شروع/Update Task خود | ✓ | ✓ | ✓ | ✕ |
| Block Task خود | ✓ | ✓ | ✓ | ✕ |
| Submit for Review | ✓ | ✓ | ✓ برای task خودش | ✕ |
| Approve/Request Revision | ✓ | با reviewer permission | ✕ | ✕ |
| Publish به Client | ✓ | با permission | ✕ | ✕ |
| Client Approval | ✕ | ✕ | ✕ | فقط اگر فعال شده باشد |
| Client Comment | ✕ | ✕ | ✕ | فقط اگر فعال شده باشد |
| دیدن report داخلی | ✓ | ✓ | با permission | ✕ |
| دیدن report منتشرشده | ✓ | ✓ | با permission | ✓ |
| Upload file | ✓ | ✓ | با permission | ✕ مگر صریحاً فعال شود |
| Download file داخلی | ✓ | ✓ | با permission | ✕ |
| Download file منتشرشده | ✓ | ✓ | ✓ اگر مجاز | ✓ اگر publish شده |
| مدیریت userها | ✓ | ✕ | ✕ | ✕ |
| مدیریت settingها/templateها | ✓ | با permission محدود | ✕ | ✕ |
| دیدن audit log | ✓ | با permission project | محدود به own history | ✕ |

---

## 5. Permission Templateها

برای جلوگیری از تنظیم دستی permission برای هر user، templateهای زیر ساخته می‌شوند:

### Head Engineer

```text
Full system access
All projects
User management
Global settings
Review/approval
Client publication
Audit logs
```

### Project Manager

```text
Assigned projects only
Create and assign tasks
Review and approve tasks
Manage project members
Publish reports/files to client
No global user/settings access
```

### Engineer / Employee

```text
Assigned projects only
Own/team tasks only
Start, update, block and submit tasks
Upload allowed files
No approval, no user management, no global settings
```

### Client Viewer

```text
Read-only published summary/reports/files
No comments
No approval
```

### Client Approver

```text
Read published summary/reports/files
Optional comments
Approve/request changes on chosen deliverables
```

Admin می‌تواند بعد از انتخاب template، permissionهای خاص را override کند.

---

## 6. Task-level Controls

حتی اگر Client یا Employee project access داشته باشد، Task-level rule قابل اعمال است:

```text
Task visibility: internal / selected employees / published to client
Assigned users: one or many
Reviewers: one or many
Client recipients: one or many
Comments enabled: on / off
Client approval required: on / off
File download enabled: on / off
```

### Rule مهم

Client visibility هیچ‌گاه به معنی دسترسی به تمام project/taskها نیست. فقط object مشخصی که Admin publish کرده قابل مشاهده است.

---

## 7. User Lifecycle و Session Security

| Event | رفتار لازم |
|---|---|
| User created | تا فعال‌شدن، فقط account ایجاد می‌شود؛ permissionها از template/project membership می‌آیند |
| Password changed | تمام sessionهای قبلی user revoke شوند |
| User deactivated | تمام sessionها revoke شوند؛ API access فوری متوقف شود |
| Role/permission changed | session/permission cache invalidate شود |
| User removed from project | دسترسی همان project فوراً قطع شود |
| User deleted | project membership، access record، notification subscription و session cleanup شوند |

---

## 8. الزام‌های Backend

برای هر endpoint write/read حساس:

1. user باید active باشد.
2. role و permission تازه از source معتبر بررسی شود.
3. project membership بررسی شود.
4. object-level access بررسی شود؛ مثلاً task متعلق به همان project باشد.
5. action در audit log ثبت شود.
6. خطای permission عمومی باشد و جزئیات database به client داده نشود.

---

## 9. معیار پذیرش مرحلهٔ پیاده‌سازی

- [ ] Employee با request مستقیم نتواند task پروژهٔ دیگر را بخواند/ویرایش کند.
- [ ] Client نتواند task داخلی یا file منتشرنشده را ببیند.
- [ ] Project Admin نتواند user global یا template عمومی را تغییر دهد مگر permission صریح داشته باشد.
- [ ] Deactivate/delete user فوراً session و access را قطع کند.
- [ ] هر تغییر permission/project membership audit شود.
- [ ] تمام permission checkها در server باشند، نه فقط frontend.
