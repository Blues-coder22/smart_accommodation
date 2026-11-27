<?php
/**
 * v9.38.1: incident and report management.
 *  - report_history: an append-only timeline per report (submitted, investigating, notes, outcome, suspensions).
 *  - reports.outcome / handled_by / handled_at: how an investigation ended and who closed it.
 *  - abuse limits for people filing reports, and a record of how their earlier reports were judged.
 * Private admin notes live only in report_history. They are never sent to the reporter or the reported person.
 */
if (!function_exists('ensureReportManagementTables')) {
    function ensureReportManagementTables($pdo) {
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS report_history (
                history_id INT AUTO_INCREMENT PRIMARY KEY,
                report_id INT NOT NULL,
                actor_id INT NULL,
                action VARCHAR(30) NOT NULL,
                outcome VARCHAR(30) NULL,
                note TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rh_report (report_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach (['outcome' => 'VARCHAR(30) NULL', 'handled_by' => 'INT NULL', 'handled_at' => 'DATETIME NULL'] as $col => $def) {
                if (!$pdo->query("SHOW COLUMNS FROM reports LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE reports ADD COLUMN $col $def");
            }
        } catch (Throwable $e) { error_log('ensureReportManagementTables: ' . $e->getMessage()); }
    }
}
if (!function_exists('logReportEvent')) {
    /** Never throws. $note is private to admins. */
    function logReportEvent($pdo, $reportId, $actorId, $action, $outcome = null, $note = '') {
        ensureReportManagementTables($pdo);
        try {
            $pdo->prepare('INSERT INTO report_history (report_id, actor_id, action, outcome, note) VALUES (?,?,?,?,?)')
                ->execute([(int)$reportId, $actorId ? (int)$actorId : null, $action, $outcome, (string)$note !== '' ? mb_substr((string)$note, 0, 2000) : null]);
        } catch (Throwable $e) { error_log('logReportEvent: ' . $e->getMessage()); }
    }
}
if (!function_exists('reportOutcomeLabel')) {
    /** What the admin sees. */
    function reportOutcomeLabel($o) {
        return ['substantiated' => 'Upheld: the report was confirmed', 'inconclusive' => 'Inconclusive: not enough evidence', 'unsubstantiated' => 'Not upheld: no problem found',
                'no_action_needed' => 'No action needed', 'false_report' => 'False or malicious report'][$o] ?? '';
    }
}
if (!function_exists('reportOutcomeForReporter')) {
    /** What the person who filed the report is told. A false report is never described to them as false. */
    function reportOutcomeForReporter($o) {
        if ($o === 'substantiated') return 'Your report was upheld.';
        if ($o === 'inconclusive')  return 'We could not confirm the problem with the evidence available.';
        return 'We did not find a problem that needs action.';
    }
}
if (!function_exists('reportAbuseCheck')) {
    /** Returns '' when the person may file this report, otherwise a message to show them. */
    function reportAbuseCheck($pdo, $uid, $type, $targetId, $incidentType) {
        ensureReportManagementTables($pdo);
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND outcome = 'false_report' AND created_at >= (NOW() - INTERVAL 180 DAY)");
            $q->execute([(int)$uid]);
            if ((int)$q->fetchColumn() >= 3) return 'New reports from your account are paused because several recent ones were reviewed and not found to be genuine. If you have a real safety concern, please contact support and we will look at it.';
            $q = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE reporter_id = ? AND created_at >= (NOW() - INTERVAL 24 HOUR)");
            $q->execute([(int)$uid]);
            if ((int)$q->fetchColumn() >= 5) return 'You have sent several reports today. Please wait until tomorrow, or contact support if something is urgent.';
            $q = $pdo->prepare("SELECT 1 FROM reports WHERE reporter_id = ? AND report_type = ? AND target_id = ? AND COALESCE(incident_type, '') = ? AND status IN ('pending','reviewed') LIMIT 1");
            $q->execute([(int)$uid, $type, (int)$targetId, (string)$incidentType]);
            if ($q->fetchColumn()) return 'You already have an open report about this. Our team is looking into it, and you will be told the outcome.';
        } catch (Throwable $e) { error_log('reportAbuseCheck: ' . $e->getMessage()); }   // fail open: a lookup problem must not stop a real report
        return '';
    }
}
if (!function_exists('reporterRecord')) {
    /** How this person's earlier reports were judged, for the admin. */
    function reporterRecord($pdo, $uid) {
        $q = $pdo->prepare("SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'resolved' AND COALESCE(outcome, 'substantiated') = 'substantiated'), 0) AS upheld,
                COALESCE(SUM(status = 'resolved' AND COALESCE(outcome, 'substantiated') <> 'substantiated'), 0) AS inconclusive,
                COALESCE(SUM(status = 'dismissed' AND COALESCE(outcome, '') <> 'false_report'), 0) AS not_upheld,
                COALESCE(SUM(outcome = 'false_report'), 0) AS false_reports
            FROM reports WHERE reporter_id = ?");
        $q->execute([(int)$uid]);
        return array_map('intval', $q->fetch(PDO::FETCH_ASSOC));
    }
}
if (!function_exists('targetReportRecord')) {
    /** Other reports about the same listing or person, for the admin. */
    function targetReportRecord($pdo, $type, $targetId, $exceptReportId) {
        $q = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'resolved' AND COALESCE(outcome, 'substantiated') = 'substantiated'), 0) AS upheld
            FROM reports WHERE report_type = ? AND target_id = ? AND report_id <> ?");
        $q->execute([$type, (int)$targetId, (int)$exceptReportId]);
        return array_map('intval', $q->fetch(PDO::FETCH_ASSOC));
    }
}