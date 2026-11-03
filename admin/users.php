<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    // Host identity verification decisions (verify / reject). The ID number and photo are erased once decided.
    if (isset($_POST['verification_action'])) {
        $vid = (int)($_POST['verification_id'] ?? 0);
        $vstmt = $pdo->prepare("SELECT v.*, u.email AS host_email, u.full_name AS host_name, u.role AS user_role
                                FROM host_verifications v JOIN users u ON u.user_id = v.host_id
                                WHERE v.verification_id = ? AND v.status = 'pending'");
        $vstmt->execute([$vid]);
        $ver = $vstmt->fetch(PDO::FETCH_ASSOC);
        $reasonText = trim((string)($_POST['reject_reason'] ?? ''));
        if (!$ver) {
            setFlash('warning', 'That verification request is no longer pending.');
        } elseif ($_POST['verification_action'] === 'verify') {
            $pdo->prepare("UPDATE host_verifications SET status='verified', reason=NULL, decided_at=NOW(), decided_by=? WHERE verification_id=?")
                ->execute([(int)$_SESSION['user_id'], $vid]);
            purgeVerificationSecrets($pdo, $ver['host_id']);
            $isGuest = ($ver['user_role'] ?? 'host') === 'tourist';
            sendEmail($ver['host_email'], $isGuest ? 'You are a verified guest — StayExplore Kenya' : 'You are a verified host — StayExplore Kenya',
                '<h2>You are verified</h2><p>Dear ' . h($ver['host_name']) . ', your identity has been checked. ' . ($isGuest ? 'A “Verified guest” badge now appears next to your name.' : 'A “Verified host” badge now appears on your listings.') . '</p>');
            setFlash('success', h($ver['host_name']) . ' is now a verified ' . (($ver['user_role'] ?? 'host') === 'tourist' ? 'guest' : 'host') . '. Their ID details were erased.');
        } elseif ($_POST['verification_action'] === 'reject') {
            if ($reasonText === '' || mb_strlen($reasonText, 'UTF-8') > 500) {
                setFlash('error', 'Give a reason (up to 500 characters) when rejecting, so the host knows what to fix.');
            } else {
                $pdo->prepare("UPDATE host_verifications SET status='rejected', reason=?, decided_at=NOW(), decided_by=? WHERE verification_id=?")
                    ->execute([sanitize($reasonText), (int)$_SESSION['user_id'], $vid]);
                purgeVerificationSecrets($pdo, $ver['host_id']);
                sendEmail($ver['host_email'], 'Verification not approved — StayExplore Kenya',
                    '<h2>Verification not approved</h2><p>Dear ' . h($ver['host_name']) . ', we could not verify your identity yet.</p><p><strong>Reason:</strong> '
                    . h($reasonText) . '</p><p>Sign in, open your profile and submit your details again.</p>');
                setFlash('rejected', 'Request rejected. The person has been told why, and their ID details were erased.');
            }
        }
        redirect('users.php');
    }
    $user_id = (int)($_POST['user_id'] ?? 0);
    $action  = sanitize($_POST['action'] ?? '');
    $reason  = sanitize($_POST['reason'] ?? '');

    // Prevent admin from acting on themselves
    if ($user_id === $_SESSION['user_id']) {
        setFlash('error', 'You cannot modify your own account.');
        redirect('users.php');
    }

    $stmt = $pdo->prepare("
        SELECT * FROM users WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if ($user && !empty($user['account_deleted_at'])) {
        setFlash('error', 'This account was permanently deleted by its owner and cannot be changed or restored.');
        redirect('users.php');
    }

    if ($user) {
        switch ($action) {
            case 'suspend':
                if (in_array($user['status'], ['suspended', 'banned'], true)) {
                    setFlash('info', "Nothing changed: {$user['full_name']}'s account is already {$user['status']}, so they were not emailed again.");
                    break;
                }
                $pdo->prepare("
                    UPDATE users SET status = 'suspended'
                    WHERE user_id = ?
                ")->execute([$user_id]);

                sendEmail(
                    $user['email'],
                    'Account Suspended — StayExplore Kenya',
                    "
                    <h2>Account suspended</h2>
                    <p>Dear {$user['full_name']},</p>
                    <p>Your account has been temporarily suspended.
                    </p>
                    <p><strong>Reason:</strong> " .
                    ($reason ?: 'Violation of platform guidelines.')
                    . "</p>
                    <p>Please contact support if you believe
                       this is an error.</p>
                    "
                );
                setFlash('success',
                    "{$user['full_name']}'s account suspended.");
                break;

            case 'ban':
                if ($user['status'] === 'banned') {
                    setFlash('info', "Nothing changed: {$user['full_name']}'s account is already banned, so they were not emailed again.");
                    break;
                }
                $pdo->prepare("
                    UPDATE users SET status = 'banned'
                    WHERE user_id = ?
                ")->execute([$user_id]);

                sendEmail(
                    $user['email'],
                    'Account Banned — StayExplore Kenya',
                    "
                    <h2>Account banned</h2>
                    <p>Dear {$user['full_name']},</p>
                    <p>Your account has been permanently banned
                       from the platform.</p>
                    <p><strong>Reason:</strong> " .
                    ($reason ?: 'Serious violation of platform guidelines.')
                    . "</p>
                    "
                );
                setFlash('success',
                    "{$user['full_name']}'s account banned.");
                break;

            case 'restore':
                if ($user['status'] === 'active' && empty($user['account_closed_at'])) {
                    setFlash('info', "Nothing changed: {$user['full_name']}'s account is already active, so they were not emailed again.");
                    break;
                }
                $pdo->prepare("
                    UPDATE users SET status = 'active', account_closed_at = NULL
                    WHERE user_id = ? AND account_deleted_at IS NULL
                ")->execute([$user_id]);

                sendEmail(
                    $user['email'],
                    'Account Restored — StayExplore Kenya',
                    "
                    <h2>Account restored</h2>
                    <p>Dear {$user['full_name']},</p>
                    <p>Your account has been restored.
                       You can now log in again.</p>
                    "
                );
                setFlash('success',
                    "{$user['full_name']}'s account restored.");
                break;

            case 'verify':
                $pdo->prepare("
                    UPDATE users SET email_verified = 1
                    WHERE user_id = ?
                ")->execute([$user_id]);
                setFlash('success',
                    "{$user['full_name']}'s email verified.");
                break;
        }
    }
    redirect('users.php?role=' . sanitize($_GET['role'] ?? 'all')
             . '&status=' . sanitize($_GET['status'] ?? 'all'));
}

$role_filter   = sanitize($_GET['role'] ?? 'all');
$status_filter = sanitize($_GET['status'] ?? 'all');
$search        = sanitize($_GET['search'] ?? '');

// Build query
$where  = "u.role != 'admin'";
$params = [];

if ($role_filter !== 'all') {
    $where  .= " AND u.role = ?";
    $params[] = $role_filter;
}
if ($status_filter !== 'all') {
    $where  .= " AND u.status = ?";
    $params[] = $status_filter;
}
if (!empty($search)) {
    $where  .= " AND (u.full_name LIKE ?
                 OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$users = $pdo->prepare("
    SELECT u.*,
           COUNT(DISTINCT CASE WHEN u.role = 'tourist'
               THEN b.booking_id END) AS bookings_made,
           COUNT(DISTINCT CASE WHEN u.role = 'host'
               THEN a.accommodation_id END) AS listings_count,
           COUNT(DISTINCT r.report_id) AS reports_against
    FROM users u
    LEFT JOIN bookings b ON b.tourist_id = u.user_id
    LEFT JOIN accommodations a ON a.host_id = u.user_id
    LEFT JOIN reports r ON r.target_id = u.user_id
    WHERE $where
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");
$users->execute($params);
$users = $users->fetchAll();

// Host identity (ID) verification is separate from email verification: show both so the admin list agrees
// with what each host sees on their own dashboard.
$hostIdStatus = [];
try {
    $hostIds = array_values(array_map(fn($u) => (int)$u['user_id'], array_filter($users, fn($u) => in_array($u['role'], ['host','tourist'], true))));
    if ($hostIds) {
        $in = implode(',', array_fill(0, count($hostIds), '?'));
        $st = $pdo->prepare("SELECT host_id, status FROM host_verifications WHERE host_id IN ($in)");
        $st->execute($hostIds);
        foreach ($st->fetchAll() as $r) $hostIdStatus[(int)$r['host_id']] = $r['status'];
    }
} catch (Throwable $e) { $hostIdStatus = []; }

// Counts
$counts = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(role='tourist') AS tourists,
        SUM(role='host') AS hosts,
        SUM(status='suspended') AS suspended,
        SUM(status='banned') AS banned,
        SUM(email_verified=0 AND status='active') AS unverified
    FROM users WHERE role != 'admin'
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — StayExplore Kenya</title>
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
                       margin-bottom:20px }
        .top-bar     { display:flex; gap:10px; margin-bottom:16px;
                       flex-wrap:wrap; align-items:center }
        .search-input { padding:8px 12px; font-size:13px;
                        border:1px solid var(--border-strong);
                        border-radius:6px; background:var(--surface-2);
                        color:var(--text-primary); min-width:220px }
        .search-input:focus { outline:none; border-color:#1D9E75 }
        .filter-select { padding:8px 12px; font-size:13px;
                         border:1px solid var(--border-strong);
                         border-radius:6px;
                         background:var(--surface-2);
                         color:var(--text-primary) }
        .stats-row   { display:flex; gap:10px; margin-bottom:20px;
                       flex-wrap:wrap }
        .stat-chip   { padding:6px 14px; border-radius:4px;
                       font-size:12px; font-weight:500;
                       border:1px solid var(--border);
                       background:var(--surface-2);
                       color:var(--text-secondary) }
        .stat-chip span { font-weight:700;
                          color:var(--text-primary);
                          margin-left:4px }
        .section-card { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; overflow:hidden }
        table        { width:100%; border-collapse:collapse;
                       font-size:13px }
        th           { font-size:10px; font-weight:700;
                       letter-spacing:.06em; text-transform:uppercase;
                       color:var(--text-secondary); padding:10px 18px;
                       text-align:left; background:var(--surface-1);
                       border-bottom:1px solid var(--border) }
        td           { padding:12px 18px;
                       border-bottom:1px solid var(--border);
                       vertical-align:middle }
        tr:last-child td { border-bottom:none }
        tr:hover td  { background:var(--surface-1) }
        .user-name   { font-weight:500; margin-bottom:2px }
        .user-email  { font-size:12px; color:var(--text-secondary) }
        .role-badge  { font-size:11px; font-weight:600;
                       padding:2px 8px; border-radius:3px }
        .role-tourist { background:#E0E7FF; color:#3730A3;
                        border:1px solid #A5B4FC }
        .role-host    { background:#FEF3C7; color:#92400E;
                        border:1px solid #FCD34D }
        .status-pill  { display:inline-block; font-size:11px;
                        font-weight:600; padding:2px 8px;
                        border-radius:3px }
        .s-active     { background:#E1F5EE; color:#0F6E56;
                        border:1px solid #9FE1CB }
        .s-suspended  { background:#FFF8E1; color:#9A6A00;
                        border:1px solid #F9D57C }
        .s-banned     { background:#FEF2F2; color:#991B1B;
                        border:1px solid #FECACA }
        .verified-yes { color:#1D9E75; font-size:12px }
        .verified-no  { color:#DC2626; font-size:12px }
        .action-cell  { display:flex; gap:6px; flex-wrap:wrap }
        .btn-sm      { padding:5px 10px; font-size:11px;
                       border-radius:3px; cursor:pointer;
                       font-weight:500; border:1px solid;
                       background:none }
        .btn-suspend { color:#D97706; border-color:#F9D57C }
        .btn-suspend:hover { background:#FFF8E1 }
        .btn-ban     { color:#DC2626; border-color:#FECACA }
        .btn-ban:hover { background:#FEF2F2 }
        .btn-restore { color:#0F6E56; border-color:#9FE1CB }
        .btn-restore:hover { background:#E1F5EE }
        .btn-verify  { color:#1A4DB3; border-color:#B3C5F5 }
        .btn-verify:hover { background:#F0F4FF }
        .inline-form { display:inline }
        .reason-input { width:140px; padding:4px 8px;
                        border:1px solid var(--border-strong);
                        border-radius:3px; font-size:11px;
                        background:var(--surface-2) }
        .empty-state { text-align:center; padding:48px;
                       font-size:13px;
                       color:var(--text-secondary) }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
        }
    </style>
</head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Users</h1>

        <?php
        $pendingVerifications = [];
        try {
            $pv = $pdo->query("SELECT v.*, u.full_name, u.email, u.role AS user_role, u.created_at AS joined
                               FROM host_verifications v JOIN users u ON u.user_id = v.host_id
                               WHERE v.status = 'pending' ORDER BY v.submitted_at ASC");
            $pendingVerifications = $pv->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $pendingVerifications = []; }
        ?>
        <?php if ($pendingVerifications): ?>
        <div id="verification" style="border:1px solid #F0D8A8;background:#FFF9EC;border-radius:8px;padding:18px;margin-bottom:22px">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:4px">Identity verification requests (<?= count($pendingVerifications) ?>)</h2>
            <p style="font-size:12px;color:var(--text-secondary);margin-bottom:14px">Compare the details with the ID photo. Only administrators can see the photo, and it is erased as soon as you decide.</p>
            <?php foreach ($pendingVerifications as $pv): ?>
            <div style="border-top:1px solid #F0D8A8;padding:14px 0">
                <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:13px;margin-bottom:10px">
                    <div><strong><?= h($pv['full_name']) ?></strong> <span style="font-size:11px;background:#fff;border:1px solid #F0D8A8;border-radius:99px;padding:1px 8px"><?= ($pv['user_role'] ?? 'host') === 'tourist' ? 'Tourist' : 'Host' ?></span><br><span style="color:var(--text-secondary)"><?= h($pv['email']) ?></span></div>
                    <div>Legal name<br><strong><?= h($pv['legal_name']) ?></strong></div>
                    <div><?= h($pv['id_type']) ?><br><strong><?= h($pv['id_number']) ?></strong></div>
                    <div>Property proof<br><strong><?= h($pv['business_ref'] ?: '—') ?></strong></div>
                    <div>Submitted<br><?= date('d M Y H:i', strtotime($pv['submitted_at'])) ?></div>
                    <div><?php if ($pv['id_photo']): ?><a href="verification_file.php?id=<?= (int)$pv['verification_id'] ?>" target="_blank" rel="noopener">View ID photo &nearr;</a><?php endif; ?></div>
                </div>
                <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="verification_id" value="<?= (int)$pv['verification_id'] ?>">
                    <button type="submit" name="verification_action" value="verify" class="btn-sm btn-restore" onclick="return confirm('Approve this identity? Their ID details will be erased.');">Approve identity</button>
                    <input aria-label="Reason (required to reject), e.g. the ID photo is blurry" type="text" name="reject_reason" maxlength="500" placeholder="Reason (required to reject), e.g. the ID photo is blurry" style="flex:1;min-width:240px;padding:6px 8px">
                    <button type="submit" name="verification_action" value="reject" class="btn-sm" onclick="return confirm('Reject this ID verification? The person will be emailed your reason and their ID details will be erased.')">Reject</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-chip">
                Total <span><?= $counts['total'] ?></span>
            </div>
            <div class="stat-chip">
                Tourists <span><?= $counts['tourists'] ?></span>
            </div>
            <div class="stat-chip">
                Hosts <span><?= $counts['hosts'] ?></span>
            </div>
            <div class="stat-chip">
                Suspended <span><?= $counts['suspended'] ?></span>
            </div>
            <div class="stat-chip">
                Banned <span><?= $counts['banned'] ?></span>
            </div>
            <div class="stat-chip">
                Email unverified <span><?= $counts['unverified'] ?></span>
            </div>
        </div>

        <!-- Search and Filter -->
        <form method="GET" class="top-bar">
            <input aria-label="Search name or email" type="text" name="search"
                   class="search-input"
                   value="<?= h($search) ?>"
                   placeholder="Search name or email...">
            <select aria-label="Role" name="role" class="filter-select"
                    onchange="this.form.submit()">
                <option value="all" <?= $role_filter==='all'
                    ?'selected':'' ?>>All roles</option>
                <option value="tourist" <?= $role_filter==='tourist'
                    ?'selected':'' ?>>Tourists</option>
                <option value="host" <?= $role_filter==='host'
                    ?'selected':'' ?>>Hosts</option>
            </select>
            <select aria-label="Status" name="status" class="filter-select"
                    onchange="this.form.submit()">
                <option value="all" <?= $status_filter==='all'
                    ?'selected':'' ?>>All statuses</option>
                <option value="active" <?= $status_filter==='active'
                    ?'selected':'' ?>>Active</option>
                <option value="suspended"
                    <?= $status_filter==='suspended'
                        ?'selected':'' ?>>Suspended</option>
                <option value="banned" <?= $status_filter==='banned'
                    ?'selected':'' ?>>Banned</option>
            </select>
            <button type="submit"
                    style="padding:8px 16px;background:#085041;
                           color:#fff;border:none;border-radius:6px;
                           font-size:13px;cursor:pointer">
                Search
            </button>
            <?php if ($search || $role_filter !== 'all'
                || $status_filter !== 'all'): ?>
            <a href="users.php"
               style="font-size:13px;color:var(--text-secondary);
                      padding:8px">
                Clear
            </a>
            <?php endif; ?>
        </form>

        <div class="section-card">
            <?php if (empty($users)): ?>
                <div class="empty-state">No users found.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Email</th>
                        <th>Host ID check</th>
                        <th>Activity</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="user-name">
                                <?= h($u['full_name']) ?>
                            </div>
                            <div class="user-email">
                                <?= h($u['email']) ?>
                            </div>
                            <?php if ($u['phone_number']): ?>
                            <div class="user-email">
                                <?= h(
                                    $u['phone_number']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="role-badge role-<?= $u['role'] ?>">
                                <?= ucfirst($u['role']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['email_verified']): ?>
                                <span class="verified-yes">Email verified</span>
                            <?php else: ?>
                                <span class="verified-no">Email not verified</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!in_array($u['role'], ['host','tourist'], true)): ?>
                                <span style="font-size:12px;color:var(--text-secondary)">&mdash;</span>
                            <?php else:
                                $idState = $hostIdStatus[(int)$u['user_id']] ?? 'unverified'; ?>
                                <?php if ($idState === 'verified'): ?>
                                    <span class="verified-yes">ID verified</span>
                                <?php elseif ($idState === 'pending'): ?>
                                    <a href="#verification" style="font-size:12px;color:#D97706">ID review pending</a>
                                <?php elseif ($idState === 'rejected'): ?>
                                    <span class="verified-no">ID rejected</span>
                                <?php else: ?>
                                    <span style="font-size:12px;color:var(--text-secondary)">ID not submitted</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['role'] === 'tourist'): ?>
                                <span style="font-size:12px;
                                             color:var(--text-secondary)">
                                    <?= $u['bookings_made'] ?>
                                    booking<?= $u['bookings_made'] != 1
                                               ? 's' : '' ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size:12px;
                                             color:var(--text-secondary)">
                                    <?= $u['listings_count'] ?>
                                    listing<?= $u['listings_count'] != 1
                                               ? 's' : '' ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($u['reports_against'] > 0): ?>
                            <div style="font-size:11px;color:#DC2626">
                                <?= $u['reports_against'] ?>
                                report<?= $u['reports_against'] != 1
                                           ? 's' : '' ?> against
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $isClosed = !empty($u['account_closed_at']);
                            $spill = $isClosed ? 's-suspended' : ([
                                'active'    => 's-active',
                                'suspended' => 's-suspended',
                                'banned'    => 's-banned'
                            ][$u['status']] ?? 's-active');
                            ?>
                            <span class="status-pill <?= $spill ?>">
                                <?= $isClosed ? 'Closed' : ucfirst($u['status']) ?>
                            </span>
                        </td>
                        <td style="font-size:12px;
                                   color:var(--text-secondary)">
                            <?= date('d M Y',
                                strtotime($u['created_at'])) ?>
                        </td>
                        <td>
                            <div class="action-cell">

                                <?php if ($u['status'] === 'active' && empty($u['account_closed_at'])): ?>
                                <form method="POST" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id"
                                           value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action"
                                           value="suspend">
                                    <input aria-label="Reason" type="text" name="reason"
                                           class="reason-input"
                                           placeholder="Reason...">
                                    <button type="submit"
                                            class="btn-sm btn-suspend"
                                            onclick="return confirm(
                                                'Suspend this account?')">
                                        Suspend
                                    </button>
                                </form>
                                <form method="POST" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id"
                                           value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action"
                                           value="ban">
                                    <button type="submit"
                                            class="btn-sm btn-ban"
                                            onclick="return confirm(
                                                'Permanently ban this account? This cannot be undone.')">
                                        Ban
                                    </button>
                                </form>

                                <?php elseif (
                                    ($u['status'] === 'suspended' || !empty($u['account_closed_at']))): ?>
                                <form method="POST" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id"
                                           value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action"
                                           value="restore">
                                    <button type="submit"
                                            class="btn-sm btn-restore"
                                            onclick="return confirm(
                                                'Restore this account?')">
                                        Restore
                                    </button>
                                </form>
                                <form method="POST" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id"
                                           value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action"
                                           value="ban">
                                    <button type="submit"
                                            class="btn-sm btn-ban"
                                            onclick="return confirm(
                                                'Permanently ban this account?')">
                                        Ban
                                    </button>
                                </form>

                                <?php elseif (
                                    $u['status'] === 'banned'): ?>
                                <form method="POST" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id"
                                           value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action"
                                           value="restore">
                                    <button type="submit"
                                            class="btn-sm btn-restore"
                                            onclick="return confirm(
                                                'Restore this banned account?')">
                                        Restore
                                    </button>
                                </form>
                                <?php endif; ?>

                                <?php if (!$u['email_verified']): ?>
                                <form method="POST" class="inline-form">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="user_id"
                                           value="<?= $u['user_id'] ?>">
                                    <input type="hidden" name="action"
                                           value="verify">
                                    <button type="submit"
                                            class="btn-sm btn-verify">
                                        Verify email
                                    </button>
                                </form>
                                <?php endif; ?>

                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    <?php appShellEnd(); ?>

</body>
</html>