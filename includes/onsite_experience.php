<?php
/**
 * Optional "on-site experience" a host can add to a listing: a tour guide or an attraction that is
 * INSIDE the property (for example a guided farm walk or a private nature trail).
 * Only this fee is ever added to a booking total. Public attraction entry fees (parks, museums, reserves)
 * are paid at the gate and are never added by this website.
 * Same as database/migration_onsite_experience.sql; the columns are created automatically if missing.
 */
if (!function_exists('ensureOnsiteExperienceColumns')) {
    function ensureOnsiteExperienceColumns($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accommodations'
                                AND COLUMN_NAME = 'onsite_experience_fee'")->fetchColumn();
            if (!(int)$has) {
                $pdo->exec("ALTER TABLE accommodations
                            ADD COLUMN onsite_experience_name VARCHAR(120) NULL DEFAULT NULL,
                            ADD COLUMN onsite_experience_fee DECIMAL(10,2) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) { /* run database/migration_onsite_experience.sql by hand */ }
    }
}

if (!function_exists('saveOnsiteExperience')) {
    /** Reads onsite_name / onsite_fee from the host form and saves them (both empty = no on-site experience). */
    function saveOnsiteExperience($pdo, $accommodation_id, array $post) {
        ensureOnsiteExperienceColumns($pdo);
        $name = trim((string)($post['onsite_name'] ?? ''));
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 120, 'UTF-8') : substr($name, 0, 120);
        $fee  = (float)($post['onsite_fee'] ?? 0);
        if ($name === '' || $fee <= 0) { $name = null; $fee = null; }
        else { $fee = min($fee, 100000); }
        try {
            $pdo->prepare("UPDATE accommodations SET onsite_experience_name = ?, onsite_experience_fee = ? WHERE accommodation_id = ?")
                ->execute([$name, $fee, (int)$accommodation_id]);
        } catch (Throwable $e) {}
    }
}

if (!function_exists('onsiteExperienceFormHtml')) {
    function onsiteExperienceFormHtml($name, $fee) {
        $name = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
        $fee  = ($fee !== null && $fee !== '' && (float)$fee > 0) ? (string)(float)$fee : '';
        return '<div class="form-group" style="border:1px solid #dfe7e3;border-radius:8px;padding:16px;margin:18px 0;background:#fafcfb">'
            . '<label style="font-weight:600">Tour guide or attraction inside your property (optional)</label>'
            . '<p style="font-size:13px;color:#4f5f57;margin:4px 0 12px;line-height:1.5">Only fill this in if you offer something of your own on the property, '
            . 'like a guided farm walk or a private nature trail. The fee is added to the guest&rsquo;s total, once per guest. '
            . 'Do <strong>not</strong> enter fees for national parks, museums or other public places nearby. '
            . 'Those belong to the government or the site owner, and guests pay them at the gate.</p>'
            . '<input aria-label="Name of the tour or guide you offer" type="text" name="onsite_name" maxlength="120" value="' . $name . '" placeholder="e.g. Guided farm walk with our resident guide" style="width:100%;margin-bottom:8px">'
            . '<input aria-label="Fee per guest (KES)" type="number" name="onsite_fee" min="0" max="100000" step="50" value="' . $fee . '" placeholder="Fee per guest (KES)" style="width:100%">'
            . '</div>';
    }
}

if (!function_exists('bookingPriceBreakdown')) {
    /**
     * Splits a saved booking total into "nights x price" and the host's on-site tour/guide fee.
     * The extra is worked out from the SAVED total, so it stays correct even if the host later changes the fee.
     * Expects the booking row to include price_per_night, check_in_date, check_out_date, total_amount,
     * num_guests and (optionally) onsite_experience_name.
     */
    function bookingPriceBreakdown(array $b) {
        $nights   = max(0, (int)(new DateTime($b['check_in_date']))->diff(new DateTime($b['check_out_date']))->days);
        $stay     = (float)$b['price_per_night'] * $nights;
        $fee      = max(0.0, (float)($b['service_fee'] ?? 0));
        $extra    = max(0.0, round((float)$b['total_amount'] - $fee - $stay, 2));
        $guests   = max(1, (int)($b['num_guests'] ?? 1));
        $name     = trim((string)($b['onsite_experience_name'] ?? ''));
        if ($name === '') $name = 'Tour / guide offered by your host';
        return ['nights' => $nights, 'stay' => $stay, 'service_fee' => $fee, 'extra' => $extra, 'extra_name' => $name,
                'extra_per_guest' => $extra / $guests, 'guests' => $guests];
    }
}

if (!function_exists('bookingExtraNoteHtml')) {
    function bookingExtraNoteHtml(array $br) {
        if ($br['extra'] <= 0) return '';
        return '<div style="font-size:12px;color:#4f5f57;line-height:1.5;margin:8px 0 0">The extra KES ' . number_format($br['extra'])
            . ' is for <strong>' . htmlspecialchars($br['extra_name'], ENT_QUOTES, 'UTF-8')
            . '</strong>, a tour or guide your host offers at the stay. It is charged per guest and is not part of the nightly price. '
            . 'Public attraction entry fees are never included.</div>';
    }
}
