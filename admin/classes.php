<?php
/** School Administrator: all classes in the school. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('school_admin');

$classes = all_classes();

$page_title    = 'Classes';
$page_subtitle = 'Create classes, assign lecturers and open the class records.';
require APP_ROOT . '/includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <div><h2>All classes</h2><div class="sub"><?= count($classes) ?> class(es) registered</div></div>
        <a class="btn btn-sm" href="<?= e(base_url('admin/class_new.php')) ?>">＋ Create class</a>
    </div>

    <?php if (!$classes): ?>
        <div class="empty">No classes yet. <a href="<?= e(base_url('admin/class_new.php')) ?>">Create the first one.</a></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>Code</th><th>Class name</th><th>Lecturer</th><th>Created by</th>
                    <th class="num">Students</th><th class="num">Sessions</th><th>Actions</th></tr>
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
                        <td><?= $c['lecturer_name'] ? e($c['lecturer_name']) : '<span class="badge badge-warn">Unassigned</span>' ?></td>
                        <td class="muted"><?= e($c['created_by_name'] ?? '—') ?></td>
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

<?php require APP_ROOT . '/includes/footer.php'; ?>
