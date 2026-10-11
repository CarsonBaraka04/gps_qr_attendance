<?php
/** School Administrator: create a class, assign a lecturer and enrol students. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('school_admin');

$lecturers = users_by_role('lecturer');
$students  = users_by_role('student');
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $code        = post('class_code');
    $name        = post('class_name');
    $description = post('description');
    $lecturerId  = post_int('lecturer_id');
    $selected    = $_POST['students'] ?? [];
    $selected    = is_array($selected) ? array_map('intval', $selected) : [];

    if ($code === '' || text_len($code) > 20) {
        $errors[] = 'Enter a class code of at most 20 characters (for example BIT 4205).';
    }
    if ($name === '' || text_len($name) > 120) {
        $errors[] = 'Enter a class name of at most 120 characters.';
    }
    if ((int) db_value('SELECT COUNT(*) FROM classes WHERE class_code = ?', [$code]) > 0) {
        $errors[] = 'The class code "' . $code . '" is already in use.';
    }
    if ($lecturerId > 0 && (int) db_value('SELECT COUNT(*) FROM users WHERE user_id = ? AND role = \'lecturer\'', [$lecturerId]) === 0) {
        $errors[] = 'The selected lecturer does not exist.';
    }

    if (!$errors) {
        $insert = db()->prepare(
            'INSERT INTO classes (class_code, class_name, description, created_by, lecturer_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $code,
            $name,
            $description !== '' ? $description : null,
            auth_id(),
            $lecturerId > 0 ? $lecturerId : null,
        ]);

        $newId = (int) db()->lastInsertId();
        $count = 0;

        if ($selected) {
            $enrol = db()->prepare('INSERT IGNORE INTO enrollments (class_id, student_id) VALUES (?, ?)');

            foreach ($selected as $studentId) {
                if ((int) db_value('SELECT COUNT(*) FROM users WHERE user_id = ? AND role = \'student\'', [$studentId]) > 0) {
                    $enrol->execute([$newId, $studentId]);
                    $count++;
                }
            }
        }

        log_action('class_created', 'Created class ' . $code . ' — ' . $name
            . ($lecturerId > 0 ? ' assigned to lecturer #' . $lecturerId : ' (lecturer not yet assigned)')
            . ', ' . $count . ' student(s) enrolled.');

        flash('success', 'Class ' . $code . ' created' . ($count > 0 ? ' with ' . $count . ' student(s) enrolled' : '') . '.');
        redirect(base_url('admin/classes.php'));
    }

    foreach ($errors as $err) {
        flash('danger', $err);
    }
}

$page_title    = 'Create Class';
$page_subtitle = 'Register a new class, assign its lecturer and enrol the students.';
require APP_ROOT . '/includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <div><h2>New class</h2><div class="sub">The class code must be unique.</div></div>
    </div>

    <form method="post" action="<?= e(base_url('admin/class_new.php')) ?>">
        <?= csrf_field() ?>

        <div class="form-grid">
            <div class="field">
                <label for="class_code">Class code</label>
                <input type="text" name="class_code" id="class_code" required maxlength="20" placeholder="BIT 4205">
            </div>

            <div class="field">
                <label for="class_name">Class name</label>
                <input type="text" name="class_name" id="class_name" required maxlength="120" placeholder="Information Systems Project">
            </div>

            <div class="field">
                <label for="lecturer_id">Assign to lecturer</label>
                <select name="lecturer_id" id="lecturer_id">
                    <option value="">— assign later —</option>
                    <?php foreach ($lecturers as $l): ?>
                        <option value="<?= (int) $l['user_id'] ?>"><?= e($l['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="help">The lecturer can only manage classes assigned to them.</span>
            </div>

            <div class="field">
                <label for="description">Description</label>
                <input type="text" name="description" id="description" placeholder="Optional short description">
            </div>
        </div>

        <div class="field" style="margin-top:1rem">
            <label for="students">Enrol students</label>
            <span class="help">Hold Ctrl (Windows) or Cmd (Mac) to select more than one student.</span>
            <select name="students[]" id="students" multiple size="<?= max(4, min(10, count($students))) ?>" style="max-width:520px">
                <?php foreach ($students as $s): ?>
                    <option value="<?= (int) $s['user_id'] ?>">
                        <?= e($s['full_name'] . ($s['reg_no'] ? ' (' . $s['reg_no'] . ')' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn">Create class</button>
            <a class="btn btn-outline" href="<?= e(base_url('admin/classes.php')) ?>">Cancel</a>
        </div>
    </form>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
