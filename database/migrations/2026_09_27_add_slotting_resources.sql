-- ============================================================================
-- Migration: Slotting and Rotation — physical resources (Rooms/Chairs/HS)
-- File: database/migrations/2026_09_27_add_slotting_resources.sql
--
-- service_resources: the configurable physical resource catalog (Rooms,
-- Chairs, Head Spa stations). Never hard-deleted — deactivated via is_active,
-- since historical appointments may reference a resource_id.
--
-- appointments.resource_id: nullable, optional. Left NULL on every existing
-- appointment (no backfill, per confirmed decision) and NULL by default on
-- every new appointment unless explicitly assigned (walk-in booking, Phase 4)
-- or required at approval (Phase 5).
-- ============================================================================

CREATE TABLE IF NOT EXISTS service_resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    type ENUM('room','chair','head_spa') NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE appointments
    ADD COLUMN IF NOT EXISTS resource_id INT NULL AFTER service_duration_id;

-- FK added separately and guarded: ADD CONSTRAINT has no IF NOT EXISTS form,
-- so re-running the bare statement errors on a second pass. Check first.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments'
      AND CONSTRAINT_NAME = 'fk_appointments_resource'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_resource FOREIGN KEY (resource_id) REFERENCES service_resources(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed data — only if the table is empty, so re-running never duplicates rows
-- or reintroduces resources an admin has since deactivated/renamed.
SET @resource_count := (SELECT COUNT(*) FROM service_resources);
INSERT INTO service_resources (name, type, sort_order)
SELECT * FROM (
    SELECT 'Room 1' AS name, 'room' AS type, 1 AS sort_order UNION ALL
    SELECT 'Room 2', 'room', 2 UNION ALL
    SELECT 'Room 3', 'room', 3 UNION ALL
    SELECT 'Room 4', 'room', 4 UNION ALL
    SELECT 'Chair 1', 'chair', 5 UNION ALL
    SELECT 'Chair 2', 'chair', 6 UNION ALL
    SELECT 'Chair 3', 'chair', 7 UNION ALL
    SELECT 'HS 1', 'head_spa', 8 UNION ALL
    SELECT 'HS 2', 'head_spa', 9
) seed
WHERE @resource_count = 0;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW CREATE TABLE service_resources;
-- SELECT * FROM service_resources ORDER BY sort_order;  -- 9 rows, only if table was empty
-- SHOW COLUMNS FROM appointments LIKE 'resource_id';
-- SHOW CREATE TABLE appointments;  -- confirm fk_appointments_resource exists once
-- SELECT COUNT(*) FROM appointments WHERE resource_id IS NOT NULL;  -- 0 right after migration
-- ============================================================================
