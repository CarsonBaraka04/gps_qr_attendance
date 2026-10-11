<?php
/**
 * Lecturer: view attendance for a class, or pick a class first.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('lecturer');

$classes  = lecturer_classes(auth_id());
$classId  = query_int('class_id');
$class    = null;
$sessions = [];
$summary  = [];

if ($classId) {
    foreach ($classes as $c) {
        if ((int) $c['class_id'] === $classId) {
            $class = $c;
        }
    }

    if (!$class) {
        flash('danger', 'That class is not assigned to you.');
        redirect(base_url('lecturer/attendance.php'));
    }

    $sessions = class_sessions($classId, auth_id());
    $summary  = class_attendance_summary($classId);
}

$page_title    = 'Attendance';
$page_subtitle = $class
    ? $class['class_code'] . ' — ' . $class['class_name']
    : 'Choose a class to view its attendance.';
require APP_ROOT . '/includes/header.php';
?>

<?php if (!$class): ?>

    <div class="card">
        <div class="card-head"><div><h2>Select a class</h2><div class="sub">You have <?= count($classes) ?> class(es).</div></div></div>

        <?php if (!$classes): ?>
            <div class="empty">No classes have been assigned to you yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Code</th><th>Class name</th><th class="num">Students</th><th class="num">Sessions</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($classes as $c): ?>
                        <tr>
                            <td class="mono"><strong><?= e($c['class_code']) ?></strong></td>
                            <td><?= e($c['class_name']) ?></td>
                            <td class="num"><?= (int) $c['student_count'] ?></td>
                            <td class="num"><?= (int) $c['session_count'] ?></td>
                            <td><a class="btn btn-sm" href="<?= e(base_url('lecturer/attendance.php?class_id=' . (int) $c['class_id'])) ?>">View attendance</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="btn-row" style="margin-bottom:1rem">
        <a class="btn btn-sm" href="<?= e(base_url('lecturer/session_start.php?class_id=' . $classId)) ?>">▶ Initiate QR code</a>
        <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=summary&class_id=' . $classId)) ?>">⬇ Export summary (CSV)</a>
        <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=matrix&class_id=' . $classId)) ?>">⬇ Export full matrix (CSV)</a>
    </div>

    <div class="card">
        <div class="card-head">
            <div>
                <h2>Student attendance summary</h2>
                <div class="sub"><?= count($summary) ?> students &middot; <?= count($sessions) ?> session(s) recorded for this class.</div>
            </div>
        </div>

        <?php if (!$summary): ?>
            <div class="empty">No students are enrolled in this class yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Reg. no</th><th>Student</th><th class="num">Late</th><th class="num">Absent</th><th>Attendance</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($summary as $s): ?>
                        <tr>
                            <td class="mono"><?= e($s['reg_no'] ?? '—') ?></td>
                            <td><?= e($s['full_name']) ?></td>
                            <td class="num"><?= (int) $s['late_count'] ?></td>
                            <td class="num"><?= (int) $s['absent_count'] ?></td>
                            <td><?= pct_meter((int) $s['attended'], (int) $s['sessions_held']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <div><h2>Sessions</h2><div class="sub">Open a register to view, edit or export the attendance of that session.</div></div>
        </div>

        <?php if (!$sessions): ?>
            <div class="empty">No sessions have been held for this class yet.
                <a href="<?= e(base_url('lecturer/session_start.php?class_id=' . $classId)) ?>">Start the first one.</a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Date</th><th>Time</th><th>Status</th><th>Present</th><th class="num">Radius</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                        <?php
                        $pct = (int) $s['enrolled_count'] > 0
                            ? round((int) $s['present_count'] / (int) $s['enrolled_count'] * 100)
                            : 0;
                        ?>
                        <tr>
                            <td><?= e(fmt_date($s['session_date'])) ?></td>
                            <td class="mono"><?= e(fmt_time($s['start_time'])) ?></td>
                            <td><?= status_badge($s['status']) ?></td>
                            <td>
                                <strong><?= (int) $s['present_count'] ?></strong>
                                <span class="muted">/ <?= (int) $s['enrolled_count'] ?> (<?= $pct ?>%)</span>
                            </td>
                            <td class="num"><?= (int) $s['geofence_radius'] ?> m</td>
                            <td>
                                <div class="btn-row">
                                    <?php if ($s['status'] === 'open'): ?>
                                        <a class="btn btn-sm btn-accent" href="<?= e(base_url('lecturer/session_live.php?session_id=' . (int) $s['session_id'])) ?>">QR screen</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/attendance_edit.php?session_id=' . (int) $s['session_id'])) ?>">Register</a>
                                    <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=session&session_id=' . (int) $s['session_id'])) ?>">CSV</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <p class="muted" style="font-size:.8rem">
        <a href="<?= e(base_url('lecturer/attendance.php')) ?>">&larr; Back to the class list</a>
    </p>

<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
