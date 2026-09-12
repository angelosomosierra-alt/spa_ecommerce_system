-- ============================================================================
-- Migration: extend users.admin_role enum to include 'hr'
-- File: database/migrations/2026_09_11_extend_admin_role_enum_add_hr.sql
--
-- The 'hr' admin sub-role was fully implemented in PHP (admin_access.php,
-- staff.php UI, page-role guards) but the database enum was never extended,
-- causing MySQL to silently store '' (empty string) instead of 'hr' when
-- non-strict mode is active — making any created HR account unusable.
--
-- Idempotent: MODIFY COLUMN with the full enum list is safe to re-run;
-- if 'hr' is already present, MySQL accepts the statement without error.
-- ============================================================================

ALTER TABLE users
    MODIFY COLUMN admin_role
        ENUM('owner','cashier','marketing','it','hr') DEFAULT NULL;

-- ============================================================================
-- VERIFICATION
-- ============================================================================
-- SHOW COLUMNS FROM users LIKE 'admin_role';
-- Expected Type: enum('owner','cashier','marketing','it','hr')
