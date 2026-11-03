<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/upload_helpers.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$report_type = sanitize($_GET['type'] ?? 'listing');
$target_id   = (int)($_GET['target_id'] ?? 0);
$booking_id  = (int)($_GET['booking_id'] ?? 0);

// Validate target exists
$target_name = '';

if ($report_type === 'listing' && $target_id) {
    $stmt = $pdo->prepare("
        SELECT title FROM accommodations WHERE accommodation_id = ?
    ");
    $stmt->execute([$target_id]);
    $target = $stmt->fetch();
    $target_name = $target['title'] ?? '';
}

if ($report_type === 'incident' && $booking_id) {
    $stmt = $pdo->prepare("
        SELECT b.booking_id, a.title, a.accommodation_id
        FROM bookings b
        JOIN accommodations a ON a.accommodation_id = b.accommodation_id
        WHERE b.booking_id = ? AND b.tourist_id = ?
    ");
    $stmt->execute([$booking_id, $_SESSION['user_id']]);
    $booking = $stmt->fetch();
    $target_id   = $booking['accommodation_id'] ?? 0;
    $target_name = $booking['title'] ?? '';
}

// Stays this tourist can report an incident about (used when the form was not opened from My bookings).
$my_stays = $pdo->prepare("
    SELECT b.booking_id, b.check_in_date, a.title
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE b.tourist_id = ? AND b.status IN ('confirmed','completed','no_show')
    ORDER BY b.check_in_date DESC
");
$my_stays->execute([$_SESSION['user_id']]);
$my_stays = $my_stays->fetchAll();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $type        = sanitize($_POST['report_type'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $incident_t  = sanitize($_POST['incident_type'] ?? '');
    $evidence    = null;

    if (empty($description) || strlen($description) < 20) {
        $errors[] = 'Please provide more details (at least 20 characters).';
    }

    // Handle evidence upload
    if (isset($_FILES['evidence'])
        && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
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

    // Only these report types exist for tourists.
    if (!in_array($type, ['listing', 'incident', 'review', 'tourist'], true)) {
        $errors[] = 'Please choose a valid report type.';
    }

    // An incident must be tied to a stay the tourist really booked (confirmed or completed).
    // The listing to suspend comes from that booking, never from the URL.
    $critical_types = ['theft', 'physical_abuse', 'sexual_harassment', 'safety_hazard', 'privacy_violation'];
    if ($type === 'incident') {
        $post_booking = (int)($_POST['booking_id'] ?? $booking_id);
        $chk = $pdo->prepare("
            SELECT accommodation_id FROM bookings
            WHERE booking_id = ? AND tourist_id = ?
            AND status IN ('confirmed','completed','no_show')
        ");
        $chk->execute([$post_booking, $_SESSION['user_id']]);
        $own_acc = $chk->fetchColumn();
        if (!$own_acc) {
            $errors[] = 'Incidents can only be reported for a stay you booked. Open this form from My bookings.';
        } else {
            $target_id = (int)$own_acc;
        }
        if ($incident_t === '') {
            $errors[] = 'Please select the type of incident.';
        }
    } else {
        // Other reports must point at a real listing.
        $chk = $pdo->prepare("SELECT COUNT(*) FROM accommodations WHERE accommodation_id = ?");
        $chk->execute([$target_id]);
        if (!$chk->fetchColumn()) {
            $errors[] = 'Please open this form from a listing page so we know what you are reporting.';
        }
    }

    if (empty($errors)) {
        // Save report
        $stmt = $pdo->prepare("
            INSERT INTO reports
            (reporter_id, report_type, target_id,
             description, evidence_path, incident_type,
             status, listing_suspended)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
        ");

        // Auto-suspend the listing only for critical incidents
        $suspend = ($type === 'incident' && in_array($incident_t, $critical_types, true)) ? 1 : 0;

        $stmt->execute([
            $_SESSION['user_id'],
            $type,
            $target_id,
            $description,
            $evidence,
            $incident_t,
            $suspend
        ]);

        $report_id = $pdo->lastInsertId();

        // Suspend listing if critical incident
        if ($suspend && $target_id) {
            $pdo->prepare("
                UPDATE accommodations
                SET status = 'suspended'
                WHERE accommodation_id = ?
            ")->execute([$target_id]);
        }

        // Notify admin
        $admin = adminNotificationRecipient($pdo);

        if ($admin) {
            $urgency = $suspend ? '*** URGENT *** ' : '';
            sendEmail(
                $admin['email'],
                $urgency . 'New Report Submitted — StayExplore Kenya',
                "
                <h2>{$urgency}New Report #RPT{$report_id}</h2>
                <p><strong>Type:</strong> $type</p>
                <p><strong>Property:</strong> " . h($target_name) . "</p>
                <p><strong>Description:</strong> " . h($description) . "</p>
                " . ($incident_t
                    ? "<p><strong>Incident type:</strong> $incident_t</p>"
                    : "") . "
                " . ($suspend
                    ? "<p style='color:red'><strong>
                       The listing has been automatically suspended
                       pending your review.</strong></p>"
                    : "") . "
                <p>Please log in to the admin panel to review this report.</p>
                "
            );
        }

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-wrap   { max-width:760px; margin:0 auto; padding:32px 24px }
        .page-tag    { font-size:11px; font-weight:600; letter-spacing:.06em;
                       color:#DC2626; text-transform:uppercase;
                       margin-bottom:8px }
        .page-title  { font-size:28px; font-weight:400; margin-bottom:4px }
        .page-sub    { font-size:14px; color:var(--text-secondary);
                       margin-bottom:28px }
        .report-layout { display:grid;
                         grid-template-columns:1fr 260px;
                         gap:20px; align-items:start }
        .report-card { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:24px }
        .report-card h3 { font-size:15px; font-weight:500;
                          margin-bottom:18px }
        .form-group  { margin-bottom:16px }
        .form-group label { font-size:13px; font-weight:500;
                            display:block; margin-bottom:6px }
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
                                     border-color:#1D9E75 }
        .file-input  { border:0.5px dashed var(--border-strong);
                       padding:16px; text-align:center;
                       border-radius:6px; cursor:pointer }
        .submit-btn  { width:100%; padding:11px; background:#DC2626;
                       color:#fff; border:none; border-radius:6px;
                       font-size:14px; font-weight:500; cursor:pointer }
        .submit-btn:hover { background:#b91c1c }
        .side-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:18px;
                       margin-bottom:12px }
        .side-card.urgent { border-color:#DC2626;
                            background:#FEF2F2 }
        .side-card h4 { font-size:13px; font-weight:600;
                        margin-bottom:6px }
        .side-card p  { font-size:13px; color:var(--text-secondary);
                        line-height:1.5 }
        .side-card.urgent h4 { color:#DC2626 }
        .emergency-num { font-size:18px; font-weight:700;
                         color:#DC2626; margin:6px 0 }
        .success-state { text-align:center; padding:40px 20px }
        .success-icon  { width:52px; height:52px;
                         background:#E1F5EE; border-radius:50%;
                         display:flex; align-items:center;
                         justify-content:center; margin:0 auto 16px;
                         border:1px solid #9FE1CB }
        .success-icon svg { width:22px; height:22px; stroke:#1D9E75 }
        @media(max-width:768px){
            .report-layout { grid-template-columns:1fr }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Safety and support</div>
    <h1 class="page-title">Report a listing or incident</h1>
    <p class="page-sub">
        Your report is private and reviewed by our trust team.
        <?php if ($target_name): ?>
            Related to: <strong><?= h($target_name) ?></strong>
        <?php endif; ?>
    </p>

    <?php if ($success): ?>

        <div class="report-card">
            <div class="success-state">
                <div class="success-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                </div>
                <h2 style="font-size:20px;font-weight:500;margin-bottom:8px">
                    Report submitted
                </h2>
<?php if (!empty($report_id)): ?><p style="font-size:14px;margin:0 0 14px">Your reference: <strong>#RPT<?= str_pad((string)$report_id, 5, '0', STR_PAD_LEFT) ?></strong>. Keep it in case you need to ask about this report.</p><?php endif; ?>
                <p style="font-size:14px;color:var(--text-secondary);
                           margin-bottom:20px;line-height:1.6">
                    Our trust team will review your report within 24 hours.
                    <?php if ($report_type === 'incident'): ?>
                        The listing has been temporarily suspended
                        while we investigate.
                    <?php endif; ?>
                    If anyone is in immediate danger,
                    please call <strong>999</strong> or <strong>112</strong>.
                </p>
                <a href="bookings.php"
                   style="display:inline-block;padding:10px 24px;
                          background:#085041;color:#fff;border-radius:6px;
                          font-size:14px;font-weight:500;
                          text-decoration:none">
                    Back to my bookings
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

        <div class="report-layout">

            <!-- Report Form -->
            <div class="report-card">
                <h3>Tell us what happened</h3>

                <form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                    <?php if ($target_id): ?>
                        <input type="hidden" name="target_id"
                               value="<?= $target_id ?>">
                    <?php endif; ?>

                    <!-- Report Type -->
                    <div class="form-group">
                        <label for="report_type">Report type</label>
                        <select id="report_type" name="report_type"
                                onchange="toggleIncidentType(this)">
                            <option value="listing"
                                <?= $report_type==='listing'
                                    ?'selected':'' ?>>
                                Listing misrepresentation
                            </option>
                            <option value="incident"
                                <?= $report_type==='incident'
                                    ?'selected':'' ?>>
                                Safety or security incident
                            </option>
                            <option value="review">
                                Suspicious review
                            </option>
                            <option value="tourist">
                                Other concern
                            </option>
                        </select>
                    </div>

                    <!-- Incident Type — shown for incident reports -->
                    <div class="form-group" id="incidentTypeGroup"
                         style="display:<?= $report_type==='incident'
                                           ?'block':'none' ?>">
                        <label for="incident_type">Incident type</label>
                        <select id="incident_type" name="incident_type">
                            <option value="">Select type</option>
                            <option value="theft">Theft</option>
                            <option value="physical_abuse">
                                Physical abuse or threats
                            </option>
                            <option value="sexual_harassment">
                                Sexual harassment
                            </option>
                            <option value="safety_hazard">
                                Safety hazard
                            </option>
                            <option value="privacy_violation">
                                Privacy violation (hidden camera etc)
                            </option>
                            <option value="misrepresentation">
                                Property completely different from listing
                            </option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <!-- Related Booking -->
                    <?php if ($booking_id): ?>
                    <div class="form-group">
                        <label>Related booking</label>
                        <input aria-label="Related booking" type="text" readonly
                               value="#BK<?= str_pad($booking_id, 5, '0',
                                   STR_PAD_LEFT) ?> &middot;
                                   <?= h($target_name) ?>">
                        <input type="hidden" name="booking_id"
                               value="<?= $booking_id ?>">
                    </div>
                    <?php else: ?>
                    <div class="form-group" id="incidentBookingGroup"
                         style="display:<?= $report_type === 'incident' ? 'block' : 'none' ?>">
                        <label for="incident_booking">Which stay is this about?</label>
                        <select id="incident_booking" name="booking_id">
                            <option value="">Select one of your stays</option>
                            <?php foreach ($my_stays as $st): ?>
                                <option value="<?= (int)$st['booking_id'] ?>">
                                    #BK<?= str_pad($st['booking_id'], 5, '0', STR_PAD_LEFT) ?> &middot; <?= h($st['title']) ?> &middot; <?= date('d M Y', strtotime($st['check_in_date'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($my_stays)): ?>
                            <p style="font-size:12px;margin-top:6px">Incidents can only be reported for stays you have booked.</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Description -->
                    <div class="form-group">
                        <label for="description">Details</label>
                        <textarea id="description" name="description"
                                  placeholder="Describe what happened in as much detail as possible..."
                                  required></textarea>
                    </div>

                    <!-- Evidence Upload -->
                    <div class="form-group">
                        <label>Supporting evidence (optional)</label>
                        <div class="file-input">
                            <input aria-label="Evidence" type="file" name="evidence"
                                   accept="image/jpeg,image/png,image/webp"
                                   style="display:block;width:100%;
                                          cursor:pointer">
                            <p style="font-size:12px;
                                      color:var(--text-secondary);
                                      margin-top:6px">
                                JPG, PNG or WebP — max 5MB
                            </p>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn">
                        Submit report
                    </button>

                </form>
            </div>

            <!-- Side Info -->
            <div>
                <div class="side-card urgent">
                    <h4>If anyone is in danger</h4>
                    <div class="emergency-num">999 or 112</div>
                    <p>
                        Contact local emergency services first.
                        In Kenya, call 999 or 112.
                    </p>
                </div>

                <div class="side-card">
                    <h4>What happens next</h4>
                    <p>
                        A specialist reviews your report and
                        follows up securely within 24 hours.
                        Critical incident reports trigger
                        immediate listing suspension.
                    </p>
                </div>

                <div class="side-card">
                    <h4>Your privacy</h4>
                    <p>
                        Your report is confidential.
                        The host will not be told who submitted it.
                    </p>
                </div>
            </div>

        </div>

    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>

<script>
function toggleIncidentType(sel) {
    const show = sel.value === 'incident' ? 'block' : 'none';
    document.getElementById('incidentTypeGroup').style.display = show;
    const bk = document.getElementById('incidentBookingGroup');
    if (bk) bk.style.display = show;
}
</script>

</body>
</html>