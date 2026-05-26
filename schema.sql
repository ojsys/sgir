-- SGIR RIGS Feedback System — MySQL Schema
-- Run: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS u902918896_sgir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE u902918896_sgir;

-- ─────────────────────────────────────────────────────────────────────────────
-- Departments
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS departments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    slug       VARCHAR(100) NOT NULL UNIQUE,
    icon       VARCHAR(20)  NOT NULL DEFAULT '💬',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Rig Locations (where the observer is physically located)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS rig_locations (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    code       VARCHAR(50)  NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Feedback
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS feedback (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id     INT UNSIGNED NULL,
    location_id       INT UNSIGNED NULL,
    other_department  VARCHAR(150) NULL,
    rating            TINYINT(1)   NOT NULL DEFAULT 3 COMMENT '1-5',
    category          ENUM('compliment','suggestion','complaint') NOT NULL DEFAULT 'suggestion',
    message           TEXT         NOT NULL,
    is_anonymous      TINYINT(1)   NOT NULL DEFAULT 1,
    submitter_name    VARCHAR(150) NULL,
    email             VARCHAR(255) NULL,
    phone             VARCHAR(30)  NULL,
    status            ENUM('new','reviewed','actioned') NOT NULL DEFAULT 'new',
    admin_notes       TEXT         NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at       DATETIME     NULL,
    CONSTRAINT fk_feedback_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_feedback_loc  FOREIGN KEY (location_id)   REFERENCES rig_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Safety Observations
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS safety_observations (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id      INT UNSIGNED NULL,
    location_id        INT UNSIGNED NULL,
    task_activity      VARCHAR(255) NOT NULL,
    work_area          VARCHAR(255) NOT NULL,
    safety_observation TEXT         NOT NULL,
    observation_status ENUM('open','close') NOT NULL DEFAULT 'open',
    stop_work_authority TINYINT(1)  NOT NULL DEFAULT 0,
    is_safe            TINYINT(1)   NOT NULL DEFAULT 0,
    unsafe_act         TINYINT(1)   NOT NULL DEFAULT 0,
    unsafe_condition   TINYINT(1)   NOT NULL DEFAULT 0,
    near_miss          TINYINT(1)   NOT NULL DEFAULT 0,
    corrective_action  TEXT         NULL,
    further_actions    TEXT         NULL,
    observer_name      VARCHAR(150) NOT NULL,
    observer_company   VARCHAR(150) NULL,
    observation_date   DATE         NOT NULL,
    status             ENUM('new','reviewed','actioned') NOT NULL DEFAULT 'new',
    admin_notes        TEXT         NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at        DATETIME     NULL,
    CONSTRAINT fk_safety_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_safety_loc  FOREIGN KEY (location_id)   REFERENCES rig_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Medical Feedback
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS medical_feedback (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id        INT UNSIGNED NULL,
    location_id          INT UNSIGNED NULL,
    visit_date           DATE         NOT NULL,
    visit_reason         ENUM('injury','illness','routine','medication','emergency','mental_health','other') NOT NULL DEFAULT 'routine',
    visit_reason_other   VARCHAR(255) NULL,
    work_area            VARCHAR(255) NULL,
    is_work_related      TINYINT(1)   NOT NULL DEFAULT 0,
    response_time        ENUM('immediate','quick','acceptable','slow','very_slow') NULL,
    clinic_accessible    ENUM('yes','no') NULL,
    seen_at_reasonable_time ENUM('yes','no') NULL,
    staff_professionalism TINYINT(1)  NULL COMMENT '1-5',
    treatment_explained  ENUM('yes','partially','no') NULL,
    felt_listened_to     ENUM('yes','partially','no') NULL,
    privacy_maintained   ENUM('yes','no') NULL,
    treatment_appropriate ENUM('yes','unsure','no') NULL,
    cleanliness_rating   TINYINT(1)   NULL COMMENT '1-5',
    medications_available ENUM('yes','partially','no') NULL,
    facility_adequacy    TINYINT(1)   NULL COMMENT '1-5',
    followup_instructions ENUM('yes','no','na') NULL,
    referred_for_further_care ENUM('yes','no','not_needed') NULL,
    fit_to_return        ENUM('yes','no','still_on_sick_bay','na') NULL,
    overall_rating       TINYINT(1)   NULL COMMENT '1-5',
    confident_future_use ENUM('yes','maybe','no') NULL,
    urgent_review        TINYINT(1)   NOT NULL DEFAULT 0,
    comments             TEXT         NULL,
    is_anonymous         TINYINT(1)   NOT NULL DEFAULT 1,
    observer_name        VARCHAR(150) NULL,
    observer_company     VARCHAR(150) NULL,
    employee_id          VARCHAR(50)  NULL,
    status               ENUM('new','reviewed','actioned') NOT NULL DEFAULT 'new',
    admin_notes          TEXT         NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at          DATETIME     NULL,
    CONSTRAINT fk_medical_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_medical_loc  FOREIGN KEY (location_id)   REFERENCES rig_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- QR Codes
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS qrcodes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier    VARCHAR(50)  NOT NULL UNIQUE,
    department_id INT UNSIGNED NULL,
    image_path    VARCHAR(500) NOT NULL DEFAULT '',
    scan_count    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_qr_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Admin Settings (singleton row, id=1)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_settings (
    id                  INT UNSIGNED PRIMARY KEY DEFAULT 1,
    notification_emails TEXT         NULL,
    company_name        VARCHAR(150) NOT NULL DEFAULT 'SGIR RIGS',
    company_tagline     VARCHAR(255) NOT NULL DEFAULT 'Feedback Portal',
    logo_path           VARCHAR(500) NULL,
    favicon_path        VARCHAR(500) NULL,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Department Questions
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS department_questions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NULL,
    question_text TEXT         NOT NULL,
    question_type ENUM('text','textarea','radio','checkbox','select','rating') NOT NULL DEFAULT 'text',
    options       TEXT         NULL COMMENT 'JSON array for radio/checkbox/select',
    is_required   TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order    INT          NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dq_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Feedback Answers (answers to department_questions)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS feedback_answers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    answer      TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fa_feedback  FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE,
    CONSTRAINT fk_fa_question  FOREIGN KEY (question_id) REFERENCES department_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Admin Users
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(80)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','supervisor') NOT NULL DEFAULT 'admin',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Seed Data
-- ─────────────────────────────────────────────────────────────────────────────

-- Default admin user (password: sgir@admin2024)
INSERT INTO admin_users (username, password_hash, is_active)
VALUES ('admin', '$2y$10$rwc/6W/0jk1YqyEPcQSyPu/uQ6dkNLgJYs63Glk6wLynOG0rUx.xi', 1)
ON DUPLICATE KEY UPDATE id = id;
-- password: sgir@admin2024 — regenerate with: php -r "echo password_hash('sgir@admin2024', PASSWORD_DEFAULT);"

-- Default admin settings
INSERT INTO admin_settings (id, notification_emails, company_name, company_tagline)
VALUES (1, 'admin@sgir.com', 'SGIR RIGS', 'Oil Rig Feedback Portal')
ON DUPLICATE KEY UPDATE id = id;

-- Departments
INSERT INTO departments (name, slug, icon, is_active, sort_order) VALUES
    ('Safety',        'safety',         '⚠️',  1, 1),
    ('Medical Clinic','medical-clinic', '🏥',  1, 2),
    ('Operations',    'operations',     '⚙️',  1, 3),
    ('Catering',      'catering',       '🍽️', 1, 4),
    ('Accommodation', 'accommodation',  '🏠',  1, 5),
    ('Administration','administration', '📋',  1, 6),
    ('Other',         'other',          '💬',  1, 7)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Rig Locations
INSERT INTO rig_locations (name, code, is_active, sort_order) VALUES
    ('Onshore Base / Yard', 'BASE',   1, 1),
    ('Offshore Platform A', 'PLAT-A', 1, 2),
    ('Offshore Platform B', 'PLAT-B', 1, 3),
    ('Drilling Rig 1',      'RIG-1',  1, 4),
    ('Drilling Rig 2',      'RIG-2',  1, 5);
