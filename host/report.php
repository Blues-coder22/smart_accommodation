<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/upload_helpers.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Handle form submission
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $report_type  = sanitize($_POST['report_type'] ?? '');
    $incident_type= sanitize($_POST['incident_type'] ?? '');
    $booking_id   = (int)($_POST['booking_id'] ?? 0);
    $description  = sanitize($_POST['description'] ?? '');
    $evidence     = null;

    if (empty($description) || strlen($description) < 20) {
        $errors[] = 'Please provide more details (at least 20 characters).';
    }
    if (!in_array($report_type, ['listing', 'review', 'tourist', 'incident'], true)) {
        $errors[] = 'Please choose a valid report type.';
    }

    // Verify booking belongs to host if provided
    $booking = null;
    if ($booking_id) {
        $stmt = $pdo->prepare("
            SELECT b.*, a.title, a.accommodation_id,
                   u.full_name AS tourist_name
            FROM bookings b
            JOIN accommodations a
                ON a.accommodation_id = b.accommodation_id
            JOIN users u ON u.user_id = b.tourist_id
            WHERE b.booking_id = ? AND a.host_id = ?
        ");
        $stmt->execute([$booking_id, $host_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $errors[] = 'Invalid booking selected.';
        }
    }

    // Handle evidence upload
    if (isset($_FILES['evidence'])
        && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/webp'];
        $ftype   = mime_content_type($_FILES['evidence']['tmp_name']);

        if (!in_array($ftype, $allowed)) {
            $errors[] = 'Evidence must be a JPG, PNG, or WebP image.';
        } elseif ($_FILES['evidence']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Evidence file must be under 5MB.';
        } else {
            $filename = saveUploadedImage(
                $_FILES['evidence']['tmp_name'], $ftype,
                'evidence', 'evidence', 5 * 1024 * 1024
            );
            if ($filename !== false) {
                $evidence = $filename;
            } else {
                $errors[] = 'The evidence image could not be saved. Please try again.';
            }
        }
    }

    if (empty($errors)) {
        $target_id = $booking
            ? $booking['tourist_id']
            : $host_id;

        $pdo->prepare("
            INSERT INTO reports
            (reporter_id, report_type, target_id,
             description, evidence_path,
             incident_type, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ")->execute([
            $host_id, $report_type,
            $target_id, $description,
            $evidence, $incident_type
        ]);

        $report_id = $pdo->lastInsertId();

        // Notify admin
        $admin = adminNotificationRecipient($pdo);

        if ($admin) {
            $urgency = ($report_type === 'incident')
                ? '*** URGENT *** ' : '';
            $guest_info = $booking
                ? "<p><strong>Guest:</strong>
                   {$booking['tourist_name']}</p>
                   <p><strong>Property:</strong>
                   {$booking['title']}</p>
                   <p><strong>Booking:</strong> #BK" .
                   str_pad($booking_id, 5, '0', STR_PAD_LEFT) .
                   "</p>"
                : '';

            sendEmail(
                $admin['email'],
                $urgency . 'Host Report — StayExplore Kenya',
                "
                <h2>{$urgency}New host report #RPT{$report_id}</h2>
                <p><strong>Type:</strong> $report_type</p>
                $guest_info
                <p><strong>Details:</strong> $description</p>
                " . ($incident_type
                    ? "<p><strong>Incident:</strong>
                       $incident_type</p>" : "") . "
                <p>Please log in to the admin panel to review.</p>
                "
            );
        }

        $success = true;
    }
}

// Get host's recent bookings for selector
$bookings = $pdo->prepare("
    SELECT b.booking_id, a.title,
           u.full_name AS tourist_name,
           b.check_in_date, b.check_out_date
    FROM bookings b
    JOIN accommodations a
        ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    WHERE a.host_id = ?
    AND b.status IN ('confirmed','completed','no_show')
    ORDER BY b.created_at DESC
    LIMIT 20
");
$bookings->execute([$host_id]);
$bookings = $bookings->fetchAll();

// Get host's submitted reports
$my_reports = $pdo->prepare("
    SELECT r.*, b.booking_id,
           a.title AS property_title,
           u.full_name AS target_name
    FROM reports r
    LEFT JOIN bookings b ON b.tourist_id = r.target_id
    LEFT JOIN accommodations a
        ON a.accommodation_id = b.accommodation_id
        AND a.host_id = ?
    LEFT JOIN users u ON u.user_id = r.target_id
    WHERE r.reporter_id = ?
    ORDER BY r.created_at DESC
    LIMIT 10
");
$my_reports->execute([$host_id, $host_id]);
$my_reports = $my_reports->fetchAll();
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
                       letter-spacing:.08em; color:#126b4e;
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
                               border-left:3px solid #126b4e }
        .main        { padding:28px }
        .page-layout { display:grid;
                       grid-template-columns:1fr 260px;
                       gap:20px; align-items:start }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#b42318;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:4px }
        .page-sub    { font-size:13px; color:var(--text-secondary);
                       margin-bottom:24px }
        .form-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:24px;
                       margin-bottom:20px }
        .form-card h3{ font-size:14px; font-weight:500;
                       margin-bottom:18px;
                       padding-bottom:12px;
                       border-bottom:1px solid var(--border) }
        .form-group  { margin-bottom:16px }
        .form-group label { font-size:12px; font-weight:600;
                            letter-spacing:.04em; text-transform:uppercase;
                            display:block; margin-bottom:6px;
                            color:var(--text-secondary) }
        .form-group input,
        .form-group select,
        .form-group textarea { width:100%; padding:10px 12px;
                               border:1px solid var(--border-strong);
                               border-radius:6px; font-size:13px;
                               color:var(--text-primary);
                               background:var(--surface-2);
                               font-family:inherit }
        .form-group textarea { min-height:100px; resize:vertical }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline:none;
                                     border-color:#126b4e }
        .file-zone   { border:0.5px dashed var(--border-strong);
                       border-radius:6px; padding:16px;
                       text-align:center }
        .file-zone input { display:block; width:100%;
                           cursor:pointer }
        .file-zone p { font-size:12px; color:var(--text-secondary);
                       margin-top:6px }
        .submit-btn  { width:100%; padding:11px; background:#DC2626;
                       color:#fff; border:none; border-radius:6px;
                       font-size:13px; font-weight:500; cursor:pointer }
        .submit-btn:hover { background:#b91c1c }
        .side-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:16px;
                       margin-bottom:12px }
        .side-card.urgent { border-color:#DC2626;
                            background:#FEF2F2 }
        .side-card h4{ font-size:13px; font-weight:600;
                       margin-bottom:6px }
        .side-card.urgent h4 { color:#b42318 }
        .side-card p { font-size:13px; color:var(--text-secondary);
                       line-height:1.5 }
        .emergency   { font-size:20px; font-weight:700;
                       color:#b42318; margin:6px 0 }
        .history-card{ background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; overflow:hidden }
        .history-head{ padding:14px 18px;
                       border-bottom:1px solid var(--border) }
        .history-head h3 { font-size:13px; font-weight:500 }
        .history-row { padding:12px 18px;
                       border-bottom:1px solid var(--border);
                       font-size:13px }
        .history-row:last-child { border-bottom:none }
        .history-type{ font-weight:500; margin-bottom:2px;
                       text-transform:capitalize }
        .history-meta{ font-size:11px; color:var(--text-secondary) }
        .status-pill { display:inline-block; font-size:10px;
                       font-weight:600; padding:2px 7px;
                       border-radius:3px; float:right }
        .s-pending   { background:#FFF8E1; color:#7a5200;
                       border:1px solid #F9D57C }
        .s-reviewed  { background:#E1F5EE; color:#0F6E56;
                       border:1px solid #9FE1CB }
        .s-resolved  { background:#F0F4FF; color:#1A4DB3;
                       border:1px solid #B3C5F5 }
        .success-card{ background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:40px;
                       text-align:center }
        .success-icon{ width:52px; height:52px; background:#E1F5EE;
                       border-radius:50%; display:flex;
                       align-items:center; justify-content:center;
                       margin:0 auto 16px;
                       border:1px solid #9FE1CB }
        .success-icon svg { width:22px; height:22px; stroke:#126b4e }
        @media(max-width:1000px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .page-layout { grid-template-columns:1fr }
        }
    </style>
</head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <div class="page-tag">Safety and support</div>
        <h1 class="page-title">Reports</h1>
        <p class="page-sub">
            Report a guest concern or serious incident.
            All reports are private and reviewed by our team.
        </p>

        <?php if ($success): ?>

        <div class="success-card">
            <div class="success-icon">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
            </div>
            <h2 style="font-size:20px;font-weight:500;
                       margin-bottom:8px">
                Report submitted
            </h2>
<?php if (!empty($report_id)): ?><p style="font-size:14px;margin:0 0 14px">Your reference: <strong>#RPT<?= str_pad((string)$report_id, 5, '0', STR_PAD_LEFT) ?></strong>. Keep it in case you need to ask about this report.</p><?php endif; ?>
            <p style="font-size:14px;color:var(--text-secondary);
                       margin-bottom:24px;line-height:1.6">
                Our team will review your report within 24 hours.
                If anyone is in immediate danger,
                call <strong>999</strong> or <strong>112</strong>.
            </p>
            <div style="display:flex;gap:10px;justify-content:center">
                <a href="report.php"
                   style="padding:9px 20px;background:#085041;
                          color:#fff;border-radius:6px;
                          font-size:13px;font-weight:500;
                          text-decoration:none">
                    Submit another report
                </a>
                <a href="dashboard.php"
                   style="padding:9px 20px;background:none;
                          color:var(--text-primary);border-radius:6px;
                          font-size:13px;font-weight:500;
                          border:1px solid var(--border-strong);
                          text-decoration:none">
                    Back to dashboard
                </a>
            </div>
        </div>

        <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:20px">
            <?php foreach ($errors as $e): ?>
                <p><?= h($e) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="page-layout">

            <!-- Left — Form + History -->
            <div>
                <div class="form-card">
                    <h3>Submit a report</h3>

                    <form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                        <!-- Report Type -->
                        <div class="form-group">
                            <label>Report type</label>
                            <select aria-label="Report type" name="report_type"
                                    onchange="toggleIncident(this)"
                                    required>
                                <option value="">Select type</option>
                                <option value="tourist">
                                    Guest behaviour concern
                                </option>
                                <option value="incident">
                                    Safety or security incident
                                </option>
                            </select>
                        </div>

                        <!-- Incident Type -->
                        <div class="form-group"
                             id="incidentGroup"
                             style="display:none">
                            <label>Incident type</label>
                            <select aria-label="Incident type" name="incident_type">
                                <option value="">Select type</option>
                                <option value="property_damage">
                                    Property damage
                                </option>
                                <option value="theft">
                                    Theft of property items
                                </option>
                                <option value="threats">
                                    Physical threats or violence
                                </option>
                                <option value="illegal_activity">
                                    Illegal activity on premises
                                </option>
                                <option value="unauthorized_guests">
                                    Unauthorized extra guests
                                </option>
                                <option value="poor_condition">
                                    Property left in unacceptable
                                    condition
                                </option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <!-- Related Booking -->
                        <div class="form-group">
                            <label>Related booking (optional)</label>
                            <select aria-label="Related booking (optional)" name="booking_id">
                                <option value="">
                                    Select a booking
                                </option>
                                <?php foreach ($bookings as $b): ?>
                                <option value="<?= $b['booking_id'] ?>">
                                    #BK<?= str_pad($b['booking_id'],
                                        5, '0', STR_PAD_LEFT) ?>
                                    &mdash;
                                    <?= h($b['title']) ?>
                                    &mdash;
                                    <?= h(
                                        explode(' ',
                                        $b['tourist_name'])[0]) ?>
                                    (<?= date('d M',
                                        strtotime($b['check_in_date']))?>
                                    &ndash;
                                    <?= date('d M',
                                        strtotime($b['check_out_date']))?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Description -->
                        <div class="form-group">
                            <label>Details</label>
                            <textarea aria-label="Details" name="description"
                                      placeholder="Describe what happened in as much detail as possible..."
                                      required></textarea>
                        </div>

                        <!-- Evidence -->
                        <div class="form-group">
                            <label>
                                Supporting evidence (optional)
                            </label>
                            <div class="file-zone">
                                <input aria-label="Evidence" type="file" name="evidence"
                                       accept="image/jpeg,image/png,
                                               image/webp">
                                <p>
                                    Photo evidence — JPG, PNG or WebP,
                                    max 5MB
                                </p>
                            </div>
                        </div>

                        <button type="submit" class="submit-btn">
                            Submit report
                        </button>

                    </form>
                </div>

                <!-- Report History -->
                <?php if (!empty($my_reports)): ?>
                <div class="history-card">
                    <div class="history-head">
                        <h3>Your recent reports</h3>
                    </div>
                    <?php foreach ($my_reports as $rep): ?>
                    <div class="history-row">
                        <?php
                        $spill = [
                            'pending'   => 's-pending',
                            'reviewed'  => 's-reviewed',
                            'resolved'  => 's-resolved',
                            'dismissed' => 's-reviewed'
                        ][$rep['status']] ?? 's-pending';
                        ?>
                        <span class="status-pill <?= $spill ?>">
                            <?= ucfirst($rep['status']) ?>
                        </span>
                        <div class="history-type">
                            <?= str_replace('_', ' ',
                                $rep['report_type']) ?>
                        </div>
                        <div class="history-meta">
                            #RPT<?= str_pad($rep['report_id'],
                                5, '0', STR_PAD_LEFT) ?>
                            &middot;
                            <?= date('d M Y',
                                strtotime($rep['created_at'])) ?>
                            <?php if ($rep['target_name']): ?>
                                &middot;
                                <?= h(
                                    explode(' ',
                                    $rep['target_name'])[0]) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right — Info Cards -->
            <div>
                <div class="side-card urgent">
                    <h4>If anyone is in danger</h4>
                    <div class="emergency">999 or 112</div>
                    <p>
                        Contact emergency services first.
                        In Kenya call 999 or 112.
                    </p>
                </div>

                <div class="side-card">
                    <h4>What happens next</h4>
                    <p>
                        Our trust team reviews every report
                        within 24 hours and will follow up
                        by email. Serious incidents are
                        escalated immediately.
                    </p>
                </div>

                <div class="side-card">
                    <h4>Your privacy</h4>
                    <p>
                        Reports are confidential.
                        Guests are not told who submitted them
                        or the exact details of the report.
                    </p>
                </div>

                <div class="side-card">
                    <h4>Evidence tips</h4>
                    <p>
                        Photos of damage or theft significantly
                        help our team investigate. Take photos
                        before cleaning up or making repairs.
                    </p>
                </div>
            </div>

        </div>

        <?php endif; ?>

    <?php appShellEnd(); ?>

<script>
function toggleIncident(sel) {
    document.getElementById('incidentGroup').style.display =
        sel.value === 'incident' ? 'block' : 'none';
}
</script>

</body>
</html>