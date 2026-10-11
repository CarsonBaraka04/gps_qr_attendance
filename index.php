<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}

$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email    = post('email');
    $password = (string) ($_POST['password'] ?? '');
    $result   = attempt_login($email, $password);

    if ($result['ok']) {
        login_user($result['user']);
        log_action('login_success', $result['user']['full_name'] . ' logged in as ' . role_label($result['user']['role']) . '.');
        flash('success', $result['message']);
        redirect(base_url('dashboard.php'));
    }

    log_action('login_failed', 'Failed login attempt for "' . $email . '".', null);
    flash('danger', $result['message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in &middot; <?= e(APP_SHORT) ?></title>
<link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
</head>
<body class="login-page">

<div class="login-card">
    <div class="login-head">
        <span class="brand-mark">QR</span>
        <h1>GPS-Enabled QR Code Attendance</h1>
        <p>Educational Institutions &middot; Strathmore University</p>
    </div>

    <?php foreach (take_flashes() as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>

    <form method="post" action="<?= e(base_url('index.php')) ?>" autocomplete="on">
        <?= csrf_field() ?>

        <div class="field">
            <label for="email">E-mail address</label>
            <input type="email" id="email" name="email" required autofocus
                   value="<?= e($email) ?>" placeholder="name@strathmore.edu">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required placeholder="••••••••">
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-block">Log in</button>
        </div>
    </form>

    <div class="login-foot">
        <details>
            <summary>Demonstration accounts</summary>
            <table>
                <tr><td><strong>System Admin</strong></td><td><code>sysadmin@strathmore.edu</code></td><td><code>Admin@123</code></td></tr>
                <tr><td><strong>School Admin</strong></td><td><code>registrar@strathmore.edu</code></td><td><code>Admin@123</code></td></tr>
                <tr><td><strong>Lecturer</strong></td><td><code>j.mwangi@strathmore.edu</code></td><td><code>Lecturer@123</code></td></tr>
                <tr><td><strong>Student</strong></td><td><code>carson.bwire@strathmore.edu</code></td><td><code>Student@123</code></td></tr>
            </table>
        </details>
    </div>
</div>

</body>
</html>
