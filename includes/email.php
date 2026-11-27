<?php
require_once __DIR__ . '/booking_rules.php';
require_once __DIR__ . '/helpers.php';
// Simple email function using PHPMailer
// Install PHPMailer: composer require phpmailer/phpmailer
// OR download from https://github.com/PHPMailer/PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

// Email settings come from includes/config.php (copy includes/config.example.php to that name and fill it in),
// or from server environment variables of the same names. XAMPP does not load .env files by itself.
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
function mailSetting($name, $default = '') {
    $v = getenv($name);
    if ($v !== false && $v !== '') return $v;
    return defined($name) ? constant($name) : $default;
}

/** True when SMTP settings are present (otherwise emails are only written to logs/mail.log). */
function emailIsConfigured() {
    return mailSetting('SMTP_USERNAME') !== '' && str_replace(' ', '', mailSetting('SMTP_PASSWORD')) !== '';
}

/**
 * Absolute URL of a page in this project, e.g. appAbsoluteUrl('verify.php?token=..').
 * APP_BASE_URL is only trusted when its folder is the folder this copy of the site really runs from. A stale
 * value (for example still pointing at an older copy such as /smart_accommodation) used to put the OLD site's
 * address in every verification email, so the link opened the old site and its old dashboard.
 */
function appAbsoluteUrl($path) {
    $actualPath = function_exists('appBasePath') ? rtrim(appBasePath(), '/') : '/smart_accommodation';
    $baseUrl = '';
    $configuredBase = trim((string)mailSetting('APP_BASE_URL'));
    if ($configuredBase !== '') {
        $configuredPath = rtrim((string)parse_url($configuredBase, PHP_URL_PATH), '/');
        $isWebRequest = PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST']);
        if (!$isWebRequest || strcasecmp($configuredPath, $actualPath) === 0) {
            $baseUrl = rtrim($configuredBase, '/');
        }
    }
    if ($baseUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $scheme . '://' . $host . $actualPath;
    }
    return $baseUrl . '/' . ltrim($path, '/');
}


/**
 * True when email IS set up but this computer cannot reach the mail service (for example no internet).
 * Checked once per page, with a short 4-second wait.
 */
function emailIsOffline() {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!emailIsConfigured()) return $cached = false;   // not set up is a different situation (messages go to logs/mail.log)
    $errno = 0; $errstr = '';
    $fp = @fsockopen(mailSetting('SMTP_HOST', 'smtp.gmail.com'), (int)mailSetting('SMTP_PORT', 587), $errno, $errstr, 4);
    if ($fp) { fclose($fp); return $cached = false; }
    return $cached = true;
}

/** Plain wording for people when an email could not go out because we are offline. */
function emailOfflineMessage() {
    return 'We can’t send emails right now. Please try again when you’re back online. Nothing has changed on your account, and any email we already sent still works.';
}

/**
 * Who hears about things that need an admin (new listing, new report, flagged review...).
 * ADMIN_NOTIFY_EMAIL in includes/config.php wins when it is filled in (several addresses may be separated by commas);
 * otherwise every active, confirmed admin account is used, always with its CURRENT email address.
 * Returns ['email' => 'a@x.com,b@y.com'] or false when there is nobody to tell.
 */
function adminNotificationRecipient($pdo) {
    $emails = [];
    $configured = trim((string)mailSetting('ADMIN_NOTIFY_EMAIL'));
    if ($configured !== '') {
        foreach (preg_split('/[,;\s]+/', $configured) as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[strtolower($e)] = $e;
    }
    if (!$emails) {
        try {
            $rows = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND status = 'active' AND email_verified = 1
                                 AND account_closed_at IS NULL AND account_deleted_at IS NULL ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            $rows = $pdo->query("SELECT email FROM users WHERE role = 'admin' ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
        }
        foreach ($rows as $e) if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[strtolower($e)] = $e;
    }
    return $emails ? ['email' => implode(',', array_values($emails))] : false;
}

/**
 * Sends a fresh verification email WITHOUT breaking the link the person may already have.
 * The old link and code are only replaced once the new email has really gone out.
 * @return string 'sent' | 'offline' | 'failed'
 */
function resendVerificationEmailSafely($pdo, array $user) {
    if (emailIsOffline()) return 'offline';
    $uid = (int)$user['user_id'];
    $old = $pdo->prepare('SELECT verification_token FROM users WHERE user_id = ?');
    $old->execute([$uid]);
    $oldToken = $old->fetchColumn();
    $oldCode = null;
    try {
        $q = $pdo->prepare('SELECT code_hash, expires_at, attempts FROM verify_codes WHERE user_id = ?');
        $q->execute([$uid]);
        $oldCode = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET verification_token = ? WHERE user_id = ?')->execute([$token, $uid]);
    if (sendVerificationEmail($user['email'], $user['full_name'], $token)) return 'sent';

    if (emailIsConfigured()) {          // the email really failed: put the earlier link and code back
        $pdo->prepare('UPDATE users SET verification_token = ? WHERE user_id = ?')->execute([$oldToken ?: null, $uid]);
        try {
            if ($oldCode) $pdo->prepare('REPLACE INTO verify_codes (user_id, code_hash, expires_at, attempts) VALUES (?, ?, ?, ?)')
                               ->execute([$uid, $oldCode['code_hash'], $oldCode['expires_at'], 0]);   // fresh tries: a failed resend must never leave the person locked out
            else $pdo->prepare('DELETE FROM verify_codes WHERE user_id = ?')->execute([$uid]);
        } catch (Throwable $e) {}
    }
    return 'failed';
}

function sendEmail($to, $subject, $body) {
    // Development fallback: no SMTP password configured.
if (mailSetting('SMTP_PASSWORD') === '') {
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $entry = "==== " . date('Y-m-d H:i:s') . " ====\nTo: $to\nSubject: $subject\n"
               . trim(strip_tags(preg_replace('/<a[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', ' $1 ', $body))) . "\n\n";
        @file_put_contents($dir . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
        return false;
    }

    if (emailIsOffline()) {
        error_log('Email not sent (no internet connection): ' . $subject);
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Timeout = 12;
        $smtpHost = mailSetting('SMTP_HOST', 'smtp.gmail.com');
        $smtpUser = mailSetting('SMTP_USERNAME');
        $smtpPass = str_replace(' ', '', mailSetting('SMTP_PASSWORD'));   // Google shows app passwords with spaces
        $fromEmail = mailSetting('SMTP_FROM_EMAIL') ?: $smtpUser;
        $fromName = mailSetting('SMTP_FROM_NAME', 'StayExplore Kenya');

        if ($smtpUser === '' || $smtpPass === '' || $fromEmail === '') {
            error_log('Email is not configured. Fill in includes/config.php (SMTP_USERNAME, SMTP_PASSWORD).');
            // Development fallback: keep the message in logs/mail.log so the verification link can still be found.
            $dir = __DIR__ . '/../logs';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/mail.log', '==== ' . date('Y-m-d H:i:s') . " ====\nTo: $to\nSubject: $subject\n"
                . trim(strip_tags(preg_replace('/<a[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', ' $1 ', $body))) . "\n\n", FILE_APPEND | LOCK_EX);
            return false;
        }

        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)mailSetting('SMTP_PORT', 587);
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($fromEmail, $fromName);
        $any = false;
        foreach ((is_array($to) ? $to : preg_split('/[,;]+/', (string)$to)) as $addr) {
            $addr = trim($addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL)) { $mail->addAddress($addr); $any = true; }
        }
        if (!$any) return false;
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
        return false;
    }
}

// Email templates
function sendVerificationEmail($to, $name, $token) {
    $link = appAbsoluteUrl('verify.php?token=' . rawurlencode($token));
    $name = h($name);
    // A 6-digit code as well as the link: it can be typed on the device that is waiting, so verifying
    // does not depend on the phone being able to open this site's address.
    $codeHtml = '';
    global $pdo;
    try {
        if (isset($pdo)) {
            require_once __DIR__ . '/verify_wait.php';
            $q = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
            $q->execute([$to]);
            $uid = (int)$q->fetchColumn();
            if ($uid > 0) {
                $code = issueVerifyCode($pdo, $uid);
                $codeHtml = "
        <p style='margin-top:24px'><strong>Option 2: use the 6-digit code.</strong> If the link does not open, type this code on the page where you signed up:</p>
        <p style='font-size:30px;font-weight:bold;letter-spacing:8px;background:#f4f8f5;border:1px solid #cfdcd5;
           border-radius:8px;display:inline-block;padding:10px 18px;margin:4px 0'>$code</p>
        <p style='color:#666;font-size:13px'>The code works for 30 minutes.</p>";
            }
        }
    } catch (Throwable $e) { error_log('Verification code not created: ' . $e->getMessage()); }
    $body = "
        <h2>Welcome to StayExplore Kenya, $name!</h2>
        <p>Thanks for signing up. Please verify your email address to get started.</p>
        <p><strong>Option 1: use the link.</strong></p>
        <a href='$link' style='background:#126b4e;color:white;
           padding:10px 20px;text-decoration:none;border-radius:5px'>
           Verify my email
        </a>
        $codeHtml
        <p>If you did not create this account, you can ignore this email.</p>
    ";
    return sendEmail($to, 'Verify your email — StayExplore Kenya', $body);
}

function sendBookingConfirmationEmail($to, $name, $booking) {
    $ref = bookingLabel($booking['booking_id']);
    $body = "
        <h2>Booking Confirmed!</h2>
        <p>Dear $name,</p>
        <p>Your stay is reserved. Here are your details:</p>
        <table border='1' cellpadding='8'>
            <tr><td>Booking ID</td><td>$ref</td></tr>
            <tr><td>Property</td><td>" . h($booking['title']) . "</td></tr>
            <tr><td>Check-in</td><td>{$booking['check_in_date']}</td></tr>
            <tr><td>Check-out</td><td>{$booking['check_out_date']}</td></tr>
            <tr><td>Total</td><td>KES " . number_format($booking['total_amount']) . "</td></tr>
        </table>
        <p>Payment is made directly to the host (cash, bank transfer or M-Pesa). You will get another
           email when the host confirms they have received it.</p>
        " . bookingRulesEmailHtml() . "
        <p>Thank you for using StayExplore Kenya.</p>
    ";
    return sendEmail($to, 'Booking Confirmed — StayExplore Kenya', $body);
}

function sendNewBookingHostEmail($to, $name, $booking) {
    $ref = bookingLabel($booking['booking_id']);
    $body = "
        <h2>New booking request</h2>
        <p>Dear $name,</p>
        <p>A guest has asked to book:</p>
        <table border='1' cellpadding='8'>
            <tr><td>Booking ID</td><td>$ref</td></tr>
            <tr><td>Property</td><td>{$booking['title']}</td></tr>
            <tr><td>Check-in</td><td>{$booking['check_in_date']}</td></tr>
            <tr><td>Check-out</td><td>{$booking['check_out_date']}</td></tr>
            <tr><td>Total</td><td>KES " . number_format($booking['total_amount']) . "</td></tr>
        </table>
        <p>Open <strong>Bookings</strong> in your host area to <strong>accept or decline</strong> this request. The guest is asked to pay you only after you accept.</p>
    ";
    return sendEmail($to, 'New booking request — StayExplore Kenya', $body);
}

// Sent to the tourist when they submit their payment details (not currently used at checkout:
// the booking-confirmed email already covers it), kept for completeness.
function sendPaymentConfirmationEmail($to, $name, $booking, $method = '', $reference = '', $hostName = '') {
    $ref = bookingLabel($booking['booking_id']);
    $hostAmt = 'KES ' . number_format((float)$booking['total_amount'], 2);
    $platAmt = (float)($booking['platform_amount'] ?? 0);
    $hostAmt .= emailApprox($booking['booking_id'], $booking['total_amount']);
    $legs = "<strong>$hostAmt sent to " . h($hostName !== '' ? $hostName : 'your host') . "</strong>"
          . ($platAmt > 0 ? " and <strong>KES " . number_format($platAmt, 2) . " sent to StayExplore Kenya</strong>" : "");
    $body = "
        <h2>Payment sent</h2>
        <p>Dear " . h($name) . ",</p>
        <p style='font-size:18px'>$legs for " . h($booking['title']) . " (booking $ref) on " . date('j M Y \\a\\t H:i') . ".</p>
        <table border='1' cellpadding='8'>
            <tr><td>To your host</td><td>$hostAmt" . ($method !== '' ? " &middot; " . h(paymentMethodLabel($method)) : "") . ($reference !== '' ? " &middot; ref " . h($reference) : "") . "</td></tr>
            " . ($platAmt > 0 ? "<tr><td>To StayExplore Kenya</td><td>KES " . number_format($platAmt, 2) . " &middot; ref " . h((string)($booking['platform_reference'] ?? '')) . "</td></tr>" : "") . "
            <tr><td>Check-in</td><td>" . h($booking['check_in_date']) . "</td></tr>
            <tr><td>Status</td><td>Waiting for the money to be confirmed received</td></tr>
        </table>
        <p>This is not a receipt yet. You will get an email each time a payment is confirmed (your host's, then StayExplore Kenya's), with your receipt.</p>
        " . bookingRulesEmailHtml() . "
        <p>Thank you for using StayExplore Kenya.</p>
    ";
    return sendEmail($to, 'Payment sent for booking ' . $ref . ' — awaiting confirmation', $body);
}

// Sent to the tourist when the host marks a payment as actually received.
function sendPaymentVerifiedEmail($to, $name, $booking, $hostName = '', $reference = '', $paymentId = 0) {
    $ref = bookingLabel($booking['booking_id']);
    $amount = 'KES ' . number_format((float)$booking['total_amount'], 2);
    $link = $paymentId ? "<p><a href='" . h(appAbsoluteUrl('receipt.php?as=tourist&pid=' . opaqueId('pay', $paymentId))) . "'>View or print your receipt</a></p>" : '';
    $body = "
        <h2>Payment confirmed</h2>
        <p>Dear " . h($name) . ",</p>
        <p style='font-size:18px'><strong>$amount received</strong>" . emailApprox($booking['booking_id'], $booking['total_amount']) . " by " . h($hostName !== '' ? $hostName : 'your host') . " for " . h($booking['title']) . " (booking $ref).</p>
        " . ($reference !== '' ? "<p><strong>Reference:</strong> " . h($reference) . "</p>" : "") . "
        <p><strong>Confirmed:</strong> " . date('j M Y \\a\\t H:i') . "</p>
        $link
        <p>Keep your receipt in case you ever need to show proof of payment. Thank you for using StayExplore Kenya.</p>
    ";
    return sendEmail($to, $amount . ' received — payment confirmed', $body);
}

function sendListingApprovedEmail($to, $name, $listing_title) {
    $body = "
        <h2>Listing Approved!</h2>
        <p>Dear $name,</p>
        <p>Your listing <strong>$listing_title</strong> has been 
           approved and is now visible to tourists.</p>
        <p>Log in to your host dashboard to manage your listing.</p>
    ";
    return sendEmail($to, 'Listing Approved — StayExplore Kenya', $body);
}

function sendListingRejectedEmail($to, $name, $listing_title, $reason) {
    $body = "
        <h2>Listing Not Approved</h2>
        <p>Dear $name,</p>
        <p>Your listing <strong>$listing_title</strong> was not approved.</p>
        <p><strong>Reason:</strong> $reason</p>
        <p>Please update your listing and resubmit for review.</p>
    ";
    return sendEmail($to, 'Listing Update Required — StayExplore Kenya', $body);
}

function sendIncidentReportEmail($admin_email, $reporter_name, 
                                  $incident_type, $report_id) {
    $body = "
        <h2>Critical Incident Report Received</h2>
        <p>A critical incident report has been submitted.</p>
        <table border='1' cellpadding='8'>
            <tr><td>Report ID</td><td>#RPT$report_id</td></tr>
            <tr><td>Reported by</td><td>$reporter_name</td></tr>
            <tr><td>Incident type</td><td>$incident_type</td></tr>
        </table>
        <p>Please log in to the admin panel to review this report immediately.</p>
    ";
    return sendEmail($admin_email, 
                     'Critical Incident Report — Immediate Review Required', 
                     $body);
}

// ---- Payment / booking-hold notices -------------------------------------------------------------------------------
function paymentMethodLabel($m) {
    return ['cash' => 'Cash (pay on arrival)', 'bank_transfer' => 'Bank transfer', 'mpesa' => 'M-Pesa'][$m] ?? ucfirst((string)$m);
}

// To the HOST when a guest submits payment details: tells them to check their account and mark it received.
function sendPaymentSubmittedHostEmail($to, $hostName, $booking, $method, $reference) {
    $ref = bookingLabel($booking['booking_id']);
    $refLine = ($reference !== null && $reference !== '') ? '<tr><td>Transaction reference</td><td>' . h($reference) . '</td></tr>' : '';
    $body = "
        <h2>A guest has submitted payment details</h2>
        <p>Hello " . h($hostName) . ",</p>
        <p>The guest says they have paid for booking $ref. This is <strong>not</strong> a confirmation that the money has arrived.</p>
        <table border='1' cellpadding='8'>
            <tr><td>Property</td><td>" . h($booking['title']) . "</td></tr>
            <tr><td>Check-in</td><td>" . h($booking['check_in_date']) . "</td></tr>
            <tr><td>Check-out</td><td>" . h($booking['check_out_date']) . "</td></tr>
            <tr><td>Amount</td><td>KES " . number_format($booking['total_amount']) . "</td></tr>
            <tr><td>Method</td><td>" . h(paymentMethodLabel($method)) . "</td></tr>
            $refLine
        </table>
        <p>Check your M-Pesa or bank account. When the money has really arrived, open <strong>Payments</strong> in your host area
           and choose <strong>Mark as paid</strong>; the guest is then emailed automatically. Until then the booking request stays pending.</p>";
    return sendEmail($to, 'Payment details submitted — check your account — StayExplore Kenya', $body);
}

// To the GUEST when a host reverses a payment that was marked as received.
function sendPaymentReversedEmail($to, $name, $booking, $reason) {
    $ref = bookingLabel($booking['booking_id']);
    $body = "
        <h2>Your payment was set back to awaiting</h2>
        <p>Hello " . h($name) . ",</p>
        <p>The host of <strong>" . h($booking['title']) . "</strong> changed the payment for booking $ref from <em>received</em> to <em>awaiting</em>.</p>
        <p><strong>Reason given by the host:</strong> " . h($reason) . "</p>
        <p>If you did pay, keep your M-Pesa message or bank receipt and report this from the Report page of your account.
           An admin has been told and can review it; a payment confirmed by an admin cannot be undone by the host.</p>";
    return sendEmail($to, 'Your payment status changed — StayExplore Kenya', $body);
}

// To every ADMINISTRATOR when a host reverses a received payment.
function sendPaymentReversedAdminEmail($to, $booking, $hostName, $touristName, $reason) {
    $ref = bookingLabel($booking['booking_id']);
    $body = "
        <h2>A host reversed a received payment</h2>
        <table border='1' cellpadding='8'>
            <tr><td>Booking</td><td>$ref</td></tr>
            <tr><td>Property</td><td>" . h($booking['title']) . "</td></tr>
            <tr><td>Host</td><td>" . h($hostName) . "</td></tr>
            <tr><td>Guest</td><td>" . h($touristName) . "</td></tr>
            <tr><td>Check-in</td><td>" . h($booking['check_in_date']) . "</td></tr>
            <tr><td>Amount</td><td>KES " . number_format($booking['amount']) . "</td></tr>
            <tr><td>Reason given</td><td>" . h($reason) . "</td></tr>
        </table>
        <p>Open <strong>Payments</strong> in the admin area and use the <em>reversed</em> filter to review it.</p>";
    return sendEmail($to, 'Payment reversed by host — review needed — StayExplore Kenya', $body);
}

// When a pending request expires and the dates are released.
function sendBookingExpiredEmail($to, $name, $booking, $why, $forHost = false) {
    $ref = bookingLabel($booking['booking_id']);
    if ($why === 'no_payment') {
        $line = 'Your host accepted this request, but we did not receive your payment details within ' . (int)paymentDeadlineHours() . ' hours of acceptance, so the booking was cancelled and the dates were released for other guests. If you still want the stay, please send a new request.';
    } elseif ($forHost) {
        $line = 'You did not accept or decline this request within ' . (int)hostReplyHours() . ' hours, so it expired and the dates were released. Please reply to requests promptly.';
    } else {
        $line = 'The host did not reply within ' . (int)hostReplyHours() . ' hours, so the request expired and the dates were released. You have not been charged, and nothing is owed. If you already sent money to the host, please ask them for a refund.';
    }
    $body = "
        <h2>Booking request expired</h2>
        <p>Hello " . h($name) . ",</p>
        <p>Your request $ref for <strong>" . h($booking['title']) . "</strong> (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . ") has expired.</p>
        <p>$line</p>";
    return sendEmail($to, 'Booking request expired — StayExplore Kenya', $body);
}

function sendPasswordResetEmail($to, $name, $token) {
    $link = appAbsoluteUrl('reset-password.php?token=' . rawurlencode($token));
    $name = h($name);
    $body = "
        <h2>Reset your password</h2>
        <p>Hello $name,</p>
        <p>We received a request to reset the password for your StayExplore Kenya account. This link works once and
           expires in 60 minutes:</p>
        <p><a href='$link' style='background:#126b4e;color:white;padding:10px 20px;text-decoration:none;border-radius:5px'>Choose a new password</a></p>
        <p>If you did not ask for this, you can ignore this email. Your password will not change.</p>
    ";
    return sendEmail($to, 'Reset your password — StayExplore Kenya', $body);
}

function sendPasswordChangedEmail($to, $name) {
    $name = h($name);
    $body = "
        <h2>Your password was changed</h2>
        <p>Hello $name,</p>
        <p>The password for your StayExplore Kenya account has just been changed. If this was you, no action is needed.
           If it was not, reset your password again straight away and contact the admin.</p>
    ";
    return sendEmail($to, 'Your password was changed — StayExplore Kenya', $body);
}

// ---- Booking reminders, releases and host cancellations (v9.14) ----
// Reminder: the guest has not entered payment details and the dates will be released soon.
function sendPaymentDeadlineReminderEmail($to, $name, $booking, $minutesLeft) {
    $ref = bookingLabel($booking['booking_id']);
    $left = $minutesLeft >= 90 ? 'about ' . round($minutesLeft / 60) . ' hours' : (int)$minutesLeft . ' minutes';
    $body = "<h2>Pay your host soon to keep your dates</h2><p>Dear " . h($name) . ",</p>"
          . "<p>Your host accepted booking <strong>$ref</strong> for <strong>" . h($booking['title']) . "</strong> (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . ").</p>"
          . "<p>You have <strong>$left</strong> left to pay your host and enter the payment details. After that the booking is cancelled and the dates are released to other guests.</p>"
          . "<p><a href='" . h(appAbsoluteUrl('tourist/payment.php?ref=' . bookingRef($booking['booking_id']))) . "'>Go to the payment page</a></p>";
    return sendEmail($to, 'Pay your host to keep your dates — StayExplore Kenya', $body);
}

// Host releases the dates of a pending request (decline), with an optional note.
function sendBookingReleasedEmail($to, $name, $booking, $note = '') {
    $ref = bookingLabel($booking['booking_id']);
    $body = "<h2>Booking request not accepted</h2><p>Dear " . h($name) . ",</p>"
          . "<p>Your host could not accept booking $ref for <strong>" . h($booking['title']) . "</strong> (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . "). The dates have been released.</p>"
          . ($note !== '' ? "<p><strong>Message from your host:</strong> " . nl2br(h($note)) . "</p>" : "")
          . "<p>If you already sent any money, please ask your host to return it. You are welcome to look for another stay.</p>";
    return sendEmail($to, 'Booking request not accepted — StayExplore Kenya', $body);
}

// Host cancels a booking (guest gets the reason).
function sendHostCancelledGuestEmail($to, $name, $booking, $reason, $paymentStatus) {
    $ref = bookingLabel($booking['booking_id']);
    $paid = in_array($paymentStatus, ['confirmed'], true);
    $body = "<h2>Your booking was cancelled by the host</h2><p>Dear " . h($name) . ",</p>"
          . "<p>Your host has cancelled booking $ref for <strong>" . h($booking['title']) . "</strong> (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . ").</p>"
          . "<p><strong>Reason given:</strong> " . nl2br(h($reason)) . "</p>"
          . ($paid ? "<p><strong>You had already paid.</strong> Your host should refund you in full. If they do not, please contact us using the report option so an admin can follow it up.</p>"
                   : "<p>If you sent any money, please ask your host to return it.</p>")
          . "<p>We are sorry about this. You can search for another stay at any time.</p>";
    return sendEmail($to, 'Booking cancelled by the host — StayExplore Kenya', $body);
}

function sendHostCancelledAdminEmail($to, $booking, $hostName, $guestName, $reason, $paymentStatus) {
    $ref = bookingLabel($booking['booking_id']);
    $body = "<h2>A host cancelled a booking</h2>"
          . "<p><strong>Booking:</strong> $ref — " . h($booking['title']) . " (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . ")</p>"
          . "<p><strong>Host:</strong> " . h($hostName) . " &nbsp; <strong>Guest:</strong> " . h($guestName) . "</p>"
          . "<p><strong>Booking status before:</strong> " . h($booking['status']) . " &nbsp; <strong>Payment:</strong> " . h($paymentStatus !== '' ? $paymentStatus : 'none recorded') . "</p>"
          . "<p><strong>Reason given:</strong> " . nl2br(h($reason)) . "</p>";
    return sendEmail($to, 'Host cancelled a booking — StayExplore Kenya', $body);
}

// ---- Commission remittances (v9.18) ----
function sendCommissionRemittanceAdminEmail($to, $hostName, $amount, $reference) {
    $body = "<h2>A host reported a commission payment</h2><p><strong>Host:</strong> " . h($hostName) . "</p>"
          . "<p><strong>Amount:</strong> KES " . number_format((float)$amount, 2) . " &nbsp; <strong>Reference:</strong> " . h($reference) . "</p>"
          . "<p>Please check it against your M-Pesa or bank statement, then accept or reject it under Revenue &amp; commission.</p>";
    return sendEmail($to, 'Commission payment to review — StayExplore Kenya', $body);
}
function sendCommissionDecisionHostEmail($to, $name, $amount, $reference, $accepted, $note = '') {
    $body = "<h2>Commission payment " . ($accepted ? 'received' : 'not accepted') . "</h2><p>Dear " . h($name) . ",</p>"
          . "<p>Your commission payment of KES " . number_format((float)$amount, 2) . " (reference " . h($reference) . ") was <strong>" . ($accepted ? 'received. Thank you.' : 'not accepted.') . "</strong></p>"
          . ($note !== '' ? "<p><strong>Note from the admin:</strong> " . nl2br(h($note)) . "</p>" : "")
          . ($accepted ? "" : "<p>The bookings are back on your outstanding list. You can submit the payment again with the correct reference.</p>");
    return sendEmail($to, 'Commission payment ' . ($accepted ? 'received' : 'not accepted') . ' — StayExplore Kenya', $body);
}

// To the HOST at the moment they confirm a payment: "KES x received from ...", plus what is owed to the platform.
function sendPaymentReceivedHostEmail($to, $hostName, $booking, $guestName, $reference, $commission = 0, $serviceFee = 0) {
    $ref = bookingLabel($booking['booking_id']);
    $amount = 'KES ' . number_format((float)$booking['total_amount'], 2);
    $body = "<h2>Payment confirmed</h2><p>Dear " . h($hostName) . ",</p>"
          . "<p style='font-size:18px'><strong>$amount received from " . h($guestName) . "</strong> for " . h($booking['title']) . " (booking $ref).</p>"
          . ($reference !== '' ? "<p><strong>Reference:</strong> " . h($reference) . "</p>" : "")
          . "<p>The booking is now confirmed and the guest has been told.</p>"
          . "<p>Guests pay you directly. Your agreed platform commission is payable separately to StayExplore Kenya after eligible completed stays; it will appear as an invoice on your Commission page once this stay is completed.</p>";
    return sendEmail($to, 'Payment confirmed: ' . $amount . ' from ' . $guestName, $body);
}

// ---- Platform share (service fee + commission) paid by the guest straight to the admin (v9.21) ----
function sendPlatformPaymentAdminEmail($to, $guestName, $title, $bookingId, $amount, $reference) {
    $ref = bookingLabel($bookingId);
    $body = "<h2>Payment to StayExplore Kenya to check</h2><p><strong>" . h($guestName) . "</strong> reports paying <strong>KES " . number_format((float)$amount, 2) . "</strong> for booking $ref (" . h($title) . ").</p>"
          . "<p><strong>Reference:</strong> " . h($reference) . "</p>"
          . "<p>Compare it with your M-Pesa or bank statement, then accept or reject it under Revenue &amp; commission.</p>";
    return sendEmail($to, 'KES ' . number_format((float)$amount, 2) . ' reported — check your account', $body);
}
function sendPlatformPaymentDecisionGuestEmail($to, $name, $bookingId, $amount, $reference, $accepted, $note = '', $receiptUrl = '') {
    $ref = bookingLabel($bookingId); $amt = 'KES ' . number_format((float)$amount, 2);
    $body = "<h2>" . ($accepted ? 'Payment received' : 'Payment not accepted') . "</h2><p>Dear " . h($name) . ",</p>"
          . "<p style='font-size:18px'><strong>$amt" . emailApprox($bookingId, $amount) . " " . ($accepted ? 'received by StayExplore Kenya' : 'could not be matched') . "</strong> for booking $ref (reference " . h($reference) . ").</p>"
          . ($note !== '' ? "<p><strong>Note from the admin:</strong> " . nl2br(h($note)) . "</p>" : "")
          . ($accepted ? ($receiptUrl !== '' ? "<p><a href='" . h($receiptUrl) . "'>View or print your receipt</a></p>" : "")
                       : "<p>Please check the reference and send your payment again from your booking page, or contact us.</p>");
    return sendEmail($to, $amt . ($accepted ? ' received by StayExplore Kenya' : ' not accepted') . ' — booking ' . $ref, $body);
}

// Guest: a cancelled booking and what refund (if any) to expect (v9.26). No money moves automatically.
function sendRefundNoticeGuestEmail($to, $name, $bookingId, $title, $due, $note, $hostPaid) {
    $ref = bookingLabel($bookingId);
    $body = "<h2>Booking cancelled</h2><p>Dear " . h($name) . ",</p><p>Booking <strong>$ref</strong> for " . h($title) . " was cancelled.</p>"
          . ((float)$due > 0 ? "<p style='font-size:18px'><strong>Refund due from StayExplore Kenya: KES " . number_format((float)$due, 2) . "</strong>" . emailApprox($bookingId, $due) . ". The admin will send it to you and you will get another email when it is done.</p>"
                             : "<p>" . h($note) . "</p>")
          . ((float)$hostPaid > 0 ? "<p>Your host received KES " . number_format((float)$hostPaid, 2) . " directly from you. Please ask your host for that refund, following the cancellation rules.</p>" : "")
          . "<p>You can see everything under My bookings.</p>";
    return sendEmail($to, ((float)$due > 0 ? 'Refund due: KES ' . number_format((float)$due, 2) . ' — ' : 'Booking cancelled — ') . $ref, $body);
}


// v9.39: " (about USD 93 at KES 129 per USD on 27 Nov 2026)" for a booking whose guest paid while viewing another currency; '' otherwise.
if (!function_exists('emailApprox')) {
    function emailApprox($bookingId, $kes) {
        global $pdo;
        try { require_once __DIR__ . '/currency.php'; return lockedApproxHtml($kes, lockedCurrencyForBooking($pdo, $bookingId)); } catch (Throwable $e) { return ''; }
    }
}
