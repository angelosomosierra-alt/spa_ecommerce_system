-- ============================================================================
-- Cancelling or declining a booking never touched its order's
-- payment_status — it stayed 'unpaid' forever even though there's no
-- service left to collect for. That inflated "Pending Payments" on the
-- Dashboard with dead entries (confirmed: cancelled/declined bookings
-- were a real share of that count). admin/appointments.php's cancel and
-- decline actions now set the order to 'void' when they close out a
-- booking whose order was still 'unpaid'; this migration does the same,
-- once, for every already-cancelled/declined booking still carrying one.
--
-- 'void' is a new payment_status value — distinct from 'refunded' (money
-- was collected, then given back) and from leaving it 'unpaid' (still
-- owed). Safe to re-run: only ever touches rows currently 'unpaid' whose
-- appointment is already cancelled/declined.
-- ============================================================================

SET @enum_has_void := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_status'
      AND COLUMN_TYPE LIKE '%''void''%'
);

SET @ddl := IF(@enum_has_void = 0,
    "ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending_payment','unpaid','paid','refunded','partially_refunded','void') NOT NULL DEFAULT 'unpaid'",
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE orders o
JOIN order_items oi ON oi.order_id = o.id
JOIN appointments a ON a.order_item_id = oi.id
SET o.payment_status = 'void'
WHERE o.payment_status = 'unpaid'
  AND a.status IN ('cancelled', 'declined');
