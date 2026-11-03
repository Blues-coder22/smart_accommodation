<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $report_id    = (int)($_POST['report_id'] ?? 0);
    $action       = sanitize($_POST['action'] ?? '');
    $admin_action = sanitize($_POST['admin_action'] ?? '');

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

    if ($report && in_array($action, ['resolve', 'dismiss', 'suspend_user', 'suspend_listing'], true) && $report['status'] !== 'pending') {
        setFlash('info', 'This report has already been handled, so nothing was changed and nobody was emailed again.');
    } elseif ($report) {
        switch ($action) {
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
    }
    redirect('reports.php?filter=' .
             sanitize($_GET['filter'] ?? 'pending'));
}

$filter = sanitize($_GET['filter'] ?? 'pending');

$where = "1=1";
if ($filter === 'pending') {
    $where = "r.status = 'pending'";
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
        SUM(status='pending') AS pending,
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
    <style>
        
        .dash-wrap   { display:grid;
                       grid-template-columns:200px 1fr; flex:1 }
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .sidebar-role{ font-size:10px; font-weight:700;
                       letter-spacing:.08em; color:#DC2626;
                       text-transform:uppercase;
                       padding:0 20px; margin-bottom:12px }
        .sidebar-link{ display:flex; align-items:center; gap:10px;
                       padding:9px 20px; font-size:13px;
                       color:var(--text-secondary);
                       text-decoration:none; width:100% }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #1D9E75 }
        .badge       { font-size:10px; background:#DC2626;
                       color:#fff; border-radius:10px;
                       padding:1px 6px; margin-left:auto }
        .main        { padding:28px }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#1D9E75;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:4px }
        .page-sub    { font-size:13px; color:var(--text-secondary);
                       margin-bottom:20px }
        .filter-tabs { display:flex; gap:6px; margin-bottom:20px;
                       flex-wrap:wrap }
        .filter-tab  { padding:6px 14px; font-size:12px;
                       border-radius:4px; text-decoration:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border);
                       display:flex; align-items:center; gap:6px }
        .filter-tab.active { background:#085041; color:#fff;
                             border-color:#085041 }
        .report-card { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:20px;
                       margin-bottom:12px }
        .report-card.incident { border-color:#FECACA;
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
        .s-pending   { background:#FFF8E1; color:#9A6A00;
                       border:1px solid #F9D57C }
        .s-resolved  { background:#E1F5EE; color:#0F6E56;
                       border:1px solid #9FE1CB }
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
        .suspended-notice { background:#FEF2F2;
                            border:1px solid #FECACA;
                            border-radius:6px; padding:10px 14px;
                            font-size:12px; color:#991B1B;
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
                                      border-color:#1D9E75 }
        .btn-resolve { padding:7px 16px; background:#085041;
                       color:#fff; border:none; border-radius:4px;
                       font-size:12px; font-weight:500; cursor:pointer }
        .btn-dismiss { padding:7px 14px; background:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border-strong);
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-suspend { padding:7px 14px; background:none;
                       color:#D97706; border:1px solid #F9D57C;
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-danger  { padding:7px 14px; background:none;
                       color:#DC2626; border:1px solid #FECACA;
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-restore { padding:7px 14px; background:none;
                       color:#0F6E56; border:1px solid #9FE1CB;
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .admin-action-note { background:#E1F5EE;
                             border:1px solid #9FE1CB;
                             border-radius:6px; padding:10px 14px;
                             font-size:12px; color:#0F6E56;
                             margin-top:10px }
        .emergency-box { background:#FEF2F2;
                         border:1px solid #FECACA;
                         border-radius:6px; padding:12px 14px;
                         margin-bottom:14px }
        .emergency-box h4 { font-size:12px; font-weight:700;
                            color:#DC2626; margin-bottom:4px }
        .emergency-box p  { font-size:12px; color:#991B1B }
        .empty-state { text-align:center; padding:48px;
                       font-size:14px;
                       color:var(--text-secondary) }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .report-grid { grid-template-columns:1fr }
        }
    </style>
</head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Reports</h1>
        <p class="page-sub">
            Handle incident reports, listing flags,
            and guest concerns.
        </p>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=pending"
               class="filter-tab <?= $filter==='pending'
                   ?'active':'' ?>">
                Pending
                <span style="font-size:10px;opacity:.8">
                    (<?= $counts['pending'] ?>)
                </span>
            </a>
            <a href="?filter=incident"
               class="filter-tab <?= $filter==='incident'
                   ?'active':'' ?>">
                Incidents
                <span style="font-size:10px;opacity:.8">
                    (<?= $counts['incidents'] ?>)
                </span>
            </a>
            <a href="?filter=resolved"
               class="filter-tab <?= $filter==='resolved'
                   ?'active':'' ?>">
                Resolved
                <span style="font-size:10px;opacity:.8">
                    (<?= $counts['resolved'] ?>)
                </span>
            </a>
            <a href="?filter=dismissed"
               class="filter-tab <?= $filter==='dismissed'
                   ?'active':'' ?>">
                Dismissed
                <span style="font-size:10px;opacity:.8">
                    (<?= $counts['dismissed'] ?>)
                </span>
            </a>
        </div>

        <?php if (empty($reports)): ?>
            <div class="empty-state">
                No <?= $filter ?> reports found.
            </div>
        <?php else: ?>
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
                            <?php if ($rep['incident_type']): ?>
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
                        <?= ucfirst($rep['status']) ?>
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
                     class="evidence-img">
                <?php endif; ?>

                <!-- Admin action taken -->
                <?php if ($rep['admin_action']): ?>
                <div class="admin-action-note">
                    <strong>Action taken:</strong>
                    <?= h($rep['admin_action']) ?>
                </div>
                <?php endif; ?>

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
}
</script>

</body>
</html>