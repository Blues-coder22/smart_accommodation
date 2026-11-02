<?php $base = function_exists('appBasePath') ? appBasePath() : '/smart_accommodation'; ?>
<footer class="hp-footer">
    <div>
        <div class="brand">StayExplore Kenya</div>
        <p>Thoughtfully matching travellers with Kenyan stays and experiences.</p>
        <p>&copy; <?= date('Y') ?> StayExplore Kenya. All rights reserved.</p>
    </div>
    <div>
        <h4>Explore</h4>
        <a href="<?= $base ?>/tourist/search.php?type=accommodation">Stays</a>
        <a href="<?= $base ?>/tourist/search.php?type=attraction">Attractions</a>
        <a href="<?= $base ?>/index.php#attractions">Discover Kenya</a>
    </div>
    <div>
        <h4>Company</h4>
        <a href="<?= $base ?>/about.html">About us</a>
        <a href="<?= $base ?>/contact.html">Contact</a>
        <a href="<?= $base ?>/register.php?role=host">List your property</a>
    </div>
    <div>
        <h4>Support</h4>
        <a href="<?= $base ?>/help.html">Help centre</a>
        <a href="<?= $base ?>/privacy.html">Privacy</a>
        <a href="<?= $base ?>/terms.html">Terms</a>
    </div>
</footer>
<script>window.APP_BASE_PATH = <?= json_encode($base, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>; window.__smartAccommodationServerRendered = true;</script>
<script src="<?= $base ?>/assets/js/app.js" defer></script>
<script src="<?= $base ?>/assets/js/polish.js" defer></script>
