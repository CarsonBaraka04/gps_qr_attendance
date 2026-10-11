<?php
/** Student: the classes I am registered for and my attendance in each. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('student');

$classes = student_classes(auth_id());

$page_title    = 'My Classes';
$page_subtitle = 'The classes you are registered for.';
require APP_ROOT . '/includes/header.php';
?>

<?php if (!$classes): ?>
    <div class="card"><div class="empty">You are not registered for any class yet. Contact the school administrator.</div></div>
<?php else: ?>
    <div class="card">
        <div class="card-head">
            <div><h2>Registered classes</h2><div class="sub"><?= count($classes) ?> class(es)</div></div>
            <a class="btn btn-sm btn-accent" href="<?= e(base_url('student/scan.php')) ?>">▣ Scan QR code</a>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>Code</th><th>Class name</th><th>Lecturer</th><th class="num">Held</th><th class="num">Attended</th><th>Attendance</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($classes as $c): ?>
                    <tr>
                        <td class="mono"><strong><?= e($c['class_code']) ?></strong></td>
                        <td>
                            <?= e($c['class_name']) ?>
                            <?php if ($c['description']): ?>
                                <div class="muted" style="font-size:.78rem"><?= e($c['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($c['lecturer_name'] ?? 'Not assigned') ?></td>
                        <td class="num"><?= (int) $c['sessions_held'] ?></td>
                        <td class="num"><?= (int) $c['attended'] ?></td>
                        <td><?= pct_meter((int) $c['attended'], (int) $c['sessions_held'], false) ?></td>
                        <td>
                            <a class="btn btn-sm btn-outline"
                               href="<?= e(base_url('student/records.php?class_id=' . (int) $c['class_id'])) ?>">Records</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="alert alert-info" style="margin-top:1rem;margin-bottom:0;font-size:.82rem">
            Green means you are at or above the 75% attendance requirement. Amber is 50–74%; red is below 50%.
        </div>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
