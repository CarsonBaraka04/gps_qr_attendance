<?php
/**
 * Lecturer: initiate a QR code  ->  creates the session and sends the lecturer
 * to the live projection screen. The lecturer's current GPS position becomes
 * the reference point for the geofence.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('lecturer');

$classes  = lecturer_classes(auth_id());
$classIds = array_map(static fn (array $c): int => (int) $c['class_id'], $classes);

$classId = query_int('class_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $classId  = post_int('class_id');
    $date     = post('session_date');
    $time     = post('start_time');
    $lat      = post_float('ref_latitude', 999);
    $lng      = post_float('ref_longitude', 999);
    $radius   = post_int('geofence_radius', DEFAULT_GEOFENCE_RADIUS);
    $errors   = [];

    if (!in_array($classId, $classIds, true)) {
        $errors[] = 'Select one of your own classes.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $errors[] = 'Enter a valid session date.';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        $errors[] = 'Enter a valid start time.';
    }
    if (!valid_coordinates($lat, $lng)) {
        $errors[] = 'Capture the reference GPS position of the venue (latitude and longitude).';
    }
    if ($radius < 10 || $radius > 1000) {
        $errors[] = 'The geofence radius must be between 10 and 1000 metres.';
    }

    if ($errors) {
        foreach ($errors as $err) {
            flash('danger', $err);
        }
    } else {
        $stmt = db()->prepare(
            'INSERT INTO sessions
                 (class_id, lecturer_id, session_date, start_time, ref_latitude, ref_longitude, geofence_radius, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'open\')'
        );
        $stmt->execute([$classId, auth_id(), $date, $time . ':00', $lat, $lng, $radius]);

        $newId = (int) db()->lastInsertId();
        $code  = '';

        foreach ($classes as $c) {
            if ((int) $c['class_id'] === $classId) {
                $code = $c['class_code'];
            }
        }

        log_action(
            'session_created',
            'Created session ' . $newId . ' for ' . $code . ' at ' . number_format($lat, 7) . ', ' . number_format($lng, 7)
            . ' with a ' . $radius . ' m geofence.'
        );

        flash('success', 'Session started. Project the QR code for your students.');
        redirect(base_url('lecturer/session_live.php?session_id=' . $newId));
    }
}

$page_title    = 'Initiate QR Code';
$page_subtitle = 'Start an attendance session and generate a rotating QR code.';
require APP_ROOT . '/includes/header.php';
?>

<?php if (!$classes): ?>
    <div class="card"><div class="empty">You have no classes assigned, so you cannot start a session.</div></div>
<?php else: ?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Start an attendance session</h2>
            <div class="sub">The reference position below becomes the centre of the 50 m geofence students must be inside.</div>
        </div>
    </div>

    <form method="post" action="<?= e(base_url('lecturer/session_start.php')) ?>" id="session-form">
        <?= csrf_field() ?>

        <div class="form-grid">
            <div class="field">
                <label for="class_id">Class</label>
                <select name="class_id" id="class_id" required>
                    <option value="">— select a class —</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= (int) $c['class_id'] ?>" <?= $classId === (int) $c['class_id'] ? 'selected' : '' ?>>
                            <?= e($c['class_code'] . ' — ' . $c['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="session_date">Session date</label>
                <input type="date" name="session_date" id="session_date" value="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div class="field">
                <label for="start_time">Start time</label>
                <input type="time" name="start_time" id="start_time" value="<?= e(date('H:i')) ?>" required>
                <span class="help">Students scanning more than <?= (int) LATE_AFTER_MINUTES ?> minutes after this time are marked <strong>late</strong>.</span>
            </div>

            <div class="field">
                <label for="geofence_radius">Geofence radius (metres)</label>
                <input type="number" name="geofence_radius" id="geofence_radius"
                       value="<?= (int) DEFAULT_GEOFENCE_RADIUS ?>" min="10" max="1000" required>
                <span class="help">Default 50 m — the accepted GPS accuracy for a lecture room.</span>
            </div>

            <div class="field">
                <label for="ref_latitude">Reference latitude</label>
                <input type="text" name="ref_latitude" id="ref_latitude" inputmode="decimal" placeholder="-1.3095964" required>
            </div>

            <div class="field">
                <label for="ref_longitude">Reference longitude</label>
                <input type="text" name="ref_longitude" id="ref_longitude" inputmode="decimal" placeholder="36.8125324" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-outline" id="btn-locate">📍 Use my current position</button>
            <button type="button" class="btn btn-outline" id="btn-venue">🏫 Use campus venue coordinates</button>
            <button type="submit" class="btn btn-accent">Initiate QR code →</button>
        </div>

        <div id="geo-status" class="alert alert-info" style="margin-top:.9rem;margin-bottom:0">
            Click <strong>Use my current position</strong> so the geofence is centred on this room.
            If the browser cannot get a GPS fix (common on a laptop indoors), use the campus coordinates button or type them in manually.
        </div>
    </form>
</div>

<script>
(function () {
    var statusBox = document.getElementById('geo-status');

    function say(message, kind) {
        statusBox.className = 'alert alert-' + kind;
        statusBox.innerHTML = message;
    }

    document.getElementById('btn-locate').addEventListener('click', function () {
        if (!navigator.geolocation) {
            say('This browser does not support the Geolocation API. Enter the coordinates manually.', 'warning');
            return;
        }

        say('Requesting your position… allow location access when the browser asks.', 'info');

        navigator.geolocation.getCurrentPosition(function (pos) {
            document.getElementById('ref_latitude').value  = pos.coords.latitude.toFixed(7);
            document.getElementById('ref_longitude').value = pos.coords.longitude.toFixed(7);
            say('Position captured: <strong>' + pos.coords.latitude.toFixed(7) + ', ' + pos.coords.longitude.toFixed(7)
                + '</strong> (accurate to about ' + Math.round(pos.coords.accuracy) + ' m).', 'success');
        }, function (err) {
            var reasons = {1: 'permission was denied', 2: 'the position is unavailable', 3: 'the request timed out'};
            say('Could not read your position: ' + (reasons[err.code] || 'unknown error')
                + '. Enter the coordinates manually or use the campus coordinates button.', 'warning');
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
    });

    document.getElementById('btn-venue').addEventListener('click', function () {
        document.getElementById('ref_latitude').value  = '-1.3095964';
        document.getElementById('ref_longitude').value = '36.8125324';
        say('Campus venue coordinates filled in (Strathmore University, Ole Sangale Road).', 'info');
    });
})();
</script>

<?php endif; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
