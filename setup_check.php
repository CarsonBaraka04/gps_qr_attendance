<?php
/**
 * Installation check. Open this page once after importing the database:
 *   http://localhost/gps_qr_attendance/setup_check.php
 * Delete or rename the file afterwards.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$results  = [];
$critical = 0;

/* 1. PHP version */
$phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
$results[] = [$phpOk ? 'ok' : 'bad', 'PHP version', 'PHP ' . PHP_VERSION . ($phpOk ? ' — supported.' : ' — 8.0 or newer is required.')];
if (!$phpOk) { $critical++; }

/* 2. extensions */
foreach (['pdo_mysql' => true, 'json' => true, 'session' => true, 'mbstring' => false, 'openssl' => false, 'gd' => false] as $ext => $required) {
    $loaded = extension_loaded($ext);
    $results[] = [
        $loaded ? 'ok' : ($required ? 'bad' : 'warn'),
        'Extension: ' . $ext,
        $loaded ? 'Loaded.' : ($required ? 'Missing. Enable it in php.ini and restart Apache.' : 'Not loaded. Optional - the system works without it.')
    ];
    if (!$loaded && $required) { $critical++; }
}

/* 3. database */
try {
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $results[] = ['ok', 'Database connection', 'Connected to ' . DB_NAME . ' — ' . count($tables) . ' table(s) found.'];

    foreach (['users', 'classes', 'enrollments', 'sessions', 'qr_codes', 'attendance_records', 'system_logs'] as $t) {
        $bad = !in_array($t, $tables, true);
        $results[] = [$bad ? 'bad' : 'ok', 'Table: ' . $t, $bad ? 'Missing. Import database/gps_qr_attendance.sql.' : count_rows($t) . ' row(s).'];
        if ($bad) { $critical++; }
    }

    $users = (int) db_value('SELECT COUNT(*) FROM users');

    if ($users === 0) {
        $results[] = ['warn', 'Demonstration accounts', 'No users exist. Import the SQL file so that the seeded accounts are created.'];
    } else {
        $results[] = ['ok', 'Demonstration accounts', $users . ' user account(s) available. Default password pattern is shown on the login page.'];

        $admin = db()->query('SELECT email FROM users WHERE role = \'system_admin\' LIMIT 1')->fetchColumn();
        $results[] = $admin
            ? ['ok', 'System administrator', 'Sign in as ' . $admin . '.']
            : ['bad', 'System administrator', 'No system administrator account exists.'];
    }
} catch (Throwable $e) {
    $results[] = ['bad', 'Database connection', $e->getMessage()];
    $critical++;
}

/* 4. writable folders (nothing specific needed, but report the document root) */
$results[] = ['ok', 'Application folder', APP_ROOT];
$results[] = ['ok', 'Base URL detected', base_url() . ' — QR codes will point here.'];

$page_title    = 'Installation Check';
$page_subtitle = $critical === 0 ? 'Everything is ready.' : $critical . ' problem(s) must be fixed first.';
require APP_ROOT . '/includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2><?= $critical === 0 ? '✅ Installation is healthy' : '⚠️ Fix ' . $critical . ' item(s) below' ?></h2>
            <div class="sub">Checked <?= e(date('d M Y H:i:s')) ?></div>
        </div>
        <a class="btn btn-sm" href="<?= e(base_url('index.php')) ?>">Go to the login page</a>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th style="width:70px">Result</th><th style="width:250px">Item</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($results as [$tone, $title, $detail]): ?>
                <tr>
                    <td>
                        <?php if ($tone === 'ok'): ?><span class="badge badge-ok">PASS</span>
                        <?php elseif ($tone === 'warn'): ?><span class="badge badge-warn">WARN</span>
                        <?php else: ?><span class="badge badge-bad">FAIL</span><?php endif; ?>
                    </td>
                    <td><strong><?= e($title) ?></strong></td>
                    <td class="muted"><?= e($detail) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($critical === 0): ?>
        <div class="alert alert-warning" style="margin-top:1rem;margin-bottom:0">
            <strong>Security reminder:</strong> delete or rename <code>setup_check.php</code> and set
            <code>DEBUG_MODE</code> to <code>false</code> in <code>config/config.php</code> before the defence.
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
