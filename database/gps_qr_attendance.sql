-- Active: 1720436119590@@127.0.0.1@3306@mysql
-- =====================================================================
--  GPS-Enabled QR Code-Based Attendance System for Educational Institutions
--  MySQL 8 / MariaDB physical schema + demonstration data
--
--  Author : Carson Baraka Bwire | Reg. No. 158667 | BBIT 4B
--  School : Strathmore University - School of Computing and Engineering Sciences
--
--  Import with phpMyAdmin (Import tab) or:
--    mysql -u root -p < gps_qr_attendance.sql
--
--  NOTE ON NAMING
--  The conceptual ERD and schema diagrams use UPPERCASE entity names
--  (USER, CLASS, SESSION ...). The physical MySQL tables use lowercase
--  plural names (users, classes, sessions ...) which is the normal coding
--  convention and avoids conflicts with reserved SQL words.
-- =====================================================================

DROP DATABASE IF EXISTS `gps_qr_attendance`;
CREATE DATABASE `gps_qr_attendance`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE `gps_qr_attendance`;

-- ---------------------------------------------------------------------
-- 1. users  (one table for every human actor; `role` controls access)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
    `user_id`       INT             NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(100)    NOT NULL,
    `email`         VARCHAR(120)    NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL,
    `role`          ENUM('lecturer','student','school_admin','system_admin') NOT NULL,
    `reg_no`        VARCHAR(30)     DEFAULT NULL COMMENT 'Registration number for students',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '0 = deactivated, cannot log in',
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_reg_no` (`reg_no`),
    KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. classes  (created by a school administrator, taught by a lecturer)
--    Two foreign keys point at users: created_by and lecturer_id
-- ---------------------------------------------------------------------
CREATE TABLE `classes` (
    `class_id`    INT             NOT NULL AUTO_INCREMENT,
    `class_code`  VARCHAR(20)     NOT NULL,
    `class_name`  VARCHAR(120)    NOT NULL,
    `description` TEXT            DEFAULT NULL,
    `created_by`  INT             NOT NULL,
    `lecturer_id` INT             DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`class_id`),
    UNIQUE KEY `uq_classes_code` (`class_code`),
    KEY `idx_classes_lecturer` (`lecturer_id`),
    KEY `idx_classes_created_by` (`created_by`),
    CONSTRAINT `fk_classes_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_classes_lecturer` FOREIGN KEY (`lecturer_id`)
        REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. enrollments  (many-to-many bridge: a student belongs to a class)
-- ---------------------------------------------------------------------
CREATE TABLE `enrollments` (
    `enrolment_id` INT      NOT NULL AUTO_INCREMENT,
    `class_id`     INT      NOT NULL,
    `student_id`   INT      NOT NULL,
    `enrolled_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`enrolment_id`),
    UNIQUE KEY `uq_enrolment` (`class_id`, `student_id`),
    KEY `idx_enrollments_student` (`student_id`),
    CONSTRAINT `fk_enrollments_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`class_id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_enrollments_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. sessions  (one lecture meeting; holds the reference GPS point and
--    the geofence radius used to validate student scans)
-- ---------------------------------------------------------------------
CREATE TABLE `sessions` (
    `session_id`      INT            NOT NULL AUTO_INCREMENT,
    `class_id`        INT            NOT NULL,
    `lecturer_id`     INT            NOT NULL,
    `session_date`    DATE           NOT NULL,
    `start_time`      TIME           NOT NULL,
    `ref_latitude`    DECIMAL(10,7)  NOT NULL,
    `ref_longitude`   DECIMAL(10,7)  NOT NULL,
    `geofence_radius` INT            NOT NULL DEFAULT 50 COMMENT 'Radius in metres',
    `status`          ENUM('open','closed') NOT NULL DEFAULT 'open',
    `created_at`      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`session_id`),
    KEY `idx_sessions_class` (`class_id`),
    KEY `idx_sessions_lecturer` (`lecturer_id`),
    KEY `idx_sessions_status` (`status`),
    CONSTRAINT `fk_sessions_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`class_id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_sessions_lecturer` FOREIGN KEY (`lecturer_id`)
        REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. qr_codes  (time-limited tokens; rotated so a screenshot cannot be
--    forwarded to an absent classmate -> anti-proxy measure)
-- ---------------------------------------------------------------------
CREATE TABLE `qr_codes` (
    `qr_id`       INT          NOT NULL AUTO_INCREMENT,
    `session_id`  INT          NOT NULL,
    `token`       VARCHAR(255) NOT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expiry_time` DATETIME     NOT NULL,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`qr_id`),
    UNIQUE KEY `uq_qr_token` (`token`),
    KEY `idx_qr_session` (`session_id`),
    CONSTRAINT `fk_qr_session` FOREIGN KEY (`session_id`)
        REFERENCES `sessions` (`session_id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. attendance_records  (one row per student per session; stores the
--    student's GPS position at scan time plus the edit audit trail)
-- ---------------------------------------------------------------------
CREATE TABLE `attendance_records` (
    `attendance_id`     INT           NOT NULL AUTO_INCREMENT,
    `session_id`        INT           NOT NULL,
    `student_id`        INT           NOT NULL,
    `timestamp`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `student_latitude`  DECIMAL(10,7) DEFAULT NULL,
    `student_longitude` DECIMAL(10,7) DEFAULT NULL,
    `distance_m`        DECIMAL(8,2)  DEFAULT NULL COMMENT 'Metres from the reference point',
    `status`            ENUM('present','absent','late') NOT NULL DEFAULT 'present',
    `is_edited`         TINYINT(1)    NOT NULL DEFAULT 0,
    `edited_by`         INT           DEFAULT NULL,
    `edited_at`         DATETIME      DEFAULT NULL,
    PRIMARY KEY (`attendance_id`),
    UNIQUE KEY `uq_attendance` (`session_id`, `student_id`),
    KEY `idx_attendance_student` (`student_id`),
    KEY `idx_attendance_status` (`status`),
    CONSTRAINT `fk_attendance_session` FOREIGN KEY (`session_id`)
        REFERENCES `sessions` (`session_id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`)
        REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_edited_by` FOREIGN KEY (`edited_by`)
        REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. system_logs  (audit trail used by the System Administrator to
--    troubleshoot the system)
-- ---------------------------------------------------------------------
CREATE TABLE `system_logs` (
    `log_id`     INT          NOT NULL AUTO_INCREMENT,
    `user_id`    INT          DEFAULT NULL,
    `action`     VARCHAR(255) NOT NULL,
    `timestamp`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `details`    TEXT         DEFAULT NULL,
    `ip_address` VARCHAR(45)  DEFAULT NULL,
    PRIMARY KEY (`log_id`),
    KEY `idx_logs_user` (`user_id`),
    KEY `idx_logs_time` (`timestamp`),
    CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`user_id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  DEMONSTRATION DATA
--  Passwords (change them after your first login):
--    sysadmin@strathmore.edu   / Admin@123     (System Administrator)
--    registrar@strathmore.edu  / Admin@123     (School Administrator)
--    j.mwangi@strathmore.edu   / Lecturer@123  (Lecturer)
--    a.otieno@strathmore.edu   / Lecturer@123  (Lecturer)
--    carson.bwire@strathmore.edu / Student@123 (Student, reg. 158667)
--    other students            / Student@123
-- =====================================================================
INSERT INTO `users` (`full_name`, `email`, `password_hash`, `role`, `reg_no`) VALUES
('System Administrator', 'sysadmin@strathmore.edu', '$2b$10$asMiqpzRtDSExKCyA/FnTOGQYTFnqqj4zZp8zj1icD4o0VtwHo/x6', 'system_admin', NULL),
('Grace Wanjiku', 'registrar@strathmore.edu', '$2b$10$MoOlo1Nz4qHlDrewXrF6QeGitdfC0.9wy.Zm1qtvnNTKY9poKAFJe', 'school_admin', NULL),
('Dr. James Mwangi', 'j.mwangi@strathmore.edu', '$2b$10$cCo3sHhoQK9EAdSPaN9hVOZ2TZiWWRjq7MHZLS3Tz/IG3uu6PlZGi', 'lecturer', NULL),
('Ms. Alice Otieno', 'a.otieno@strathmore.edu', '$2b$10$KI2SopTi86BUBzvBNHyHd.zG6zZg3FwBizrGLYJvyjK3ib2jKY/A2', 'lecturer', NULL),
('Carson Baraka Bwire', 'carson.bwire@strathmore.edu', '$2b$10$YerD87pwyaO4ZG6hGDOSoeWZWIavVl2o3k0NEcefgplzjtkCzjyWq', 'student', '158667'),
('Amina Hassan', 'amina.hassan@strathmore.edu', '$2b$10$serIetai9.ZTrF1qb75cCOhEqFGcm6uyGCPGDWeYWHZ12RSwlzdQ6', 'student', '158701'),
('Brian Kimani', 'brian.kimani@strathmore.edu', '$2b$10$yxht0FHo/.TR8pW3WD.y/efnqAZPPpCTWFpyy3t.zh6cpCdp2POLq', 'student', '158745'),
('Diana Chebet', 'diana.chebet@strathmore.edu', '$2b$10$w7LYe3117mA4R6qLAkXBfe8NS44/9Xcw9k3sHxeSlJSi6PdEqUkJa', 'student', '158802'),
('Emmanuel Ochieng', 'emmanuel.ochieng@strathmore.edu', '$2b$10$PzWET4nLqyD/mfyMVCmMe.7V9pTQgDGiH74wSaKZQvjjg3wtGTh2e', 'student', '158833');

INSERT INTO `classes` (`class_id`, `class_code`, `class_name`, `description`, `created_by`, `lecturer_id`) VALUES
(1, 'BIT 4205', 'Information Systems Project', 'Final year project supervision class.', 2, 3),
(2, 'BIT 4302', 'Business Intelligence',       'Data warehousing and analytics.',       2, 4),
(3, 'BIT 4401', 'Information Security',        'Security principles and controls.',     2, 3);

INSERT INTO `enrollments` (`class_id`, `student_id`) VALUES
(1, 5), (1, 6), (1, 7), (1, 8), (1, 9),
(2, 5), (2, 7), (2, 8),
(3, 6), (3, 8);

-- Reference coordinates: Strathmore University, Ole Sangale Road, Nairobi
INSERT INTO `sessions`
    (`session_id`, `class_id`, `lecturer_id`, `session_date`, `start_time`,
     `ref_latitude`, `ref_longitude`, `geofence_radius`, `status`, `created_at`) VALUES
(1, 1, 3, '2026-09-15', '14:00:00', -1.3095964, 36.8125324, 50, 'closed', '2026-09-15 13:58:00'),
(2, 1, 3, '2026-09-17', '14:00:00', -1.3095964, 36.8125324, 50, 'closed', '2026-09-17 13:57:00'),
(3, 2, 4, '2026-09-16', '09:00:00', -1.3096420, 36.8124901, 50, 'closed', '2026-09-16 08:58:00');

INSERT INTO `qr_codes` (`session_id`, `token`, `created_at`, `expiry_time`, `is_active`) VALUES
(1, 'DEMO-TOKEN-SESSION-1-CLOSED', '2026-09-15 14:00:05', '2026-09-15 14:01:05', 0),
(2, 'DEMO-TOKEN-SESSION-2-CLOSED', '2026-09-17 14:00:03', '2026-09-17 14:01:03', 0);

INSERT INTO `attendance_records`
    (`session_id`, `student_id`, `timestamp`, `student_latitude`, `student_longitude`, `distance_m`, `status`) VALUES
-- session 1 : 4 of 5 students scanned inside the geofence
(1, 5, '2026-09-15 14:01:12', -1.3096100, 36.8125180, 2.28, 'present'),
(1, 6, '2026-09-15 14:02:40', -1.3097210, 36.8126002, 15.60, 'present'),
(1, 7, '2026-09-15 14:03:05', -1.3094820, 36.8124470, 15.75, 'present'),
(1, 8, '2026-09-15 14:19:30', -1.3096001, 36.8125300, 0.48, 'late'),
-- session 2 : 3 students
(2, 5, '2026-09-17 14:00:58', -1.3095990, 36.8125290, 0.42, 'present'),
(2, 6, '2026-09-17 14:01:33', -1.3096700, 36.8125800, 9.28, 'present'),
(2, 8, '2026-09-17 14:02:10', -1.3095210, 36.8124900, 9.71, 'present'),
-- session 3 : one edited record (lecturer corrected a manual mark)
(3, 7, '2026-09-16 09:02:14', -1.3096500, 36.8124950, 1.00, 'present'),
(3, 8, '2026-09-16 09:03:40', -1.3096320, 36.8124800, 1.52, 'present');

INSERT INTO `system_logs` (`user_id`, `action`, `timestamp`, `details`, `ip_address`) VALUES
(3, 'session_created',       '2026-09-15 13:58:00', 'Created session 1 for class BIT 4205', '192.168.1.20'),
(3, 'qr_token_issued',       '2026-09-15 14:00:05', 'QR token issued for session 1', '192.168.1.20'),
(5, 'attendance_submitted',  '2026-09-15 14:01:12', 'Attendance recorded for session 1 (2.3 m from reference point)', '192.168.1.44'),
(6, 'attendance_submitted',  '2026-09-15 14:02:40', 'Attendance recorded for session 1 (15.6 m from reference point)', '192.168.1.51'),
(7, 'attendance_submitted',  '2026-09-15 14:03:05', 'Attendance recorded for session 1 (15.8 m from reference point)', '192.168.1.63'),
(8, 'attendance_submitted',  '2026-09-15 14:19:30', 'Attendance recorded for session 1 as LATE (0.5 m from reference point)', '192.168.1.72'),
(3, 'session_closed',        '2026-09-15 15:00:00', 'Session 1 closed', '192.168.1.20'),
(3, 'session_created',       '2026-09-17 13:57:00', 'Created session 2 for class BIT 4205', '192.168.1.20'),
(4, 'session_created',       '2026-09-16 08:58:00', 'Created session 3 for class BIT 4302', '192.168.1.21'),
(4, 'attendance_edited',     '2026-09-16 09:20:00', 'Marked student 7 as PRESENT (was absent) in session 3', '192.168.1.21'),
(1, 'system_check',          '2026-09-18 08:00:00', 'Weekly system check completed - all tables healthy', '192.168.1.10');
