<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/profile_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$user_id = $_SESSION['user_id'];
$user    = getCurrentUser();

$profile_errors  = [];
$password_errors = [];
$profile_success = false;
$password_success= false;
$close_errors = [];
$delete_errors = [];
$verify_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (isset($_POST['submit_verification'])) {
        $vr = submitHostVerification($pdo, $user_id, $_POST, $_FILES['id_photo'] ?? null);
        if ($vr['success']) {
            setFlash('success', 'Your verification request was sent. An administrator will review it and email you the result.');
            redirect('profile.php#verification');
        }
        $verify_errors = $vr['errors'];
    }

    if (isset($_POST['delete_account'])) {
        $deleteResult = deleteUserAccount($pdo, $user_id, $_POST['delete_password'] ?? '', $_POST['delete_confirm'] ?? '');
        if ($deleteResult['success']) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            header('Location: ' . appBasePath() . '/index.php?account=deleted');
            exit;
        }
        $delete_errors = $deleteResult['errors'] ?? ['The account could not be deleted.'];
    }

    if (isset($_POST['close_account'])) {
        $closeResult = closeUserAccount($pdo, $user_id, $_POST['close_password'] ?? '');
        if ($closeResult['success']) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
            header('Location: ' . appBasePath() . '/index.php?account=closed');
            exit;
        }
        $close_errors = $closeResult['errors'] ?? ['The account could not be closed.'];
    }
    if (isset($_POST['update_profile'])) {
        $result = updateProfile($pdo, $user_id, [
            'full_name'      => $_POST['full_name'] ?? '',
            'phone'          => $_POST['phone'] ?? '',
            'existing_photo' => $user['profile_photo'],
            'photo_file'     => $_FILES['profile_photo'] ?? null
        ]);
        if ($result['success']) {
            $profile_success = true;
            $user = getCurrentUser();
        } else {
            $profile_errors = $result['errors'];
        }
    }

    if (isset($_POST['update_password'])) {
        $result = updatePassword(
            $pdo, $user_id,
            $_POST['current_password'] ?? '',
            $_POST['new_password'] ?? '',
            $_POST['confirm_password'] ?? ''
        );
        if ($result['success']) {
            $password_success = true;
        } else {
            $password_errors = $result['errors'];
        }
    }
}

// Host stats
$hstats = $pdo->prepare("
    SELECT
        COUNT(DISTINCT a.accommodation_id) AS total_listings,
        COUNT(DISTINCT CASE WHEN a.status='approved'
            THEN a.accommodation_id END) AS live_listings,
        COUNT(DISTINCT b.booking_id) AS total_bookings,
        COALESCE(AVG(r.rating), 0) AS avg_rating,
        COALESCE(SUM(CASE WHEN p.payment_status='confirmed'
            THEN p.amount END), 0) AS total_earned
    FROM users u
    LEFT JOIN accommodations a ON a.host_id = u.user_id
    LEFT JOIN bookings b ON b.accommodation_id = a.accommodation_id
    LEFT JOIN reviews r
        ON r.accommodation_id = a.accommodation_id
        AND r.is_published = 1
    LEFT JOIN payments p ON p.host_id = u.user_id
        AND p.payment_status = 'confirmed'
    WHERE u.user_id = ?
");
$hstats->execute([$user_id]);
$hstats = $hstats->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Profile — StayExplore Kenya</title>
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
        .main        { padding:28px; max-width:680px }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#126b4e;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:20px }
        .profile-header { display:flex; align-items:center;
                          gap:20px; background:var(--surface-2);
                          border:1px solid var(--border);
                          border-radius:8px; padding:20px;
                          margin-bottom:16px }
        .avatar      { width:72px; height:72px; border-radius:50%;
                       background:#126b4e; display:flex;
                       align-items:center; justify-content:center;
                       color:#fff; font-size:28px; font-weight:600;
                       overflow:hidden; flex-shrink:0 }
        .avatar img  { width:100%; height:100%; object-fit:cover }
        .profile-name { font-size:18px; font-weight:500;
                        margin-bottom:3px }
        .profile-email{ font-size:13px; color:var(--text-secondary);
                        margin-bottom:6px }
        .profile-role { font-size:11px; font-weight:600;
                        padding:2px 9px; border-radius:3px;
                        background:#FEF3C7; color:#92400E;
                        border:1px solid #FCD34D;
                        display:inline-block }
        .stats-row   { display:grid;
                       grid-template-columns:repeat(3,1fr);
                       gap:10px; margin-bottom:16px }
        .stat-chip   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:12px;
                       text-align:center }
        .stat-num    { font-size:20px; font-weight:500;
                       color:#085041 }
        .stat-label  { font-size:11px; color:var(--text-secondary);
                       margin-top:2px }
        .form-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:22px;
                       margin-bottom:16px }
        .form-card h3{ font-size:14px; font-weight:500;
                       margin-bottom:18px; padding-bottom:12px;
                       border-bottom:1px solid var(--border) }
        .form-group  { margin-bottom:14px }
        .form-group label { font-size:12px; font-weight:600;
                            letter-spacing:.04em; text-transform:uppercase;
                            display:block; margin-bottom:5px;
                            color:var(--text-secondary) }
        .form-group input { width:100%; padding:9px 12px;
                            border:1px solid var(--border-strong);
                            border-radius:6px; font-size:13px;
                            color:var(--text-primary);
                            background:var(--surface-2) }
        .form-group input:focus { outline:none;
                                  border-color:#126b4e }
        .form-row    { display:grid;
                       grid-template-columns:1fr 1fr; gap:12px }
        .btn-save    { padding:9px 22px; background:#085041;
                       color:#fff; border:none; border-radius:6px;
                       font-size:13px; font-weight:500; cursor:pointer }
        .success-msg { background:#E1F5EE; border:1px solid #9FE1CB;
                       border-radius:6px; padding:10px 14px;
                       font-size:13px; color:#0F6E56;
                       margin-bottom:14px }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .stats-row { grid-template-columns:repeat(2,1fr) }
            .form-row  { grid-template-columns:1fr }
        }
    </style>
</head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Profile</h1>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="avatar">
                <?php if ($user['profile_photo']): ?>
                    <img src="<?= h(profilePhotoUrl($user['user_id'], $user['profile_photo'])) ?>"
                         alt="Your profile photo">
                <?php else: ?>
                    <?= strtoupper(substr($user['full_name'],0,1)) ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="profile-name">
                    <?= h($user['full_name']) ?>
                </div>
                <div class="profile-email">
                    <?= h($user['email']) ?>
                </div>
                <span class="profile-role">Host</span>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-chip">
                <div class="stat-num">
                    <?= $hstats['live_listings'] ?>
                    <span style="font-size:13px;font-weight:400;
                                 color:var(--text-secondary)">
                        / <?= $hstats['total_listings'] ?>
                    </span>
                </div>
                <div class="stat-label">Live listings</div>
            </div>
            <div class="stat-chip">
                <div class="stat-num">
                    <?= $hstats['total_bookings'] ?>
                </div>
                <div class="stat-label">Total bookings</div>
            </div>
            <div class="stat-chip">
                <div class="stat-num">
                    <?= $hstats['avg_rating'] > 0
                        ? number_format($hstats['avg_rating'], 1)
                        : '—' ?>
                </div>
                <div class="stat-label">Average rating</div>
            </div>
        </div>

        <!-- Update Profile -->
        <div class="form-card">
            <h3>Personal details</h3>

            <?php if ($profile_success): ?>
            <div class="success-msg">
                Profile updated successfully.
            </div>
            <?php endif; ?>

            <?php if (!empty($profile_errors)): ?>
            <div class="alert alert-error"
                 style="margin-bottom:14px">
                <?php foreach ($profile_errors as $e): ?>
                    <p><?= h($e) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="update_profile"
                       value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full name</label>
                        <input aria-label="Full name" type="text" name="full_name"
                               value="<?= h(
                                   $user['full_name']) ?>"
                               required>
                    </div>
                    <div class="form-group">
                        <label>Phone number</label>
                        <input aria-label="Phone number" type="tel" name="phone"
                               value="<?= h(
                                   $user['phone_number'] ?? '') ?>"
                               placeholder="+254 7XX XXX XXX">
                    </div>
                </div>
                <div class="form-group">
                    <label>Profile photo</label>
                    <input aria-label="Profile photo" type="file" name="profile_photo"
                           accept="image/jpeg,image/png,image/webp">
                </div>
                <button type="submit" class="btn-save">
                    Save changes
                </button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="form-card">
            <h3>Change password</h3>

            <?php if ($password_success): ?>
            <div class="success-msg">
                Password updated successfully.
            </div>
            <?php endif; ?>

            <?php if (!empty($password_errors)): ?>
            <div class="alert alert-error"
                 style="margin-bottom:14px">
                <?php foreach ($password_errors as $e): ?>
                    <p><?= h($e) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="POST">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="update_password"
                       value="1">
                <div class="form-group">
                    <label>Current password</label>
                    <input aria-label="Current password" type="password"
                           name="current_password"
                           placeholder="Enter current password">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>New password</label>
                        <input aria-label="New password" type="password"
                               name="new_password"
                               placeholder="Minimum 8 characters">
                    </div>
                    <div class="form-group">
                        <label>Confirm new password</label>
                        <input aria-label="Confirm new password" type="password"
                               name="confirm_password"
                               placeholder="Repeat new password">
                    </div>
                </div>
                <button type="submit" class="btn-save">
                    Update password
                </button>
            </form>
    </div>

    <?php include __DIR__ . '/../includes/host_verification_card.php'; ?>

    <div class="form-card" style="border:1px solid #e6b7b7">
        <h3 style="color:#a12b2b">Close account</h3>
        <p style="font-size:13px;color:var(--text-secondary)">Closing your account signs you out and prevents future logins. Your booking, payment and review history is retained for record-keeping. If you are a host, approved listings are suspended so they cannot receive new bookings. Contact the administrator if you need to request reactivation.</p>
        <?php if (!empty($close_errors)): ?>
            <div class="alert alert-error" style="margin-bottom:14px">
                <?php foreach ($close_errors as $e): ?><p><?= h($e, ENT_QUOTES, 'UTF-8') ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="POST" onsubmit="return confirm('Close your account? You will be signed out and will not be able to log in again unless an administrator reactivates it.');">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="close_account" value="1">
            <div class="form-group">
                <label for="close_password">Confirm with your current password</label>
                <input type="password" id="close_password" name="close_password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-save" style="background:#a12b2b;color:#fff">Close my account</button>
        </form>
    </div>

    <?php include __DIR__ . '/../includes/delete_account_card.php'; ?>

    <?php appShellEnd(); ?>

</body>
</html>