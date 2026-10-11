<?php
/**
 * School Administrator: manage one class - assign its lecturer and control the
 * enrolment list. Un-enrolling a student who already has attendance records is
 * blocked so that class records stay consistent.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('school_admin');

$classId = query_int('class_id');

$stmt = db()->prepare(
    'SELECT c.*, lec.full_name AS lecturer_name
       FROM classes c
       LEFT JOIN users lec ON lec.user_id = c.lecturer_id
      WHERE c.class_id = ? LIMIT 1'
);
$stmt->execute([$classId]);
$class = $stmt->fetch();

if (!$class) {
    flash('danger', 'That class does not exist.');
    redirect(base_url('admin/classes.php'));
}

$lecturers = users_by_role('lecturer');
$students  = users_by_role('student');

/* ----------------------------- Actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action = post('action');
    $errors = [];

    /* ---- save the class details and the lecturer assignment ---- */
    if ($action === 'save') {
        $code        = post('class_code');
        $name        = post('class_name');
        $description = post('description');
        $lecturerId  = post_int('lecturer_id');

        if ($code === '' || $name === '') {
            $errors[] = 'The class code and class name are required.';
        }
        if ((int) db_value('SELECT COUNT(*) FROM classes WHERE class_code = ? AND class_id <> ?', [$code, $classId]) > 0) {
            $errors[] = 'Another class already uses the code "' . $code . '".';
        }
        if ($lecturerId > 0 && (int) db_value('SELECT COUNT(*) FROM users WHERE user_id = ? AND role = \'lecturer\'', [$lecturerId]) === 0) {
            $errors[] = 'The selected lecturer does not exist.';
        }

        if ($errors) {
            foreach ($errors as $err) {
                flash('danger', $err);
            }
        } else {
            db()->prepare(
                'UPDATE classes
                    SET class_code = ?, class_name = ?, description = ?, lecturer_id = ?
                  WHERE class_id = ?'
            )->execute([
                $code,
                $name,
                $description !== '' ? $description : null,
                $lecturerId > 0 ? $lecturerId : null,
                $classId,
            ]);

            $lecturerName = 'nobody';

            foreach ($lecturers as $l) {
                if ((int) $l['user_id'] === $lecturerId) {
                    $lecturerName = $l['full_name'];
                }
            }

            log_action('class_updated', 'Updated class ' . $code . ' and assigned it to ' . $lecturerName . '.');
            flash('success', 'Class saved. Lecturer assigned: ' . $lecturerName . '.');
        }
    }

    /* ---- enrol one student ---- */
    if ($action === 'enroll') {
        $studentId = post_int('student_id');

        if ((int) db_value('SELECT COUNT(*) FROM users WHERE user_id = ? AND role = \'student\'', [$studentId]) === 0) {
            flash('danger', 'Select a valid student.');
        } else {
            db()->prepare('INSERT IGNORE INTO enrollments (class_id, student_id) VALUES (?, ?)')
                ->execute([$classId, $studentId]);

            log_action('student_enrolled', 'Enrolled student #' . $studentId . ' in class ' . $class['class_code'] . '.');
            flash('success', 'Student enrolled in ' . $class['class_code'] . '.');
        }
    }

    /* ---- remove one student from the class ---- */
    if ($action === 'unenroll') {
        $studentId = post_int('student_id');

        $hasRecords = (int) db_value(
            'SELECT COUNT(*)
               FROM attendance_records ar
               JOIN sessions s ON s.session_id = ar.session_id
              WHERE s.class_id = ? AND ar.student_id = ?',
            [$classId, $studentId]
        );

        if ($hasRecords > 0) {
            flash('danger', 'That student already has ' . $hasRecords . ' attendance record(s) in this class, '
                . 'so the enrolment cannot be removed. The records must stay auditable.');
        } else {
            db()->prepare('DELETE FROM enrollments WHERE class_id = ? AND student_id = ?')
                ->execute([$classId, $studentId]);

            log_action('student_unenrolled', 'Removed student #' . $studentId . ' from class ' . $class['class_code'] . '.');
            flash('success', 'Student removed from the class.');
        }
    }

    redirect(base_url('admin/class_edit.php?class_id=' . $classId));
}

$enrolled   = class_enrolled_students($classId);
$enrolledIds = array_map(static fn (array $s): int => (int) $s['user_id'], $enrolled);
$available  = array_values(array_filter(
    $students,
    static fn (array $s): bool => !in_array((int) $s['user_id'], $enrolledIds, true)
));
$sessions = class_sessions($classId);

$page_title    = 'Manage ' . $class['class_code'];
$page_subtitle = $class['class_name'];
require APP_ROOT . '/includes/header.php';
?>

<div class="btn-row" style="margin-bottom:1rem">
    <a class="btn btn-sm btn-outline" href="<?= e(base_url('admin/classes.php')) ?>">&larr; All classes</a>
    <a class="btn btn-sm btn-outline" href="<?= e(base_url('admin/records.php?class_id=' . $classId)) ?>">Class records</a>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Class details and lecturer</h2><div class="sub">Assigning a lecturer gives them access to this class only.</div></div>
    </div>

    <form method="post" action="<?= e(base_url('admin/class_edit.php?class_id=' . $classId)) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">

        <div class="form-grid">
            <div class="field">
                <label for="class_code">Class code</label>
                <input type="text" name="class_code" id="class_code" value="<?= e($class['class_code']) ?>" required maxlength="20">
            </div>
            <div class="field">
                <label for="class_name">Class name</label>
                <input type="text" name="class_name" id="class_name" value="<?= e($class['class_name']) ?>" required maxlength="120">
            </div>
            <div class="field">
                <label for="lecturer_id">Assigned lecturer</label>
                <select name="lecturer_id" id="lecturer_id">
                    <option value="">— not assigned —</option>
                    <?php foreach ($lecturers as $l): ?>
                        <option value="<?= (int) $l['user_id'] ?>" <?= (int) $class['lecturer_id'] === (int) $l['user_id'] ? 'selected' : '' ?>>
                            <?= e($l['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="description">Description</label>
                <input type="text" name="description" id="description" value="<?= e($class['description'] ?? '') ?>">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Save changes</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Enrolled students</h2><div class="sub"><?= count($enrolled) ?> student(s) in this class</div></div>
    </div>

    <?php if (!$enrolled): ?>
        <div class="empty">No students are enrolled yet. Add one below.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Reg. no</th><th>Student</th><th>E-mail</th><th>Enrolled on</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($enrolled as $s): ?>
                    <tr>
                        <td class="mono"><?= e($s['reg_no'] ?? '—') ?></td>
                        <td><?= e($s['full_name']) ?></td>
                        <td class="muted"><?= e($s['email']) ?></td>
                        <td class="muted"><?= e(fmt_dt($s['enrolled_at'])) ?></td>
                        <td>
                            <form method="post" style="margin:0"
                                  action="<?= e(base_url('admin/class_edit.php?class_id=' . $classId)) ?>"
                                  onsubmit="return confirm('Remove this student from the class?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unenroll">
                                <input type="hidden" name="student_id" value="<?= (int) $s['user_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($available): ?>
        <form method="post" style="margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--line)"
              action="<?= e(base_url('admin/class_edit.php?class_id=' . $classId)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="enroll">
            <div class="field">
                <label for="student_id">Add a student</label>
                <div class="btn-row">
                    <select name="student_id" id="student_id" style="max-width:420px" required>
                        <option value="">— select a student —</option>
                        <?php foreach ($available as $s): ?>
                            <option value="<?= (int) $s['user_id'] ?>">
                                <?= e($s['full_name'] . ($s['reg_no'] ? ' (' . $s['reg_no'] . ')' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn">Enrol student</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Sessions held</h2><div class="sub"><?= count($sessions) ?> session(s) recorded for this class</div></div>
        <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=matrix&class_id=' . $classId)) ?>">⬇ Export matrix (CSV)</a>
    </div>

    <?php if (!$sessions): ?>
        <div class="empty">No sessions have been held for this class yet.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Date</th><th>Time</th><th>Status</th><th>Present</th><th class="num">Radius</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($sessions as $s): ?>
                    <tr>
                        <td><?= e(fmt_date($s['session_date'])) ?></td>
                        <td class="mono"><?= e(fmt_time($s['start_time'])) ?></td>
                        <td><?= status_badge($s['status']) ?></td>
                        <td><strong><?= (int) $s['present_count'] ?></strong> <span class="muted">/ <?= (int) $s['enrolled_count'] ?></span></td>
                        <td class="num"><?= (int) $s['geofence_radius'] ?> m</td>
                        <td>
                            <a class="btn btn-sm btn-outline"
                               href="<?= e(base_url('lecturer/export.php?type=session&session_id=' . (int) $s['session_id'])) ?>">CSV</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
