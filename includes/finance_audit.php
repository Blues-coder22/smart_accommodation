<?php
/**
 * v9.38.1: one audit trail for money decisions that the older payment_audit table (host/guest payments only) does not cover:
 * host commission payments, guest payments to StayExplore Kenya, and payments an admin records by hand.
 * Rows are only ever added, never changed or deleted.
 */
if (!function_exists('ensureFinanceAuditTable')) {
    function ensureFinanceAuditTable($pdo) {
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS finance_audit (
                audit_id INT AUTO_INCREMENT PRIMARY KEY,
                entity VARCHAR(30) NOT NULL,
                entity_id INT NOT NULL,
                host_id INT NULL,
                actor_id INT NULL,
                actor_role VARCHAR(20) NOT NULL,
                action VARCHAR(30) NOT NULL,
                old_status VARCHAR(20) NULL,
                new_status VARCHAR(20) NULL,
                amount DECIMAL(10,2) NULL,
                note VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_entity (entity, entity_id), KEY idx_host (host_id), KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureFinanceAuditTable: ' . $e->getMessage()); }
    }
}
if (!function_exists('logFinanceEvent')) {
    /** Never throws: a logging problem must not undo or block the payment decision itself. */
    function logFinanceEvent($pdo, $entity, $entityId, $hostId, $actorId, $actorRole, $action, $old = null, $new = null, $amount = null, $note = '') {
        ensureFinanceAuditTable($pdo);
        try {
            $pdo->prepare('INSERT INTO finance_audit (entity, entity_id, host_id, actor_id, actor_role, action, old_status, new_status, amount, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$entity, (int)$entityId, $hostId ? (int)$hostId : null, $actorId ? (int)$actorId : null, $actorRole, $action, $old, $new,
                           $amount === null ? null : round((float)$amount, 2), (string)$note !== '' ? mb_substr((string)$note, 0, 500) : null]);
        } catch (Throwable $e) { error_log('logFinanceEvent: ' . $e->getMessage()); }
    }
}
if (!function_exists('financeAuditRecent')) {
    function financeAuditRecent($pdo, $limit = 40) {
        ensureFinanceAuditTable($pdo);
        try {
            return $pdo->query('SELECT f.*, a.full_name AS actor_name, h.full_name AS host_name FROM finance_audit f
                LEFT JOIN users a ON a.user_id = f.actor_id LEFT JOIN users h ON h.user_id = f.host_id ORDER BY f.audit_id DESC LIMIT ' . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return []; }
    }
}