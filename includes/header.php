<?php
/**
 * Shared page header: top bar, role-based side navigation, flash messages.
 * Set $page_title and (optionally) $page_subtitle before including this file.
 */
$__u        = current_user();
$__role     = $__u['role'] ?? '';
$__current  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

$__nav = match ($__role) {
    'lecturer' => [
        ['dashboard.php',              'Dashboard',     '◉'],
        ['lecturer/classes.php',       'My Classes',    '▤'],
        ['lecturer/session_start.php', 'Start Session', '▶'],
        ['lecturer/attendance.php',    'Attendance',    '✓'],
    ],
    'student' => [
        ['dashboard.php',        'Dashboard',    '◉'],
        ['student/classes.php',  'My Classes',   '▤'],
        ['student/scan.php',     'Scan QR Code', '▣'],
        ['student/records.php',  'My Records',   '✓'],
    ],
    'school_admin' => [
        ['dashboard.php',       'Dashboard',     '◉'],
        ['admin/classes.php',   'Classes',       '▤'],
        ['admin/class_new.php', 'Create Class',  '＋'],
        ['admin/records.php',   'Class Records', '✓'],
    ],
    'system_admin' => [
        ['dashboard.php',      'Dashboard',    '◉'],
        ['sysadmin/users.php', 'Manage Users', '☰'],
        ['sysadmin/health.php','Troubleshoot', '✚'],
        ['sysadmin/logs.php',  'System Logs',  '⚙'],
    ],
    default => [],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'Dashboard') ?> &middot; <?= e(APP_SHORT) ?></title>
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body>

<div class="topbar">
    <div class="brand">
        <span class="brand-mark">QR</span>
        <span>
            <strong><?= e(APP_SHORT) ?></strong>
            <small>GPS-Enabled QR Code Attendance System</small>
        </span>
    </div>
    <div class="topbar-right">
        <span class="userchip">
            <strong><?= e($__u['full_name'] ?? '') ?></strong>
            <small><?= e(role_label($__role)) ?></small>
        </span>
        <form method="post" action="<?= e(base_url('logout.php')) ?>" style="margin:0">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Log out</button>
        </form>
    </div>
</div>

<div class="layout">
    <aside class="sidenav">
        <ul>
            <?php foreach ($__nav as [$__path, $__label, $__icon]): ?>
                <li>
                    <a class="<?= str_ends_with($__current, '/' . $__path) ? 'active' : '' ?>"
                       href="<?= e(base_url($__path)) ?>">
                        <span class="ico"><?= $__icon ?></span><?= e($__label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <main class="content">
        <div class="page-head">
            <h1><?= e($page_title ?? 'Dashboard') ?></h1>
            <?php if (!empty($page_subtitle)): ?>
                <p><?= e($page_subtitle) ?></p>
            <?php endif; ?>
        </div>

        <?php foreach (take_flashes() as $__f): ?>
            <div class="alert alert-<?= e($__f['type']) ?>"><?= e($__f['message']) ?></div>
        <?php endforeach; ?>
