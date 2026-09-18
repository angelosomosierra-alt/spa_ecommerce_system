-- ============================================================================
-- Migration: add advance_payment_method to appointments
-- File: database/migrations/2026_09_16_add_advance_payment_method_to_appointments.sql
--
-- Tracks which payment method was used to collect the advance (down) payment
-- on a walk-in service booking. Used to auto-compute per-method DP totals in
-- the Daily Report instead of requiring manual entry in the report header.
--
-- Previously only maya_dp was manually tracked in daily_reports; this column
-- allows GCash, Card/Swiper, QRPH, and Cash advances to be reported accurately
-- as well.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS is safe to re-run.
-- Existing rows default to 'cash' which is the correct fallback for historical
-- appointments where method was not recorded.
-- ============================================================================

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS advance_payment_method VARCHAR(20) NOT NULL DEFAULT 'cash';

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM appointments LIKE 'advance_payment_method';
-- Expected: varchar(20) NOT NULL DEFAULT 'cash'
