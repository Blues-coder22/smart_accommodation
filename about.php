<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="page about-page">
    <section class="about-hero card pad">
        <div>
            <div class="eyebrow">About StayExplore Kenya</div>
            <h1>Discover more. Stay better.</h1>
            <p class="sub">StayExplore Kenya is a travel discovery and accommodation platform designed to help travellers find suitable places to stay and discover attractions nearby.</p>
        </div>
        <div class="about-stat">
            <strong>Kenya first</strong>
            <span>Built around local stays, destinations and experiences.</span>
        </div>
    </section>
    <section class="grid grid-2" style="margin-top:20px">
        <article class="card pad">
            <div class="eyebrow">Our purpose</div>
            <h2>Make travel decisions easier.</h2>
            <p class="small">Travellers often have to search for accommodation and attractions separately. StayExplore brings those decisions together so a traveller can start with a stay or an attraction and continue naturally through the journey.</p>
        </article>
        <article class="card pad">
            <div class="eyebrow">Our vision</div>
            <h2>Connect people with places.</h2>
            <p class="small">We aim to make discovering Kenya more convenient while giving local hosts an organised way to present properties, manage bookings and connect with travellers.</p>
        </article>
    </section>
    <section class="card pad" style="margin-top:20px">
        <div class="eyebrow">What we offer</div>
        <h2>One connected travel experience</h2>
        <div class="grid grid-3" style="margin-top:20px">
            <div><h3>Smart discovery</h3><p class="small">Accommodation and attraction results are arranged to help you decide with confidence.</p></div>
            <div><h3>Trusted information</h3><p class="small">Verified-booking reviews and listing moderation are designed to make decisions clearer and safer.</p></div>
            <div><h3>Local hosting</h3><p class="small">Hosts can present their properties, manage availability and bookings, and keep track of their platform activity.</p></div>
        </div>
    </section>
    <section class="grid grid-2" style="margin-top:20px">
        <article class="card pad">
            <div class="eyebrow">For travellers</div>
            <h2>Plan with context.</h2>
            <p class="small">Compare stays, explore nearby attractions, check amenities and reviews, make a booking, and keep your trip information in one place.</p>
            <a class="btn btn-sm" href="tourist/search.php?type=accommodation" style="margin-top:12px">Explore stays</a>
        </article>
        <article class="card pad">
            <div class="eyebrow">For hosts</div>
            <h2>Grow your property presence.</h2>
            <p class="small">Hosts can list properties, manage availability and bookings, track payments, respond to reviews and contact us for assistance.</p>
            <a class="btn btn-outline btn-sm" href="register.php?role=host" style="margin-top:12px">Become a host</a>
        </article>
    </section>
    <section class="card pad about-contact" style="margin-top:20px">
        <div class="eyebrow">Questions?</div>
        <h2>We're building a simpler way to explore Kenya.</h2>
        <p class="small">StayExplore Kenya focuses on a clear, accessible and connected experience for tourists, hosts and platform administrators.</p>
        <div class="actions">
            <a class="btn" href="how-it-works.php">See how it works</a>
            <a class="btn btn-outline" href="index.php">Back to home</a>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
