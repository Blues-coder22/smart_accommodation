<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/onsite_experience.php';
ensureOnsiteExperienceColumns($pdo);
require_once '../includes/upload_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];
$lid     = (int)($_GET['id'] ?? 0);

// Verify ownership
$stmt = $pdo->prepare("
    SELECT * FROM accommodations
    WHERE accommodation_id = ? AND host_id = ?
");
$stmt->execute([$lid, $host_id]);
$listing = $stmt->fetch();

if (!$listing) {
    setFlash('error', 'Listing not found.');
    redirect('listings.php');
}

// Get existing data
$amenities = $pdo->prepare("
    SELECT amenity_name FROM amenities
    WHERE accommodation_id = ?
");
$amenities->execute([$lid]);
$amenities = $amenities->fetchAll(PDO::FETCH_COLUMN);

$rules = $pdo->prepare("
    SELECT rule_description FROM house_rules
    WHERE accommodation_id = ?
");
$rules->execute([$lid]);
$rules = $rules->fetchAll(PDO::FETCH_COLUMN);

$photos = $pdo->prepare("
    SELECT * FROM accommodation_photos
    WHERE accommodation_id = ?
    ORDER BY is_primary DESC
");
$photos->execute([$lid]);
$photos = $photos->fetchAll();

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
    $new_amenities = (array)($_POST['amenities'] ?? []);
    $new_rules     = array_filter(array_map(
        'trim', explode("\n", $_POST['house_rules'] ?? '')
    ));

    if (empty($title))
        $errors[] = 'Property title is required.';
    if (empty($description) || strlen($description) < 50)
        $errors[] = 'Description must be at least 50 characters.';
    if ($price <= 0)
        $errors[] = 'Please enter a valid price.';
    if (empty($location_name))
        $errors[] = 'Location is required.';
    if ($latitude == 0 || $longitude == 0)
        $errors[] = 'Please pin your property on the map.';

    if (empty($errors)) {
        // Update listing
        // Reset to pending if major details changed
        $needs_review = (
            $listing['title'] !== $title
            || $listing['description'] !== $description
            || $listing['price_per_night'] != $price
        );

        $new_status = $needs_review
            ? 'pending' : $listing['status'];

        $pdo->prepare("
            UPDATE accommodations SET
                title = ?, description = ?,
                property_type = ?, price_per_night = ?,
                max_guests = ?, location_name = ?,
                latitude = ?, longitude = ?,
                status = ?
            WHERE accommodation_id = ?
        ")->execute([
            $title, $description, $property_type,
            $price, $max_guests, $location_name,
            $latitude, $longitude,
            $new_status, $lid
        ]);
        saveOnsiteExperience($pdo, $lid, $_POST);

        // Update amenities
        $pdo->prepare("
            DELETE FROM amenities
            WHERE accommodation_id = ?
        ")->execute([$lid]);

        foreach ($new_amenities as $am) {
            $am = sanitize($am);
            if (!empty($am)) {
                $pdo->prepare("
                    INSERT INTO amenities
                    (accommodation_id, amenity_name)
                    VALUES (?, ?)
                ")->execute([$lid, $am]);
            }
        }

        // Update house rules
        $pdo->prepare("
            DELETE FROM house_rules
            WHERE accommodation_id = ?
        ")->execute([$lid]);

        foreach ($new_rules as $rule) {
            if (!empty($rule)) {
                $pdo->prepare("
                    INSERT INTO house_rules
                    (accommodation_id, rule_description)
                    VALUES (?, ?)
                ")->execute([$lid, $rule]);
            }
        }

        // Handle new photos
        if (!empty($_FILES['photos']['name'][0])) {
            $photos_arr = $_FILES['photos'];
            foreach ($photos_arr['tmp_name'] as $i => $tmp) {
                if ($photos_arr['error'][$i] !== UPLOAD_ERR_OK)
                    continue;
                $allowed = ['image/jpeg','image/png','image/webp'];
                $ftype   = mime_content_type($tmp);
                if (!in_array($ftype, $allowed)) continue;
                if ($photos_arr['size'][$i] > 5*1024*1024) continue;

                $filename = saveUploadedImage(
                    $tmp, $ftype, 'accommodations', 'acc_' . $lid,
                    5 * 1024 * 1024
                );

                if ($filename !== false) {
                    $pdo->prepare("
                        INSERT INTO accommodation_photos
                        (accommodation_id, photo_path, is_primary)
                        VALUES (?, ?, 0)
                    ")->execute([$lid, $filename]);
                }
            }
        }

        // Handle photo deletions
        $delete_photos = (array)($_POST['delete_photos'] ?? []);
        foreach ($delete_photos as $photo_id) {
            $photo_id = (int)$photo_id;
            $p = $pdo->prepare("
                SELECT photo_path FROM accommodation_photos
                WHERE photo_id = ?
                AND accommodation_id = ?
            ");
            $p->execute([$photo_id, $lid]);
            $p = $p->fetch();
            if ($p) {
                $photoPath = dirname(__DIR__) . '/uploads/accommodations/'
                    . basename($p['photo_path']);
                if (is_file($photoPath)) @unlink($photoPath);
                $pdo->prepare("
                    DELETE FROM accommodation_photos
                    WHERE photo_id = ?
                ")->execute([$photo_id]);
            }
        }

        // Always keep one main photo: if the main one was removed (or none was ever set), promote the oldest remaining photo
        $hasPrimary = $pdo->prepare('SELECT COUNT(*) FROM accommodation_photos WHERE accommodation_id = ? AND is_primary = 1');
        $hasPrimary->execute([$lid]);
        if ((int)$hasPrimary->fetchColumn() === 0) {
            $pdo->prepare('UPDATE accommodation_photos SET is_primary = 1 WHERE accommodation_id = ? ORDER BY photo_id ASC LIMIT 1')
                ->execute([$lid]);
        }

        if ($needs_review) {
            setFlash('success',
                'Listing updated and resubmitted for approval.');
        } else {
            setFlash('success', 'Listing updated successfully.');
        }
        redirect('listings.php');
    }

    // Refresh photos after changes
    $photos_q = $pdo->prepare("
        SELECT * FROM accommodation_photos
        WHERE accommodation_id = ?
        ORDER BY is_primary DESC
    ");
    $photos_q->execute([$lid]);
    $photos = $photos_q->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Listing — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../assets/js/map-pins.js"></script>
    <style>
        
        .dash-wrap  { display:grid;
                      grid-template-columns:200px 1fr; flex:1 }
        .sidebar    { background:var(--surface-2);
                      border-right:1px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .sidebar-role { font-size:10px; font-weight:700;
                        letter-spacing:.08em; color:#126b4e;
                        text-transform:uppercase;
                        padding:0 20px; margin-bottom:12px }
        .sidebar-link { display:flex; align-items:center; gap:10px;
                        padding:9px 20px; font-size:13px;
                        color:var(--text-secondary);
                        text-decoration:none; width:100% }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #126b4e }
        .main       { padding:28px; max-width:800px }
        .page-tag   { font-size:11px; font-weight:600;
                      letter-spacing:.06em; color:#126b4e;
                      text-transform:uppercase; margin-bottom:8px }
        .page-title { font-size:26px; font-weight:400;
                      margin-bottom:4px }
        .page-sub   { font-size:13px; color:var(--text-secondary);
                      margin-bottom:24px }
        .form-section { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; padding:24px;
                        margin-bottom:16px }
        .form-section h3 { font-size:14px; font-weight:500;
                           margin-bottom:18px; padding-bottom:12px;
                           border-bottom:1px solid var(--border) }
        .form-group { margin-bottom:16px }
        .form-group label { font-size:12px; font-weight:600;
                            letter-spacing:.04em; text-transform:uppercase;
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
                                     border-color:#126b4e }
        .form-row   { display:grid;
                      grid-template-columns:1fr 1fr; gap:14px }
        .amenity-grid { display:grid;
                        grid-template-columns:repeat(3,1fr);
                        gap:8px }
        .amenity-check { display:flex; align-items:center;
                         gap:8px; font-size:13px }
        .amenity-check input { width:15px; height:15px;
                               accent-color:#126b4e }
        #map        { height:260px; border-radius:6px;
                      border:1px solid var(--border-strong);
                      margin-top:8px }
        .map-hint   { font-size:12px; color:var(--text-secondary);
                      margin-top:6px }
        .photo-grid { display:flex; flex-wrap:wrap; gap:8px;
                      margin-bottom:12px }
        .photo-item { position:relative; width:100px; height:80px }
        .photo-item img { width:100%; height:100%; object-fit:cover;
                          border-radius:6px;
                          border:1px solid var(--border) }
        .photo-del  { position:absolute; top:-6px; right:-6px;
                      width:18px; height:18px; background:#DC2626;
                      color:#fff; border:none; border-radius:50%;
                      font-size:11px; cursor:pointer;
                      display:flex; align-items:center;
                      justify-content:center }
        .photo-primary { position:absolute; bottom:2px; left:2px;
                         font-size:9px; background:#085041;
                         color:#fff; padding:1px 4px;
                         border-radius:2px }
        .photo-upload { border:0.5px dashed var(--border-strong);
                        border-radius:6px; padding:16px;
                        text-align:center; cursor:pointer }
        .photo-upload input { display:none }
        .photo-upload p { font-size:13px;
                          color:var(--text-secondary) }
        .photo-preview { display:flex; flex-wrap:wrap; gap:8px;
                         margin-top:10px }
        .photo-preview img { width:80px; height:64px;
                             border-radius:4px; object-fit:cover;
                             border:1px solid var(--border) }
        .resubmit-notice { background:#FFF8E1;
                           border:1px solid #F9D57C;
                           border-radius:6px; padding:12px 14px;
                           font-size:13px; color:#7a5200;
                           margin-bottom:20px }
        .submit-btn { width:100%; padding:12px; background:#085041;
                      color:#fff; border:none; border-radius:6px;
                      font-size:14px; font-weight:500; cursor:pointer }
        .submit-btn:hover { background:#0a6350 }
    </style>
</head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Edit listing</h1>
        <p class="page-sub">
            <?= h($listing['title']) ?>
        </p>

        <div class="resubmit-notice">
            Changing the title, description, or price will
            resubmit the listing for admin approval before
            going live again.
        </div>

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
                               $_POST['title']
                               ?? $listing['title']) ?>"
                           maxlength="150" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Property type</label>
                        <select aria-label="Property type" name="property_type" required>
                            <?php foreach ([
                                'Apartment','House','Guest House',
                                'Villa','Studio','Cottage',
                                'Lodge','Hostel'
                            ] as $t): ?>
                            <option value="<?= $t ?>"
                                <?= (($_POST['property_type']
                                      ?? $listing['property_type'])
                                     === $t) ? 'selected' : '' ?>>
                                <?= $t ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Maximum guests</label>
                        <select aria-label="Maximum guests" name="max_guests">
                            <?php for ($i=1; $i<=20; $i++): ?>
                            <option value="<?= $i ?>"
                                <?= (($_POST['max_guests']
                                      ?? $listing['max_guests'])
                                     == $i) ? 'selected' : '' ?>>
                                <?= $i ?> guest<?= $i>1?'s':'' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <?= onsiteExperienceFormHtml($_POST['onsite_name'] ?? ($listing['onsite_experience_name'] ?? ''), $_POST['onsite_fee'] ?? ($listing['onsite_experience_fee'] ?? '')) ?>
                <div class="form-group">
                    <label>Price per night (KES)</label>
                    <input aria-label="Price per night (KES)" type="number" name="price_per_night"
                           value="<?= $_POST['price_per_night']
                               ?? $listing['price_per_night'] ?>"
                           min="1" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea aria-label="Description" name="description" rows="5"
                              required><?= h(
                        $_POST['description']
                        ?? $listing['description']) ?></textarea>
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
                               $_POST['location_name']
                               ?? $listing['location_name']) ?>"
                           required>
                </div>
                <input type="hidden" name="latitude" id="latInput"
                       value="<?= $_POST['latitude']
                           ?? $listing['latitude'] ?>">
                <input type="hidden" name="longitude" id="lngInput"
                       value="<?= $_POST['longitude']
                           ?? $listing['longitude'] ?>">
                <label style="font-size:12px;font-weight:600;
                              letter-spacing:.04em;
                              text-transform:uppercase;
                              color:var(--text-secondary)">
                    Property location
                </label>
                <div id="map"></div>
                <p class="map-hint">
                    Click to move the pin to your exact location.
                    <span id="coordDisplay"
                          style="color:#126b4e;margin-left:6px">
                        <?= number_format($listing['latitude'],4) ?>,
                        <?= number_format($listing['longitude'],4) ?>
                    </span>
                </p>
            </div>

            <!-- Amenities -->
            <div class="form-section">
                <h3>Amenities</h3>
                <div class="amenity-grid">
                    <?php foreach ([
                        'Wi-Fi','Parking','Breakfast',
                        'Swimming Pool','Air Conditioning',
                        'Kitchen','TV','Washing Machine',
                        'Generator','Water Heater',
                        'Security Guard','Garden'
                    ] as $amenity): ?>
                    <label class="amenity-check">
                        <input type="checkbox" name="amenities[]"
                               value="<?= h($amenity) ?>"
                               <?= in_array($amenity,
                                   (array)($_POST['amenities']
                                   ?? $amenities))
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
                              placeholder="No smoking inside&#10;No parties&#10;Quiet hours after 10pm"><?= h(
                        $_POST['house_rules']
                        ?? implode("\n", $rules)) ?></textarea>
                </div>
            </div>

            <!-- Photos -->
            <div class="form-section">
                <h3>Photos</h3>

                <!-- Existing Photos -->
                <?php if (!empty($photos)): ?>
                <p style="font-size:13px;color:var(--text-secondary);
                           margin-bottom:10px">
                    Existing photos — check the box to delete
                </p>
                <div class="photo-grid">
                    <?php foreach ($photos as $p): ?>
                    <div class="photo-item">
                        <img src="../uploads/accommodations/<?= h($p['photo_path']) ?>"
                             alt="Property photo">
                        <?php if ($p['is_primary']): ?>
                        <span class="photo-primary">Main</span>
                        <?php endif; ?>
                        <label style="position:absolute;
                                      bottom:-2px;right:-2px">
                            <input type="checkbox"
                                   name="delete_photos[]"
                                   value="<?= $p['photo_id'] ?>"
                                   style="accent-color:#DC2626;
                                          width:14px;height:14px">
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Add New Photos -->
                <p style="font-size:13px;color:var(--text-secondary);
                           margin:12px 0 8px">
                    Add more photos
                </p>
                <div class="photo-upload"
                     onclick="document.getElementById(
                         'newPhotos').click()">
                    <input aria-label="Photos" type="file" id="newPhotos"
                           name="photos[]" multiple
                           accept="image/jpeg,image/png,image/webp"
                           onchange="previewNewPhotos(this)">
                    <p>Click to upload additional photos</p>
                </div>
                <div class="photo-preview" id="newPreview"></div>
            </div>

            <div style="display:flex;gap:10px">
                <button type="submit" class="submit-btn">
                    Save changes
                </button>
                <a href="listings.php"
                   style="padding:12px 20px;font-size:14px;
                          color:var(--text-secondary);
                          border:1px solid var(--border-strong);
                          border-radius:6px;text-decoration:none;
                          display:flex;align-items:center">
                    Cancel
                </a>
            </div>

        </form>

    <?php appShellEnd(); ?>

<script>
const map = L.map('map').setView(
    [<?= $listing['latitude'] ?>, <?= $listing['longitude'] ?>],
    14
);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
    attribution:'© OpenStreetMap contributors'
}).addTo(map);

let marker = L.marker(
    [<?= $listing['latitude'] ?>, <?= $listing['longitude'] ?>],
    { icon: SEPins.stay(), draggable: true }
).addTo(map);

// Update on marker drag
marker.on('dragend', function() {
    const pos = marker.getLatLng();
    document.getElementById('latInput').value =
        pos.lat.toFixed(7);
    document.getElementById('lngInput').value =
        pos.lng.toFixed(7);
    document.getElementById('coordDisplay').textContent =
        pos.lat.toFixed(4) + ', ' + pos.lng.toFixed(4);
});

// Update on map click
map.on('click', function(e) {
    const lat = e.latlng.lat.toFixed(7);
    const lng = e.latlng.lng.toFixed(7);
    document.getElementById('latInput').value = lat;
    document.getElementById('lngInput').value = lng;
    document.getElementById('coordDisplay').textContent =
        parseFloat(lat).toFixed(4) + ', ' +
        parseFloat(lng).toFixed(4);
    marker.setLatLng([lat, lng]);
});

function previewNewPhotos(input) {
    const preview = document.getElementById('newPreview');
    preview.innerHTML = '';
    Array.from(input.files).slice(0,10).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.createElement('img');
            img.src   = e.target.result;
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
}
</script>

</body>
</html>