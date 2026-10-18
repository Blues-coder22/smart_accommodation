<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title         = sanitize($_POST['title'] ?? '');
    $description   = sanitize($_POST['description'] ?? '');
    $property_type = sanitize($_POST['property_type'] ?? '');
    $price         = (float)($_POST['price_per_night'] ?? 0);
    $max_guests    = (int)($_POST['max_guests'] ?? 1);
    $location_name = sanitize($_POST['location_name'] ?? '');
    $latitude      = (float)($_POST['latitude'] ?? 0);
    $longitude     = (float)($_POST['longitude'] ?? 0);
    $amenities     = $_POST['amenities'] ?? [];
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
    if (empty($_FILES['photos']['name'][0]))
        $errors[] = 'Please upload at least one photo.';

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

            $ext      = pathinfo($photos['name'][$i], PATHINFO_EXTENSION);
            $filename = 'acc_' . $accommodation_id . '_'
                        . uniqid() . '.' . $ext;
            $dest     = '../uploads/accommodations/' . $filename;

            if (move_uploaded_file($tmp, $dest)) {
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

        // Save amenities
        foreach ($amenities as $amenity) {
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
        foreach ($rules as $rule) {
            if (!empty($rule)) {
                $pdo->prepare("
                    INSERT INTO house_rules
                    (accommodation_id, rule_description)
                    VALUES (?, ?)
                ")->execute([$accommodation_id, $rule]);
            }
        }

        // Notify admin
        $admin = $pdo->query("
            SELECT email FROM users
            WHERE role = 'admin' LIMIT 1
        ")->fetch();

        if ($admin) {
            sendEmail(
                $admin['email'],
                'New Listing Awaiting Approval — Smart Accommodation Kenya',
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Listing — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body        { display:flex; flex-direction:column;
                      min-height:100vh }
        .dash-wrap  { display:grid;
                      grid-template-columns:200px 1fr;
                      flex:1 }
        .sidebar    { background:var(--surface-2);
                      border-right:0.5px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .sidebar-role { font-size:10px; font-weight:700;
                        letter-spacing:.08em; color:#1D9E75;
                        text-transform:uppercase;
                        padding:0 20px; margin-bottom:12px }
        .sidebar-link { display:flex; align-items:center; gap:10px;
                        padding:9px 20px; font-size:13px;
                        color:var(--text-secondary); cursor:pointer;
                        text-decoration:none; width:100% }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #1D9E75 }
        .main       { padding:28px; max-width:800px }
        .page-tag   { font-size:11px; font-weight:600;
                      letter-spacing:.06em; color:#1D9E75;
                      text-transform:uppercase; margin-bottom:8px }
        .page-title { font-size:26px; font-weight:400;
                      margin-bottom:4px }
        .page-sub   { font-size:13px; color:var(--text-secondary);
                      margin-bottom:28px }
        .form-section { background:var(--surface-2);
                        border:0.5px solid var(--border);
                        border-radius:8px; padding:24px;
                        margin-bottom:16px }
        .form-section h3 { font-size:14px; font-weight:500;
                           margin-bottom:18px;
                           padding-bottom:12px;
                           border-bottom:0.5px solid var(--border) }
        .form-group { margin-bottom:16px }
        .form-group label { font-size:12px; font-weight:600;
                            letter-spacing:.04em;
                            text-transform:uppercase;
                            display:block; margin-bottom:6px;
                            color:var(--text-secondary) }
        .form-group input,
        .form-group select,
        .form-group textarea { width:100%; padding:10px 12px;
                               border:0.5px solid var(--border-strong);
                               border-radius:6px; font-size:14px;
                               color:var(--text-primary);
                               background:var(--surface-2);
                               font-family:inherit }
        .form-group textarea { resize:vertical }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline:none;
                                     border-color:#1D9E75 }
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
                               accent-color:#1D9E75;
                               flex-shrink:0 }
        #map        { height:260px; border-radius:6px;
                      border:0.5px solid var(--border-strong);
                      margin-top:8px }
        .map-hint   { font-size:12px; color:var(--text-secondary);
                      margin-top:6px }
        .photo-upload { border:0.5px dashed var(--border-strong);
                        border-radius:6px; padding:24px;
                        text-align:center; cursor:pointer;
                        transition:.15s }
        .photo-upload:hover { border-color:#1D9E75 }
        .photo-upload input { display:none }
        .photo-upload p { font-size:13px;
                          color:var(--text-secondary);
                          margin-bottom:8px }
        .photo-preview { display:flex; flex-wrap:wrap;
                         gap:8px; margin-top:12px }
        .photo-thumb { width:80px; height:64px; border-radius:4px;
                       object-fit:cover;
                       border:0.5px solid var(--border) }
        .submit-btn { width:100%; padding:12px;
                      background:#085041; color:#fff;
                      border:none; border-radius:6px;
                      font-size:14px; font-weight:500;
                      cursor:pointer; margin-top:8px }
        .submit-btn:hover { background:#0a6350 }
        .success-card { background:var(--surface-2);
                        border:0.5px solid var(--border);
                        border-radius:8px; padding:40px;
                        text-align:center }
        .success-icon { width:52px; height:52px;
                        background:#E1F5EE; border-radius:50%;
                        display:flex; align-items:center;
                        justify-content:center;
                        margin:0 auto 16px;
                        border:0.5px solid #9FE1CB }
        .success-icon svg { width:22px; height:22px; stroke:#1D9E75 }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="dash-wrap">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-role">Host</div>
        <a href="dashboard.php" class="sidebar-link">Overview</a>
        <a href="listings.php" class="sidebar-link active">Listings</a>
        <a href="availability.php" class="sidebar-link">Availability</a>
        <a href="bookings.php" class="sidebar-link">Bookings</a>
        <a href="payments.php" class="sidebar-link">Payments</a>
        <a href="reviews.php" class="sidebar-link">Reviews</a>
        <a href="report.php" class="sidebar-link">Reports</a>
        <a href="profile.php" class="sidebar-link">Profile</a>
    </aside>

    <main class="main">

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
                administrator approval. You will receive an email
                once it has been reviewed.
            </p>
            <div style="display:flex;gap:10px;justify-content:center">
                <a href="listings.php"
                   style="padding:10px 20px;background:#085041;
                          color:#fff;border-radius:6px;font-size:14px;
                          font-weight:500;text-decoration:none">
                    View my listings
                </a>
                <a href="add_listing.php"
                   style="padding:10px 20px;background:none;
                          color:var(--text-primary);border-radius:6px;
                          font-size:14px;font-weight:500;
                          text-decoration:none;
                          border:0.5px solid var(--border-strong)">
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
                <p><?= $e ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">

            <!-- Basic Details -->
            <div class="form-section">
                <h3>Basic details</h3>

                <div class="form-group">
                    <label>Property title</label>
                    <input type="text" name="title"
                           value="<?= htmlspecialchars(
                               $_POST['title'] ?? '') ?>"
                           placeholder="e.g. Cozy apartment near Karura Forest"
                           maxlength="150" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Property type</label>
                        <select name="property_type" required>
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
                        <select name="max_guests">
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

                <div class="form-group">
                    <label>Price per night (KES)</label>
                    <input type="number" name="price_per_night"
                           value="<?= $_POST['price_per_night'] ?? '' ?>"
                           min="1" placeholder="e.g. 3500" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="5"
                              placeholder="Describe your property — what makes it special, what guests can expect, nearby landmarks..."
                              required><?= htmlspecialchars(
                        $_POST['description'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Location -->
            <div class="form-section">
                <h3>Location</h3>

                <div class="form-group">
                    <label>Location name</label>
                    <input type="text" name="location_name"
                           id="locationInput"
                           value="<?= htmlspecialchars(
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
                          style="color:#1D9E75;margin-left:6px"></span>
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
                               value="<?= $amenity ?>"
                               <?= in_array($amenity,
                                   $_POST['amenities'] ?? [])
                                   ? 'checked' : '' ?>>
                        <?= $amenity ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- House Rules -->
            <div class="form-section">
                <h3>House rules</h3>
                <div class="form-group">
                    <label>Rules (one per line)</label>
                    <textarea name="house_rules" rows="5"
                              placeholder="No smoking inside&#10;No parties or events&#10;Quiet hours after 10pm&#10;No pets"><?= htmlspecialchars(
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
                    <input type="file" id="photoInput"
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

    </main>
</div>

<?php include '../includes/footer.php'; ?>

<!-- Map for location picking -->
<script>
const map = L.map('map').setView([-1.2921, 36.8219], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let marker = null;

<?php if (!empty($_POST['latitude']) && !empty($_POST['longitude'])): ?>
// Restore previous pin if form had errors
marker = L.marker(
    [<?= $_POST['latitude'] ?>, <?= $_POST['longitude'] ?>]
).addTo(map);
map.setView(
    [<?= $_POST['latitude'] ?>, <?= $_POST['longitude'] ?>], 15
);
showCoords(<?= $_POST['latitude'] ?>, <?= $_POST['longitude'] ?>);
<?php endif; ?>

map.on('click', function(e) {
    const lat = e.latlng.lat.toFixed(7);
    const lng = e.latlng.lng.toFixed(7);

    document.getElementById('latInput').value = lat;
    document.getElementById('lngInput').value = lng;

    if (marker) map.removeLayer(marker);
    marker = L.marker([lat, lng]).addTo(map);
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