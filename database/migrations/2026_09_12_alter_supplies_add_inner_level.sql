-- ============================================================================
-- Migration: add inner-level packaging columns to supplies
-- File: database/migrations/2026_09_12_alter_supplies_add_inner_level.sql
--
-- Adds optional Case → Inner → Piece hierarchy to the supplies table.
-- Existing rows default to has_inner_level = 0 — no change in behaviour.
-- When has_inner_level = 1:
--   content_per_unit is auto-computed as inners_per_case × pieces_per_inner
--   so all existing delivery/count math that uses content_per_unit keeps working.
-- ============================================================================

ALTER TABLE `supplies`
  ADD COLUMN `has_inner_level` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 = Case → Inner → Piece hierarchy; 0 = flat content_per_unit',
  ADD COLUMN `inners_per_case` decimal(10,4) DEFAULT NULL
    COMMENT 'How many inner units in one case; NULL when has_inner_level = 0',
  ADD COLUMN `pieces_per_inner` decimal(10,4) DEFAULT NULL
    COMMENT 'How many pieces in one inner; NULL when has_inner_level = 0';

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM supplies;
-- Expected: has_inner_level, inners_per_case, pieces_per_inner present.
-- Existing rows: has_inner_level = 0, inners_per_case = NULL, pieces_per_inner = NULL.
