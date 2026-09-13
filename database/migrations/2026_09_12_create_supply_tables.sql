-- ============================================================================
-- Migration: create supply inventory tables (Phase 1)
-- File: database/migrations/2026_09_12_create_supply_tables.sql
--
-- Creates three tables for internal consumables tracking:
--   supplies         — master list of supply items
--   supply_deliveries — log of stock-in events (increases current_stock)
--   supply_counts     — log of physical counts (resets current_stock to truth)
--
-- current_stock is stored in base units (ml, g, pcs, etc.).
-- unit_cost (supplier_price / content_per_unit) is computed on read, not stored.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.
-- ============================================================================

-- ─── supplies ────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `supplies` (
  `id`               int(11)        NOT NULL AUTO_INCREMENT,
  `name`             varchar(255)   NOT NULL,
  `category`         varchar(100)   NOT NULL
                     COMMENT 'Massage | Nails | Lashes | Aesthetics | Assorted | Product | Product Sold',
  `uom_label`        varchar(20)    NOT NULL
                     COMMENT 'Purchase unit label shown in UI: BOT, PCS, GAL, KILO, BOX, PACK, etc.',
  `content_per_unit` decimal(10,4)  NOT NULL DEFAULT 1.0000
                     COMMENT 'How many base units are in one purchase unit (e.g. 500.0000 for a 500 ml bottle)',
  `base_unit_label`  varchar(20)    NOT NULL
                     COMMENT 'Smallest tracked unit label: ml, g, pcs, etc.',
  `supplier_price`   decimal(10,2)  NOT NULL DEFAULT 0.00
                     COMMENT 'Cost per ONE purchase unit (per bottle, per box, etc.)',
  `current_stock`    decimal(14,4)  NOT NULL DEFAULT 0.0000
                     COMMENT 'Running stock total in base units; updated by deliveries and physical counts',
  `reorder_level`    decimal(14,4)  DEFAULT NULL
                     COMMENT 'Optional low-stock alert threshold in base units; NULL = no alert',
  `notes`            text           DEFAULT NULL,
  `deleted_at`       datetime       DEFAULT NULL,
  `created_at`       timestamp      NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_supplies_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─── supply_deliveries ───────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `supply_deliveries` (
  `id`              int(11)       NOT NULL AUTO_INCREMENT,
  `supply_id`       int(11)       NOT NULL,
  `delivery_date`   date          NOT NULL,
  `quantity_units`  decimal(10,4) NOT NULL
                    COMMENT 'Quantity received in purchase units (e.g. 3.0000 bottles)',
  `quantity_base`   decimal(14,4) NOT NULL
                    COMMENT 'quantity_units x content_per_unit; snapshotted at insert so history is stable if content_per_unit is later edited',
  `added_by`        int(11)       DEFAULT NULL,
  `added_by_name`   varchar(150)  NOT NULL,
  `notes`           varchar(500)  DEFAULT NULL,
  `created_at`      timestamp     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_delivery_supply`  (`supply_id`),
  KEY `idx_delivery_date`    (`delivery_date`),
  CONSTRAINT `fk_delivery_supply`
    FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ─── supply_counts ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `supply_counts` (
  `id`                   int(11)       NOT NULL AUTO_INCREMENT,
  `supply_id`            int(11)       NOT NULL,
  `count_date`           date          NOT NULL,
  `whole_units_counted`  int(11)       NOT NULL DEFAULT 0
                         COMMENT 'Full sealed / unopened purchase units counted',
  `partial_base_counted` decimal(10,4) NOT NULL DEFAULT 0.0000
                         COMMENT 'Remaining base units in an open container; use 0 for piece-based items',
  `total_base_counted`   decimal(14,4) NOT NULL
                         COMMENT 'Stored: (whole_units_counted x content_per_unit) + partial_base_counted; snapshotted at write time',
  `expected_stock`       decimal(14,4) NOT NULL
                         COMMENT 'supplies.current_stock snapshotted at the moment the count is submitted',
  `variance`             decimal(14,4) NOT NULL
                         COMMENT 'total_base_counted - expected_stock; negative = shrinkage',
  `counted_by`           int(11)       DEFAULT NULL,
  `counted_by_name`      varchar(150)  NOT NULL,
  `notes`                varchar(500)  DEFAULT NULL,
  `created_at`           timestamp     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_count_supply` (`supply_id`),
  KEY `idx_count_date`   (`count_date`),
  CONSTRAINT `fk_count_supply`
    FOREIGN KEY (`supply_id`) REFERENCES `supplies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW CREATE TABLE supplies\G
-- SHOW CREATE TABLE supply_deliveries\G
-- SHOW CREATE TABLE supply_counts\G
-- Expected: all three tables present with FK constraints and named indexes.
