<?php
/**
 * v9.37: reminder emails for host commission invoices.
 *
 * For every invoice that is not fully paid:
 *   1. "soon"    : N days before the due date (default 2)
 *   2. "today"   : on the due date
 *   3. "overdue" : the day after, then every R days (default 7) until it is paid, at most 10 reminders in all
 * One email per host per run lists every invoice that needs a reminder (no spam when a host has several), and the same
 * message appears in the host's notifications. If an email cannot be sent it is tried again in about 6 hours.
 * Runs from the scheduled maintenance job (cron or the page fallback); safe to run again at any time.
 * Admin settings (Revenue & commission): invoice_reminders (on/off), invoice_reminder_before_days, invoice_reminder_repeat_days.
 */
if (!function_exists('invoiceRemindersOn'))      { function invoiceRemindersOn()      { return siteSettingGet('invoice_reminders', '1') === '1'; } }
if (!function_exists('invoiceReminderBefore'))   { function invoiceReminderBefore()   { return max(0, min(30, (int)siteSettingGet('invoice_reminder_before_days', 2))); } }
if (!function_exists('invoiceReminderRepeat'))   { function invoiceReminderRepeat()   { return max(1, min(60, (int)siteSettingGet('invoice_reminder_repeat_days', 7))); } }

if (!function_exists('ensureInvoiceReminderColumns')) {
    function ensureInvoiceReminderColumns($pdo) {
        static $done = false; if ($done) return; $done = true;
        try {
            $have = $pdo->query("SHOW COLUMNS FROM commission_invoices")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('reminders_sent', $have, true))     $pdo->exec("ALTER TABLE commission_invoices ADD COLUMN reminders_sent TINYINT NOT NULL DEFAULT 0");
            if (!in_array('last_reminder_at', $have, true))   $pdo->exec("ALTER TABLE commission_invoices ADD COLUMN last_reminder_at DATETIME NULL");
            if (!in_array('next_reminder_at', $have, true))   $pdo->exec("ALTER TABLE commission_invoices ADD COLUMN next_reminder_at DATETIME NULL");
        } catch (Throwable $e) { error_log('ensureInvoiceReminderColumns: ' . $e->getMessage()); }
    }
}

if (!function_exists('sendCommissionReminderHostEmail')) {
    /** $items: [['invoice_no','outstanding','due_date','kind'], ...]; $worst: 'overdue' | 'today' | 'soon' */
    function sendCommissionReminderHostEmail($to, $name, array $items, $worst, $blocking) {
        $total = 0.0; $rows = '';
        foreach ($items as $i) {
            $total += (float)$i['outstanding'];
            $when = $i['kind'] === 'overdue' ? 'was due ' . date('j M Y', strtotime($i['due_date'])) . ' (overdue)' : ($i['kind'] === 'today' ? 'due today' : 'due ' . date('j M Y', strtotime($i['due_date'])));
            $rows .= '<tr><td style="padding:6px 12px 6px 0">' . h($i['invoice_no']) . '</td><td style="padding:6px 12px 6px 0">KES ' . number_format((float)$i['outstanding']) . '</td><td style="padding:6px 0">' . h($when) . '</td></tr>';
        }
        $n = count($items);
        if ($worst === 'overdue') { $subject = 'Commission payment overdue — StayExplore Kenya'; $lead = 'Your commission payment to StayExplore Kenya is <strong>overdue</strong>.'; }
        elseif ($worst === 'today') { $subject = 'Commission payment due today — StayExplore Kenya'; $lead = 'A commission payment to StayExplore Kenya is <strong>due today</strong>.'; }
        else { $subject = 'Commission payment due soon — StayExplore Kenya'; $lead = 'A commission payment to StayExplore Kenya is <strong>due soon</strong>.'; }
        $body = '<h2>' . h($subject === '' ? '' : preg_replace('/ — .*/u', '', $subject)) . '</h2><p>Dear ' . h($name) . ',</p><p>' . $lead . ' This is the commission on guest stays where you confirmed the payment.</p>'
              . '<table style="border-collapse:collapse;margin:10px 0">' . $rows . '</table>'
              . '<p><strong>Total to pay: KES ' . number_format($total) . '</strong> (' . $n . ' invoice' . ($n === 1 ? '' : 's') . ')</p>'
              . '<p>How to pay: open your <a href="' . h(appAbsoluteUrl('host/commission.php')) . '">Commission page</a>, pay as shown there, then press &ldquo;Report a payment&rdquo; with your M-Pesa or bank code so we can confirm it.</p>'
              . ($worst === 'overdue' && $blocking ? '<p>Please pay soon: new bookings for your listings may be paused until the overdue amount is settled.</p>' : '')
              . '<p>If you have already paid and reported it, you can ignore this message. We will confirm it shortly. If something looks wrong, reply to this email or use Contact support in your account menu.</p>';
        return sendEmail($to, $subject, $body);
    }
}

if (!function_exists('sendInvoiceReminders')) {
    function sendInvoiceReminders($pdo) {
        $out = ['hosts' => 0, 'invoices' => 0, 'overdue' => 0, 'failed' => 0];
        if (!invoiceRemindersOn()) return $out;
        ensureInvoiceReminderColumns($pdo);
        $before = invoiceReminderBefore(); $repeat = invoiceReminderRepeat();
        try {
            $q = $pdo->prepare("SELECT ci.invoice_id, ci.invoice_no, ci.host_id, ci.amount, ci.paid_amount, ci.due_date, ci.reminders_sent, ci.overdue_notified, u.email, u.full_name
                FROM commission_invoices ci JOIN users u ON u.user_id = ci.host_id
                WHERE ci.status IN ('unpaid','partial') AND ci.due_date <= DATE_ADD(CURDATE(), INTERVAL $before DAY)
                  AND (ci.next_reminder_at IS NULL OR ci.next_reminder_at <= NOW()) AND ci.reminders_sent < 10
                  AND (ci.amount - ci.paid_amount) > 0
                ORDER BY ci.host_id, ci.due_date LIMIT 400");
            $q->execute(); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('sendInvoiceReminders: ' . $e->getMessage()); return $out; }
        $today = date('Y-m-d'); $byHost = [];
        foreach ($rows as $r) {
            $kind = $r['due_date'] < $today ? 'overdue' : ($r['due_date'] === $today ? 'today' : 'soon');
            $byHost[(int)$r['host_id']][] = ['row' => $r, 'kind' => $kind, 'invoice_no' => $r['invoice_no'], 'outstanding' => (float)$r['amount'] - (float)$r['paid_amount'], 'due_date' => $r['due_date']];
        }
        $upd = $pdo->prepare("UPDATE commission_invoices SET reminders_sent = reminders_sent + 1, last_reminder_at = NOW(), next_reminder_at = ?, overdue_notified = IF(?, 1, overdue_notified) WHERE invoice_id = ?");
        $retry = $pdo->prepare("UPDATE commission_invoices SET next_reminder_at = DATE_ADD(NOW(), INTERVAL 6 HOUR) WHERE invoice_id = ?");
        $newOverdue = 0;
        foreach ($byHost as $hostId => $items) {
            $first = $items[0]['row']; $worst = 'soon';
            foreach ($items as $it) { if ($it['kind'] === 'overdue') { $worst = 'overdue'; break; } if ($it['kind'] === 'today') $worst = 'today'; }
            $total = array_sum(array_column($items, 'outstanding'));
            $text = count($items) === 1
                ? 'Invoice ' . $items[0]['invoice_no'] . ' (KES ' . number_format($items[0]['outstanding']) . ') ' . ($worst === 'overdue' ? 'is past its due date.' : ($worst === 'today' ? 'is due today.' : 'is due on ' . date('j M', strtotime($items[0]['due_date'])) . '.'))
                : count($items) . ' commission invoices (KES ' . number_format($total) . ' in all) need payment' . ($worst === 'overdue' ? ', some are overdue.' : '.');
            $title = $worst === 'overdue' ? 'Commission invoice overdue' : ($worst === 'today' ? 'Commission due today' : 'Commission due soon');
            $sent = false;
            if (!emailIsConfigured()) {
                // No mail account set up: the reminder is still shown inside the site, and the schedule moves on (no endless retries).
                $sent = true;
            } else
            try { $sent = (bool)sendCommissionReminderHostEmail($first['email'], $first['full_name'], $items, $worst, function_exists('blockOverdueHosts') && blockOverdueHosts()); } catch (Throwable $e) { error_log('commission reminder email: ' . $e->getMessage()); }
            if ($sent) {
                notify($pdo, $hostId, $title, $text . ' Please pay StayExplore Kenya and report the payment on your Commission page.', 'host/commission.php');
                foreach ($items as $it) {
                    $isOver = $it['kind'] === 'overdue';
                    if ($isOver && !(int)$it['row']['overdue_notified']) $newOverdue++;
                    // soon -> next on the due date; due day -> the day after; overdue -> again after R days
                    $next = $it['kind'] === 'soon' ? $it['due_date'] . ' 00:00:00' : ($it['kind'] === 'today' ? date('Y-m-d 00:00:00', strtotime($today . ' +1 day')) : date('Y-m-d H:i:s', strtotime('+' . $repeat . ' days')));
                    $upd->execute([$next, $isOver ? 1 : 0, (int)$it['row']['invoice_id']]);
                    if ($isOver) $out['overdue']++;
                    $out['invoices']++;
                }
                $out['hosts']++;
            } else {
                $out['failed']++;
                foreach ($items as $it) $retry->execute([(int)$it['row']['invoice_id']]);
                // The email could not go out: still tell the host inside the site, once per invoice.
                foreach ($items as $it) if ($it['kind'] === 'overdue' && !(int)$it['row']['overdue_notified']) {
                    notify($pdo, $hostId, 'Commission invoice overdue', 'Invoice ' . $it['invoice_no'] . ' (KES ' . number_format($it['outstanding']) . ') is past its due date. Please pay StayExplore Kenya and report the payment on your Commission page.', 'host/commission.php');
                    $pdo->prepare('UPDATE commission_invoices SET overdue_notified = 1 WHERE invoice_id = ?')->execute([(int)$it['row']['invoice_id']]);
                    $newOverdue++;
                }
            }
        }
        if ($newOverdue) notifyAdmins($pdo, 'Commission invoices overdue', $newOverdue . ' host commission invoice(s) passed their due date.', 'admin/revenue.php');
        return $out;
    }
}
