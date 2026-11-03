# v9.12 - accessibility and usability pass (PHP application)

Checked first, then fixed:
- Mobile menu: the shared header's menu button had no click handler on PHP pages (CSS waited for a class nothing added). Fixed in assets/js/polish.js, with Escape to close and aria-expanded/aria-controls.
- Text size: body 15px, form labels 13.5px (were 9-11px), table headings 12px, small text 13px. Appended at the end of assets/css/style.css.
- Keyboard focus: one clear 3px ring on links, buttons and form fields (the old rule removed the outline on fields). "Skip to main content" link added by polish.js.
- Form fields: 77 fields had a visible label or placeholder but no programmatic name; each now has an accessible name.
- Images: all 26 <img> tags in the PHP pages already had alt; two weak ones improved, and the admin Commons photo suggestions now describe themselves.
- Admin listings: Reject and Suspend now ask for confirmation naming the listing, and the reason is required (the empty reason boxes also hid their placeholder text).
- Admin ID verification: Reject asks for confirmation.
- Reports (guest and host): the confirmation now shows the report reference.

Checked and found already fine / not as described:
- how-it-works.html exists in this project and the header link works.
- Add/edit listing forms already group fields into sections (Basic details, Location, Amenities, House rules, Photos).
- Most destructive actions already had confirmations.

## Follow-up pass (static pages and review items)
- The six static pages (about, help, privacy, terms, contact, how-it-works) used the old frontend stylesheet and had no polish.js, so the v9.12 fixes never reached them (body was 14px, no skip link, 2px focus ring). The accessibility block is now also in assets/css/a11y.css, linked from those pages, and they load polish.js (shared mobile menu, Escape to close, skip link). Their duplicate inline menu script was removed.
- Static-page hamburger was white on a cream header (invisible); now dark green.
- Checked in headless Chromium at 1280px and 390px: body 15px, skip link present, no sideways overflow, menu opens and closes with Escape.
- verify_status.php: JSON polling endpoint, no UI. Logic reviewed, no change needed (no-store, stops when verified/expired/blocked, expires after 30 min).
- mailbox.php: wording no longer claims an email was already sent. Nothing in the PHP app links to this page; decide whether to link it from login/register or remove it.
- NOT done: PHP pages were not rendered (no PHP in this environment); unified headings/spacing/card layout; Lighthouse on a real device.
- v9.13: the banner headline on login, register, verify, verify_wait, mailbox, forgot-password, resend-verification and reset-password is now a styled paragraph (.auth-heading), so each page has a single <h1> (the form title). Not yet viewed in a browser with PHP running.
