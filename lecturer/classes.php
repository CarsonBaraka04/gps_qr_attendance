<?php
/** Lecturer: the classes assigned to me. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('lecturer');

$classes = lecturer_classes(auth_id());

$page_title    = 'My Classes';
$page_subtitle = 'Classes allocated to you by the school administrator.';
require APP_ROOT . '/includes/header.php';
?>

<?php if (!$classes): ?>
    <div class="card"><div class="empty">You have no classes assigned yet. Ask the school administrator to allocate one.</div></div>
<?php else: ?>
    <div class="card">
        <div class="card-head">
            <div><h2>Classes assigned to you</h2><div class="sub"><?= count($classes) ?> class(es)</div></div>
        </div>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>Code</th><th>Class name</th><th class="num">Students</th><th class="num">Sessions</th><th>Actions</th></tr>
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
                        <td class="num"><?= (int) $c['student_count'] ?></td>
                        <td class="num"><?= (int) $c['session_count'] ?></td>
                        <td>
                            <div class="btn-row">
                                <a class="btn btn-sm" href="<?= e(base_url('lecturer/session_start.php?class_id=' . (int) $c['class_id'])) ?>">Initiate QR code</a>
                                <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/attendance.php?class_id=' . (int) $c['class_id'])) ?>">View attendance</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
