-- ============================================================================
-- appointment_extra_services.slip_number — a service added on from the
-- Appointments desk ("+ Add service") needs its own paper slip number when
-- it is an aesthetic treatment, same as the main service already gets via
-- orders.slip_number / service_uses_aesthetic_slip(). Previously there was
-- nowhere to record it for an add-on, so an aesthetic add-on's slip stayed
-- unrecorded in the system.
-- Idempotent: only adds the column if it isn't already there.
-- ============================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointment_extra_services'
      AND COLUMN_NAME = 'slip_number'
);

SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE appointment_extra_services ADD COLUMN slip_number VARCHAR(50) NULL DEFAULT NULL AFTER person_label',
    'SELECT 1'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
