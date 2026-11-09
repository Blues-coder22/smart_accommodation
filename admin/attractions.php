<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/gallery.php';
require_once '../includes/commons_photos.php';
ensureAttractionPhotosTable($pdo);
require_once '../includes/attraction_content.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

$errors  = [];
$success = false;
$editing = null;

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete_attraction'])) {
    requireCsrf();
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
    requireCsrf();
    $aid         = (int)($_POST['attraction_id'] ?? 0);
    $name        = sanitize($_POST['name'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $category    = sanitize($_POST['category'] ?? '');
    $location    = sanitize($_POST['location_name'] ?? '');
    $latitude    = (float)($_POST['latitude'] ?? 0);
    $longitude   = (float)($_POST['longitude'] ?? 0);
    $extras = [];
    foreach (['tagline', 'best_time', 'climate', 'terrain', 'best_for', 'did_you_know', 'whats_new'] as $k) {
        $extras[$k] = sanitize($_POST[$k] ?? '');
    }

    if (empty($name))
        $errors[] = 'Attraction name is required.';
    if (empty($category))
        $errors[] = 'Category is required.';
    if (empty($location))
        $errors[] = 'Location name is required.';
    if ($latitude == 0 || $longitude == 0)
        $errors[] = 'Please pin the attraction on the map.';

    // A new attraction with the same name at (almost) the same spot as an existing one is a duplicate.
    $duplicateOf = null;
    if (!$aid && $name !== '' && $latitude != 0 && $longitude != 0) {
        $dup = $pdo->prepare("SELECT attraction_id, name FROM attractions
                              WHERE LOWER(name) = LOWER(?) AND ABS(latitude - ?) < 0.002 AND ABS(longitude - ?) < 0.002 LIMIT 1");
        $dup->execute([$name, $latitude, $longitude]);
        $duplicateOf = $dup->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($duplicateOf) {
            $errors[] = '“' . html_entity_decode($duplicateOf['name'], ENT_QUOTES, 'UTF-8') . '” is already in your attractions at this spot, so it was not added again. Edit the existing one instead.';
        }
    }

    // Photos: several can be added at once and removed one by one (handled after the save below)
    $photo = $_POST['existing_photo'] ?? null;

    if (empty($errors)) {
        if ($aid) {
            // Update existing
            $pdo->prepare("
                UPDATE attractions SET
                    name = ?, description = ?,
                    category = ?, location_name = ?,
                    latitude = ?, longitude = ?,
                    photo_path = ?
                WHERE attraction_id = ?
            ")->execute([
                $name, $description, $category,
                $location, $latitude, $longitude,
                $photo, $aid
            ]);
            saveAttractionExtras($pdo, $aid, $extras);
            $photoErrors = saveAttractionPhotos($pdo, $aid, $_FILES['photos'] ?? [], (array)($_POST['remove_photos'] ?? []), '../uploads/attractions');
            $cm = importCommonsPhotos($pdo, $aid, (array)($_POST['commons_titles'] ?? []), '../uploads/attractions');
            $photoErrors = array_merge($photoErrors, $cm['errors']);
            setFlash($photoErrors ? 'warning' : 'success', "Attraction \"$name\" updated." . ($photoErrors ? ' Some photos were skipped: ' . implode(' ', $photoErrors) : ''));
        } else {
            // Insert new
            $pdo->prepare("
                INSERT INTO attractions
                (name, description, category, location_name,
                 latitude, longitude, entry_fee, photo_path)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?)
            ")->execute([
                $name, $description, $category,
                $location, $latitude, $longitude,
                $photo
            ]);
            $newId = (int)$pdo->lastInsertId();
            saveAttractionExtras($pdo, $newId, $extras);
            $photoErrors = saveAttractionPhotos($pdo, $newId, $_FILES['photos'] ?? [], [], '../uploads/attractions');
            $cm = importCommonsPhotos($pdo, $newId, (array)($_POST['commons_titles'] ?? []), '../uploads/attractions');
            $photoErrors = array_merge($photoErrors, $cm['errors']);
            setFlash($photoErrors ? 'warning' : 'success', "Attraction \"$name\" added." . ($photoErrors ? ' Some photos were skipped: ' . implode(' ', $photoErrors) : ''));
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
    $editExtras = $editing ? getAttractionExtras($pdo, $edit_id) : [];
}
$editExtras = $editExtras ?? [];

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

// "Add attraction" buttons on the map page (osm_attractions.php) open this form with the place already filled in:
// attractions.php?add=1&name=...&category=...&location=...&lat=...&lng=...
$prefill = [];
if (!$editing && isset($_GET['add'])) {
    $pLat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $pLng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($pLat !== false && $pLng !== false && $pLat >= -90 && $pLat <= 90 && $pLng >= -180 && $pLng <= 180) {
        $pCat = trim((string)($_GET['category'] ?? ''));
        $prefill = [
            'name'     => substr(trim(strip_tags((string)($_GET['name'] ?? ''))), 0, 150),
            'category' => in_array($pCat, $categories, true) ? $pCat : '',
            'location' => substr(trim(strip_tags((string)($_GET['location'] ?? ''))), 0, 150),
            'lat'      => round($pLat, 7),
            'lng'      => round($pLng, 7),
        ];
    }
}
// Photos picked with "Find photos" on the map arrive as ?photos=File:A.jpg|File:B.jpg (or come back in POST after an error).
$pickedCommons = []; $pickedCommonsError = '';
$pickTitles = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (array)($_POST['commons_titles'] ?? [])
    : (isset($_GET['photos']) ? explode('|', (string)$_GET['photos']) : []);
if ($pickTitles) [$pickedCommons, $pickedCommonsError] = commonsInfoForTitles($pickTitles, 360);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attractions — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="../assets/js/map-pins.js"></script>
    <style>
        
        .dash-wrap   { display:grid;
                       grid-template-columns:200px 1fr; flex:1 }
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .sidebar-role{ font-size:10px; font-weight:700;
                       letter-spacing:.08em; color:#b42318;
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
                               border-left:3px solid #126b4e }
        .main        { padding:28px }
        .page-layout { display:grid;
                       grid-template-columns:1fr 340px;
                       gap:24px; align-items:start }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#126b4e;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:4px }
        .page-sub    { font-size:13px; color:var(--text-secondary);
                       margin-bottom:20px }
        .form-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:24px;
                       position:sticky; top:80px }
        .form-card h3{ font-size:14px; font-weight:500;
                       margin-bottom:18px;
                       padding-bottom:12px;
                       border-bottom:1px solid var(--border) }
        .form-group  { margin-bottom:14px }
        .form-group label { font-size:11px; font-weight:600;
                            letter-spacing:.04em; text-transform:uppercase;
                            display:block; margin-bottom:5px;
                            color:var(--text-secondary) }
        .form-group input,
        .form-group select,
        .form-group textarea { width:100%; padding:9px 12px;
                               border:1px solid var(--border-strong);
                               border-radius:6px; font-size:13px;
                               color:var(--text-primary);
                               background:var(--surface-2);
                               font-family:inherit }
        .form-group textarea { resize:vertical; min-height:80px }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { outline:none;
                                     border-color:#126b4e }
        .form-row    { display:grid;
                       grid-template-columns:1fr 1fr; gap:10px }
        #map         { height:200px; border-radius:6px;
                       border:1px solid var(--border-strong);
                       margin-top:6px }
        .map-hint    { font-size:11px; color:var(--text-secondary);
                       margin-top:4px }
        .existing-photo { width:100%; height:80px; object-fit:cover;
                          border-radius:6px; margin-bottom:8px;
                          border:1px solid var(--border) }
        .btn-save    { width:100%; padding:10px; background:#085041;
                       color:#fff; border:none; border-radius:6px;
                       font-size:13px; font-weight:500; cursor:pointer }
        .btn-save:hover { background:#0a6350 }
        .btn-cancel  { width:100%; padding:9px; background:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border-strong);
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
                       border:1px solid var(--border);
                       border-radius:8px; display:grid;
                       grid-template-columns:64px 1fr auto;
                       gap:12px; align-items:center;
                       padding:12px; margin-bottom:8px;
                       transition:border-color .15s }
        .att-row:hover { border-color:var(--border-strong) }
        .att-thumb   { width:64px; height:52px; border-radius:4px;
                       background:#EEEDFE; color:#4f5f57; overflow:hidden;
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
                       border:1px solid var(--border-strong);
                       background:none; text-decoration:none;
                       display:inline-block }
        .btn-edit:hover { border-color:#126b4e; color:#126b4e }
        .btn-del     { padding:5px 12px; font-size:11px;
                       border-radius:3px; cursor:pointer;
                       color:#b42318; border:1px solid #FECACA;
                       background:none }
        .btn-del:hover { background:#FEF2F2 }
        .empty-cat   { font-size:13px; color:var(--text-secondary);
                       padding:12px; text-align:center;
                       border:0.5px dashed var(--border);
                       border-radius:6px }
        .count-badge { font-size:11px; background:var(--surface-1);
                       border:1px solid var(--border);
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


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Attractions</h1>
<div style="margin:12px 0 18px"><a class="btn btn-outline" href="osm_attractions.php">Find attractions on the map</a> <span style="font-size:12px;color:#666">Add new places for review before tourists see them.</span></div>
        <p class="page-sub">
            Add and manage tourist attractions.
            These appear in search results and
            on recommendation maps.
        </p>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:20px">
            <?php foreach ($errors as $e): ?>
                <p><?= h($e) ?></p>
            <?php endforeach; ?>
            <?php if (!empty($duplicateOf)): ?>
                <p><a href="?edit=<?= (int)$duplicateOf['attraction_id'] ?>"><strong>Open the existing attraction to edit it</strong></a></p>
            <?php endif; ?>
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
                            <?= h($cat) ?>
                            <span class="count-badge">
                                <?= count($grouped[$cat]) ?>
                            </span>
                        </div>
                        <?php foreach ($grouped[$cat] as $att): ?>
                        <div class="att-row">
                            <div class="att-thumb">
                                <?php if ($att['photo_path']): ?>
                                    <img src="../uploads/attractions/<?= h($att['photo_path']) ?>"
                                         alt="<?= h($att['name']) ?>">
                                <?php else: ?>
                                    <span style="font-size:12px;color:#4f5f57">No photo</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="att-name">
                                    <?= h($att['name']) ?>
                                </div>
                                <div class="att-meta">
                                    <?= h(
                                        $att['location_name']) ?>
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
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
                        function ($a) use ($categories) { return !in_array($a['category'], $categories); });
                    if (!empty($other)):
                    ?>
                    <div class="category-section">
                        <div class="category-label">Other</div>
                        <?php foreach ($other as $att): ?>
                        <div class="att-row">
                            <div class="att-thumb"><span style="font-size:12px;color:#4f5f57">No photo</span></div>
                            <div>
                                <div class="att-name">
                                    <?= h($att['name']) ?>
                                </div>
                                <div class="att-meta">
                                    <?= h(
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
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="save_attraction"
                               value="1">
                        <input type="hidden" name="attraction_id"
                               value="<?= $editing
                                   ? $editing['attraction_id']
                                   : 0 ?>">
                        <input type="hidden" name="existing_photo"
                               value="<?= h(
                                   $editing['photo_path'] ?? '') ?>">

                        <div class="form-group">
                            <label>Name</label>
                            <input aria-label="Name" type="text" name="name"
                                   value="<?= h(
                                       $editing['name']
                                       ?? ($_POST['name'] ?? ($prefill['name'] ?? ''))) ?>"
                                   placeholder="e.g. Nairobi National Park"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Category</label>
                            <select aria-label="Category" name="category" required>
                                <option value="">Select category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= h($cat) ?>"
                                    <?= (($editing['category']
                                          ?? '') === $cat
                                         || ($_POST['category']
                                             ?? '') === $cat
                                         || (!$editing && !isset($_POST['category'])
                                             && ($prefill['category'] ?? '') === $cat))
                                        ? 'selected' : '' ?>>
                                    <?= h($cat) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Location name</label>
                            <input aria-label="Location name" type="text" name="location_name"
                                   id="locInput"
                                   value="<?= h(
                                       $editing['location_name']
                                       ?? ($_POST['location_name']
                                           ?? ($prefill['location'] ?? ''))) ?>"
                                   placeholder="e.g. Langata, Nairobi"
                                   required>
                        </div>


                        <div class="form-group">
                            <label>Description</label>
                            <textarea aria-label="Description" name="description"
                                      placeholder="Brief description of the attraction..."><?= h(
                                $editing['description']
                                ?? ($_POST['description'] ?? '')) ?>
                            </textarea>
                        </div>

                        <div class="form-group">
                            <label>Tagline (one catchy line)</label>
                            <input aria-label="Tagline (one catchy line)" type="text" name="tagline" maxlength="200"
                                   placeholder="e.g. Endless savannah and the Great Migration"
                                   value="<?= h($editExtras['tagline'] ?? ($_POST['tagline'] ?? '')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Best time to visit</label>
                            <input aria-label="Best time to visit" type="text" name="best_time" maxlength="150"
                                   placeholder="e.g. July – October, or December – March and July – October"
                                   value="<?= h($editExtras['best_time'] ?? ($_POST['best_time'] ?? '')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Climate</label>
                            <input aria-label="Climate" type="text" name="climate" maxlength="150"
                                   value="<?= h($editExtras['climate'] ?? ($_POST['climate'] ?? '')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Landscape</label>
                            <input aria-label="Landscape" type="text" name="terrain" maxlength="190"
                                   value="<?= h($editExtras['terrain'] ?? ($_POST['terrain'] ?? '')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Best for</label>
                            <input aria-label="Best for" type="text" name="best_for" maxlength="190"
                                   placeholder="e.g. Wildlife, photography, families"
                                   value="<?= h($editExtras['best_for'] ?? ($_POST['best_for'] ?? '')) ?>">
                        </div>
                        <div class="form-group">
                            <label>“Did you know?” facts (one per line)</label>
                            <textarea aria-label="“Did you know?” facts (one per line)" name="did_you_know" rows="4"
                                      placeholder="One fun, true fact per line"><?= h($editExtras['did_you_know'] ?? ($_POST['did_you_know'] ?? '')) ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>“What’s new” updates (one per line)</label>
                            <textarea aria-label="“What’s new” updates (one per line)" name="whats_new" rows="3"
                                      placeholder="e.g. New viewing deck opened this month"><?= h($editExtras['whats_new'] ?? ($_POST['whats_new'] ?? '')) ?></textarea>
                        </div>

                        <!-- Photos (several allowed) -->
                        <div class="form-group">
                            <label>Photos</label>
                            <?php $existingPhotos = $editing ? getAttractionPhotoNames($pdo, (int)$editing['attraction_id'], $editing['photo_path']) : []; ?>
                            <?php if ($existingPhotos): ?>
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-bottom:10px">
                                <?php foreach ($existingPhotos as $pi => $pn): ?>
                                <label style="display:block;font-size:11px;color:var(--text-secondary);cursor:pointer">
                                    <img src="../uploads/attractions/<?= h($pn) ?>" alt="Photo <?= $pi + 1 ?>" style="width:100%;height:80px;object-fit:cover;border-radius:8px;display:block;margin-bottom:4px">
                                    <input type="checkbox" name="remove_photos[]" value="<?= h($pn) ?>"> Remove<?= $pi === 0 ? ' (cover)' : '' ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($pickedCommons): ?>
                            <div style="border:1px solid #cfe3d8;background:#f4faf6;border-radius:10px;padding:12px;margin-bottom:12px">
                                <strong style="font-size:13px">Photos from Wikimedia Commons</strong>
                                <p style="font-size:11px;color:var(--text-secondary);margin:2px 0 10px">Ticked photos are saved when you press save, with the photographer&rsquo;s credit shown on the attraction page. Untick any you do not want.</p>
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px">
                                <?php foreach ($pickedCommons as $cp): ?>
                                    <label style="display:block;font-size:11px;color:var(--text-secondary);cursor:pointer">
                                        <img src="<?= h($cp['thumb']) ?>" alt="Photo suggestion by <?= h($cp['artist']) ?>" style="width:100%;height:86px;object-fit:cover;border-radius:8px;display:block;margin-bottom:4px">
                                        <input type="checkbox" name="commons_titles[]" value="<?= h($cp['title']) ?>" checked>
                                        <?= h($cp['artist']) ?> &middot; <?= h($cp['license']) ?>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php elseif ($pickedCommonsError): ?>
                            <p style="font-size:12px;color:#8a2b22;margin:0 0 10px"><?= h($pickedCommonsError) ?></p>
                            <?php endif; ?>
                            <input aria-label="Photos" type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp">
                            <p style="font-size:11px;color:var(--text-secondary);margin:6px 0 0">
                                Hold Ctrl (or Cmd) to pick several photos at once. JPG, PNG or WebP, up to 5MB each. The first photo is the cover and visitors can flip through the rest as a slideshow.
                            </p>
                        </div>

                        <!-- Map -->
                        <div class="form-group">
                            <label>Pin location on map</label>
                            <input type="hidden" name="latitude"
                                   id="latInput"
                                   value="<?= $editing['latitude']
                                       ?? ($_POST['latitude'] ?? ($prefill['lat'] ?? '')) ?>">
                            <input type="hidden" name="longitude"
                                   id="lngInput"
                                   value="<?= $editing['longitude']
                                       ?? ($_POST['longitude'] ?? ($prefill['lng'] ?? '')) ?>">
                            <div id="map"></div>
                            <p class="map-hint">
                                Click to pin exact location.
                                <span id="coordDisplay"
                                      style="color:#126b4e">
                                    <?php if ($editing): ?>
                                        <?= number_format(
                                            $editing['latitude'], 4) ?>,
                                        <?= number_format(
                                            $editing['longitude'], 4) ?>
                                    <?php endif; ?>
                                    <?php if (!$editing && $prefill): ?>
                                        <?= number_format($prefill['lat'], 4) ?>, <?= number_format($prefill['lng'], 4) ?>
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
    <?php appShellEnd(); ?>

<script>
// Init map
const initLat = <?= $editing['latitude']
    ?? ($_POST['latitude'] ?? ($prefill['lat'] ?? -1.2921)) ?>;
const initLng = <?= $editing['longitude']
    ?? ($_POST['longitude'] ?? ($prefill['lng'] ?? 36.8219)) ?>;
const initZoom = <?= ($editing || !empty($_POST['latitude']) || $prefill)
    ? 13 : 7 ?>;

const map = L.map('map').setView([initLat, initLng], initZoom);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let marker = null;

<?php if ($editing): ?>
marker = L.marker(
    [<?= $editing['latitude'] ?>, <?= $editing['longitude'] ?>],
    { icon: SEPins.attraction() }
).addTo(map);
<?php elseif ($prefill): ?>
marker = L.marker(
    [<?= $prefill['lat'] ?>, <?= $prefill['lng'] ?>],
    { icon: SEPins.attraction() }
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
    marker = L.marker([lat, lng], { icon: SEPins.attraction() }).addTo(map);
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