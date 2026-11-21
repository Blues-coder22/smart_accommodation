<?php
// Help me choose where to go (v9.31). A short questionnaire ranks the attractions in the database against what the
// tourist wants: interests, setting, month, nightly budget. Every point of the score has a visible reason.
// Plain PHP and SQL; no external service. Open to everyone (no sign-in needed), read-only.
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/currency.php';
require_once '../includes/attraction_content.php';
require_once '../includes/attraction_reviews.php';
require_once '../includes/attraction_search.php';
if (session_status() === PHP_SESSION_NONE) session_start();

const DEST_STAY_RADIUS_KM = 50;     // a stay this close counts as "near" the attraction

// ---- the questions -------------------------------------------------------------------------------------------
$interestOptions = [   // key => [label, words looked for in the category / name / description]
    'wildlife'  => ['Wildlife and safari',   ['wildlife', 'safari', 'park', 'reserve']],
    'beach'     => ['Beaches and coast',     ['beach', 'coast', 'marine']],
    'nature'    => ['Nature and scenery',    ['nature', 'lake', 'forest', 'waterfall', 'park']],
    'adventure' => ['Adventure and hiking',  ['adventure', 'hik', 'climb', 'mountain', 'cycling']],
    'culture'   => ['Culture and history',   ['cultur', 'histor', 'museum', 'relig', 'heritage']],
    'food'      => ['Food and entertainment', ['restaurant', 'entertain', 'shop', 'market']],
];
$settingOptions = [    // key => [label, words looked for in name / place / category / description / terrain / climate]
    'any'       => ['No preference', []],
    'coast'     => ['Coast and islands', ['beach', 'coast', 'ocean', 'island', 'reef', 'marine', 'mombasa', 'diani', 'lamu', 'watamu', 'malindi', 'kilifi']],
    'mountains' => ['Mountains and highlands', ['mountain', 'mount ', 'mt ', 'mt.', 'highland', 'peak', 'aberdare', 'forest', 'hik']],
    'savannah'  => ['Savannah and wildlife country', ['savannah', 'savanna', 'plains', 'safari', 'wildlife', 'game reserve', 'national reserve', 'national park']],
    'lakes'     => ['Lakes and the Rift Valley', ['lake', 'rift valley', 'flamingo', 'hippo', 'naivasha', 'nakuru', 'baringo', 'bogoria', 'victoria']],
    'city'      => ['City and culture', ['nairobi', 'city', 'urban', 'museum', 'market', 'shopping']],
];
$monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
               7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

$submitted = isset($_GET['go']);
$selInterests = [];
foreach ((array)($_GET['interests'] ?? []) as $k) if (is_string($k) && isset($interestOptions[$k])) $selInterests[] = $k;
$setting = (string)($_GET['setting'] ?? 'any'); if (!isset($settingOptions[$setting])) $setting = 'any';
$month   = (int)($_GET['month'] ?? 0); if ($month < 1 || $month > 12) $month = 0;
$budget  = (float)($_GET['budget'] ?? 0); if ($budget < 0) $budget = 0; if ($budget > 1000000) $budget = 1000000;

function destDecode($t) { return strtolower(html_entity_decode((string)$t, ENT_QUOTES, 'UTF-8')); }
function destHasAny($haystack, array $words) { $n = 0; foreach ($words as $w) if ($w !== '' && strpos($haystack, $w) !== false) $n++; return $n; }
function destKm($lat1, $lng1, $lat2, $lng2) {
    $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * 6371 * asin(min(1, sqrt($a)));
}

$results = [];
if ($submitted) {
    $atts = $pdo->query("SELECT * FROM attractions WHERE latitude IS NOT NULL AND longitude IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $stays = $pdo->query("SELECT accommodation_id, title, price_per_night, latitude, longitude FROM accommodations
                          WHERE status = 'approved' AND latitude IS NOT NULL AND longitude IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $extras  = getAttractionExtrasMap($pdo, $atts);
    $ratings = attractionRatingsMap($pdo, array_column($atts, 'attraction_id'));

    foreach ($atts as $a) {
        $id = (int)$a['attraction_id'];
        $ex = $extras[$id] ?? [];
        $known = attractionKnowledge($a['name'] ?? '');
        $cat  = destDecode($a['category'] ?? '');
        $name = destDecode($a['name'] ?? '');
        $hay  = $name . ' ' . destDecode($a['location_name'] ?? '') . ' ' . $cat . ' ' . destDecode($a['description'] ?? '') . ' '
              . destDecode($ex['terrain'] ?? '') . ' ' . destDecode($ex['climate'] ?? '') . ' ' . destDecode($known['tagline'] ?? '');
        $why = []; $notes = []; $score = 0;

        // 1. Interests (40): the category matches = full points; only the text mentions it = part points
        if ($selInterests) {
            $best = 0; $bestLabel = '';
            foreach ($selInterests as $k) {
                list($label, $words) = $interestOptions[$k];
                if (destHasAny($cat, $words)) { if ($best < 40) { $best = 40; $bestLabel = $label; } }
                elseif (destHasAny($name . ' ' . destDecode($a['description'] ?? ''), $words) && $best < 15) { $best = 15; $bestLabel = $label; }
            }
            $score += $best;
            if ($best >= 40) $why[] = 'Matches your interest: ' . $bestLabel;
            elseif ($best > 0) $why[] = 'Mentions ' . strtolower($bestLabel);
        } else { $score += 20; }
        $interestPts = $selInterests ? $best : 20;

        // 2. Setting (20)
        $settingPts = 10;
        if ($setting !== 'any') {
            $hits = destHasAny($hay, $settingOptions[$setting][1]);
            $settingPts = $hits >= 2 ? 20 : ($hits === 1 ? 10 : 0);
            if ($settingPts > 0) $why[] = 'Setting: ' . strtolower($settingOptions[$setting][0]);
        }
        $score += $settingPts;
        if ($selInterests && $interestPts === 0 && ($setting === 'any' || $settingPts === 0)) continue;   // nothing in common with the answers

        // 3. Season (15)
        $bestTime = trim((string)($ex['best_time'] ?? '')) !== '' ? $ex['best_time'] : ($known['best_time'] ?? '');
        $months = parseSeasonMonths($bestTime);
        if ($month && $months) {
            if (in_array($month, $months, true)) { $score += 15; $why[] = 'In season in ' . $monthNames[$month]; }
            else { $notes[] = 'Outside its best season in ' . $monthNames[$month] . ' (best: ' . trim(html_entity_decode($bestTime, ENT_QUOTES, 'UTF-8')) . ')'; }
        } else { $score += 7; if ($month && !$months) $notes[] = 'Best season not recorded yet'; }

        // 4. Stays near it, and within budget (20)
        $near = []; $affordable = []; $cheapest = null;
        foreach ($stays as $s) {
            if (destKm((float)$a['latitude'], (float)$a['longitude'], (float)$s['latitude'], (float)$s['longitude']) <= DEST_STAY_RADIUS_KM) {
                $near[] = $s;
                $p = (float)$s['price_per_night'];
                if ($cheapest === null || $p < $cheapest) $cheapest = $p;
                if ($budget <= 0 || $p <= $budget) $affordable[] = $s;
            }
        }
        if ($budget > 0) {
            $c = count($affordable);
            $score += $c >= 2 ? 20 : ($c === 1 ? 12 : 0);
            if ($c > 0) $why[] = $c . ' stay' . ($c > 1 ? 's' : '') . ' within budget, within ' . DEST_STAY_RADIUS_KM . ' km';
            elseif ($near) $notes[] = 'Stays nearby cost more than your budget (from KES ' . number_format($cheapest) . ' a night)';
            else $notes[] = 'No stays listed within ' . DEST_STAY_RADIUS_KM . ' km yet';
        } else {
            $score += $near ? 10 : 0;
            if ($near) $why[] = count($near) . ' stay' . (count($near) > 1 ? 's' : '') . ' within ' . DEST_STAY_RADIUS_KM . ' km';
            else $notes[] = 'No stays listed within ' . DEST_STAY_RADIUS_KM . ' km yet';
        }

        // 5. Visitor rating (5): pulled towards a neutral 3 when there are few ratings
        $score += attractionAdjustedRating($ratings[$id] ?? null);

        $results[] = ['att' => $a, 'score' => (int)round(min(100, $score)), 'why' => $why, 'notes' => $notes,
                      'cheapest' => $cheapest, 'near' => count($near), 'affordable' => count($affordable)];
    }
    usort($results, function ($x, $y) { return $y['score'] <=> $x['score'] ?: strcasecmp((string)$x['att']['name'], (string)$y['att']['name']); });
    $results = array_slice($results, 0, 6);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Where to go — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .dc-wrap { max-width: 1000px; margin: 0 auto; padding: 32px 20px 60px; }
        .dc-form { background: var(--surface-2, #fff); border: 1px solid var(--border, #e4ddcf); border-radius: 12px; padding: 20px; margin: 18px 0 28px; display: grid; gap: 18px; }
        .dc-form fieldset { border: 0; padding: 0; margin: 0; }
        .dc-form legend { font-weight: 600; margin-bottom: 8px; font-size: 14px; }
        .dc-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; }
        .dc-checks label { display: flex; gap: 8px; align-items: center; border: 1px solid var(--border, #e4ddcf); border-radius: 8px; padding: 9px 11px; cursor: pointer; font-size: 14px; }
        .dc-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 14px; }
        .dc-row label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; }
        .dc-row select, .dc-row input { width: 100%; padding: 10px; border: 1px solid var(--border-strong, #c9c3b2); border-radius: 8px; font: inherit; }
        .dc-hint { font-size: 12px; color: #565c55; margin-top: 4px; }
        .dc-results { display: grid; gap: 16px; }
        .dc-card { display: grid; grid-template-columns: 200px 1fr; gap: 0; background: var(--surface-2, #fff); border: 1px solid var(--border, #e4ddcf); border-radius: 12px; overflow: hidden; }
        .dc-card img { width: 100%; height: 100%; min-height: 170px; object-fit: cover; background: #f0eee7; display: block; }
        .dc-body { padding: 16px; }
        .dc-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .dc-name { font-size: 18px; font-weight: 600; margin: 0 0 2px; }
        .dc-loc { font-size: 13px; color: #565c55; margin-bottom: 8px; }
        .dc-score { flex: none; text-align: center; background: #e6f5ef; color: #0f6e56; border-radius: 10px; padding: 6px 10px; font-weight: 700; font-size: 18px; line-height: 1.1; }
        .dc-score small { display: block; font-size: 10px; font-weight: 600; letter-spacing: .04em; }
        .dc-why { margin: 8px 0 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 6px; }
        .dc-why li { font-size: 12px; background: #eef4f1; color: #0d3b2e; border-radius: 999px; padding: 3px 10px; }
        .dc-notes { margin: 8px 0 0; padding: 0; list-style: none; font-size: 12px; color: #6b5b2a; }
        .dc-notes li { margin: 2px 0; }
        .dc-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
        .dc-empty { border: 1px dashed var(--border, #e4ddcf); border-radius: 12px; padding: 28px; text-align: center; }
        @media (max-width: 640px) { .dc-card { grid-template-columns: 1fr; } .dc-card img { height: 170px; } }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php include '../includes/header.php'; ?>
<main class="dc-wrap" id="main-content">
    <div class="eyebrow">Plan your trip</div>
    <h1>Where should you go?</h1>
    <p class="small">Not sure where to go in Kenya? Answer four quick questions and we will suggest places to visit, with stays near them. Leave anything you do not mind about as it is.</p>

    <form class="dc-form" method="get" action="choose_destination.php">
        <input type="hidden" name="go" value="1">
        <fieldset>
            <legend>What do you enjoy? (pick any)</legend>
            <div class="dc-checks">
                <?php foreach ($interestOptions as $k => $opt): ?>
                    <label><input type="checkbox" name="interests[]" value="<?= h($k) ?>" <?= in_array($k, $selInterests, true) ? 'checked' : '' ?>> <?= h($opt[0]) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="dc-row">
            <div>
                <label for="dcSetting">What kind of place?</label>
                <select id="dcSetting" name="setting">
                    <?php foreach ($settingOptions as $k => $opt): ?>
                        <option value="<?= h($k) ?>" <?= $setting === $k ? 'selected' : '' ?>><?= h($opt[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="dcMonth">When are you travelling?</label>
                <select id="dcMonth" name="month">
                    <option value="0">Any month</option>
                    <?php foreach ($monthNames as $n => $label): ?>
                        <option value="<?= $n ?>" <?= $month === $n ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="dcBudget">Most you want to pay per night (KES)</label>
                <input id="dcBudget" type="number" name="budget" min="0" step="500" inputmode="numeric" placeholder="No limit" value="<?= $budget > 0 ? (int)$budget : '' ?>">
                <div class="dc-hint">Prices are in Kenyan shillings, the amount you pay.</div>
            </div>
        </div>
        <div><button class="btn btn-primary" type="submit">Suggest places</button></div>
    </form>

    <?php if ($submitted): ?>
        <h2>Suggested for you</h2>
        <?php if (!$results): ?>
            <div class="dc-empty">
                <p><strong>Nothing matched those answers yet.</strong></p>
                <p class="small">Try fewer interests, &ldquo;No preference&rdquo; for the kind of place, or a higher budget. New places and stays are added as hosts and the team add them.</p>
            </div>
        <?php else: ?>
            <p class="small" style="margin-bottom:14px">The match score (out of 100) adds up your interests (40), the kind of place (20), the month (15), stays nearby within your budget (20) and visitor ratings (5). It is a guide; the reasons under each place show what counted.</p>
            <div class="dc-results">
            <?php foreach ($results as $r): $a = $r['att'];
                $stayUrl = 'search.php?type=accommodation&attraction_id=' . (int)$a['attraction_id'] . ($budget > 0 ? '&max_price=' . (int)$budget : ''); ?>
                <article class="dc-card">
                    <img src="<?= h(attractionPhotoUrl($a['photo_path'] ?? '')) ?>" alt="<?= h($a['name']) ?>" loading="lazy">
                    <div class="dc-body">
                        <div class="dc-top">
                            <div>
                                <h3 class="dc-name"><?= h($a['name']) ?></h3>
                                <div class="dc-loc"><?= h($a['location_name']) ?><?= !empty($a['category']) ? ' &middot; ' . h($a['category']) : '' ?></div>
                            </div>
                            <div class="dc-score" aria-label="Match score <?= (int)$r['score'] ?> out of 100"><?= (int)$r['score'] ?><small>MATCH</small></div>
                        </div>
                        <?php if ($r['why']): ?><ul class="dc-why"><?php foreach ($r['why'] as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        <?php if ($r['notes']): ?><ul class="dc-notes"><?php foreach ($r['notes'] as $n): ?><li>Note: <?= h($n) ?></li><?php endforeach; ?></ul><?php endif; ?>
                        <?php if ($r['cheapest'] !== null): ?><div class="dc-loc" style="margin-top:8px">Stays nearby from <?= moneyHtml($r['cheapest']) ?> a night</div><?php endif; ?>
                        <div class="dc-actions">
                            <a class="btn btn-outline" href="attraction.php?id=<?= (int)$a['attraction_id'] ?>">About this place</a>
                            <a class="btn btn-primary" href="<?= h($stayUrl) ?>">Find stays nearby</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php include '../includes/footer.php'; ?>
</body>
</html>
