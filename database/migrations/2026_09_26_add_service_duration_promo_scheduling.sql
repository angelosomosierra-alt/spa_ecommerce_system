-- ============================================================================
-- Migration: per-duration-variant promo price scheduling
-- File: database/migrations/2026_09_26_add_service_duration_promo_scheduling.sql
--
-- Additive to service_durations (added in
-- 2026_09_25_add_service_duration_variants.sql). Lets a single duration
-- variant (e.g. "60 mins") be scheduled to automatically charge its
-- promo_price instead of regular_price during a daily time window
-- (e.g. 10:00 AM - 4:00 PM), reverting automatically outside that window —
-- evaluated fresh on every page load via get_active_duration_price()
-- (config.php), no cron job needed.
--
-- Every existing row defaults to price_mode='regular' — no behavior change
-- for variants already created before this migration.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS is safe to re-run.
-- ============================================================================

ALTER TABLE service_durations
    ADD COLUMN IF NOT EXISTS price_mode ENUM('regular','promo') NOT NULL DEFAULT 'regular' AFTER promo_price,
    ADD COLUMN IF NOT EXISTS promo_start_time TIME NULL AFTER price_mode,
    ADD COLUMN IF NOT EXISTS promo_end_time TIME NULL AFTER promo_start_time;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM service_durations LIKE 'price_mode';
-- Expected: enum('regular','promo') NOT NULL DEFAULT 'regular'
-- SHOW COLUMNS FROM service_durations LIKE 'promo_start_time';
-- SHOW COLUMNS FROM service_durations LIKE 'promo_end_time';
-- Expected: both time NULL DEFAULT NULL
