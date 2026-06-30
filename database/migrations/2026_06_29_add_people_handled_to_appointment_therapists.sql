-- ============================================================================
-- Migration: add people_handled to appointment_therapists
-- File: database/migrations/2026_06_29_add_people_handled_to_appointment_therapists.sql
--
-- Tracks how many people each assigned therapist is responsible for in a
-- group booking. Defaults to 1 for all existing rows (backward-compatible).
-- SUM(people_handled) across all rows for an appointment must equal
-- appointments.people_count when fully staffed.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS (MariaDB 10.1.4+).
-- ============================================================================

ALTER TABLE appointment_therapists
    ADD COLUMN IF NOT EXISTS people_handled INT NOT NULL DEFAULT 1;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM appointment_therapists;
-- Expected: column 'people_handled' INT NOT NULL DEFAULT 1
