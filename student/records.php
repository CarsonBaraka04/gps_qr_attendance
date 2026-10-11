<?php
/** Student: my attendance record for every session. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('student');

$classes = student_classes(auth_id());
$classId = query_int('class_id');
$records = student_records(auth_id(), $classId ?: null);

$attended = 0;
$held     = 0;

foreach ($classes as $c) {
    if (!$classId || (int) $c['class_id'] === $classId) {
        $held     += (int) $c['sessions_held'];
        $attended += (int) $c['attended'];
    }
}

$page_title    = 'My Records';
$page_subtitle = 'Every session your class has held, and whether you were marked present.';
require APP_ROOT . '/includes/header.php';
?>

<div class="stat-grid">
    <div class="stat"><div class="label">Sessions held</div><div class="value"><?= $held ?></div></div>
    <div class="stat ok"><div class="label">Attended</div><div class="value"><?= $attended ?></div></div>
    <div class="stat bad"><div class="label">Missed</div><div class="value"><?= max(0, $held - $attended) ?></div></div>
    <div class="stat <?= attendance_pct($attended, $held) >= 75 ? 'ok' : 'bad' ?>">
        <div class="label">Attendance</div>
        <div class="value"><?= number_format(attendance_pct($attended, $held), 1) ?>%</div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>Session history</h2><div class="sub"><?= count($records) ?> record(s)</div></div>
        <form method="get" action="<?= e(base_url('student/records.php')) ?>" style="margin:0">
            <select name="class_id" onchange="this.form.submit()">
                <option value="">All classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= (int) $c['class_id'] ?>" <?= $classId === (int) $c['class_id'] ? 'selected' : '' ?>>
                        <?= e($c['class_code'] . ' — ' . $c['class_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (!$records): ?>
        <div class="empty">No attendance has been recorded for you yet. Scan the QR code during a session.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>Class</th><th>Date</th><th>Time</th><th>Status</th><th>Recorded at</th><th class="num">Distance</th><th>Edited</th></tr>
                </thead>
                <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td>
                            <span class="mono"><strong><?= e($r['class_code']) ?></strong></span>
                            <div class="muted" style="font-size:.76rem"><?= e($r['class_name']) ?></div>
                        </td>
                        <td><?= e(fmt_date($r['session_date'])) ?></td>
                        <td class="mono"><?= e(fmt_time($r['start_time'])) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="mono"><?= e(fmt_dt($r['timestamp'])) ?></td>
                        <td class="num"><?= $r['distance_m'] !== null ? e(distance_text((float) $r['distance_m'])) : '—' ?></td>
                        <td>
                            <?= (int) $r['is_edited'] === 1
                                ? '<span class="badge badge-muted">edited</span>'
                                : '<span class="muted">—</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="muted" style="font-size:.78rem;margin-top:.8rem;margin-bottom:0">
            <strong>Present</strong> = scan within <?= (int) LATE_AFTER_MINUTES ?> minutes of the start time inside the geofence.
            <strong>Late</strong> = scan after that. <strong>Absent</strong> = no accepted scan, or the lecturer recorded an absence.
            Records marked <em>edited</em> were changed by the lecturer and the change is kept in the audit trail.
        </p>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
