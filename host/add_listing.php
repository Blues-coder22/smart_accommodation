<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/notifications.php';   // v9.38.1: in-app alert to the admin
require_once '../includes/db.php';
require_once '../includes/onsite_experience.php';
require_once '../includes/upload_helpers.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');
require_once '../includes/commission.php';
if (!hostAgreementAccepted($pdo, (int)$_SESSION['user_id'])) {   // the host must accept the commission terms before listing
    setFlash('info', 'Please read and accept the host agreement before you add a listing.');
    redirect('agreement.php');
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $title         = sanitize($_POST['title'] ?? '');
    $description   = sanitize($_POST['description'] ?? '');
    $property_type = sanitize($_POST['property_type'] ?? '');
    $price         = (float)($_POST['price_per_night'] ?? 0);
    $max_guests    = (int)($_POST['max_guests'] ?? 1);
    $location_name = sanitize($_POST['location_name'] ?? '');
    $latitude      = (float)($_POST['latitude'] ?? 0);
    $longitude     = (float)($_POST['longitude'] ?? 0);
    $amenities     = (array)((array)($_POST['amenities'] ?? []));
    $rules         = array_filter(array_map(
        'trim', explode("\n", $_POST['house_rules'] ?? '')
    ));

    // Validation
    if (empty($title))
        $errors[] = 'Property title is required.';
    if (empty($description) || strlen($description) < 50)
        $errors[] = 'Please write at least 50 characters describing your property.';
    if (empty($property_type))
        $errors[] = 'Property type is required.';
    if ($price <= 0)
        $errors[] = 'Please enter a valid price per night.';
    if (empty($location_name))
        $errors[] = 'Location is required.';
    if ($latitude == 0 || $longitude == 0)
        $errors[] = 'Please pick your property location on the map.';
    // At least 3 usable photos (JPG, PNG or WebP, up to 5 MB each)
    $validPhotos = 0;
    if (!empty($_FILES['photos']['name'][0])) {
        foreach ((array)$_FILES['photos']['tmp_name'] as $i => $tmpName) {
            if (($_FILES['photos']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            if (($_FILES['photos']['size'][$i] ?? 0) > 5 * 1024 * 1024) continue;
            if (!in_array(mime_content_type($tmpName), ['image/jpeg', 'image/png', 'image/webp'], true)) continue;
            $validPhotos++;
        }
    }
    if ($validPhotos < 3)
        $errors[] = 'Please upload at least 3 clear photos (JPG, PNG or WebP, up to 5 MB each).';

    if (empty($errors)) {
        // Insert listing
        $stmt = $pdo->prepare("
            INSERT INTO accommodations
            (host_id, title, description, property_type,
             price_per_night, max_guests, location_name,
             latitude, longitude, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $title, $description, $property_type,
            $price, $max_guests, $location_name,
            $latitude, $longitude
        ]);

        $accommodation_id = $pdo->lastInsertId();
        saveOnsiteExperience($pdo, $accommodation_id, $_POST);

        // Handle photo uploads
        $photos     = $_FILES['photos'];
        $uploaded   = 0;
        $is_primary = 1;

        foreach ($photos['tmp_name'] as $i => $tmp) {
            if ($photos['error'][$i] !== UPLOAD_ERR_OK) continue;

            $allowed = ['image/jpeg','image/png','image/webp'];
            $ftype   = mime_content_type($tmp);
            if (!in_array($ftype, $allowed)) continue;
            if ($photos['size'][$i] > 5 * 1024 * 1024) continue;

            $filename = saveUploadedImage(
                $tmp, $ftype, 'accommodations', 'acc_' . $accommodation_id,
                5 * 1024 * 1024
            );

            if ($filename !== false) {
                $pdo->prepare("
                    INSERT INTO accommodation_photos
                    (accommodation_id, photo_path, is_primary)
                    VALUES (?, ?, ?)
                ")->execute([$accommodation_id, $filename, $is_primary]);
                $is_primary = 0;
                $uploaded++;
            }

            if ($uploaded >= 10) break;
        }

        if ($uploaded < 3) {
            // Not enough photos could be saved: remove what was saved, then the half-created listing
            $saved = $pdo->prepare('SELECT photo_path FROM accommodation_photos WHERE accommodation_id = ?');
            $saved->execute([$accommodation_id]);
            foreach ($saved->fetchAll(PDO::FETCH_COLUMN) as $pp) {
                $f = dirname(__DIR__) . '/uploads/accommodations/' . basename((string)$pp);
                if (is_file($f)) @unlink($f);
            }
            $pdo->prepare('DELETE FROM accommodation_photos WHERE accommodation_id = ?')->execute([$accommodation_id]);
            $pdo->prepare('DELETE FROM accommodations WHERE accommodation_id = ?')
                ->execute([$accommodation_id]);
            $errors[] = 'We could save only ' . $uploaded . ' of your photos, and a stay needs at least 3. Please use JPG, PNG or WebP images under 5 MB and try again.';
        }

        if ($uploaded > 0) {
            try { mediaSelfHeal($pdo, true); } catch (Throwable $e) {}   // back up the new photos and drop identical ones straight away
        }

        // Save amenities only after at least one photo was saved
        if ($uploaded > 0) foreach ($amenities as $amenity) {
            $amenity = sanitize($amenity);
            if (!empty($amenity)) {
                $pdo->prepare("
                    INSERT INTO amenities
                    (accommodation_id, amenity_name)
                    VALUES (?, ?)
                ")->execute([$accommodation_id, $amenity]);
            }
        }

        // Save house rules
        if ($uploaded > 0) foreach ($rules as $rule) {
            if (!empty($rule)) {
                $pdo->prepare("
                    INSERT INTO house_rules
                    (accommodation_id, rule_description)
                    VALUES (?, ?)
                ")->execute([$accommodation_id, $rule]);
            }
        }

        // Notify admin only after the listing and at least one photo were saved
        if ($uploaded > 0) {
        notifyAdmins($pdo, 'New listing to approve', '"' . $title . '" in ' . $location_name . ' was submitted by ' . ($_SESSION['full_name'] ?? 'a host') . ' and is waiting for your approval.', 'admin/listings.php');
        $admin = adminNotificationRecipient($pdo);

        if ($admin) {
            sendEmail(
                $admin['email'],
                'New Listing Awaiting Approval — StayExplore Kenya',
                "
                <h2>New listing pending approval</h2>
                <p><strong>Title:</strong> $title</p>
                <p><strong>Location:</strong> $location_name</p>
                <p><strong>Type:</strong> $property_type</p>
                <p><strong>Price:</strong> KES $price/night</p>
                <p>Please log in to the admin panel to review and
                   approve or reject this listing.</p>
                "
            );
        }

        $success        = true;
        $new_listing_id = $accommodation_id;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Listing — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <script src="../assets/js/leaflet-loader.js"></script>
<script src="../assets/js/map-pins.js"></script>
    <style>
        .sidebar    { background:var(--surface-2);
                      border-right:1px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .main       { padding:28px; max-width:800px }
        .form-section { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; padding:24px;
                        margin-bottom:16px }
        .form-section h3 { font-size:14px; font-weight:500;
                           margin-bottom:18px;
                           padding-bottom:12px;
                           border-bottom:1px solid var(--border) }
        .form-group { margin-bottom:16px }
        .form-group label { font-size:12px; font-weight:600;
                            letter-spacing:.04em;
                            text-transform:uppercase;
                            display:block; margin-bottom:6px;
                            color:var(--text-secondary) }
        .form-group input,
        .form-group select,
        .form-group textarea { width:100%; padding:10px 12px;
                               border:1px solid var(--border-strong);
                               border-radius:6px; font-size:14px;
                               color:var(--text-primary);
                               background:var(--surface-2);
                               font-family:inherit }
        .form-group textarea { resize:vertical }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline:none;
                                     border-color:var(--accent) }
        .form-row   { display:grid;
                      grid-template-columns:1fr 1fr;
                      gap:14px }
        .amenity-grid { display:grid;
                        grid-template-columns:repeat(3,1fr);
                        gap:8px }
        .amenity-check { display:flex; align-items:center;
                         gap:8px; font-size:13px;
                         color:var(--text-primary) }
        .amenity-check input { width:15px; height:15px;
                               accent-color:#126b4e;
                               flex-shrink:0 }
        #map        { height:260px; border-radius:6px;
                      border:1px solid var(--border-strong);
                      margin-top:8px }
        .map-hint   { font-size:12px; color:var(--text-secondary);
                      margin-top:6px }
        .photo-upload { border:0.5px dashed var(--border-strong);
                        border-radius:6px; padding:24px;
                        text-align:center; cursor:pointer;
                        transition:.15s }
        .photo-upload:hover { border-color:var(--accent) }
        .photo-upload input { display:none }
        .photo-upload p { font-size:13px;
                          color:var(--text-secondary);
                          margin-bottom:8px }
        .photo-preview { display:flex; flex-wrap:wrap;
                         gap:8px; margin-top:12px }
        .photo-thumb { width:80px; height:64px; border-radius:4px;
                       object-fit:cover;
                       border:1px solid var(--border) }
        .submit-btn { width:100%; padding:12px;
                      background:var(--btn-hover); color:#fff;
                      border:none; border-radius:6px;
                      font-size:14px; font-weight:500;
                      cursor:pointer; margin-top:8px }
        .submit-btn:hover { background:#0a6350 }
        .success-card { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; padding:40px;
                        text-align:center }
        .success-icon { width:52px; height:52px;
                        background:var(--accent-soft); border-radius:50%;
                        display:flex; align-items:center;
                        justify-content:center;
                        margin:0 auto 16px;
                        border:1px solid var(--accent-line) }
        .success-icon svg { width:22px; height:22px; stroke:#126b4e }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?php if ($success): ?>

        <div class="success-card">
            <div class="success-icon">
                <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
            </div>
            <h2 style="font-size:20px;font-weight:500;margin-bottom:8px">
                Listing submitted for approval
            </h2>
            <p style="font-size:14px;color:var(--text-secondary);
                       margin-bottom:24px;line-height:1.6">
                Your listing has been submitted and is awaiting
                admin approval. You will receive an email
                once it has been reviewed.
            </p>
            <div style="display:flex;gap:10px;justify-content:center">
                <a href="listings.php"
                   style="padding:10px 20px;background:var(--btn-hover);
                          color:#fff;border-radius:6px;font-size:14px;
                          font-weight:500;text-decoration:none">
                    View my listings
                </a>
                <a href="add_listing.php"
                   style="padding:10px 20px;background:none;
                          color:var(--text-primary);border-radius:6px;
                          font-size:14px;font-weight:500;
                          text-decoration:none;
                          border:1px solid var(--border-strong)">
                    Add another listing
                </a>
            </div>
        </div>

        <?php else: ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Add a new listing</h1>
        <p class="page-sub">
            Your listing will be reviewed by our team
            before going live.
        </p>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:20px">
            <?php foreach ($errors as $e): ?>
                <p><?= h($e) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

            <!-- Basic Details -->
            <div class="form-section">
                <h3>Basic details</h3>

                <div class="form-group">
                    <label>Property title</label>
                    <input aria-label="Property title" type="text" name="title"
                           value="<?= h(
                               $_POST['title'] ?? '') ?>"
                           placeholder="e.g. Cozy apartment near Karura Forest"
                           maxlength="150" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Property type</label>
                        <select aria-label="Property type" name="property_type" required>
                            <option value="">Select type</option>
                            <?php foreach ([
                                'Apartment','House','Guest House',
                                'Villa','Studio','Cottage',
                                'Lodge','Hostel'
                            ] as $t): ?>
                            <option value="<?= $t ?>"
                                <?= (($_POST['property_type'] ?? '') === $t)
                                    ? 'selected' : '' ?>>
                                <?= $t ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Maximum guests</label>
                        <select aria-label="Maximum guests" name="max_guests">
                            <?php for ($i = 1; $i <= 20; $i++): ?>
                            <option value="<?= $i ?>"
                                <?= (($_POST['max_guests'] ?? 1) == $i)
                                    ? 'selected' : '' ?>>
                                <?= $i ?> guest<?= $i > 1 ? 's' : '' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <?= onsiteExperienceFormHtml($_POST['onsite_name'] ?? '', $_POST['onsite_fee'] ?? '') ?>
                <div class="form-group">
                    <label>Price per night (KES)</label>
                    <input aria-label="Price per night (KES)" type="number" name="price_per_night"
                           value="<?= $_POST['price_per_night'] ?? '' ?>"
                           min="1" placeholder="e.g. 3500" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea aria-label="Description" name="description" rows="5"
                              placeholder="Describe your property — what makes it special, what guests can expect, nearby landmarks..."
                              required><?= h(
                        $_POST['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Location -->
            <div class="form-section">
                <h3>Location</h3>

                <div class="form-group">
                    <label>Location name</label>
                    <input aria-label="Location name" type="text" name="location_name"
                           id="locationInput"
                           value="<?= h(
                               $_POST['location_name'] ?? '') ?>"
                           placeholder="e.g. Westlands, Nairobi"
                           required>
                </div>

                <input type="hidden" name="latitude"
                       id="latInput"
                       value="<?= $_POST['latitude'] ?? '' ?>">
                <input type="hidden" name="longitude"
                       id="lngInput"
                       value="<?= $_POST['longitude'] ?? '' ?>">

                <label style="font-size:12px;font-weight:600;
                              letter-spacing:.04em;text-transform:uppercase;
                              color:var(--text-secondary)">
                    Pin your property on the map
                </label>
                <div id="map"></div>
                <p class="map-hint">
                    Click on the map to place a pin at your
                    property's exact location.
                    <span id="coordDisplay"
                          style="color:var(--accent);margin-left:6px"></span>
                </p>
            </div>

            <!-- Amenities -->
            <div class="form-section">
                <h3>Amenities</h3>
                <div class="amenity-grid">
                    <?php foreach ([
                        'Wi-Fi','Parking','Breakfast','Swimming Pool',
                        'Air Conditioning','Kitchen','TV','Washing Machine',
                        'Generator','Water Heater','Security Guard',
                        'Garden'
                    ] as $amenity): ?>
                    <label class="amenity-check">
                        <input type="checkbox" name="amenities[]"
                               value="<?= h($amenity) ?>"
                               <?= in_array($amenity,
                                   $_POST['amenities'] ?? [])
                                   ? 'checked' : '' ?>>
                        <?= h($amenity) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- House Rules -->
            <div class="form-section">
                <h3>House rules</h3>
                <div class="form-group">
                    <label>Rules (one per line)</label>
                    <textarea aria-label="Rules (one per line)" name="house_rules" rows="5"
                              placeholder="No smoking inside&#10;No parties or events&#10;Quiet hours after 10pm&#10;No pets"><?= h(
                        $_POST['house_rules'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Photos -->
            <div class="form-section">
                <h3>Photos</h3>
                <p style="font-size:13px;color:var(--text-secondary);
                           margin-bottom:14px">
                    Upload at least 3 clear photos of your property.
                    The first photo will be used as the main image.
                    Maximum 10 photos, 5MB each.
                </p>
                <div class="photo-upload"
                     onclick="document.getElementById('photoInput').click()">
                    <input aria-label="Photos" type="file" id="photoInput"
                           name="photos[]" multiple
                           accept="image/jpeg,image/png,image/webp"
                           onchange="previewPhotos(this)">
                    <p>Click to upload photos</p>
                    <p style="font-size:12px">
                        JPG, PNG or WebP — max 5MB each
                    </p>
                </div>
                <div class="photo-preview" id="photoPreview"></div>
            </div>

            <button type="submit" class="submit-btn">
                Submit listing for approval
            </button>

        </form>

        <?php endif; ?>

    <?php appShellEnd(); ?>

<!-- Map for location picking -->
<script type="text/se-leaflet" data-target="map">
const map = L.map('map').setView([-1.2921, 36.8219], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let marker = null;

<?php if (!empty($_POST['latitude']) && !empty($_POST['longitude'])): ?>
// Restore previous pin if form had errors
marker = L.marker(
    [<?= (float)$_POST['latitude'] ?>, <?= (float)$_POST['longitude'] ?>],
    { icon: SEPins.stay() }
).addTo(map);
map.setView(
    [<?= (float)$_POST['latitude'] ?>, <?= (float)$_POST['longitude'] ?>], 15
);
showCoords(<?= (float)$_POST['latitude'] ?>, <?= (float)$_POST['longitude'] ?>);
<?php endif; ?>

map.on('click', function(e) {
    const lat = e.latlng.lat.toFixed(7);
    const lng = e.latlng.lng.toFixed(7);

    document.getElementById('latInput').value = lat;
    document.getElementById('lngInput').value = lng;

    if (marker) map.removeLayer(marker);
    marker = L.marker([lat, lng], { icon: SEPins.stay() }).addTo(map);
    showCoords(lat, lng);
});

function showCoords(lat, lng) {
    document.getElementById('coordDisplay').textContent =
        lat + ', ' + lng;
}

// Geocode location name to center map
document.getElementById('locationInput')
        .addEventListener('blur', function() {
    const q = this.value.trim();
    if (!q) return;

    fetch('https://nominatim.openstreetmap.org/search' +
          '?format=json&q=' + encodeURIComponent(q + ', Kenya') +
          '&limit=1')
    .then(r => r.json())
    .then(data => {
        if (data.length > 0) {
            map.setView([data[0].lat, data[0].lon], 14);
        }
    });
});
</script>

<!-- Photo preview -->
<script>
function previewPhotos(input) {
    const preview = document.getElementById('photoPreview');
    preview.innerHTML = '';

    Array.from(input.files).slice(0, 10).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.createElement('img');
            img.src   = e.target.result;
            img.className = 'photo-thumb';
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
}
</script>

</body>
</html>