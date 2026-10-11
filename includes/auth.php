<?php
/**
 * Authentication: login, logout, registration of session state.
 */

/**
 * @return array{ok: bool, message: string, user?: array}
 */
function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Enter both your e-mail address and password.'];
    }

    $stmt = db()->prepare(
        'SELECT user_id, full_name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['ok' => false, 'message' => 'Incorrect e-mail address or password.'];
    }

    if ((int) $user['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'This account has been deactivated. Contact the system administrator.'];
    }

    return ['ok' => true, 'message' => 'Welcome back, ' . $user['full_name'] . '.', 'user' => $user];
}

function login_user(array $user): void
{
    session_regenerate_id(true);           // prevents session fixation

    $_SESSION['user'] = [
        'user_id'   => (int) $user['user_id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role'],
    ];
}

function logout_user(): void
{
    if (is_logged_in()) {
        log_action('logout', auth_name() . ' logged out of the system.');
    }

    /* Clear every value, then move to a brand new session id so the old id
       cannot be replayed. The session is not destroyed outright because the
       login page still needs to read the confirmation message. */
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        session_regenerate_id(true);
    }

    $_SESSION['flash'][] = ['type' => 'info', 'message' => 'You have been logged out.'];
}
