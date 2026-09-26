-- ============================================================================
-- Migration: unify service pricing — backfill service_durations for every
--            service that doesn't have a row yet
-- File: database/migrations/2026_09_26_unify_service_pricing_backfill.sql
--
-- Removes the "classic vs Duration Options" split in admin/services.php.
-- Going forward every service — simple or complex — is configured entirely
-- through service_durations rows; the old classic-only fields (services.price/
-- session_time/promo_price/price_mode/promo_start_time/promo_end_time) become
-- a read-only synced mirror (kept for other, not-yet-migrated code that still
-- reads services.price directly — see admin/services.php's post-save sync).
--
-- For every service with ZERO service_durations rows, this inserts exactly
-- one row carrying its current classic price/duration (and promo columns,
-- if any were ever set — none were as of this migration, but the statement
-- handles it generally). Services that already have rows (Duration Options
-- users, and session_count > 1 packages from the earlier pass) are left
-- completely untouched — the WHERE NOT EXISTS guard makes this idempotent
-- and safe to re-run.
-- ============================================================================

INSERT INTO service_durations
    (service_id, duration_minutes, session_count, regular_price, promo_price, price_mode, promo_start_time, promo_end_time)
SELECT
    s.id,
    s.session_time,
    1,
    s.price,
    s.promo_price,
    s.price_mode,
    s.promo_start_time,
    s.promo_end_time
FROM services s
WHERE NOT EXISTS (SELECT 1 FROM service_durations sd WHERE sd.service_id = s.id);

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SELECT COUNT(*) FROM services s WHERE NOT EXISTS
--   (SELECT 1 FROM service_durations sd WHERE sd.service_id = s.id);
-- Expected: 0 (every service now has at least one row)
-- ============================================================================
