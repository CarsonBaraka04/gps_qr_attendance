<?php
/**
 * API  -  Validate a student QR scan and record the attendance.
 *
 * Validation order:
 *   1. the caller is a logged-in student
 *   2. the token exists
 *   3. the token has not been replaced by a newer rotation
 *   4. the token has not expired (small grace period allowed)
 *   5. the session is still open
 *   6. the student is enrolled in that class
 *   7. the student has not already been marked for this session
 *   8. the student's GPS position is inside the geofence
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!is_logged_in()) {
    json_out(['ok' => false, 'error' => 'Your session has expired. Log in again and rescan.'], 401);
}

if (auth_role() !== 'student') {
    json_out(['ok' => false, 'error' => 'Only student accounts can submit attendance.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Invalid request method.'], 405);
}

$token    = post('token');
$accuracy = post_float('accuracy');

if ($token === '') {
    json_out(['ok' => false, 'error' => 'No QR code token was received. Scan the code again.'], 400);
}

if (!isset($_POST['latitude'], $_POST['longitude']) || $_POST['latitude'] === '' || $_POST['longitude'] === '') {
    json_out([
        'ok'    => false,
        'error' => 'Your location could not be read. Allow location access in your browser and try again.',
    ], 400);
}

$lat = (float) $_POST['latitude'];
$lng = (float) $_POST['longitude'];

if (!valid_coordinates($lat, $lng)) {
    json_out(['ok' => false, 'error' => 'The GPS coordinates received are not valid. Move to an open area and try again.'], 400);
}

/* ---------- 2. token ---------- */
$stmt = db()->prepare(
    'SELECT q.qr_id, q.token, q.expiry_time, q.is_active,
            s.session_id, s.class_id, s.lecturer_id, s.session_date, s.start_time,
            s.ref_latitude, s.ref_longitude, s.geofence_radius, s.status AS session_status,
            c.class_code, c.class_name, u.full_name AS lecturer_name
       FROM qr_codes q
       JOIN sessions s ON s.session_id = q.session_id
       JOIN classes  c ON c.class_id   = s.class_id
       JOIN users    u ON u.user_id    = s.lecturer_id
      WHERE q.token = ?
      LIMIT 1'
);
$stmt->execute([$token]);
$qr = $stmt->fetch();

if (!$qr) {
    log_action('attendance_rejected', 'Unrecognised QR token supplied by student ' . auth_name() . '.');
    json_out(['ok' => false, 'error' => 'This QR code is not recognised. Make sure you are scanning the code on the lecturer\'s screen.'], 404);
}

/* ---------- 3. session open ---------- */
if ($qr['session_status'] !== 'open') {
    json_out(['ok' => false, 'error' => 'This session has been closed by the lecturer. Attendance can no longer be submitted.'], 409);
}

/* ---------- 4. replaced by a newer rotation ---------- */
if ((int) $qr['is_active'] !== 1) {
    json_out([
        'ok'    => false,
        'error' => 'This QR code has been replaced by a newer one. Point your camera at the current code on the screen.',
    ], 409);
}

/* ---------- 5. expiry ---------- */
$expiresAt = strtotime($qr['expiry_time']);
if (time() > $expiresAt + QR_GRACE_SECONDS) {
    json_out([
        'ok'    => false,
        'error' => 'This QR code has expired. Wait for the next code to appear (it refreshes every '
                   . (int) QR_TOKEN_TTL . ' seconds) and scan again.',
    ], 410);
}

/* ---------- 6. enrolled ---------- */
$enrolled = (int) db_value(
    'SELECT COUNT(*) FROM enrollments WHERE class_id = ? AND student_id = ?',
    [(int) $qr['class_id'], auth_id()]
);

if ($enrolled === 0) {
    log_action('attendance_rejected', auth_name() . ' is not enrolled in ' . $qr['class_code'] . '.');
    json_out(['ok' => false, 'error' => 'You are not enrolled in ' . $qr['class_code'] . ', so your attendance cannot be recorded.'], 403);
}

/* ---------- 7. duplicate ---------- */
$existing = db()->prepare(
    'SELECT attendance_id, status, timestamp, distance_m FROM attendance_records
      WHERE session_id = ? AND student_id = ? LIMIT 1'
);
$existing->execute([(int) $qr['session_id'], auth_id()]);
$already = $existing->fetch();

if ($already) {
    json_out([
        'ok'       => true,
        'duplicate' => true,
        'message'  => 'You were already marked ' . strtoupper($already['status']) . ' for this session at '
                      . date('H:i:s', strtotime($already['timestamp'])) . '.',
        'data'     => [
            'class_code'  => $qr['class_code'],
            'class_name'  => $qr['class_name'],
            'session_date' => $qr['session_date'],
            'start_time'  => substr($qr['start_time'], 0, 5),
            'status'      => $already['status'],
            'time'        => date('H:i:s', strtotime($already['timestamp'])),
            'distance_m'  => $already['distance_m'] !== null ? round((float) $already['distance_m'], 1) : null,
        ],
    ]);
}

/* ---------- 8. geofence ---------- */
$geo = check_geofence(
    $lat,
    $lng,
    (float) $qr['ref_latitude'],
    (float) $qr['ref_longitude'],
    (int) $qr['geofence_radius']
);

if (!$geo['inside']) {
    log_action(
        'attendance_rejected',
        sprintf('%s scanned from %.1f m away in %s - outside the %d m geofence.',
            auth_name(), $geo['distance'], $qr['class_code'], (int) $qr['geofence_radius'])
    );

    json_out([
        'ok'       => false,
        'error'    => 'You are ' . distance_text($geo['distance']) . ' from the lecture venue. '
                      . 'You must be within ' . (int) $qr['geofence_radius'] . ' m of the lecturer\'s position to be marked present.',
        'distance' => round($geo['distance'], 1),
    ], 403);
}

/* ---------- record it ---------- */
$sessionStart = strtotime($qr['session_date'] . ' ' . $qr['start_time']);
$status       = time() > $sessionStart + (LATE_AFTER_MINUTES * 60) ? 'late' : 'present';
$distance     = round($geo['distance'], 2);

try {
    $insert = db()->prepare(
        'INSERT INTO attendance_records
             (session_id, student_id, student_latitude, student_longitude, distance_m, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([(int) $qr['session_id'], auth_id(), $lat, $lng, $distance, $status]);
} catch (PDOException $ex) {
    if ($ex->getCode() === '23000') {
        json_out(['ok' => true, 'duplicate' => true, 'message' => 'Your attendance for this session was already recorded.']);
    }
    throw $ex;
}

log_action(
    'attendance_submitted',
    sprintf('Attendance recorded for session %d in %s (%s, %.1f m from the reference point).',
        (int) $qr['session_id'], $qr['class_code'], $status, $geo['distance'])
);

json_out([
    'ok'   => true,
    'data' => [
        'class_code'   => $qr['class_code'],
        'class_name'   => $qr['class_name'],
        'lecturer_name' => $qr['lecturer_name'],
        'session_date' => $qr['session_date'],
        'start_time'   => substr($qr['start_time'], 0, 5),
        'status'       => $status,
        'time'         => date('H:i:s'),
        'distance_m'   => round($geo['distance'], 1),
        'accuracy_m'   => $accuracy > 0 ? round($accuracy, 1) : null,
    ],
]);
