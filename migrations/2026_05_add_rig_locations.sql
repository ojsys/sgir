-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Rig Locations (May 2026)
-- ─────────────────────────────────────────────────────────────────────────────
-- Adds the rig_locations table and a location_id column to feedback,
-- safety_observations and medical_feedback so every submission records where
-- the observer was based.
--
-- WHEN TO RUN: only on an EXISTING MySQL database that was created before this
-- feature. Fresh installs already get everything from schema.sql.
-- Local SQLite dev databases are rebuilt by `php setup.php` and need nothing.
--
-- Usage (MySQL):  mysql -u <user> -p <database> < migrations/2026_05_add_rig_locations.sql
-- Safe to run once. Re-running will error on duplicate column/table — that is
-- expected and means the migration has already been applied.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rig_locations (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    code       VARCHAR(50)  NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE feedback
    ADD COLUMN location_id INT UNSIGNED NULL AFTER department_id,
    ADD CONSTRAINT fk_feedback_loc FOREIGN KEY (location_id) REFERENCES rig_locations(id) ON DELETE SET NULL;

ALTER TABLE safety_observations
    ADD COLUMN location_id INT UNSIGNED NULL AFTER department_id,
    ADD CONSTRAINT fk_safety_loc FOREIGN KEY (location_id) REFERENCES rig_locations(id) ON DELETE SET NULL;

ALTER TABLE medical_feedback
    ADD COLUMN location_id INT UNSIGNED NULL AFTER department_id,
    ADD CONSTRAINT fk_medical_loc FOREIGN KEY (location_id) REFERENCES rig_locations(id) ON DELETE SET NULL;

-- Starter locations (edit/rename/extend from the admin dashboard → Rig Locations)
INSERT INTO rig_locations (name, code, is_active, sort_order) VALUES
    ('Onshore Base / Yard', 'BASE',   1, 1),
    ('Offshore Platform A', 'PLAT-A', 1, 2),
    ('Offshore Platform B', 'PLAT-B', 1, 3),
    ('Drilling Rig 1',      'RIG-1',  1, 4),
    ('Drilling Rig 2',      'RIG-2',  1, 5);
