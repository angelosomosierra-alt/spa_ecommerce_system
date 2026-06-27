-- ============================================================================
-- Feature C · Phase 1 (Revised) — extend appointment_extra_services
-- File: database/migrations/2026_06_feature_c_extra_services_columns.sql
--
-- DESIGN: add-ons are ADDITIVE. The original service stays on the appointments
-- row (existing reports untouched). Add-on services go in the already-existing
-- appointment_extra_services table. Future reports will SUM both sources.
-- This migration only adds the two columns needed for per-add-on therapist
-- tracking and commission: therapist_id and commission.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS (MariaDB 10.1.4+).
-- Safe on a live DB; does NOT alter appointments or appointment_therapists.
-- ============================================================================

-- ── Add missing columns ───────────────────────────────────────────────────────
ALTER TABLE appointment_extra_services
    ADD COLUMN IF NOT EXISTS therapist_id INT            DEFAULT NULL  AFTER service_id,
    ADD COLUMN IF NOT EXISTS commission   DECIMAL(10,2)  NOT NULL DEFAULT 0.00 AFTER charged_price;

-- Fallback if your MariaDB is older than 10.1.4 (run only if the ALTER above errors):
-- ALTER TABLE appointment_extra_services ADD COLUMN therapist_id INT DEFAULT NULL AFTER service_id;
-- ALTER TABLE appointment_extra_services ADD COLUMN commission DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER charged_price;

-- ── Indexes ───────────────────────────────────────────────────────────────────
-- CREATE INDEX IF NOT EXISTS requires MariaDB 10.1.4+.
-- Skip either statement if the index already exists (MariaDB will no-op it).

CREATE INDEX IF NOT EXISTS idx_aes_therapist_id
    ON appointment_extra_services (therapist_id);

-- appointment_id index: add only if not already present.
-- To check first: SHOW INDEX FROM appointment_extra_services;
-- Look for a Key_name covering appointment_id; if one exists, skip this line.
CREATE INDEX IF NOT EXISTS idx_aes_appointment_id
    ON appointment_extra_services (appointment_id);

-- ============================================================================
-- VERIFICATION (run in phpMyAdmin after migration)
-- ============================================================================
-- Confirm therapist_id and commission columns are present:
-- SHOW COLUMNS FROM appointment_extra_services;
--
-- Confirm indexes:
-- SHOW INDEX FROM appointment_extra_services;
