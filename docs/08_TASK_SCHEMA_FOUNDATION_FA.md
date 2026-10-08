# بنیاد فنی و امن — schema همکاری، زمان و Client

**تاریخ:** ۸ اکتوبر ۲۰۲۶
**مرحله:** Foundation فنی و امن (پیش از ساخت Task API و صفحهٔ PWA)
**وضعیت دیتابیس اصلی:** دست‌نخورده. SHA-256 در پایان این مرحله: `537aceca969f105f57fccb43885826a402f262438da99c537243a6e734f2266d`

## هدف این مرحله

طبق پلان تأییدشده، پیش از ساخت Task Workspace باید این‌ها آماده می‌شد:

1. سیستم migration نسخه‌دار — **انجام شد** (مراحل قبلی، `migrations.php` با ledger و تراکنش)
2. schema جدید برای Task، Assignee، Review، Client Approval و Notification — **انجام شد**
3. اصلاح permissionهای Backend — **انجام شد** (مرحلهٔ ۲٫۵)
4. حفظ دیتابیس فعلی و migration بدون حذف داده — **آماده است**؛ اجرا روی دیتابیس اصلی فقط با backup تأییدشده و اجازهٔ شما

## migration 009 — `20261008_009_client_collaboration_foundation`

سه جدولی که از پلان باقی مانده بود اضافه شد. همهٔ migrationها افزایشی‌اند؛ هیچ داده‌ای حذف یا بازنویسی نمی‌شود.

### `pm_milestones` — مراحل مهم پروژه

برای milestoneهایی مانند «تکمیل فونداسیون» یا «تحویل نقشهٔ برق».

| فیلد | معنا |
|---|---|
| `project_id`, `section_id` | اتصال به پروژه و در صورت نیاز به یک Section |
| `title`, `description` | عنوان و توضیح |
| `target_date` | تاریخ هدف |
| `progress_threshold` | در صورت تعیین، رسیدن progress پروژه به این عدد milestone را خودکار reached می‌کند |
| `status` | `planned` / `in_progress` / `reached` / `missed` / `cancelled` |
| `reached_at` | زمان تحقق |
| `notify_client` | آیا با تحقق این milestone به Client notification برود |
| `deleted_at`, `deleted_by` | امکان archive کردن به‌جای حذف فیزیکی |

### `pm_work_logs` — زمان واقعی کار کارمندان

هر Task زمان تخمینی و مهلت دارد (`pm_tasks`: `estimated_minutes`, `start_at`, `due_at`, `completed_at`). اما برای اینکه بعداً بتوان دید کدام Engineer overloaded است یا کدام Task بیشتر از زمان تعیین‌شده وقت گرفته، ثبتِ زمان واقعی لازم است. این جدول هر session کاری را نگه می‌دارد:

`task_id`, `user_id`, `started_at`, `ended_at`, `minutes`, `note`

همچنین `pm_tasks.actual_start_at` اضافه شد تا زمان شروع واقعیِ Task مستقیم در دسترس باشد.

### `pm_client_updates` — تنها سطح رسمی ارتباط با صاحب پروژه

Client نباید Taskهای داخلی، commentهای داخلی یا workload کارمندان را بگیرد. این جدول سطح انتشار جداگانه است:

| فیلد | کنترل |
|---|---|
| `published_at` | تا چیزی منتشر نشود، Client آن را نمی‌بیند |
| `approval_required` | آیا تأیید Client لازم است |
| `comments_enabled` | آیا Client می‌تواند نظر بدهد |
| `files_enabled` | آیا Client می‌تواند فایل‌ها را دانلود کند |
| `decision` | `not_requested` / `awaiting_approval` / `approved` / `changes_requested` |
| `decision_comment` | دلیل الزامی هنگام `changes_requested` |

## قواعدی که در سطح دیتابیس enforce می‌شوند

این قواعد فقط در کد نیستند؛ در SQLite trigger هم enforce شده‌اند تا هیچ API یا import دستی نتواند دورشان بزند:

| قاعده | دلیل |
|---|---|
| `approval_required = 1` نیازمند `published_at` است | از Client هرگز برای چیزی که منتشر نشده تأیید خواسته نشود |
| `changes_requested` نیازمند دلیل است | رد کردنِ بدون دلیل در کار ساختمانی باعث اختلاف می‌شود |
| `reached` / `missed` نیازمند timestamp است | تاریخچهٔ milestone قابل اعتماد بماند |
| `minutes >= 0` | جلوگیری از زمان منفی |
| foreign keyها برای task، user، project، milestone | جلوگیری از رکورد یتیم |

## تست‌های انجام‌شده

همه روی دیتابیس کاملاً disposable اجرا شدند؛ دیتابیس اصلی باز یا تغییر نکرد.

- PHP lint: ۱۲ فایل بدون خطا
- اجرای migrationهای ۰۰۱ تا ۰۰۹ روی دیتابیس تازه: موفق
- تست triggerها و constraintها: ۱۱ مورد، همه مطابق انتظار (مواردی که باید مسدود می‌شدند مسدود شدند)
- دروازهٔ production: بدون تأیید، حتی فایل دیتابیس هم ساخته نشد؛ با `NAWARA_ALLOW_SCHEMA_MIGRATIONS=1` همهٔ ۹ migration اجرا شد و پس از آن production عادی بالا آمد

## قدم بعدی

ساخت **Task transition service در سمت سرور** (سرویس انتقال وضعیت) مطابق `docs/01_TASK_WORKFLOW_FA.md`، با این ویژگی‌ها:

- هر انتقال وضعیت در یک تراکنش بررسی شود: actor، membership، محدودهٔ پروژه، وضعیت قبلی، policy
- کارمند فقط `Submit for Review` می‌زند؛ completion نهایی با تأیید Admin
- `Review Required` برای هر Task قابل روشن/خاموش باشد
- assignment تک‌نفره یا چندنفره با دو حالت: نتیجهٔ مشترک / تکمیل جداگانهٔ هر کارمند
- progress رسمی فقط پس از تأیید بالا برود
- notification مطابق `docs/03_NOTIFICATION_MATRIX_FA.md` در همان تراکنش ثبت شود
