# گزارش اصلاح طراحی و تست امنیتی مرحلهٔ ۲٫۵

**تاریخ:** ۸ اکتوبر ۲۰۲۶
**دامنه:** اصلاح امنیت/طراحی سیستم فعلی؛ بدون شروع API یا UI مرحلهٔ ۳ Task/Client/PWA
**دیتابیس اصلی:** دست‌نخورده؛ SHA-256 قبل و بعد از تست‌ها: `537aceca969f105f57fccb43885826a402f262438da99c537243a6e734f2266d`

## موارد اصلاح‌شده

- `pm_project_members` منبع canonical دسترسی پروژه شد؛ `pm_project_access` فقط mirror سازگاری است. Head Admin فقط `role=admin` است و `project_admin` به Admin سراسری تبدیل نمی‌شود.
- پروژه‌ها و priorityها archive/restore و audit دارند؛ حذف فیزیکی priority در سطح SQLite مسدود است. archive/restore پروژه با `version` و optimistic locking انجام می‌شود.
- save پروژه، status، section/item، user و priority validation، محدودیت اندازه، تاریخ، weight، رنگ status، assignee و duplicateها را بررسی می‌کند. تغییر template هنگام وجود پروژهٔ فعال قفل است تا معنی progress تاریخی تغییر نکند.
- session timeout، refresh user، `auth_version`، login throttle، CSRF، deactivation/revocation و جلوگیری از حذف آخرین Admin در code/schema محافظت شده‌اند.
- فایل‌ها خارج document root در production اجباری‌اند؛ مجوز view/manage جداست، traversal/symlink/path validation، quota و allowlist فعال‌اند.
- تمام event handlerهای inline حذف شدند. bootstrap از meta tag escaped خوانده می‌شود و actionها با event delegation در `script.js` انجام می‌شوند. صفحهٔ PDF نیز script خارجی CSP-safe دارد.
- `esc()` در سمت کلاینت برای **هر دو context متن و attribute** بازنویسی شد. پیاده‌سازی قبلی فقط از serializer متنی مرورگر استفاده می‌کرد و quoteها را escape نمی‌کرد؛ در نتیجه مقدارِ کنترل‌شده می‌توانست از attribute بیرون بزند و attribute/event handler جدید بسازد.
- CSP production شامل `script-src 'self'` بدون `unsafe-inline`، `frame-ancestors 'none'` و headerهای clickjacking/HSTS است.
- migration production خودکار نیست. تا backup تأیید نشود و `NAWARA_ALLOW_SCHEMA_MIGRATIONS=1` فقط برای run مورد تأیید تنظیم نشود، برنامه fail-closed است و schema را تغییر نمی‌دهد.

## تست‌های انجام‌شده روی copy کاملاً disposable

همهٔ تست‌ها با PHP-Wasm روی copy جدا اجرا شدند؛ نه `data/database.sqlite` اصلی و نه uploadهای اصلی برای test باز/مهاجرت/ویرایش نشدند.

| دسته | نتیجه |
|---|---|
| PHP lint | تمام ۱۲ فایل PHP بدون syntax error |
| JavaScript parse | `script.js` و `pdf_actions.js` بدون syntax error |
| migration clean DB | migrationهای 001 تا 008، شامل integrity priorities، با موفقیت اجرا شد |
| production migration gate | production بدون approval نه DB تازه ساخت و نه DB موجود را تغییر داد؛ پس از run مورد تأیید، production عادی باز شد |
| CSRF | mutation بدون token با `403` رد شد |
| project concurrency | update قدیمی `409`؛ archive بدون version `422`؛ archive/restore versioned موفق |
| transaction rollback | project value نامعتبر `422` و project ناقص ایجاد نشد |
| template integrity | تغییر status/section هنگام وجود پروژهٔ فعال `409` شد |
| priorities | validation تاریخ، version اجباری (`422`)، toggle/version conflict، archive/restore و archived list موفق؛ delete فیزیکی/assignee نامعتبر در trigger SQLite رد شد |
| authorization | Employee فقط پروژهٔ assigned را دید؛ project دیگر و delete غیرمجاز `403` شد |
| client isolation | Client list خالی و `meta=1` با `403` رد شد |
| deactivation | session قبلی user غیرفعال‌شده در request بعدی `401` شد |
| login throttling | بعد از ۸ failure هم‌نام، تلاش بعدی throttle شد |
| file manager | folder عادی موفق؛ `../../` برای create/list با `400` رد شد |
| CSP | header production بررسی شد؛ inline script/handler صفر و `/data/database.sqlite` در server test `404` بود |
| stored-XSS regression (DOM) | payloadهای مخرب در نام پروژه، نام/path فایل و folder و breadcrumb هیچ node یا attribute قابل اجرا نساختند؛ مقدار فقط به صورت attribute امنِ `data-*` نگه داشته شد |
| action coverage | ۶۰ مقدار `data-action` همگی به handler معتبر در `script.js` وصل‌اند؛ هیچ inline handler در کل source نیست |

## شرط باقی‌مانده برای production

این code remediation جای incident-response عملیاتی را نمی‌گیرد. repository/تاریخچهٔ عمومی که قبلاً SQLite یا upload داشته، و rotate کردن password/secretهای احتمالی، باید با تأیید مالک محصول و plan جداگانه انجام شود. این کار عمداً در این مرحله انجام نشده است، چون destructive/public-exposure action بدون تأیید مجاز نیست.

همچنین migration دیتابیس اصلی هنوز اجرا نشده است. قبل از deploy باید backup قابل بازگردانی گرفته و تأیید شود، سپس migration با `NAWARA_ALLOW_SCHEMA_MIGRATIONS=1` در maintenance window اجرا و بعد متغیر حذف شود.
