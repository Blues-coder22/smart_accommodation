<?php
/**
 * Language choice and dark mode button (v9.27).
 *
 *  - English and Kiswahili work OFFLINE. Kiswahili is a built-in word list in the browser
 *    (assets/js/se-i18n.js + assets/js/se-i18n-sw.js), so it needs no internet and no Google.
 *  - Every other language still uses Google's free "website translator" widget, which needs an internet connection.
 *    It only loads when one of those languages is chosen; the page text is then sent to Google to be translated.
 *  - Legal documents (receipts, certificates, evidence packs) are marked translate="no" so they stay as issued.
 *
 * The choice is stored in the "se_lang" cookie (set by se-i18n.js). The old "googtrans" cookie is still read as a fallback.
 */
if (!function_exists('siteLanguages')) {
    function siteLanguages() {
        return ['en' => 'English', 'sw' => 'Kiswahili', 'fr' => 'Français', 'zu' => 'isiZulu', 'it' => 'Italiano', 'es' => 'Español', 'de' => 'Deutsch',
                'pt' => 'Português', 'ar' => 'العربية', 'hi' => 'हिन्दी', 'zh-CN' => '中文', 'am' => 'አማርኛ', 'so' => 'Soomaali', 'yo' => 'Yorùbá', 'rw' => 'Kinyarwanda'];
    }
}
if (!function_exists('offlineLanguages')) {
    /** Languages built into the site: they work with no internet connection. */
    function offlineLanguages() { return ['en', 'sw']; }
}
if (!function_exists('currentLanguage')) {
    function currentLanguage() {
        $c = (string)($_COOKIE['se_lang'] ?? '');
        if ($c !== '' && isset(siteLanguages()[$c])) return $c;
        if (preg_match('#^/en/([A-Za-z-]+)$#', (string)($_COOKIE['googtrans'] ?? ''), $m) && isset(siteLanguages()[$m[1]])) return $m[1];
        return 'en';
    }
}
if (!function_exists('languageWidgetHtml')) {
    function languageWidgetHtml() {
        static $printed = false; $cur = currentLanguage();
        $opt = function ($code) use ($cur) {
            return '<option value="' . $code . '"' . ($cur === $code ? ' selected' : '') . '>' . htmlspecialchars(siteLanguages()[$code], ENT_QUOTES, 'UTF-8') . '</option>';
        };
        $off = ''; $on = '';
        foreach (siteLanguages() as $code => $name) { if (in_array($code, offlineLanguages(), true)) $off .= $opt($code); else $on .= $opt($code); }
        $html = '<label class="se-lang notranslate" translate="no"><span class="sr-only">Language / Lugha</span>'
              . '<select aria-label="Language / Lugha" data-se-lang>'
              . '<optgroup label="Works offline">' . $off . '</optgroup><optgroup label="Needs internet">' . $on . '</optgroup></select></label>'
              . '<button type="button" class="se-theme notranslate" translate="no" data-se-theme aria-label="Dark mode" title="Dark mode"></button>';
        if (!$printed) {
            $printed = true;
            // Google's widget is only used for languages that are not built in; English and Kiswahili never contact Google.
            $html .= "<div id=\"google_translate_element\" style=\"display:none\" class=\"notranslate\"></div>\n<script>"
                . "function googleTranslateElementInit(){new google.translate.TranslateElement({pageLanguage:'en',autoDisplay:false,includedLanguages:'"
                . implode(',', array_filter(array_keys(siteLanguages()), fn($c) => !in_array($c, offlineLanguages(), true))) . "'},'google_translate_element');}</script>\n";
            if (!in_array($cur, offlineLanguages(), true)) $html .= "<script src=\"https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit\" async></script>\n";
        }
        return $html;
    }
}
