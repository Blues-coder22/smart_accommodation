<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/functions.php';
$is_homepage = basename($_SERVER['PHP_SELF']) === 'index.php';
?>
<header class="<?= $is_homepage ? 'hp-header' : 'public-nav' ?>">
    <a href="/smart_accommodation/index.php"
       class="<?= $is_homepage ? 'hp-brand' : 'public-brand' ?>">
        <span class="brand-mark">SE</span>
        <span>StayExplore<small>KENYA</small></span>
    </a>
    <?php if ($is_homepage): ?>
        <nav class="hp-nav" aria-label="Main navigation">
            <a href="/smart_accommodation/tourist/search.php?type=accommodation">Discover</a>
            <a href="/smart_accommodation/how-it-works.php">How it works</a>
            <a href="/smart_accommodation/about.php">About us</a>
        </nav>
        <div class="hp-actions">
    <?php else: ?>
        <nav class="public-links" aria-label="Main navigation">
            <a href="/smart_accommodation/index.php">Home</a>
            <a href="/smart_accommodation/tourist/search.php?type=accommodation">Stays</a>
            <a href="/smart_accommodation/tourist/search.php?type=attraction">Attractions</a>
        </nav>
        <div class="public-actions">
    <?php endif; ?>
            <?php if (isLoggedIn()): ?>
                <?php if (hasRole('tourist')): ?>
                    <span class="role-pill">Tourist</span>
                    <a href="/smart_accommodation/tourist/bookings.php">My bookings</a>
                <?php elseif (hasRole('host')): ?>
                    <span class="role-pill">Host</span>
                    <a href="/smart_accommodation/host/dashboard.php">Host dashboard</a>
                <?php elseif (hasRole('admin')): ?>
                    <span class="role-pill">Admin</span>
                    <a href="/smart_accommodation/admin/dashboard.php">Admin panel</a>
                <?php endif; ?>
                <a href="/smart_accommodation/logout.php" class="btn btn-outline">
                    Sign out
                </a>
            <?php else: ?>
                <a href="/smart_accommodation/login.php">Sign in</a>
                <a href="/smart_accommodation/register.php" class="btn">
                    Sign up
                </a>
            <?php endif; ?>
        </div>
</header>