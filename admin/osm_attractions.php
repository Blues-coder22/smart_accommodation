<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');

// Keep the import queue separate from approved attractions. The latter table is
// only populated when an administrator approves a candidate.
$pdo->exec("CREATE TABLE IF NOT EXISTS osm_attraction_imports (
    import_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    osm_type VARCHAR(12) NOT NULL,
    osm_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'Attraction',
    location_name VARCHAR(150) DEFAULT NULL,
    latitude DECIMAL(10,8) NOT NULL,
    longitude DECIMAL(11,8) NOT NULL,
    tags_json LONGTEXT DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    attraction_id INT DEFAULT NULL,
    imported_by INT DEFAULT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_osm_source (osm_type, osm_id),
    KEY idx_osm_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$errors = [];
$notice = '';
function osmCategoryFromTags(array $tags): string {
    // Names match the category list in Manage Attractions (admin/attractions.php) so the edit form can select them.
    $t = fn($k) => (string)($tags[$k] ?? '');
    if ($t('tourism') === 'museum') return 'Museums';
    if ($t('natural') === 'beach') return 'Beaches';
    if (in_array($t('tourism'), ['zoo','aquarium'], true)) return 'Wildlife';
    if (in_array($t('amenity'), ['restaurant','cafe','fast_food','food_court'], true)) return 'Restaurants';
    if ($t('amenity') === 'place_of_worship') return 'Religious Sites';
    if ($t('shop') !== '' || $t('amenity') === 'marketplace') return 'Shopping';
    if (in_array($t('amenity'), ['cinema','nightclub'], true) || $t('tourism') === 'theme_park' || $t('leisure') === 'water_park') return 'Entertainment';
    if (in_array($t('tourism'), ['camp_site','caravan_site'], true)) return 'Adventure';
    if ($t('boundary') === 'national_park') return 'National Parks';
    if ($t('historic') !== '' || $t('man_made') === 'lighthouse') return 'Historical Sites';
    if (in_array($t('tourism'), ['gallery','artwork'], true) || in_array($t('amenity'), ['theatre','arts_centre','planetarium'], true)) return 'Culture';
    if (in_array($t('leisure'), ['park','nature_reserve','garden'], true) || $t('tourism') === 'viewpoint'
        || in_array($t('natural'), ['waterfall','cave_entrance','peak','hot_spring','volcano','spring'], true)) return 'Nature';
    return 'Attraction';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'import_selected') {
        $raw = json_decode($_POST['selected_places'] ?? '[]', true);
        if (!is_array($raw) || !$raw) {
            $errors[] = 'Select at least one named place from the discovery results.';
        } else {
            $insert = $pdo->prepare("INSERT IGNORE INTO osm_attraction_imports
                (osm_type, osm_id, name, category, location_name, latitude, longitude, tags_json, imported_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $added = 0; $skippedExisting = 0;
            foreach (array_slice($raw, 0, 100) as $place) {
                if (!is_array($place)) continue;
                $osmType = (string)($place['type'] ?? '');
                $osmId = filter_var($place['id'] ?? null, FILTER_VALIDATE_INT);
                $name = trim(strip_tags((string)($place['name'] ?? '')));
                $lat = filter_var($place['lat'] ?? null, FILTER_VALIDATE_FLOAT);
                $lng = filter_var($place['lng'] ?? null, FILTER_VALIDATE_FLOAT);
                $tags = is_array($place['tags'] ?? null) ? $place['tags'] : [];
                if (!in_array($osmType, ['node','way','relation'], true) || !$osmId || $name === '' || $lat === false || $lng === false || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
                $already = $pdo->prepare("SELECT 1 FROM attractions WHERE LOWER(name)=LOWER(?) AND ABS(latitude-?) < 0.002 AND ABS(longitude-?) < 0.002 LIMIT 1");
                $already->execute([$name, $lat, $lng]);
                if ($already->fetchColumn()) { $skippedExisting++; continue; }
                $location = trim((string)($tags['addr:city'] ?? $tags['addr:town'] ?? $tags['addr:village'] ?? $tags['addr:suburb'] ?? $tags['addr:county'] ?? $tags['addr:state'] ?? ''));
                if ($location === '') $location = trim((string)($tags['is_in'] ?? ''));
                if ($location === '') $location = 'Needs location verification';
                $insert->execute([$osmType, $osmId, substr($name, 0, 150), osmCategoryFromTags($tags), substr($location, 0, 150), $lat, $lng, json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), (int)$_SESSION['user_id']]);
                $added += $insert->rowCount();
            }
            $notice = $added . ' new place(s) imported to the pending review queue. Existing OSM IDs were skipped.'
                . ($skippedExisting ? ' ' . $skippedExisting . ' place(s) were already in your attractions and were left out.' : '');
        }
    } elseif ($action === 'approve' || $action === 'reject') {
        $id = (int)($_POST['import_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM osm_attraction_imports WHERE import_id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$candidate) {
            $errors[] = 'That pending import was not found. Refresh the page and try again.';
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE osm_attraction_imports SET status='rejected', reviewed_at=NOW() WHERE import_id=?")->execute([$id]);
            $notice = 'Place rejected. It will not appear in tourist recommendations.';
        } else {
            // Avoid duplicate records if a similar attraction was already entered manually.
            $dupe = $pdo->prepare("SELECT attraction_id FROM attractions WHERE LOWER(name)=LOWER(?) AND ABS(latitude-?) < 0.001 AND ABS(longitude-?) < 0.001 LIMIT 1");
            $dupe->execute([$candidate['name'], $candidate['latitude'], $candidate['longitude']]);
            $existingId = $dupe->fetchColumn();
            if ($existingId) {
                $pdo->prepare("UPDATE osm_attraction_imports SET status='approved', attraction_id=?, reviewed_at=NOW() WHERE import_id=?")->execute([(int)$existingId, $id]);
                $notice = 'This place already exists in the attraction catalogue. The import was linked to the existing record.';
            } else {
                $description = 'Imported from OpenStreetMap. Please review and complete this description before public promotion.';
                // Only use an OSM image tag when it looks like a direct HTTPS image URL.
                // Most OSM records do not have a usable photo, so admins can upload one later.
                $photoPath = null;
                $tags = json_decode($candidate['tags_json'] ?? '', true) ?: [];
                $tagImage = trim((string)($tags['image'] ?? ''));
                if ($tagImage !== '' && filter_var($tagImage, FILTER_VALIDATE_URL) &&
                    stripos($tagImage, 'https://') === 0 &&
                    preg_match('~\.(jpe?g|png|webp)(?:[?#].*)?$~i', $tagImage)) {
                    $photoPath = $tagImage;
                }
                $insert = $pdo->prepare("INSERT INTO attractions (name, description, category, location_name, latitude, longitude, entry_fee, photo_path) VALUES (?, ?, ?, ?, ?, ?, 0, ?)");
                $insert->execute([$candidate['name'], $description, $candidate['category'], $candidate['location_name'], $candidate['latitude'], $candidate['longitude'], $photoPath]);
                $attractionId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE osm_attraction_imports SET status='approved', attraction_id=?, reviewed_at=NOW() WHERE import_id=?")->execute([$attractionId, $id]);
                $notice = 'Attraction approved and added to the catalogue. You can edit its description and other details in Manage Attractions.';
            }
        }
    }
}

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending','approved','rejected','all'], true)) $statusFilter = 'pending';
$sql = "SELECT * FROM osm_attraction_imports";
$params = [];
if ($statusFilter !== 'all') { $sql .= " WHERE status = ?"; $params[] = $statusFilter; }
$sql .= " ORDER BY imported_at DESC LIMIT 250";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $imports = $stmt->fetchAll(PDO::FETCH_ASSOC);
$existingAttractions = $pdo->query("SELECT attraction_id, name, latitude, longitude FROM attractions WHERE latitude IS NOT NULL AND longitude IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Discover Attractions — Smart Accommodation Kenya</title>
<link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"><script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
.osm-page{max-width:1180px;margin:0 auto;padding:28px 20px}.osm-card{background:#fff;border:1px solid #e3e7e5;border-radius:12px;padding:20px;margin-bottom:20px}.osm-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.osm-grid label{display:block;font-size:13px;margin-bottom:5px}.osm-grid input,.osm-grid select{width:100%;padding:10px;border:1px solid #ccd4d0;border-radius:7px}.osm-map{height:320px;border-radius:10px;margin:16px 0}.osm-results{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}.osm-result{border:1px solid #e0e5e2;border-radius:9px;padding:12px}.osm-result label{display:flex;gap:8px;align-items:flex-start}.osm-muted{color:#66736d;font-size:13px}.osm-pill{display:inline-block;border-radius:20px;padding:3px 9px;background:#fff1df;color:#96520c;font-size:11px}.osm-table{width:100%;border-collapse:collapse}.osm-table th,.osm-table td{text-align:left;padding:10px;border-bottom:1px solid #e5e9e7;vertical-align:top}.osm-actions{display:flex;gap:8px;flex-wrap:wrap}.osm-actions button{cursor:pointer}.osm-source{font-size:12px;word-break:break-all;color:#68736e}.osm-map{height:440px}.osm-buttons{display:flex;gap:10px;flex-wrap:wrap}.osm-help{margin:4px 0 14px;font-size:13px;background:#f4f8f6;border:1px solid #dfe9e4;border-radius:9px;padding:10px 14px}.osm-help summary{cursor:pointer;font-weight:700;color:#0d3b2e}.osm-help ol{margin:10px 0 2px 18px;padding:0;line-height:1.55}.osm-help li{margin-bottom:6px}.osm-have{display:inline-block;font-size:11px;font-weight:700;color:#1b6b4a;background:#e3f4ea;border-radius:10px;padding:2px 8px;margin-top:4px}.osm-have-card{background:#f6fbf8}.osm-popup{font-size:13px}.leaflet-container .osm-popup a.btn,.osm-result a.btn{color:#fff;text-decoration:none}.leaflet-container .osm-popup a.btn-primary:hover{color:#fff}.osm-popup-actions,.osm-card-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.osm-popup-actions .btn,.osm-card-actions .btn{min-height:30px;padding:5px 10px;font-size:11px}.osm-result{display:flex;flex-direction:column;justify-content:space-between}.osm-result label{cursor:pointer}@media(max-width:680px){.osm-grid{grid-template-columns:1fr}.osm-table{font-size:12px}.osm-table th,.osm-table td{padding:7px}}
</style></head><body><?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
<div class="osm-page"><h1>Find attractions on the map</h1><p class="osm-muted">Search an area for places, add the ones you like to the review list, and approve them before tourists can see them.</p>
<?php foreach ($errors as $error): ?><div class="alert alert-error"><?= h($error, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= h($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="osm-card"><h2>Search an area</h2>
<p class="osm-muted">Pick what you are looking for, then search an area. Click anywhere on the map to drop the pin, or click one of the orange dots to see the place and add it as an attraction.</p>
<div class="osm-grid">
 <div style="grid-column:1/-1"><label for="osmArea">Area name</label><input id="osmArea" type="text" maxlength="180" value="Nairobi, Kenya" placeholder="e.g. Naivasha, Nakuru County, Kenya" autocomplete="off"></div>
 <div><label for="osmType">Type of place</label><select id="osmType"></select></div>
 <div><label for="osmRadius">Search distance</label><select id="osmRadius"><option value="1000">1 km</option><option value="3000" selected>3 km</option><option value="5000">5 km</option><option value="10000">10 km</option><option value="20000">20 km</option></select></div>
 <div class="osm-buttons" style="grid-column:1/-1"><button class="btn btn-primary" id="osmSearch" type="button">Find places in this area</button><button class="btn btn-outline" id="osmAddAtPin" type="button">+ Add attraction at the pin</button></div>
</div>
<details style="margin-top:12px"><summary style="cursor:pointer;font-size:13px">Enter the exact location instead</summary><div class="osm-grid" style="margin-top:12px"><div><label for="osmLat">Latitude</label><input id="osmLat" type="number" step="any" value="-1.2921" min="-90" max="90"></div><div><label for="osmLng">Longitude</label><input id="osmLng" type="number" step="any" value="36.8219" min="-180" max="180"></div></div><p class="osm-muted">You can also click the map below.</p></details>
<div id="osmAdminMap" class="osm-map" aria-label="Map: click to place the pin, click an orange dot to add that place"></div>
<p id="osmSearchStatus" class="osm-muted" role="status" aria-live="polite">Ready to search. Details may be incomplete, so please check each place before approving.</p>
<details class="osm-help"><summary>How does this map work?</summary>
<ol>
<li><strong>The map picture</strong> (the streets and labels) comes from OpenStreetMap, a free map of the world edited by volunteers. The page shows it with a small library called Leaflet.</li>
<li><strong>Area name → pin.</strong> When you press <em>Find places</em>, the name you typed (for example “Naivasha”) is sent to OpenStreetMap’s search service (Nominatim), which answers with a latitude and longitude. If you already placed the pin yourself by clicking the map, your pin is used instead.</li>
<li><strong>Places around the pin.</strong> The type you chose (restaurants, museums, parks…) is turned into a question for the Overpass service: “list every <em>named</em> place of this type within the distance I picked”. Each answer becomes an orange dot.</li>
<li><strong>Adding.</strong> <em>Add attraction</em> opens the Manage Attractions form with the name, category and exact position filled in, so you only add a description, fee and photos. <em>Add to review list</em> instead keeps the place in the queue below until you approve it.</li>
<li>Nothing is shown to tourists until it is saved as an attraction. OpenStreetMap is edited by the public, so names and positions can be wrong or missing. Please check each place.</li>
</ol></details>
<form method="post" id="osmImportForm"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="import_selected"><input type="hidden" name="selected_places" id="osmSelectedPlaces" value="[]"><div id="osmResults" class="osm-results"></div><div style="margin-top:14px"><button type="submit" class="btn btn-primary" id="osmImportButton" disabled>Add selected to review list</button></div></form>
</section>
<section class="osm-card"><div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2>Imported places review queue</h2><form method="get"><label for="statusFilter">Status </label><select name="status" id="statusFilter" onchange="this.form.submit()"><option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option><option value="approved" <?= $statusFilter==='approved'?'selected':'' ?>>Approved</option><option value="rejected" <?= $statusFilter==='rejected'?'selected':'' ?>>Rejected</option><option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option></select></form></div>
<?php if (!$imports): ?><p class="osm-muted">No imports found for this status.</p><?php else: ?><div style="overflow-x:auto"><table class="osm-table"><thead><tr><th>Place</th><th>Location / coordinates</th><th>Source details</th><th>Status</th><th>Review action</th></tr></thead><tbody>
<?php foreach ($imports as $item): $tags = json_decode($item['tags_json'] ?? '', true) ?: []; ?><tr><td><strong><?= h($item['name'], ENT_QUOTES, 'UTF-8') ?></strong><br><span class="osm-muted"><?= h($item['category'], ENT_QUOTES, 'UTF-8') ?></span></td><td><?= h($item['location_name'] ?? '', ENT_QUOTES, 'UTF-8') ?><br><span class="osm-muted"><?= h($item['latitude'] . ', ' . $item['longitude'], ENT_QUOTES, 'UTF-8') ?></span></td><td><span class="osm-source">OSM <?= h($item['osm_type'] . '/' . $item['osm_id'], ENT_QUOTES, 'UTF-8') ?></span><br><span class="osm-muted"><?= h(implode(', ', array_slice(array_map(function ($k, $v) { return $k . '=' . $v; }, array_keys($tags), array_values($tags)),0,8)), ENT_QUOTES, 'UTF-8') ?></span></td><td><span class="osm-pill"><?= h($item['status'], ENT_QUOTES, 'UTF-8') ?></span></td><td><?php if ($item['status']==='pending'): ?><div class="osm-actions"><form method="post"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="import_id" value="<?= (int)$item['import_id'] ?>"><button class="btn btn-primary" type="submit" onclick="return confirm('Approve this place and add it to the attraction catalogue?')">Approve</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="import_id" value="<?= (int)$item['import_id'] ?>"><button class="btn btn-outline" type="submit" onclick="return confirm('Reject this place?')">Reject</button></form></div><?php elseif ($item['attraction_id']): ?><a href="attractions.php?edit=<?= (int)$item['attraction_id'] ?>">Edit attraction record</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<p class="osm-muted" style="margin-top:12px">Map data © OpenStreetMap contributors. Please use the search button only when you need it.</p>
</section></div><?php appShellEnd(); ?>
<style>
.cm-back{position:fixed;inset:0;background:rgba(20,30,25,.55);z-index:3000;display:flex;align-items:center;justify-content:center;padding:16px}
.cm-back[hidden]{display:none}
.cm-box{background:#fff;border-radius:14px;max-width:880px;width:100%;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.cm-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid #e3e7e5}
.cm-head button{border:0;background:none;font-size:26px;line-height:1;cursor:pointer;color:#555}
.cm-body{padding:14px 20px;overflow:auto;flex:1}
.cm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}
.cm-item{border:2px solid #e3e7e5;border-radius:10px;overflow:hidden;cursor:pointer;display:block;font-size:11px;color:#55645c;background:#fff}
.cm-item.on{border-color:#1b6b4a;background:#f4faf6}
.cm-item img{width:100%;height:120px;object-fit:cover;display:block;background:#eef1ef}
.cm-item .cm-meta{padding:7px 9px;line-height:1.4}
.cm-item input{margin-right:5px}
.cm-sub{grid-column:1/-1;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#68776f;margin:8px 0 0}
.cm-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 20px;border-top:1px solid #e3e7e5;flex-wrap:wrap}
</style>
<div id="cmModal" class="cm-back" hidden role="dialog" aria-modal="true" aria-labelledby="cmTitle">
 <div class="cm-box">
  <div class="cm-head"><strong id="cmTitle">Find photos</strong><button type="button" id="cmClose" aria-label="Close">&times;</button></div>
  <div class="cm-body">
   <p class="osm-muted" id="cmStatus" role="status" aria-live="polite"></p>
   <div id="cmGrid" class="cm-grid"></div>
   <p class="osm-muted" style="margin-top:12px">Photos come from Wikimedia Commons and are free to use with credit. The photographer&rsquo;s name and licence are saved with each photo and shown on the attraction page. Tick the ones that really show this place (up to 8).</p>
  </div>
  <div class="cm-foot"><span id="cmCount" class="osm-muted"></span><span id="cmActions" style="display:flex;gap:8px;align-items:center"><button type="button" class="btn btn-primary" id="cmGo"></button></span></div>
 </div>
</div>
<script>
(function(){
 // ---- What can be searched. Each type is a list of OpenStreetMap tag filters. ----
 const ALL_FILTERS=['["tourism"~"^(attraction|museum|viewpoint|theme_park|zoo|gallery|artwork|aquarium|camp_site|caravan_site|information)$"]','["historic"]','["leisure"~"^(park|nature_reserve|garden|water_park)$"]','["natural"~"^(beach|waterfall|peak|cave_entrance|hot_spring|volcano|spring)$"]','["man_made"="lighthouse"]','["amenity"~"^(theatre|arts_centre|planetarium|place_of_worship)$"]'];
 const TYPES=[
  {key:'all',label:'All attraction types',filters:ALL_FILTERS},
  {key:'restaurants',label:'Restaurants & cafés',filters:['["amenity"~"^(restaurant|cafe|fast_food|food_court)$"]']},
  {key:'museums',label:'Museums',filters:['["tourism"="museum"]']},
  {key:'galleries',label:'Art galleries & theatres',filters:['["tourism"~"^(gallery|artwork)$"]','["amenity"~"^(arts_centre|theatre|planetarium)$"]']},
  {key:'parks',label:'Parks & nature reserves',filters:['["leisure"~"^(park|nature_reserve|garden)$"]','["boundary"="national_park"]']},
  {key:'beaches',label:'Beaches',filters:['["natural"="beach"]']},
  {key:'historic',label:'Historical sites & monuments',filters:['["historic"]','["man_made"="lighthouse"]']},
  {key:'viewpoints',label:'Viewpoints',filters:['["tourism"="viewpoint"]']},
  {key:'nature',label:'Waterfalls, peaks & caves',filters:['["natural"~"^(waterfall|peak|cave_entrance|hot_spring|volcano|spring)$"]']},
  {key:'wildlife',label:'Zoos & wildlife',filters:['["tourism"~"^(zoo|aquarium)$"]']},
  {key:'entertainment',label:'Entertainment & theme parks',filters:['["tourism"="theme_park"]','["leisure"="water_park"]','["amenity"~"^(cinema|nightclub)$"]']},
  {key:'camping',label:'Camp sites',filters:['["tourism"~"^(camp_site|caravan_site)$"]']},
  {key:'worship',label:'Places of worship',filters:['["amenity"="place_of_worship"]']},
  {key:'shopping',label:'Shopping & markets',filters:['["shop"~"^(mall|department_store|craft|gift|art)$"]','["amenity"="marketplace"]']}
 ];
 const typeSelect=document.getElementById('osmType');
 TYPES.forEach(t=>{const o=document.createElement('option');o.value=t.key;o.textContent=t.label;typeSelect.appendChild(o);});

 // ---- Map ----
 const map=L.map('osmAdminMap').setView([-1.2921,36.8219],12);
 L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap contributors'}).addTo(map);
 const centerMarker=L.marker([-1.2921,36.8219],{draggable:true,title:'Search pin: drag me or click the map'}).addTo(map);
 const candidateLayer=L.layerGroup().addTo(map);
 const $=id=>document.getElementById(id);
 const statusEl=$('osmSearchStatus');
 const EXISTING=<?= json_encode(array_map(function($a){return ['id'=>(int)$a['attraction_id'],'name'=>html_entity_decode((string)$a['name'],ENT_QUOTES,'UTF-8'),'lat'=>(float)$a['latitude'],'lng'=>(float)$a['longitude']];},$existingAttractions), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?>;
 let results=[];
 let manualCenter=false;   // true once the admin placed the pin by hand
 let busy=false;

 function esc(s){const d=document.createElement('div');d.textContent=String(s??'');return d.innerHTML;}
 // Category names match the list in Manage Attractions so the add form can pre-select them.
 function category(tags){
  if(tags.tourism==='museum')return 'Museums';
  if(tags.natural==='beach')return 'Beaches';
  if(tags.tourism==='zoo'||tags.tourism==='aquarium')return 'Wildlife';
  if(['restaurant','cafe','fast_food','food_court'].includes(tags.amenity))return 'Restaurants';
  if(tags.amenity==='place_of_worship')return 'Religious Sites';
  if(tags.shop||tags.amenity==='marketplace')return 'Shopping';
  if(['cinema','nightclub'].includes(tags.amenity)||['theme_park'].includes(tags.tourism)||tags.leisure==='water_park')return 'Entertainment';
  if(['camp_site','caravan_site'].includes(tags.tourism))return 'Adventure';
  if(tags.boundary==='national_park')return 'National Parks';
  if(tags.historic||tags.man_made==='lighthouse')return 'Historical Sites';
  if(tags.tourism==='gallery'||tags.tourism==='artwork'||['theatre','arts_centre','planetarium'].includes(tags.amenity))return 'Culture';
  if(['park','nature_reserve','garden'].includes(tags.leisure)||tags.tourism==='viewpoint'||['waterfall','cave_entrance','peak','hot_spring','volcano','spring'].includes(tags.natural))return 'Nature';
  return 'Attraction';
 }
 function placeArea(tags){return String(tags['addr:suburb']||tags['addr:city']||tags['addr:town']||tags['addr:village']||tags['addr:county']||tags['addr:state']||'').trim();}
 function addUrl(name,cat,area,lat,lng){
  const q=new URLSearchParams({add:'1',name:name||'',category:cat||'',location:area||'',lat:Number(lat).toFixed(7),lng:Number(lng).toFixed(7)});
  return 'attractions.php?'+q.toString();
 }
 // A search result that is already one of your attractions (same name, within 250 m).
 function existingFor(p){const n=String(p.name).trim().toLowerCase();return EXISTING.find(e=>e.name.trim().toLowerCase()===n&&distance(p.lat,p.lng,e.lat,e.lng)<250)||null;}
 function distance(a,b,c,d){const R=6371000,t=x=>x*Math.PI/180,dl=t(c-a),dg=t(d-b);const h=Math.sin(dl/2)**2+Math.cos(t(a))*Math.cos(t(c))*Math.sin(dg/2)**2;return 2*R*Math.asin(Math.sqrt(h));}

 // ---- Pin handling ----
 function setCenter(lat,lng){$('osmLat').value=Number(lat).toFixed(6);$('osmLng').value=Number(lng).toFixed(6);centerMarker.setLatLng([lat,lng]);}
 function pinPoint(){return {lat:Number($('osmLat').value),lng:Number($('osmLng').value)};}
 function validPoint(p){return Number.isFinite(p.lat)&&Number.isFinite(p.lng)&&p.lat>=-90&&p.lat<=90&&p.lng>=-180&&p.lng<=180;}
 function button(text,cls,fn){const b=document.createElement('button');b.type='button';b.className=cls;b.textContent=text;b.addEventListener('click',fn);return b;}
 function pinPopup(){
  const p=pinPoint();const box=document.createElement('div');box.className='osm-popup';
  const t=document.createElement('strong');t.textContent='Pin location';box.appendChild(t);
  const c=document.createElement('div');c.className='osm-muted';c.textContent=p.lat.toFixed(5)+', '+p.lng.toFixed(5);box.appendChild(c);
  const row=document.createElement('div');row.className='osm-popup-actions';
  row.appendChild(button('Search around here','btn btn-sm btn-outline',()=>{map.closePopup();runSearch(false);}));
  row.appendChild(button('Add attraction here','btn btn-sm btn-primary',()=>addAtPin()));
  box.appendChild(row);return box;
 }
 function openPinPopup(){centerMarker.bindPopup(pinPopup(),{minWidth:200}).openPopup();}
 map.on('click',e=>{manualCenter=true;setCenter(e.latlng.lat,e.latlng.lng);openPinPopup();});
 centerMarker.on('dragend',()=>{manualCenter=true;const p=centerMarker.getLatLng();setCenter(p.lat,p.lng);openPinPopup();});
 $('osmLat').addEventListener('input',()=>manualCenter=true);$('osmLng').addEventListener('input',()=>manualCenter=true);
 $('osmArea').addEventListener('input',()=>{manualCenter=false;});   // a newly typed area name wins over an old pin

 // Add an attraction at the pin: look up the neighbourhood name first (best effort, 4 seconds at most).
 async function addAtPin(){
  const p=pinPoint();if(!validPoint(p)){alert('Click the map to place the pin first.');return;}
  statusEl.textContent='Opening the add form for this spot…';let area='';
  try{
   const ctl=new AbortController();const timer=setTimeout(()=>ctl.abort(),4000);
   const r=await fetch('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=14&lat='+p.lat+'&lon='+p.lng,{signal:ctl.signal});clearTimeout(timer);
   if(r.ok){const j=await r.json();const a=j.address||{};area=a.suburb||a.city_district||a.neighbourhood||a.town||a.city||a.village||a.county||'';if(a.county&&area&&area!==a.county&&!/county/i.test(area)&&a.city&&a.city!==area)area=area+', '+a.city;}
  }catch(e){}
  window.location.href=addUrl('','',area,p.lat,p.lng);
 }
 $('osmAddAtPin').addEventListener('click',addAtPin);

 // ---- Results ----
 function resultPopup(p){
  const box=document.createElement('div');box.className='osm-popup';
  const t=document.createElement('strong');t.textContent=p.name;box.appendChild(t);
  const c=document.createElement('div');c.className='osm-muted';c.textContent=category(p.tags);box.appendChild(c);
  const row=document.createElement('div');row.className='osm-popup-actions';
  if(p.existing){
   const note=document.createElement('div');note.className='osm-have';note.textContent='✓ Already in your attractions';box.appendChild(note);
   const edit=document.createElement('a');edit.className='btn btn-sm btn-primary';edit.textContent='Edit attraction';edit.href='attractions.php?edit='+p.existing.id;row.appendChild(edit);row.appendChild(button('Find photos','btn btn-sm btn-outline',()=>openPhotoFinder(p)));box.appendChild(row);return box;
  }
  const add=document.createElement('a');add.className='btn btn-sm btn-primary';add.textContent='Add attraction';add.href=addUrl(p.name,category(p.tags),placeArea(p.tags),p.lat,p.lng);row.appendChild(add);
  row.appendChild(button('Find photos','btn btn-sm btn-outline',()=>openPhotoFinder(p)));
  row.appendChild(button('Add to review list','btn btn-sm btn-outline',()=>{const cb=document.querySelector('#osmResults input[data-index="'+results.indexOf(p)+'"]');if(cb){cb.checked=true;syncSelected();cb.closest('.osm-result').scrollIntoView({block:'nearest',behavior:'smooth'});}map.closePopup();}));
  box.appendChild(row);return box;
 }
 function render(){
  const host=$('osmResults');host.replaceChildren();
  results.forEach((p,i)=>{
   const card=document.createElement('div');card.className='osm-result';
   const label=document.createElement('label');const cb=document.createElement('input');cb.type='checkbox';cb.dataset.index=String(i);cb.addEventListener('change',syncSelected);
   const text=document.createElement('span');text.innerHTML='<strong>'+esc(p.name)+'</strong><br><span class="osm-muted">'+esc(category(p.tags))+' · '+(p.dist>=1000?(p.dist/1000).toFixed(1)+' km':Math.round(p.dist)+' m')+' away</span>';
   label.append(cb,text);card.appendChild(label);
   if(p.existing){cb.disabled=true;card.classList.add('osm-have-card');const tag=document.createElement('span');tag.className='osm-have';tag.textContent='✓ Already in your attractions';text.appendChild(document.createElement('br'));text.appendChild(tag);}
   const row=document.createElement('div');row.className='osm-card-actions';
   row.appendChild(button('Show on map','btn btn-sm btn-outline',()=>{map.setView([p.lat,p.lng],Math.max(map.getZoom(),16));p.marker&&p.marker.openPopup();window.scrollTo({top:$('osmAdminMap').getBoundingClientRect().top+window.scrollY-90,behavior:'smooth'});}));
   const add=document.createElement('a');add.className='btn btn-sm btn-primary';
   if(p.existing){add.textContent='Edit attraction';add.href='attractions.php?edit='+p.existing.id;}
   else{add.textContent='Add attraction';add.href=addUrl(p.name,category(p.tags),placeArea(p.tags),p.lat,p.lng);}
   row.appendChild(add);
   row.appendChild(button('Find photos','btn btn-sm btn-outline',()=>openPhotoFinder(p)));
   card.appendChild(row);host.appendChild(card);
  });
  syncSelected();
 }
 function syncSelected(){
  const chosen=Array.from(document.querySelectorAll('#osmResults input:checked')).map(cb=>{const p=results[Number(cb.dataset.index)];return {type:p.type,id:p.id,name:p.name,lat:p.lat,lng:p.lng,tags:p.tags};});
  $('osmSelectedPlaces').value=JSON.stringify(chosen);$('osmImportButton').disabled=chosen.length===0;
 }

  // fetch() that gives up after a time limit instead of waiting forever
 function timedFetch(url,opts,ms){const ctl=new AbortController();const t=setTimeout(()=>ctl.abort(),ms);return fetch(url,Object.assign({},opts,{signal:ctl.signal})).finally(()=>clearTimeout(t));}

 // ---- Search ----
 async function runSearch(allowAreaLookup){
  if(busy)return;
  const area=$('osmArea').value.trim();
  const radius=Number($('osmRadius').value);
  const type=TYPES.find(t=>t.key===typeSelect.value)||TYPES[0];
  let p=pinPoint();
  const btn=$('osmSearch');
  // Look the area name up only when the admin has not placed a pin of their own.
  const useName=allowAreaLookup&&!(manualCenter&&validPoint(p));
  if(useName&&!area){alert('Enter an area name, or click the map to place the pin.');return;}
  if(!useName&&!validPoint(p)){alert('Click the map to place the pin first.');return;}
  busy=true;btn.disabled=true;
  if(useName){
   statusEl.textContent='Finding “'+area+'” on the map…';
   try{
    const geoResponse=await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=ke&q='+encodeURIComponent(area));
    if(!geoResponse.ok) throw new Error('The place search service returned an error.');
    const geo=await geoResponse.json();
    if(!geo.length) throw new Error('No matching Kenyan area was found. Try adding a county or the word Kenya, or click the map to place the pin yourself.');
    p={lat:Number(geo[0].lat),lng:Number(geo[0].lon)};
    manualCenter=false;setCenter(p.lat,p.lng);map.setView([p.lat,p.lng],Math.max(12,Number(geo[0].zoom)||12));
   }catch(e){busy=false;btn.disabled=false;statusEl.textContent=e.message||'Could not find that area. Click the map to place the pin instead.';return;}
  }else{map.setView([p.lat,p.lng],Math.max(map.getZoom(),13));}
  statusEl.textContent='Looking for '+type.label.toLowerCase()+' within '+(radius>=1000?radius/1000+' km':radius+' m')+'…';
  results=[];candidateLayer.clearLayers();render();
  const around='(around:'+radius+','+p.lat+','+p.lng+');';
  const query='[out:json][timeout:25];('+type.filters.map(f=>'nwr["name"]'+f+around).join('')+');out center tags;';
  const endpoints=['https://overpass-api.de/api/interpreter','https://overpass.private.coffee/api/interpreter'];let data=null;
  for(const endpoint of endpoints){try{const response=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},body:'data='+encodeURIComponent(query)});if(!response.ok)throw new Error('HTTP '+response.status);data=await response.json();break;}catch(e){}}
  busy=false;btn.disabled=false;
  if(!data||!Array.isArray(data.elements)){statusEl.textContent='The place search isn’t available right now. Please try again in a minute.';return;}
  const seen=new Set();
  results=data.elements.map(el=>{
   const tags=el.tags||{},pt=Number.isFinite(el.lat)&&Number.isFinite(el.lon)?{lat:el.lat,lng:el.lon}:el.center?{lat:el.center.lat,lng:el.center.lon}:null;
   if(!pt||!String(tags.name||'').trim())return null;const key=el.type+'/'+el.id;if(seen.has(key))return null;seen.add(key);
   return {type:el.type,id:el.id,name:String(tags.name).trim(),lat:pt.lat,lng:pt.lng,tags:tags,dist:distance(p.lat,p.lng,pt.lat,pt.lng)};
  }).filter(Boolean).sort((a,b)=>a.dist-b.dist).slice(0,100);
  results.forEach(r=>{
   r.existing=existingFor(r);
   r.marker=L.circleMarker([r.lat,r.lng],r.existing?{radius:9,color:'#1b6b4a',weight:2,fillColor:'#2ea66f',fillOpacity:.85}:{radius:9,color:'#b4540a',weight:2,fillColor:'#E67E22',fillOpacity:.85}).addTo(candidateLayer);
   r.marker.bindPopup(resultPopup(r),{minWidth:200});
  });
  setCenter(p.lat,p.lng);render();
  statusEl.textContent=results.length
   ? results.length+' '+type.label.toLowerCase()+' found'+(area&&useName?' near '+area:' around the pin')+'. Click an orange dot (or a card below) to add it'+(results.some(r=>r.existing)?'; green dots are already in your attractions.':'.')
   : 'No named '+type.label.toLowerCase()+' found within that distance. Try a larger distance or another type.';
 }
 $('osmSearch').addEventListener('click',()=>runSearch(true));
 $('osmImportForm').addEventListener('submit',function(e){syncSelected();if(!JSON.parse($('osmSelectedPlaces').value||'[]').length){e.preventDefault();alert('Select at least one place first.');}});
 // ---- Find photos (Wikimedia Commons) ----

 // ---- Commons search and download done by the browser itself (no PHP certificate setup needed) ----
 const CM_API='https://commons.wikimedia.org/w/api.php';
 const CM_PROPS={prop:'imageinfo',iiprop:'url|mime|size|extmetadata',iiextmetadatafilter:'Artist|Credit|LicenseShortName|LicenseUrl|ImageDescription|NonFree|Restrictions'};
 function cmText(html,max){const t=new DOMParser().parseFromString(String(html||''),'text/html').body.textContent||'';return t.replace(/\s+/g,' ').trim().slice(0,max);}
 function cmToPhoto(pg){
  const ii=pg.imageinfo&&pg.imageinfo[0];if(!ii)return null;
  if(!['image/jpeg','image/png','image/webp'].includes(ii.mime))return null;
  const m=ii.extmetadata||{},v=k=>String((m[k]&&m[k].value)||'');
  if(v('NonFree').toLowerCase()==='true'||v('Restrictions').trim()!=='')return null;
  const license=cmText(v('LicenseShortName'),80);if(!license)return null;
  let artist=cmText(v('Artist'),150)||cmText(v('Credit'),150)||'Unknown author';
  let lu=v('LicenseUrl');if(!/^https:\/\//i.test(lu))lu='';
  return {title:pg.title,thumb:ii.thumburl||ii.url||'',artist:artist,license:license,license_url:lu,page_url:ii.descriptionurl||'',description:cmText(v('ImageDescription'),140),index:pg.index||0};
 }
 async function cmQuery(params){
  const q=new URLSearchParams(Object.assign({action:'query',format:'json',formatversion:'2',origin:'*'},CM_PROPS,params));
  const r=await timedFetch(CM_API+'?'+q.toString(),{},20000);
  if(!r.ok)throw new Error('HTTP '+r.status);
  return r.json();
 }
 async function commonsSearchBrowser(p){
  const out=new Map(),name=String(p.name||'').trim();
  const jobs=[];
  if(name){jobs.push(cmQuery({iiurlwidth:'360',generator:'search',gsrsearch:name+(/kenya/i.test(name)?'':' Kenya')+' filetype:bitmap',gsrnamespace:'6',gsrlimit:'18'}).then(d=>['name',d]));}
  if(Number.isFinite(p.lat)&&Number.isFinite(p.lng)){jobs.push(cmQuery({iiurlwidth:'360',generator:'geosearch',ggscoord:p.lat+'|'+p.lng,ggsradius:'1000',ggsnamespace:'6',ggslimit:'18'}).then(d=>['nearby',d]));}
  const settled=await Promise.allSettled(jobs);
  if(settled.length&&settled.every(x=>x.status==='rejected'))throw new Error('unreachable');
  // name matches first, then nearby ones
  settled.filter(x=>x.status==='fulfilled').map(x=>x.value).sort((a,b)=>a[0]==='name'?-1:1).forEach(([src,d])=>{
   ((d.query&&d.query.pages)||[]).slice().sort((a,b)=>(a.index||0)-(b.index||0)).forEach(pg=>{const ph=cmToPhoto(pg);if(ph&&!out.has(ph.title)){ph.source=src;out.set(ph.title,ph);}});
  });
  return Array.from(out.values());
 }
 // The browser downloads the chosen photos (larger size) and uploads them to this site with their credit.
 async function savePhotosFromBrowser(p,titles){
  const d=await cmQuery({iiurlwidth:'1280',titles:titles.join('|')});
  const infos=((d.query&&d.query.pages)||[]).map(cmToPhoto).filter(Boolean);
  if(!infos.length)throw new Error('Those photos could not be read from Commons.');
  const fd=new FormData(),meta=[];let skipped=0;
  for(const ph of infos){
   try{
    const r=await timedFetch(ph.thumb,{},60000);if(!r.ok)throw new Error('x');
    const blob=await r.blob();if(blob.size>5*1024*1024){skipped++;continue;}
    const ext=blob.type==='image/png'?'png':blob.type==='image/webp'?'webp':'jpg';
    fd.append('files[]',blob,'cm.'+ext);meta.push({credit:ph.artist,license:ph.license,license_url:ph.license_url,source_url:ph.page_url});
   }catch(e){skipped++;}
  }
  if(!meta.length)throw new Error('The photos could not be downloaded to this browser.');
  fd.append('csrf_token',CSRF);fd.append('action','import_files');fd.append('attraction_id',p.existing.id);fd.append('meta',JSON.stringify(meta));
  const r=await timedFetch('commons_photos.php',{method:'POST',body:fd},90000);
  const res=await r.json();
  if(skipped)(res.errors=res.errors||[]).push(skipped+' photo(s) could not be downloaded and were skipped.');
  return res;
 }
 const CSRF=<?= json_encode(generateCsrfToken()) ?>;
 const cm={p:null,photos:[]};
 const cmEl={modal:$('cmModal'),title:$('cmTitle'),status:$('cmStatus'),grid:$('cmGrid'),count:$('cmCount'),go:$('cmGo'),actions:$('cmActions')};
 function cmTicked(){return Array.from(cmEl.grid.querySelectorAll('input:checked')).map(i=>i.value);}
 function cmSync(){
  const n=cmTicked().length,p=cm.p;
  cmEl.grid.querySelectorAll('.cm-item').forEach(l=>l.classList.toggle('on',l.querySelector('input').checked));
  cmEl.count.textContent=n+' selected';
  if(p.existing){cmEl.go.textContent='Save '+n+' photo'+(n===1?'':'s')+' to this attraction';cmEl.go.disabled=n===0;}
  else if(cm.serverOk===false){cmEl.go.textContent='Add attraction (then add photos from the map)';cmEl.go.disabled=false;}
  else{cmEl.go.textContent=n?'Add attraction with '+n+' photo'+(n===1?'':'s'):'Add attraction without photos';cmEl.go.disabled=false;}
 }
 function cmClose(){cmEl.modal.hidden=true;document.body.style.overflow='';}
 $('cmClose').addEventListener('click',cmClose);
 cmEl.modal.addEventListener('click',e=>{if(e.target===cmEl.modal)cmClose();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!cmEl.modal.hidden)cmClose();});
 async function openPhotoFinder(p){
  cm.p=p;cm.photos=[];map.closePopup();
  cmEl.title.textContent='Photos for '+p.name;cmEl.grid.replaceChildren();cmEl.status.textContent='Searching Wikimedia Commons…';
  cmEl.actions.querySelectorAll('.cm-extra').forEach(n=>n.remove());
  cmEl.modal.hidden=false;document.body.style.overflow='hidden';cmSync();
  try{
   cm.photos=await commonsSearchBrowser(p);
  }catch(e){cmEl.status.textContent='Could not reach Wikimedia Commons from this browser (no internet, or it is busy). Try again in a moment, or upload photos by hand.';return;}
  cm.serverOk=true;
  if(!p.existing){
   try{const pr=await timedFetch('commons_photos.php?action=ping',{},15000);const pj=await pr.json();cm.serverOk=!!pj.ok;}catch(e){cm.serverOk=false;}
  }
  if(!cm.photos.length){cmEl.status.textContent='No free photos were found for this place. You can still add it and upload your own photo.';return;}
  if(cm.serverOk===false&&!p.existing){cmEl.status.textContent='Photos found, but this site cannot download them while the attraction is new. Add the attraction first, then press Find photos on it from the map and tick them again. (Or ask for the one-time certificate setup.)';}
  else cmEl.status.textContent=cm.photos.length+' free photo'+(cm.photos.length===1?'':'s')+' found. Tick the ones that show this place.';
  let last='';
  cm.photos.forEach(ph=>{
   if(ph.source!==last){last=ph.source;const h=document.createElement('div');h.className='cm-sub';h.textContent=ph.source==='name'?'Matches the name':'Taken nearby';cmEl.grid.appendChild(h);}
   const lab=document.createElement('label');lab.className='cm-item';
   const img=document.createElement('img');img.loading='lazy';img.alt=ph.description||ph.title;if(/^https:\/\//i.test(ph.thumb))img.src=ph.thumb;
   const meta=document.createElement('div');meta.className='cm-meta';
   const cb=document.createElement('input');cb.type='checkbox';cb.value=ph.title;
   cb.addEventListener('change',()=>{if(cmTicked().length>8){cb.checked=false;alert('Pick up to 8 photos.');}cmSync();});
   meta.append(cb,document.createTextNode(ph.artist+' · '+ph.license));
   if(/^https:\/\//i.test(ph.page_url)){const a=document.createElement('a');a.href=ph.page_url;a.target='_blank';a.rel='noopener';a.textContent=' view';a.addEventListener('click',e=>e.stopPropagation());meta.appendChild(a);}
   lab.append(img,meta);cmEl.grid.appendChild(lab);
  });
  cmSync();
 }
 cmEl.go.addEventListener('click',async()=>{
  const p=cm.p,titles=cmTicked();
  if(!p.existing){window.location.href=addUrl(p.name,category(p.tags||{}),placeArea(p.tags||{}),p.lat,p.lng)+(titles.length&&cm.serverOk!==false?'&photos='+encodeURIComponent(titles.join('|')):'');return;}
  cmEl.go.disabled=true;cmEl.status.textContent='Downloading and saving the photos…';
  try{
   const d=await savePhotosFromBrowser(p,titles);
   cmEl.status.textContent=(d.saved||0)+' photo'+(d.saved===1?'':'s')+' saved with credit.'+((d.errors||[]).length?' '+d.errors.join(' '):'');
   if(d.saved){cmEl.grid.replaceChildren();cmEl.count.textContent='';const a=document.createElement('a');a.className='btn btn-primary cm-extra';a.href='attractions.php?edit='+p.existing.id;a.textContent='Open attraction';cmEl.actions.replaceChildren(a);}
   else cmEl.go.disabled=false;
  }catch(e){cmEl.status.textContent=(e&&e.message&&e.message.length<120?e.message+' ':'')+'Saving failed. Check Manage Attractions to see whether any photos were added before trying again, or upload them by hand.';cmEl.go.disabled=false;}
 });

})();
</script>
</body></html>