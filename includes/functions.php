<?php
/**
 * Shared helper functions.
 */

/* ----------------------------- Output ----------------------------- */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_out(array $payload, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ----------------------------- URLs ----------------------------- */

/**
 * Build the application base URL.
 * Detects the host automatically so that QR codes generated on a laptop
 * contain the laptop's LAN address and phones on the same Wi-Fi can open them.
 */
function base_url(string $path = ''): string
{
    static $base = null;

    if ($base === null) {
        if (BASE_URL_OVERRIDE !== '') {
            $base = rtrim(BASE_URL_OVERRIDE, '/');
        } else {
            $https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $scheme  = $https ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $docRoot = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
            $appRoot = str_replace('\\', '/', (string) realpath(APP_ROOT));
            $webPath = '';

            if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
                $webPath = substr($appRoot, strlen($docRoot));
            }

            $base = $scheme . '://' . $host . rtrim($webPath, '/');
        }
    }

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function redirect(string $url)
{
    header('Location: ' . $url);
    exit;
}

/* ----------------------------- Input ----------------------------- */

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function post_int(string $key, int $default = 0): int
{
    return (int) ($_POST[$key] ?? $default);
}

function query_int(string $key, int $default = 0): int
{
    return (int) ($_GET[$key] ?? $default);
}

function post_float(string $key, float $default = 0.0): float
{
    return (float) ($_POST[$key] ?? $default);
}

function query_float(string $key, float $default = 0.0): float
{
    return (float) ($_GET[$key] ?? $default);
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    return substr((string) $ip, 0, 45);
}

/* ----------------------------- Flash messages ----------------------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

/* ----------------------------- CSRF ----------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';

    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Security check failed (invalid session token). Please go back, reload the page and try again.');
    }
}

/* ----------------------------- Logged-in user ----------------------------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function auth_id(): int
{
    return (int) (current_user()['user_id'] ?? 0);
}

function auth_role(): string
{
    return (string) (current_user()['role'] ?? '');
}

function auth_name(): string
{
    return (string) (current_user()['full_name'] ?? 'Guest');
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please log in to continue.');
        redirect(base_url('index.php'));
    }

    // A deactivated account loses access immediately, not only at the next login.
    $stmt = db()->prepare('SELECT is_active FROM users WHERE user_id = ?');
    $stmt->execute([auth_id()]);

    if ((int) $stmt->fetchColumn() !== 1) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('warning', 'Your account is no longer active.');
        redirect(base_url('index.php'));
    }
}

function require_role(string ...$roles): void
{
    require_login();

    if (!in_array(auth_role(), $roles, true)) {
        http_response_code(403);
        echo '<h3 style="font-family:system-ui">403 &mdash; Access denied</h3>'
           . '<p style="font-family:system-ui">Your account role (<strong>'
           . e(auth_role()) . '</strong>) is not allowed to open this page.</p>';
        exit;
    }
}

/* ----------------------------- Audit trail ----------------------------- */

function log_action(string $action, ?string $details = null, ?int $userId = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO system_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId ?? (is_logged_in() ? auth_id() : null),
            $action,
            $details,
            client_ip(),
        ]);
    } catch (Throwable $ex) {
        error_log('log_action failed: ' . $ex->getMessage());
    }
}

/* ----------------------------- Formatting ----------------------------- */

function fmt_date(?string $d): string
{
    return $d ? date('D, d M Y', strtotime($d)) : '—';
}

function fmt_time(?string $t): string
{
    return $t ? date('H:i', strtotime($t)) : '—';
}

function fmt_dt(?string $dt): string
{
    return $dt ? date('d M Y, H:i:s', strtotime($dt)) : '—';
}

function role_label(string $role): string
{
    return match ($role) {
        'lecturer'     => 'Lecturer',
        'student'      => 'Student',
        'school_admin' => 'School Administrator',
        'system_admin' => 'System Administrator',
        default        => ucfirst($role),
    };
}

function status_badge(string $status): string
{
    $map = [
        'present' => ['Present', 'ok'],
        'late'    => ['Late',    'warn'],
        'absent'  => ['Absent',  'bad'],
        'open'    => ['Open',    'ok'],
        'closed'  => ['Closed',  'muted'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst($status), 'muted'];
    return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
}

function distance_text(?float $m): string
{
    if ($m === null) {
        return '—';
    }
    return $m >= 1000 ? number_format($m / 1000, 2) . ' km' : number_format($m, 1) . ' m';
}

/* ----------------------------- Text ----------------------------- */

/**
 * Character count that works whether or not the mbstring extension is loaded.
 */
function text_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

/* ----------------------------- Attendance presentation ----------------------------- */

/** Attendance percentage, rounded to one decimal place. */
function attendance_pct(int $attended, int $held): float
{
    return $held > 0 ? round($attended / $held * 100, 1) : 0.0;
}

/**
 * Colour-coded attendance meter.
 * Green >= 75% (the usual university minimum), amber >= 50%, red below 50%.
 */
function pct_meter(int $attended, int $held, bool $withCounts = true): string
{
    $pct  = attendance_pct($attended, $held);
    $tone = $pct >= 75 ? '' : ($pct >= 50 ? 'mid' : 'low');

    $meter = '<div style="display:flex;align-items:center;gap:.5rem;min-width:130px">'
           . '<div class="meter ' . $tone . '" style="flex:1"><span style="width:' . $pct . '%"></span></div>'
           . '<span class="pct">' . number_format($pct, 1) . '%</span>'
           . '</div>';

    if (!$withCounts) {
        return $meter;
    }

    return '<div><div class="muted mono" style="font-size:.76rem">'
         . $attended . ' / ' . $held . ' sessions</div>' . $meter . '</div>';
}

/** Combined date + start time of a session, ready for display. */
function session_when(array $s): string
{
    return fmt_date($s['session_date'] ?? null) . ' &middot; ' . fmt_time($s['start_time'] ?? null);
}
