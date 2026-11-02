<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>How Hosting Works — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="page">
    <div class="eyebrow">For local hosts</div>
    <h1>Share your space. Manage your stays.</h1>
    <p class="sub">StayExplore helps hosts present their accommodation to travellers and manage listing details, availability and booking activity from one place.</p>
    <div class="grid grid-3" style="margin-top:28px">
        <section class="card pad"><div class="eyebrow">01</div><h2>Create a host account</h2><p class="small">Register as a host and verify your email before signing in.</p></section>
        <section class="card pad"><div class="eyebrow">02</div><h2>Add your property</h2><p class="small">Provide the property name, location, price, amenities, house rules and clear photos.</p></section>
        <section class="card pad"><div class="eyebrow">03</div><h2>Wait for review</h2><p class="small">An administrator reviews the listing before it becomes visible as an approved accommodation.</p></section>
        <section class="card pad"><div class="eyebrow">04</div><h2>Manage availability</h2><p class="small">Keep availability and property details up to date so travellers can make informed booking requests.</p></section>
        <section class="card pad"><div class="eyebrow">05</div><h2>Track bookings and payments</h2><p class="small">Use the host dashboard to review bookings and view payment records supported by the system.</p></section>
        <section class="card pad"><div class="eyebrow">06</div><h2>Communicate responsibly</h2><p class="small">Follow house rules, respond to booking activity and use the reporting tools when an incident needs attention.</p></section>
    </div>
    <div class="card pad" style="margin-top:20px">
        <h2>Ready to host?</h2>
        <p class="small">Create a host account to start. If you already have one, sign in to open your host dashboard.</p>
        <div class="actions">
            <a class="btn" href="register.php?role=host">Create host account</a>
            <a class="btn btn-outline" href="login.php">Sign in</a>
        </div>
    </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
