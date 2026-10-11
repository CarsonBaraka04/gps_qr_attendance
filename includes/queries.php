<?php
/**
 * All domain queries in one place so the pages stay readable.
 */

/* ------------------------- Lecturer ------------------------- */

function lecturer_classes(int $lecturerId): array
{
    $sql = 'SELECT c.class_id, c.class_code, c.class_name, c.description,
                   COUNT(DISTINCT e.student_id) AS student_count,
                   COUNT(DISTINCT s.session_id) AS session_count,
                   (SELECT COUNT(*) FROM sessions os
                     WHERE os.class_id = c.class_id AND os.status = \'open\') AS open_sessions
              FROM classes c
              LEFT JOIN enrollments e ON e.class_id = c.class_id
              LEFT JOIN sessions s    ON s.class_id = c.class_id
             WHERE c.lecturer_id = ?
             GROUP BY c.class_id, c.class_code, c.class_name, c.description
             ORDER BY c.class_code';

    $stmt = db()->prepare($sql);
    $stmt->execute([$lecturerId]);
    return $stmt->fetchAll();
}

function class_sessions(int $classId, ?int $lecturerId = null): array
{
    $sql = 'SELECT s.*,
                   (SELECT COUNT(*) FROM attendance_records ar
                     WHERE ar.session_id = s.session_id
                       AND ar.status IN (\'present\',\'late\')) AS present_count,
                   (SELECT COUNT(*) FROM enrollments en
                     WHERE en.class_id = s.class_id) AS enrolled_count
              FROM sessions s
             WHERE s.class_id = ?';
    $params = [$classId];

    if ($lecturerId !== null) {
        $sql .= ' AND s.lecturer_id = ?';
        $params[] = $lecturerId;
    }

    $sql .= ' ORDER BY s.session_date DESC, s.start_time DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function session_detail(int $sessionId): ?array
{
    $sql = 'SELECT s.*, c.class_code, c.class_name, u.full_name AS lecturer_name
              FROM sessions s
              JOIN classes c ON c.class_id = s.class_id
              JOIN users   u ON u.user_id  = s.lecturer_id
             WHERE s.session_id = ? LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Every enrolled student plus their record for this session (NULL = no record yet). */
function session_register(int $sessionId): array
{
    $sql = 'SELECT u.user_id, u.full_name, u.reg_no, u.email,
                   ar.attendance_id, ar.status, ar.timestamp, ar.distance_m,
                   ar.is_edited, ar.edited_at, ar.student_latitude, ar.student_longitude,
                   ed.full_name AS edited_by_name
              FROM sessions s
              JOIN enrollments e ON e.class_id = s.class_id
              JOIN users u       ON u.user_id  = e.student_id
              LEFT JOIN attendance_records ar
                     ON ar.session_id = s.session_id AND ar.student_id = u.user_id
              LEFT JOIN users ed ON ed.user_id = ar.edited_by
             WHERE s.session_id = ?
             ORDER BY u.full_name';

    $stmt = db()->prepare($sql);
    $stmt->execute([$sessionId]);
    return $stmt->fetchAll();
}

/** Per-student attendance percentage for a whole class. */
function class_attendance_summary(int $classId): array
{
    $sql = 'SELECT u.user_id, u.full_name, u.reg_no,
                   COUNT(DISTINCT s.session_id) AS sessions_held,
                   COUNT(DISTINCT CASE WHEN ar.status IN (\'present\',\'late\')
                                       THEN ar.attendance_id END) AS attended,
                   SUM(CASE WHEN ar.status = \'late\'   THEN 1 ELSE 0 END) AS late_count,
                   SUM(CASE WHEN ar.status = \'absent\' THEN 1 ELSE 0 END) AS absent_count
              FROM enrollments e
              JOIN users u ON u.user_id = e.student_id
              LEFT JOIN sessions s ON s.class_id = e.class_id
              LEFT JOIN attendance_records ar
                     ON ar.session_id = s.session_id AND ar.student_id = u.user_id
             WHERE e.class_id = ?
             GROUP BY u.user_id, u.full_name, u.reg_no
             ORDER BY u.full_name';

    $stmt = db()->prepare($sql);
    $stmt->execute([$classId]);
    return $stmt->fetchAll();
}

/* ------------------------- Student ------------------------- */

function student_classes(int $studentId): array
{
    $sql = 'SELECT c.class_id, c.class_code, c.class_name, c.description,
                   u.full_name AS lecturer_name,
                   COUNT(DISTINCT s.session_id) AS sessions_held,
                   COUNT(DISTINCT CASE WHEN ar.status IN (\'present\',\'late\')
                                       THEN ar.attendance_id END) AS attended,
                   COUNT(DISTINCT CASE WHEN ar.status = \'absent\'
                                       THEN ar.attendance_id END) AS absences
              FROM enrollments e
              JOIN classes c      ON c.class_id = e.class_id
              LEFT JOIN users u   ON u.user_id  = c.lecturer_id
              LEFT JOIN sessions s ON s.class_id = c.class_id
              LEFT JOIN attendance_records ar
                     ON ar.session_id = s.session_id AND ar.student_id = e.student_id
             WHERE e.student_id = ?
             GROUP BY c.class_id, c.class_code, c.class_name, c.description, u.full_name
             ORDER BY c.class_code';

    $stmt = db()->prepare($sql);
    $stmt->execute([$studentId]);
    return $stmt->fetchAll();
}

function student_records(int $studentId, ?int $classId = null): array
{
    $sql = 'SELECT ar.*, s.session_date, s.start_time, s.status AS session_status,
                   s.geofence_radius, c.class_code, c.class_name, c.class_id
              FROM attendance_records ar
              JOIN sessions s ON s.session_id = ar.session_id
              JOIN classes  c ON c.class_id   = s.class_id
             WHERE ar.student_id = ?';
    $params = [$studentId];

    if ($classId) {
        $sql .= ' AND c.class_id = ?';
        $params[] = $classId;
    }

    $sql .= ' ORDER BY s.session_date DESC, s.start_time DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** The class whose open session this student may scan right now, if any. */
function open_session_for_student(int $studentId): ?array
{
    $sql = 'SELECT s.session_id, s.session_date, s.start_time, s.status,
                   c.class_code, c.class_name, u.full_name AS lecturer_name
              FROM sessions s
              JOIN classes c     ON c.class_id = s.class_id
              JOIN enrollments e ON e.class_id = s.class_id AND e.student_id = ?
              JOIN users u       ON u.user_id  = s.lecturer_id
             WHERE s.status = \'open\'
             ORDER BY s.session_date DESC, s.start_time DESC
             LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/* ------------------------- School Administrator ------------------------- */

function all_classes(): array
{
    $sql = 'SELECT c.*, lec.full_name AS lecturer_name, adm.full_name AS created_by_name,
                   COUNT(DISTINCT e.student_id) AS student_count,
                   COUNT(DISTINCT s.session_id) AS session_count
              FROM classes c
              LEFT JOIN users lec ON lec.user_id = c.lecturer_id
              LEFT JOIN users adm ON adm.user_id = c.created_by
              LEFT JOIN enrollments e ON e.class_id = c.class_id
              LEFT JOIN sessions s    ON s.class_id = c.class_id
             GROUP BY c.class_id, c.class_code, c.class_name, c.description,
                      c.created_by, c.lecturer_id, c.created_at,
                      lec.full_name, adm.full_name
             ORDER BY c.class_code';

    return db()->query($sql)->fetchAll();
}

function users_by_role(string $role): array
{
    $stmt = db()->prepare(
        'SELECT user_id, full_name, email, reg_no FROM users WHERE role = ? AND is_active = 1 ORDER BY full_name'
    );
    $stmt->execute([$role]);
    return $stmt->fetchAll();
}

function class_enrolled_students(int $classId): array
{
    $stmt = db()->prepare(
        'SELECT u.user_id, u.full_name, u.reg_no, u.email, e.enrolment_id, e.enrolled_at
           FROM enrollments e
           JOIN users u ON u.user_id = e.student_id
          WHERE e.class_id = ?
          ORDER BY u.full_name'
    );
    $stmt->execute([$classId]);
    return $stmt->fetchAll();
}

/* ------------------------- System Administrator ------------------------- */

function system_logs(int $limit = 200, string $search = ''): array
{
    $sql = 'SELECT l.*, u.full_name, u.role
              FROM system_logs l
              LEFT JOIN users u ON u.user_id = l.user_id';
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE l.action LIKE ? OR l.details LIKE ? OR u.full_name LIKE ?';
        $like = '%' . $search . '%';
        $params = [$like, $like, $like];
    }

    $sql .= ' ORDER BY l.log_id DESC LIMIT ' . max(1, min(1000, $limit));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function count_rows(string $table): int
{
    $allowed = ['users', 'classes', 'enrollments', 'sessions', 'qr_codes', 'attendance_records', 'system_logs'];

    if (!in_array($table, $allowed, true)) {
        return 0;
    }

    return (int) db()->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}

/** Run a query that returns a single scalar value. */
function db_value(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
