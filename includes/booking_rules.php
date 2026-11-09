<?php
/**
 * Booking and payment rules, shown to guests in a pop-up window (so booking pages stay uncluttered)
 * and summarised in booking emails. Change the numbers here and every page and email follows.
 */
require_once __DIR__ . '/booking_holds.php';   // BOOKING_NO_PAYMENT_HOLD_HOURS, BOOKING_HOST_REPLY_HOURS

// Free cancellation until this many hours before check-in. Inside the window a refund is up to the host.
if (!defined('BOOKING_FREE_CANCEL_HOURS')) define('BOOKING_FREE_CANCEL_HOURS', 48);

if (!function_exists('isLateCancellation')) {
    /** True when the check-in date is less than BOOKING_FREE_CANCEL_HOURS away (check-in counted from 00:00). */
    function isLateCancellation($checkInDate) {
        $ts = strtotime($checkInDate . ' 00:00:00');
        return $ts !== false && ($ts - time()) < BOOKING_FREE_CANCEL_HOURS * 3600;
    }
}

if (!function_exists('bookingRulesList')) {
    /** [heading => text] in plain words. */
    function bookingRulesList() {
        $hold = (int)BOOKING_NO_PAYMENT_HOLD_HOURS; $reply = (int)BOOKING_HOST_REPLY_HOURS; $free = (int)BOOKING_FREE_CANCEL_HOURS;
        return [
            'Reserving'            => "Sending a booking request holds your dates for you straight away. The booking is not final until your host accepts it.",
            'Paying'               => "Bank transfer and M-Pesa: send the money to your host and enter the details on the payment page within $hold hours of reserving, or your request is cancelled and the dates are released. Cash: you pay your host in cash when you check in.",
            'Host reply'           => "Your host has $reply hours to accept or decline. If they do not reply, the request is cancelled automatically and you are told by email.",
            'Payment confirmation' => "The site does not take your money. Your host confirms once they have really received it, and you get an email when they do.",
            'Cancelling'           => "You can cancel online until the day of check-in. Cancel at least $free hours before check-in for a free cancellation. Closer than that, any refund of money you have already paid is decided by your host.",
            'If your host cancels' => "You are emailed with the reason. If you had already paid, your host should refund you. If they do not, contact us through the report option.",
        ];
    }
}

if (!function_exists('bookingRulesDialog')) {
    /** Prints a link plus a pop-up dialog. Safe to call once per page. */
    function bookingRulesDialog($label = 'Booking and payment rules') {
        static $n = 0; $n++;
        $id = 'bkRules' . $n;
        ?>
        <button type="button" class="bk-rules-link" data-rules-open="<?= $id ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
        <dialog id="<?= $id ?>" class="bk-rules-dialog" aria-labelledby="<?= $id ?>Title">
            <div class="bk-rules-head">
                <h2 id="<?= $id ?>Title">Booking and payment rules</h2>
                <button type="button" class="bk-rules-x" data-rules-close aria-label="Close">&times;</button>
            </div>
            <dl>
            <?php foreach (bookingRulesList() as $head => $text): ?>
                <dt><?= htmlspecialchars($head, ENT_QUOTES, 'UTF-8') ?></dt>
                <dd><?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endforeach; ?>
            </dl>
            <div style="text-align:right"><button type="button" class="bk-rules-ok" data-rules-close>Got it</button></div>
        </dialog>
        <?php if ($n === 1): ?>
        <style>
            .bk-rules-link{background:none;border:0;padding:0;color:#0d3b2e;font:inherit;font-weight:600;text-decoration:underline;cursor:pointer}
            .bk-rules-dialog{border:0;border-radius:12px;padding:22px 26px;max-width:560px;width:calc(100% - 32px);max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.35);color:#1f241f}
            .bk-rules-dialog::backdrop{background:rgba(15,25,20,.55)}
            .bk-rules-head{display:flex;justify-content:space-between;align-items:center;gap:12px}
            .bk-rules-head h2{margin:0 0 6px;font-size:22px}
            .bk-rules-x{background:none;border:0;font-size:28px;line-height:1;cursor:pointer;padding:4px 8px}
            .bk-rules-dialog dt{font-weight:700;margin-top:14px}
            .bk-rules-dialog dd{margin:3px 0 0;color:#4a5149;line-height:1.55}
            .bk-rules-ok{margin-top:18px;background:#0d3b2e;color:#fff;border:0;border-radius:6px;padding:10px 22px;font:inherit;font-weight:600;cursor:pointer}
        </style>
        <script>
        document.addEventListener('click', function (e) {
            var o = e.target.closest('[data-rules-open]');
            if (o) { var d = document.getElementById(o.getAttribute('data-rules-open')); if (d && d.showModal) d.showModal(); return; }
            if (e.target.closest('[data-rules-close]')) { var dd = e.target.closest('dialog'); if (dd) dd.close(); return; }
            if (e.target.tagName === 'DIALOG') e.target.close();   // click on the dimmed background
        });
        </script>
        <?php endif;
    }
}

if (!function_exists('bookingRulesEmailHtml')) {
    /** Short version for emails. */
    function bookingRulesEmailHtml() {
        $hold = (int)BOOKING_NO_PAYMENT_HOLD_HOURS; $free = (int)BOOKING_FREE_CANCEL_HOURS;
        return "<p style='margin-top:18px'><strong>Good to know</strong></p><ul>"
             . "<li>Bank transfer or M-Pesa: enter your payment details within $hold hours of reserving, or the dates are released. Cash: pay at check-in.</li>"
             . "<li>Your host confirms when the money has really arrived.</li>"
             . "<li>Free cancellation until $free hours before check-in. After that, any refund is up to your host.</li></ul>";
    }
}
