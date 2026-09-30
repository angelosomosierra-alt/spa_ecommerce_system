-- ============================================================================
-- Migration: multi-session package support
-- File: database/migrations/2026_09_25_add_multi_session_package_support.sql
--
-- Adds a general, N-session package feature for walk-in bookings, additive to
-- (and independent of) the existing 2-session (is_two_session/session2_price/
-- session_group_id) feature:
--
--   - services.session_count            — 1 = regular service (default,
--                                          no behavior change). >1 = a
--                                          multi-session package.
--   - service_sessions                  — per-service duration template,
--                                          one row per session_number, only
--                                          populated for session_count > 1.
--   - appointment_sessions               — per-appointment session progress
--                                          (date, therapist, status,
--                                          commission), one row per booked
--                                          session.
--   - appointments.promo_percent        — audit-trail only; records the %
--                                          used if a Promo discount was
--                                          applied at walk-in. charged_price
--                                          already reflects the discounted
--                                          amount — this column is never read
--                                          in any price calculation.
--
-- Note: the consuming pages (admin/services.php, admin/walkin.php,
-- admin/appointments.php) also run these same statements idempotently on
-- every page load (ADD COLUMN/CREATE TABLE IF NOT EXISTS), matching this
-- codebase's existing self-healing-schema convention (see is_two_session /
-- session_group_id, which shipped the same way). This file exists as the
-- canonical, reviewable record of the change — running it by hand is not
-- required for the feature to work, but is safe and idempotent if you do.
-- ============================================================================

ALTER TABLE services
    ADD COLUMN IF NOT EXISTS session_count INT NOT NULL DEFAULT 1 AFTER price;

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS promo_percent DECIMAL(5,2) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS service_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    session_number INT NOT NULL,
    duration_minutes INT NOT NULL,
    UNIQUE KEY uq_service_session (service_id, session_number),
    CONSTRAINT fk_svc_sessions_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM services LIKE 'session_count';
-- Expected: int(11) NOT NULL DEFAULT 1, positioned right after `price`
--
-- SHOW COLUMNS FROM appointments LIKE 'promo_percent';
-- Expected: decimal(5,2) NULL DEFAULT NULL
--
-- SHOW CREATE TABLE service_sessions;
-- SHOW CREATE TABLE appointment_sessions;
-- Expected: both tables exist with the columns/keys/FKs listed above
