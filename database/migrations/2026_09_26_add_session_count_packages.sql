-- ============================================================================
-- Migration: session-count packages (N-session packages as a Duration Options
--            extension)
-- File: database/migrations/2026_09_26_add_session_count_packages.sql
--
-- Supersedes the abandoned draft in
-- 2026_09_25_add_multi_session_package_support.sql (services.session_count,
-- standalone service_sessions table, appointments.promo_percent — none of
-- that ever shipped and has since been fully removed from the PHP). This is
-- the real, final design: session_count lives on service_durations itself,
-- so a single service can offer both single-session and multi-session
-- variants side by side (e.g. "60 min" vs "60 min x 3 sessions"), each with
-- its own price via the existing regular/promo pricing columns.
--
-- Completely independent of, and does not touch, the separate 2-session
-- (is_two_session / session2_price / session_group_id) feature — that stays
-- exactly as-is.
--
--   - service_durations.session_count   — 1 = ordinary duration variant
--                                          (default, no behavior change).
--                                          >1 = an N-session package: the
--                                          price is the FULL package total,
--                                          charged upfront at booking.
--   - appointment_sessions              — per-appointment session progress
--                                          (date, therapist, status,
--                                          commission), one row per booked
--                                          session. Only populated for
--                                          appointments linked to a
--                                          session_count > 1 variant.
--                                          (This table already existed from
--                                          the abandoned draft above with
--                                          the exact schema needed here —
--                                          reused as-is, not recreated.)
--   - daily_report_session_commission_rows — commission-only Daily Report
--                                          rows generated when a later
--                                          session of a package completes on
--                                          a different calendar date than
--                                          the package's booking date. Kept
--                                          entirely separate from
--                                          daily_report_spreadsheet_rows
--                                          (the existing editable sale-row
--                                          table) — these rows are a
--                                          read-only record, never editable
--                                          through the Spreadsheet tab.
--
-- Note: the consuming pages (admin/services.php, admin/walkin.php,
-- admin/appointments.php, admin/daily_report.php,
-- admin/_daily_report_data.php) also run these same statements idempotently
-- on every page load (ADD COLUMN/CREATE TABLE IF NOT EXISTS), matching this
-- codebase's existing self-healing-schema convention. This file exists as
-- the canonical, reviewable record of the change — running it by hand is
-- not required for the feature to work, but is safe and idempotent if you
-- do.
-- ============================================================================

ALTER TABLE service_durations
    ADD COLUMN IF NOT EXISTS session_count INT NOT NULL DEFAULT 1 AFTER duration_minutes;

-- Old unique key (service_id, duration_minutes) no longer allows a service to
-- offer the same duration at two different session counts (e.g. a plain
-- 60-min session alongside a 60-min x 3-session package). Widen it to
-- include session_count. Guarded so re-running this file is a no-op once
-- already applied.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_durations'
      AND INDEX_NAME = 'uq_service_duration' AND COLUMN_NAME = 'session_count'
);
-- DROP + ADD must be a single ALTER TABLE statement: service_id's FK
-- (fk_service_durations_service) needs a supporting index at all times, and
-- MySQL only guarantees that across a combined statement, not two separate
-- ALTERs (a lone DROP INDEX fails with "needed in a foreign key constraint").
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE service_durations DROP INDEX uq_service_duration, ADD UNIQUE KEY uq_service_duration (service_id, duration_minutes, session_count)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS appointment_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    session_number INT NOT NULL,
    session_date DATETIME NULL,
    therapist_id INT NULL,
    duration_minutes INT NOT NULL,
    status ENUM('not_scheduled','scheduled','checked_in','completed') NOT NULL DEFAULT 'not_scheduled',
    commission DECIMAL(10,2) NULL,
    checked_in_at DATETIME NULL,
    completed_at DATETIME NULL,
    completed_by INT NULL,
    completed_by_name VARCHAR(120) NULL,
    UNIQUE KEY uq_appt_session (appointment_id, session_number),
    CONSTRAINT fk_appt_sessions_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_appt_sessions_therapist FOREIGN KEY (therapist_id) REFERENCES therapists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS daily_report_session_commission_rows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL,
    appointment_session_id INT NOT NULL,
    report_date DATE NOT NULL,
    therapist_id INT NULL,
    therapist_name VARCHAR(120) NULL,
    service_label VARCHAR(255) NOT NULL,
    customer_name VARCHAR(120) NULL,
    commission DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_drscr_date (report_date),
    CONSTRAINT fk_drscr_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_drscr_sess FOREIGN KEY (appointment_session_id) REFERENCES appointment_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM service_durations LIKE 'session_count';
-- Expected: int(11) NOT NULL DEFAULT 1, positioned right after `duration_minutes`
--
-- SHOW INDEX FROM service_durations WHERE Key_name = 'uq_service_duration';
-- Expected: covers (service_id, duration_minutes, session_count)
--
-- SHOW CREATE TABLE appointment_sessions;
-- SHOW CREATE TABLE daily_report_session_commission_rows;
-- Expected: both tables exist with the columns/keys/FKs listed above
-- ============================================================================
