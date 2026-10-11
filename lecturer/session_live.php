<?php
/**
 * Lecturer: the live QR projection screen.
 * The token rotates automatically every QR_TOKEN_TTL seconds and the list of
 * students who have scanned refreshes every 5 seconds.
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('lecturer');

$sessionId = query_int('session_id');
$session   = session_detail($sessionId);

if (!$session || (int) $session['lecturer_id'] !== auth_id()) {
    flash('danger', 'That session does not exist or does not belong to you.');
    redirect(base_url('lecturer/classes.php'));
}

/* ---------- close the session ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (post('action') === 'close') {
        db()->prepare('UPDATE sessions SET status = \'closed\' WHERE session_id = ?')->execute([$sessionId]);
        db()->prepare('UPDATE qr_codes SET is_active = 0 WHERE session_id = ?')->execute([$sessionId]);

        log_action('session_closed', 'Session ' . $sessionId . ' (' . $session['class_code'] . ') closed by the lecturer.');

        flash('success', 'Session closed. The QR code is no longer valid and attendance can no longer be submitted.');
        redirect(base_url('lecturer/attendance_edit.php?session_id=' . $sessionId));
    }
}

$enrolled = (int) db_value('SELECT COUNT(*) FROM enrollments WHERE class_id = ?', [(int) $session['class_id']]);
$present  = (int) db_value(
    'SELECT COUNT(*) FROM attendance_records WHERE session_id = ? AND status IN (\'present\',\'late\')',
    [$sessionId]
);
$isOpen   = $session['status'] === 'open';

$page_title    = 'Live QR Code — ' . $session['class_code'];
$page_subtitle = $session['class_name'] . ' · ' . fmt_date($session['session_date']) . ' · ' . fmt_time($session['start_time']);
require APP_ROOT . '/includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <div>
            <h2><?= e($session['class_code']) ?> &mdash; <?= e($session['class_name']) ?></h2>
            <div class="sub">
                <?= e(fmt_date($session['session_date'])) ?> at <?= e(fmt_time($session['start_time'])) ?>
                &middot; geofence <?= (int) $session['geofence_radius'] ?> m
                &middot; reference <?= e(number_format((float) $session['ref_latitude'], 7)) ?>, <?= e(number_format((float) $session['ref_longitude'], 7)) ?>
            </div>
        </div>
        <div class="btn-row">
            <?php if ($isOpen): ?>
                <?= status_badge('open') ?>
                <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/attendance.php?class_id=' . (int) $session['class_id'])) ?>">Attendance</a>
                <form method="post" style="margin:0" onsubmit="return confirm('Close this session? Students will no longer be able to scan.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close">
                    <button type="submit" class="btn btn-sm btn-danger">Close session</button>
                </form>
            <?php else: ?>
                <?= status_badge('closed') ?>
                <a class="btn btn-sm" href="<?= e(base_url('lecturer/attendance_edit.php?session_id=' . $sessionId)) ?>">Open attendance register</a>
                <a class="btn btn-sm btn-outline" href="<?= e(base_url('lecturer/export.php?type=session&session_id=' . $sessionId)) ?>">Export register (CSV)</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isOpen): ?>
        <div class="qr-stage">

            <div>
                <div class="qr-box" id="qrcode">
                    <span class="muted">Generating the QR code…</span>
                </div>

                <div class="countdown">
                    <span class="ring" id="secs">--</span>
                    <span>seconds until this code is replaced automatically</span>
                </div>

                <div class="help mono" id="token-url" style="word-break:break-all;font-size:.72rem;margin-top:.4rem"></div>

                <div class="alert alert-info" style="margin-top:.9rem;margin-bottom:0;font-size:.8rem">
                    Project this screen. The code changes every <?= (int) QR_TOKEN_TTL ?> seconds, so a screenshot
                    sent to an absent classmate will not work. Students must also be inside the
                    <?= (int) $session['geofence_radius'] ?> m geofence to be marked present.
                </div>
            </div>

            <div>
                <div class="stat-grid" style="grid-template-columns:repeat(3, minmax(120px, 1fr)); margin-bottom:.9rem">
                    <div class="stat ok"><div class="label">Present</div><div class="value" id="count-present"><?= $present ?></div></div>
                    <div class="stat bad"><div class="label">Not yet scanned</div><div class="value" id="count-absent"><?= max(0, $enrolled - $present) ?></div></div>
                    <div class="stat"><div class="label">Enrolled</div><div class="value"><?= $enrolled ?></div></div>
                </div>

                <div class="card-head" style="margin-bottom:.5rem">
                    <div>
                        <h2 style="font-size:1rem"><span class="live-dot"></span>Scans received</h2>
                        <div class="sub" id="live-updated">Updating every 5 seconds…</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data">
                        <thead>
                        <tr><th>Student</th><th>Reg. no</th><th>Status</th><th>Time</th><th class="num">Distance</th></tr>
                        </thead>
                        <tbody id="scan-rows">
                            <tr><td colspan="5" class="muted">No scans yet. Waiting…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <script src="<?= e(base_url('assets/js/vendor/qrcode.min.js')) ?>"></script>
        <script>
        (function () {
            var SESSION_ID = <?= (int) $sessionId ?>;
            var ISSUE_URL  = <?= json_encode(base_url('api/qr_issue.php?session_id=' . $sessionId)) ?>;
            var STATUS_URL = <?= json_encode(base_url('api/qr_status.php?session_id=' . $sessionId)) ?>;

            var qrContainer = document.getElementById('qrcode');
            var secsBox     = document.getElementById('secs');
            var urlBox      = document.getElementById('token-url');
            var rowsBox     = document.getElementById('scan-rows');
            var secsLeft    = 0;
            var busy        = false;

            function esc(value) {
                return String(value === null || value === undefined ? '' : value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            function drawQr(text) {
                qrContainer.innerHTML = '';
                new QRCode(qrContainer, {
                    text: text,
                    width: 300,
                    height: 300,
                    colorDark: '#002E56',
                    colorLight: '#FFFFFF',
                    correctLevel: QRCode.CorrectLevel.M
                });
            }

            function rotate() {
                if (busy) { return; }
                busy = true;

                fetch(ISSUE_URL, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        busy = false;
                        if (!data.ok) {
                            qrContainer.innerHTML = '<span class="muted">' + esc(data.error || 'Could not generate the QR code.') + '</span>';
                            return;
                        }
                        drawQr(data.scan_url);
                        urlBox.textContent = data.scan_url;
                        secsLeft = parseInt(data.expires_in, 10) || 60;
                        secsBox.textContent = secsLeft;
                    })
                    .catch(function () {
                        busy = false;
                        qrContainer.innerHTML = '<span class="muted">Network error while generating the QR code.</span>';
                    });
            }

            function refreshStatus() {
                fetch(STATUS_URL, { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.ok) { return; }

                        document.getElementById('count-present').textContent = data.present;
                        document.getElementById('count-absent').textContent  = data.absent;
                        document.getElementById('live-updated').textContent  = 'Last updated ' + data.checked_at;

                        if (!data.scans.length) {
                            rowsBox.innerHTML = '<tr><td colspan="5" class="muted">No scans yet. Waiting…</td></tr>';
                            return;
                        }

                        var html = '';
                        data.scans.forEach(function (s) {
                            var badge = s.status === 'present'
                                ? '<span class="badge badge-ok">Present</span>'
                                : (s.status === 'late'
                                    ? '<span class="badge badge-warn">Late</span>'
                                    : '<span class="badge badge-bad">Absent</span>');

                            if (s.is_edited) {
                                badge += ' <span class="badge badge-muted">edited</span>';
                            }

                            html += '<tr>'
                                 +  '<td>' + esc(s.name) + '</td>'
                                 +  '<td class="mono">' + esc(s.reg_no) + '</td>'
                                 +  '<td>' + badge + '</td>'
                                 +  '<td class="mono">' + esc(s.time) + '</td>'
                                 +  '<td class="num">' + (s.distance === null ? '—' : s.distance + ' m') + '</td>'
                                 +  '</tr>';
                        });
                        rowsBox.innerHTML = html;
                    })
                    .catch(function () { /* keep the previous list on a network wobble */ });
            }

            rotate();
            refreshStatus();

            setInterval(function () {
                secsLeft--;
                secsBox.textContent = secsLeft > 0 ? secsLeft : 0;
                if (secsLeft <= 0) { rotate(); }
            }, 1000);

            setInterval(refreshStatus, 5000);
        })();
        </script>

    <?php else: ?>
        <div class="empty">
            <strong>This session is closed.</strong><br>
            <?= $present ?> of <?= $enrolled ?> students were recorded. The QR code has been invalidated.
        </div>
        <div class="btn-row" style="margin-top:1rem">
            <a class="btn" href="<?= e(base_url('lecturer/attendance_edit.php?session_id=' . $sessionId)) ?>">Open the attendance register</a>
            <a class="btn btn-outline" href="<?= e(base_url('lecturer/export.php?type=session&session_id=' . $sessionId)) ?>">Export as CSV</a>
            <a class="btn btn-outline" href="<?= e(base_url('lecturer/session_start.php?class_id=' . (int) $session['class_id'])) ?>">Start a new session</a>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
