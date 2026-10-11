<?php
/**
 * Role-aware dashboard. One file, four different landing pages.
 * Actions: Lecturer | Student | School Administrator | System Administrator
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$role = auth_role();

switch ($role) {

    /* ==================================================================
       LECTURER
       ================================================================== */
    case 'lecturer':
        $classes      = lecturer_classes(auth_id());
        $classCount   = count($classes);
        $studentCount = (int) db_value(
            'SELECT COUNT(DISTINCT e.student_id)
               FROM enrollments e
               JOIN classes c ON c.class_id = e.class_id
              WHERE c.lecturer_id = ?',
            [auth_id()]
        );
        $sessionCount = (int) db_value('SELECT COUNT(*) FROM sessions WHERE lecturer_id = ?', [auth_id()]);
        $openCount    = (int) db_value(
            'SELECT COUNT(*) FROM sessions WHERE lecturer_id = ? AND status = \'open\'',
            [auth_id()]
        );

        $page_title    = 'Lecturer Dashboard';
        $page_subtitle = 'Welcome, ' . auth_name() . '. Your teaching overview.';
        require APP_ROOT . '/includes/header.php';
        ?>

        <div class="stat-grid">
            <div class="stat"><div class="label">Classes assigned</div><div class="value"><?= $classCount ?></div><div class="hint">Allocated by the school administrator</div></div>
            <div class="stat"><div class="label">Students taught</div><div class="value"><?= $studentCount ?></div><div class="hint">Distinct students across your classes</div></div>
            <div class="stat ok"><div class="label">Sessions held</div><div class="value"><?= $sessionCount ?></div><div class="hint">Attendance sessions created</div></div>
            <div class="stat <?= $openCount > 0 ? 'warn' : '' ?>"><div class="label">Open sessions</div><div class="value"><?= $openCount ?></div><div class="hint"><?= $openCount > 0 ? 'Students can scan right now' : 'No session running' ?></div></div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>Classes assigned to you</h2>
                    <div class="sub">Initiate a QR code or review attendance for any class below.</div>
                </div>
                <a class="btn btn-sm" href="<?= e(base_url('lecturer/session_start.php')) ?>">▶ Start a session</a>
            </div>

            <?php if (!$classes): ?>
                <div class="empty">No classes have been assigned to you yet. Contact the school administrator.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Code</th><th>Class name</th><th class="num">Students</th>
                            <th class="num">Sessions</th><th>Status</th><th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td class="mono"><strong><?= e($c['class_code']) ?></strong></td>
                                <td><?= e($c['class_name']) ?></td>
                                <td class="num"><?= (int) $c['student_count'] ?></td>
                                <td class="num"><?= (int) $c['session_count'] ?></td>
                                <td>
                                    <?= (int) $c['open_sessions'] > 0
                                        ? '<span class="badge badge-ok">Open session</span>'
                                        : '<span class="badge badge-muted">Idle</span>' ?>
                                </td>
                                <td>
                                    <div class="btn-row">
                                        <a class="btn btn-sm" href="<?= e(base_url('lecturer/session_start.php?class_id=' . (int) $c['class_id'])) ?>">Initiate QR</a>
                                        <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/attendance.php?class_id=' . (int) $c['class_id'])) ?>">Attendance</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        require APP_ROOT . '/includes/footer.php';
        break;

    /* ==================================================================
       STUDENT
       ================================================================== */
    case 'student':
        $classes   = student_classes(auth_id());
        $held      = 0;
        $attended  = 0;

        foreach ($classes as $c) {
            $held     += (int) $c['sessions_held'];
            $attended += (int) $c['attended'];
        }

        $open     = open_session_for_student(auth_id());
        $overall  = attendance_pct($attended, $held);

        $page_title    = 'Student Dashboard';
        $page_subtitle = 'Welcome, ' . auth_name() . '. Your attendance at a glance.';
        require APP_ROOT . '/includes/header.php';
        ?>

        <div class="stat-grid">
            <div class="stat"><div class="label">Registered classes</div><div class="value"><?= count($classes) ?></div><div class="hint">Classes you are enrolled in</div></div>
            <div class="stat"><div class="label">Sessions held</div><div class="value"><?= $held ?></div><div class="hint">Total lecture sessions so far</div></div>
            <div class="stat ok"><div class="label">Sessions attended</div><div class="value"><?= $attended ?></div><div class="hint">Marked present or late</div></div>
            <div class="stat <?= $overall >= 75 ? 'ok' : 'bad' ?>"><div class="label">Overall attendance</div><div class="value"><?= number_format($overall, 1) ?>%</div><div class="hint">Minimum requirement is 75%</div></div>
        </div>

        <?php if ($open): ?>
            <div class="alert alert-success">
                <strong><span class="live-dot"></span>A session is open now.</strong>
                <?= e($open['class_code'] . ' — ' . $open['class_name']) ?>
                (<?= e(fmt_date($open['session_date']) . ' ' . fmt_time($open['start_time'])) ?>,
                <?= e($open['lecturer_name']) ?>).
                <a href="<?= e(base_url('student/scan.php')) ?>"><strong>Scan the QR code now &rarr;</strong></a>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>My classes</h2>
                    <div class="sub">Attendance is calculated from the sessions your class has held.</div>
                </div>
                <a class="btn btn-sm btn-accent" href="<?= e(base_url('student/scan.php')) ?>">▣ Scan QR code</a>
            </div>

            <?php if (!$classes): ?>
                <div class="empty">You are not registered for any class yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr><th>Code</th><th>Class name</th><th>Lecturer</th><th>My attendance</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td class="mono"><strong><?= e($c['class_code']) ?></strong></td>
                                <td><?= e($c['class_name']) ?></td>
                                <td><?= e($c['lecturer_name'] ?? 'Not assigned') ?></td>
                                <td><?= pct_meter((int) $c['attended'], (int) $c['sessions_held']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        require APP_ROOT . '/includes/footer.php';
        break;

    /* ==================================================================
       SCHOOL ADMINISTRATOR
       ================================================================== */
    case 'school_admin':
        $classes = all_classes();

        $page_title    = 'School Administrator Dashboard';
        $page_subtitle = 'Create classes, assign lecturers and access class records.';
        require APP_ROOT . '/includes/header.php';
        ?>

        <div class="stat-grid">
            <div class="stat"><div class="label">Classes</div><div class="value"><?= count($classes) ?></div><div class="hint">Registered in the school</div></div>
            <div class="stat"><div class="label">Lecturers</div><div class="value"><?= count(users_by_role('lecturer')) ?></div><div class="hint">Available for allocation</div></div>
            <div class="stat"><div class="label">Students</div><div class="value"><?= count(users_by_role('student')) ?></div><div class="hint">Enrolled in the school</div></div>
            <div class="stat ok"><div class="label">Sessions recorded</div><div class="value"><?= count_rows('sessions') ?></div><div class="hint">Across all classes</div></div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>All classes</h2>
                    <div class="sub">Assign a lecturer or review the records of any class.</div>
                </div>
                <a class="btn btn-sm" href="<?= e(base_url('admin/class_new.php')) ?>">＋ Create class</a>
            </div>

            <?php if (!$classes): ?>
                <div class="empty">No classes have been created yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr>
                            <th>Code</th><th>Class name</th><th>Lecturer</th>
                            <th class="num">Students</th><th class="num">Sessions</th><th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($classes as $c): ?>
                            <tr>
                                <td class="mono"><strong><?= e($c['class_code']) ?></strong></td>
                                <td><?= e($c['class_name']) ?></td>
                                <td>
                                    <?= $c['lecturer_name']
                                        ? e($c['lecturer_name'])
                                        : '<span class="badge badge-warn">Unassigned</span>' ?>
                                </td>
                                <td class="num"><?= (int) $c['student_count'] ?></td>
                                <td class="num"><?= (int) $c['session_count'] ?></td>
                                <td>
                                    <div class="btn-row">
                                        <a class="btn btn-sm btn-outline" href="<?= e(base_url('admin/class_edit.php?class_id=' . (int) $c['class_id'])) ?>">Manage</a>
                                        <a class="btn btn-sm btn-outline" href="<?= e(base_url('admin/records.php?class_id=' . (int) $c['class_id'])) ?>">Records</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        require APP_ROOT . '/includes/footer.php';
        break;

    /* ==================================================================
       SYSTEM ADMINISTRATOR
       ================================================================== */
    case 'system_admin':
        $logs = system_logs(8);

        $page_title    = 'System Administrator Dashboard';
        $page_subtitle = 'Monitor the system, review the audit trail and troubleshoot.';
        require APP_ROOT . '/includes/header.php';
        ?>

        <div class="stat-grid">
            <div class="stat"><div class="label">User accounts</div><div class="value"><?= count_rows('users') ?></div><div class="hint">All roles</div></div>
            <div class="stat"><div class="label">Classes</div><div class="value"><?= count_rows('classes') ?></div><div class="hint">Registered classes</div></div>
            <div class="stat"><div class="label">Attendance records</div><div class="value"><?= count_rows('attendance_records') ?></div><div class="hint">Scans and manual marks</div></div>
            <div class="stat ok"><div class="label">Log entries</div><div class="value"><?= count_rows('system_logs') ?></div><div class="hint">Audit trail</div></div>
        </div>

        <div class="card">
            <div class="card-head">
                <div>
                    <h2>System status</h2>
                    <div class="sub">Summary of the running environment.</div>
                </div>
                <a class="btn btn-sm btn-outline" href="<?= e(base_url('sysadmin/health.php')) ?>">Run full diagnostic</a>
            </div>
            <div class="table-wrap">
                <table class="data">
                    <tbody>
                    <tr><th>PHP version</th><td class="mono"><?= e(PHP_VERSION) ?></td></tr>
                    <tr><th>Database</th><td class="mono"><?= e(db()->query('SELECT VERSION()')->fetchColumn()) ?></td></tr>
                    <tr><th>Application</th><td class="mono"><?= e(base_url()) ?></td></tr>
                    <tr><th>QR token lifetime</th><td><?= (int) QR_TOKEN_TTL ?> seconds (rotation) + <?= (int) QR_GRACE_SECONDS ?>s grace</td></tr>
                    <tr><th>Default geofence</th><td><?= (int) DEFAULT_GEOFENCE_RADIUS ?> metres</td></tr>
                    <tr><th>Time zone</th><td class="mono"><?= e(date_default_timezone_get()) ?> &middot; now <?= e(date('d M Y H:i:s')) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <div><h2>Recent activity</h2><div class="sub">The most recent entries in the audit trail.</div></div>
                <a class="btn btn-sm btn-outline" href="<?= e(base_url('sysadmin/logs.php')) ?>">View all logs</a>
            </div>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="mono" style="white-space:nowrap"><?= e(fmt_dt($l['timestamp'])) ?></td>
                            <td><?= e($l['full_name'] ?? 'system') ?></td>
                            <td><span class="badge badge-navy"><?= e($l['action']) ?></span></td>
                            <td class="muted"><?= e($l['details'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        require APP_ROOT . '/includes/footer.php';
        break;

    /* ================================================================== */
    default:
        http_response_code(403);
        echo 'Unknown role. Contact the system administrator.';
}
