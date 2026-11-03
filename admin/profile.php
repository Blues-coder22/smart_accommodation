<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/profile_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

$user_id = $_SESSION['user_id'];
$user    = getCurrentUser();

$profile_errors  = [];
$password_errors = [];
$profile_success = false;
$password_success= false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
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

// Platform summary for admin
$pstats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM users
         WHERE role != 'admin') AS total_users,
        (SELECT COUNT(*) FROM accommodations
         WHERE status = 'approved') AS live_listings,
        (SELECT COUNT(*) FROM bookings) AS total_bookings,
        (SELECT COUNT(*) FROM reports
         WHERE status = 'pending') AS open_reports
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile — StayExplore Kenya</title>
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
        .main        { padding:28px; max-width:680px }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#1D9E75;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:20px }
        .profile-header { display:flex; align-items:center;
                          gap:20px; background:var(--surface-2);
                          border:1px solid var(--border);
                          border-radius:8px; padding:20px;
                          margin-bottom:16px }
        .avatar      { width:72px; height:72px; border-radius:50%;
                       background:#DC2626; display:flex;
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
                        background:#FEF2F2; color:#991B1B;
                        border:1px solid #FECACA;
                        display:inline-block }
        .stats-row   { display:grid;
                       grid-template-columns:repeat(4,1fr);
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
                                  border-color:#1D9E75 }
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

        <div class="page-tag">Admin</div>
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
                <span class="profile-role">Administrator</span>
            </div>
        </div>

        <!-- Platform Stats -->
        <div class="stats-row">
            <div class="stat-chip">
                <div class="stat-num">
                    <?= $pstats['total_users'] ?>
                </div>
                <div class="stat-label">Total users</div>
            </div>
            <div class="stat-chip">
                <div class="stat-num">
                    <?= $pstats['live_listings'] ?>
                </div>
                <div class="stat-label">Live listings</div>
            </div>
            <div class="stat-chip">
                <div class="stat-num">
                    <?= $pstats['total_bookings'] ?>
                </div>
                <div class="stat-label">Total bookings</div>
            </div>
            <div class="stat-chip">
                <div class="stat-num"
                     style="<?= $pstats['open_reports'] > 0
                         ? 'color:#DC2626' : '' ?>">
                    <?= $pstats['open_reports'] ?>
                </div>
                <div class="stat-label">Open reports</div>
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

    <?php appShellEnd(); ?>

</body>
</html>