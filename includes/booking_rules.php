<?php
/**
 * Booking and payment rules, shown to guests in a pop-up window (so booking pages stay uncluttered)
 * and summarised in booking emails. Change the numbers here and every page and email follows.
 */
require_once __DIR__ . '/commission.php';   // SERVICE_FEE_PERCENT
require_once __DIR__ . '/booking_holds.php';   // bookingHoldHours(), hostReplyHours()

// Free cancellation until this many hours before check-in. Inside the window a refund is up to the host.

if (!function_exists('isLateCancellation')) {
    /** True when the check-in date is less than freeCancelHours() away (check-in counted from 00:00). */
    function isLateCancellation($checkInDate) {
        $ts = strtotime($checkInDate . ' 00:00:00');
        return $ts !== false && ($ts - time()) < freeCancelHours() * 3600;
    }
}

if (!function_exists('bookingRulesList')) {
    /** [heading => text] in plain words. */
    function bookingRulesList() {
        $pay = (int)paymentDeadlineHours(); $reply = (int)hostReplyHours(); $free = (int)freeCancelHours();
        return [
            'Requesting'           => "Sending a booking request holds your dates while your host decides. You are not asked to pay anything at this stage.",
            'Host reply'           => "Your host has $reply hours to accept or decline. If they do not reply, the request is cancelled automatically, the dates are released and you are told. You have not been charged.",
            'Paying'               => "Only after your host accepts you pay them directly, by M-Pesa or bank transfer, using the details they give you. You have $pay hours from acceptance to pay and enter the payment details, or the booking is cancelled and the dates are released. We remind you before the deadline." . (serviceFeePercent() > 0 ? " A service fee of " . pct(serviceFeePercent()) . "% is added to your total and shown before you send your request." : " No fee is added to your total."),
            'Confirmation'         => "StayExplore Kenya does not hold your money. Your booking is confirmed only when your host confirms they received your payment. Entering payment details is not the same as being confirmed. You then get your booking reference and your host's contact details.",
            'Cancelling'           => "You can cancel online until the day of check-in. Cancel at least $free hours before check-in for a free cancellation. Closer than that, any refund of money you have already paid is decided by your host.",
            'If your host cancels' => "You are emailed with the reason. If you had already paid, your host should refund you directly. If they do not, contact us through the report option.",
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
            .bk-rules-link{background:none;border:0;padding:0;color:var(--heading);font:inherit;font-weight:600;text-decoration:underline;cursor:pointer}
            .bk-rules-dialog{border:0;border-radius:12px;padding:22px 26px;max-width:560px;width:calc(100% - 32px);max-height:calc(100vh - 32px);max-height:calc(100dvh - 32px);overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.35);color:var(--ink)}
            .bk-rules-dialog::backdrop{background:rgba(15,25,20,.55)}
            .bk-rules-head{display:flex;justify-content:space-between;align-items:center;gap:12px}
            .bk-rules-head h2{margin:0 0 6px;font-size:22px}
            .bk-rules-x{background:none;border:0;font-size:28px;line-height:1;cursor:pointer;padding:4px 8px}
            .bk-rules-dialog dt{font-weight:700;margin-top:14px}
            .bk-rules-dialog dd{margin:3px 0 0;color:#4a5149;line-height:1.55}
            .bk-rules-ok{margin-top:18px;background:var(--forest);color:#fff;border:0;border-radius:6px;padding:10px 22px;font:inherit;font-weight:600;cursor:pointer}
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
        $pay = (int)paymentDeadlineHours(); $free = (int)freeCancelHours();
        return "<p style='margin-top:18px'><strong>Good to know</strong></p><ul>"
             . "<li>You pay your host only after they accept. Pay by M-Pesa or bank transfer within $pay hours of acceptance, or the dates are released.</li>"
             . "<li>The booking is confirmed when your host confirms the money has really arrived.</li>"
             . "<li>Free cancellation until $free hours before check-in. After that, any refund is up to your host.</li></ul>";
    }
}
