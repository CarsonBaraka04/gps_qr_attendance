<?php
/**
 * System Administrator: troubleshoot the system.
 *
 * Runs a set of health checks over the running installation, reports what is
 * wrong in plain language, and offers safe corrective actions. Every action it
 * performs is written to the audit trail.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('system_admin');

/* ----------------------------- Corrective actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (post('action') === 'close_stale_sessions') {
        $stmt = db()->prepare(
            'UPDATE sessions
                SET status = \'closed\'
              WHERE status = \'open\' AND session_date < CURDATE()'
        );
        $stmt->execute();
        $closed = $stmt->rowCount();

        db()->prepare(
            'UPDATE qr_codes q
               JOIN sessions s ON s.session_id = q.session_id
                SET q.is_active = 0
              WHERE s.status = \'closed\''
        )->execute();

        log_action('system_maintenance', 'Closed ' . $closed . ' stale open session(s) and deactivated their QR tokens.');
        flash('success', $closed . ' stale session(s) closed.');
    }

    if (post('action') === 'deactivate_stale_tokens') {
        $stmt = db()->prepare('UPDATE qr_codes SET is_active = 0 WHERE is_active = 1 AND expiry_time < NOW()');
        $stmt->execute();
        $n = $stmt->rowCount();

        log_action('system_maintenance', 'Deactivated ' . $n . ' expired QR token(s).');
        flash('success', $n . ' expired token(s) deactivated.');
    }

    if (post('action') === 'run_check') {
        log_action('system_check', 'Manual system check requested by the system administrator.');
        flash('success', 'Check completed and recorded in the audit trail.');
    }

    redirect(base_url('sysadmin/health.php'));
}

/* ----------------------------- Diagnostics ----------------------------- */
$checks   = [];
$problems = 0;

/* 1. database connection */
try {
    $dbVersion = (string) db()->query('SELECT VERSION()')->fetchColumn();
    $checks[]  = ['ok', 'Database connection', 'Connected to ' . DB_NAME . ' on ' . DB_HOST . ' (server ' . $dbVersion . ').'];
} catch (Throwable $e) {
    $checks[] = ['bad', 'Database connection', 'Failed: ' . $e->getMessage()];
    $problems++;
}

/* 2. required tables */
$expected = ['attendance_records', 'classes', 'enrollments', 'qr_codes', 'sessions', 'system_logs', 'users'];
$found    = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missing  = array_values(array_diff($expected, $found));

if ($missing) {
    $checks[] = ['bad', 'Database tables', 'Missing table(s): ' . implode(', ', $missing) . '. Import database/gps_qr_attendance.sql again.'];
    $problems++;
} else {
    $checks[] = ['ok', 'Database tables', 'All 7 tables are present.'];
}

/* 3. PHP requirements */
$required = ['pdo_mysql', 'json', 'session'];
$absent   = [];

foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $absent[] = $ext;
    }
}

if ($absent) {
    $checks[] = ['bad', 'PHP extensions', 'Not loaded: ' . implode(', ', $absent) . '. Enable them in php.ini and restart Apache.'];
    $problems++;
} else {
    $checks[] = ['ok', 'PHP extensions', 'All required extensions are loaded (PHP ' . PHP_VERSION . ').'];
}

/* 4. clock agreement between PHP and the database */
$dbNow    = strtotime((string) db()->query('SELECT NOW()')->fetchColumn());
$phpNow   = time();
$skewSecs = abs($dbNow - $phpNow);

if ($skewSecs > 30) {
    $checks[] = ['warn', 'Clock agreement', 'PHP and MySQL differ by ' . $skewSecs . ' seconds. QR codes still work because their expiry uses the PHP clock, but the timestamps written by MySQL will differ.'];
    $problems++;
} else {
    $checks[] = ['ok', 'Clock agreement', 'PHP and MySQL agree within ' . $skewSecs . ' second(s).'];
}

/* 5. stale open sessions */
$staleSessions = db()->query(
    'SELECT s.session_id, s.session_date, s.start_time, c.class_code
       FROM sessions s
       JOIN classes c ON c.class_id = s.class_id
      WHERE s.status = \'open\' AND s.session_date < CURDATE()
      ORDER BY s.session_date'
)->fetchAll();

if ($staleSessions) {
    $checks[] = ['warn', 'Stale open sessions',
        count($staleSessions) . ' session(s) were left open on a previous day and still accept scans. Close them below.'];
    $problems++;
} else {
    $checks[] = ['ok', 'Stale open sessions', 'No old sessions are still accepting scans.'];
}

/* 6. expired but still active QR tokens */
$staleTokens = (int) db_value('SELECT COUNT(*) FROM qr_codes WHERE is_active = 1 AND expiry_time < NOW()');

if ($staleTokens > 0) {
    $checks[] = ['warn', 'Expired QR tokens', $staleTokens . ' token(s) are marked active although they have expired. Deactivate them below.'];
    $problems++;
} else {
    $checks[] = ['ok', 'Expired QR tokens', 'No expired token is still marked active.'];
}

/* 7. attendance records whose student is no longer enrolled */
$orphans = (int) db_value(
    'SELECT COUNT(*)
       FROM attendance_records ar
       JOIN sessions s ON s.session_id = ar.session_id
       LEFT JOIN enrollments e ON e.class_id = s.class_id AND e.student_id = ar.student_id
      WHERE e.enrolment_id IS NULL'
);

if ($orphans > 0) {
    $checks[] = ['warn', 'Referential integrity',
        $orphans . ' attendance record(s) belong to a student who is no longer enrolled in that class.'];
    $problems++;
} else {
    $checks[] = ['ok', 'Referential integrity', 'Every attendance record belongs to an enrolled student.'];
}

/* 8. rejected scans and failed logins in the last 7 days */
$rejected = (int) db_value(
    'SELECT COUNT(*) FROM system_logs WHERE action = \'attendance_rejected\' AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
);
$failedLogins = (int) db_value(
    'SELECT COUNT(*) FROM system_logs WHERE action = \'login_failed\' AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
);

$checks[] = [
    $rejected >= 20 ? 'warn' : 'ok',
    'Rejected scans (last 7 days)',
    $rejected . ' scan(s) were rejected, mainly for being outside the geofence or using an expired code. '
    . 'Failed logins in the same period: ' . $failedLogins . '.'
];

/* 9. volume summary */
$checks[] = ['ok', 'Record volumes', sprintf(
    '%d users, %d classes, %d enrolments, %d sessions, %d QR tokens, %d attendance records, %d log entries.',
    count_rows('users'), count_rows('classes'), count_rows('enrollments'), count_rows('sessions'),
    count_rows('qr_codes'), count_rows('attendance_records'), count_rows('system_logs')
)];

/* 10. connection mode */
$checks[] = ['ok', 'Base URL in use', base_url() . ' — QR codes generated now will point at this address.'];

$page_title    = 'Troubleshoot the System';
$page_subtitle = $problems === 0
    ? 'All checks passed.'
    : $problems . ' item(s) need attention.';
require APP_ROOT . '/includes/header.php';
?>

<div class="stat-grid">
    <div class="stat <?= $problems === 0 ? 'ok' : 'warn' ?>">
        <div class="label">Health</div>
        <div class="value"><?= $problems === 0 ? 'OK' : $problems ?></div>
        <div class="hint"><?= $problems === 0 ? 'No problems found' : 'Warning(s) to review' ?></div>
    </div>
    <div class="stat"><div class="label">PHP</div><div class="value" style="font-size:1.1rem"><?= e(PHP_VERSION) ?></div><div class="hint"><?= e(PHP_SAPI) ?></div></div>
    <div class="stat"><div class="label">Database</div><div class="value" style="font-size:1.1rem"><?= e($dbVersion ?? '—') ?></div><div class="hint"><?= e(DB_NAME) ?></div></div>
    <div class="stat"><div class="label">Open sessions</div><div class="value"><?= (int) db_value('SELECT COUNT(*) FROM sessions WHERE status = \'open\'') ?></div><div class="hint">Accepting scans now</div></div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Diagnostic checks</h2><div class="sub">Run against the live database and PHP environment.</div></div>
        <div class="btn-row">
            <form method="post" style="margin:0"><?= csrf_field() ?>
                <input type="hidden" name="action" value="run_check">
                <button type="submit" class="btn btn-sm btn-outline">Re-run and record</button>
            </form>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data">
            <thead><tr><th style="width:70px">Result</th><th style="width:250px">Check</th><th>Finding</th></tr></thead>
            <tbody>
            <?php foreach ($checks as [$tone, $title, $detail]): ?>
                <tr>
                    <td>
                        <?php if ($tone === 'ok'): ?>
                            <span class="badge badge-ok">PASS</span>
                        <?php elseif ($tone === 'warn'): ?>
                            <span class="badge badge-warn">WARN</span>
                        <?php else: ?>
                            <span class="badge badge-bad">FAIL</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($title) ?></strong></td>
                    <td class="muted"><?= e($detail) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($staleSessions || $staleTokens > 0): ?>
    <div class="card">
        <div class="card-head">
            <div><h2>Corrective actions</h2><div class="sub">Safe repairs. Each one is written to the audit trail.</div></div>
        </div>

        <div class="btn-row">
            <?php if ($staleSessions): ?>
                <form method="post" style="margin:0" onsubmit="return confirm('Close all sessions left open on a previous day?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close_stale_sessions">
                    <button type="submit" class="btn btn-danger btn-sm">Close <?= count($staleSessions) ?> stale session(s)</button>
                </form>
            <?php endif; ?>

            <?php if ($staleTokens > 0): ?>
                <form method="post" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="deactivate_stale_tokens">
                    <button type="submit" class="btn btn-sm">Deactivate <?= $staleTokens ?> expired token(s)</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($staleSessions): ?>
            <div class="table-wrap" style="margin-top:1rem">
                <table class="data">
                    <thead><tr><th class="num">Session</th><th>Class</th><th>Date</th><th>Start time</th></tr></thead>
                    <tbody>
                    <?php foreach ($staleSessions as $s): ?>
                        <tr>
                            <td class="num"><?= (int) $s['session_id'] ?></td>
                            <td class="mono"><?= e($s['class_code']) ?></td>
                            <td><?= e(fmt_date($s['session_date'])) ?></td>
                            <td class="mono"><?= e(fmt_time($s['start_time'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><div><h2>Configuration in force</h2><div class="sub">Values loaded from config/config.php.</div></div></div>
    <div class="table-wrap">
        <table class="data">
            <tbody>
            <tr><th>Application</th><td><?= e(APP_NAME) ?></td></tr>
            <tr><th>Base URL</th><td class="mono"><?= e(base_url()) ?></td></tr>
            <tr><th>Database</th><td class="mono"><?= e(DB_USER . '@' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME) ?></td></tr>
            <tr><th>QR token lifetime</th><td><?= (int) QR_TOKEN_TTL ?> s + <?= (int) QR_GRACE_SECONDS ?> s grace = <?= (int) QR_TOKEN_TTL + (int) QR_GRACE_SECONDS ?> s maximum</td></tr>
            <tr><th>Default geofence</th><td><?= (int) DEFAULT_GEOFENCE_RADIUS ?> m</td></tr>
            <tr><th>Late threshold</th><td><?= (int) LATE_AFTER_MINUTES ?> minutes after the start time</td></tr>
            <tr><th>Time zone</th><td class="mono"><?= e(date_default_timezone_get()) ?></td></tr>
            <tr><th>Debug mode</th><td><?= DEBUG_MODE ? '<span class="badge badge-warn">ON — turn off before the defence</span>' : '<span class="badge badge-ok">Off</span>' ?></td></tr>
            <tr><th>Server time</th><td class="mono"><?= e(date('d M Y H:i:s')) ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
