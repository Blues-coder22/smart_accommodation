<?php
// TEMPLATE. Copy this file to includes/config.php and fill in your own values.
// config.php is git-ignored on purpose, so your real password is never uploaded to GitHub. This example file is safe to commit.
//
// Gmail: Google Account > Security > turn on 2-Step Verification > App passwords > create one for "Mail".
// Paste the 16-letter app password below (not your normal Gmail password).

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-address@gmail.com');
define('SMTP_PASSWORD', '');                       // the 16-letter app password
define('SMTP_FROM_EMAIL', 'your-address@gmail.com');
define('SMTP_FROM_NAME', 'StayExplore Kenya');

// The public address of the site, used in the verification link inside emails (no trailing slash).
// On your computer: http://localhost/smart_accommodation
// To open the link from a phone on the same Wi-Fi use the computer's address, e.g. http://192.168.1.20/smart_accommodation
// Once the site is online use the real domain, e.g. https://www.yoursite.co.ke
define('APP_BASE_URL', 'http://localhost/smart_accommodation');

// Where messages for the admin go (new listings to approve, new reports, flagged reviews).
// Leave empty to use the email address of the active admin account(s). Several addresses can be separated by commas.
define('ADMIN_NOTIFY_EMAIL', '');

// Commission the platform earns when a host confirms a payment (percent of the booking amount).
// The rate used is saved on each booking's commission record, so changing it later does not alter past records.
define('COMMISSION_RATE_PERCENT', 10);   // starting value only; the admin changes it under Revenue & commission
// Shown to guests on the payment page: where to send StayExplore Kenya's share. The admin can change it under Revenue & commission.
define('COMMISSION_PAY_INSTRUCTIONS', 'Send this amount by M-Pesa to Till/Paybill XXXXXX (StayExplore Kenya), or by bank transfer to the account the admin gives you, then enter the reference below.');
// Secret used to make receipt verification codes. Put any long random text here and never share it.
define('RECEIPT_SECRET', 'change-this-to-a-long-random-text');
// Only payments entered on or after this date earn commission, e.g. '2026-12-01'. Leave '' to include every confirmed payment.
define('COMMISSION_START_DATE', '');
// Service fee added to the guest's total, as a percent of stay + extras (0 turns it off). The host receives it with the payment and passes it on to the platform with the commission.
define('SERVICE_FEE_PERCENT', 0);   // starting value only (0 = no guest fee at launch); the admin changes it under Revenue & commission

// ---- SMS verification codes (optional). Sign up at https://africastalking.com, create an app, copy its API key here. ----
// SMS_USERNAME: your Africa's Talking app username, or 'sandbox' to test (sandbox texts only reach the simulator, not real phones).
// Leave SMS_API_KEY empty to switch SMS off for real sending: codes are then only written to logs/sms.log (handy while developing).
// SMS_SENDER_ID: optional approved sender name such as 'StayExplore'; leave empty to use the shared sender.
define('SMS_PROVIDER', 'africastalking');
define('SMS_USERNAME', '');
define('SMS_API_KEY', '');
define('SMS_SENDER_ID', '');
