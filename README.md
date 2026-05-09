# SGIR RIGS Feedback System — PHP Edition

A self-contained PHP feedback portal for SGIR RIGS Oil Rig Company, Nigeria.
Supports general feedback, safety observations, and medical feedback with an admin dashboard,
department-specific custom questions, QR code generation, and CSV/Excel export.

---

## Local Development (SQLite)

### Requirements
- PHP 8.0+
- PHP extensions: `pdo`, `pdo_sqlite`, `gd`, `mbstring`, `json`

### Quick Start

```bash
# 1. Enter the project directory
cd sgir_php

# 2. (Optional) Verify / edit the local .env — defaults work out of the box
cat .env

# 3. Initialise the SQLite database (creates database.sqlite with seed data)
php setup.php
# — OR —
sqlite3 database.sqlite < schema_sqlite.sql

# 4. Start the built-in PHP dev server
php -S localhost:8080

# 5. Open in your browser
#    Public portal:  http://localhost:8080/
#    Admin login:    http://localhost:8080/dashboard/login.php
#    Credentials:    admin / sgir@admin2024
```

> The `.env` file controls which database driver is used.
> `DB_DRIVER=sqlite` → uses `database.sqlite` (default for local dev).
> `DB_DRIVER=mysql`  → connects to MySQL using the `DB_*` credentials.

---

## Production Deployment — cPanel Shared Hosting

### Prerequisites
- A cPanel hosting account with PHP 8.0+ and MySQL
- Access to **File Manager** or FTP/SFTP
- Access to **MySQL Databases** in cPanel

---

### Step 1 — Create the MySQL Database

1. Log in to **cPanel** → **MySQL Databases**.
2. Under **Create New Database**, enter a name (e.g. `sgir_feedback`) and click **Create Database**.
   cPanel will prefix it with your account username automatically, e.g. `cpanelusername_sgir_feedback`.
3. Under **MySQL Users → Add New User**, create a user with a strong password.
   Note the generated username (e.g. `cpanelusername_dbuser`).
4. Under **Add User to Database**, select the user and database, then grant **ALL PRIVILEGES**.

---

### Step 2 — Import the Schema

1. In cPanel → **phpMyAdmin**, select your new database from the left panel.
2. Click the **Import** tab.
3. Click **Choose File** and select `schema.sql` from this project.
4. Click **Go**. All tables and seed data will be created.

> **Note:** Import `schema.sql` (MySQL). Do **not** import `schema_sqlite.sql` — that is for local development only.

---

### Step 3 — Upload the Files

Upload the contents of `sgir_php/` to your server. **Exclude** these local-only files — they must not be on the production server:

| Exclude | Reason |
|---|---|
| `database.sqlite` | Local SQLite database — not used in production |
| `setup.php` | Local setup script — security risk if exposed |
| `schema_sqlite.sql` | SQLite schema — not needed in production |
| `.env` | You will create this fresh on the server in Step 4 |

**Expected directory structure on the server:**

```
public_html/          ← or a subdirectory, e.g. public_html/feedback/
├── .htaccess         ← already included; protects .env and sensitive files
├── index.php
├── assets/
├── config/
├── dashboard/
├── feedback/
├── includes/
├── lib/
├── schema.sql
├── uploads/          ← must be writable (chmod 755) — see Step 5
└── .env_example
```

**Option A — File Manager (cPanel)**

1. In cPanel → **File Manager**, navigate to `public_html/` (or your chosen subdirectory).
2. Zip the contents of `sgir_php/` locally (excluding the files listed above).
3. Upload the `.zip`, then right-click → **Extract** into the target directory.

**Option B — FTP/SFTP**

Use FileZilla or any SFTP client with your cPanel FTP credentials.
Upload the contents of `sgir_php/` (excluding the files above) to `public_html/`.

---

### Step 4 — Create the Production `.env`

1. In File Manager, navigate to your upload directory.
2. Click **+ File**, name it `.env`.
3. Right-click → **Edit** and paste the following, filling in your values:

```ini
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=cpanelusername_sgir_feedback
DB_USER=cpanelusername_dbuser
DB_PASSWORD=your_strong_password_here

COMPANY_NAME="SGIR RIGS"
COMPANY_TAGLINE="Oil Rig Feedback Portal"

MAIL_FROM="noreply@yourdomain.com"
NOTIFICATION_EMAILS="admin@yourdomain.com"
```

> Replace `cpanelusername_sgir_feedback`, `cpanelusername_dbuser`, and `DB_PASSWORD`
> with the exact values you set in Step 1. The `DB_HOST` is almost always `localhost` on shared hosting.

Use `.env_example` (included in the project) as a reference template.

---

### Step 5 — Set Folder Permissions

The `uploads/` directory must be writable by the web server:

1. In File Manager, right-click `uploads/` → **Change Permissions** → set to **755**.

> If file uploads (logo, favicon, QR codes) still fail after setting 755, try **775**.
> Avoid **777** on shared hosting.
>
> You do **not** need to create any subdirectories inside `uploads/` — the app creates them automatically on first use.

---

### Step 6 — Configure PHP Version

1. In cPanel → **MultiPHP Manager** (or **Select PHP Version**).
2. Set PHP to **8.0** or higher for your domain / subdirectory.
3. Ensure these extensions are **enabled**:

| Extension | Required for |
|---|---|
| `pdo` | Database abstraction |
| `pdo_mysql` | MySQL connection |
| `gd` | QR code image generation |
| `mbstring` | Unicode / multi-byte string handling |
| `json` | API responses and question options |
| `fileinfo` | Upload MIME type validation |

> `pdo_sqlite` is **not** needed on the production server.

---

### Step 7 — Verify `.htaccess` Protection

The project ships with a `.htaccess` file that handles security automatically. Confirm it was uploaded and contains at minimum:

```apache
# Block access to sensitive files
<FilesMatch "^\.env(\.example)?$|^setup\.php$|^schema.*\.sql$|^database\.sqlite$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Disable directory listing
Options -Indexes
```

If your host uses Nginx instead of Apache, apply equivalent rules in your server config or ask your host to block direct access to `.env` and `*.sql` files.

---

### Step 8 — Test the Deployment

1. Visit your domain (e.g. `https://yourdomain.com/` or `https://yourdomain.com/feedback/`).
2. Confirm the feedback portal loads and all departments are visible.
3. Submit a test feedback entry and confirm it appears in the dashboard.
4. Log in to the admin dashboard: `https://yourdomain.com/dashboard/login.php`
   Default credentials: `admin` / `sgir@admin2024`
5. Navigate to **Dashboard → Questions** and add a test question for a department.
6. Submit feedback for that department and confirm the custom question appears in the form.
7. Verify the answer appears in the feedback detail view under "Additional Questions".
8. Navigate to **Dashboard → Users**, create a supervisor account, then log in as that supervisor and confirm they can only see Overview and Feedback — not Settings, Questions, Export, QR Codes, or Users.
9. Test logo upload via **Dashboard → Settings → Site Branding**.
10. **Change the admin password immediately** — see Step 9.

---

### Step 9 — Change the Default Admin Password

**Via the dashboard** (recommended):
Dashboard → Users → click ✏️ next to `admin` → use the **Reset Password** form at the bottom of the edit panel.

**Via phpMyAdmin** (alternative):

```sql
-- Generate hash first: php -r "echo password_hash('newpassword', PASSWORD_DEFAULT);"
UPDATE admin_users
SET password_hash = '$2y$10$YOUR_GENERATED_HASH_HERE'
WHERE username = 'admin';
```

---

## Subdirectory Deployment

If the app is not at the domain root (e.g. at `yourdomain.com/feedback/`), the `BASE_URL` constant is auto-detected from `$_SERVER['DOCUMENT_ROOT']` and `$_SERVER['SCRIPT_NAME']` in `config/app.php`. No manual configuration is needed as long as the files are uploaded correctly.

---

## File Reference

| File / Folder            | Purpose                                                        |
|--------------------------|----------------------------------------------------------------|
| `index.php`              | Public landing page (department selector)                      |
| `feedback/`              | Public-facing forms (general, safety, medical) + submit handlers |
| `dashboard/overview.php` | Admin dashboard home — stats and charts                        |
| `dashboard/feedback.php` | Paginated feedback list with filters                           |
| `dashboard/feedback-detail.php` | View / update a single feedback entry + custom answers  |
| `dashboard/questions.php`| Manage custom questions per department (add / edit / delete)   |
| `dashboard/export.php`   | CSV / Excel export of feedback                                 |
| `dashboard/qr-codes.php` | Generate and manage department QR codes                        |
| `dashboard/users.php`    | User management — add/edit/delete admins and supervisors       |
| `dashboard/settings.php` | Site branding, departments, notification emails, password      |
| `config/app.php`         | Bootstrap, .env loader, constants                              |
| `config/database.php`    | PDO connection (MySQL + SQLite)                                |
| `includes/auth.php`      | Session management, login/logout, CSRF helpers                 |
| `includes/helpers.php`   | h(), json_response(), format_date(), pagination, filters       |
| `includes/email.php`     | HTML email notification on new feedback                        |
| `includes/sidebar.php`   | Dashboard sidebar navigation partial                           |
| `lib/qrcode.php`         | QR code generation via external API                            |
| `assets/`                | CSS, JS, images                                                |
| `uploads/`               | User-uploaded files (must be writable, chmod 755); subdirectories are created automatically |
| `schema.sql`             | MySQL schema + seed data — **import this on production**       |
| `schema_sqlite.sql`      | SQLite schema + seed data — local dev only                     |
| `setup.php`              | CLI script to initialise SQLite database — local dev only      |
| `.env`                   | Environment config (never commit, create fresh on server)      |
| `.env_example`           | Production `.env` template with all available keys             |
| `.htaccess`              | Blocks access to `.env`, `*.sql`, `setup.php`; disables listing |

---

## Default Credentials

| Role  | Username | Password         |
|-------|----------|------------------|
| Admin | `admin`  | `sgir@admin2024` |

**Change this password immediately after first login.**
