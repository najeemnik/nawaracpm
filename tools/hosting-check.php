<?php
/**
 * Nawara Studio — CPM :: بررسی آماده‌گی هاست
 * ==========================================
 * Hosting readiness probe
 *
 * طریقهٔ استفاده:
 *   1. این فایل را با FTP در پوشهٔ public_html هاست آپلود کنید.
 *   2. در مرورگر باز کنید:  https://dmain-shoma.com/hosting-check.php
 *   3. خروجی را کپی کنید و برای من بفرستید.
 *   4. بعد از بررسی، حتماً این فایل را از هاست پاک کنید.
 *
 * این اسکریپت هیچ رمزی را نشان نمی‌دهد، چیزی در دیتابیس نمی‌نویسد
 * و فقط اطلاعات محیطی را گزارش می‌کند.
 */

declare(strict_types=1);

function row(string $label, $value, string $status = 'info', string $note = ''): void
{
    $badge = [
        'ok'   => '<span style="color:#0a7d33;font-weight:700">✔ مناسب</span>',
        'warn' => '<span style="color:#a86400;font-weight:700">⚠ توجه</span>',
        'bad'  => '<span style="color:#b3261e;font-weight:700">✘ مشکل</span>',
        'info' => '<span style="color:#444">–</span>',
    ][$status] ?? '';

    echo '<tr>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . htmlspecialchars($label, ENT_QUOTES) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;font-family:monospace">' . htmlspecialchars((string)$value, ENT_QUOTES) . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee">' . $badge . '</td>'
        . '<td style="padding:6px 10px;border-bottom:1px solid #eee;color:#666;font-size:13px">' . htmlspecialchars($note, ENT_QUOTES) . '</td>'
        . '</tr>';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>بررسی آماده‌گی هاست — Nawara Studio</title>
<style>
  body{font-family:Tahoma,"Segoe UI",sans-serif;background:#f6f7f9;margin:0;padding:24px;color:#1a1a1a;direction:rtl}
  .wrap{max-width:1000px;margin:0 auto;background:#fff;padding:24px 28px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
  h1{margin:0 0 4px;font-size:20px}
  .sub{color:#666;font-size:13px;margin-bottom:20px}
  table{width:100%;border-collapse:collapse;margin-bottom:26px}
  th{text-align:right;padding:8px 10px;background:#eef1f4;font-size:14px}
  h2{font-size:16px;margin:26px 0 8px;padding-bottom:6px;border-bottom:2px solid #e3e7ea}
  .note{background:#fff8e1;border-right:4px solid #f0b429;padding:10px 14px;font-size:13px;line-height:1.8;margin:12px 0}
  .ok{background:#e8f5e9;border-right:4px solid #0a7d33;padding:10px 14px;font-size:13px;line-height:1.8;margin:12px 0}
  .bad{background:#fdecea;border-right:4px solid #b3261e;padding:10px 14px;font-size:13px;line-height:1.8;margin:12px 0}
</style>
</head>
<body>
<div class="wrap">

<h1>بررسی آماده‌گی هاست — Nawara Studio — CPM</h1>
<div class="sub">تاریخ بررسی: <?= htmlspecialchars(date('Y-m-d H:i:s')) ?> &nbsp;|&nbsp; بعد از خواندن نتیجه، این فایل را از هاست پاک کنید.</div>

<h2>۱. نسخهٔ PHP و افزونه‌های ضروری</h2>
<table>
<tr><th>مورد</th><th>مقدار</th><th>وضعیت</th><th>توضیح</th></tr>
<?php
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
row('نسخهٔ PHP', PHP_VERSION, $phpOk ? 'ok' : 'warn', $phpOk ? '' : 'نسخهٔ ۸.۱ یا بالاتر توصیه می‌شود');

$ext = static function (string $name): bool { return extension_loaded($name); };

// حیاتی‌ترین مورد کل پروژه
if ($ext('pdo_sqlite')) {
    row('pdo_sqlite', 'موجود', 'ok', 'دیتابیس SQLite کار می‌کند — مهم‌ترین مورد');
} else {
    row('pdo_sqlite', 'موجود نیست', 'bad', 'بدون این افزونه، دیتابیس فعلی شما روی این هاست کار نمی‌کند');
}

row('sqlite3', $ext('sqlite3') ? 'موجود' : 'موجود نیست', $ext('sqlite3') ? 'ok' : 'warn', 'نسخهٔ کتابخانهٔ SQLite');

if ($ext('pdo_mysql')) {
    row('pdo_mysql', 'موجود', 'info', 'جایگزین احتمالی اگر SQLite پشتیبانی نشود');
} else {
    row('pdo_mysql', 'موجود نیست', 'info', '');
}

foreach ([
    'json'      => 'ضروری',
    'mbstring'  => 'برای متن دری/پشتو ضروری',
    'openssl'   => 'برای HTTPS و رمزنگاری ضروری',
    'curl'      => 'برای Push و AI بعداً لازم است',
    'gd'        => 'فشرده‌سازی تصویر (اگر imagick نباشد)',
    'imagick'   => 'فشرده‌سازی تصویر (اختیاری)',
    'zip'       => 'خروجی و بکاپ',
    'intl'      => 'تاریخ شمسی و عددگذاری محلی (اختیاری)',
] as $e => $why) {
    row($e, $ext($e) ? 'موجود' : 'موجود نیست', $ext($e) ? 'ok' : 'warn', $why);
}

if ($ext('pdo_sqlite')) {
    try {
        $v = (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn();
        row('نسخهٔ SQLite', $v, version_compare($v, '3.35.0', '>=') ? 'ok' : 'warn',
            'نسخهٔ ۳.۳۵+ برای ویژگی‌های پیشرفته بهتر است');
    } catch (Throwable $e) {
        row('نسخهٔ SQLite', 'خطا', 'bad', htmlspecialchars($e->getMessage()));
    }
}
?>
</table>

<h2>۲. فضای دیسک و محدودیت‌های آپلود</h2>
<table>
<tr><th>مورد</th><th>مقدار</th><th>وضعیت</th><th>توضیح</th></tr>
<?php
$free = @disk_free_space(__DIR__);
$total = @disk_total_space(__DIR__);
row('فضای آزاد هاست', $free ? number_format($free / 1073741824, 2) . ' GB' : 'نامعلوم',
    ($free && $free > 2 * 1073741824) ? 'ok' : 'warn', 'کمتر از ۲ گیگ برای عکس‌های سایت کم است');
row('فضای کل', $total ? number_format($total / 1073741824, 2) . ' GB' : 'نامعلوم', 'info', '');

row('upload_max_filesize', ini_get('upload_max_filesize'), 'info', 'ما تصویر را در مرورگر فشرده می‌کنیم، پس مهم نیست');
row('post_max_size', ini_get('post_max_size'), 'info', '');
row('memory_limit', ini_get('memory_limit'), 'info', '');
row('max_execution_time', ini_get('max_execution_time'), 'info', 'برای cron مهم است');
?>
</table>

<h2>۳. مسیر فایل‌ها و Web Root</h2>
<table>
<tr><th>مورد</th><th>مقدار</th><th>وضعیت</th><th>توضیح</th></tr>
<?php
row('مسیر فعلی اسکریپت', __DIR__, 'info', 'اگر inside public_html است، یعنی این همان Web Root است');
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
row('Document Root', $docRoot ?: 'نامعلوم', 'info', '');

// مسیر خارج از Web Root: یک سطح بالاتر
$outside = dirname($docRoot ?: __DIR__);
$outsideWritable = @is_writable($outside);
row('مسیر خارج از Web Root', $outside, $outsideWritable ? 'ok' : 'warn',
    $outsideWritable ? 'می‌توانیم دیتابیس را اینجا بگذاریم (امن)' : 'نوشتن در این مسیر مجاز نیست — باید راه دیگر پیدا کنیم');

$testDir = $outside . '/nawara_probe_test';
$created = @mkdir($testDir, 0700);
if ($created) {
    @rmdir($testDir);
    row('تست ساخت پوشه در خارج از Web Root', 'موفق', 'ok', 'دیتابیس می‌تواند کاملاً خارج از دسترس مرورگر باشد');
} else {
    row('تست ساخت پوشه در خارج از Web Root', 'ناموفق', 'warn', 'باید از مسیر داخل public_html با محافظت .htaccess استفاده کنیم');
}

row('.htaccess / mod_rewrite',
    (function_exists('apache_get_modules') && in_array('mod_rewrite', apache_get_modules(), true)) ? 'فعال' : 'نامعلوم',
    'info', 'اگر فعال باشد، می‌توانیم دسترسی مستقیم به پوشهٔ data را ببندیم');
?>
</table>

<h2>۴. HTTPS و دامنه</h2>
<table>
<tr><th>مورد</th><th>مقدار</th><th>وضعیت</th><th>توضیح</th></tr>
<?php
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
row('اتصال فعلی', $isHttps ? 'HTTPS ✔' : 'HTTP', $isHttps ? 'ok' : 'warn',
    'PWA بدون HTTPS اصلاً نصب نمی‌شود');
row('هاست', $_SERVER['HTTP_HOST'] ?? 'نامعلوم', 'info', 'زیردامنه‌ها باید همین‌جا تعریف شوند');
?>
</table>

<h2>۵. مواردی که باید در cPanel خودتان چک کنید</h2>
<table>
<tr><th>مورد</th><th>کجا</th><th>چرا مهم است</th></tr>
<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">Subdomains</td><td style="padding:6px 10px;border-bottom:1px solid #eee">cPanel ← Domains ← Subdomains</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:13px">برای <code>app.</code> و <code>tasks.</code> لازم است</td></tr>
<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">SSL برای زیردامنه</td><td style="padding:6px 10px;border-bottom:1px solid #eee">cPanel ← SSL/TLS Status ← Run AutoSSL</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:13px">SSL رایگان باید زیردامنه‌ها را هم پوشش دهد، وگرنه PWA نصب نمی‌شود</td></tr>
<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">Cron Jobs</td><td style="padding:6px 10px;border-bottom:1px solid #eee">cPanel ← Advanced ← Cron Jobs</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:13px">برای یادآوری و هشدار تأخیر ضروری است — بدون آن سیستم نوتیفیکیشن ناقص می‌ماند</td></tr>
<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">انتخاب نسخهٔ PHP</td><td style="padding:6px 10px;border-bottom:1px solid #eee">cPanel ← Select PHP Version</td><td style="padding:6px 10px;border-bottom:1px solid #eee;font-size:13px"> ticks کنید: pdo_sqlite، sqlite3، mbstring، gd، intl</td></tr>
</table>

<h2>۶. ارزیابی کلی</h2>
<?php
$blockers = [];
if (!$ext('pdo_sqlite')) {
    $blockers[] = 'افزونهٔ pdo_sqlite فعال نیست — دیتابیس SQLite شما روی این هاست کار نمی‌کند.';
}
if (!$isHttps) {
    $blockers[] = 'در حال حاضر روی HTTPS نیستید — PWA بدون SSL نصب نمی‌شود.';
}
if ($free && $free < 2 * 1073741824) {
    $blockers[] = 'فضای آزاد کمتر از ۲ گیگابایت است.';
}

if ($blockers) {
    echo '<div class="bad"><strong>مواردی که باید حل شود:</strong><ol>';
    foreach ($blockers as $b) {
        echo '<li>' . htmlspecialchars($b) . '</li>';
    }
    echo '</ol></div>';
} else {
    echo '<div class="ok"><strong>هاست از نظر موارد حیاتی آماده است.</strong><br>'
        . 'فقط باقی‌ماندهٔ موارد بخش ۵ را در cPanel چک کنید و نتیجه را برای من بفرستید.</div>';
}
?>

<div class="note">
<strong>یادداشت دربارهٔ حجم ترافیک (۳۰ گیگ در ماه):</strong><br>
عکس‌های سایت اگر بدون فشرده‌سازی آپلود شوند، خیلی زود ترافیک شما را تمام می‌کنند.
برای همین در طراحی سیستم، تصویر <strong>در مرورگر فشرده می‌شود</strong> (تقریباً ۲۰۰ تا ۳۰۰ کیلوبایت به‌جای ۳ تا ۵ مگابایت)
و فقط بعد از آن آپلود می‌گردد. با این کار، ۳۰ گیگ برای استفادهٔ شما کافی خواهد بود.
</div>

</div>
</body>
</html>
