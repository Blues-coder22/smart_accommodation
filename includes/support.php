<?php
/**
 * v9.34: in-app support. A guest or host writes to the StayExplore admins from inside the website, the admins answer
 * from their own inbox, and both sides are notified (bell + live update). Email stays available as a second way.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('ensureSupportTables')) {
    function ensureSupportTables($pdo) {
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS support_threads (
                thread_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                subject VARCHAR(160) NOT NULL,
                category VARCHAR(40) NOT NULL DEFAULT 'other',
                booking_ref VARCHAR(20) NULL,
                status ENUM('open','closed') NOT NULL DEFAULT 'open',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_user (user_id, updated_at), KEY idx_status (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS support_messages (
                message_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                thread_id INT NOT NULL,
                sender_id INT NOT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                body VARCHAR(2000) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                KEY idx_thread (thread_id, message_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureSupportTables: ' . $e->getMessage()); }
    }
}
if (!function_exists('supportCategories')) {
    function supportCategories() {
        return ['booking' => 'A booking or payment', 'refund' => 'A cancellation or refund', 'host' => 'A host or guest problem',
                'account' => 'My account or sign-in', 'listing' => 'My listing (hosts)', 'other' => 'Something else'];
    }
}
if (!function_exists('supportEmailAddress')) {
    /** The address people can email: the admin's configured notification address, else the support address. */
    function supportEmailAddress($pdo = null) {
        try {
            $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
            if ($pdo && function_exists('adminNotificationRecipient')) {
                $r = adminNotificationRecipient($pdo);
                if ($r && !empty($r['email'])) { $first = trim(preg_split('/[,;\s]+/', $r['email'])[0] ?? ''); if (filter_var($first, FILTER_VALIDATE_EMAIL)) return $first; }
            }
        } catch (Throwable $e) {}
        return 'support@stayexplore.co.ke';
    }
}
if (!function_exists('supportMailLinks')) {
    /** Links that open the person's own mail app (mailto) or Gmail / Outlook in the browser, addressed to support. */
    function supportMailLinks($pdo, $subject = 'StayExplore Kenya support', $body = '') {
        $to = supportEmailAddress($pdo);
        $name = (string)($_SESSION['full_name'] ?? ''); $mail = (string)($_SESSION['email'] ?? '');
        if ($body === '') $body = "Hello StayExplore Kenya support,\n\n\n\n---\n" . ($name !== '' ? "Name: $name\n" : '') . ($mail !== '' ? "Account email: $mail\n" : '') . ($_SESSION['role'] ?? '' ? 'Role: ' . $_SESSION['role'] . "\n" : '');
        $q = 'subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body);
        return ['to' => $to,
            'mailto' => 'mailto:' . $to . '?' . $q,
            'gmail' => 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode($to) . '&su=' . rawurlencode($subject) . '&body=' . rawurlencode($body),
            'outlook' => 'https://outlook.live.com/mail/0/deeplink/compose?to=' . rawurlencode($to) . '&subject=' . rawurlencode($subject) . '&body=' . rawurlencode($body)];
    }
}
if (!function_exists('supportNotifyAdmins')) {
    function supportNotifyAdmins($pdo, $threadId, $fromName, $subject) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM notifications n JOIN users u ON u.user_id = n.user_id WHERE u.role = 'admin' AND n.url = ? AND n.is_read = 0 AND n.title = 'Support message'");
        $url = 'admin/support.php?thread_id=' . (int)$threadId;
        $dup->execute([$url]);
        if ((int)$dup->fetchColumn() === 0) notifyAdmins($pdo, 'Support message', $fromName . ' wrote to support: ' . $subject, $url);
    }
}
if (!function_exists('supportAddMessage')) {
    /** Adds one message. $asAdmin true = an admin replying. Returns the new message id or 0. */
    function supportAddMessage($pdo, $threadId, $senderId, $asAdmin, $body) {
        $pdo->prepare('INSERT INTO support_messages (thread_id, sender_id, is_admin, body) VALUES (?,?,?,?)')->execute([(int)$threadId, (int)$senderId, $asAdmin ? 1 : 0, $body]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE support_threads SET updated_at = CURRENT_TIMESTAMP, status = 'open' WHERE thread_id = ?")->execute([(int)$threadId]);
        $t = $pdo->prepare('SELECT t.user_id, t.subject, u.full_name FROM support_threads t JOIN users u ON u.user_id = t.user_id WHERE t.thread_id = ?');
        $t->execute([(int)$threadId]); $r = $t->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            if ($asAdmin) {
                $role = $pdo->prepare('SELECT role FROM users WHERE user_id = ?'); $role->execute([(int)$r['user_id']]);
                $to = ($role->fetchColumn() === 'host') ? 'host' : 'tourist';
                notify($pdo, (int)$r['user_id'], 'Reply from support', 'StayExplore support replied: ' . $r['subject'], $to . '/support.php?thread_id=' . (int)$threadId);
            } else {
                supportNotifyAdmins($pdo, $threadId, $r['full_name'], $r['subject']);
            }
        }
        return $id;
    }
}
