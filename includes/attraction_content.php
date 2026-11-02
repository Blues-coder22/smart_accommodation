<?php
/**
 * Content for the fun, story-style attraction pages (tagline, best time, "Did you know?", "What's new").
 *
 * Admins can fill these in (Admin > Manage attractions). Anything left blank is filled sensibly:
 *   - well-known Kenyan places get a few built-in, well-documented facts,
 *   - every attraction gets general Kenya facts and category travel tips,
 *   - "What's new" is built from real data: newly listed stays nearby, recent guest ratings, and the season.
 * Extra fields live in their own table (attraction_extras) so the existing attractions table is untouched.
 */
require_once __DIR__ . '/helpers.php';

if (!function_exists('ensureAttractionExtras')) {
    function ensureAttractionExtras($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS attraction_extras (
                attraction_id INT NOT NULL PRIMARY KEY,
                tagline       VARCHAR(220) NULL,
                best_time     VARCHAR(160) NULL,
                climate       VARCHAR(160) NULL,
                terrain       VARCHAR(200) NULL,
                best_for      VARCHAR(200) NULL,
                did_you_know  TEXT NULL,
                whats_new     TEXT NULL,
                updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { /* run database/migration_attraction_extras.sql by hand */ }
    }
}

if (!function_exists('getAttractionExtras')) {
    function getAttractionExtras($pdo, $attraction_id) {
        ensureAttractionExtras($pdo);
        try {
            $st = $pdo->prepare("SELECT * FROM attraction_extras WHERE attraction_id = ?");
            $st->execute([(int)$attraction_id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('saveAttractionExtras')) {
    function saveAttractionExtras($pdo, $attraction_id, array $f) {
        ensureAttractionExtras($pdo);
        $st = $pdo->prepare("INSERT INTO attraction_extras
              (attraction_id, tagline, best_time, climate, terrain, best_for, did_you_know, whats_new)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE tagline = VALUES(tagline), best_time = VALUES(best_time),
                climate = VALUES(climate), terrain = VALUES(terrain), best_for = VALUES(best_for),
                did_you_know = VALUES(did_you_know), whats_new = VALUES(whats_new)");
        $nz = function ($k) use ($f) { $v = trim((string)($f[$k] ?? '')); return $v === '' ? null : $v; };
        $st->execute([(int)$attraction_id, $nz('tagline'), $nz('best_time'), $nz('climate'), $nz('terrain'),
                      $nz('best_for'), $nz('did_you_know'), $nz('whats_new')]);
    }
}

if (!function_exists('attractionEmoji')) {
    function attractionEmoji($category) {
        // Single-colour symbols (text presentation, no coloured emoji): the VS15 mark U+FE0E asks for the plain glyph.
        $c = strtolower(html_entity_decode((string)$category, ENT_QUOTES, 'UTF-8'));
        $map = [
            'wildlife' => "\u{265E}", 'park' => "\u{2618}", 'nature' => "\u{2698}", 'beach' => "\u{2600}",
            'museum' => "\u{26B1}", 'histor' => "\u{265C}", 'adventure' => "\u{26F0}", 'cultur' => "\u{266B}",
            'restaurant' => "\u{2615}", 'shop' => "\u{2726}", 'entertain' => "\u{2605}", 'relig' => "\u{271D}",
            'lake' => "\u{2248}", 'mountain' => "\u{26F0}",
        ];
        foreach ($map as $key => $glyph) {
            if (strpos($c, $key) !== false) return $glyph . "\u{FE0E}";
        }
        return "\u{25C6}\u{FE0E}";
    }
}

if (!function_exists('attractionPhotoUrl')) {
    /** Public URL (relative to a page in /tourist) of an attraction photo, or a frontend default image. */
    function attractionPhotoUrl($photo) {
        $photo = trim((string)$photo);
        if ($photo === '') return '../StayExplore_Kenya_Authenticated_Frontend/assets/kenya-lake-shore.jpg';
        if (preg_match('~^https?://~i', $photo) || strpos($photo, '/') === 0) return $photo;
        return '../uploads/attractions/' . rawurlencode(basename(str_replace('\\', '/', $photo)));
    }
}

/** Built-in, well-documented facts for famous places (matched on name). Each: [keywords, tagline, best, climate, facts[]] */
if (!function_exists('attractionKnowledge')) {
    function attractionKnowledge($name) {
        $n = strtolower(html_entity_decode((string)$name, ENT_QUOTES, 'UTF-8'));
        $db = [
            ['mara', 'Endless savannah, big cats and the Great Migration.', 'July – October (Great Migration)', 'Warm, dry days and cool evenings', [
                'Each year well over a million wildebeest, plus zebra and gazelle, circle between Tanzania’s Serengeti and the Mara chasing fresh grass.',
                'The dramatic Mara River crossings usually happen between July and October.',
                'The Mara is part of the greater Mara–Serengeti ecosystem, one of the last great migratory wildlife systems on Earth.',
                'A sunrise hot-air balloon flight is one of the most memorable ways to see the plains.']],
            ['mount kenya|mt kenya|mt. kenya', 'Glaciers on the equator: Africa’s second-highest peak.', 'January – February and August – September', 'Cool and misty; cold at altitude', [
                'At 5,199 m, Batian is the second-highest summit in Africa, after Kilimanjaro.',
                'Mount Kenya is a UNESCO World Heritage Site, listed in 1997.',
                'The equator passes just north of the summit — glaciers and the equator share the same view.',
                'Its Kikuyu name, Kirinyaga, is often translated as “mountain of brightness”.']],
            ['lamu', 'Swahili lanes, dhows and not a car in sight.', 'December – March and July – October', 'Hot and humid with cooling sea breezes', [
                'Lamu Old Town is a UNESCO World Heritage Site (2001) and one of the oldest, best-preserved Swahili settlements in East Africa.',
                'Donkeys and dhows are the main transport — there are almost no cars on the island.',
                'Look for the carved wooden doors: each one tells you something about the household behind it.']],
            ['diani', 'Powder-white sand and warm Indian Ocean water.', 'December – March and July – October', 'Hot, humid, steady sea breeze', [
                'Diani’s coral reef sits just offshore, which keeps the water calm and clear for snorkelling.',
                'Troops of Angolan colobus monkeys live in the coastal forest right behind the beach.']],
            ['amboseli', 'Elephants with Kilimanjaro as the backdrop.', 'June – October (dry season)', 'Warm and dusty; cooler mornings', [
                'Amboseli is famous for classic views of Mount Kilimanjaro rising above herds of elephants.',
                'Its elephants are among the most closely studied in the world — research here began in 1972.']],
            ['nairobi national|nairobi park', 'Wild Africa with a city skyline behind it.', 'June – October and January – February', 'Mild and pleasant', [
                'It is one of very few wildlife parks in the world right beside a capital city.',
                'It is a rhino sanctuary — a great place to look for black rhino.']],
            ['nakuru', 'Rift Valley lake, rhinos and pink flamingo shores.', 'June – March (varies with the lake level)', 'Warm days, cool nights', [
                'Lake Nakuru National Park protects both black and white rhino.',
                'Flamingo numbers rise and fall with the lake’s water level and algae, so every visit looks a little different.']],
            ['hell', 'Walk or cycle among zebra and giraffe.', 'June – October and January – March', 'Warm and dry', [
                'Hell’s Gate is one of the few Kenyan parks where you may walk or cycle among wildlife.',
                'Steam still rises from the earth in places — the park sits on active geothermal ground.']],
            ['tsavo', 'Kenya’s biggest wild country.', 'June – October', 'Hot and dry', [
                'Tsavo East and West together make up the largest protected area in Kenya.',
                'Tsavo’s elephants dust-bathe in red soil, which is why they look rusty red.']],
            ['fort jesus', 'Five centuries of Mombasa history in coral stone.', 'December – March', 'Hot and humid', [
                'Fort Jesus was built by the Portuguese between 1593 and 1596.',
                'It became a UNESCO World Heritage Site in 2011.']],
            ['naivasha', 'Hippos, birdlife and a freshwater Rift Valley lake.', 'June – March', 'Mild, breezy', [
                'Lake Naivasha is a freshwater lake in the Rift Valley — unusual in a region of soda lakes.',
                'A boat trip at dawn is one of the best ways to spot hippos and birds.']],
            ['samburu', 'The dry north’s rare “Special Five”.', 'June – October and January – February', 'Hot and dry', [
                'Samburu is known for the “Special Five”: Grevy’s zebra, reticulated giraffe, Somali ostrich, beisa oryx and gerenuk.']],
            ['giraffe centre', 'Eye to eye with a Rothschild’s giraffe.', 'All year', 'Mild', [
                'The centre helps protect the endangered Rothschild’s giraffe, and you can feed them from a raised platform.']],
            ['sheldrick|elephant orphan', 'Orphaned baby elephants, raised with love.', 'All year', 'Mild', [
                'The trust has been hand-raising orphaned elephants since 1977, then returning them to the wild.']],
            ['karura', 'A big green forest inside Nairobi.', 'All year (dry months are easiest)', 'Mild and fresh', [
                'Karura is among the largest urban forests in the world, with waterfalls, caves and cycling trails.']],
        ];
        foreach ($db as $row) {
            foreach (explode('|', $row[0]) as $kw) {
                if ($kw !== '' && strpos($n, $kw) !== false) {
                    return ['tagline' => $row[1], 'best_time' => $row[2], 'climate' => $row[3], 'facts' => $row[4]];
                }
            }
        }
        return null;
    }
}

if (!function_exists('kenyaFacts')) {
    function kenyaFacts() {
        return [
            'The equator crosses Kenya — near Nanyuki you can stand with one foot in each hemisphere.',
            '“Safari” is a Swahili word that simply means “journey”.',
            'The Great Rift Valley runs through Kenya, shaping its lakes, volcanoes and dramatic escarpments.',
            'Kenya’s Rift Valley highlands are famous for producing many of the world’s best long-distance runners.',
            'Swahili and English are both official languages in Kenya — “karibu” means “welcome”.',
        ];
    }
}

if (!function_exists('categoryTips')) {
    function categoryTips($category) {
        $c = strtolower(html_entity_decode((string)$category, ENT_QUOTES, 'UTF-8'));
        if (strpos($c, 'park') !== false || strpos($c, 'wildlife') !== false) {
            return ['Early mornings and late afternoons are when animals are most active.', 'Bring binoculars and a zoom lens — and neutral-coloured clothes.'];
        }
        if (strpos($c, 'beach') !== false) {
            return ['Check the tide times: low tide is best for walks and reef pools.', 'Reef-safe sunscreen protects the corals you came to see.'];
        }
        if (strpos($c, 'museum') !== false || strpos($c, 'histor') !== false) {
            return ['Hiring a local guide brings the stories behind the stones to life.', 'Go early to enjoy the exhibits before the crowds arrive.'];
        }
        if (strpos($c, 'adventure') !== false || strpos($c, 'mountain') !== false) {
            return ['Wear layers — weather changes fast — and carry plenty of water.', 'Start early so you finish well before dark.'];
        }
        if (strpos($c, 'cultur') !== false || strpos($c, 'relig') !== false) {
            return ['Dress modestly and ask before photographing people.', 'A few words of Swahili — “asante” (thank you) — go a long way.'];
        }
        if (strpos($c, 'restaurant') !== false) {
            return ['Ask what the local speciality is — and order that.', 'Book ahead on weekends and holidays.'];
        }
        return ['Ask your host for their favourite local tip.', 'Go early — mornings are quieter and cooler.'];
    }
}

if (!function_exists('packingList')) {
    function packingList($category) {
        $c = strtolower(html_entity_decode((string)$category, ENT_QUOTES, 'UTF-8'));
        if (strpos($c, 'park') !== false || strpos($c, 'wildlife') !== false) return ['Binoculars', 'Camera', 'Hat & sunscreen', 'Warm layer for dawn'];
        if (strpos($c, 'beach') !== false) return ['Sunglasses', 'Reef-safe sunscreen', 'Sandals', 'Snorkel'];
        if (strpos($c, 'adventure') !== false || strpos($c, 'mountain') !== false) return ['Sturdy shoes', 'Water bottle', 'Warm layers', 'Torch'];
        if (strpos($c, 'museum') !== false || strpos($c, 'histor') !== false || strpos($c, 'cultur') !== false || strpos($c, 'relig') !== false) return ['Comfy shoes', 'Notebook', 'Camera', 'Modest clothing'];
        return ['Comfy shoes', 'Water bottle', 'Camera', 'Hat & sunscreen'];
    }
}

if (!function_exists('parseSeasonMonths')) {
    /** "July – October (Great Migration)" or "Dec–Mar and Jul–Oct" => array of month numbers 1-12. */
    function parseSeasonMonths($text) {
        $names = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
                  'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        $text = strtolower(html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8'));
        if (preg_match('/all year|year[- ]round/', $text)) return range(1, 12);
        if (!preg_match_all('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*/', $text, $m, PREG_OFFSET_CAPTURE)) return [];
        $found = [];
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $cur = $names[$m[1][$i][0]];
            $isRange = false;
            if ($i + 1 < $count) {
                $gapStart = $m[0][$i][1] + strlen($m[0][$i][0]);
                $gap = substr($text, $gapStart, $m[0][$i + 1][1] - $gapStart);
                $isRange = (bool)preg_match('/^\s*(–|—|-|&ndash;|to|through)\s*$/u', $gap);
            }
            if ($isRange) {
                $end = $names[$m[1][$i + 1][0]];
                for ($k = $cur, $guard = 0; $guard < 12; $k = $k % 12 + 1, $guard++) {
                    $found[$k] = true;
                    if ($k === $end) break;
                }
                $i++;
            } else {
                $found[$cur] = true;
            }
        }
        $out = array_keys($found);
        sort($out);
        return $out;
    }
}

if (!function_exists('relativeDays')) {
    function relativeDays($datetime) {
        $t = strtotime((string)$datetime);
        if (!$t) return '';
        $d = (int)floor((time() - $t) / 86400);
        if ($d <= 0) return 'today';
        if ($d === 1) return 'yesterday';
        if ($d < 14) return $d . ' days ago';
        if ($d < 60) return floor($d / 7) . ' weeks ago';
        return date('M Y', $t);
    }
}

if (!function_exists('buildAttractionStory')) {
    /**
     * Everything the attraction page needs. $nearbyStays: rows with title, accommodation_id, distance_km, created_at?
     * Returns tagline, best_time, climate, terrain, best_for, months[], inSeason(bool|null), facts[], news[], tips[], packing[].
     */
    function buildAttractionStory($pdo, array $att, array $nearbyStays) {
        $ex = getAttractionExtras($pdo, (int)$att['attraction_id']);
        $known = attractionKnowledge($att['name'] ?? '');
        $pick = function ($key, $knownKey = null) use ($ex, $known) {
            if (!empty($ex[$key])) return $ex[$key];
            if ($known && $knownKey && !empty($known[$knownKey])) return $known[$knownKey];
            return '';
        };

        $desc = trim(html_entity_decode((string)($att['description'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $firstSentence = $desc !== '' ? preg_split('/(?<=[.!?])\s+/', $desc, 2)[0] : '';
        $tagline = $pick('tagline', 'tagline');
        if ($tagline === '') $tagline = mb_strlen($firstSentence) <= 140 ? $firstSentence : mb_substr($firstSentence, 0, 137) . '…';

        $bestTime = $pick('best_time', 'best_time');
        $months   = parseSeasonMonths($bestTime);
        $curMonth = (int)date('n');

        // Did you know: admin facts first, then built-in, then general Kenya facts + category tips.
        $facts = [];
        foreach (preg_split('/\R+/', (string)($ex['did_you_know'] ?? '')) as $line) {
            $line = trim(html_entity_decode($line, ENT_QUOTES, 'UTF-8'));
            if ($line !== '') $facts[] = ['kind' => 'Did you know?', 'text' => $line];
        }
        if ($known) foreach ($known['facts'] as $f) $facts[] = ['kind' => 'Did you know?', 'text' => $f];
        foreach (categoryTips($att['category'] ?? '') as $t) $facts[] = ['kind' => 'Insider tip', 'text' => $t];
        $kf = kenyaFacts();
        $seed = (int)($att['attraction_id'] ?? 0);
        for ($i = 0; $i < 2; $i++) $facts[] = ['kind' => 'Kenya fact', 'text' => $kf[($seed + $i) % count($kf)]];
        $facts = array_slice($facts, 0, 8);

        // What's new: admin posts, season, new stays nearby, recent ratings.
        $news = [];
        foreach (preg_split('/\R+/', (string)($ex['whats_new'] ?? '')) as $line) {
            $line = trim(html_entity_decode($line, ENT_QUOTES, 'UTF-8'));
            if ($line !== '') $news[] = ['icon' => '•', 'text' => $line, 'when' => 'From the StayExplore team', 'link' => null, 'fresh' => true];
        }
        if ($months) {
            if (in_array($curMonth, $months, true)) {
                $news[] = ['icon' => '•', 'text' => 'It’s in season right now — this is a great time to visit.', 'when' => 'This month', 'link' => null, 'fresh' => true];
            } else {
                $next = null;
                for ($k = 1; $k <= 12; $k++) { $m = ($curMonth + $k - 1) % 12 + 1; if (in_array($m, $months, true)) { $next = $m; break; } }
                if ($next) $news[] = ['icon' => '•', 'text' => 'The best window opens in ' . date('F', mktime(0, 0, 0, $next, 1)) . ' — a good moment to plan ahead.', 'when' => 'Coming up', 'link' => null, 'fresh' => false];
            }
        }
        foreach ($nearbyStays as $s) {
            if (!empty($s['created_at']) && strtotime($s['created_at']) > strtotime('-90 days')) {
                $news[] = ['icon' => '•', 'text' => 'New stay nearby: ' . html_entity_decode((string)$s['title'], ENT_QUOTES, 'UTF-8') . ' (' . number_format((float)$s['distance_km'], 1) . ' km away).',
                           'when' => 'Listed ' . relativeDays($s['created_at']), 'link' => 'details.php?id=' . (int)$s['accommodation_id'], 'fresh' => true];
                if (count($news) >= 6) break;
            }
        }
        try {
            $rv = $pdo->prepare("SELECT r.rating, r.created_at, a.title, a.accommodation_id
                FROM reviews r JOIN accommodations a ON a.accommodation_id = r.accommodation_id
                WHERE r.is_published = 1 AND a.status = 'approved' AND a.latitude IS NOT NULL
                  AND " . haversineSql('a.latitude', 'a.longitude', ':la', ':lo', ':la2') . " < 40
                ORDER BY r.created_at DESC LIMIT 2");
            $rv->execute([':la' => $att['latitude'], ':lo' => $att['longitude'], ':la2' => $att['latitude']]);
            foreach ($rv->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $news[] = ['icon' => '•', 'text' => 'A guest rated ' . html_entity_decode((string)$r['title'], ENT_QUOTES, 'UTF-8') . ' ' . (int)$r['rating'] . '/5 after staying nearby.',
                           'when' => ucfirst(relativeDays($r['created_at'])), 'link' => 'details.php?id=' . (int)$r['accommodation_id'], 'fresh' => false];
            }
        } catch (Throwable $e) { /* reviews table shape differs: skip */ }
        if (!$news) {
            $news[] = ['icon' => '•', 'text' => 'Nothing new yet — be the first to plan a trip here and tell us how it went.', 'when' => 'Stay tuned', 'link' => null, 'fresh' => false];
        }

        return [
            'tagline'   => $tagline,
            'best_time' => $bestTime,
            'climate'   => $pick('climate', 'climate'),
            'terrain'   => $pick('terrain'),
            'best_for'  => $pick('best_for'),
            'months'    => $months,
            'in_season' => $months ? in_array($curMonth, $months, true) : null,
            'facts'     => $facts,
            'news'      => array_slice($news, 0, 6),
            'packing'   => packingList($att['category'] ?? ''),
        ];
    }
}

if (!function_exists('getAttractionExtrasMap')) {
    /** [attraction_id => extras row] for a list of attraction rows (one query). */
    function getAttractionExtrasMap($pdo, array $attractions) {
        ensureAttractionExtras($pdo);
        $ids = array_values(array_filter(array_map(function ($a) { return (int)($a['attraction_id'] ?? 0); }, $attractions)));
        if (!$ids) return [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT * FROM attraction_extras WHERE attraction_id IN ($in)");
            $st->execute($ids);
            $map = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['attraction_id']] = $r;
            return $map;
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('attractionTeaser')) {
    /** One short, fun line for a search card: first admin fact, else a built-in fact, else the tagline. */
    function attractionTeaser(array $att, array $extras = []) {
        foreach (preg_split('/\R+/', (string)($extras['did_you_know'] ?? '')) as $line) {
            $line = trim(html_entity_decode($line, ENT_QUOTES, 'UTF-8'));
            if ($line !== '') return ['label' => 'Did you know?', 'text' => $line];
        }
        $k = attractionKnowledge($att['name'] ?? '');
        if ($k && !empty($k['facts'])) return ['label' => 'Did you know?', 'text' => $k['facts'][0]];
        $kf = kenyaFacts();
        return ['label' => 'Kenya fact', 'text' => $kf[((int)($att['attraction_id'] ?? 0)) % count($kf)]];
    }
}

if (!function_exists('attractionBlurb')) {
    /** Tagline for cards: admin tagline, built-in tagline, else the first sentence of the description. */
    function attractionBlurb(array $att, array $extras = []) {
        if (!empty($extras['tagline'])) return html_entity_decode($extras['tagline'], ENT_QUOTES, 'UTF-8');
        $k = attractionKnowledge($att['name'] ?? '');
        if ($k) return $k['tagline'];
        $d = trim(html_entity_decode((string)($att['description'] ?? ''), ENT_QUOTES, 'UTF-8'));
        if ($d === '') return 'Discover this corner of Kenya.';
        $s = preg_split('/(?<=[.!?])\s+/', $d, 2)[0];
        return mb_strlen($s) <= 120 ? $s : mb_substr($s, 0, 117) . '…';
    }
}
