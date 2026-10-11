<?php
/**
 * API  -  Issue (or rotate) the QR token for an open session.
 * Called automatically by the lecturer's live screen every QR_TOKEN_TTL seconds.
 * Only the lecturer who owns the session may call it.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('lecturer');

$sessionId = query_int('session_id');
$session   = session_detail($sessionId);

if (!$session) {
    json_out(['ok' => false, 'error' => 'Session not found.'], 404);
}

if ((int) $session['lecturer_id'] !== auth_id()) {
    json_out(['ok' => false, 'error' => 'This session does not belong to you.'], 403);
}

if ($session['status'] !== 'open') {
    json_out(['ok' => false, 'error' => 'This session has already been closed.'], 409);
}

/* A rotating token invalidates the previous one, so a screenshot that is
   forwarded on WhatsApp cannot be used later. */
db()->prepare('UPDATE qr_codes SET is_active = 0 WHERE session_id = ? AND is_active = 1')
    ->execute([$sessionId]);

$firstIssue = ((int) db_value('SELECT COUNT(*) FROM qr_codes WHERE session_id = ?', [$sessionId])) === 0;
$token      = bin2hex(random_bytes(24));

/* The expiry is calculated with the PHP clock and stored as a plain datetime.
   Calculating it with MySQL NOW() instead would break whenever the PHP and
   MySQL time zones disagree (for example PHP on Africa/Nairobi and MySQL on UTC). */
$expiresAt = date('Y-m-d H:i:s', time() + QR_TOKEN_TTL);

$stmt = db()->prepare(
    'INSERT INTO qr_codes (session_id, token, expiry_time, is_active) VALUES (?, ?, ?, 1)'
);
$stmt->execute([$sessionId, $token, $expiresAt]);

if ($firstIssue) {
    log_action(
        'qr_token_issued',
        'QR code initiated for session ' . $sessionId . ' (' . $session['class_code'] . ').'
    );
}

json_out([
    'ok'         => true,
    'token'      => $token,
    'scan_url'   => base_url('student/scan.php?token=' . $token),
    'expires_in' => (int) QR_TOKEN_TTL,
    'rotated_at' => date('H:i:s'),
]);
