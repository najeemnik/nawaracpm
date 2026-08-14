<?php
/**
 * generate_pdf.php
 */

require_once __DIR__ . '/database.php';
requireLoginPage();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    die('Invalid project ID');
}

$pdo = getDB();
$project = getProjectPayload($pdo, $id);
$meta = fetchMeta($pdo);

if (!$project) {
    die('Project not found');
}

$valueMap = [];
foreach ($project['values'] as $v) {
    $valueMap[(int)$v['item_id']] = $v;
}

$statusMap = [];
foreach ($meta['statuses'] as $s) {
    $statusMap[(int)$s['id']] = $s;
}

$engineerMap = [];
foreach ($meta['engineers'] as $e) {
    $engineerMap[(int)$e['id']] = $e;
}

function h($str): string
{
    return htmlspecialchars((string)($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function barHtml(int $pct): string
{
    $color = $pct >= 80 ? '#10b981' : ($pct >= 50 ? '#3b82f6' : ($pct >= 25 ? '#f59e0b' : '#ef4444'));
    $w = max($pct, 3);

    return '
        <div class="bar">
            <div class="bar-fill" style="width:' . $w . '%;background:' . $color . ';">
                ' . $pct . '%
            </div>
        </div>
    ';
}

$logoExists = file_exists(__DIR__ . '/logo.png');
$printDate = date('Y-m-d H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Report - <?php echo h($project['project_name']); ?></title>
<style>
@page{size:A4;margin:14mm;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Segoe UI,Tahoma,sans-serif;color:#1e293b;background:#fff;font-size:11px;line-height:1.5;}
.wrap{max-width:190mm;margin:0 auto;padding:15px;}
.no-print{text-align:center;margin:15px 0;}
.btn{border:none;border-radius:8px;padding:10px 24px;cursor:pointer;font-weight:700;}
.btn-primary{background:#6366f1;color:#fff;}
.btn-secondary{background:#64748b;color:#fff;}
.header{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #6366f1;padding-bottom:14px;margin-bottom:16px;}
.brand{display:flex;align-items:center;gap:12px;}
.logo{width:52px;height:52px;border-radius:12px;background:#eef2ff;display:flex;align-items:center;justify-content:center;overflow:hidden;font-weight:800;color:#4f46e5;}
.logo img{width:100%;height:100%;object-fit:contain;}
.brand h1{font-size:18px;}
.brand p{color:#64748b;font-size:10px;}
.meta{text-align:right;color:#64748b;font-size:10px;}
.info{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;}
.card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:8px;}
.label{font-size:8px;text-transform:uppercase;color:#94a3b8;font-weight:700;margin-bottom:2px;}
.value{font-size:12px;font-weight:700;}
.section{margin-top:12px;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;}
.section-title{background:#eef2ff;color:#4338ca;font-weight:800;padding:8px 10px;border-bottom:1px solid #e2e8f0;}
table{width:100%;border-collapse:collapse;}
td,th{border-bottom:1px solid #f1f5f9;padding:6px 8px;text-align:left;vertical-align:top;}
th{background:#f8fafc;color:#475569;font-size:9px;text-transform:uppercase;}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;color:#fff;font-size:9px;font-weight:700;}
.bar{height:21px;background:#e2e8f0;border-radius:20px;overflow:hidden;}
.bar-fill{height:100%;border-radius:20px;color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;font-size:10px;}
.footer{display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;margin-top:14px;padding-top:8px;color:#94a3b8;font-size:9px;}
@media print{.no-print{display:none!important;}body{-webkit-print-color-adjust:exact;print-color-adjust:exact;}}
</style>
</head>
<body>

<div class="no-print">
    <button class="btn btn-primary" onclick="window.print()">Print / Save PDF</button>
    <button class="btn btn-secondary" onclick="window.close()">Close</button>
</div>

<div class="wrap">

    <div class="header">
        <div class="brand">
            <div class="logo">
                <?php if ($logoExists): ?>
                    <img src="logo.png" alt="Logo">
                <?php else: ?>
                    NawAra
                <?php endif; ?>
            </div>
            <div>
                <h1><?php echo h(APP_NAME); ?></h1>
                <p><?php echo h(APP_SUBTITLE); ?></p>
            </div>
        </div>
        <div class="meta">
            <div>Project Report</div>
            <strong><?php echo h($printDate); ?></strong>
            <div>Project ID: #<?php echo (int)$project['id']; ?></div>
        </div>
    </div>

    <div class="info">
        <div class="card"><div class="label">Project Name</div><div class="value"><?php echo h($project['project_name']); ?></div></div>
        <div class="card"><div class="label">Client</div><div class="value"><?php echo h($project['client_name']); ?></div></div>
        <div class="card"><div class="label">Zone</div><div class="value"><?php echo h($project['zone']); ?></div></div>
        <div class="card"><div class="label">Lead Engineer</div><div class="value"><?php echo h($project['lead_engineer_name'] ?: '-'); ?></div></div>
        <div class="card"><div class="label">Start Date</div><div class="value"><?php echo h($project['start_date'] ?: '-'); ?></div></div>
        <div class="card"><div class="label">End Date</div><div class="value"><?php echo h($project['end_date'] ?: '-'); ?></div></div>
    </div>

    <?php if (!empty($project['description'])): ?>
        <div class="card" style="margin-bottom:14px;">
            <div class="label">Description</div>
            <?php echo h($project['description']); ?>
        </div>
    <?php endif; ?>

    <div style="margin-bottom:14px;">
        <div class="label">Overall Progress</div>
        <?php echo barHtml((int)$project['progress']); ?>
    </div>

    <?php foreach ($project['section_progress'] as $sp): ?>
        <div style="margin-bottom:8px;">
            <strong><?php echo h($sp['icon'] . ' ' . $sp['name']); ?>:</strong>
            <?php echo (int)$sp['percent']; ?>%
            <small style="color:#94a3b8;">Weight: <?php echo h($sp['weight']); ?>%</small>
        </div>
    <?php endforeach; ?>

    <?php foreach ($meta['sections'] as $section): ?>
        <div class="section">
            <div class="section-title">
                <?php echo h($section['icon'] . ' ' . $section['name']); ?>
                <span style="font-weight:400;">(<?php echo h($section['weight']); ?>%)</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Status</th>
                        <th>Assignee</th>
                        <th>Report Date</th>
                        <th>Comment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($section['items'] as $item): ?>
                        <?php
                        $v = $valueMap[(int)$item['id']] ?? null;
                        $statusName = $v['status_name'] ?? 'Not Started';
                        $statusColor = $v['status_color'] ?? '#6b7280';
                        ?>
                        <tr>
                            <td><?php echo h($item['name']); ?> <small style="color:#94a3b8;">(<?php echo h($item['weight']); ?>%)</small></td>
                            <td><span class="badge" style="background:<?php echo h($statusColor); ?>"><?php echo h($statusName); ?></span></td>
                            <td><?php echo h($v['assignee_name'] ?? '-'); ?></td>
                            <td><?php echo h($v['report_date'] ?? '-'); ?></td>
                            <td><?php echo h($v['comment'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

    <div class="footer">
        <div><?php echo h(APP_NAME); ?> - v<?php echo h(APP_VERSION); ?></div>
        <div>Print Date: <?php echo h($printDate); ?></div>
        <div>Page 1 of 1</div>
    </div>

</div>

</body>
</html>