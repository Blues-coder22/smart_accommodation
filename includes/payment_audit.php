<?php
/** History of every payment status change (who, when, why) so an administrator can track disputes. */
if (!function_exists('ensurePaymentAuditTable')) {
    function ensurePaymentAuditTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS payment_audit (
                audit_id INT AUTO_INCREMENT PRIMARY KEY,
                payment_id INT NOT NULL,
                actor_id INT NULL,
                actor_role VARCHAR(20) NOT NULL,
                action VARCHAR(30) NOT NULL,
                old_status VARCHAR(20) NULL,
                new_status VARCHAR(20) NULL,
                note VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pay (payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}
if (!function_exists('logPaymentChange')) {
    function logPaymentChange($pdo, $payment_id, $actor_id, $actor_role, $action, $old, $new, $note = '') {
        ensurePaymentAuditTable($pdo);
        try {
            $pdo->prepare("INSERT INTO payment_audit (payment_id, actor_id, actor_role, action, old_status, new_status, note) VALUES (?,?,?,?,?,?,?)")
                ->execute([(int)$payment_id, $actor_id ? (int)$actor_id : null, $actor_role, $action, $old, $new, $note !== '' ? mb_substr($note, 0, 500) : null]);
        } catch (Throwable $e) {}
    }
}
if (!function_exists('paymentLockedByAdmin')) {
    /** True when the most recent change was an administrator confirming the payment. The host may not undo that. */
    function paymentLockedByAdmin($pdo, $payment_id) {
        ensurePaymentAuditTable($pdo);
        try {
            $q = $pdo->prepare("SELECT action FROM payment_audit WHERE payment_id = ? ORDER BY audit_id DESC LIMIT 1");
            $q->execute([(int)$payment_id]);
            return $q->fetchColumn() === 'admin_confirmed';
        } catch (Throwable $e) { return false; }
    }
}
