<?php
/** School Administrator: read-only access to class attendance records. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('school_admin');

$classId = query_int('class_id');
$class   = null;

if ($classId) {
    $stmt = db()->prepare('SELECT class_id, class_code, class_name FROM classes WHERE class_id = ? LIMIT 1');
    $stmt->execute([$classId]);
    $class = $stmt->fetch();

    if (!$class) {
        flash('danger', 'That class does not exist.');
        redirect(base_url('admin/records.php'));
    }
}

$classes = all_classes();

$page_title    = 'Class Records';
$page_subtitle = $class
    ? $class['class_code'] . ' — ' . $class['class_name']
    : 'Attendance records of every class.';
require APP_ROOT . '/includes/header.php';
?>

<?php if (!$class): ?>

    <div class="card">
        <div class="card-head"><div><h2>Choose a class</h2><div class="sub">Open the records of any class in the school.</div></div></div>
        <?php if (!$classes): ?>
            <div class="empty">No classes exist yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Code</th><th>Class name</th><th>Lecturer</th><th class="num">Students</th><th class="num">Sessions</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($classes as $c): ?>
                        <tr>
                            <td class="mono"><strong><?= e($c['class_code']) ?></strong></td>
                            <td><?= e($c['class_name']) ?></td>
                            <td><?= e($c['lecturer_name'] ?? 'Unassigned') ?></td>
                            <td class="num"><?= (int) $c['student_count'] ?></td>
                            <td class="num"><?= (int) $c['session_count'] ?></td>
                            <td><a class="btn btn-sm" href="<?= e(base_url('admin/records.php?class_id=' . (int) $c['class_id'])) ?>">Open records</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <?php
    $summary  = class_attendance_summary($classId);
    $sessions = class_sessions($classId);
    ?>

    <div class="btn-row" style="margin-bottom:1rem">
        <a class="btn btn-sm btn-outline" href="<?= e(base_url('admin/records.php')) ?>">&larr; All classes</a>
        <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=summary&class_id=' . $classId)) ?>">⬇ Export summary (CSV)</a>
        <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=matrix&class_id=' . $classId)) ?>">⬇ Export matrix (CSV)</a>
    </div>

    <div class="card">
        <div class="card-head"><div><h2>Attendance summary</h2><div class="sub"><?= count($summary) ?> student(s)</div></div></div>

        <?php if (!$summary): ?>
            <div class="empty">No students are enrolled in this class.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Reg. no</th><th>Student</th><th class="num">Late</th><th class="num">Absent</th><th>Attendance</th></tr></thead>
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
        <div class="card-head"><div><h2>Sessions</h2><div class="sub">Read-only view. Editing is the lecturer's responsibility.</div></div></div>

        <?php if (!$sessions): ?>
            <div class="empty">No sessions have been held for this class yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Date</th><th>Time</th><th>Status</th><th>Present</th><th class="num">Geofence</th></tr></thead>
                    <tbody>
                    <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td><?= e(fmt_date($s['session_date'])) ?></td>
                            <td class="mono"><?= e(fmt_time($s['start_time'])) ?></td>
                            <td><?= status_badge($s['status']) ?></td>
                            <td><strong><?= (int) $s['present_count'] ?></strong> <span class="muted">/ <?= (int) $s['enrolled_count'] ?></span></td>
                            <td class="num"><?= (int) $s['geofence_radius'] ?> m</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
