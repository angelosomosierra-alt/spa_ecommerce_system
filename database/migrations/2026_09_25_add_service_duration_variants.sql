-- ============================================================================
-- Migration: service duration variants
-- File: database/migrations/2026_09_25_add_service_duration_variants.sql
--
-- Lets a single service catalog entry (e.g. "Swedish Massage") offer more
-- than one duration, each with its own regular/promo price (e.g. 60 min /
-- ₱849 vs 90 min / ₱1,199), WITHOUT creating separate service rows.
-- Purely additive — a service with zero rows in service_durations behaves
-- exactly as before (single services.price / services.session_time).
--
--   - service_durations           — duration/price variants per service,
--                                    only populated for services that opt
--                                    into multiple durations.
--   - appointments.service_duration_id — records which specific variant
--                                    was booked, for traceability only.
--                                    Nullable; appointments.duration_minutes
--                                    and appointments.charged_price remain
--                                    the fields that actually drive
--                                    scheduling and payment, exactly as
--                                    today.
--
-- Idempotent: ADD COLUMN/CREATE TABLE IF NOT EXISTS is safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS service_durations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    duration_minutes INT NOT NULL,
    regular_price DECIMAL(10,2) NOT NULL,
    promo_price DECIMAL(10,2) NOT NULL,
    UNIQUE KEY uq_service_duration (service_id, duration_minutes),
    CONSTRAINT fk_service_durations_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS service_duration_id INT NULL DEFAULT NULL AFTER duration_minutes;

-- FK added separately (some MariaDB versions choke on ADD COLUMN + ADD
-- CONSTRAINT combined with IF NOT EXISTS) — this fails harmlessly with a
-- duplicate-key/constraint-exists error if re-run, which is fine to ignore.
ALTER TABLE appointments
    ADD CONSTRAINT fk_appt_service_duration FOREIGN KEY (service_duration_id) REFERENCES service_durations(id) ON DELETE SET NULL;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW CREATE TABLE service_durations;
-- SHOW COLUMNS FROM appointments LIKE 'service_duration_id';
-- Expected: int(11) NULL DEFAULT NULL, positioned right after duration_minutes
