<?php
/**
 * availability.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Core availability engine for Recovery Spa booking system.
 *
 * Operating hours: 10:00 AM – 10:00 PM (Asia/Manila)
 * Slot interval:   Based on service session_time
 * Pre-open window: 2 hours before open (8:00 AM) = future booking rules apply
 *
 * Priority:
 *   1st — Partner/Hotel  (rate_type = 'hotel')
 *   2nd — Online booking
 *   3rd — Walk-in
 *
 * Usage:
 *   require_once 'availability.php';
 *   $engine = new AvailabilityEngine($conn);
 *   $slots  = $engine->getAvailableSlots($service_id, $date, $people_count, $rate_type);
 *   $check  = $engine->checkSlot($service_id, $datetime, $people_count, $rate_type);
 */

class AvailabilityEngine {

    private $conn;
    private $tz;

    // Operating hours
    const OPEN_HOUR      = 10; // 10:00 AM
    const CLOSE_HOUR     = 20; // 8:00 PM
    const PRE_OPEN_HOURS = 2;  // 2hrs before open = future booking rules

    public function __construct($conn) {
        $this->conn = $conn;
        $this->tz   = new DateTimeZone('Asia/Manila');
    }

    // ── Get service details ───────────────────────────────────────────────────
    public function getService(int $service_id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM services WHERE id = ?");
        $stmt->bind_param("i", $service_id);
        $stmt->execute();
        $svc = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $svc ?: null;
    }

    // ── Check if service has ANY qualified therapist ──────────────────────────
    public function serviceHasTherapist(int $service_id): bool {
        $result = $this->conn->query("
            SELECT COUNT(*) AS c
            FROM (
                SELECT therapist_id FROM therapist_specialty_services WHERE service_id = $service_id
                UNION
                SELECT ts.therapist_id FROM therapist_specialties ts
                JOIN services s ON s.category_id = ts.category_id
                WHERE s.id = $service_id
                UNION
                SELECT id AS therapist_id FROM therapists WHERE is_generalist = 1
            ) qualified
        ");
        return intval($result->fetch_assoc()['c']) > 0;
    }

    // ── Get qualified therapist IDs for a service ─────────────────────────────
    public function getQualifiedTherapistIds(int $service_id): array {
        $result = $this->conn->query("
            SELECT DISTINCT therapist_id FROM (
                SELECT therapist_id FROM therapist_specialty_services WHERE service_id = $service_id
                UNION
                SELECT ts.therapist_id FROM therapist_specialties ts
                JOIN services s ON s.category_id = ts.category_id
                WHERE s.id = $service_id
                UNION
                SELECT id AS therapist_id FROM therapists WHERE is_generalist = 1
            ) q
        ");
        $ids = [];
        while ($r = $result->fetch_assoc()) $ids[] = intval($r['therapist_id']);
        return $ids;
    }

    // ── Get on-duty qualified therapist IDs for today ─────────────────────────
    public function getOnDutyQualifiedIds(int $service_id): array {
        $all_ids = $this->getQualifiedTherapistIds($service_id);
        if (empty($all_ids)) return [];
        $ids_str = implode(',', $all_ids);
        $result = $this->conn->query("
            SELECT t.id
            FROM therapists t
            JOIN therapist_attendance ta ON ta.therapist_id = t.id
            WHERE ta.duty_date = CURDATE()
              AND ta.time_out IS NULL
              AND t.id IN ($ids_str)
        ");
        $ids = [];
        while ($r = $result->fetch_assoc()) $ids[] = intval($r['id']);
        return $ids;
    }

    // ── Count therapists free at a specific datetime ──────────────────────────
    public function countFreeTherapists(
        array  $therapist_ids,
        string $datetime,
        int    $session_time,
        string $rate_type = 'regular',
        int    $exclude_appt_id = 0
    ): int {
        if (empty($therapist_ids)) return 0;
        $ids_str = implode(',', $therapist_ids);
        $buffer  = ($rate_type === 'home') ? 30 : 0;
        $dt_esc  = $this->conn->real_escape_string($datetime);
        $new_end_mins   = $session_time + $buffer;

        $exclude_clause = $exclude_appt_id > 0
            ? "AND a2.id != $exclude_appt_id"
            : "";

        $result = $this->conn->query("
            SELECT t.id,
                   (SELECT COUNT(*)
                    FROM appointment_therapists at2
                    JOIN appointments a2 ON at2.appointment_id = a2.id
                    JOIN services     s2 ON a2.service_id = s2.id
                    WHERE at2.therapist_id = t.id
                      AND a2.status IN ('pending','approved','assigned')
                      $exclude_clause
                      AND DATE(a2.appointment_date) = DATE('{$dt_esc}')
                      AND (a2.appointment_date - INTERVAL IF(a2.service_type='home',30,0) MINUTE)
                            < ('{$dt_esc}' + INTERVAL {$new_end_mins} MINUTE)
                      AND (a2.appointment_date + INTERVAL ((s2.session_time * IFNULL(at2.people_handled,1)) + IF(a2.service_type='home',30,0)) MINUTE)
                            > ('{$dt_esc}' - INTERVAL {$buffer} MINUTE)
                   ) AS is_busy
            FROM therapists t
            WHERE t.id IN ($ids_str)
        ");

        $free = 0;
        while ($r = $result->fetch_assoc()) {
            if ((int)$r['is_busy'] === 0) $free++;
        }
        return $free;
    }

    // ── Check if a specific slot is available ────────────────────────────────
    public function checkSlot(
        int    $service_id,
        string $datetime,
        int    $people_count = 1,
        string $rate_type    = 'regular',
        int    $exclude_appt_id = 0,
        int    $preferred_therapist_id = 0
    ): array {
        $svc = $this->getService($service_id);
        if (!$svc) return ['available' => false, 'reason' => 'Service not found'];

        $session_time = intval($svc['session_time'] ?? 60);
        $now          = new DateTime('now', $this->tz);
        $slot_dt      = new DateTime($datetime, $this->tz);
        $today_str    = $now->format('Y-m-d');
        $slot_date    = $slot_dt->format('Y-m-d');
        $is_today     = ($slot_date === $today_str);

        // ── Check if within operating hours ──────────────────────────────────
        // CLOSE_HOUR is the last allowed START hour; sessions may run past it.
        // At exactly CLOSE_HOUR, only minute :00 is valid (matches the picker rule).
        $slot_hour = (int)$slot_dt->format('H');
        $slot_min  = (int)$slot_dt->format('i');
        if ($slot_hour < self::OPEN_HOUR || $slot_hour > self::CLOSE_HOUR) {
            return ['available' => false, 'reason' => 'Outside operating hours (10AM–8PM)'];
        }
        if ($slot_hour === self::CLOSE_HOUR && $slot_min > 0) {
            return ['available' => false, 'reason' => 'Last bookable start time is 8:00 PM'];
        }

        // ── Past time check (same day) ────────────────────────────────────────
        if ($is_today && $slot_dt <= $now) {
            return ['available' => false, 'reason' => 'Time has already passed'];
        }

        // ── No specialty therapist exists at all ──────────────────────────────
        if (!$this->serviceHasTherapist($service_id)) {
            return ['available' => false, 'reason' => 'Currently unavailable — no qualified therapist'];
        }

        // ── Determine which therapist pool to use ─────────────────────────────
        $pre_open_ts = mktime(
            self::OPEN_HOUR - self::PRE_OPEN_HOURS, 0, 0,
            (int)$now->format('m'), (int)$now->format('d'), (int)$now->format('Y')
        );
        $use_today_roster = $is_today && (time() >= $pre_open_ts);

        if ($use_today_roster) {
            // Same-day: use on-duty therapists only
            $therapist_ids = $this->getOnDutyQualifiedIds($service_id);
            if (empty($therapist_ids)) {
                return ['available' => false, 'reason' => 'No qualified therapist on duty today'];
            }
        } else {
            // Future: use all therapists with specialty
            $therapist_ids = $this->getQualifiedTherapistIds($service_id);
        }

        // ── Filter to preferred therapist if requested ───────────────────────
        if ($preferred_therapist_id > 0) {
            if (!in_array($preferred_therapist_id, $therapist_ids)) {
                $label = $use_today_roster ? 'not on duty today' : 'not available for this service';
                return ['available' => false, 'reason' => "Selected therapist is $label"];
            }
            $therapist_ids = [$preferred_therapist_id];
        }

        // ── Count free therapists at this slot ───────────────────────────────
        // Pass total window = session_time × people_count (back-to-back model)
        $free = $this->countFreeTherapists(
            $therapist_ids, $datetime, $session_time * $people_count, $rate_type, $exclude_appt_id
        );

        if ($free === 0) {
            return ['available' => false, 'reason' => 'No therapist available at this time', 'free' => 0, 'needed' => $people_count];
        }

        if ($free < $people_count) {
            return [
                'available'      => true, // warning, not hard block
                'warning'        => true,
                'reason'         => "Only {$free} therapist(s) available for {$people_count} people — some may need to wait",
                'free'           => $free,
                'needed'         => $people_count,
            ];
        }

        return [
            'available' => true,
            'warning'   => false,
            'free'      => $free,
            'needed'    => $people_count,
        ];
    }

    // ── Get all available slots for a service on a given date ─────────────────
    public function getAvailableSlots(
        int    $service_id,
        string $date,
        int    $people_count  = 1,
        string $rate_type     = 'regular',
        int    $preferred_therapist_id = 0
    ): array {
        $svc = $this->getService($service_id);
        if (!$svc) return [];

        $session_time = intval($svc['session_time'] ?? 60);
        $slots = [];

        // Generate slots from 10AM to 10PM based on session_time interval
        $open  = new DateTime("{$date} " . sprintf('%02d:00', self::OPEN_HOUR), $this->tz);
        $close = new DateTime("{$date} " . sprintf('%02d:00', self::CLOSE_HOUR), $this->tz);

        $current = clone $open;
        while ($current < $close) {
            // Check if session fits before closing
            $end = clone $current;
            $end->modify("+{$session_time} minutes");
            if ($end > $close) break;

            $slot_str = $current->format('Y-m-d H:i:s');
            $check    = $this->checkSlot($service_id, $slot_str, $people_count, $rate_type, 0, $preferred_therapist_id);

            $slots[] = [
                'time'      => $current->format('H:i'),
                'time_label'=> $current->format('h:i A'),
                'datetime'  => $current->format('Y-m-d\TH:i'),
                'status'    => $check['available'] ? ($check['warning'] ?? false ? 'warning' : 'available') : 'unavailable',
                'reason'    => $check['reason'] ?? null,
                'free'      => $check['free'] ?? 0,
                'needed'    => $people_count,
                'is_past'   => ($current <= new DateTime('now', $this->tz)),
            ];

            $current->modify("+{$session_time} minutes");
        }

        return $slots;
    }

    // ── Get next available slot after a given datetime ────────────────────────
    public function getNextAvailableSlot(
        int    $service_id,
        string $after_datetime,
        int    $people_count = 1,
        string $rate_type    = 'regular'
    ): ?array {
        $svc = $this->getService($service_id);
        if (!$svc) return null;
        $session_time = intval($svc['session_time'] ?? 60);

        $after = new DateTime($after_datetime, $this->tz);
        // Look up to 7 days ahead
        for ($day = 0; $day < 7; $day++) {
            $date_str = (clone $after)->modify("+{$day} days")->format('Y-m-d');
            $slots    = $this->getAvailableSlots($service_id, $date_str, $people_count, $rate_type);
            foreach ($slots as $slot) {
                if ($slot['status'] === 'available' || $slot['status'] === 'warning') {
                    $slot_dt = new DateTime($slot['datetime'], $this->tz);
                    if ($slot_dt > $after) return $slot;
                }
            }
        }
        return null;
    }

    // ── Get busy windows for a specific therapist on a given date ─────────────
    // Returns array of ['start' => 'HH:MM', 'end' => 'HH:MM'] (sorted by start)
    public function getBusyWindowsForTherapist(int $therapist_id, string $date): array {
        $date_esc = $this->conn->real_escape_string($date);
        $result   = $this->conn->query("
            SELECT a.appointment_date, s.session_time AS svc_st,
                   a.service_type, IFNULL(at2.people_handled, 1) AS ph
            FROM appointment_therapists at2
            JOIN appointments a ON at2.appointment_id = a.id
            JOIN services     s ON a.service_id       = s.id
            WHERE at2.therapist_id = $therapist_id
              AND a.status IN ('pending','approved','assigned')
              AND DATE(a.appointment_date) = '$date_esc'
            ORDER BY a.appointment_date
        ");
        $windows = [];
        while ($r = $result->fetch_assoc()) {
            $buf    = ($r['service_type'] === 'home') ? 30 : 0;
            $dt     = new DateTime($r['appointment_date'], $this->tz);
            $appt_m = (int)$dt->format('H') * 60 + (int)$dt->format('i');
            $ws_m   = $appt_m - $buf;
            $we_m   = $appt_m + intval($r['svc_st']) * intval($r['ph']) + $buf;
            $windows[] = [
                'start' => sprintf('%02d:%02d', intdiv($ws_m, 60), $ws_m % 60),
                'end'   => sprintf('%02d:%02d', intdiv($we_m, 60), $we_m % 60),
            ];
        }
        return $windows;
    }

    // ── Get combined blocked windows for any-available mode ───────────────────
    // A window is "blocked" only when EVERY qualified therapist is occupied.
    public function getBusyWindowsAnyAvailable(
        array  $therapist_ids,
        string $date,
        int    $session_time,
        int    $buffer
    ): array {
        if (empty($therapist_ids)) return [];

        $open_m  = self::OPEN_HOUR  * 60;
        $max_end = 22 * 60; // sessions may run until 10 PM

        // Per-therapist free intervals (minutes since midnight)
        $all_free = [];
        foreach ($therapist_ids as $tid) {
            $busy = [];
            foreach ($this->getBusyWindowsForTherapist($tid, $date) as $w) {
                [$h, $m] = array_map('intval', explode(':', $w['start']));
                $s = $h * 60 + $m;
                [$h, $m] = array_map('intval', explode(':', $w['end']));
                $e = $h * 60 + $m;
                $busy[] = [$s, $e];
            }
            usort($busy, fn($a, $b) => $a[0] - $b[0]);

            $cur = $open_m;
            foreach ($busy as [$bs, $be]) {
                if ($bs > $cur) $all_free[] = [$cur, $bs];
                $cur = max($cur, $be);
            }
            if ($cur < $max_end) $all_free[] = [$cur, $max_end];
        }

        // Union of all free intervals
        usort($all_free, fn($a, $b) => $a[0] - $b[0]);
        $mfree = [];
        foreach ($all_free as [$s, $e]) {
            if (empty($mfree) || $s > $mfree[count($mfree)-1][1]) {
                $mfree[] = [$s, $e];
            } else {
                $mfree[count($mfree)-1][1] = max($mfree[count($mfree)-1][1], $e);
            }
        }

        // Blocked = [open_m, max_end] MINUS merged free
        $blocked = [];
        $cur = $open_m;
        foreach ($mfree as [$s, $e]) {
            if ($s > $cur) $blocked[] = [$cur, $s];
            $cur = max($cur, $e);
        }
        if ($cur < $max_end) $blocked[] = [$cur, $max_end];

        $out = [];
        foreach ($blocked as [$s, $e]) {
            $out[] = [
                'start' => sprintf('%02d:%02d', intdiv($s, 60), $s % 60),
                'end'   => sprintf('%02d:%02d', intdiv($e, 60), $e % 60),
            ];
        }
        return $out;
    }
}