<?php
/** System Administrator: the audit trail used for troubleshooting. */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('system_admin');

$search = query('search');
$limit  = query_int('limit', 200);

if ($limit < 25 || $limit > 1000) {
    $limit = 200;
}

$logs = system_logs($limit, $search);

$page_title    = 'System Logs';
$page_subtitle = 'Every important action in the system, newest first.';
require APP_ROOT . '/includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <div><h2>Audit trail</h2><div class="sub"><?= count($logs) ?> entr(ies) shown</div></div>
        <form method="get" action="<?= e(base_url('sysadmin/logs.php')) ?>" class="btn-row" style="margin:0">
            <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search action, details or user…" style="min-width:240px">
            <select name="limit" style="width:auto">
                <?php foreach ([50, 200, 500, 1000] as $n): ?>
                    <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?> rows</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Filter</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-outline" href="<?= e(base_url('sysadmin/logs.php')) ?>">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$logs): ?>
        <div class="empty">No log entries match your filter.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead>
                <tr><th class="num">#</th><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="num muted"><?= (int) $l['log_id'] ?></td>
                        <td class="mono" style="white-space:nowrap"><?= e(fmt_dt($l['timestamp'])) ?></td>
                        <td><?= e($l['full_name'] ?? 'system') ?></td>
                        <td class="muted"><?= e($l['role'] ? role_label($l['role']) : '—') ?></td>
                        <td><span class="badge badge-navy"><?= e($l['action']) ?></span></td>
                        <td class="muted"><?= e($l['details'] ?? '') ?></td>
                        <td class="mono muted"><?= e($l['ip_address'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><div><h2>What the log records</h2></div></div>
    <ul style="margin:0;padding-left:1.2rem;font-size:.85rem;line-height:1.75">
        <li><span class="badge badge-navy">login_success</span> <span class="badge badge-navy">login_failed</span> — who signed in, and failed attempts.</li>
        <li><span class="badge badge-navy">class_created</span> <span class="badge badge-navy">class_updated</span> <span class="badge badge-navy">student_enrolled</span> — school administrator actions.</li>
        <li><span class="badge badge-navy">session_created</span> <span class="badge badge-navy">qr_token_issued</span> <span class="badge badge-navy">session_closed</span> — the lecturer's QR lifecycle.</li>
        <li><span class="badge badge-navy">attendance_submitted</span> <span class="badge badge-navy">attendance_rejected</span> — accepted scans and every rejected scan (with the reason).</li>
        <li><span class="badge badge-navy">attendance_edited</span> <span class="badge badge-navy">report_exported</span> — manual corrections and downloads.</li>
        <li><span class="badge badge-navy">user_created</span> <span class="badge badge-navy">user_deactivated</span> <span class="badge badge-navy">user_activated</span> <span class="badge badge-navy">password_reset</span> — system administrator account management.</li>
    </ul>
</div>

<?php require APP_ROOT . '/includes/footer.php'; ?>
