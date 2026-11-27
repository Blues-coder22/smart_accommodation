<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/notifications.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';   // siteSettingGet / siteSettingSet
require_once '../includes/report_safety.php';
ensureReportManagementTables($pdo);
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $report_id    = (int)($_POST['report_id'] ?? 0);
    $action       = sanitize($_POST['action'] ?? '');
    if ($action === 'set_auto_suspend') {   // the on/off switch for automatic suspension after a critical incident
        $on = (($_POST['value'] ?? '') === '1');
        siteSettingSet('report_auto_suspend', $on ? '1' : '0', (int)$_SESSION['user_id']);
        setFlash('success', $on ? 'Automatic suspension is ON: a critical incident suspends the listing immediately.' : 'Automatic suspension is OFF: critical incidents alert you but you decide whether to suspend.');
        redirect('reports.php');
    }
    $admin_action = sanitize($_POST['admin_action'] ?? '');
    // v9.38.1: private note (admins only, never sent to anyone) and the investigation outcome, checked against what each action allows.
    $internal = mb_substr(trim((string)($_POST['internal_note'] ?? '')), 0, 2000);
    $allowedOutcomes = ['resolve' => ['substantiated', 'inconclusive'], 'dismiss' => ['unsubstantiated', 'no_action_needed', 'false_report'], 'suspend_user' => ['substantiated'], 'suspend_listing' => ['substantiated']];
    $outcome = sanitize($_POST['outcome'] ?? '');
    if (isset($allowedOutcomes[$action]) && !in_array($outcome, $allowedOutcomes[$action], true)) $outcome = $allowedOutcomes[$action][0];

    $stmt = $pdo->prepare("
        SELECT r.*,
               u.email AS reporter_email,
               u.full_name AS reporter_name
        FROM reports r
        JOIN users u ON u.user_id = r.reporter_id
        WHERE r.report_id = ?
    ");
    $stmt->execute([$report_id]);
    $report = $stmt->fetch();

    if ($report && in_array($action, ['resolve', 'dismiss', 'suspend_user', 'suspend_listing'], true) && !in_array($report['status'], ['pending', 'reviewed'], true)) {
        setFlash('info', 'This report was already handled, so nothing changed and no email was sent again.');
    } elseif ($report) {
        switch ($action) {
            case 'investigate':
                // v9.38.1: take the report into investigation. Nothing is suspended; the reporter is told it is being looked at.
                $u = $pdo->prepare("UPDATE reports SET status = 'reviewed', handled_by = ? WHERE report_id = ? AND status = 'pending'");
                $u->execute([(int)$_SESSION['user_id'], $report_id]);
                if ($u->rowCount() === 1) {
                    logReportEvent($pdo, $report_id, (int)$_SESSION['user_id'], 'investigating', null, $internal);
                    notify($pdo, (int)$report['reporter_id'], 'We are looking into your report', 'Report #RPT' . str_pad((string)$report_id, 5, '0', STR_PAD_LEFT) . ' is being investigated. You will be told the outcome.', '');
                    setFlash('success', 'Marked as under investigation. The reporter was told.');
                } else { setFlash('info', 'This report is already being investigated or has been closed.'); }
                break;

            case 'add_note':
                if ($internal === '') { setFlash('error', 'Write the note first.'); break; }
                logReportEvent($pdo, $report_id, (int)$_SESSION['user_id'], 'note', null, $internal);
                setFlash('success', 'Private note saved. Only admins can see it.');
                break;

            case 'resolve':
                $pdo->prepare("
                    UPDATE reports
                    SET status = 'resolved',
                        admin_action = ?
                    WHERE report_id = ?
                ")->execute([$admin_action, $report_id]);

                // Notify reporter
                sendEmail(
                    $report['reporter_email'],
                    'Report Update — StayExplore Kenya',
                    "
                    <h2>Your report has been resolved</h2>
                    <p>Dear {$report['reporter_name']},</p>
                    <p>Your report #RPT" .
                    str_pad($report_id, 5, '0', STR_PAD_LEFT) .
                    " has been reviewed and resolved.</p>
                    <p><strong>Action taken:</strong>
                       $admin_action</p>
                    <p>Thank you for helping keep our platform safe.
                    </p>
                    "
                );
                setFlash('success', 'Report marked as resolved.');
                break;

            case 'dismiss':
                $pdo->prepare("
                    UPDATE reports
                    SET status = 'dismissed',
                        admin_action = ?
                    WHERE report_id = ?
                ")->execute([
                    $admin_action ?: 'Report dismissed.',
                    $report_id
                ]);

                // Restore listing if it was suspended
                if ($report['listing_suspended']
                    && $report['report_type'] === 'incident') {
                    $pdo->prepare("
                        UPDATE accommodations
                        SET status = 'approved'
                        WHERE accommodation_id = ?
                    ")->execute([$report['target_id']]);
                }
                setFlash('success', 'Report dismissed.');
                break;

            case 'suspend_user':
                $pdo->prepare("
                    UPDATE users SET status = 'suspended'
                    WHERE user_id = ?
                ")->execute([$report['target_id']]);

                $pdo->prepare("
                    UPDATE reports
                    SET status = 'resolved',
                        admin_action = ?
                    WHERE report_id = ?
                ")->execute([
                    'User account suspended. ' . $admin_action,
                    $report_id
                ]);

                // Get target user details
                $target = $pdo->prepare("
                    SELECT email, full_name FROM users
                    WHERE user_id = ?
                ");
                $target->execute([$report['target_id']]);
                $target = $target->fetch();

                if ($target) {
                    sendEmail(
                        $target['email'],
                        'Account Suspended — StayExplore Kenya',
                        "
                        <h2>Account suspended</h2>
                        <p>Dear {$target['full_name']},</p>
                        <p>Your account has been suspended following
                           a report review.</p>
                        <p><strong>Reason:</strong> $admin_action</p>
                        "
                    );
                }
                setFlash('success',
                    'User suspended and report resolved.');
                break;

            case 'suspend_listing':
                $pdo->prepare("
                    UPDATE accommodations
                    SET status = 'suspended'
                    WHERE accommodation_id = ?
                ")->execute([$report['target_id']]);

                $pdo->prepare("
                    UPDATE reports
                    SET status = 'resolved',
                        listing_suspended = 1,
                        admin_action = ?
                    WHERE report_id = ?
                ")->execute([
                    'Listing suspended. ' . $admin_action,
                    $report_id
                ]);
                setFlash('success',
                    'Listing suspended and report resolved.');
                break;

            case 'restore_listing':
                $pdo->prepare("
                    UPDATE accommodations
                    SET status = 'approved'
                    WHERE accommodation_id = ?
                ")->execute([$report['target_id']]);

                $pdo->prepare("
                    UPDATE reports
                    SET listing_suspended = 0,
                        admin_action = ?
                    WHERE report_id = ?
                ")->execute([
                    'Listing restored. ' . $admin_action,
                    $report_id
                ]);
                setFlash('success', 'Listing restored.');
                break;
        }
        // v9.38.1: people hear the outcome in the app, not only by email.
        try {
            $rl = 'Report #RPT' . str_pad((string)$report_id, 5, '0', STR_PAD_LEFT);
            if (in_array($action, ['resolve', 'dismiss', 'suspend_user', 'suspend_listing'], true)) {
                // Record how it ended and who closed it, and write it to the history (the private note stays private).
                $pdo->prepare('UPDATE reports SET outcome = ?, handled_by = ?, handled_at = NOW() WHERE report_id = ?')->execute([$outcome, (int)$_SESSION['user_id'], $report_id]);
                logReportEvent($pdo, $report_id, (int)$_SESSION['user_id'], $action, $outcome, $internal);
            } elseif ($action === 'restore_listing') {
                logReportEvent($pdo, $report_id, (int)$_SESSION['user_id'], 'restore_listing', null, $internal);
            }
            if (in_array($action, ['resolve', 'suspend_user', 'suspend_listing'], true)) {
                notify($pdo, (int)$report['reporter_id'], 'Your report was resolved', $rl . ' was reviewed and resolved. ' . reportOutcomeForReporter($outcome) . ($admin_action !== '' ? ' Action taken: ' . $admin_action : '') . ' Thank you for helping keep the platform safe.', '');
            } elseif ($action === 'dismiss') {
                notify($pdo, (int)$report['reporter_id'], 'Your report was reviewed', $rl . ' was reviewed and closed. ' . reportOutcomeForReporter($outcome) . ($admin_action !== '' ? ' Note: ' . $admin_action : ''), '');
            }
            if (in_array($action, ['suspend_listing', 'restore_listing'], true) && !empty($report['target_id'])) {
                $hl = $pdo->prepare('SELECT host_id, title FROM accommodations WHERE accommodation_id = ?'); $hl->execute([(int)$report['target_id']]);
                if ($hrow = $hl->fetch(PDO::FETCH_ASSOC)) {
                    if ($action === 'suspend_listing') notify($pdo, (int)$hrow['host_id'], 'Your listing was suspended', '"' . $hrow['title'] . '" was taken offline while a report is looked into. Guests cannot book it for now. Contact support if you have questions.', 'host/listings.php');
                    else notify($pdo, (int)$hrow['host_id'], 'Your listing is live again', '"' . $hrow['title'] . '" was restored and guests can book it again.', 'host/listings.php');
                }
            }
        } catch (Throwable $e) { error_log('report notifications: ' . $e->getMessage()); }
    }
    redirect('reports.php?filter=' .
             sanitize($_GET['filter'] ?? 'pending'));
}

$filter = sanitize($_GET['filter'] ?? 'pending');

$where = "1=1";
if ($filter === 'pending') {
    $where = "r.status IN ('pending','reviewed')";
} elseif ($filter === 'resolved') {
    $where = "r.status = 'resolved'";
} elseif ($filter === 'dismissed') {
    $where = "r.status = 'dismissed'";
} elseif ($filter === 'incident') {
    $where = "r.report_type = 'incident'";
}

$reports = $pdo->query("
    SELECT r.*,
           u.full_name AS reporter_name,
           u.email AS reporter_email,
           u.role AS reporter_role,
           t.full_name AS target_name,
           t.email AS target_email,
           t.role AS target_role,
           t.status AS target_status,
           a.title AS listing_title,
           a.status AS listing_status
    FROM reports r
    JOIN users u ON u.user_id = r.reporter_id
    LEFT JOIN users t ON t.user_id = r.target_id
    LEFT JOIN accommodations a
        ON a.accommodation_id = r.target_id
        AND r.report_type IN ('listing','incident')
    WHERE $where
    ORDER BY
        CASE WHEN r.report_type = 'incident'
             THEN 0 ELSE 1 END ASC,
        r.created_at DESC
")->fetchAll();

$counts = $pdo->query("
    SELECT
        SUM(status IN ('pending','reviewed')) AS pending,
        SUM(status='resolved') AS resolved,
        SUM(status='dismissed') AS dismissed,
        SUM(report_type='incident') AS incidents
    FROM reports
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .badge       { font-size:10px; background:#DC2626;
                       color:#fff; border-radius:10px;
                       padding:1px 6px; margin-left:auto }
        .main        { padding:28px }
        .filter-tabs { display:flex; gap:6px; margin-bottom:20px;
                       flex-wrap:wrap }
        .filter-tab  { padding:6px 14px; font-size:12px;
                       border-radius:4px; text-decoration:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border);
                       display:flex; align-items:center; gap:6px }
        .filter-tab.active { background:var(--btn-hover); color:#fff;
                             border-color:var(--accent-strong) }
        .report-card { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:20px;
                       margin-bottom:12px }
        .report-card.incident { border-color:var(--bad-line);
                                border-left:3px solid #DC2626 }
        .report-top  { display:flex; justify-content:space-between;
                       align-items:flex-start; margin-bottom:14px }
        .report-type { font-size:13px; font-weight:600;
                       text-transform:capitalize;
                       margin-bottom:3px }
        .report-ref  { font-size:12px; color:var(--text-secondary) }
        .status-pill { display:inline-block; font-size:11px;
                       font-weight:600; padding:3px 9px;
                       border-radius:3px }
        .s-pending   { background:var(--warn-bg); color:var(--warn-fg);
                       border:1px solid var(--warn-line) }
        .s-resolved  { background:var(--accent-soft); color:var(--accent-strong);
                       border:1px solid var(--accent-line) }
        .s-dismissed { background:var(--surface-1);
                       color:var(--text-secondary);
                       border:1px solid var(--border) }
        .report-grid { display:grid;
                       grid-template-columns:1fr 1fr;
                       gap:12px; margin-bottom:14px }
        .info-block  { background:var(--surface-1);
                       border:1px solid var(--border);
                       border-radius:6px; padding:12px }
        .info-block h4 { font-size:10px; font-weight:700;
                         letter-spacing:.06em; text-transform:uppercase;
                         color:var(--text-secondary); margin-bottom:8px }
        .info-row    { display:flex; justify-content:space-between;
                       font-size:12px; padding:3px 0 }
        .info-label  { color:var(--text-secondary) }
        .info-val    { font-weight:500 }
        .description { background:var(--surface-1);
                       border:1px solid var(--border);
                       border-radius:6px; padding:12px;
                       font-size:13px; line-height:1.6;
                       color:var(--text-primary);
                       margin-bottom:14px }
        .evidence-img { max-width:200px; border-radius:6px;
                        border:1px solid var(--border);
                        margin-bottom:14px; display:block }
        .suspended-notice { background:var(--bad-bg);
                            border:1px solid var(--bad-line);
                            border-radius:6px; padding:10px 14px;
                            font-size:12px; color:var(--bad-fg);
                            margin-bottom:14px;
                            display:flex; align-items:center;
                            gap:8px }
        .action-section { border-top:1px solid var(--border);
                          padding-top:14px }
        .action-section h4 { font-size:12px; font-weight:600;
                             letter-spacing:.04em; text-transform:uppercase;
                             color:var(--text-secondary);
                             margin-bottom:10px }
        .action-row  { display:flex; gap:8px; flex-wrap:wrap;
                       align-items:flex-start }
        .action-note { width:100%; margin-top:8px }
        .action-note textarea { width:100%; padding:8px 12px;
                                border:1px solid var(--border-strong);
                                border-radius:6px; font-size:13px;
                                resize:none; height:60px;
                                font-family:inherit;
                                background:var(--surface-2);
                                color:var(--text-primary) }
        .action-note textarea:focus { outline:none;
                                      border-color:var(--accent) }
        .btn-resolve { padding:7px 16px; background:var(--btn-hover);
                       color:#fff; border:none; border-radius:4px;
                       font-size:12px; font-weight:500; cursor:pointer }
        .btn-dismiss { padding:7px 14px; background:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border-strong);
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-suspend { padding:7px 14px; background:none;
                       color:var(--warn-fg); border:1px solid var(--warn-line);
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-danger  { padding:7px 14px; background:none;
                       color:var(--bad-fg); border:1px solid var(--bad-line);
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-restore { padding:7px 14px; background:none;
                       color:var(--accent-strong); border:1px solid var(--accent-line);
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .admin-action-note { background:var(--accent-soft);
                             border:1px solid var(--accent-line);
                             border-radius:6px; padding:10px 14px;
                             font-size:12px; color:var(--accent-strong);
                             margin-top:10px }
        .emergency-box { background:var(--bad-bg);
                         border:1px solid var(--bad-line);
                         border-radius:6px; padding:12px 14px;
                         margin-bottom:14px }
        .emergency-box h4 { font-size:12px; font-weight:700;
                            color:var(--bad-fg); margin-bottom:4px }
        .emergency-box p  { font-size:12px; color:var(--bad-fg) }
        .empty-state { text-align:center; padding:48px;
                       font-size:14px;
                       color:var(--text-secondary) }
        @media(max-width:1024px){
            .sidebar   { display:none }
            .report-grid { grid-template-columns:1fr }
        }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Reports</h1>
        <p class="page-sub">
            Handle incident reports, listing flags,
            and guest concerns.
        </p>

        <?php $autoSuspend = siteSettingGet('report_auto_suspend', '1') === '1'; ?>
        <form method="POST" class="no-print" style="margin:0 0 16px;padding:12px 14px;border:1px solid var(--border);border-radius:10px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="set_auto_suspend">
            <input type="hidden" name="value" value="<?= $autoSuspend ? '0' : '1' ?>">
            <span><strong>Automatic suspension after a critical incident:</strong> <?= $autoSuspend ? 'ON. The listing is suspended at once and you are alerted.' : 'OFF. You are alerted and decide whether to suspend.' ?></span>
            <button type="submit" class="btn-dismiss"><?= $autoSuspend ? 'Switch off' : 'Switch on' ?></button>
        </form>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=pending"
               class="filter-tab <?= $filter==='pending'
                   ?'active':'' ?>">
                Pending
                <span style="font-size:11px">
                    (<?= $counts['pending'] ?>)
                </span>
            </a>
            <a href="?filter=incident"
               class="filter-tab <?= $filter==='incident'
                   ?'active':'' ?>">
                Incidents
                <span style="font-size:11px">
                    (<?= $counts['incidents'] ?>)
                </span>
            </a>
            <a href="?filter=resolved"
               class="filter-tab <?= $filter==='resolved'
                   ?'active':'' ?>">
                Resolved
                <span style="font-size:11px">
                    (<?= $counts['resolved'] ?>)
                </span>
            </a>
            <a href="?filter=dismissed"
               class="filter-tab <?= $filter==='dismissed'
                   ?'active':'' ?>">
                Dismissed
                <span style="font-size:11px">
                    (<?= $counts['dismissed'] ?>)
                </span>
            </a>
        </div>

        <?php if (empty($reports)): ?>
            <div class="empty-state">
                No <?= $filter ?> reports found.
            </div>
        <?php else: ?>
            <?php
            $histAll = [];
            if ($reports) {
                $ids = implode(',', array_map(fn($r) => (int)$r['report_id'], $reports));
                foreach ($pdo->query("SELECT h.*, u.full_name AS actor_name FROM report_history h LEFT JOIN users u ON u.user_id = h.actor_id WHERE h.report_id IN ($ids) ORDER BY h.history_id")->fetchAll(PDO::FETCH_ASSOC) as $hrow) $histAll[(int)$hrow['report_id']][] = $hrow;
            }
            $actionLabels = ['submitted' => 'Report received', 'investigating' => 'Investigation started', 'note' => 'Private note', 'resolve' => 'Marked resolved', 'dismiss' => 'Dismissed', 'suspend_user' => 'User suspended', 'suspend_listing' => 'Listing suspended', 'restore_listing' => 'Listing restored'];
            ?>
            <?php foreach ($reports as $rep): ?>
            <div class="report-card <?= $rep['report_type'] === 'incident'
                ? 'incident' : '' ?>">

                <!-- Top -->
                <div class="report-top">
                    <div>
                        <div class="report-type">
                            <?php
                            $type_labels = [
                                'listing'  => 'Listing misrepresentation',
                                'review'   => 'Suspicious review',
                                'tourist'  => 'Guest behaviour concern',
                                'incident' => 'Critical incident'
                            ];
                            echo $type_labels[$rep['report_type']]
                                 ?? ucfirst($rep['report_type']);
                            ?>
                            <?php if (($rep['incident_type'] ?? '') === 'host_conduct'): ?><span class="badge" style="background:#fdecea;color:#8a1c14;padding:2px 8px;border-radius:10px;font-size:11px;margin-left:6px">Host conduct</span><?php elseif ($rep['incident_type']): ?>
                                &mdash;
                                <span style="font-weight:400;
                                             text-transform:capitalize">
                                    <?= str_replace('_', ' ',
                                        $rep['incident_type']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="report-ref">
                            #RPT<?= str_pad($rep['report_id'],
                                5, '0', STR_PAD_LEFT) ?>
                            &middot;
                            Submitted
                            <?= date('d M Y',
                                strtotime($rep['created_at'])) ?>
                            at
                            <?= date('H:i',
                                strtotime($rep['created_at'])) ?>
                        </div>
                    </div>
                    <?php
                    $spill = [
                        'pending'   => 's-pending',
                        'resolved'  => 's-resolved',
                        'dismissed' => 's-dismissed',
                        'reviewed'  => 's-resolved'
                    ][$rep['status']] ?? 's-pending';
                    ?>
                    <span class="status-pill <?= $spill ?>">
                        <?= $rep['status'] === 'reviewed' ? 'Under investigation' : ucfirst($rep['status']) ?>
                    </span>
                </div>

                <!-- Emergency notice for incidents -->
                <?php if ($rep['report_type'] === 'incident'): ?>
                <div class="emergency-box">
                    <h4>Critical incident report</h4>
                    <p>
                        If anyone is in immediate danger,
                        contact emergency services on
                        <strong>999</strong> or
                        <strong>112</strong> immediately.
                        This report requires urgent attention.
                    </p>
                </div>
                <?php endif; ?>

                <!-- Suspended listing notice -->
                <?php if ($rep['listing_suspended']): ?>
                <div class="suspended-notice">
                    Listing automatically suspended
                    pending this review
                    <?php if ($rep['listing_title']): ?>
                        &mdash;
                        <strong>
                            <?= h($rep['listing_title']) ?>
                        </strong>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Reporter and Target Info -->
                <div class="report-grid">
                    <div class="info-block">
                        <h4>Reported by</h4>
                        <div class="info-row">
                            <span class="info-label">Name</span>
                            <span class="info-val">
                                <?= h(
                                    $rep['reporter_name']) ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email</span>
                            <span class="info-val">
                                <?= h(
                                    $rep['reporter_email']) ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Role</span>
                            <span class="info-val">
                                <?= ucfirst($rep['reporter_role']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="info-block">
                        <h4>
                            <?= in_array($rep['report_type'],
                                ['listing','incident'])
                                ? 'Reported listing'
                                : 'Reported user' ?>
                        </h4>
                        <?php if (in_array($rep['report_type'],
                            ['listing','incident'])
                            && $rep['listing_title']): ?>
                            <div class="info-row">
                                <span class="info-label">Listing</span>
                                <span class="info-val">
                                    <?= h(
                                        $rep['listing_title']) ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status</span>
                                <span class="info-val">
                                    <?= ucfirst(
                                        $rep['listing_status'] ?? '—') ?>
                                </span>
                            </div>
                        <?php elseif ($rep['target_name']): ?>
                            <div class="info-row">
                                <span class="info-label">Name</span>
                                <span class="info-val">
                                    <?= h(
                                        $rep['target_name']) ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Email</span>
                                <span class="info-val">
                                    <?= h(
                                        $rep['target_email']) ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Status</span>
                                <span class="info-val">
                                    <?= ucfirst(
                                        $rep['target_status'] ?? '—') ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Description -->
                <div class="description">
                    <?= nl2br(h($rep['description'])) ?>
                </div>

                <!-- Evidence -->
                <?php if ($rep['evidence_path']): ?>
                <img src="../uploads/evidence/<?= h(
                    $rep['evidence_path']) ?>"
                     alt="Evidence photo submitted with this report"
                     class="evidence-img" loading="lazy" decoding="async">
                <?php endif; ?>

                <!-- Admin action taken -->
                <?php if ($rep['admin_action']): ?>
                <div class="admin-action-note">
                    <strong>Action taken:</strong>
                    <?= h($rep['admin_action']) ?>
                </div>
                <?php endif; ?>

                <!-- v9.38.1: context for the investigation, and the history of this report -->
                <?php
                $rid = (int)$rep['report_id'];
                $rr = reporterRecord($pdo, (int)$rep['reporter_id']);
                $tr = targetReportRecord($pdo, $rep['report_type'], (int)$rep['target_id'], $rid);
                $affected = 0;
                if (in_array($rep['report_type'], ['listing', 'incident'], true) && $rep['listing_title'] !== null) {
                    $aq = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE accommodation_id = ? AND status IN ('accepted','payment_pending','confirmed') AND check_out_date >= CURDATE()");
                    $aq->execute([(int)$rep['target_id']]); $affected = (int)$aq->fetchColumn();
                }
                $isOpen = in_array($rep['status'], ['pending', 'reviewed'], true);
                ?>
                <div class="report-context" style="margin-top:12px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;font-size:13px;display:grid;gap:6px">
                    <div><strong>Reporter record:</strong> <?= (int)$rr['total'] ?> report<?= $rr['total'] === 1 ? '' : 's' ?> in total: <?= (int)$rr['upheld'] ?> upheld, <?= (int)$rr['not_upheld'] + (int)$rr['inconclusive'] ?> not upheld or inconclusive<?= $rr['false_reports'] > 0 ? ', <strong>' . (int)$rr['false_reports'] . ' marked false</strong>' : '' ?>.</div>
                    <?php if ($tr['total'] > 0): ?><div><strong>Other reports about this <?= $rep['report_type'] === 'tourist' ? 'guest' : 'listing' ?>:</strong> <?= (int)$tr['total'] ?> (<?= (int)$tr['upheld'] ?> upheld).</div><?php endif; ?>
                    <?php if ($isOpen && $affected > 0): ?><div role="status"><strong>Before you suspend the listing:</strong> <?= $affected ?> active booking<?= $affected === 1 ? '' : 's' ?> on it (accepted, awaiting payment or confirmed) would be affected. Contact those guests or arrange alternatives if you suspend it.</div><?php endif; ?>
                    <?php if (!$isOpen && !empty($rep['outcome'])): ?><div><strong>Outcome:</strong> <?= h(reportOutcomeLabel($rep['outcome'])) ?></div><?php endif; ?>
                    <details>
                        <summary style="cursor:pointer"><strong>History</strong> (<?= count($histAll[$rid] ?? []) ?>)</summary>
                        <ol style="margin:8px 0 0;padding-left:18px;display:grid;gap:6px">
                            <li>Filed <?= h(date('j M Y, H:i', strtotime($rep['created_at']))) ?> by <?= h($rep['reporter_name']) ?></li>
                            <?php foreach ($histAll[$rid] ?? [] as $hr): ?>
                                <li><?= h(date('j M Y, H:i', strtotime($hr['created_at']))) ?>: <strong><?= h($actionLabels[$hr['action']] ?? $hr['action']) ?></strong> by <?= h($hr['actor_name'] ?: 'the system') ?><?= $hr['outcome'] ? ' (' . h(reportOutcomeLabel($hr['outcome'])) . ')' : '' ?><?= $hr['note'] ? '<br><span style="opacity:.85">Private note: ' . nl2br(h($hr['note'])) . '</span>' : '' ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </details>
                    <?php if (!$isOpen): ?>
                    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="report_id" value="<?= $rid ?>"><input type="hidden" name="action" value="add_note">
                        <label class="sr-only" for="late-<?= $rid ?>">Private note</label>
                        <input id="late-<?= $rid ?>" name="internal_note" maxlength="2000" placeholder="Add a private note (admins only)" style="flex:1;min-width:200px;padding:8px 10px" required>
                        <button type="submit" class="btn-dismiss">Save note</button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Actions — only for pending/reviewed -->
                <?php if (in_array($rep['status'],
                    ['pending','reviewed'])): ?>
                <div class="action-section">
                    <h4>Take action</h4>

                    <div class="action-note">
                        <textarea aria-label="Describe the action taken (sent to reporter)" id="note-<?= $rep['report_id'] ?>"
                                  placeholder="Describe the action taken (sent to reporter)...">
                        </textarea>
                    </div>

                    <div class="action-note">
                        <label class="sr-only" for="internal-<?= $rep['report_id'] ?>">Private note</label>
                        <textarea aria-label="Private note for admins only (never sent to anyone)" id="internal-<?= $rep['report_id'] ?>"
                                  placeholder="Private note for admins only: what you found, who you spoke to. Never sent to anyone."></textarea>
                    </div>
                    <div class="action-note">
                        <label for="outcome-<?= $rep['report_id'] ?>" style="font-size:12px;display:block;margin-bottom:4px">Outcome when you close it</label>
                        <select id="outcome-<?= $rep['report_id'] ?>" style="padding:8px 10px;width:100%">
                            <option value="substantiated">Upheld: the report was confirmed</option>
                            <option value="inconclusive">Inconclusive: not enough evidence</option>
                            <option value="unsubstantiated">Not upheld: no problem found</option>
                            <option value="no_action_needed">No action needed</option>
                            <option value="false_report">False or malicious report</option>
                        </select>
                        <div style="font-size:12px;opacity:.8;margin-top:4px">Resolve and suspend accept the first two; Dismiss accepts the last three. Three false reports in 180 days pause that person from filing more.</div>
                    </div>
                    <div class="action-row" style="margin-top:8px">
                        <?php if ($rep['status'] === 'pending'): ?>
                        <form method="POST" style="margin:0" onsubmit="setNote(this, <?= $rep['report_id'] ?>)">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="report_id" value="<?= $rep['report_id'] ?>"><input type="hidden" name="action" value="investigate">
                            <input type="hidden" name="admin_action" value=""><input type="hidden" name="internal_note" value=""><input type="hidden" name="outcome" value="">
                            <button type="submit" class="btn-restore">Start investigation</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" style="margin:0" onsubmit="setNote(this, <?= $rep['report_id'] ?>)">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="report_id" value="<?= $rep['report_id'] ?>"><input type="hidden" name="action" value="add_note">
                            <input type="hidden" name="admin_action" value=""><input type="hidden" name="internal_note" value=""><input type="hidden" name="outcome" value="">
                            <button type="submit" class="btn-dismiss">Save private note</button>
                        </form>
                    </div>

                    <div class="action-row" style="margin-top:8px">

                        <!-- Resolve -->
                        <form method="POST" style="margin:0"
                              onsubmit="setNote(this,
                                  <?= $rep['report_id'] ?>)">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="report_id"
                                   value="<?= $rep['report_id'] ?>">

                            <input type="hidden" name="action"
                                   value="resolve">
                            <input type="hidden" name="admin_action"
                                   id="admin_action_resolve_<?= $rep['report_id'] ?>">
                            <input type="hidden" name="internal_note" value=""><input type="hidden" name="outcome" value="">

                            <button type="submit" class="btn-resolve">
                                Mark resolved
                            </button>
                        </form>

                        <!-- Dismiss -->
                        <form method="POST" style="margin:0"
                              onsubmit="setNote(this,
                                  <?= $rep['report_id'] ?>)">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="report_id"
                                   value="<?= $rep['report_id'] ?>">

                            <input type="hidden" name="action"
                                   value="dismiss">
                            <input type="hidden" name="admin_action"
                                   id="admin_action_dismiss_<?= $rep['report_id'] ?>">
                            <input type="hidden" name="internal_note" value=""><input type="hidden" name="outcome" value="">

                            <button type="submit" class="btn-dismiss"
                                    onclick="return confirm(
                                        'Dismiss this report?')">
                                Dismiss
                            </button>
                        </form>

                        <!-- Suspend user -->
                        <?php if ($rep['target_name']
                            && in_array($rep['report_type'],
                                ['tourist','incident'])): ?>
                        <form method="POST" style="margin:0"
                              onsubmit="setNote(this,
                                  <?= $rep['report_id'] ?>)">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="report_id"
                                   value="<?= $rep['report_id'] ?>">

                            <input type="hidden" name="action"
                                   value="suspend_user">
                            <input type="hidden" name="admin_action"
                                   id="admin_action_suspend_user_<?= $rep['report_id'] ?>">
                            <input type="hidden" name="internal_note" value=""><input type="hidden" name="outcome" value="">

                            <button type="submit" class="btn-suspend"
                                    onclick="return confirm(
                                        'Suspend this user account?')">
                                Suspend user
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- Suspend listing -->
                        <?php if (in_array($rep['report_type'],
                            ['listing','incident'])
                            && ($rep['listing_status'] === 'approved'
                                || $rep['listing_status'] === null)):?>
                        <form method="POST" style="margin:0"
                              onsubmit="setNote(this,
                                  <?= $rep['report_id'] ?>)">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="report_id"
                                   value="<?= $rep['report_id'] ?>">

                            <input type="hidden" name="action"
                                   value="suspend_listing">
                            <input type="hidden" name="admin_action"
                                   id="admin_action_suspend_listing_<?= $rep['report_id'] ?>">
                            <input type="hidden" name="internal_note" value=""><input type="hidden" name="outcome" value="">

                            <button type="submit" class="btn-danger"
                                    onclick="return confirm(
                                        'Suspend this listing?')">
                                Suspend listing
                            </button>
                        </form>
                        <?php endif; ?>

                        <!-- Restore listing -->
                        <?php if ($rep['listing_suspended']
                            || $rep['listing_status'] === 'suspended'): ?>
                        <form method="POST" style="margin:0"
                              onsubmit="setNote(this,
                                  <?= $rep['report_id'] ?>)">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="report_id"
                                   value="<?= $rep['report_id'] ?>">

                            <input type="hidden" name="action"
                                   value="restore_listing">
                            <input type="hidden" name="admin_action"
                                   id="admin_action_restore_listing_<?= $rep['report_id'] ?>">
                            <input type="hidden" name="internal_note" value=""><input type="hidden" name="outcome" value="">

                            <button type="submit" class="btn-restore"
                                    onclick="return confirm(
                                        'Restore this listing?')">
                                Restore listing
                            </button>
                        </form>
                        <?php endif; ?>

                    </div>
                </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php appShellEnd(); ?>

<script>
// Copy textarea note into the hidden input of the
// submitted form
function setNote(form, id) {
    const note = document.getElementById('note-' + id).value;
    // Find the admin_action hidden input in this form
    const hidden = form.querySelector('[name="admin_action"]');
    if (hidden) hidden.value = note;
    const inn = document.getElementById('internal-' + id), out = document.getElementById('outcome-' + id);
    const hi = form.querySelector('[name="internal_note"]'), ho = form.querySelector('[name="outcome"]');
    if (hi && inn) hi.value = inn.value;
    if (ho && out) ho.value = out.value;
}
</script>

</body>
</html>