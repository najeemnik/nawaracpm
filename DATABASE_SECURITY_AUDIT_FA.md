# گزارش امنیتی و عیب‌یابی Database — NawAra CPM

**تاریخ بررسی:** ۷ اکتوبر ۲۰۲۶  
**وضعیت تغییرات:** این گزارش فقط خواندنی تهیه شده است؛ هیچ داده، schema یا فایل اجرایی تغییر داده نشده است.

> این گزارش همهٔ مواردی را پوشش می‌دهد که با بررسی source code، schema، تنظیمات SQLite و snapshot فعلی database قابل شناسایی بود. این یک security review عمیق است، اما هیچ بررسی نمی‌تواند وجود آسیب‌پذیری ناشناخته در PHP، web server، hosting یا dependencyهای آینده را به‌طور مطلق رد کند.

---

## 1. نتیجهٔ فوری

Database **خراب (corrupt) نیست**، ولی طراحی و طرز استفاده از آن چند خطر جدی دارد. تا رفع موارد بحرانی، database نباید با دادهٔ واقعی روی اینترنت یا server عمومی deploy شود.

### وضعیت صحت فعلی

| بررسی | نتیجه |
|---|---|
| `PRAGMA quick_check` | `ok` |
| `PRAGMA integrity_check` | `ok` |
| `PRAGMA foreign_key_check` برای FKهای تعریف‌شده | بدون خطا |
| Progress ذخیره‌شدهٔ ۳ پروژه | با محاسبهٔ فعلی مطابقت دارد |
| Password hashes موجود | bcrypt (`$2y$`) هستند؛ plaintext password در جدول پیدا نشد |
| Secure delete | فعال است (`secure_delete=1`) |
| Journal mode | WAL |

این نکات مثبت مهم‌اند؛ اما به معنی امن‌بودن برنامه یا access-control نیستند.

---

## 2. یافته‌های واقعی در snapshot فعلی

بدون افشای نام اشخاص، پروژه‌ها، رمزها یا محتویات فایل‌ها:

- Database فعلی **۳ user فعال** دارد که **۲ تای آن Admin** است.
- ۳ پروژه، ۶ status، ۶ section، ۳۴ section item، ۶۷ project value و ۵ priority record وجود دارد.
- ۶ section فعال هستند؛ فقط **۱۸ item فعال** و **۱۶ item غیرفعال** وجود دارد.
- **۱۹ project value** به itemهای غیرفعال وصل‌اند؛ این valueها در `fetchMeta()` و گزارش فعلی نادیده گرفته می‌شوند.
- هر پروژه فقط ۱۶ از ۱۸ item فعال فعلی را value دارد؛ برای هر پروژه ۲ value فعال موجود نیست.
- **۱ project access یتیم** به user حذف‌شده اشاره می‌کند.
- **۱ project value** به engineer غیرفعال اشاره می‌کند.
- وزن sectionهای فعال جمعاً ۱۰۰ است، ولی وزن itemهای یکی از sectionهای فعال فقط ۷۵ است. برنامه آن را silently normalize می‌کند و validation ندارد.
- جدول legacy به نام `project_files` دو record دارد، اما هیچ code فعلی به آن رجوع نمی‌کند.

---

# 3. آسیب‌پذیری‌ها و مشکل‌ها

## P0 — بحرانی؛ قبل از deploy عمومی باید رفع شوند

### DB-01 — Database حساس داخل Web Root و داخل Git است

**وضعیت:** تأییدشده  
**شواهد:**

- `config.php:18–23` مسیر database را `__DIR__/data/database.sqlite` تعیین می‌کند.
- `data/database.sqlite` در Git track شده است.
- `uploads/test.txt` هم در Git track شده است.
- `.gitignore` وجود ندارد.
- هیچ `.htaccess`، `nginx.conf`، `web.config` یا rule منع دسترسی به `data/` و `uploads/` در repository نیست.
- permission فعلی: folderهای `data` و `uploads` برابر `0755` و database برابر `0644` است.

**اثر:**

اگر document root همان folder پروژه باشد ــ در hostingهای ساده این حالت معمول است ــ یک مهاجم ممکن است فایل SQLite را مستقیم دانلود کند. این فایل شامل userها، bcrypt password hashها، permissionها، پروژه‌ها، commentها و داده‌های کاری است. حتی اگر web server download را مسدود کند، database به Git وارد شده و ممکن است در clone، backup یا repository مشترک افشا شود.

**اصلاح لازم:**

1. `data/` و `uploads/` را خارج از document root انتقال دهید.
2. database، WAL/SHM و uploadهای runtime را به `.gitignore` اضافه کنید.
3. یک database نمونهٔ بدون دادهٔ واقعی برای repository بسازید.
4. password تمام accountهای فعلی را rotate کنید و sessionهای قدیمی را invalidate کنید.
5. permission storage را برای user مربوط web server محدود کنید؛ `0755/0644` برای دادهٔ حساس مناسب نیست.

---

### DB-02 — Admin bootstrap با credential ثابت در source code

**وضعیت:** تأییدشده  
**شواهد:** `database.php:150–169`

وقتی جدول user خالی باشد، code یک Admin پیش‌فرض با username و password معلوم ایجاد می‌کند.

**اثر:**

اگر database تازه ایجاد، reset یا خالی شود، شخصی که source را دیده است می‌تواند Admin شود. این موضوع با DB-01 خطر بیشتری پیدا می‌کند.

**اصلاح لازم:**

- credential ثابت را کامل حذف کنید.
- setup اولیه را از CLI یا environment secret انجام دهید.
- password تصادفی یک‌بارمصرف بسازید و در اولین ورود، تغییر password اجباری باشد.
- setup endpoint نباید بعد از نصب قابل استفاده بماند.

---

### DB-03 — Authorization سمت server برای عملیات database ناقص است

**وضعیت:** تأییدشده  
**شواهد:**

- `save_project.php:7` فقط login را چک می‌کند؛ actionهای `save_project`، `add_engineer`، `add_status`، `save_engineers`، `save_statuses` و `save_sections` بررسی permission سمت server ندارند.
- `delete_project.php:7–38` فقط login را چک می‌کند؛ `can_delete` یا `canDoOnProject()` را اجرا نمی‌کند.
- `priorities.php:9–10` فقط login را چک می‌کند؛ permission `priorities` را enforce نمی‌کند.
- `generate_pdf.php:7–16` فقط login را چک می‌کند؛ `view`، `print` یا `pdf` را enforce نمی‌کند.

**اثر:**

هر user واردشده می‌تواند با request مستقیم، دادهٔ پروژه‌های دیگر را تغییر یا حذف کند، settingهای مشترک را عوض کند، priorityها را مدیریت کند یا report پروژه‌های غیرمجاز را ببیند. مخفی‌کردن button در JavaScript امنیت نیست.

**اصلاح لازم:**

یک authorization layer واحد ایجاد شود:

- ایجاد پروژه: `add`
- ویرایش پروژه موجود: `canDoOnProject($id, 'edit')`
- حذف: `canDoOnProject($id, 'delete')`
- report/PDF: هم `view` و هم `print` یا `pdf`
- فایل: هم `view` و هم `files`
- engineers/statuses/sections: `settings` یا Admin
- priority: `priorities`

تمام endpointها باید قبل از هر query write، permission را server-side چک کنند.

---

### DB-04 — File Manager permission bypass و storage ناامن

**وضعیت:** تأییدشده  
**شواهد:**

- `file_manager.php:10–55` project ID را فقط از `$_GET` یا `$_POST` پیش از parse شدن JSON می‌خواند.
- عملیات `create_folder`، `rename` و `delete` project ID واقعی را بعداً از JSON body می‌خوانند.
- اگر بررسی ابتدایی project ID صفر ببیند، condition permission اجرا نمی‌شود.
- `file_manager.php:69` storage را داخل `__DIR__/uploads` می‌سازد.
- `file_manager.php:70` تا ۵۰۰MB برای هر فایل اجازه می‌دهد.
- فقط blacklist پسوند وجود دارد؛ MIME validation، allowlist، quota کلی، antivirus و randomized storage name وجود ندارد.

**اثر:**

کاربر login‌شده می‌تواند permission file را در بعضی requestها دور بزند. فایل‌ها هم ممکن است مستقیم از URL زیر `uploads/` قابل دسترسی باشند. HTML، SVG و چندین نوع فایل قابل اجرا در browser توسط blacklist محدود نشده‌اند. این مسئله می‌تواند به data leak، stored XSS، disk exhaustion و در serverهای misconfigured به اجرای فایل خطرناک برسد.

**اصلاح لازم:**

- body را اول parse کنید، سپس ID واحد و واقعی را برای authorization و action استفاده کنید.
- هیچ وقت query-string و JSON body را با اولویت‌های متفاوت برای یک authorization decision مخلوط نکنید.
- upload را خارج web root نگه دارید.
- فقط allowlist نوع فایل/extension مورد نیاز business را اجازه دهید.
- MIME را server-side تشخیص دهید، حجم/تعداد/quota تعیین کنید، نام ذخیره‌شده تصادفی باشد و نام اصلی فقط metadata باشد.
- دانلود فقط از endpoint مجاز با `Content-Disposition: attachment` و `X-Content-Type-Options: nosniff` انجام شود.

---

### DB-05 — Stored XSS از داده‌های database و file nameها

**وضعیت:** تأییدشده در code review  
**شواهد:**

- `script.js:178–214` نام پروژه را داخل inline `onclick` می‌گذارد؛ فقط apostrophe را replace می‌کند و HTML/JavaScript context را امن نمی‌کند.
- `script.js:1144–1192` path و name فایل/folder با `innerHTML` و inline event handler ساخته می‌شوند.
- `esc()` برای text/HTML مفید است، ولی escaping HTML به‌تنهایی برای JavaScript داخل attribute کافی نیست.
- `fm_clean_name()` quote، semicolon و چند character مهم JavaScript را منع نمی‌کند.

**اثر:**

نام پروژه، file یا folder که در database/filesystem ذخیره شده، می‌تواند در browser Admin یا user دیگر JavaScript اجرا کند؛ سپس session یا دادهٔ database را سرقت/تغییر دهد.

**اصلاح لازم:**

- inline handler (`onclick`, `ondblclick`) را حذف کنید.
- event listener را با `addEventListener()` وصل کنید.
- برای text از `textContent` استفاده کنید.
- filename/folder name را با allowlist محدود کنید؛ مثلاً حروف، اعداد، فاصله، dash و underscore.
- CSP مناسب اضافه کنید؛ ولی CSP جای اصلاح output encoding نیست.

---

## P1 — زیاد مهم؛ در نخستین مرحلهٔ hardening رفع شوند

### DB-06 — Access table foreign key ندارد و رکورد یتیم واقعاً وجود دارد

**وضعیت:** تأییدشده  
**شواهد:**

- `pm_project_access` در `database.php:133–145` هیچ foreign key به `pm_users` یا `pm_projects` ندارد.
- یک access record فعلی به user حذف‌شده اشاره می‌کند.
- `users.php:218–219` user را حذف می‌کند، ولی accessهای او را پاک نمی‌کند.
- `delete_project.php:37–38` project را حذف می‌کند، ولی accessها و فایل‌های disk را پاک نمی‌کند.

**اثر:**

permissionهای stale باقی می‌مانند. این مشکل امروز حداقل یک record یتیم ایجاد کرده است. در آینده cleanup، reporting و authorization می‌تواند اشتباه شود.

**اصلاح لازم:**

Foreign keyهای زیر را در migration جدید اضافه کنید:

- `pm_project_access.user_id → pm_users.id ON DELETE CASCADE`
- `pm_project_access.project_id → pm_projects.id ON DELETE CASCADE`

همچنان index جداگانه روی `project_id` اضافه شود و record یتیم فعلی پس از backup پاک یا بررسی شود.

---

### DB-07 — تاریخچهٔ پروژه‌ها با تغییر template از بین می‌رود یا تغییر می‌کند

**وضعیت:** تأییدشده و در data فعلی دیده شد  
**شواهد:**

- `save_project.php` هنگام save sectionها همهٔ section/itemهای قبلی را `active=0` می‌کند.
- `fetchMeta()` فقط section و itemهای `active=1` را برمی‌گرداند.
- ۱۹ value فعلی به item غیرفعال متصل‌اند و در report/progress فعلی دیده نمی‌شوند.
- `pm_project_values.item_id` با `ON DELETE CASCADE` تعریف شده است؛ حذف فیزیکی item می‌تواند تاریخچه را کامل پاک کند.
- status percent و section/item weight shared هستند؛ تغییر آن‌ها progress پروژه‌های قدیمی را retroactively تغییر می‌دهد.

**اثر:**

گزارش پروژهٔ سال گذشته ممکن است امروز با ارقام و itemهای متفاوت نمایش داده شود. این برای project reporting، قرارداد، audit و اعتماد مشتری خطرناک است.

**اصلاح لازم:**

یکی از این modelها لازم است:

1. هنگام ساخت پروژه، template/section/item/status را snapshot کنید؛ یا
2. version برای template و status بسازید و پروژه به version مشخص وصل باشد؛ یا
3. تغییر template را فقط با migration کنترل‌شده و audit log اجازه دهید.

قبل از migration باید ۱۹ value غیرفعال map یا restore شوند؛ نباید کورکورانه حذف شوند.

---

### DB-08 — Session پس از حذف user، تغییر password یا revoke permission معتبر می‌ماند

**وضعیت:** تأییدشده  
**شواهد:**

- `config.php:41–46` login را فقط از `$_SESSION` می‌خواند.
- `index.php:28–30` تمام record user را داخل session ذخیره می‌کند.
- `users.php` role، password و permission database را تغییر می‌دهد، ولی sessionهای موجود را revoke نمی‌کند.

**اثر:**

کاربری که حذف، deactivate یا محدود شده، تا پایان session می‌تواند با permission قدیمی کار کند. تغییر password هم sessionهای قدیمی را قطع نمی‌کند.

**اصلاح لازم:**

- در هر request مهم، user active/role/permission را از database یا cache معتبر بخوانید.
- `session_version` یا `auth_version` در user table داشته باشید و در session مقایسه کنید.
- پس از password/role/permission change، همهٔ sessionهای آن user revoke شوند.
- پس از login از `session_regenerate_id(true)` استفاده کنید.

---

### DB-09 — Error messageهای database به client افشا می‌شوند

**وضعیت:** تأییدشده  
**شواهد:**

- `config.php:7–10` نمایش تمام errorها را روشن کرده است.
- `database.php:33–37` message کامل database exception را به JSON response می‌دهد.
- endpointهای `save_project.php`، `delete_project.php`، `load_projects.php`، `file_manager.php`، `priorities.php` و `users.php` نیز exception message را به client برمی‌گردانند.

**اثر:**

path سرور، نام جدول/column، SQL error و اطلاعات داخلی به مهاجم داده می‌شود و exploit را آسان می‌کند.

**اصلاح لازم:**

در production:

- `display_errors=0`
- errorها در log امن ثبت شوند.
- client فقط message عمومی و request/correlation ID بگیرد.

---

### DB-10 — Schema constraints ضعیف؛ database خودش از دادهٔ بد محافظت نمی‌کند

**وضعیت:** تأییدشده  
**موارد:**

- `pm_users.role` CHECK ندارد.
- `pm_users.permissions` CHECK `json_valid()` ندارد و structure/key/value آن enforce نشده است.
- flagهای `active` و `can_*` CHECK بولی ندارند.
- `pm_statuses.percent` CHECK بین ۰ و ۱۰۰ ندارد.
- `color` الگوی hex enforce نمی‌کند.
- `pm_sections.weight` و `pm_section_items.weight` range یا total validation ندارند.
- `assignee_type` CHECK ندارد.
- `pm_projects.progress` CHECK ۰ تا ۱۰۰ ندارد.
- تاریخ‌ها فقط TEXT هستند و database فرمت/ترتیب آن‌ها را enforce نمی‌کند.
- text fieldها محدودیت طول ندارند.

Data فعلی از این لحاظ تمیز است، ولی schema تضمینی نمی‌دهد. هر import، script، bug یا request جدید می‌تواند دادهٔ ناسالم بسازد.

**اصلاح لازم:**

CHECK constraint، validation سمت server و migration اضافه شود. مثال‌ها:

- `CHECK(role IN ('admin','user'))`
- `CHECK(active IN (0,1))`
- `CHECK(percent BETWEEN 0 AND 100)`
- `CHECK(progress BETWEEN 0 AND 100)`
- `CHECK(assignee_type IN ('none','engineer','architect','any'))`
- `CHECK(json_valid(permissions))`

محدودیت مجموع وزن section/item بهتر است در service layer با transaction enforce شود، چون CHECK ساده برای مجموع چند row کافی نیست.

---

### DB-11 — Progress یک cache بدون trigger است و می‌تواند stale شود

**وضعیت:** تأییدشده در design؛ مقدار فعلی درست است  
**شواهد:**

- `pm_projects.progress` ذخیره می‌شود، اما trigger database برای update آن وجود ندارد.
- `updateProjectProgress()` فقط از code صدا زده می‌شود.
- در `save_project.php:482–484` transaction اول commit می‌شود و update progress بعداً اجرا می‌گردد.
- در save statuses/sections نیز config اول commit و recalculation تمام پروژه‌ها بعداً اجرا می‌شود.

**اثر:**

اگر calculation بعد از commit fail شود، project value/config ذخیره شده ولی progress stale می‌ماند. direct database update نیز progress را تغییر نمی‌دهد.

**اصلاح لازم:**

- update data و progress را در یک transaction واحد انجام دهید؛ یا
- progress را هنگام خواندن محاسبه کنید و column cache را حذف/به‌درستی refresh کنید؛ یا
- triggerهای مناسب و تست‌شده بسازید.

---

### DB-12 — File metadata legacy و disk state از database جدا است

**وضعیت:** تأییدشده  
**شواهد:**

- جدول `project_files` در database وجود دارد و index هم دارد.
- source code فعلی هیچ reference به `project_files` ندارد.
- File Manager مستقیم filesystem را می‌خواند، rename/delete می‌کند و metadata transaction/audit ندارد.

**اثر:**

metadata قدیمی، file واقعی و permissionها از هم جدا می‌شوند. معلوم نیست دو record legacy واقعاً به کدام fileها تعلق دارند. حذف project هم folder disk را پاک نمی‌کند.

**اصلاح لازم:**

تصمیم معماری لازم است:

- یا table legacy را پس از backup/migration حذف کنید؛
- یا file metadata را رسمی کنید، foreign key، checksum، uploaded_by، deleted_at، audit و cleanup transaction اضافه کنید.

---

### DB-13 — بدون audit log، owner و version برای data حساس

**وضعیت:** تأییدشده  
**اثر:**

هیچ جدول audit برای این تغییرات وجود ندارد:

- چه کسی پروژه را ساخت/ویرایش/حذف کرد
- چه کسی status یا weight را تغییر داد
- چه کسی permission را عوض کرد
- چه کسی فایل را delete/rename کرد
- چه زمانی و چرا تغییر انجام شد

`pm_projects` فقط `created_at/updated_at` دارد، اما `created_by`، `updated_by`، version یا change history ندارد. Priority هم created_by ندارد.

**اصلاح لازم:**

- `created_by`, `updated_by`, optimistic `version`
- جدول immutable audit/event log
- soft delete برای project/fileهای مهم
- restore policy

---

### DB-14 — CSRF، cookie hardening و session fixation کامل نیست

**وضعیت:** تأییدشده  
**شواهد:**

- CSRF token یا Origin/Referer validation در write endpointها نیست.
- `config.php:29–35` cookie `httponly` و `SameSite=Lax` دارد، اما `secure=true` ندارد.
- login `session_regenerate_id()` ندارد.

**اثر:**

در صورت HTTP، cookie ممکن است روی اتصال ناامن ارسال شود. نبود CSRF protection و session regeneration نیز attack surface را افزایش می‌دهد.

**اصلاح لازم:**

HTTPS اجباری، cookie Secure، CSRF token برای تمام mutationها، Origin validation و session regeneration بعد از login.

---

### DB-15 — project access model ownership ندارد

**وضعیت:** تأییدشده در model  

Userی که `add` دارد ولی `view_all_projects` ندارد، پس از ایجاد پروژه ممکن است access row برای پروژهٔ تازه نداشته باشد و نتواند پروژهٔ خودش را ببیند. Database هم owner/create-user برای project ندارد.

**اصلاح لازم:**

- `created_by` یا `owner_user_id` در `pm_projects` اضافه شود.
- هنگام ایجاد پروژه، transaction به‌صورت خودکار access مناسب برای creator بسازد.
- ruleهای ownership و per-project permission واضح شوند.

---

### DB-16 — Database migration/versioning وجود ندارد؛ schema drift اتفاق افتاده است

**وضعیت:** تأییدشده  
**شواهد:**

- `PRAGMA user_version` برابر ۰ است.
- فایل migration/schema/backup در repository وجود ندارد.
- code فقط `CREATE TABLE IF NOT EXISTS` اجرا می‌کند.
- جدول `project_files` در DB موجود است اما source فعلی آن را ایجاد یا استفاده نمی‌کند.

**اثر:**

در release بعدی ALTER، backfill، cleanup یا rollback قابل اعتماد نیست. installهای قدیمی schema متفاوت می‌گیرند و bugهای silent ایجاد می‌شود.

**اصلاح لازم:**

Migration versioned بسازید؛ مثلاً migrationهای شماره‌دار با transaction، backup، pre-check و rollback plan. `PRAGMA user_version` یا migration table را مدیریت کنید.

---

## P2 — عملیاتی، پایداری و Performance

### DB-17 — SQLite/WAL backup و concurrency plan ندارد

**وضعیت:** خطر عملیاتی تأییدشده  
**شواهد:**

- برنامه WAL را فعال می‌کند (`database.php:26–27`).
- backup script، restore guide یا monitoring وجود ندارد.
- `database.sqlite-wal` و `database.sqlite-shm` در هنگام استفاده ممکن است ایجاد شوند.
- code هیچ `busy_timeout`، retry policy یا write queue برای PDO تعریف نمی‌کند.

**اثر:**

Copy کردن فقط `database.sqlite` در زمان active بودن WAL ممکن است backup ناقص بدهد. هم‌زمانی writeها ممکن است `database is locked` ایجاد کند. آخرین save می‌تواند write قبلی را بدون conflict warning overwrite کند.

**اصلاح لازم:**

- backup با SQLite online backup API یا `VACUUM INTO`/`.backup` انجام دهید؛ WAL/SHM را در policy در نظر بگیرید.
- restore drill و retention policy بسازید.
- PDO busy timeout، retry محدود و transaction کوتاه اضافه کنید.
- برای concurrency بالا به PostgreSQL/MySQL مهاجرت یا design queue در نظر گرفته شود.

> نکته: `foreign_keys=ON` هم در SQLite per-connection است. Application آن را در `getDB()` فعال می‌کند، اما هر script یا maintenance connection باید جداگانه آن را روشن کند.

---

### DB-18 — Index و query plan برای رشد داده مناسب نیست

**وضعیت:** تأییدشده  

`EXPLAIN QUERY PLAN` نشان داد:

- list پروژه‌ها برای `ORDER BY updated_at DESC` full scan و temporary B-tree می‌سازد؛ index `updated_at` وجود ندارد.
- search با `%term%` full scan می‌کند؛ indexهای name/client/zone کمک مؤثر نمی‌کنند.
- query بر اساس فقط `pm_project_access.project_id` full scan می‌کند؛ composite index فعلی از `user_id, project_id` شروع می‌شود.
- active item by section full scan می‌کند؛ index مناسب روی `pm_section_items(section_id, active, sort_order)` وجود ندارد.
- list همهٔ پروژه‌ها progress را برای هر پروژه دوباره محاسبه می‌کند (N+1 query pattern).

**اصلاح لازم:**

- index روی `pm_projects(updated_at DESC, id DESC)`
- index روی `pm_project_access(project_id)`
- index روی `pm_section_items(section_id, active, sort_order)`
- pagination
- query تجمیعی برای progress یا cache معتبر
- در صورت نیاز FTS5 برای search، نه `%LIKE%` روی حجم بالا

---

### DB-19 — Validation طول متن، date و حجم داده محدود نیست

**وضعیت:** تأییدشده  

اکنون داده‌های فعلی طول کوتاه و فرمت درست دارند، اما schema و endpointها حد بالای قابل اتکا برای project name، description، comment، priority title، username، filename یا JSON payload ندارند.

**اثر:**

Database bloat، کندی، responseهای بزرگ، XSS surface بزرگ‌تر و denial of service ممکن است.

**اصلاح لازم:**

حداکثر length را هم در client و هم server enforce کنید؛ مثال:

- project/client name: 150–200 character
- description/comment: limit منطقی business
- priority title: 250 character
- username: 64 character
- request body limit و upload quota

---

### DB-20 — Date/time به شکل TEXT و local time ذخیره می‌شود

**وضعیت:** design risk  

برنامه از `datetime('now','localtime')` و stringهای `Y-m-d` / `Y-m-dTH:i` استفاده می‌کند. timezone field، UTC standard و validation database وجود ندارد.

**اثر:**

گزارش‌های تاریخی، daylight/timezone migration و integration بعدی می‌تواند مبهم یا ناسازگار شود.

**اصلاح لازم:**

UTC را در database ذخیره کنید، timezone display را در UI انجام دهید، format ISO 8601 مشخص داشته باشید و start/end date را validate کنید.

---

### DB-21 — Encryption at rest و server hardening خارج از database تعریف نشده

**وضعیت:** خطر deployment  

SQLite به‌صورت عادی plaintext است. در این repository encryption layer، key management، volume encryption policy یا access isolation تعریف نشده است. چون DB داخل web root و با permission باز قرار دارد، این مورد در این پروژه اهمیت بسیار بالا پیدا می‌کند.

**اصلاح لازم:**

- اول storage را private کنید.
- full-disk/volume encryption یا solution دارای key management در production در نظر بگیرید.
- secretها را در environment/secret manager نگه دارید، نه Git.

---

# 4. مواردی که سالم‌اند یا فوری نبودند

این موارد برای جلوگیری از برداشت اشتباه ثبت شده‌اند:

- SQL queryهای اصلی عمدتاً prepared statement هستند؛ SQL injection مستقیم در review فعلی پیدا نشد.
- username و engineer/status nameهایی که unique هستند، unique constraint دارند.
- `pm_project_values` unique `(project_id, item_id)` دارد.
- FKهای تعریف‌شده در `pm_project_values` و `pm_projects` در snapshot فعلی violation ندارند.
- passwordها bcrypt hash هستند، نه cleartext.
- data فعلی role، permission JSON، date format، color format، flagها و status range نامعتبر ندارد.
- `secure_delete=1` فعال است؛ پس حذف pageها از نگاه SQLite بهتر از default ناامن است.

این موارد مثبت‌اند، ولی ضعف authorization، storage exposure و schema lifecycle را جبران نمی‌کنند.

---

# 5. ترتیب اصلاح پیشنهادی

## مرحلهٔ اول — توقف خطرهای فوری

1. Backup امن بگیرید و database واقعی را از Git/Web Root خارج کنید.
2. password همهٔ userها را rotate و sessionها را invalidate کنید.
3. default Admin credential را حذف کنید.
4. server-side authorization همهٔ endpointها را کامل کنید.
5. File Manager authorization bypass و public upload storage را رفع کنید.
6. XSS sinkها و inline handlerها را حذف کنید.
7. `display_errors` و exception leakage را در production خاموش کنید.

## مرحلهٔ دوم — Database repair و migration

1. از database backup بگیرید.
2. access یتیم را بررسی و پاکسازی کنید.
3. ۱۹ value مربوط itemهای inactive را restore/map/version کنید.
4. engineer inactive reference را بررسی کنید.
5. migration برای FK، CHECK، index و `project_files` طراحی کنید.
6. owner/audit/version model برای projectها اضافه کنید.

## مرحلهٔ سوم — پایداری و scale

1. backup/restore automation برای WAL
2. CSRF، Secure cookie، session revoke و security headerها
3. testهای authorization matrix و regression
4. pagination، index، query optimization و FTS در صورت نیاز
5. CI، README deployment و incident/restore guide

---

# 6. نتیجهٔ نهایی

**database فعلی از نگاه SQLite سالم است، اما از نگاه confidentiality، authorization، lifecycle و historical integrity آسیب‌پذیری‌های جدی دارد.**

بزرگ‌ترین خطرها به ترتیب:

1. Database و uploads داخل Web Root و Git
2. Admin bootstrap با credential ثابت
3. Backend authorization bypass برای write/delete/report/priority
4. File Manager authorization bypass و upload storage ناامن
5. Stored XSS از داده‌های project/file/folder
6. FK ناقص و data orphan
7. از دست رفتن/تغییر تاریخچهٔ project valueها با تغییر template
8. sessionهایی که بعد از revoke/delete همچنان معتبر می‌مانند

تا وقتی P0 رفع نشده است، این پروژه باید فقط در محیط محدود و با data غیرحساس استفاده شود.
