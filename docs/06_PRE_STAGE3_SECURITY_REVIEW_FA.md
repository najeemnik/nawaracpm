# گزارش بازبینی امنیتی و طراحی پیش از مرحلهٔ ۳ — NawAra CPM

**تاریخ:** ۸ اکتوبر ۲۰۲۶
**نسخهٔ بازبینی‌شده:** `f4832f2` (`feat: add secure task management foundation`)
**نوع بازبینی:** source-code، schema/design، تنظیمات و آزمایش‌های بی‌خطر در clone موقت
**نتیجه:** **مرحلهٔ ۳ برای پیاده‌سازی Task UI/API هنوز تأیید نمی‌شود.** ابتدا دو مورد P0 و سپس موارد P1 این گزارش باید اصلاح و دوباره آزمایش شوند.

> این بررسی هیچ تغییری در `data/database.sqlite` اصلی، migrationهای موجود یا فایل‌های اجرایی ایجاد نکرده است. آزمایش HTTP فقط روی `/tmp/nawaracpm-stage2-test/` انجام شد و آزمایش XSS فقط داخل DOM محلی و با یک flag بی‌خطر انجام شد؛ هیچ payload به سیستم اصلی، GitHub یا کاربر دیگری فرستاده نشد.

---

## 1. خلاصهٔ مدیریتی

در مرحلهٔ ۲، چند کنترل مهم درست اضافه شده‌اند: CSRF برای mutationها، refresh شدن user و `auth_version` در requestهای محافظت‌شده، جلوگیری از Client در workspace داخلی، authorization بهتر endpointهای قدیمی، error response عمومی، migration ledger، محدودیت اولیهٔ upload و audit event پایه. این‌ها نقاط مثبت واقعی‌اند.

اما دو خطر فعلی می‌توانند قبل از هر Task API به افشای اطلاعات یا تصاحب session Admin منجر شوند:

1. **Database و runtime storage در repository عمومی GitHub و به‌صورت پیش‌فرض زیر Web Root هستند.** GitHub API تأیید کرد که repository `najeemnik/nawaracpm` در زمان بازبینی public است و `data/database.sqlite` در شاخهٔ `main` قابل دسترس است. همچنین server آزمایشی غیر-Apache، با وجود `.htaccess`، `GET /data/database.sqlite` را با `200 OK` برگرداند.
2. **Stored XSS تأیید شد.** نام پروژه و نام/path فایل یا folder داخل `innerHTML` و inline JavaScript handler ساخته می‌شوند. `esc()` فقط برای text/HTML context مناسب است، نه برای JavaScript داخل attribute. یک نام کنترل‌شده می‌تواند attribute/event handler جدید وارد کند و در browser Admin اجرا شود.

این دو مورد همدیگر را شدیدتر می‌سازند: database public می‌تواند hashها، project data، permissionها و بعداً push subscriptionها را افشا کند؛ XSS نیز می‌تواند session مجاز Admin را برای انجام actionهای CSRF-protected در origin خود برنامه سوءاستفاده کند.

---

## 2. روش و شواهد بازبینی

| بررسی | نتیجهٔ تأییدشده |
|---|---|
| وضعیت repository | `gh repo view` گزارش داد `isPrivate: false` برای `https://github.com/najeemnik/nawaracpm`. |
| وجود DB روی `main` | GitHub Contents API metadata مربوط به `main/data/database.sqlite` را برگرداند؛ محتوا عمداً خوانده/نمایش داده نشد. |
| storage مستقیم HTTP | در clone موقت، `GET /data/database.sqlite` پاسخ `200 OK`, `application/octet-stream` و body غیرخالی داد. `/uploads/` و حتی `.htaccess` نیز توسط آن server static خوانده شدند. این آزمایش نشان می‌دهد `.htaccess` برای Nginx/static server محافظت نیست. |
| XSS پروژه | DOM test محلی، با template دقیق project action در `script.js`، نشان داد نام پروژه می‌تواند event attribute جدید بسازد و flag بی‌خطر محلی را اجرا کند. |
| XSS فایل/folder | DOM test محلی، با template rename/open در `script.js`، نشان داد HTML escaping درون `'...'` JavaScript literal دوباره به quote واقعی decode می‌شود و handler را می‌شکند. |
| headerهای browser | پاسخ login در clone دارای `X-Content-Type-Options`, `Referrer-Policy` و `Permissions-Policy` بود، اما CSP، `X-Frame-Options`/`frame-ancestors` و HSTS نداشت. |
| database اصلی | فقط checksum حفاظت‌شده بررسی شد؛ DB اصلی باز، migrate یا برای test استفاده نشد. |

### درجه‌بندی

- **P0 / بحرانی:** پیش از internet/public deployment باید متوقف یا رفع شود.
- **P1 / زیاد مهم:** پیش از باز کردن Task، Client portal، upload جدید یا notification API باید رفع شود.
- **P2 / متوسط:** در hardening release نزدیک رفع شود؛ برای پایداری، privacy یا جلوگیری از سوءاستفاده مهم است.

---

## 3. یافته‌های P0 — باید فوراً رسیدگی شوند

### SEC-01 — افشای database و فایل‌های runtime از Git عمومی و Web Root

**شدت:** P0 / بحرانی
**وضعیت:** تأییدشده
**ناحیه‌های درگیر:** `data/database.sqlite`، `uploads/`، `config.php:24–36`، `file_manager.php:73–76`، GitHub repository و deployment پیش‌فرض.

#### چرا خطرناک است

- `data/database.sqlite` در Git track شده و repository در زمان بازبینی public بود.
- نسخهٔ `main` نیز فایل database را دارد؛ حذف فایل فقط از commit جدید، نسخه‌های قبلی repository یا cloneها را پاک نمی‌کند.
- fallback برنامه storage را زیر `__DIR__/data` و `__DIR__/uploads` قرار می‌دهد؛ در hosting ساده، document root غالباً همان folder برنامه است.
- `.htaccess` موجود فقط زمانی اثر دارد که Apache، `mod_authz` و `AllowOverride` درست پیکربندی شده باشند. Nginx و static server آن را اجرا نمی‌کنند.
- SQLite شامل دادهٔ کاری، username، bcrypt password hash، permission، activity و در مراحل بعدی notification/push subscription خواهد بود. uploadها نیز ممکن است نقشه، قرارداد، گزارش و فایل client را داشته باشند.

#### اثر محتمل

افشای دادهٔ پروژه و اطلاعات هویتی، حملهٔ offline به password hashها، دسترسی به فایل‌های private و بعداً سوءاستفاده از push endpointها. این مورد باید مانند **incident احتمالی افشای داده** دیده شود، نه فقط یک bug آینده.

#### اقدام فوری پیشنهادی

1. تا رفع، application را روی internet/public host deploy نکنید و به repository public دادهٔ جدید اضافه نکنید.
2. مالک repository باید با اولویت بالا visibility را private کند. این کار exposure قبلی، clone، cache یا forkهای احتمالی را پاک نمی‌کند؛ فقط exposure بعدی را کاهش می‌دهد.
3. تمام passwordهای accountهایی که در DB موجود/منتشر بوده‌اند reset یا rotate شوند و sessionها invalidate گردند. دسترسی hosting، backup و هر secret مرتبط نیز بازبینی شود.
4. بدون تأیید جداگانه، history rewrite انجام نشود. قبل از آن backup forensic، inventory fork/release و plan بازگردانی لازم است.
5. در production، `NAWARA_DATA_DIR` و `NAWARA_UPLOADS_DIR` باید **اجباری** و خارج از Document Root باشند؛ production نباید به fallback داخل repository اجازه دهد.
6. سپس، با plan مورد تأیید: DB واقعی و upload runtime از Git current tree و history پاک، `.gitignore` اضافه، و فقط schema/sample کاملاً بی‌خطر نگهداری شود.
7. برای Apache/Nginx و host واقعی test خودکار شود که URLهای `/data/`, `/uploads/`, SQLite/WAL/SHM و dotfileها همگی `403/404` دهند.

> راهنمای `docs/04_DEPLOYMENT_SECURITY_FA.md` جهت درست را بیان می‌کند، اما توصیهٔ عملی به‌تنهایی کافی نیست؛ default ناامن همچنان قابل deploy شدن است. production باید fail-closed باشد.

---

### SEC-02 — Stored XSS در نام پروژه، file/folder و breadcrumb

**شدت:** P0 / بحرانی
**وضعیت:** تأییدشده با DOM test محلی
**ناحیه‌های درگیر:** `script.js:189–224` و `script.js:1179–1227`؛ ورودی از project name، نام/path folder و نام/path file.

#### ریشهٔ مشکل

نمونهٔ پروژه نام را در inline handler وارد می‌کند:

```js
onclick="openFiles(${p.id}, '${safeName}')"
```

اما `safeName` فقط `'` را replace می‌کند؛ quote دوتایی، backslash و syntax/HTML context به‌درستی encode نمی‌شوند. بخش file manager نیز الگوی زیر دارد:

```js
onclick="fmRename('${esc(folder.path)}','${esc(folder.name)}')"
```

`esc()` برای text node مناسب است، اما entity مانند quote در HTML parser دوباره decode می‌شود و سپس JavaScript handler آن را به‌عنوان quote واقعی می‌بیند. بنابراین HTML escaping جای JavaScript-string escaping نیست.

`fm_clean_name()` نیز quote و چند character لازم برای جلوگیری از این مسئله را منع نمی‌کند. این restriction به‌تنهایی راه‌حل نیست؛ هر output باید با context صحیح render شود.

#### اثر محتمل

کاربری که اجازهٔ ساخت/rename folder یا upload file دارد، یا کاربری که project name را ذخیره می‌کند، می‌تواند script را در browser کسی که فهرست پروژه یا فایل را باز می‌کند اجرا کند. اگر قربانی Admin باشد، script در origin معتبر NawAra اجرا می‌شود و می‌تواند requestهای مجاز همراه CSRF token/session قربانی بسازد یا اطلاعات قابل‌دسترسی او را بخواند.

#### اصلاح لازم

1. تمام inline `onclick`, `ondblclick`, `onchange` و string-built handlerهای dynamic حذف شوند.
2. برای text از `textContent`، برای ID از `dataset` و برای action از `addEventListener()` استفاده شود. نام پروژه/file هرگز نباید بخشی از source code باشد.
3. componentهای file/project با DOM API یا template frameworkی که text/attribute context را درست encode می‌کند ساخته شوند؛ نه `innerHTML` با data قابل‌کنترل.
4. `fm_clean_name()` به allowlist business-friendly (مثلاً letters/numbers/space/`._-()` با Unicode policy مشخص) محدود شود، اما output encoding همچنان اجباری بماند.
5. پس از حذف inline handlerها، CSP سخت‌گیرانه اجرا شود (رجوع به SEC-07).
6. regression test شامل quoteهای تکی/دوتایی، backslash، HTML delimiter، Unicode و file/folder/project nameهای malformed اضافه شود.

---

## 4. یافته‌های P1 — blockerهای طراحی برای مرحلهٔ Task/Client

### SEC-03 — دو منبع authorization و تضاد با Permission Matrix

**شدت:** P1 / زیاد مهم
**وضعیت:** تأییدشده در source/design
**ناحیه‌های درگیر:** `config.php:220–381`، `load_projects.php`، `users.php:188–282`، `migrations.php` و `docs/02_PERMISSION_MATRIX_FA.md`.

اکنون legacy endpointها از `pm_project_access` استفاده می‌کنند، در حالی‌که migration جدید `pm_project_members` را اضافه کرده است. UI user management هر دو table را sync می‌کند، اما authorization اصلی (`canViewProject()`/`canDoOnProject()`) هنوز legacy table را مرجع می‌گیرد. `pm_project_members.permissions` عملاً برای authorization legacy استفاده نمی‌شود.

همچنان document مدل `head_admin` و `project_admin` را تعریف می‌کند، ولی code فعلی هر user با `role='admin'` را بدون project scope مجاز می‌داند. یعنی `membership_role='project_admin'` هنوز یک capability enforcement واقعی نیست.

#### خطر

در Task API جدید اگر بعضی endpointها از member table و بعضی از access table استفاده کنند، revoke، assignment و project scope ممکن است ناسازگار شود و به BOLA/IDOR منجر گردد. یک Project Admin محدود نیز ممکن است ناخواسته به Admin سراسری تبدیل شود یا بالعکس permission مورد انتظار خود را نداشته باشد.

#### اصلاح لازم

- قبل از Task endpoint، یک authorization service واحد بسازید: `requireProjectPermission($projectId, $permission)`.
- دقیقاً یک canonical source برای membership و project-scoped permission انتخاب شود؛ legacy table فقط compatibility adapter موقت باشد، نه source دوم.
- roleهای global (`head_admin` یا معادل روشن) از project role (`project_admin`) جدا شوند؛ `role=admin` نباید خودکار به معنی همهٔ پروژه‌ها باشد مگر صریحاً Head Admin باشد.
- migration reconciliation فقط با backup، report تفاوت رکوردها و transaction انجام شود. API جدید نباید همزمان از هر دو model تصمیم بگیرد.
- test matrix برای remove-from-project، downgrade admin، client membership، user deactivation و concurrent update اجباری است.

---

### SEC-04 — task foundation relation/state invariantها را enforce نمی‌کند

**شدت:** P1 / زیاد مهم پیش از هر Task API
**وضعیت:** schema foundation است؛ endpoint Task هنوز وجود ندارد، پس این مورد فعلاً surface عمومی ندارد اما blocker مرحلهٔ ۳ است.
**ناحیه‌های درگیر:** `migrations.php`، tableهای `pm_tasks`, `pm_task_assignees`, `pm_task_reviewers`, `pm_task_client_recipients`, `pm_task_comments`, `pm_task_dependencies` و `pm_task_attachments`.

نمونهٔ invariantهای هنوز enforce‌نشده:

- `parent_task_id` و dependencyها تضمین نمی‌کنند هر دو Task متعلق به همان project باشند؛ dependency cycle نیز جز self-reference منع نشده است.
- `section_id` و `item_id` تضمین نمی‌کنند item متعلق به همان section باشد.
- assignee/reviewer/client recipient تضمین نمی‌کنند user عضو همان project، active و دارای account type مناسب باشد.
- `assignment_mode='single'` یک assignee واحد را enforce نمی‌کند؛ `all_assignees_required` نیز حداقل assignee required یا completion همه را enforce نمی‌کند.
- `client_approval_required=1` الزاماً internal review، publication و client recipient معتبر را enforce نمی‌کند؛ flagهای visibility و approval status می‌توانند contradictory شوند.
- comment با `visibility='client'` می‌تواند در schema بدون published task/recipient ایجاد شود.
- transition state machine در DB/service هنوز وجود ندارد؛ columnهای status به‌تنهایی جلوی transition غیرمجاز را نمی‌گیرند.
- attachment metadata پس از cascade delete ممکن است از filesystem جدا شود؛ ownership/storage lifecycle تعریف نشده است.

#### اصلاح لازم

Task API فقط از یک **server-side transition service** عبور کند؛ هر transition باید actor، membership، object scope، old status، policy، attachment/comment requirement و client gate را در یک transaction بررسی کند و سپس activity/notification ایجاد کند.

برای invariantهای relational، triggerهای SQLite یا validation service transaction-safe اضافه شود؛ دست‌کم testهای مستقیم API باید cross-project parent/dependency/assignee/reviewer/client attempts را `403/422` کنند. Client queryها باید projection جدا داشته باشند، نه filter سطح UI.

---

### SEC-05 — storage فایل: quota کلی، malware policy و lifecycle قابل اتکا نیست

**شدت:** P1 / زیاد مهم
**وضعیت:** تأییدشده
**ناحیه‌های درگیر:** `file_manager.php:73–89`, `155–178`, `428–506`, `510–536`.

کنترل‌های فعلی خوب‌اند: path canonicalized می‌شود، symlink traversal تا حد زیادی مسدود است، extension allowlist وجود دارد، upload request limit دارد و download application-authorized/attachment است. با این حال:

- quota فقط per request است؛ quota per project/user/system، retention و monitoring disk وجود ندارد. کاربر دارای file permission می‌تواند با requestهای متعدد disk را پر کند.
- validation اساساً extension است؛ `finfo`/magic-byte policy، archive policy، malware/antivirus workflow و quarantine وجود ندارد.
- نام original روی filesystem استفاده می‌شود؛ storage key غیرقابل حدس و metadata رسمی برای legacy file manager وجود ندارد.
- uploadها به‌صورت پیش‌فرض زیر Web Root و با permission ساخت `0755` ایجاد می‌شوند؛ SEC-01 این خطر را بسیار بیشتر می‌کند.
- حذف project فایل‌های `uploads/project_<id>` را تعیین تکلیف نمی‌کند؛ نه purge امن وجود دارد، نه archive/retention، نه restore state روشن.
- یک permission `files` هم view/download و هم upload/rename/delete را پوشش می‌دهد؛ برای client/task deliverable آینده کافی نیست.

#### اصلاح لازم

Storage را خارج Web Root ببرید، نام ذخیره‌شده را random/key-based کنید، metadata را در DB با owner/project/task/checksum/quarantine/deleted state نگهدارید و download را تنها از authorization endpoint بدهید. quota تجمعی، file count، alerting، MIME/signature validation و policy archive/malware پیش از PWA upload جدید لازم‌اند. Permissionها حداقل به `view_files`, `download_files`, `upload_files`, `manage_files`, `publish_files_to_client` تفکیک شوند.

---

### SEC-06 — حذف فیزیکی پروژه و retention/audit ناقص

**شدت:** P1 / زیاد مهم
**وضعیت:** تأییدشده
**ناحیه‌های درگیر:** `delete_project.php:38–51`، foreign keyهای migration و filesystem uploads.

حذف پروژه یک `DELETE` فیزیکی است. cascade می‌تواند project values، memberها، Taskهای آینده، task activity و attachment metadata را پاک کند. اما folder فایل legacy روی disk باقی می‌ماند. علاوه بر این، `pm_audit_log.project_id` با `ON DELETE SET NULL` است؛ پس eventهای قبلی file/project بعد از حذف project association خود را از دست می‌دهند. task activity نیز با project/task cascade پاک می‌شود.

این رفتار برای نقشه‌ها، قراردادها و review history ساختمانی ریسک privacy، retention و dispute resolution دارد.

#### اصلاح لازم

- برای project/task/file، soft delete (`deleted_at`, `deleted_by`, reason) و restore window طراحی شود.
- purge نهایی باید job جدا، permission بسیار محدود، retention policy، audit immutable و cleanup storage transaction-aware داشته باشد.
- audit event باید identifier پایدار (project UUID/archived label) داشته باشد تا بعد از delete قابل جست‌وجو بماند.
- پیش از هر destructive action، dependency/file count و effect summary به Admin نشان داده شود.

---

### SEC-07 — CSP و حفاظت clickjacking/frame وجود ندارد

**شدت:** P1 / زیاد مهم (به‌خصوص همراه SEC-02)
**وضعیت:** تأییدشده
**ناحیه‌های درگیر:** `config.php:52–56`، `index.php` و `script.js`.

`X-Content-Type-Options`، Referrer Policy و Permissions Policy وجود دارند، اما `Content-Security-Policy` و `frame-ancestors`/`X-Frame-Options` وجود ندارد. یک سایت مخرب می‌تواند UI را در frame قرار دهد و user واردشده را برای click روی action واقعی گمراه کند. CSRF token این clickjacking را حل نمی‌کند، چون action از UI خود NawAra انجام می‌شود.

CSP فعلی را نمی‌توان بدون refactor ساده اضافه کرد، زیرا صفحه و `script.js` پر از inline event handler و inline script/style هستند. این وابستگی علت دیگر برای رفع SEC-02 است.

#### اصلاح لازم

پس از DOM/event-listener refactor، CSP با `default-src 'self'`، script policy بدون `unsafe-inline`، `object-src 'none'`، `base-uri 'self'` و `frame-ancestors 'none'` (یا allowlist دقیق) اضافه شود. در لایهٔ web server نیز HSTS پس از تأیید HTTPS و `X-Frame-Options: DENY` برای compatibility تنظیم شود. `expose_php=Off` نیز توصیه می‌شود.

---

### SEC-08 — template/status/weight سراسری تاریخچه و progress پروژه‌های قدیمی را تغییر می‌دهد

**شدت:** P1 / integrity/design
**وضعیت:** همچنان باز؛ با audit قدیمی نیز سازگار است
**ناحیه‌های درگیر:** `save_project.php:105–377`، `database.php:282–412` و schema legacy section/status/value.

Settings سراسری section/item/status را deactivate/reactivate یا update می‌کند و `calculateProjectSummary()` فقط metadata فعال فعلی را می‌خواند. بنابراین edit template یا weight امروز می‌تواند progress/report پروژهٔ قدیمی را تغییر دهد. save خالی/ناقص settings نیز ممکن است بخش‌های فعال را deactivate کند. مجموع weightها و date/business constraintها هم در DB/service به‌طور کامل enforce نشده‌اند.

#### اصلاح لازم

قبل از وصل کردن Task completion به progress رسمی، template/status را per-project snapshot/version کنید. تغییر template باید validation مجموع weight، impact preview، optimistic version و audit داشته باشد. Progress رسمی باید از version مشخص پروژه محاسبه شود، نه از template global امروز.

---

### SEC-09 — Priority taskها global هستند و metadata بیش از scope لازم نمایش داده می‌شود

**شدت:** P1 برای privacy/object authorization
**وضعیت:** تأییدشده
**ناحیه‌های درگیر:** `priorities.php` و `load_projects.php?meta=1`.

هر user دارای global permission `priorities` می‌تواند تمام `pm_priorities` را list/toggle/edit/delete کند؛ table owner، project scope یا created_by ندارد. این با مدل account شخصی/Task assignment آینده سازگار نیست. همچنین user authenticated که `meta=1` می‌خواند، metadata سراسری engineers/statuses/sections را می‌گیرد؛ حتی اگر project access مشخصی نداشته باشد.

#### اصلاح لازم

Legacy priority را یا صریحاً «shared admin board» تعریف و فقط برای Admin نگه دارید، یا owner/project visibility و object-level authorization اضافه کنید. API Task جدید نباید این table را به‌عنوان مدل assignment استفاده کند. Metadata نیز به scope لازم و permission مربوط محدود شود.

---

## 5. یافته‌های P2 — hardening و پایداری

### SEC-10 — session idle/absolute timeout و bootstrap secret policy ناقص است

**شدت:** P2 / متوسط
**ناحیه‌های درگیر:** `config.php:37–50`، `database.php:150–175`.

Cookie lifetime تنظیم می‌شود، اما last-activity idle timeout و absolute server-side session expiry وجود ندارد. برای Admin، MFA (حداقل TOTP یا WebAuthn) هنوز وجود ندارد. در DB خالی، برنامه password تصادفی initial admin را در server error log می‌نویسد؛ بهتر است secret در log نوشته نشود و installer/CLI fail-closed باشد. `must_change_password` نیز enforce نشده است.

**اقدام:** idle/absolute expiry server-side، re-auth برای action حساس، MFA برای Admin، installer امن و one-time setup secret خارج از log.

---

### SEC-11 — input validation و schema constraintها یکدست نیستند

**شدت:** P2 / متوسط
**ناحیه‌های درگیر:** legacy tableها، `save_project.php` و `users.php`.

بعضی fieldها limit دارند، اما description/zone/comment/date/template name/count و JSON payloadهای متعدد limit/format business-grade ندارند. legacy schema برای role/flag/range/JSON/date بسیاری از CHECKها را ندارد. color status نیز به hex allowlist محدود نشده و در style context استفاده می‌شود.

**اقدام:** request body limit در web server و app، length/count/date validation، `CHECK`های additive where possible، JSON validation، hex color allowlist و rejection به‌جای silent normalization برای inputهای مهم.

---

### SEC-12 — availability/concurrency: login-attempt growth، SQLite write contention و raceهای حساس

**شدت:** P2 / متوسط
**ناحیه‌های درگیر:** `index.php:22–69`، `users.php`، `database.php` و migration runtime.

- `pm_login_attempts` تا ۳۰ روز رشد می‌کند؛ cleanup در هر login اجرا می‌شود و index مستقل روی `attempted_at` ندارد. username-based throttle نیز می‌تواند برای یک username شناخته‌شده، denial-of-service پانزده‌دقیقه‌ای بسازد.
- check «آخرین Admin» پیش از transaction update انجام می‌شود؛ concurrent requestها باید در transaction/atomic policy دوباره check شوند تا هیچ race به zero-admin نرساند.
- SQLite `busy_timeout=5000` اضافه شده، اما high write volume notification/task/activity می‌تواند lock/timeout بسازد.
- schema creation/migration/seed روی request path اجرا می‌شود؛ deploy یا first concurrent request ممکن است lock/error عملیاتی ایجاد کند.

**اقدام:** rate limit لایهٔ reverse proxy، retention/index/job برای login attempts، atomic last-admin guard، migration CLI/deploy step، short transaction/retry policy و load test پیش از notification scheduler.

---

### SEC-13 — audit log برای forensic-grade audit کافی نیست

**شدت:** P2 / متوسط، برای قرارداد/ممیزی ممکن است P1 شود
**ناحیه‌های درگیر:** `config.php:172–212` و migration audit/task activity.

Audit فعلی شروع خوبی است، ولی در همان SQLite قابل تغییر است، failure آن فقط log می‌شود، before/after کامل همهٔ sensitive actionها ندارد و cascade/delete associationهای تاریخی را کم‌رنگ می‌سازد. notification/task API آینده نیز باید audit event و object authorization را atomically ثبت کند.

**اقدام:** append-only policy در app، event schema version/hash/correlation ID، export/backup immutable یا centralized log برای محیط production، retention و restore drill.

---

## 6. تطبیق با گزارش قبلی Database

`DATABASE_SECURITY_AUDIT_FA.md` در ۷ اکتوبر تهیه شده بود. مرحلهٔ ۲ چند finding آن را واقعاً بهتر کرده است: credential ثابت حذف شده، CSRF و session rotation/auth-version اضافه شده، endpoint authorization بهتر شده، error response عمومی شده، migration ledger و busy timeout آمده و مسیر file manager تا حد زیادی harden شده است.

اما findingهای زیر هنوز باز یا تنها «راهنمای deployment» دارند: storage/Git exposure، XSS، legacy/new access duality، historical template integrity، file retention/quota، schema validation کامل، audit retention و operational backup/concurrency. بنابراین گزارش قبلی نباید به معنی clean بودن نسخهٔ فعلی برداشت شود.

---

## 7. ترتیب اصلاح پیشنهادی و معیار قبولی

### موج اضطراری — پیش از ادامهٔ feature

1. **Containment SEC-01:** private کردن repository، rotation/reset، session invalidation و plan approved برای پاک‌سازی Git history.
2. **Storage fail-closed:** production بدون external data/uploads path اجرا نشود؛ direct URL test روی web server واقعی پاس شود.
3. **XSS remediation SEC-02:** dynamic UI با DOM API/event listener refactor و regression test اجرا شود.

**قبولی:** Git/repository و public URL هیچ DB/upload واقعی ندارند؛ all direct storage paths `403/404` هستند؛ نام project/folder/file با characterهای context-breaking هیچ node/attribute/handler جدید نمی‌سازند.

### موج امنیت معماری — قبل از Task/Client API

1. canonical membership/permission resolver و role policy روشن (SEC-03).
2. server-side Task transition service و cross-project/state invariant tests (SEC-04).
3. file lifecycle/permission/quota design و soft-delete/retention (SEC-05/06).
4. CSP/frame protection پس از refactor (SEC-07).
5. template version/snapshot قبل از progress integration (SEC-08).

**قبولی:** test matrix شامل Employee/Project Admin/Head Admin/Client و direct request برای project/task/file دیگر؛ Client never sees unpublished object; invalid transition/cross-project relation رد می‌شود; deleting/archive project رفتار و retention مشخص دارد.

### موج hardening عملیاتی

session/MFA/installer، validation، database maintenance/load tests، header/server config، audit retention و backup/restore drill (SEC-10 تا SEC-13).

---

## 8. تصمیم لازم از مالک محصول

برای ادامهٔ امن، تأیید جداگانه لازم است:

1. **اجازهٔ remediation اضطراری SEC-01 و SEC-02** (بدون شروع Task UI/API)؛
2. تصمیم دربارهٔ اینکه `pm_project_members` canonical source باشد و legacy `pm_project_access` چگونه/چه زمانی retire شود؛
3. تصمیم retention: soft delete، archive و مدت نگهداری فایل/پروژه؛
4. تأیید plan کنترل‌شده برای پاک‌سازی database از Git history، پس از backup و rotation. این اقدام destructive است و بدون تأیید انجام نمی‌شود.

تا آن زمان، مرحلهٔ ۳ متوقف می‌ماند.
