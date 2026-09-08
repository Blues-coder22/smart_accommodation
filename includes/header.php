<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/functions.php';
?>
<header class="site-header">
    <div class="header-inner">
        <a href="/smart_accommodation/index.php" class="logo">
            <span class="logo-dot"></span>
            Smart Accommodation Kenya
        </a>
        <nav class="nav-links">
            <?php if (isLoggedIn()): ?>
                <?php if (hasRole('tourist')): ?>
                    <a href="/smart_accommodation/tourist/bookings.php">
                        My Bookings
                    </a>
                    <a href="/smart_accommodation/tourist/favourites.php">
                        Favourites
                    </a>
                <?php elseif (hasRole('host')): ?>
                    <a href="/smart_accommodation/host/dashboard.php">
                        Dashboard
                    </a>
                    <a href="/smart_accommodation/host/add_listing.php">
                        Add Listing
                    </a>
                <?php elseif (hasRole('admin')): ?>
                    <a href="/smart_accommodation/admin/dashboard.php">
                        Admin Panel
                    </a>
                <?php endif; ?>
                <a href="/smart_accommodation/logout.php" class="btn btn-outline">
                    Sign Out
                </a>
            <?php else: ?>
                <a href="/smart_accommodation/login.php">Sign In</a>
                <a href="/smart_accommodation/register.php" 
                   class="btn btn-primary">
                    Sign Up
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>