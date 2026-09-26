-- ============================================================================
-- Migration: classic (single-duration) service promo pricing
-- File: database/migrations/2026_09_26_add_classic_service_promo_pricing.sql
--
-- Mirrors service_durations' own promo scheduling columns
-- (2026_09_26_add_service_duration_promo_scheduling.sql) onto `services`
-- itself, so a plain single-duration service (Duration Options checkbox
-- unchecked in admin/services.php) can also have a scheduled Promo Price —
-- without being forced into the multi-row Duration Options UI just to get
-- one extra field. Resolved at runtime by config.php's new
-- get_active_service_price(), which shares its core logic with
-- get_active_duration_price() via a common internal resolver.
--
-- Every existing service defaults to price_mode='regular', promo_price=NULL
-- — zero behavior change for services that don't use this. Completely
-- independent of service_durations / Duration Options, which keep using
-- their own per-row promo columns exactly as before.
--
-- Note: the consuming pages (admin/services.php, admin/walkin.php) also run
-- this same statement idempotently on every page load (ADD COLUMN IF NOT
-- EXISTS), matching this codebase's existing self-healing-schema convention.
-- This file exists as the canonical, reviewable record of the change.
-- ============================================================================

ALTER TABLE services
    ADD COLUMN IF NOT EXISTS promo_price       DECIMAL(10,2) NULL AFTER price,
    ADD COLUMN IF NOT EXISTS price_mode        ENUM('regular','promo') NOT NULL DEFAULT 'regular' AFTER promo_price,
    ADD COLUMN IF NOT EXISTS promo_start_time  TIME NULL AFTER price_mode,
    ADD COLUMN IF NOT EXISTS promo_end_time    TIME NULL AFTER promo_start_time;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM services LIKE 'promo_price';
-- SHOW COLUMNS FROM services LIKE 'price_mode';
-- SHOW COLUMNS FROM services LIKE 'promo_start_time';
-- SHOW COLUMNS FROM services LIKE 'promo_end_time';
-- Expected: promo_price DECIMAL(10,2) NULL; price_mode ENUM('regular','promo')
-- NOT NULL DEFAULT 'regular'; promo_start_time / promo_end_time TIME NULL —
-- all positioned right after `price`.
-- ============================================================================
