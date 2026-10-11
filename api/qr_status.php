<?php
/**
 * API  -  Live attendance feed for the lecturer's screen.
 * Returns the number of students who have scanned so far plus the latest names.
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

$present = (int) db_value(
    'SELECT COUNT(*) FROM attendance_records
      WHERE session_id = ? AND status IN (\'present\',\'late\')',
    [$sessionId]
);

$enrolled = (int) db_value('SELECT COUNT(*) FROM enrollments WHERE class_id = ?', [$session['class_id']]);

$stmt = db()->prepare(
    'SELECT u.full_name, u.reg_no, ar.status, ar.timestamp, ar.distance_m, ar.is_edited
       FROM attendance_records ar
       JOIN users u ON u.user_id = ar.student_id
      WHERE ar.session_id = ?
      ORDER BY ar.timestamp DESC
      LIMIT 60'
);
$stmt->execute([$sessionId]);

$scans = array_map(static function (array $r): array {
    return [
        'name'       => $r['full_name'],
        'reg_no'     => $r['reg_no'] ?? '',
        'status'     => $r['status'],
        'time'       => date('H:i:s', strtotime($r['timestamp'])),
        'distance'   => $r['distance_m'] !== null ? round((float) $r['distance_m'], 1) : null,
        'is_edited'  => (int) $r['is_edited'] === 1,
    ];
}, $stmt->fetchAll());

json_out([
    'ok'       => true,
    'present'  => $present,
    'enrolled' => $enrolled,
    'absent'   => max(0, $enrolled - $present),
    'status'   => $session['status'],
    'scans'    => $scans,
    'checked_at' => date('H:i:s'),
]);
