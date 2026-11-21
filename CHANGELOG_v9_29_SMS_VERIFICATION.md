# v9.29 - Confirm the account by SMS code or by email

## What is new
* **Register**: a choice "Confirm my account with Email / SMS". SMS needs a phone number (Kenyan formats like 0712 345 678 are accepted and stored as +254712345678; other countries use +code).
* **Waiting page** (verify_wait.php): shows the code box for SMS, and has buttons to switch: "Send a code by SMS instead" / "Send me an email instead". Anyone who registered or signed in unverified can switch at any time.
* The SMS code is the same 6-digit code used by email: valid 30 minutes, 5 wrong tries, one new code per minute.
* Cost protection: at most 5 texts per phone number and 10 per internet address per hour (table sms_log).
* `users.phone_verified` is set to 1 when the account was confirmed by SMS.

## SMS provider (nothing to install)
The site talks to **Africa's Talking** over HTTPS using PHP's cURL (usually already on). No Composer package.
1. Create an account at africastalking.com, create an app, copy the API key.
2. In includes/config.php fill SMS_USERNAME and SMS_API_KEY (use SMS_USERNAME 'sandbox' to test; sandbox texts do not reach real phones).
3. Optional: apply for a sender ID (SMS_SENDER_ID). Kenya may require sender-ID registration.
4. Top up credit. Until the key is set, texts are only written to logs/sms.log (nothing is sent, nothing is charged).

## Files
* new: includes/sms.php, CHANGELOG_v9_29_SMS_VERIFICATION.md
* changed: register.php, verify_wait.php, includes/config.php, includes/config.example.php, database/full_schema.sql
* new table sms_log and column users.phone_verified (created automatically on first use)

## Known limits
* Confirming by SMS marks the account verified (email_verified = 1, so sign-in works) without proving the email address belongs to the person.
  If you need proven emails (password reset, receipts), ask SMS users to confirm their email later.
* Not tested against the live Africa's Talking service or a real phone: test with the sandbox first.
* The SMS text is in English only.
