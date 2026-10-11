<?php
/** System Administrator: create users, deactivate/reactivate accounts and reset passwords. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('system_admin');

const USER_ROLES = ['student', 'lecturer', 'school_admin', 'system_admin'];
const MIN_PASSWORD_LENGTH = 8;

$errors = [];
$old    = ['full_name' => '', 'email' => '', 'role' => 'student', 'reg_no' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post('action');

    if ($action === 'create') {
        $old = [
            'full_name' => post('full_name'),
            'email'     => strtolower(post('email')),
            'role'      => post('role'),
            'reg_no'    => post('reg_no'),
        ];
        $password = $_POST['password'] ?? '';
        $password = is_string($password) ? $password : '';

        if ($old['full_name'] === '' || text_len($old['full_name']) > 100) {
            $errors[] = 'Enter a full name of at most 100 characters.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || text_len($old['email']) > 120) {
            $errors[] = 'Enter a valid e-mail address.';
        } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$old['email']]) > 0) {
            $errors[] = 'The e-mail address ' . $old['email'] . ' is already registered.';
        }
        if (!in_array($old['role'], USER_ROLES, true)) {
            $errors[] = 'Select a valid role.';
        }
        if ($old['role'] === 'student') {
            if ($old['reg_no'] === '' || text_len($old['reg_no']) > 30) {
                $errors[] = 'Students need a registration number of at most 30 characters.';
            } elseif ((int) db_value('SELECT COUNT(*) FROM users WHERE reg_no = ?', [$old['reg_no']]) > 0) {
                $errors[] = 'The registration number ' . $old['reg_no'] . ' is already in use.';
            }
        }
        if (strlen($password) < MIN_PASSWORD_LENGTH) {
            $errors[] = 'The password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.';
        }

        if (!$errors) {
            db()->prepare(
                'INSERT INTO users (full_name, email, password_hash, role, reg_no) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $old['full_name'],
                $old['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $old['role'],
                $old['role'] === 'student' ? $old['reg_no'] : null,
            ]);

            log_action('user_created', 'Created ' . role_label($old['role']) . ' account ' . $old['email']
                . ' (#' . (int) db()->lastInsertId() . ').');
            flash('success', 'Account created for ' . $old['full_name'] . '.');
            redirect(base_url('sysadmin/users.php'));
        }

        foreach ($errors as $err) {
            flash('danger', $err);
        }
    } elseif ($action === 'toggle' || $action === 'reset_password') {
        $targetId = post_int('user_id');
        $stmt = db()->prepare('SELECT user_id, full_name, email, is_active FROM users WHERE user_id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target) {
            flash('danger', 'That user does not exist.');
        } elseif ($action === 'toggle') {
            if ($targetId === auth_id()) {
                flash('danger', 'You cannot deactivate your own account.');
            } else {
                $newState = (int) $target['is_active'] === 1 ? 0 : 1;
                db()->prepare('UPDATE users SET is_active = ? WHERE user_id = ?')->execute([$newState, $targetId]);
                log_action($newState === 1 ? 'user_activated' : 'user_deactivated',
                    ($newState === 1 ? 'Reactivated ' : 'Deactivated ') . $target['email'] . ' (#' . $targetId . ').');
                flash('success', $target['full_name'] . ($newState === 1 ? ' reactivated.' : ' deactivated.'));
            }
        } else {
            $password = $_POST['password'] ?? '';
            $password = is_string($password) ? $password : '';

            if (strlen($password) < MIN_PASSWORD_LENGTH) {
                flash('danger', 'The new password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.');
                redirect(base_url('sysadmin/users.php?reset=' . $targetId));
            }

            db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
            log_action('password_reset', 'Reset the password of ' . $target['email'] . ' (#' . $targetId . ').');
            flash('success', 'Password reset for ' . $target['full_name'] . '.');
        }

        redirect(base_url('sysadmin/users.php'));
    }
}

$search = query('search');
$role   = query('role');
$sql    = 'SELECT user_id, full_name, email, role, reg_no, is_active, created_at FROM users WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (full_name LIKE ? OR email LIKE ? OR reg_no LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($role, USER_ROLES, true)) {
    $sql .= ' AND role = ?';
    $params[] = $role;
}
$sql .= ' ORDER BY role, full_name';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$resetId   = query_int('reset');
$resetUser = null;
foreach ($users as $u) {
    if ((int) $u['user_id'] === $resetId) {
        $resetUser = $u;
    }
}

$page_title    = 'Manage Users';
$page_subtitle = 'Create accounts, deactivate users who should no longer have access and reset passwords.';
require APP_ROOT . '/includes/header.php';
?>

<?php if ($resetUser): ?>
<div class="card">
    <div class="card-head">
        <div><h2>Reset password</h2><div class="sub"><?= e($resetUser['full_name']) ?> &middot; <?= e($resetUser['email']) ?></div></div>
    </div>
    <form method="post" action="<?= e(base_url('sysadmin/users.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" value="<?= (int) $resetUser['user_id'] ?>">
        <div class="form-grid">
            <div class="field">
                <label for="reset_password">New password</label>
                <input type="password" name="password" id="reset_password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                <span class="help">At least <?= MIN_PASSWORD_LENGTH ?> characters. Give it to the user securely.</span>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Reset password</button>
            <a class="btn btn-outline" href="<?= e(base_url('sysadmin/users.php')) ?>">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div><h2>New user</h2><div class="sub">Only the System Administrator can create accounts.</div></div>
    </div>
    <form method="post" action="<?= e(base_url('sysadmin/users.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" name="full_name" id="full_name" required maxlength="100" value="<?= e($old['full_name']) ?>">
            </div>
            <div class="field">
                <label for="email">E-mail</label>
                <input type="email" name="email" id="email" required maxlength="120" value="<?= e($old['email']) ?>">
            </div>
            <div class="field">
                <label for="role">Role</label>
                <select name="role" id="role">
                    <?php foreach (USER_ROLES as $r): ?>
                        <option value="<?= e($r) ?>" <?= $old['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" id="reg_no_field">
                <label for="reg_no">Registration number</label>
                <input type="text" name="reg_no" id="reg_no" maxlength="30" value="<?= e($old['reg_no']) ?>">
                <span class="help">Students only.</span>
            </div>
            <div class="field">
                <label for="password">Initial password</label>
                <input type="password" name="password" id="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                <span class="help">At least <?= MIN_PASSWORD_LENGTH ?> characters.</span>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Create user</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <div><h2>All users</h2><div class="sub"><?= count($users) ?> account(s)</div></div>
        <form method="get" action="<?= e(base_url('sysadmin/users.php')) ?>" class="btn-row" style="margin:0">
            <input type="search" name="search" value="<?= e($search) ?>" placeholder="Name, e-mail or reg. no…" style="min-width:220px">
            <select name="role" style="width:auto">
                <option value="">All roles</option>
                <?php foreach (USER_ROLES as $r): ?>
                    <option value="<?= e($r) ?>" <?= $role === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Filter</button>
            <?php if ($search !== '' || $role !== ''): ?>
                <a class="btn btn-outline" href="<?= e(base_url('sysadmin/users.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$users): ?>
        <div class="empty">No users match your filter.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th>Name</th><th>E-mail</th><th>Role</th><th>Reg. no</th><th>Status</th><th>Created</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $active = (int) $u['is_active'] === 1; $isSelf = (int) $u['user_id'] === auth_id(); ?>
                    <tr>
                        <td><?= e($u['full_name']) ?><?= $isSelf ? ' <span class="muted">(you)</span>' : '' ?></td>
                        <td class="mono"><?= e($u['email']) ?></td>
                        <td><?= e(role_label($u['role'])) ?></td>
                        <td class="mono muted"><?= e($u['reg_no'] ?? '—') ?></td>
                        <td><?= $active ? '<span class="badge badge-ok">Active</span>' : '<span class="badge badge-bad">Deactivated</span>' ?></td>
                        <td class="muted" style="white-space:nowrap"><?= e(fmt_dt($u['created_at'])) ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn btn-sm btn-outline" href="<?= e(base_url('sysadmin/users.php?reset=' . (int) $u['user_id'])) ?>">Reset password</a>
                            <?php if (!$isSelf): ?>
                                <form method="post" action="<?= e(base_url('sysadmin/users.php')) ?>" style="display:inline"
                                      onsubmit="return confirm('<?= $active ? 'Deactivate' : 'Reactivate' ?> this account?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline"><?= $active ? 'Deactivate' : 'Reactivate' ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var role = document.getElementById('role');
    var field = document.getElementById('reg_no_field');
    function sync() { field.style.display = role.value === 'student' ? '' : 'none'; }
    role.addEventListener('change', sync);
    sync();
})();
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
