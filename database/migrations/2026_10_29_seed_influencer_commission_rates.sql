-- ============================================================================
-- Seed: influencer_flat_rate on therapist_commission — the fixed commission
-- a therapist earns per service on a ₱0 Influencer/PR booking, instead of a
-- percentage of a price that's zero by design (admin/appointments.php already
-- computes `commission_amt = influencer_flat_rate * people_handled` for
-- rate_type='influencer'; this column has existed since the commission
-- feature shipped but was left at its 0.00 default for almost every row).
--
-- Source: the owner's real "COMMISSION" sheet (FIX CF column) from the
-- October 2026 sales report, cross-checked against the "Commission fee
-- (Fix)" entries actually recorded on real INFLUENCER/MARKETING bookings
-- that month (e.g. Reg Foot Spa → 50, Swedish Massage → 100, Nail Cleaning
-- → 50 — all exact matches). Only everyday/lower-ticket services the owner
-- actually comps for influencers are in that sheet; aesthetics, HIFU,
-- drips etc. are not, so they are intentionally left unseeded here (stay
-- at 0.00, same as before this migration).
--
-- "Pare-pareho ang lahat ng therapist" — this does not create new
-- specialists. It only sets the flat rate for a (therapist, service) pair
-- that is already a confirmed specialty in therapist_specialty_services
-- (set via Staff > Therapists > specialties, or by category). A therapist
-- with no specialty row for a service gets no influencer_flat_rate row for
-- it here, same as before.
--
-- Safe to re-run: UPSERTs by the existing uq_therapist_service key and only
-- ever touches influencer_flat_rate, never commission_percent.
-- ============================================================================

INSERT INTO therapist_commission (therapist_id, service_id, commission_percent, influencer_flat_rate)
SELECT tss.therapist_id, tss.service_id, 0.00, m.flat_rate
FROM therapist_specialty_services tss
JOIN services s ON s.id = tss.service_id
JOIN (
    -- FIX CF 30 — quick add-on / removal services
    SELECT 'Brow Shaping (Wax or Thread)' AS name, 30.00 AS flat_rate
    UNION ALL SELECT 'Lash Removal',            30.00
    UNION ALL SELECT 'Gel Removal',             30.00
    UNION ALL SELECT 'Nail Extension Removal',  30.00

    -- FIX CF 50 — mid-tier services
    UNION ALL SELECT 'Nail Cleaning',                       50.00
    UNION ALL SELECT 'Basic Manicure',                      50.00
    UNION ALL SELECT 'Basic Pedicure',                      50.00
    UNION ALL SELECT 'Gel Manicure',                        50.00
    UNION ALL SELECT 'Gel Pedicure',                        50.00
    UNION ALL SELECT 'Regular Foot Spa',                    50.00
    UNION ALL SELECT 'Foot Spa + Foot Scrub',                50.00
    UNION ALL SELECT 'Lash lift',                           50.00
    UNION ALL SELECT 'Brow Tinting',                        50.00
    UNION ALL SELECT 'Brow Lamination',                     50.00
    UNION ALL SELECT 'Brow Shaping + Tint Package',          50.00
    UNION ALL SELECT 'Milk + Honey Scrub',                  50.00
    UNION ALL SELECT 'Coffee Detox Scrub',                  50.00
    UNION ALL SELECT 'Junior Headspa',                      50.00
    UNION ALL SELECT 'Foot Massage',                        50.00

    -- FIX CF 100 — higher-ticket everyday services
    UNION ALL SELECT 'Simple Nail Extension',                100.00
    UNION ALL SELECT 'Personal Design Nail Extension',       100.00
    UNION ALL SELECT 'Foot Spa + Foot Scrub + Foot Massage', 100.00
    UNION ALL SELECT 'Foot Spa + Food Scrub + Foot Massage', 100.00
    UNION ALL SELECT 'Classic Lash Extension',               100.00
    UNION ALL SELECT 'Cat Eye Wispy Lash Extension',         100.00
    UNION ALL SELECT 'Semi  Glam Lash Extension',            100.00
    UNION ALL SELECT 'Full Glam Lash Extension',             100.00
    UNION ALL SELECT 'Brow Lamination + Tint Package',       100.00
    UNION ALL SELECT 'Express Head Spa',                     100.00
    UNION ALL SELECT 'Luxury Scalp and Basic Facial',        100.00
    UNION ALL SELECT 'Swedish',                              100.00
    UNION ALL SELECT 'Thai Massage',                         100.00
    UNION ALL SELECT 'Combination Massage',                  100.00
    UNION ALL SELECT 'Ventosa',                               100.00
    UNION ALL SELECT 'Hot Stone',                            100.00
    UNION ALL SELECT 'Shiatsu',                              100.00
) AS m ON m.name = s.name
ON DUPLICATE KEY UPDATE influencer_flat_rate = VALUES(influencer_flat_rate);
