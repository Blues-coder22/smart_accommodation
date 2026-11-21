# v9.27 - Dark mode and offline Kiswahili

## What is new
* **Dark mode** - a sun/moon button next to the language box (header of every page; pages with their own header get it injected).
  Remembers the choice per browser (`localStorage`), follows the phone/computer setting until the person chooses, applied before the page paints (no white flash).
  Receipts, certificates, booking records and evidence packs always stay light (they are print documents).
* **English + Kiswahili work offline.** Kiswahili is a word list inside the site (`assets/js/se-i18n-sw.js`), applied in the browser. No internet, no Google, no database.
* **All other languages** (French, Zulu, ... ) still use Google's translator and need internet. If there is no connection a short notice offers English / Kiswahili instead of silently staying English.
* The language box now has two groups: *Works offline* and *Needs internet*.

## Files
* new: `assets/css/dark.css`, `assets/js/se-theme.js`, `assets/js/se-i18n.js`, `assets/js/se-i18n-sw.js`, `i18n/sw_*.txt`, `tools/build_sw_wordlist.py`, `tools/gen_dark.py`
* changed: `includes/translate.php` (language + dark button), and one line added before `</head>` on 62 pages (dark.css + the two scripts)

## Adding or fixing Kiswahili
1. Open a page in Kiswahili with `?i18n_debug=1` on the address, open the browser console: it lists every text still in English.
2. Add lines `English text|||Kiswahili text` to any file in `i18n/`.
3. Run `python3 tools/build_sw_wordlist.py` (or paste the lines into `i18n/` and ask for a rebuild).
Have a native Kiswahili speaker review the list before launch.

## Known limits
* Emails sent by the server are still in English.
* Admin pages are only partly translated (about 1 in 5 phrases).
* Text typed by users (listing names, descriptions, reviews, messages) is never translated by the offline list.
* A long sentence that has a name or number inside it (for example "Welcome, Wanjiru") needs a pattern; the common ones are in `se-i18n-sw.js`.
* The site's fonts (Google Fonts) still load from the internet when online; offline the browser uses its fallback font.
