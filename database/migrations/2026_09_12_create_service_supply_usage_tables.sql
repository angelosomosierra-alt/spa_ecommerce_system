-- ============================================================================
-- Migration: create service recipe + supply deduction audit tables (Phase 2)
-- File: database/migrations/2026_09_12_create_service_supply_usage_tables.sql
--
-- service_supply_usage — recipe: which supplies are consumed per person
--                        per service session and in what quantity (base units).
-- supply_usage_log     — immutable audit trail of every deduction made at
--                        appointment completion; stores stock_before and
--                        stock_after so the floor-at-zero behaviour is visible.
--
-- Deduction formula (appointments.php hook):
--   deducted = quantity_per_person × people_count
--   stock_after = GREATEST(0, stock_before - deducted)
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.
-- ============================================================================

-- ─── service_supply_usage ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `service_supply_usage` (
  `id`                  int(11)       NOT NULL AUTO_INCREMENT,
  `service_id`          int(11)       NOT NULL,
  `supply_id`           int(11)       NOT NULL,
  `quantity_per_person` decimal(10,4) NOT NULL DEFAULT 1.0000
                        COMMENT 'Base units consumed per person per session (ml, g, pcs, etc.)',
  `created_at`          timestamp     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ssu_service_supply` (`service_id`, `supply_id`),
  KEY `idx_ssu_service` (`service_id`),
  KEY `idx_ssu_supply`  (`supply_id`),
  CONSTRAINT `fk_ssu_service`
    FOREIGN KEY (`service_id`) REFERENCES `services`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ssu_supply`
    FOREIGN KEY (`supply_id`)  REFERENCES `supplies`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─── supply_usage_log ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `supply_usage_log` (
  `id`               int(11)       NOT NULL AUTO_INCREMENT,
  `appointment_id`   int(11)       NOT NULL,
  `supply_id`        int(11)       DEFAULT NULL,
  `quantity_deducted` decimal(14,4) NOT NULL
                     COMMENT 'Base units actually removed (may be less than requested when floored at zero)',
  `stock_before`     decimal(14,4) NOT NULL
                     COMMENT 'supplies.current_stock snapshot immediately before this deduction',
  `stock_after`      decimal(14,4) NOT NULL
                     COMMENT 'supplies.current_stock snapshot after deduction; equals GREATEST(0, stock_before - requested)',
  `deducted_at`      timestamp     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sul_appointment` (`appointment_id`),
  KEY `idx_sul_supply`      (`supply_id`),
  KEY `idx_sul_deducted_at` (`deducted_at`),
  CONSTRAINT `fk_sul_appointment`
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sul_supply`
    FOREIGN KEY (`supply_id`)      REFERENCES `supplies`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW CREATE TABLE service_supply_usage\G
-- SHOW CREATE TABLE supply_usage_log\G
-- Expected: both tables present with FK constraints and named indexes.
