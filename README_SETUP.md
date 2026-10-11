# GPS-Enabled QR Code-Based Attendance System for Educational Institutions

**Carson Baraka Bwire** · Reg. No. **158667** · BBIT 4B
Strathmore University — School of Computing and Engineering Sciences

A browser-based attendance system where a lecturer projects a **rotating QR code**, students **scan it with their phone camera**, and the system accepts the scan **only if the student's GPS position is inside the lecture-room geofence**.

---

## 1. What is built

Every function your supervisor listed is implemented and working:

| Actor | Supervisor's function | Where it lives |
|---|---|---|
| **Lecturer** | Log in | `index.php` |
| | View classes assigned | `lecturer/classes.php`, dashboard |
| | Initiate the QR-code | `lecturer/session_start.php` |
| | View the attendance | `lecturer/attendance.php` |
| | Edit attendance | `lecturer/attendance_edit.php` |
| | Export the report | `lecturer/export.php` (3 CSV report types) |
| | Log out | `logout.php` |
| **Student** | Log in | `index.php` |
| | View the registered class | `student/classes.php` |
| | Scan the QR code | `student/scan.php` |
| | View the record | `student/records.php` |
| | Log out | `logout.php` |
| **School Administrator** | Log in | `index.php` |
| | Create class | `admin/class_new.php` |
| | Assign class to a lecturer | `admin/class_edit.php` |
| | Access the class records | `admin/records.php` |
| | Log out | `logout.php` |
| **System Administrator** | Super user | `sysadmin/users.php`, `sysadmin/logs.php`, `sysadmin/health.php` |
| | Add users (create accounts) | `sysadmin/users.php` |
| | Deactivate / reactivate users | `sysadmin/users.php` |
| | Reset a user's password | `sysadmin/users.php` |
| | Troubleshoot the system | `sysadmin/health.php` (10 automated diagnostics) |
| **System** | Initializing the generation of the QR-code | `api/qr_issue.php` |
| | Geolocation mapping | `includes/geolocation.php`, `api/submit_scan.php` |
| | Updating records from scans | `api/submit_scan.php` |

### The eight rules a scan must pass

All applied **on the server**, never in the browser (`api/submit_scan.php`):

1. The caller is a logged-in student
2. The token exists
3. The session is still open
4. The token has not been replaced by a newer rotation
5. The token has not expired (60 s lifetime + 15 s grace)
6. The student is enrolled in that class
7. The student has not already been marked for that session
8. The student's GPS position is inside the geofence (Haversine distance ≤ 50 m)

A rejected scan is written to the audit trail with the reason, which is what makes the System Administrator's "troubleshoot" function real rather than decorative.

### Account management (System Administrator only)

Only the **System Administrator** can add users. There is no self-registration, and the School Administrator cannot create accounts. The reasons:

- **Least privilege** — the School Administrator manages classes and enrolment; the System Administrator manages identity.
- **Accountability** — every account action is written to `system_logs`.
- **Anti-fraud** — nobody can create an account in order to fake attendance.

`sysadmin/users.php` provides:

- **Create user** — full name, e-mail, role, initial password (at least 8 characters). Students also need a unique registration number. E-mail addresses must be unique.
- **Deactivate / reactivate** — a deactivated user cannot log in, and an already logged-in user loses access on the next page request. A System Administrator cannot deactivate their own account. Deactivated lecturers and students no longer appear in the class create/edit pickers. Accounts are deactivated rather than deleted so that attendance history and the audit trail stay intact.
- **Reset password** — the administrator sets a new password for any user.
- **Search and filter** by name, e-mail, registration number or role.

All four actions are protected by CSRF tokens and restricted to the `system_admin` role. Passwords are stored as bcrypt hashes.

---

## 2. Requirements

- **XAMPP 8.2** (Apache + MySQL/MariaDB + PHP 8.1 or newer; tested on PHP 8.4)
- Any modern browser (Chrome recommended)
- No Composer, Node.js or internet connection needed — the QR and camera libraries are bundled in `assets/js/vendor/`

---

## 3. Installation (about 6 minutes)

**Step 1 — copy the project**

Copy the whole `gps_qr_attendance` folder into your XAMPP web root:

```
C:\xampp\htdocs\gps_qr_attendance
```

**Step 2 — start the servers**

Open the XAMPP Control Panel and **Start** both **Apache** and **MySQL**.

**Step 3 — import the database**

Go to <http://localhost/phpmyadmin> → **Import** → **Choose File** → select
`database/gps_qr_attendance.sql` → **Import**.

This creates the database `gps_qr_attendance` with all 7 tables and demonstration data.
(The file starts with `DROP DATABASE IF EXISTS`, so re-importing it resets everything to a clean demonstration state — useful before a defence.)

**Upgrading an existing database** (installed before user management was added): re-importing resets all data, so to keep your data run this once in phpMyAdmin → SQL instead:

```sql
ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER reg_no;
```

**Step 4 — check the installation**

Open <http://localhost/gps_qr_attendance/setup_check.php>

Every row should read **PASS**. That page checks the PHP version, the required extensions, the database connection, all 7 tables and the demonstration accounts.

**Step 5 — log in**

Open <http://localhost/gps_qr_attendance/index.php>

---

## 4. Demonstration accounts

| Role | E-mail | Password |
|---|---|---|
| System Administrator | `sysadmin@strathmore.edu` | `Admin@123` |
| School Administrator | `registrar@strathmore.edu` | `Admin@123` |
| Lecturer | `j.mwangi@strathmore.edu` | `Lecturer@123` |
| Lecturer | `a.otieno@strathmore.edu` | `Lecturer@123` |
| Student (you, 158667) | `carson.bwire@strathmore.edu` | `Student@123` |
| Students (158701, 158745, 158802, 158833) | `amina.hassan@strathmore.edu` etc. | `Student@123` |

Passwords are stored as **bcrypt hashes** (`password_hash()`), never as plain text.

These accounts are demonstration data. New accounts are created by the System Administrator under **Manage Users**.

---

## 5. Six-minute demonstration script (use this in the defence)

**Minute 1 — School Administrator** (`registrar@strathmore.edu`)
Classes → **Create class** → code `BIT 4500`, name `Machine Learning`, assign *Dr. James Mwangi*,
select two students → Create. Then **Manage** the class and change the assigned lecturer.
*Say:* "The administrator owns class creation and lecturer allocation; the lecturer cannot create classes."

**Minute 2 — Lecturer** (`j.mwangi@strathmore.edu`)
**Start Session** → choose `BIT 4205` → leave the date and time → click **Use my current position**
(or **Use campus venue coordinates** if the laptop cannot get a GPS fix indoors) → **Initiate QR code**.
The live screen appears with the QR code and a countdown.
*Say:* "The lecturer's position becomes the centre of the geofence. The code rotates every 60 seconds so a screenshot cannot be forwarded to someone outside the room."

**Minute 3 — Student, inside the geofence** (`carson.bwire@strathmore.edu`)
In a second window (or on a phone), **Scan QR Code** → the token box is filled from the URL, or use the camera.
Click **Mark my attendance**. Green panel: *Attendance recorded — PRESENT*, with the exact distance from the lecturer.
*Point out on the lecturer's screen:* the live list and the counters update within 5 seconds without a page reload.

**Minute 4 — the anti-proxy and geofence rules**
Show, in order, that the system refuses:
- a second scan from the same student → *"already marked PRESENT at 14:02"*
- a token from the previous rotation → *"replaced by a newer one"*
- a scan with coordinates far from the venue → *"You are 2.4 km from the lecture venue…"*

**Minute 5 — Editing and exporting**
**Close session** → the register opens with all enrolled students.
Set one student to *Absent*, one to *Late*, **Save changes** → the row now shows *edited by Dr. James Mwangi on …*
Click **Mark the N student(s) without a scan as absent** for the bulk action.
Then **Export as CSV** (register, summary and matrix) and open one in Excel.

**Minute 6 — System Administrator** (`sysadmin@strathmore.edu`)
**Manage Users** → create a student (name, e-mail, registration number, initial password) → **Deactivate** the account and show that logging in as that user is refused → **Reactivate** it.
*Say:* "Only the System Administrator creates accounts, so nobody can register a fake student to obtain attendance."
**Troubleshoot** → walk through the 10 diagnostics (all green), then show **System Logs** and filter for
`geofence` — every rejection is recorded with the student, the reason and the IP. Filter for `user_` to show the account actions.
*Say:* "The audit trail is what makes the System Administrator's troubleshooting function meaningful."

---

## 6. IMPORTANT: the camera and GPS need a secure connection

This is the one thing that trips people up, so read it before you demo.

Chrome and Safari only expose the **camera** and the **GPS position** on a *secure context*:
`https://` or `http://localhost`. On a plain `http://192.168.x.x` address **both are blocked**.

The application detects this and shows a warning banner with a manual fallback. You have two options:

**Option A — present from the laptop (simplest, always works)**
Open `http://localhost/gps_qr_attendance`. `localhost` counts as secure, so the camera and geolocation both work.
Use the laptop webcam to scan the QR code shown on the same screen, or open the student account in a second window and use the token box.

**Option B — real phones over HTTPS (best for the demo)**
1. Find the laptop's IP address: open Command Prompt and run `ipconfig` (look for *IPv4 Address*, e.g. `192.168.1.5`).
2. Make sure Apache is listening on 443 (XAMPP enables SSL by default; if `https://localhost` does not open, uncomment `Include conf/extra/httpd-ssl.conf` in `C:\xampp\apache\conf\httpd.conf` and restart Apache).
3. Open the lecturer pages through the same HTTPS address (`https://192.168.1.5/gps_qr_attendance`), **not** `localhost`. The application builds the QR code from the address you browse with, so this is enough. Optionally, pin it in `config/config.php`:
   ```php
   define('BASE_URL_OVERRIDE', 'https://192.168.1.5/gps_qr_attendance');
   ```
   Leave it empty if the laptop's IP changes between sessions (DHCP).
4. Allow Apache through the Windows Firewall for **private** networks.
5. On each phone: open `https://192.168.1.5/gps_qr_attendance`, accept the self-signed certificate warning
   (*Advanced → Proceed*), log in as a student, allow the camera and location prompts, and scan.

If **Use my current position** reports *permission was denied*, allow Location for the site in the browser's site settings and make sure Windows **Location services** (including *Let desktop apps access your location*) are on. As a fallback use **Use campus venue coordinates** or type coordinates in by hand.

**Why the QR code contains the address above:** the QR encodes
`{BASE_URL}/student/scan.php?token=…`. If the QR was generated from `localhost`, a phone cannot open it;
if it was generated from the laptop's IP, every phone on the same Wi-Fi can. Setting `BASE_URL_OVERRIDE`
makes this deterministic.

**If GPS is weak indoors** (very common on a laptop): the geofence centre comes from the lecturer's browser.
Use **Use campus venue coordinates** on the Start Session page, which fills in
`-1.3095964, 36.8125324` (Strathmore University, Ole Sangale Road).

---

## 7. Corrections to make to the schema diagram

The code is the source of truth and it differs from the schema figure in five small ways.
Update the **Figure 4.3 database schema** diagram accordingly, or ask me to regenerate it:

| Table | Change |
|---|---|
| `USER` | **add** `reg_no VARCHAR(30) NULL` — students are identified by registration number |
| `USER` | **add** `is_active TINYINT(1) NOT NULL DEFAULT 1` — set to 0 when the System Administrator deactivates an account |
| `SESSION` | **add** `created_at DATETIME` |
| `ATTENDANCE_RECORD` | **add** `distance_m DECIMAL(8,2)` — the metres from the reference point, shown to the student and kept as evidence |
| `ATTENDANCE_RECORD` | **change** `status` from `ENUM('present','edited')` to **`ENUM('present','absent','late')`** — "edited" was never a status; `is_edited` already records that |

The CHEN ERD needs no change.

---

## 8. Project structure

```
gps_qr_attendance/
├── index.php                  login
├── logout.php
├── dashboard.php              role-aware landing page (4 dashboards in one file)
├── setup_check.php            installation checker  (delete before submitting)
├── config/
│   ├── config.php             app settings, TTLs, geofence defaults
│   └── database.php           PDO connection (also aligns the MySQL clock with PHP)
├── includes/
│   ├── bootstrap.php          single entry point for every page
│   ├── functions.php          helpers: escaping, CSRF, flash, audit log, formatting,
│   │                          role checks (require_role also blocks deactivated users)
│   ├── auth.php               login / logout (refuses deactivated accounts)
│   ├── geolocation.php        Haversine + geofence check
│   ├── queries.php            every domain query
│   ├── header.php / footer.php  shared layout and role-based navigation
├── lecturer/                  classes, session_start, session_live, attendance,
│                              attendance_edit, export
├── student/                   classes, scan, records
├── admin/                     classes, class_new, class_edit, records
├── sysadmin/                  users (create / deactivate / reset password), logs,
│                              health (troubleshooting)
├── api/
│   ├── qr_issue.php           issues/rotates the token (lecturer only)
│   ├── qr_status.php          live feed for the lecturer's screen
│   └── submit_scan.php        the 8-rule validation and record insert
├── assets/
│   ├── css/style.css
│   └── js/vendor/             qrcode.min.js, html5-qrcode.min.js (bundled, works offline)
└── database/
    └── gps_qr_attendance.sql  schema + demonstration data
```

---

## 9. What has been verified

The system was installed on a clean MySQL/MariaDB server and driven through a **real browser-style HTTP session**.
**81 automated checks pass, 0 fail, and no PHP warning or notice is produced anywhere.**

Included in that run:

- the SQL file imports cleanly, creating 7 tables with all foreign keys
- login succeeds with the correct password and is refused with a wrong one
- a request without a CSRF token is rejected (HTTP 419)
- a lecturer is refused (HTTP 403) on the school-administrator and system-administrator pages
- an anonymous visitor is redirected to the login page
- a class is created and stored, with its two students enrolled
- a session is created and an out-of-range geofence radius is rejected
- the QR token rotates, and the previous token is deactivated in the database
- an in-geofence scan is accepted, marked **present**, and stores the distance (2.20 m)
- the same student scanning again is told they are already marked — and only **one** row exists in the database
- a scan from 2.4 km away is rejected and reports the distance; the student is **not** recorded
- a scan without GPS coordinates is rejected
- a scan using a replaced token is rejected
- an unknown token is rejected
- closing the session deactivates every token, and further scans are refused with *"closed by the lecturer"*
- manual attendance edits are stored with `is_edited = 1`, `edited_by` and `edited_at`
- the bulk "mark remaining as absent" action works
- all three CSV exports download with the correct headers
- a lecturer cannot export a class they do not teach
- the audit trail contains login, attendance, rejection, edit and export events, and is searchable
- the troubleshooting page reports 0 failing and 0 warning checks
- logout works and the protected pages become inaccessible

The user-management feature was added afterwards and was checked separately over HTTP against the local database (it is **not** part of the 81 automated checks above):

- the Manage Users page loads for the System Administrator
- a new student account is created and stored, and a `user_created` entry appears in the audit trail
- deactivating the account sets `is_active = 0`, and a login attempt is refused with *"This account has been deactivated"*

Reactivation, password reset and the immediate logout of an already signed-in user after deactivation have not been exercised automatically — try them once before your defence.

---

## 10. Before you submit or demo

1. In `config/config.php` set `define('DEBUG_MODE', false);`
2. **Delete or rename `setup_check.php`**
3. Change the demonstration passwords (`users` table) or note them as demonstration-only in your report
4. Take your **Chapter 4 screenshots** while `DEBUG_MODE` is still on if you prefer visible errors, then turn it off
5. Keep `database/gps_qr_attendance.sql` in the appendix as evidence of the physical design

## 11. Troubleshooting

| Problem | Cause and fix |
|---|---|
| *Database connection failed* | MySQL is not started in XAMPP, or the SQL file was not imported |
| *Access denied for user 'root'* | Your MySQL root password is not empty — set `DB_PASS` in `config/config.php` |
| The QR code does not appear | Check that `assets/js/vendor/qrcode.min.js` exists; open the browser console (F12) |
| Camera button is disabled | You are on `http://` and not `localhost` — see section 6 |
| *Your location could not be read* | Location permission denied, or you are on a non-secure origin — see section 6 |
| Every scan says the code expired | Your PHP and MySQL clocks disagree — `config/database.php` already syncs them, so re-import `config/database.php` |
| The QR code opens on the laptop but not on a phone | The QR contains `localhost` — set `BASE_URL_OVERRIDE`, see section 6 |
| Students are marked *late* unexpectedly | A scan more than 15 minutes after the session start time is late; change `LATE_AFTER_MINUTES` in `config/config.php` |
| *Unknown column 'is_active'* after pulling the latest code | Your database predates user management — run the `ALTER TABLE` statement in section 3 |
| *This account has been deactivated* at login | The System Administrator deactivated it — reactivate it under **Manage Users** |
| A deactivated lecturer or student is missing from the class pickers | Intended: only active users are offered. Reactivate the account to list them again |

## 12. Source control

The project is hosted at <https://github.com/CarsonBaraka04/gps_qr_attendance> (branch `main`). The repository root is this folder, not `C:\xampp\htdocs`. After making changes run `git add .`, `git commit -m "message"` and `git push` from `C:\xampp\htdocs\gps_qr_attendance`.
