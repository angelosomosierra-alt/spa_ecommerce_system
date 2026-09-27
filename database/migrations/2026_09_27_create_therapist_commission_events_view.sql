-- ============================================================================
-- Migration: v_therapist_commission_events — one shared, correct source of
-- "therapist X earned commission Y on date Z" for every payroll/roster view.
-- Requires views (MariaDB 10.4 / MySQL 5.x+).
--
-- 'regular'         — ordinary appointments (incl. is_two_session bookings, which
--                     never create appointment_sessions rows): completed status,
--                     dated by appointment_date — identical to the old queries.
-- 'package_session' — one row per COMPLETED session of a session_count>1 package,
--                     dated by when that session actually finished. The first half
--                     excludes any appointment with appointment_sessions rows so a
--                     package's accumulated appointment_therapists row is never
--                     double-counted.
-- Consuming pages also run CREATE OR REPLACE VIEW via config.php's
-- ensure_commission_events_view() (self-healing convention).
-- ============================================================================
CREATE OR REPLACE VIEW v_therapist_commission_events AS
SELECT
    at2.id                          AS event_id,
    at2.therapist_id                AS therapist_id,
    at2.commission                  AS commission,
    DATE(ap.appointment_date)       AS event_date,
    ap.id                           AS appointment_id,
    'regular'                       AS source
FROM appointment_therapists at2
JOIN appointments ap ON at2.appointment_id = ap.id
WHERE ap.status = 'completed'
  AND NOT EXISTS (SELECT 1 FROM appointment_sessions aps2 WHERE aps2.appointment_id = ap.id)
UNION ALL
SELECT
    aps.id                                             AS event_id,
    aps.therapist_id                                   AS therapist_id,
    aps.commission                                     AS commission,
    DATE(COALESCE(aps.completed_at, aps.session_date)) AS event_date,
    aps.appointment_id                                 AS appointment_id,
    'package_session'                                  AS source
FROM appointment_sessions aps
WHERE aps.status = 'completed'
  AND aps.commission IS NOT NULL
  AND aps.therapist_id IS NOT NULL;
