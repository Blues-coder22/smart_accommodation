<?php
/**
 * One session per role (v9.20), so one browser can be signed in as a tourist, a host and an admin at the same time,
 * each in its own tab. Which session a page uses depends on where the page lives:
 *   /admin/...   -> the admin session      /host/...  -> the host session      /tourist/... -> the tourist session
 *   sign-in, register and password pages -> a separate guest session (so opening them never signs anyone out)
 *   shared pages (home, receipt, proof, profile view) -> ?as=role, else the page you came from, else the role last signed in
 * Role checks (requireRole) are still enforced on every page; a cookie from one role never gives access to another role's pages.
 * Limit: one account per role per browser. Use a private window or another browser for a second tourist.
 */
/**
 * v9.41: browser security headers on every page that starts a session (the .htaccess adds the same ones for static files).
 * They stop the site being shown inside another site's frame (clickjacking), stop browsers guessing file types, limit what
 * the Referer header reveals, and limit where forms can post. Geolocation stays allowed because the map uses it.
 */
if (!function_exists('seSendSecurityHeaders')) {
    function seSendSecurityHeaders() {
        if (PHP_SAPI === 'cli' || headers_sent()) return;
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=()');
        header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
        if (!empty($_SERVER['HTTPS'])) header('Strict-Transport-Security: max-age=31536000');
    }
    seSendSecurityHeaders();
}
if (!defined('SE_ROLES')) define('SE_ROLES', ['admin', 'host', 'tourist']);
if (!defined('SE_GUEST_PAGES')) define('SE_GUEST_PAGES', ['login.php', 'register.php', 'forgot-password.php', 'reset-password.php', 'resend-verification.php',
                            'verify_wait.php', 'verify_status.php', 'verify_continue.php', 'verify.php', 'mailbox.php']);
if (!function_exists('seBootSession')) {

    function seCookieName($role) { return 'SE_' . strtoupper($role); }
    /** Options for setcookie() (expires = 0 means until the browser closes). */
    function seSetCookieOpts($expires = 0) { return ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]; }
    function seCookieParams() { return ['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]; }

    /** Which namespace ('admin' | 'host' | 'tourist' | 'guest') this request belongs to. */
    function seCurrentNamespace() {
        static $ns = null;
        if ($ns !== null) return $ns;
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        // Public browsing pages (stays, attractions, a stay's details) are open to everyone, so they follow whoever is browsing.
        $publicBrowse = (bool)preg_match('#/tourist/(search|attraction|details)\.php$#', $script);
        if (!$publicBrowse && preg_match('#/(admin|host|tourist)/[^/]+$#', $script, $m)) return $ns = $m[1];
        if (in_array(basename($script), SE_GUEST_PAGES, true)) return $ns = 'guest';
        if (basename($script) === 'messages.php') return $ns = 'tourist';   // the shared inbox is the tourist's; hosts use host/messages.php
        $cands = [];
        if (!empty($_GET['as'])) $cands[] = strtolower((string)$_GET['as']);
        if (!empty($_SERVER['HTTP_REFERER']) && preg_match('#/(admin|host|tourist)/#', (string)parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH), $r)) $cands[] = $r[1];
        if (!empty($_COOKIE['se_active'])) $cands[] = strtolower((string)$_COOKIE['se_active']);
        $cands = array_merge($cands, ['tourist', 'host', 'admin']);
        foreach ($cands as $c) if (in_array($c, SE_ROLES, true) && !empty($_COOKIE[seCookieName($c)])) return $ns = $c;
        return $ns = 'guest';
    }

    function seBootSession() {
        if (session_status() !== PHP_SESSION_NONE) return;
        ini_set('session.use_strict_mode', '1');   // an id the server did not issue is never accepted
        $ns = seCurrentNamespace();
        session_name($ns === 'guest' ? 'SE_GUEST' : seCookieName($ns));
        session_set_cookie_params(seCookieParams());
        session_start();
    }

    /** Called at sign-in: moves to this role's own session (a fresh id) and remembers it as the one last used. */
    function seSwitchToRole($role) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ini_set('session.use_strict_mode', '1');
        $name = seCookieName($role);
        session_name($name);
        session_set_cookie_params(seCookieParams());
        $sid = (string)($_COOKIE[$name] ?? '');
        session_id($sid !== '' ? $sid : session_create_id());
        session_start();
        session_regenerate_id(true);
        $_SESSION = [];
        if (!headers_sent()) setcookie('se_active', $role, seSetCookieOpts());
        $_COOKIE['se_active'] = $role;
    }

    /** Called at sign-out: ends ONLY the current role's session and points "last used" at another signed-in role, if any. */
    function seEndCurrentSession() {
        $ns = seCurrentNamespace();
        if (session_status() === PHP_SESSION_NONE) seBootSession();
        $_SESSION = [];
        if (!headers_sent()) setcookie(session_name(), '', seSetCookieOpts(time() - 42000));
        session_destroy();
        if (!headers_sent() && $ns !== 'guest') {
            $next = '';
            foreach (SE_ROLES as $r) if ($r !== $ns && !empty($_COOKIE[seCookieName($r)])) { $next = $r; break; }
            setcookie('se_active', $next, seSetCookieOpts($next === '' ? time() - 42000 : 0));
        }
    }
}
