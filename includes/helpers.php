<?php
/**
 * Output helpers shared by every page.
 *
 * h($text)  Escape text for HTML output (element text, attributes, and JS template literals).
 *
 * Text saved through sanitize() is already HTML-encoded in the database (e.g. "Karen's" is stored as
 * "Karen&#039;s"). Escaping that again would show "Karen&#039;s" on screen and grow worse every time
 * a form is edited and saved. So h() decodes once and then escapes once. It also encodes the characters
 * ` $ \ so a listing title can never break out of a JavaScript `template literal` on the map pages.
 */
if (!function_exists('h')) {
    function h($text, ...$ignored): string {
        $text = (string)($text ?? '');
        // Text saved through sanitize() more than once ends up like "&amp;amp;#039;": decode until stable (max 3 rounds), then escape once.
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML401, 'UTF-8');
            if ($decoded === $text) break;
            $text = $decoded;
        }
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return str_replace(['`', '$', '\\'], ['&#96;', '&#36;', '&#92;'], $safe);
    }
}
