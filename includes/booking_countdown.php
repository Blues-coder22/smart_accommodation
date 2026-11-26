<?php
/**
 * Booking countdown (v9.33): a friendly "days to go" card for a confirmed booking.
 * Counts down to the start of the check-in day, Kenya time (Africa/Nairobi). The numbers tick live in the browser;
 * the message changes with how close the trip is. Bookings still waiting on the host or the payment (pending, accepted, payment pending) get a short note instead.
 */
if (!function_exists('bookingCountdownMessage')) {
    /** Returns [emoji, message] for the number of whole days left. */
    function bookingCountdownMessage($days) {
        $days = (int)$days;
        if ($days <= 0)  return ['🎉', 'Today’s the day! Safari njema!'];
        if ($days === 1) return ['🌙', 'Tomorrow is the day! Pack tonight and sleep early.'];
        if ($days <= 6)  return ['🗺️', 'Almost there! Check your directions and say hello to your host.'];
        if ($days <= 13) return ['🧴', 'One week to go. Sunscreen, a hat and your camera!'];
        if ($days <= 29) return ['🧳', 'Two weeks or so. Time to start packing.'];
        if ($days <= 59) return ['🦒', 'Less than two months. Have a look at the attractions near your stay.'];
        return ['✨', 'Plenty of time to dream. Start a list of what you want to see.'];
    }
}

if (!function_exists('bookingCountdownHtml')) {
    function bookingCountdownHtml($checkIn, $status) {
        static $assets = false;
        $status = (string)$status;
        if ($status === 'pending') {
            return '<div class="bk-cd bk-cd-wait"><span aria-hidden="true">⏳</span> <span>Your countdown starts once the host confirms.</span></div>';
        }
        // Accepted (waiting for the guest's payment) or payment reported (waiting for the host to confirm it).
        if ($status === 'accepted' || $status === 'payment_pending') {
            return '<div class="bk-cd bk-cd-wait"><span aria-hidden="true">⏳</span> <span>Your countdown starts once the host confirms your payment.</span></div>';
        }
        if ($status !== 'confirmed') return '';
        try {
            $tz = new DateTimeZone('Africa/Nairobi');
            $today = (new DateTime('now', $tz))->format('Y-m-d');
            $target = new DateTime($checkIn . ' 00:00:00', $tz);
        } catch (Throwable $e) { return ''; }
        $days = (int)round((strtotime($checkIn . ' 00:00:00 UTC') - strtotime($today . ' 00:00:00 UTC')) / 86400);
        if ($days < 0) return '';
        list($emoji, $msg) = bookingCountdownMessage($days);

        $html = '';
        if (!$assets) {
            $assets = true;
            $html .= '<style>
.bk-cd{margin:12px 0 0;padding:12px 14px;border-radius:12px;background:#eef7f1;border:1px solid #cfe5d8;color:#1d3a2c;font-size:14px}
.bk-cd-msg{margin:0 0 8px;font-weight:600}
.bk-cd-boxes{display:flex;gap:8px}
.bk-cd-boxes div{min-width:56px;text-align:center;background:#fff;border:1px solid #cfe5d8;border-radius:10px;padding:6px 8px}
.bk-cd-boxes b{display:block;font-size:20px;line-height:1.2;font-variant-numeric:tabular-nums}
.bk-cd-boxes small{font-size:11px;color:#4f5f57}
.bk-cd-wait{background:#fff8e6;border-color:#f0dfae;color:#5c4a12}
.bk-cd-sr{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
@media (prefers-reduced-motion:no-preference){.bk-cd-boxes div:last-child b{animation:bkcdpulse 1s ease-in-out infinite}}
@keyframes bkcdpulse{50%{transform:scale(1.12)}}
html[data-theme="dark"] .bk-cd{background:#16231d;border-color:#2c4437;color:#e8ece9}
html[data-theme="dark"] .bk-cd-boxes div{background:#1c2b24;border-color:#2c4437}
html[data-theme="dark"] .bk-cd-boxes small{color:#a9b8b0}
html[data-theme="dark"] .bk-cd-wait{background:#2a2412;border-color:#5a4c1f;color:#f3e3b0}
</style>';
            $html .= '<script>(function(){function pad(n){return n<10?"0"+n:""+n}function tick(){var els=document.querySelectorAll(".bk-cd[data-cd-target]");for(var i=0;i<els.length;i++){var t=Date.parse(els[i].getAttribute("data-cd-target"));if(isNaN(t))continue;var s=Math.max(0,Math.floor((t-Date.now())/1000));var v={d:Math.floor(s/86400),h:Math.floor(s%86400/3600),m:Math.floor(s%3600/60),s:s%60};for(var k in v){var n=els[i].querySelector("[data-u=\\""+k+"\\"]");if(n)n.textContent=(k==="d")?v[k]:pad(v[k]);}}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){tick();setInterval(tick,1000)})}else{tick();setInterval(tick,1000)}})();</script>';
        }

        $html .= '<div class="bk-cd"' . ($days > 0 ? ' data-cd-target="' . htmlspecialchars($target->format('Y-m-d\TH:i:sP'), ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
        $html .= '<p class="bk-cd-msg"><span aria-hidden="true">' . $emoji . '</span> <span>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</span></p>';
        if ($days > 0) {
            $html .= '<div class="bk-cd-boxes" aria-hidden="true">'
                   . '<div><b data-u="d">' . $days . '</b><small>days</small></div>'
                   . '<div><b data-u="h">00</b><small>hours</small></div>'
                   . '<div><b data-u="m">00</b><small>min</small></div>'
                   . '<div><b data-u="s">00</b><small>sec</small></div>'
                   . '</div>'
                   . '<span class="bk-cd-sr">' . $days . ' <span>days to go</span></span>';
        }
        $html .= '</div>';
        return $html;
    }
}
