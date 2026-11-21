<?php
// Attraction search helpers (v9.30): spelling variants, wider matching and relevance ranking.
// Plain PHP and SQL only: no external service and no extra tables.

/** Groups of names people use for the same place. Add more lines as needed (lower case, no apostrophes). */
function attractionAliasGroups() {
    return [
        ['maasai mara', 'masai mara', 'mara', 'maasai mara national reserve', 'masai mara national reserve'],
        ['mount kenya', 'mt kenya', 'mt. kenya', 'mount kenya national park'],
        ['nairobi national park', 'nairobi park'],
        ['lake nakuru', 'nakuru', 'lake nakuru national park'],
        ['lake naivasha', 'naivasha'],
        ['hells gate', 'hell gate', 'hells gate national park'],
        ['diani', 'diani beach'],
        ['fort jesus', 'fort jesus museum'],
        ['lamu', 'lamu island', 'lamu old town'],
        ['tsavo', 'tsavo east', 'tsavo west'],
        ['amboseli', 'amboseli national park'],
        ['aberdare', 'aberdares', 'aberdare national park'],
        ['karura', 'karura forest'],
        ['giraffe centre', 'giraffe center'],
        ['watamu', 'watamu beach', 'watamu marine park'],
    ];
}

/** The typed text plus any variants that share an alias group with it. Returns lower-case search terms. */
function attractionSearchTerms($typed) {
    $t = strtolower(trim(html_entity_decode((string)$typed, ENT_QUOTES, 'UTF-8')));
    $t = str_replace(["'", "\u{2019}"], '', $t);
    if ($t === '') return [];
    $terms = [$t];
    foreach (attractionAliasGroups() as $group) {
        foreach ($group as $variant) {
            // Same place if the typed text is, or contains, a variant (or is contained by one of 4+ letters)
            if ($t === $variant || strpos($t, $variant) !== false || (strlen($t) >= 4 && strpos($variant, $t) === 0)) {
                $terms = array_merge($terms, $group);
                break;
            }
        }
    }
    // Also match each word of a longer phrase, e.g. "wildlife safari" finds "safari" in descriptions.
    foreach (preg_split('/\s+/', $t) as $w) {
        if (strlen($w) >= 4 && !in_array($w, ['national', 'park', 'lake', 'mount', 'beach', 'reserve', 'with', 'near'], true)) $terms[] = $w;
    }
    return array_values(array_unique($terms));
}

/**
 * SQL condition (no leading AND) matching name, place, category or description for any term.
 * Adds named parameters to $params; returns '' when there are no terms.
 */
function attractionSearchWhere(array $terms, array &$params) {
    $parts = [];
    foreach ($terms as $i => $term) {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $parts[] = "(a.name LIKE :st{$i}a OR a.location_name LIKE :st{$i}b OR a.category LIKE :st{$i}c OR a.description LIKE :st{$i}d)";
        foreach (['a', 'b', 'c', 'd'] as $s) $params[":st{$i}{$s}"] = $like;
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : '';
}

/** Relevance of one attraction row for the terms: name beats place, place beats category, category beats description. */
function attractionRelevance(array $row, array $terms) {
    $name = strtolower(html_entity_decode((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $loc  = strtolower(html_entity_decode((string)($row['location_name'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $cat  = strtolower((string)($row['category'] ?? ''));
    $desc = strtolower(html_entity_decode((string)($row['description'] ?? ''), ENT_QUOTES, 'UTF-8'));
    $best = 0;
    foreach ($terms as $i => $t) {
        $w = ($i === 0) ? 1.0 : 0.9;   // what the visitor typed counts slightly more than a variant or single word
        $s = 0;
        if ($name === $t) $s = 100;
        elseif (strpos($name, $t) === 0) $s = 80;
        elseif (strpos($name, $t) !== false) $s = 70;
        elseif (strpos($loc, $t) !== false) $s = 50;
        elseif (strpos($cat, $t) !== false) $s = 40;
        elseif (strpos($desc, $t) !== false) $s = 20;
        $best = max($best, $s * $w);
    }
    return $best;
}

/** Rating pulled towards a neutral 3.0 when there are few reviews (same idea as the stay score). */
function attractionAdjustedRating($rating) {
    if (!$rating) return 3.0;   // no visible reviews yet: neutral, never zero stars
    $n = (int)$rating['count'];
    return ($n * (float)$rating['avg'] + 3 * 3.0) / ($n + 3);
}
