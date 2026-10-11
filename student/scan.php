<?php
/**
 * Student: scan the QR code and submit attendance.
 *
 * The browser reads the QR code with the camera (html5-qrcode) and reads the
 * student's position with the HTML5 Geolocation API. Both the token and the
 * coordinates are sent to api/submit_scan.php, which applies every rule
 * (token validity, session open, enrolment, duplicates, geofence) on the server.
 *
 * A manual token box is provided because camera access is blocked on plain
 * HTTP pages - see the README for how to run the demo over HTTPS or localhost.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('student');

$tokenFromUrl = query('token');
$openSession  = open_session_for_student(auth_id());

$page_title    = 'Scan QR Code';
$page_subtitle = 'Point your camera at the QR code on the lecturer\'s screen. You must be in the lecture room.';
require APP_ROOT . '/includes/header.php';
?>

<?php if ($openSession): ?>
    <div class="alert alert-success">
        <strong><span class="live-dot"></span>Session open:</strong>
        <?= e($openSession['class_code'] . ' — ' . $openSession['class_name']) ?>
        (<?= e(fmt_date($openSession['session_date']) . ' ' . fmt_time($openSession['start_time'])) ?>,
        <?= e($openSession['lecturer_name']) ?>).
    </div>
<?php else: ?>
    <div class="alert alert-warning">
        <strong>No session is open right now.</strong>
        You can still scan, but the system will only accept your attendance while the lecturer has a session running.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div>
            <h2>Scan the QR code</h2>
            <div class="sub">The code changes every minute, so it cannot be forwarded to someone who is not in the room.</div>
        </div>
    </div>

    <div id="insecure-warning" class="alert alert-warning" style="display:none">
        <strong>Camera scanning needs a secure connection.</strong>
        Browsers only allow the camera on <code>https://</code> pages or on <code>localhost</code>.
        You are on <code><?= e(base_url()) ?></code>, so use the manual code entry box below, or ask your
        administrator to serve the system over HTTPS.
    </div>

    <div class="form-grid" style="grid-template-columns:1fr">

        <div class="field">
            <label for="qr-reader-label">Camera</label>
            <div id="qr-reader"></div>
            <div class="btn-row" style="margin-top:.6rem">
                <button type="button" class="btn" id="btn-start-camera">📷 Start camera</button>
                <button type="button" class="btn btn-outline" id="btn-stop-camera" style="display:none">Stop camera</button>
            </div>
            <span class="help" id="camera-status">The camera stays on this device; nothing is uploaded until you submit.</span>
        </div>

        <div class="field">
            <label for="token">QR code token</label>
            <input type="text" id="token" name="token" placeholder="Filled automatically when the camera reads the code"
                   value="<?= e($tokenFromUrl) ?>" autocomplete="off">
            <span class="help">If the camera is unavailable, type the code shown under the QR on the lecturer's screen.</span>
        </div>

        <div class="field">
            <label>Your position</label>
            <div id="geo-line" class="help">Not read yet. Your position is captured when you submit.</div>
            <div class="btn-row" style="margin-top:.4rem">
                <button type="button" class="btn btn-outline btn-sm" id="btn-locate">📍 Read my position now</button>
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-accent" id="btn-submit">✓ Mark my attendance</button>
            <a class="btn btn-outline" href="<?= e(base_url('student/records.php')) ?>">My records</a>
        </div>
    </div>

    <div class="scan-result" id="result"></div>
</div>

<div class="card">
    <div class="card-head"><div><h2>How it works</h2></div></div>
    <ol style="margin:0;padding-left:1.2rem;font-size:.88rem;line-height:1.7">
        <li>Your lecturer starts a session and projects the QR code.</li>
        <li>You scan the code. The system reads the token inside it.</li>
        <li>The browser sends your GPS position together with the token.</li>
        <li>The server checks the token is still valid, that the session is open, that you are enrolled, that you have not scanned already, and that you are inside the <?= (int) DEFAULT_GEOFENCE_RADIUS ?> m geofence.</li>
        <li>Your attendance is recorded with the exact time and your distance from the lecturer's position.</li>
    </ol>
</div>

<script src="<?= e(base_url('assets/js/vendor/html5-qrcode.min.js')) ?>"></script>
<script>
(function () {
    var SUBMIT_URL = <?= json_encode(base_url('api/submit_scan.php')) ?>;

    var tokenInput  = document.getElementById('token');
    var resultBox   = document.getElementById('result');
    var geoLine     = document.getElementById('geo-line');
    var camStatus   = document.getElementById('camera-status');
    var startBtn    = document.getElementById('btn-start-camera');
    var stopBtn     = document.getElementById('btn-stop-camera');
    var submitBtn   = document.getElementById('btn-submit');

    var scanner   = null;
    var position  = null;      // { latitude, longitude, accuracy }
    var submitting = false;

    /* ---------- secure context check ---------- */
    if (!window.isSecureContext) {
        document.getElementById('insecure-warning').style.display = 'block';
        startBtn.disabled = true;
        camStatus.textContent = 'Camera disabled on this connection. Use the manual code box.';
    }

    function say(element, html, kind) {
        element.className = kind === 'fail' ? 'alert alert-danger' : 'alert alert-info';
        element.innerHTML = html;
    }

    /* ---------- geolocation ---------- */
    function readPosition() {
        return new Promise(function (resolve, reject) {
            if (!navigator.geolocation) {
                reject(new Error('This browser does not support the HTML5 Geolocation API.'));
                return;
            }

            geoLine.textContent = 'Reading your position… allow location access when asked.';

            navigator.geolocation.getCurrentPosition(function (pos) {
                position = {
                    latitude:  pos.coords.latitude,
                    longitude: pos.coords.longitude,
                    accuracy:  pos.coords.accuracy
                };
                geoLine.innerHTML = 'Position read: <strong>' + position.latitude.toFixed(6) + ', '
                                  + position.longitude.toFixed(6) + '</strong> (accurate to about '
                                  + Math.round(position.accuracy) + ' m).';
                resolve(position);
            }, function (err) {
                var reasons = {1: 'you denied permission', 2: 'the position is unavailable', 3: 'the request timed out'};
                var message = 'Could not read your position: ' + (reasons[err.code] || 'unknown error') + '.';
                geoLine.textContent = message;
                reject(new Error(message + ' Turn on location services, allow access in the browser and try again.'));
            }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
        });
    }

    document.getElementById('btn-locate').addEventListener('click', function () {
        readPosition().catch(function (e) { geoLine.textContent = e.message; });
    });

    /* ---------- submission ---------- */
    function submit() {
        if (submitting) { return; }

        var token = tokenInput.value.trim();

        resultBox.className = 'scan-result show';
        resultBox.innerHTML = '<div class="big-status">Submitting…</div>Please allow location access if the browser asks.';

        if (!token) {
            resultBox.className = 'scan-result show fail';
            resultBox.innerHTML = '<div class="big-status">No QR code token</div>Scan the code again, or type the code shown under the QR image.';
            return;
        }

        submitting = true;
        submitBtn.disabled = true;

        readPosition()
            .then(function (pos) {
                var body = new URLSearchParams();
                body.append('token', token);
                body.append('latitude', pos.latitude);
                body.append('longitude', pos.longitude);
                body.append('accuracy', pos.accuracy);

                return fetch(SUBMIT_URL, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                });
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                submitting = false;
                submitBtn.disabled = false;

                if (!data.ok) {
                    resultBox.className = 'scan-result show fail';
                    resultBox.innerHTML = '<div class="big-status">Attendance not recorded</div>' + escapeHtml(data.error || 'Unknown error.');
                    return;
                }

                var d = data.data || {};

                if (data.duplicate) {
                    resultBox.className = 'scan-result show';
                    resultBox.innerHTML = '<div class="big-status">Already recorded</div>' + escapeHtml(data.message);
                    return;
                }

                resultBox.className = 'scan-result show ok';
                resultBox.innerHTML =
                    '<div class="big-status">✓ Attendance recorded — ' + escapeHtml(d.status.toUpperCase()) + '</div>'
                  + '<div style="line-height:1.8">'
                  + '<strong>Class:</strong> ' + escapeHtml(d.class_code) + ' — ' + escapeHtml(d.class_name) + '<br>'
                  + '<strong>Session:</strong> ' + escapeHtml(d.session_date) + ' at ' + escapeHtml(d.start_time) + '<br>'
                  + '<strong>Recorded at:</strong> ' + escapeHtml(d.time) + '<br>'
                  + '<strong>Distance from the lecturer:</strong> ' + escapeHtml(d.distance_m) + ' m'
                  + '</div>';
            })
            .catch(function (error) {
                submitting = false;
                submitBtn.disabled = false;
                resultBox.className = 'scan-result show fail';
                resultBox.innerHTML = '<div class="big-status">Could not submit</div>' + escapeHtml(error.message);
            });
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    submitBtn.addEventListener('click', submit);

    /* ---------- camera ---------- */
    function extractToken(text) {
        var value = String(text).trim();
        try {
            var url = new URL(value);
            var fromUrl = url.searchParams.get('token');
            if (fromUrl) { return fromUrl; }
        } catch (e) { /* not a URL, treat it as the raw token */ }
        return value;
    }

    startBtn.addEventListener('click', function () {
        if (typeof Html5Qrcode === 'undefined') {
            camStatus.textContent = 'The scanning library did not load. Use the manual code box.';
            return;
        }

        scanner = new Html5Qrcode('qr-reader');
        startBtn.style.display = 'none';
        stopBtn.style.display = 'inline-flex';
        camStatus.textContent = 'Point the camera at the QR code…';

        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 240, height: 240 } },
            function (decodedText) {
                tokenInput.value = extractToken(decodedText);
                camStatus.textContent = 'QR code read. Submitting your attendance…';
                stopCamera().then(submit);
            },
            function () { /* ignore individual frames that do not contain a code */ }
        ).catch(function (error) {
            startBtn.style.display = 'inline-flex';
            stopBtn.style.display = 'none';
            camStatus.textContent = 'Camera error: ' + error;
        });
    });

    function stopCamera() {
        if (!scanner) { return Promise.resolve(); }

        return scanner.stop().then(function () {
            scanner.clear();
            scanner = null;
            startBtn.style.display = 'inline-flex';
            stopBtn.style.display = 'none';
        }).catch(function () {
            scanner = null;
            startBtn.style.display = 'inline-flex';
            stopBtn.style.display = 'none';
        });
    }

    stopBtn.addEventListener('click', function () {
        stopCamera();
        camStatus.textContent = 'Camera stopped.';
    });
})();
</script>

<?php require APP_ROOT . '/includes/footer.php'; ?>
