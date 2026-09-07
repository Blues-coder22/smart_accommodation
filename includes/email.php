<?php
// Simple email function using PHPMailer
// Install PHPMailer: composer require phpmailer/phpmailer
// OR download from https://github.com/PHPMailer/PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'cecilyado022@gmail.com';
        $mail->Password   = 'iahn tzbg zoog tuxk'; 
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('cecilyado022@gmail.com', 'Smart Accommodation Kenya');
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
    $link = "http://localhost/smart_accommodation/verify.php?token=$token";
    $body = "
        <h2>Welcome to Smart Accommodation Kenya, $name!</h2>
        <p>Please verify your email address by clicking the link below:</p>
        <a href='$link' style='background:#1D9E75;color:white;
           padding:10px 20px;text-decoration:none;border-radius:5px'>
           Verify Email
        </a>
        <p>If you did not register, please ignore this email.</p>
    ";
    return sendEmail($to, 'Verify Your Email — Smart Accommodation Kenya', $body);
}

function sendBookingConfirmationEmail($to, $name, $booking) {
    $body = "
        <h2>Booking Confirmed!</h2>
        <p>Dear $name,</p>
        <p>Your booking has been confirmed. Here are your details:</p>
        <table border='1' cellpadding='8'>
            <tr><td>Booking ID</td><td>#BK{$booking['booking_id']}</td></tr>
            <tr><td>Property</td><td>{$booking['title']}</td></tr>
            <tr><td>Check-in</td><td>{$booking['check_in_date']}</td></tr>
            <tr><td>Check-out</td><td>{$booking['check_out_date']}</td></tr>
            <tr><td>Total</td><td>KES {$booking['total_amount']}</td></tr>
        </table>
        <p>Thank you for using Smart Accommodation Kenya.</p>
    ";
    return sendEmail($to, 'Booking Confirmed — Smart Accommodation Kenya', $body);
}

function sendPaymentConfirmationEmail($to, $name, $booking) {
    $body = "
        <h2>Payment Received</h2>
        <p>Dear $name,</p>
        <p>Your payment for Booking #BK{$booking['booking_id']} 
           has been confirmed.</p>
        <p><strong>Amount:</strong> KES {$booking['total_amount']}</p>
        <p><strong>Property:</strong> {$booking['title']}</p>
        <p><strong>Check-in:</strong> {$booking['check_in_date']}</p>
        <p>Thank you for using Smart Accommodation Kenya.</p>
    ";
    return sendEmail($to, 'Payment Confirmed — Smart Accommodation Kenya', $body);
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
        <h2>⚠️ Critical Incident Report Received</h2>
        <p>A critical incident report has been submitted.</p>
        <table border='1' cellpadding='8'>
            <tr><td>Report ID</td><td>#RPT$report_id</td></tr>
            <tr><td>Reported by</td><td>$reporter_name</td></tr>
            <tr><td>Incident type</td><td>$incident_type</td></tr>
        </table>
        <p>Please log in to the admin panel to review this report immediately.</p>
    ";
    return sendEmail($admin_email, 
                     '⚠️ Critical Incident Report — Immediate Review Required', 
                     $body);
}
?>