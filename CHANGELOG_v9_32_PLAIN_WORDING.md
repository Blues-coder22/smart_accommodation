# v9.32 - Plainer wording

Words only. No logic, database or layout was changed.

## Changed
* **Sign up, verify, sign in** (register.php, verify_wait.php, verify.php, mailbox.php, login.php):
  account type is "Traveller" or "Host" with short descriptions; "Check your email" page; "Resend verification email";
  "Your email is verified"; "Please enter your email and password."
* **Verification email** (includes/email.php): "Verify my email" button, Option 1 (link) and Option 2 (6-digit code) clearly separated,
  "If you did not create this account, you can ignore this email."
* **Role label**: "Tourist" pill in the menu is now "Traveller".
* **"Administrator" -> "Admin"** in all text people read, 50 files (variable names and database values untouched).
* **Long messages rewritten in simpler words**: payment set back to awaiting, early completion, photo/screenshot upload errors,
  closing and deleting an account, verification cards, rates saved, review of a guest, attraction approved, and about 25 more.
* **Labels**: listing status "Rejected" -> "Not approved"; host "Pending approval" -> "Waiting for approval";
  admin "Pending" -> "Awaiting review"; "Review moderation" -> "Manage reviews"; "Imported places review queue" -> "Imported places to review".
* **Host pages** now say "guest" instead of "tourist" (messages, buttons, availability).
* New: WORDING_GUIDE.md (one word for each idea).

## Not changed (on purpose)
* The `StayExplore_Kenya_Authenticated_Frontend` folder: demo pages, not the live site.
* The word "listing" (about 550 uses, mostly host and admin pages) and "accommodation" in the report-style text: left as they are
  because a blanket swap risked changing meaning. Use the guide for new text.
* Admin-only technical pages (database setup, imports) and code comments that mention tourists.
* Database values and role names (`tourist`, `rejected`, ...).

## Known limits
* Not run: no PHP/MySQL here. Checked by comparing every changed file against v9.31 (brackets and quotes) and by exact-match replacement
  (every planned change was found). Open the changed pages on XAMPP and read them once.
* Pages that say "tourist" only in code-facing text such as `?as=tourist` links are correct as they are.
* The six-digit-code email and the link email still say nothing about the link's own expiry; only the code's 30 minutes is stated.
