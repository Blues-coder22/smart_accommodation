<?php
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

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
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
        $mail->addAddress($to);
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
        <h2>Welcome to Smart Accommodation Kenya, $name!</h2>
        <p>Please verify your email address by clicking the link below:</p>
        <a href='$link' style='background:#1D9E75;color:white;
           padding:10px 20px;text-decoration:none;border-radius:5px'>
           Verify Email
        </a>
        $codeHtml
        <p>If you did not register, please ignore this email.</p>
    ";
    return sendEmail($to, 'Verify Your Email — Smart Accommodation Kenya', $body);
}

function sendBookingConfirmationEmail($to, $name, $booking) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $body = "
        <h2>Booking Confirmed!</h2>
        <p>Dear $name,</p>
        <p>Your stay is reserved. Here are your details:</p>
        <table border='1' cellpadding='8'>
            <tr><td>Booking ID</td><td>$ref</td></tr>
            <tr><td>Property</td><td>{$booking['title']}</td></tr>
            <tr><td>Check-in</td><td>{$booking['check_in_date']}</td></tr>
            <tr><td>Check-out</td><td>{$booking['check_out_date']}</td></tr>
            <tr><td>Total</td><td>KES " . number_format($booking['total_amount']) . "</td></tr>
        </table>
        <p>Payment is made directly to the host (cash, bank transfer or M-Pesa). You will get another
           email when the host confirms they have received it.</p>
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
function sendPaymentConfirmationEmail($to, $name, $booking) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $body = "
        <h2>Payment details received</h2>
        <p>Dear $name,</p>
        <p>We've recorded your payment details for Booking $ref.
           Your host will confirm once the payment has actually been
           received, and you'll get a separate email when that happens.</p>
        <p><strong>Amount:</strong> KES " . number_format($booking['total_amount']) . "</p>
        <p><strong>Property:</strong> {$booking['title']}</p>
        <p><strong>Check-in:</strong> {$booking['check_in_date']}</p>
        <p>Thank you for using StayExplore Kenya.</p>
    ";
    return sendEmail($to, 'Payment details received — StayExplore Kenya', $body);
}

// Sent to the tourist when the host marks a payment as actually received.
function sendPaymentVerifiedEmail($to, $name, $booking) {
    $ref = '#BK' . str_pad($booking['booking_id'], 5, '0', STR_PAD_LEFT);
    $body = "
        <h2>Payment confirmed</h2>
        <p>Dear $name,</p>
        <p>Your host has confirmed receiving your payment for
           Booking $ref.</p>
        <p><strong>Amount:</strong> KES " . number_format($booking['total_amount']) . "</p>
        <p><strong>Property:</strong> {$booking['title']}</p>
        <p>Thank you for using StayExplore Kenya.</p>
    ";
    return sendEmail($to, 'Payment Confirmed — StayExplore Kenya', $body);
}

function sendListingApprovedEmail($to, $name, $listing_title) {
    $body = "
        <h2>Listing Approved!</h2>
        <p>Dear $name,</p>
        <p>Your listing <strong>$listing_title</strong> has been 
           approved and is now visible to tourists.</p>
        <p>Log in to your host dashboard to manage your listing.</p>
    ";
    return sendEmail($to, 'Listing Approved — Smart Accommodation Kenya', $body);
}

function sendListingRejectedEmail($to, $name, $listing_title, $reason) {
    $body = "
        <h2>Listing Not Approved</h2>
        <p>Dear $name,</p>
        <p>Your listing <strong>$listing_title</strong> was not approved.</p>
        <p><strong>Reason:</strong> $reason</p>
        <p>Please update your listing and resubmit for review.</p>
    ";
    return sendEmail($to, 'Listing Update Required — Smart Accommodation Kenya', $body);
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

function sendPasswordResetEmail($to, $name, $token) {
    $link = appAbsoluteUrl('reset-password.php?token=' . rawurlencode($token));
    $name = h($name);
    $body = "
        <h2>Reset your password</h2>
        <p>Hello $name,</p>
        <p>We received a request to reset the password for your StayExplore Kenya account. This link works once and
           expires in 60 minutes:</p>
        <p><a href='$link' style='background:#1D9E75;color:white;padding:10px 20px;text-decoration:none;border-radius:5px'>Choose a new password</a></p>
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
