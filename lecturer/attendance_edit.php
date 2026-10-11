<?php
/**
 * Lecturer: edit the attendance register of one session.
 * Every manual change is stamped with is_edited, edited_by and edited_at so the
 * system keeps an audit trail (Hevner et al., 2004 - design must be verifiable).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('lecturer');

$sessionId = query_int('session_id');
$session   = session_detail($sessionId);

if (!$session || (int) $session['lecturer_id'] !== auth_id()) {
    flash('danger', 'That session does not exist or does not belong to you.');
    redirect(base_url('lecturer/attendance.php'));
}

$allowed = ['present', 'late', 'absent'];

/* ----------------------------- Save changes ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $action  = post('action');
    $changes = 0;

    if ($action === 'save') {
        $posted = $_POST['status'] ?? [];

        if (is_array($posted)) {
            foreach ($posted as $studentId => $newStatus) {
                $studentId = (int) $studentId;

                if ($studentId <= 0 || !in_array($newStatus, $allowed, true)) {
                    continue;
                }

                /* the student must belong to this class */
                $isEnrolled = (int) db_value(
                    'SELECT COUNT(*) FROM enrollments WHERE class_id = ? AND student_id = ?',
                    [(int) $session['class_id'], $studentId]
                );

                if ($isEnrolled === 0) {
                    continue;
                }

                $existing = db()->prepare(
                    'SELECT attendance_id, status FROM attendance_records WHERE session_id = ? AND student_id = ? LIMIT 1'
                );
                $existing->execute([$sessionId, $studentId]);
                $record = $existing->fetch();

                if (!$record) {
                    db()->prepare(
                        'INSERT INTO attendance_records
                             (session_id, student_id, status, is_edited, edited_by, edited_at)
                         VALUES (?, ?, ?, 1, ?, NOW())'
                    )->execute([$sessionId, $studentId, $newStatus, auth_id()]);
                    $changes++;
                } elseif ($record['status'] !== $newStatus) {
                    db()->prepare(
                        'UPDATE attendance_records
                            SET status = ?, is_edited = 1, edited_by = ?, edited_at = NOW()
                          WHERE attendance_id = ?'
                    )->execute([$newStatus, auth_id(), (int) $record['attendance_id']]);
                    $changes++;
                }
            }
        }

        if ($changes > 0) {
            log_action('attendance_edited', 'Lecturer updated ' . $changes . ' record(s) in session ' . $sessionId
                . ' (' . $session['class_code'] . ').');
            flash('success', $changes . ' record(s) updated and stamped in the audit trail.');
        } else {
            flash('info', 'No changes were made.');
        }
    }

    if ($action === 'mark_remaining_absent') {
        $inserted = db()->prepare(
            'INSERT INTO attendance_records (session_id, student_id, status, is_edited, edited_by, edited_at)
             SELECT s.session_id, e.student_id, \'absent\', 1, ?, NOW()
               FROM sessions s
               JOIN enrollments e ON e.class_id = s.class_id
              WHERE s.session_id = ?
                AND NOT EXISTS (
                        SELECT 1 FROM attendance_records ar
                         WHERE ar.session_id = s.session_id AND ar.student_id = e.student_id
                    )'
        );
        $inserted->execute([auth_id(), $sessionId]);
        $changes = $inserted->rowCount();

        log_action('attendance_edited', 'Marked ' . $changes . ' remaining student(s) absent in session ' . $sessionId
            . ' (' . $session['class_code'] . ').');

        flash($changes > 0 ? 'success' : 'info', $changes > 0
            ? $changes . ' student(s) without a scan were marked absent.'
            : 'Every enrolled student already has a record.');
    }

    redirect(base_url('lecturer/attendance_edit.php?session_id=' . $sessionId));
}

$register = session_register($sessionId);
$present  = 0;
$late     = 0;
$absent   = 0;
$unmarked = 0;

foreach ($register as $r) {
    if ($r['attendance_id'] === null) {
        $unmarked++;
    } elseif ($r['status'] === 'present') {
        $present++;
    } elseif ($r['status'] === 'late') {
        $late++;
    } else {
        $absent++;
    }
}

$page_title    = 'Attendance Register';
$page_subtitle = $session['class_code'] . ' — ' . $session['class_name'] . ' · '
    . fmt_date($session['session_date']) . ' at ' . fmt_time($session['start_time']);
require APP_ROOT . '/includes/header.php';
?>

<div class="stat-grid">
    <div class="stat ok"><div class="label">Present</div><div class="value"><?= $present ?></div></div>
    <div class="stat warn"><div class="label">Late</div><div class="value"><?= $late ?></div></div>
    <div class="stat bad"><div class="label">Absent</div><div class="value"><?= $absent ?></div></div>
    <div class="stat"><div class="label">Not marked</div><div class="value"><?= $unmarked ?></div><div class="hint">No scan was received</div></div>
</div>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Register &mdash; <?= e($session['class_code']) ?></h2>
            <div class="sub">
                <?= e(fmt_date($session['session_date'])) ?> at <?= e(fmt_time($session['start_time'])) ?>
                &middot; <?= status_badge($session['status']) ?>
            </div>
        </div>
        <div class="btn-row">
            <?php if ($session['status'] === 'open'): ?>
                <a class="btn btn-sm btn-accent" href="<?= e(base_url('lecturer/session_live.php?session_id=' . $sessionId)) ?>">QR screen</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=session&session_id=' . $sessionId)) ?>">⬇ Export (CSV)</a>
        </div>
    </div>

    <?php if (!$register): ?>
        <div class="empty">No students are enrolled in this class.</div>
    <?php else: ?>

        <div class="alert alert-info" style="font-size:.82rem">
            Set the correct status for each student and click <strong>Save changes</strong>.
            Any record you change is flagged <em>edited</em> and stamped with your name and the time,
            so the original scan data is never silently overwritten.
        </div>

        <form method="post" action="<?= e(base_url('lecturer/attendance_edit.php?session_id=' . $sessionId)) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr>
                        <th>Reg. no</th><th>Student</th><th>Scan time</th><th class="num">Distance</th>
                        <th>Recorded</th><th style="min-width:150px">Mark as</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($register as $r): ?>
                        <?php $current = $r['status'] ?? ''; ?>
                        <tr>
                            <td class="mono"><?= e($r['reg_no'] ?? '—') ?></td>
                            <td>
                                <?= e($r['full_name']) ?>
                                <?php if ((int) $r['is_edited'] === 1): ?>
                                    <div class="muted" style="font-size:.72rem">
                                        edited by <?= e($r['edited_by_name'] ?? 'staff') ?> on <?= e(fmt_dt($r['edited_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?= $r['timestamp'] ? e(date('H:i:s', strtotime($r['timestamp']))) : '—' ?></td>
                            <td class="num"><?= $r['distance_m'] !== null ? e(distance_text((float) $r['distance_m'])) : '—' ?></td>
                            <td>
                                <?php if ($r['attendance_id'] === null): ?>
                                    <span class="badge badge-muted">No scan</span>
                                <?php else: ?>
                                    <?= status_badge($r['status']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="status[<?= (int) $r['user_id'] ?>]">
                                    <option value="" <?= $current === '' ? 'selected' : '' ?>>— leave unchanged —</option>
                                    <option value="present" <?= $current === 'present' ? 'selected' : '' ?>>Present</option>
                                    <option value="late"    <?= $current === 'late'    ? 'selected' : '' ?>>Late</option>
                                    <option value="absent"  <?= $current === 'absent'  ? 'selected' : '' ?>>Absent</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">Save changes</button>
                <a class="btn btn-outline" href="<?= e(base_url('lecturer/attendance.php?class_id=' . (int) $session['class_id'])) ?>">Back to class</a>
            </div>
        </form>

        <?php if ($unmarked > 0): ?>
            <form method="post" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--line)"
                  action="<?= e(base_url('lecturer/attendance_edit.php?session_id=' . $sessionId)) ?>"
                  onsubmit="return confirm('Mark all <?= $unmarked ?> student(s) without a scan as absent?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_remaining_absent">
                <button type="submit" class="btn btn-sm btn-outline">
                    Mark the <?= $unmarked ?> student(s) without a scan as absent
                </button>
            </form>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
