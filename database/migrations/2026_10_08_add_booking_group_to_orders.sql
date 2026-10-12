-- ============================================================================
-- Migration: add booking_group to orders
-- File: database/migrations/2026_10_08_add_booking_group_to_orders.sql
--
-- The Dashboard's Book Now modal books several guests / several services in
-- one go, as one unpaid order per service (same shape as the classic Quick
-- Book). booking_group links every order from one such booking so
-- appointments.php can offer "Pay for group": one payment covering the
-- chosen guests, each with their own discount. NULL for every other order.
--
-- admin/_book_now.php (book_now_ensure_schema) also runs these statements
-- idempotently, matching this codebase's self-healing-schema convention.
-- ============================================================================

ALTER TABLE orders ADD COLUMN IF NOT EXISTS booking_group VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE orders ADD INDEX IF NOT EXISTS idx_orders_booking_group (booking_group);

-- VERIFICATION
-- SHOW COLUMNS FROM orders LIKE 'booking_group';
