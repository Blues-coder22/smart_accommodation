<?php
/**
 * Availability calendar shared by guests and hosts. Booked nights are shaded:
 *   confirmed booking = terracotta, awaiting confirmation = amber, blocked by the host = grey hatch.
 * Guests see booked and blocked as "Not available"; hosts also see which booked nights are still pending.
 */
if (!function_exists('calendarNightStates')) {
    /** @return array date => 'confirmed'|'pending'|'blocked' for the nights between $from and $to. */
    function calendarNightStates($pdo, $accommodation_id, $from, $to) {
        $states = [];
        $q = $pdo->prepare("SELECT check_in_date, check_out_date, status FROM bookings
                            WHERE accommodation_id = ? AND status IN ('pending','confirmed')
                              AND check_out_date > ? AND check_in_date <= ?");
        $q->execute([(int)$accommodation_id, $from, $to]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $d = new DateTime($b['check_in_date']); $end = new DateTime($b['check_out_date']);
            for (; $d < $end; $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                if ($k < $from || $k > $to) continue;
                if (($states[$k] ?? '') !== 'confirmed') $states[$k] = $b['status'] === 'confirmed' ? 'confirmed' : 'pending';
            }
        }
        $a = $pdo->prepare("SELECT `date` FROM availability WHERE accommodation_id = ? AND is_available = 0 AND `date` BETWEEN ? AND ?");
        $a->execute([(int)$accommodation_id, $from, $to]);
        foreach ($a->fetchAll(PDO::FETCH_COLUMN) as $k) if (!isset($states[$k])) $states[$k] = 'blocked';
        return $states;
    }
}
if (!function_exists('bookingCalendarHtml')) {
    function bookingCalendarHtml(array $states, $months = 6, $showPending = false) {
        $today = date('Y-m-d');
        $out = '<div class="bc" data-bc data-per-view="2">';
        $out .= '<div class="bc-bar"><button type="button" class="bc-nav" data-bc-prev aria-label="Earlier months">&#8249;</button>'
              . '<span class="bc-title" data-bc-title></span>'
              . '<button type="button" class="bc-nav" data-bc-next aria-label="Later months">&#8250;</button></div><div class="bc-months">';
        $m = new DateTime('first day of this month');
        for ($i = 0; $i < $months; $i++, $m->modify('+1 month')) {
            $dim = (int)$m->format('t'); $dow = (int)$m->format('N');
            $out .= '<div class="bc-month" data-label="' . $m->format('F Y') . '"><div class="bc-mname">' . $m->format('F Y') . '</div><div class="bc-grid">';
            foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $l) $out .= '<span class="bc-dow">' . $l . '</span>';
            for ($e = 1; $e < $dow; $e++) $out .= '<span class="bc-day bc-empty"></span>';
            for ($d = 1; $d <= $dim; $d++) {
                $k = $m->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
                $st = $states[$k] ?? '';
                $cls = 'bc-day' . ($k < $today ? ' bc-past' : '') . ($k === $today ? ' bc-today' : '');
                $title = $k;
                if ($st === 'confirmed') { $cls .= ' bc-booked'; $title .= ': booked'; }
                elseif ($st === 'pending') { $cls .= $showPending ? ' bc-pending' : ' bc-booked'; $title .= $showPending ? ': awaiting confirmation' : ': booked'; }
                elseif ($st === 'blocked') { $cls .= ' bc-blocked'; $title .= ': not available'; }
                $out .= '<span class="' . $cls . '" title="' . $title . '">' . $d . '</span>';
            }
            $out .= '</div></div>';
        }
        $out .= '</div><div class="bc-legend"><span><i class="bc-key"></i>Available</span><span><i class="bc-key bc-booked"></i>Booked</span>'
              . ($showPending ? '<span><i class="bc-key bc-pending"></i>Awaiting confirmation</span>' : '')
              . '<span><i class="bc-key bc-blocked"></i>Not available</span></div></div>';
        return $out;
    }
}
