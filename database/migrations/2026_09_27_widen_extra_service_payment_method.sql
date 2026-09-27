-- ============================================================================
-- Migration: widen appointment_extra_services.payment_method + backfill rows
--            corrupted by the narrower enum silently truncating to ''
-- File: database/migrations/2026_09_27_widen_extra_service_payment_method.sql
--
-- Finding A (Daily Report / Net Cash audit): appointment_extra_services.
-- payment_method was ENUM('cash','card','qrph') — narrower than orders.
-- payment_method. admin/appointments.php's complete_session/complete flow
-- already unconditionally writes the appointment's real selected payment
-- method (e.g. 'swiper') into this column; when that value isn't in the
-- enum's list, MySQL/MariaDB silently stores '' instead of erroring. The
-- Daily Report's payment-method bucketing then silently drops that money
-- from every bucket (SWIPER/GCASH/MAYA/etc.), inflating Net Cash.
--
-- Safe to re-run: MODIFY COLUMN is idempotent (re-widening an already-wide
-- enum is a no-op), and the backfill UPDATE only ever touches rows that are
-- still '' — once corrected, a row is never matched again.
-- ============================================================================

ALTER TABLE appointment_extra_services
    MODIFY COLUMN payment_method
    ENUM('cash','online','gcash','maya','qrph','bank','card',
         'bpi_debit','bpi_credit','onsite','swiper')
    DEFAULT 'cash';

-- Backfill: for every extra-service row left at '' by the old enum's
-- truncation, restore the real payment method from its own appointment's
-- linked order (appointment_extra_services -> appointments -> order_items
-- -> orders.payment_method) — the same value the completion flow actually
-- intended to store.
UPDATE appointment_extra_services aes
JOIN appointments a  ON a.id  = aes.appointment_id
JOIN order_items  oi ON oi.id = a.order_item_id
JOIN orders       o  ON o.id  = oi.order_id
SET aes.payment_method = o.payment_method
WHERE aes.payment_method = '';

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM appointment_extra_services LIKE 'payment_method';
-- Expected: enum('cash','online','gcash','maya','qrph','bank','card',
--   'bpi_debit','bpi_credit','onsite','swiper') DEFAULT 'cash'
--
-- SELECT COUNT(*) FROM appointment_extra_services WHERE payment_method = '';
-- Expected: 0
-- ============================================================================
