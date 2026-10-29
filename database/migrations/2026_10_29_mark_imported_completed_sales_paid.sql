-- ============================================================================
-- Historical sales imported from the paper records before the system went
-- live (booking_group 'IMPOCT...') were already real, completed,
-- already-collected transactions. The import correctly marked 986 of 1013
-- of them 'paid', but missed 27 -- all 'completed' appointments from
-- October 1-25, left at 'unpaid' -- inflating "Pending Payments" with
-- sales that were never actually owed.
--
-- Scoped tight on purpose: only an imported row (booking_group LIKE
-- 'IMP%') whose appointment is already 'completed'. Never touches a live
-- booking (no IMP prefix) or an imported row that isn't completed.
-- Safe to re-run.
-- ============================================================================

UPDATE orders o
JOIN order_items oi ON oi.order_id = o.id
JOIN appointments a ON a.order_item_id = oi.id
SET o.payment_status = 'paid'
WHERE o.payment_status = 'unpaid'
  AND o.booking_group LIKE 'IMP%'
  AND a.status = 'completed';
