# گزارش آزمون مرحلهٔ ۲ — زیرساخت امن و مدل پایه

**تاریخ آزمون:** ۸ اکتوبر ۲۰۲۶ (Asia/Kabul)

**نتیجه:** قبول شد — تمام آزمون‌های زیر در یک کپی ایزوله اجرا شدند؛ فایل اصلی `data/database.sqlite` تغییر نکرد.

## محیط آزمون

- PHP 8.3 Wasm در یک کپی موقت از برنامه اجرا شد.
- دیتابیس آزمون از بکاپ پیش از مرحله ساخته شد، نه از دیتابیس اصلی مخزن.
- SHA-256 دیتابیس اصلی پیش و پس از آزمون یکسان بود:

```text
537aceca969f105f57fccb43885826a402f262438da99c537243a6e734f2266d
```

## آزمون‌های مهاجرت و یکپارچگی

موارد زیر با موفقیت بررسی شد:

1. هر شش migration ثبت و فقط یک‌بار اجرا شدند.
2. جدول‌های عضویت پروژه، Task، assignee، reviewer، client recipient، comment، attachment، checklist، dependency، activity، notification، push، audit و login-attempt ایجاد شدند.
3. ستون‌های امنیتی `pm_users` شامل `account_type`، `auth_version`، `updated_at`، `last_login_at` و `password_changed_at` ایجاد شدند.
4. اجرای دوبارهٔ initialization idempotent بود.
5. حذف یک پروژهٔ آزمایشی، Task، comment و attachment وابسته را با foreign key cascade حذف کرد.
6. `PRAGMA foreign_key_check` بدون خطا و `PRAGMA integrity_check` برابر `ok` بود.

## آزمون‌های کارکرد و مجوز

آزمون HTTP با sessionهای جداگانهٔ Admin، Employee محدود، Editor، Client و کاربر غیرفعال‌شده انجام شد:

- کاربر ناشناس نتوانست پروژه‌ها یا فهرست کاربران را بخواند.
- Admin توانست گزارش پروژه را باز کند، Priority بسازد/تکمیل/حذف کند، پروژه بسازد/حذف کند، حساب بسازد، غیرفعال و دوباره فعال کند.
- Employee محدود فقط پروژهٔ اختصاص‌داده‌شده را دید و نتوانست پروژهٔ دیگر را با ID حدس بزند، ویرایش کند، حذف کند، PDF بگیرد، فایل‌ها را ببیند، Priority یا کاربران را باز کند.
- Editor فقط پروژهٔ اختصاص‌داده‌شده را ویرایش کرد و نتوانست پروژهٔ دیگر یا حذف پروژه را انجام دهد.
- تغییر مشخصات یک کاربر بدون ارسال `project_access`، عضویت قبلی پروژه را حذف نکرد.
- Client با وجود داشتن رکورد دسترسی آزمایشی، هیچ اطلاعات workspace داخلی، PDF یا فایل را ندید. این رفتار fail-closed تا آماده‌شدن پورتال Client با publish gate عمدی است.

## آزمون‌های امنیتی

- درخواست‌های POST بدون CSRF token برای کاربران، پروژه و فایل با `403` رد شدند.
- پس از deactivation یا تغییر `auth_version`، session موجود فوراً با `401` رد شد.
- session قدیمی پس از invalidation به صفحهٔ ورود امن منتقل شد و ورود دوباره با CSRF تازه کار کرد.
- rate limit ورود پس از ۸ تلاش ناموفق برای یک username در ۱۵ دقیقه فعال شد.
- پاسخ API کاربران هیچ password hash برنگرداند؛ رمز تازه/تغییریافتهٔ کمتر از ۱۰ حرف و project ID ناموجود رد شدند.
- کاهش دسترسی آخرین Admin فعال و تغییرات global settings توسط کاربر محدود رد شدند.
- File Manager مسیرهای `../`، separator ویندوزی و symlink خارج از پوشهٔ پروژه را رد کرد.
- آپلود `php` رد شد؛ آپلود `txt` مجاز با موفقیت ثبت شد؛ بیش از سقف تعداد فایل در یک درخواست رد شد.
- headerهای `X-Content-Type-Options: nosniff` و `Referrer-Policy` بررسی شدند.
- تمام فایل‌های PHP تغییرکرده با parser PHP 8.3 و `script.js` با `node --check` بررسی شدند؛ `git diff --check` نیز بدون خطا بود.

## شرط مهم برای نصب واقعی

این آزمون امنیت کد را بررسی می‌کند، اما حفاظت وب‌سرور نیز ضروری است. راهنمای `04_DEPLOYMENT_SECURITY_FA.md` باید پیش از نشر دنبال شود: مخصوصاً انتقال `NAWARA_DATA_DIR` و `NAWARA_UPLOADS_DIR` به بیرون از public root، فعال‌سازی HTTPS، و deny کردن مستقیم `data/` و `uploads/` در Nginx/Apache.
