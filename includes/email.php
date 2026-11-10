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
 * Who hears about things that need an administrator (new listing, new report, flagged review...).
 * ADMIN_NOTIFY_EMAIL in includes/config.php wins when it is filled in (several addresses may be separated by commas);
 * otherwise every active, confirmed administrator account is used, always with its CURRENT email address.
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
                               ->execute([$uid, $oldCode['code_hash'], $oldCode['expires_at'], $oldCode['attempts']]);
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
        <p style='margin-top:24px'><strong>Link not opening on your phone?</strong> Type this code on the page where you registered:</p>
        <p style='font-size:30px;font-weight:bold;letter-spacing:8px;background:#f4f8f5;border:1px solid #cfdcd5;
           border-radius:8px;display:inline-block;padding:10px 18px;margin:4px 0'>$code</p>
        <p style='color:#666;font-size:13px'>The code works for 30 minutes.</p>";
            }
        }
    } catch (Throwable $e) { error_log('Verification code not created: ' . $e->getMessage()); }
    $body = "
        <h2>Welcome to StayExplore Kenya, $name!</h2>
        <p>Please verify your email address by clicking the link below:</p>
        <a href='$link' style='background:#126b4e;color:white;
           padding:10px 20px;text-decoration:none;border-radius:5px'>
           Verify Email
        </a>
        $codeHtml
        <p>If you did not register, please ignore this email.</p>
    ";
    return sendEmail($to, 'Verify Your Email — StayExplore Kenya', $body);
}

function sendBookingConfirmationEmail($to, $name, $booking) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
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
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $body = "
        <h2>New booking received</h2>
        <p>Dear $name,</p>
        <p>You have a new booking:</p>
        <table border='1' cellpadding='8'>
            <tr><td>Booking ID</td><td>$ref</td></tr>
            <tr><td>Property</td><td>{$booking['title']}</td></tr>
            <tr><td>Check-in</td><td>{$booking['check_in_date']}</td></tr>
            <tr><td>Check-out</td><td>{$booking['check_out_date']}</td></tr>
            <tr><td>Total</td><td>KES " . number_format($booking['total_amount']) . "</td></tr>
        </table>
        <p>When the guest's payment reaches you, open <strong>Payments</strong> in your host area and
           choose <strong>Mark as paid</strong>.</p>
    ";
    return sendEmail($to, 'New Booking — StayExplore Kenya', $body);
}

// Sent to the tourist when they submit their payment details (not currently used at checkout:
// the booking-confirmed email already covers it), kept for completeness.
function sendPaymentConfirmationEmail($to, $name, $booking, $method = '', $reference = '', $hostName = '') {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $amount = 'KES ' . number_format((float)$booking['total_amount'], 2);
    $body = "
        <h2>Payment sent</h2>
        <p>Dear " . h($name) . ",</p>
        <p style='font-size:18px'><strong>$amount sent to " . h($hostName !== '' ? $hostName : 'your host') . "</strong> for " . h($booking['title']) . " (booking $ref) on " . date('j M Y \\a\\t H:i') . ".</p>
        <table border='1' cellpadding='8'>
            " . ($reference !== '' ? "<tr><td>Reference</td><td>" . h($reference) . "</td></tr>" : "") . "
            " . ($method !== '' ? "<tr><td>Method</td><td>" . h(paymentMethodLabel($method)) . "</td></tr>" : "") . "
            <tr><td>Check-in</td><td>" . h($booking['check_in_date']) . "</td></tr>
            <tr><td>Status</td><td>Waiting for your host to confirm they received it</td></tr>
        </table>
        <p>This is not a receipt yet. When your host confirms the money has arrived you will get another email, \"$amount received\", with your receipt.</p>
        " . bookingRulesEmailHtml() . "
        <p>Thank you for using StayExplore Kenya.</p>
    ";
    return sendEmail($to, $amount . ' sent to ' . ($hostName !== '' ? $hostName : 'your host') . ' — awaiting confirmation', $body);
}

// Sent to the tourist when the host marks a payment as actually received.
function sendPaymentVerifiedEmail($to, $name, $booking, $hostName = '', $reference = '', $paymentId = 0) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $amount = 'KES ' . number_format((float)$booking['total_amount'], 2);
    $link = $paymentId ? "<p><a href='" . h(appAbsoluteUrl('tourist/receipt.php?payment_id=' . (int)$paymentId)) . "'>View or print your receipt</a></p>" : '';
    $body = "
        <h2>Payment confirmed</h2>
        <p>Dear " . h($name) . ",</p>
        <p style='font-size:18px'><strong>$amount received</strong> by " . h($hostName !== '' ? $hostName : 'your host') . " for " . h($booking['title']) . " (booking $ref).</p>
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
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
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
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $body = "
        <h2>Your payment was set back to awaiting</h2>
        <p>Hello " . h($name) . ",</p>
        <p>The host of <strong>" . h($booking['title']) . "</strong> changed the payment for booking $ref from <em>received</em> to <em>awaiting</em>.</p>
        <p><strong>Reason given by the host:</strong> " . h($reason) . "</p>
        <p>If you did pay, keep your M-Pesa message or bank receipt and report this from the Report page of your account.
           An administrator has been told and can review it; a payment confirmed by an administrator cannot be undone by the host.</p>";
    return sendEmail($to, 'Your payment status changed — StayExplore Kenya', $body);
}

// To every ADMINISTRATOR when a host reverses a received payment.
function sendPaymentReversedAdminEmail($to, $booking, $hostName, $touristName, $reason) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
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
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    if ($why === 'no_payment') {
        $line = 'We did not receive payment details within ' . (int)BOOKING_NO_PAYMENT_HOLD_HOURS . ' hours, so the dates were released for other guests.';
    } elseif ($forHost) {
        $line = 'You did not accept or decline this request within ' . (int)BOOKING_HOST_REPLY_HOURS . ' hours, so it expired and the dates were released. Please reply to requests promptly.';
    } else {
        $line = 'The host did not reply within ' . (int)BOOKING_HOST_REPLY_HOURS . ' hours, so the request expired and the dates were released. You have not been charged by this website; if you already sent money to the host, ask for a refund.';
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
           If it was not, reset your password again straight away and contact the administrator.</p>
    ";
    return sendEmail($to, 'Your password was changed — StayExplore Kenya', $body);
}

// ---- Booking reminders, releases and host cancellations (v9.14) ----
// Reminder: the guest has not entered payment details and the dates will be released soon.
function sendPaymentDeadlineReminderEmail($to, $name, $booking, $minutesLeft) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $body = "<h2>Your dates will be released soon</h2><p>Dear " . h($name) . ",</p>"
          . "<p>We are holding <strong>" . h($booking['title']) . "</strong> (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . ") for you, booking $ref.</p>"
          . "<p>We have not yet received your payment details. If they are not entered in about <strong>" . (int)$minutesLeft . " minutes</strong>, the request is cancelled and the dates are released to other guests.</p>"
          . "<p>If you are paying by <strong>cash on arrival</strong>, open the payment page and choose <strong>Cash (pay on arrival)</strong> to keep your dates.</p>"
          . "<p><a href='" . h(appAbsoluteUrl('tourist/payment.php?booking_id=' . (int)$booking['booking_id'])) . "'>Go to the payment page</a></p>";
    return sendEmail($to, 'Your dates will be released soon — StayExplore Kenya', $body);
}

// Host releases the dates of a pending request (decline), with an optional note.
function sendBookingReleasedEmail($to, $name, $booking, $note = '') {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $body = "<h2>Booking request not accepted</h2><p>Dear " . h($name) . ",</p>"
          . "<p>Your host could not accept booking $ref for <strong>" . h($booking['title']) . "</strong> (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . "). The dates have been released.</p>"
          . ($note !== '' ? "<p><strong>Message from your host:</strong> " . nl2br(h($note)) . "</p>" : "")
          . "<p>If you already sent any money, please ask your host to return it. You are welcome to look for another stay.</p>";
    return sendEmail($to, 'Booking request not accepted — StayExplore Kenya', $body);
}

// Host cancels a booking (guest gets the reason).
function sendHostCancelledGuestEmail($to, $name, $booking, $reason, $paymentStatus) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $paid = in_array($paymentStatus, ['confirmed'], true);
    $body = "<h2>Your booking was cancelled by the host</h2><p>Dear " . h($name) . ",</p>"
          . "<p>Your host has cancelled booking $ref for <strong>" . h($booking['title']) . "</strong> (" . h($booking['check_in_date']) . " to " . h($booking['check_out_date']) . ").</p>"
          . "<p><strong>Reason given:</strong> " . nl2br(h($reason)) . "</p>"
          . ($paid ? "<p><strong>You had already paid.</strong> Your host should refund you in full. If they do not, please contact us using the report option so an administrator can follow it up.</p>"
                   : "<p>If you sent any money, please ask your host to return it.</p>")
          . "<p>We are sorry about this. You can search for another stay at any time.</p>";
    return sendEmail($to, 'Booking cancelled by the host — StayExplore Kenya', $body);
}

function sendHostCancelledAdminEmail($to, $booking, $hostName, $guestName, $reason, $paymentStatus) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
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
          . ($note !== '' ? "<p><strong>Note from the administrator:</strong> " . nl2br(h($note)) . "</p>" : "")
          . ($accepted ? "" : "<p>The bookings are back on your outstanding list. You can submit the payment again with the correct reference.</p>");
    return sendEmail($to, 'Commission payment ' . ($accepted ? 'received' : 'not accepted') . ' — StayExplore Kenya', $body);
}

// To the HOST at the moment they confirm a payment: "KES x received from ...", plus what is owed to the platform.
function sendPaymentReceivedHostEmail($to, $hostName, $booking, $guestName, $reference, $commission, $serviceFee) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $amount = 'KES ' . number_format((float)$booking['total_amount'], 2);
    $due = (float)$commission + (float)$serviceFee;
    $body = "<h2>Payment received</h2><p>Dear " . h($hostName) . ",</p>"
          . "<p style='font-size:18px'><strong>$amount received from " . h($guestName) . "</strong> for " . h($booking['title']) . " (booking $ref).</p>"
          . ($reference !== '' ? "<p><strong>Reference:</strong> " . h($reference) . "</p>" : "")
          . "<table border='1' cellpadding='8'><tr><td>Guest paid you</td><td>$amount</td></tr>"
          . "<tr><td>Commission (" . rtrim(rtrim(number_format((float)COMMISSION_RATE_PERCENT, 2), '0'), '.') . "% of your price)</td><td>KES " . number_format((float)$commission, 2) . "</td></tr>"
          . "<tr><td>Guest service fee</td><td>KES " . number_format((float)$serviceFee, 2) . "</td></tr>"
          . "<tr><td><strong>Due to StayExplore Kenya</strong></td><td><strong>KES " . number_format($due, 2) . "</strong></td></tr>"
          . "<tr><td>You keep</td><td>KES " . number_format((float)$booking['total_amount'] - $due, 2) . "</td></tr></table>"
          . "<p>Send the amount due from <strong>Commission</strong> in your host area and report it with the reference. The guest has been told you received their payment.</p>";
    return sendEmail($to, $amount . ' received from ' . $guestName, $body);
}

