<?php
/**
 * Export attendance reports as CSV (opens directly in Excel).
 *
 * ?type=session&session_id=3   one session register
 * ?type=summary&class_id=1     per-student totals and percentage
 * ?type=matrix&class_id=1      student x session grid with P / L / A
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_role('lecturer', 'school_admin', 'system_admin');

/* A notice or warning printed into the output stream would corrupt the file,
   so error display is switched off for this download endpoint. */
ini_set('display_errors', '0');

$type      = query('type', 'summary');
$classId   = query_int('class_id');
$sessionId = query_int('session_id');
$role      = auth_role();

/* ----------------------------- Access control ----------------------------- */
if ($type === 'session') {
    $session = session_detail($sessionId);

    if (!$session) {
        flash('danger', 'That session does not exist.');
        redirect(base_url('dashboard.php'));
    }

    if ($role === 'lecturer' && (int) $session['lecturer_id'] !== auth_id()) {
        flash('danger', 'That session does not belong to you.');
        redirect(base_url('lecturer/classes.php'));
    }

    $classId = (int) $session['class_id'];
    $class   = ['class_code' => $session['class_code'], 'class_name' => $session['class_name']];
} else {
    if ($classId <= 0) {
        flash('danger', 'Choose a class before exporting.');
        redirect(base_url('dashboard.php'));
    }

    $stmt = db()->prepare('SELECT class_id, class_code, class_name, lecturer_id FROM classes WHERE class_id = ? LIMIT 1');
    $stmt->execute([$classId]);
    $class = $stmt->fetch();

    if (!$class) {
        flash('danger', 'That class does not exist.');
        redirect(base_url('dashboard.php'));
    }

    if ($role === 'lecturer' && (int) $class['lecturer_id'] !== auth_id()) {
        flash('danger', 'That class is not assigned to you.');
        redirect(base_url('lecturer/classes.php'));
    }
}

/* ----------------------------- Build the CSV ----------------------------- */
$rows     = [];
$filename = '';

switch ($type) {

    case 'session':
        $rows = session_register($sessionId);

        $csv = [['Reg. No', 'Student', 'Status', 'Scan time', 'Distance from reference (m)',
                 'Manually edited', 'Edited by', 'Edited at']];

        foreach ($rows as $r) {
            $csv[] = [
                $r['reg_no'] ?? '',
                $r['full_name'],
                $r['attendance_id'] === null ? 'No scan' : ucfirst($r['status']),
                $r['timestamp'] ? date('d M Y H:i:s', strtotime($r['timestamp'])) : '',
                $r['distance_m'] !== null ? number_format((float) $r['distance_m'], 1, '.', '') : '',
                (int) $r['is_edited'] === 1 ? 'Yes' : 'No',
                (int) $r['is_edited'] === 1 ? ($r['edited_by_name'] ?? '') : '',
                (int) $r['is_edited'] === 1 && $r['edited_at'] ? date('d M Y H:i:s', strtotime($r['edited_at'])) : '',
            ];
        }

        $filename = 'register_' . preg_replace('/[^A-Za-z0-9]/', '', $class['class_code'])
                  . '_' . $session['session_date'] . '.csv';
        break;

    case 'matrix':
        $sessions = class_sessions($classId);
        $sessions = array_reverse($sessions);               // oldest first across the page
        $students = class_attendance_summary($classId);

        $stmt = db()->prepare(
            'SELECT ar.student_id, ar.session_id, ar.status
               FROM attendance_records ar
               JOIN sessions s ON s.session_id = ar.session_id
              WHERE s.class_id = ?'
        );
        $stmt->execute([$classId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['student_id']][(int) $row['session_id']] = $row['status'];
        }

        $head = ['Reg. No', 'Student'];
        foreach ($sessions as $s) {
            $head[] = date('d/m', strtotime($s['session_date'])) . ' ' . substr($s['start_time'], 0, 5);
        }
        $head[] = 'Sessions held';
        $head[] = 'Attended';
        $head[] = 'Attendance %';

        $csv = [$head];

        foreach ($students as $st) {
            $line = [$st['reg_no'] ?? '', $st['full_name']];

            foreach ($sessions as $s) {
                $status = $map[(int) $st['user_id']][(int) $s['session_id']] ?? null;
                $line[] = match ($status) {
                    'present' => 'P',
                    'late'    => 'L',
                    'absent'  => 'A',
                    default   => '-',
                };
            }

            $held     = (int) $st['sessions_held'];
            $attended = (int) $st['attended'];

            $line[] = $held;
            $line[] = $attended;
            $line[] = number_format(attendance_pct($attended, $held), 1, '.', '') . '%';

            $csv[] = $line;
        }

        $csv[] = [];
        $csv[] = ['KEY: P = present, L = late, A = absent (no record), - = session held before the student enrolled'];

        $filename = 'matrix_' . preg_replace('/[^A-Za-z0-9]/', '', $class['class_code'])
                  . '_' . date('Y-m-d') . '.csv';
        break;

    case 'summary':
    default:
        $summary = class_attendance_summary($classId);

        $csv = [['Reg. No', 'Student', 'Sessions held', 'Attended', 'Late', 'Absent', 'Attendance %', 'Meets 75% requirement']];

        foreach ($summary as $st) {
            $held     = (int) $st['sessions_held'];
            $attended = (int) $st['attended'];
            $pct      = attendance_pct($attended, $held);

            $csv[] = [
                $st['reg_no'] ?? '',
                $st['full_name'],
                $held,
                $attended,
                (int) $st['late_count'],
                (int) $st['absent_count'],
                number_format($pct, 1, '.', '') . '%',
                $pct >= 75 ? 'Yes' : 'No',
            ];
        }

        $filename = 'summary_' . preg_replace('/[^A-Za-z0-9]/', '', $class['class_code'])
                  . '_' . date('Y-m-d') . '.csv';
        break;
}

log_action('report_exported', 'Exported the ' . $type . ' report for ' . $class['class_code']
    . ' (' . count($csv) - 1 . ' row(s)).');

/* ----------------------------- Stream the download ----------------------------- */
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

/* UTF-8 byte-order mark so Excel on Windows shows the accented characters correctly */
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['GPS-Enabled QR Code-Based Attendance System'], ',', '"', '');
fputcsv($out, [$class['class_code'] . ' — ' . $class['class_name']], ',', '"', '');
fputcsv($out, ['Report type: ' . ucfirst($type), 'Generated: ' . date('d M Y H:i:s')], ',', '"', '');
fputcsv($out, [], ',', '"', '');

foreach ($csv as $line) {
    fputcsv($out, $line, ',', '"', '');
}

fclose($out);
exit;
