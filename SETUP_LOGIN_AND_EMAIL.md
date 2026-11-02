# Login, logout, info pages and email - what changed and how to set up

## Where to put the folder (important)
Put the project in `C:\xampp\htdocs\smart_accommodation` and open `http://localhost/smart_accommodation/`.
**Delete or rename any older copy of the site** (for example an older `smart_accommodation` folder or a nested
`smart_accommodation\smart_accommodation`). Two copies share one login session, so signing out of one could land you
on the other copy's old login page. Logout now returns to the copy you are using, but the old copy can still be opened by mistake.
After replacing files, press Ctrl+F5 once in the browser.

## What was fixed
- **About, How it works, Contact, Privacy, Terms, Help:** these are the `.html` pages. The old `.php` versions crashed
  (the shared header used `isLoggedIn()` without loading the file that defines it). The header is fixed and the old `.php`
  addresses now redirect to the `.html` pages, so old links and bookmarks still work. Links inside the site point to `.html`.
- **Logout / login:** logout always returns to this copy's own login page. Signed-in pages and the login page are no
  longer kept by the browser, so Back after signing out cannot show an old page or an old login form.
  Hosts sign in to `host/dashboard.php`, admins to `admin/dashboard.php`, tourists to the stays page.
- **Three broken photo paths** (a line break inside the image address) on the payment, search and stay pages.

## Sending real verification emails (so people get the link in their inbox)
1. Copy `includes/config.example.php` to `includes/config.php`.
2. In your Google account turn on 2-Step Verification, then create an **App password** (Security > App passwords).
3. Put your Gmail address and the 16-letter app password in `config.php` (SMTP_USERNAME, SMTP_FROM_EMAIL, SMTP_PASSWORD).
4. Set `APP_BASE_URL` to the address people will open. `http://localhost/...` only works on your own computer; for a
   phone on the same Wi-Fi use the computer's address (for example `http://192.168.1.20/smart_accommodation`), and for the
   real site use its domain.
5. Register a new account: the email should arrive. If it does not, `error_log` in `C:\xampp\apache\logs\error.log` says why.
Never put the password inside a `.php` page, and never share `config.php`. If it was ever sent to anyone, make a new app password.
Until `config.php` is filled in, no email is sent: the account is created, the page says so, and the message is kept in `logs/mail.log`.

## Confirming your email on any device (v9.4)

After registering (or signing in with an unconfirmed email) the browser shows a **Confirm your email** page (`verify_wait.php`).
Open the email link on any device, a phone is fine. The waiting page checks every 3 seconds (`verify_status.php`) and signs itself in as
soon as the email is verified. The device that opened the link shows "Email verified" and offers a **Continue on this device** button.
If the link is opened in the same browser that registered, that browser is signed in immediately.
The waiting code lives only in the waiting browser's session, works once, expires after 30 minutes and is stored hashed in the `verify_waits` table.

## Verifying when the phone cannot open the link (v9.10)

The link in the email points at `APP_BASE_URL` (for example `http://localhost/...`). A phone that is not on the same network
as the computer running XAMPP cannot open that address, so tapping the link shows a page that never loads. That is a network
limit, not a bug: a link can only verify an account if the phone can reach the site.

So every verification email now also contains a **6-digit code**. On the page where you registered (the "Confirm your email" page),
type the code into the box and press **Verify**: the email is verified and that browser is signed in. No link and no phone
connection to the computer is needed. The code works for 30 minutes, locks after 5 wrong tries, and "Send the email again" issues a new one.
Codes are stored hashed in the `verify_codes` table (created automatically; `database/migration_verify_codes.sql` if you prefer to run it).

The link still works, and still signs the waiting browser in automatically, whenever the phone CAN reach the site: same Wi-Fi with
`APP_BASE_URL` set to the computer's address (e.g. `http://192.168.1.20/smart_accommodation`), or once the site is hosted on a real domain.
