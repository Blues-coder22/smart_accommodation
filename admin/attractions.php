<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

$errors  = [];
$success = false;
$editing = null;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete_attraction'])) {
    $aid = (int)$_POST['attraction_id'];
    $pdo->prepare("
        DELETE FROM attractions WHERE attraction_id = ?
    ")->execute([$aid]);
    setFlash('success', 'Attraction deleted.');
    redirect('attractions.php');
}

// Handle add or edit
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['save_attraction'])) {
    $aid         = (int)($_POST['attraction_id'] ?? 0);
    $name        = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $category    = sanitize($_POST['category'] ?? '');
    $location    = sanitize($_POST['location_name'] ?? '');
    $latitude    = (float)($_POST['latitude'] ?? 0);
    $longitude   = (float)($_POST['longitude'] ?? 0);
    $entry_fee   = (float)($_POST['entry_fee'] ?? 0);

    if (empty($name))
        $errors[] = 'Attraction name is required.';
    if (empty($category))
        $errors[] = 'Category is required.';
    if (empty($location))
        $errors[] = 'Location name is required.';
    if ($latitude == 0 || $longitude == 0)
        $errors[] = 'Please pin the attraction on the map.';

    // Handle photo upload
    $photo = $_POST['existing_photo'] ?? null;

    if (isset($_FILES['photo'])
        && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/webp'];
        $ftype   = mime_content_type($_FILES['photo']['tmp_name']);

        if (!in_array($ftype, $allowed)) {
            $errors[] = 'Photo must be JPG, PNG or WebP.';
        } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Photo must be under 5MB.';
        } else {
            $ext      = pathinfo(
                $_FILES['photo']['name'], PATHINFO_EXTENSION
            );
            $filename = 'att_' . uniqid() . '.' . $ext;
            $dest     = '../uploads/attractions/' . $filename;
            if (move_uploaded_file(
                $_FILES['photo']['tmp_name'], $dest)) {
                $photo = $filename;
            }
        }
    }

    if (empty($errors)) {
        if ($aid) {
            // Update existing
            $pdo->prepare("
                UPDATE attractions SET
                    name = ?, description = ?,
                    category = ?, location_name = ?,
                    latitude = ?, longitude = ?,
                    entry_fee = ?, photo_path = ?
                WHERE attraction_id = ?
            ")->execute([
                $name, $description, $category,
                $location, $latitude, $longitude,
                $entry_fee, $photo, $aid
            ]);
            setFlash('success', "Attraction \"$name\" updated.");
        } else {
            // Insert new
            $pdo->prepare("
                INSERT INTO attractions
                (name, description, category, location_name,
                 latitude, longitude, entry_fee, photo_path)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $name, $description, $category,
                $location, $latitude, $longitude,
                $entry_fee, $photo
            ]);
            setFlash('success', "Attraction \"$name\" added.");
        }
        redirect('attractions.php');
    }
}

// Get attraction being edited
$edit_id = (int)($_GET['edit'] ?? 0);
if ($edit_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM attractions WHERE attraction_id = ?
    ");
    $stmt->execute([$edit_id]);
    $editing = $stmt->fetch();
}

// Get all attractions
$attractions = $pdo->query("
    SELECT * FROM attractions ORDER BY category ASC, name ASC
")->fetchAll();

// Group by category
$grouped = [];
foreach ($attractions as $att) {
    $grouped[$att['category']][] = $att;
}

$categories = [
    'National Parks','Museums','Beaches',
    'Historical Sites','Adventure','Culture',
    'Restaurants','Shopping','Entertainment',
    'Religious Sites','Wildlife','Nature'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attractions — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body         { display:flex; flex-direction:column;
                       min-height:100vh }
        .dash-wrap   { display:grid;
                       grid-template-columns:200px 1fr; flex:1 }
        .sidebar     { background:var(--surface-2);
                       border-right:0.5px solid var(--border);
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
        .main        { padding:28px }
        .page-layout { display:grid;
                       grid-template-columns:1fr 340px;
                       gap:24px; align-items:start }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#1D9E75;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:4px }
        .page-sub    { font-size:13px; color:var(--text-secondary);
                       margin-bottom:20px }
        .form-card   { background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; padding:24px;
                       position:sticky; top:80px }
        .form-card h3{ font-size:14px; font-weight:500;
                       margin-bottom:18px;
                       padding-bottom:12px;
                       border-bottom:0.5px solid var(--border) }
        .form-group  { margin-bottom:14px }
        .form-group label { font-size:11px; font-weight:600;
                            letter-spacing:.04em; text-transform:uppercase;
                            display:block; margin-bottom:5px;
                            color:var(--text-secondary) }
        .form-group input,
        .form-group select,
        .form-group textarea { width:100%; padding:9px 12px;
                               border:0.5px solid var(--border-strong);
                               border-radius:6px; font-size:13px;
                               color:var(--text-primary);
                               background:var(--surface-2);
                               font-family:inherit }
        .form-group textarea { resize:vertical; min-height:80px }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline:none;
                                     border-color:#1D9E75 }
        .form-row    { display:grid;
                       grid-template-columns:1fr 1fr; gap:10px }
        #map         { height:200px; border-radius:6px;
                       border:0.5px solid var(--border-strong);
                       margin-top:6px }
        .map-hint    { font-size:11px; color:var(--text-secondary);
                       margin-top:4px }
        .existing-photo { width:100%; height:80px; object-fit:cover;
                          border-radius:6px; margin-bottom:8px;
                          border:0.5px solid var(--border) }
        .btn-save    { width:100%; padding:10px; background:#085041;
                       color:#fff; border:none; border-radius:6px;
                       font-size:13px; font-weight:500; cursor:pointer }
        .btn-save:hover { background:#0a6350 }
        .btn-cancel  { width:100%; padding:9px; background:none;
                       color:var(--text-secondary);
                       border:0.5px solid var(--border-strong);
                       border-radius:6px; font-size:13px;
                       cursor:pointer; margin-top:6px;
                       text-align:center; display:block;
                       text-decoration:none }
        .category-section { margin-bottom:20px }
        .category-label { font-size:11px; font-weight:700;
                          letter-spacing:.06em; text-transform:uppercase;
                          color:var(--text-secondary); margin-bottom:10px;
                          display:flex; align-items:center;
                          justify-content:space-between }
        .att-row     { background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; display:grid;
                       grid-template-columns:64px 1fr auto;
                       gap:12px; align-items:center;
                       padding:12px; margin-bottom:8px;
                       transition:border-color .15s }
        .att-row:hover { border-color:var(--border-strong) }
        .att-thumb   { width:64px; height:52px; border-radius:4px;
                       background:#EEEDFE; overflow:hidden;
                       display:flex; align-items:center;
                       justify-content:center; font-size:20px;
                       flex-shrink:0 }
        .att-thumb img { width:100%; height:100%; object-fit:cover }
        .att-name    { font-size:13px; font-weight:500;
                       margin-bottom:2px }
        .att-meta    { font-size:12px; color:var(--text-secondary) }
        .att-actions { display:flex; gap:6px; flex-shrink:0 }
        .btn-edit    { padding:5px 12px; font-size:11px;
                       border-radius:3px; cursor:pointer;
                       color:var(--text-secondary);
                       border:0.5px solid var(--border-strong);
                       background:none; text-decoration:none;
                       display:inline-block }
        .btn-edit:hover { border-color:#1D9E75; color:#1D9E75 }
        .btn-del     { padding:5px 12px; font-size:11px;
                       border-radius:3px; cursor:pointer;
                       color:#DC2626; border:0.5px solid #FECACA;
                       background:none }
        .btn-del:hover { background:#FEF2F2 }
        .empty-cat   { font-size:13px; color:var(--text-secondary);
                       padding:12px; text-align:center;
                       border:0.5px dashed var(--border);
                       border-radius:6px }
        .count-badge { font-size:11px; background:var(--surface-1);
                       border:0.5px solid var(--border);
                       border-radius:10px; padding:1px 8px;
                       color:var(--text-secondary) }
        @media(max-width:1000px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .page-layout { grid-template-columns:1fr }
            .form-card { position:static }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="dash-wrap">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-role">Admin</div>
        <a href="dashboard.php" class="sidebar-link">Overview</a>
        <a href="listings.php" class="sidebar-link">
            Listing approvals
        </a>
        <a href="users.php" class="sidebar-link">Users</a>
        <a href="reviews.php" class="sidebar-link">
            Review moderation
        </a>
        <a href="reports.php" class="sidebar-link">Reports</a>
        <a href="attractions.php"
           class="sidebar-link active">Attractions</a>
        <a href="profile.php" class="sidebar-link">Profile</a>
    </aside>

    <main class="main">

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Attractions</h1>
        <p class="page-sub">
            Add and manage tourist attractions.
            These appear in search results and
            on recommendation maps.
        </p>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:20px">
            <?php foreach ($errors as $e): ?>
                <p><?= $e ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="page-layout">

            <!-- Left — Attractions List -->
            <div>
                <?php if (empty($attractions)): ?>
                    <div style="text-align:center;padding:48px;
                                font-size:14px;
                                color:var(--text-secondary)">
                        No attractions added yet.
                        Use the form to add your first attraction.
                    </div>
                <?php else: ?>
                    <?php foreach ($categories as $cat): ?>
                    <?php if (!isset($grouped[$cat])) continue; ?>
                    <div class="category-section">
                        <div class="category-label">
                            <?= $cat ?>
                            <span class="count-badge">
                                <?= count($grouped[$cat]) ?>
                            </span>
                        </div>
                        <?php foreach ($grouped[$cat] as $att): ?>
                        <div class="att-row">
                            <div class="att-thumb">
                                <?php if ($att['photo_path']): ?>
                                    <img src="../uploads/attractions/<?= htmlspecialchars($att['photo_path']) ?>"
                                         alt="<?= htmlspecialchars($att['name']) ?>">
                                <?php else: ?>
                                    &#128205;
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="att-name">
                                    <?= htmlspecialchars($att['name']) ?>
                                </div>
                                <div class="att-meta">
                                    <?= htmlspecialchars(
                                        $att['location_name']) ?>
                                    &middot;
                                    <?= $att['entry_fee'] > 0
                                        ? 'KES ' . number_format(
                                            $att['entry_fee'])
                                        : 'Free entry' ?>
                                    &middot;
                                    <?= number_format(
                                        $att['latitude'], 4) ?>,
                                    <?= number_format(
                                        $att['longitude'], 4) ?>
                                </div>
                            </div>
                            <div class="att-actions">
                                <a href="?edit=<?= $att['attraction_id'] ?>"
                                   class="btn-edit">Edit</a>
                                <form method="POST" style="margin:0">
                                    <input type="hidden"
                                           name="attraction_id"
                                           value="<?= $att['attraction_id'] ?>">
                                    <input type="hidden"
                                           name="delete_attraction"
                                           value="1">
                                    <button type="submit"
                                            class="btn-del"
                                            onclick="return confirm(
                                                'Delete this attraction?')">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>

                    <!-- Uncategorized -->
                    <?php
                    $other = array_filter($attractions,
                        fn($a) => !in_array($a['category'],
                            $categories));
                    if (!empty($other)):
                    ?>
                    <div class="category-section">
                        <div class="category-label">Other</div>
                        <?php foreach ($other as $att): ?>
                        <div class="att-row">
                            <div class="att-thumb">&#128205;</div>
                            <div>
                                <div class="att-name">
                                    <?= htmlspecialchars($att['name']) ?>
                                </div>
                                <div class="att-meta">
                                    <?= htmlspecialchars(
                                        $att['location_name']) ?>
                                </div>
                            </div>
                            <div class="att-actions">
                                <a href="?edit=<?= $att['attraction_id'] ?>"
                                   class="btn-edit">Edit</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Right — Add / Edit Form -->
            <div>
                <div class="form-card">
                    <h3>
                        <?= $editing
                            ? 'Edit attraction'
                            : 'Add new attraction' ?>
                    </h3>

                    <form method="POST"
                          enctype="multipart/form-data">
                        <input type="hidden" name="save_attraction"
                               value="1">
                        <input type="hidden" name="attraction_id"
                               value="<?= $editing
                                   ? $editing['attraction_id']
                                   : 0 ?>">
                        <input type="hidden" name="existing_photo"
                               value="<?= htmlspecialchars(
                                   $editing['photo_path'] ?? '') ?>">

                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" name="name"
                                   value="<?= htmlspecialchars(
                                       $editing['name']
                                       ?? ($_POST['name'] ?? '')) ?>"
                                   placeholder="e.g. Nairobi National Park"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Category</label>
                            <select name="category" required>
                                <option value="">Select category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat ?>"
                                    <?= (($editing['category']
                                          ?? '') === $cat
                                         || ($_POST['category']
                                             ?? '') === $cat)
                                        ? 'selected' : '' ?>>
                                    <?= $cat ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Location name</label>
                            <input type="text" name="location_name"
                                   id="locInput"
                                   value="<?= htmlspecialchars(
                                       $editing['location_name']
                                       ?? ($_POST['location_name']
                                           ?? '')) ?>"
                                   placeholder="e.g. Langata, Nairobi"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Entry fee (KES — 0 for free)</label>
                            <input type="number" name="entry_fee"
                                   value="<?= $editing['entry_fee']
                                       ?? ($_POST['entry_fee'] ?? 0) ?>"
                                   min="0" step="50">
                        </div>

                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description"
                                      placeholder="Brief description of the attraction..."><?= htmlspecialchars(
                                $editing['description']
                                ?? ($_POST['description'] ?? '')) ?>
                            </textarea>
                        </div>

                        <!-- Photo -->
                        <div class="form-group">
                            <label>Photo</label>
                            <?php if ($editing && $editing['photo_path']): ?>
                            <img src="../uploads/attractions/<?= htmlspecialchars($editing['photo_path']) ?>"
                                 class="existing-photo"
                                 alt="Current photo">
                            <p style="font-size:11px;
                                      color:var(--text-secondary);
                                      margin-bottom:6px">
                                Upload a new photo to replace
                            </p>
                            <?php endif; ?>
                            <input type="file" name="photo"
                                   accept="image/jpeg,image/png,
                                           image/webp">
                        </div>

                        <!-- Map -->
                        <div class="form-group">
                            <label>Pin location on map</label>
                            <input type="hidden" name="latitude"
                                   id="latInput"
                                   value="<?= $editing['latitude']
                                       ?? ($_POST['latitude'] ?? '') ?>">
                            <input type="hidden" name="longitude"
                                   id="lngInput"
                                   value="<?= $editing['longitude']
                                       ?? ($_POST['longitude'] ?? '') ?>">
                            <div id="map"></div>
                            <p class="map-hint">
                                Click to pin exact location.
                                <span id="coordDisplay"
                                      style="color:#1D9E75">
                                    <?php if ($editing): ?>
                                        <?= number_format(
                                            $editing['latitude'], 4) ?>,
                                        <?= number_format(
                                            $editing['longitude'], 4) ?>
                                    <?php endif; ?>
                                </span>
                            </p>
                        </div>

                        <button type="submit" class="btn-save">
                            <?= $editing
                                ? 'Update attraction'
                                : 'Add attraction' ?>
                        </button>

                        <?php if ($editing): ?>
                        <a href="attractions.php" class="btn-cancel">
                            Cancel editing
                        </a>
                        <?php endif; ?>

                    </form>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>

<script>
// Init map
const initLat = <?= $editing['latitude']
    ?? ($_POST['latitude'] ?? -1.2921) ?>;
const initLng = <?= $editing['longitude']
    ?? ($_POST['longitude'] ?? 36.8219) ?>;
const initZoom = <?= ($editing || !empty($_POST['latitude']))
    ? 13 : 7 ?>;

const map = L.map('map').setView([initLat, initLng], initZoom);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let marker = null;

<?php if ($editing): ?>
marker = L.marker(
    [<?= $editing['latitude'] ?>, <?= $editing['longitude'] ?>]
).addTo(map);
<?php endif; ?>

map.on('click', function(e) {
    const lat = e.latlng.lat.toFixed(7);
    const lng = e.latlng.lng.toFixed(7);
    document.getElementById('latInput').value = lat;
    document.getElementById('lngInput').value = lng;
    document.getElementById('coordDisplay').textContent =
        lat + ', ' + lng;
    if (marker) map.removeLayer(marker);
    marker = L.marker([lat, lng]).addTo(map);
});

// Geocode on location input blur
document.getElementById('locInput')
    .addEventListener('blur', function() {
    const q = this.value.trim();
    if (!q) return;
    fetch('https://nominatim.openstreetmap.org/search' +
          '?format=json&q=' + encodeURIComponent(q + ', Kenya') +
          '&limit=1')
    .then(r => r.json())
    .then(data => {
        if (data.length > 0) {
            map.setView([data[0].lat, data[0].lon], 13);
        }
    });
});
</script>

</body>
</html>