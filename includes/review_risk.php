<?php
/**
 * Rule-based review risk score (0-100) with human-readable reasons. It helps an administrator decide what to
 * check first. It is a signal, not proof, and nothing is removed automatically.
 * $r is a row from admin/reviews.php (reviews + other_prop_reviews + prop_avg_rating).
 * Returns ['score' => int, 'level' => 'low'|'medium'|'high', 'reasons' => string[]].
 */
function reviewRisk($pdo, array $r) {
    $score = 0; $why = [];
    $text  = trim((string)($r['review_text'] ?? ''));
    $plain = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // A host reported this review, or its author
    try {
        $q = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE report_type = 'review' AND target_id = ?");
        $q->execute([(int)$r['review_id']]);
        if ((int)$q->fetchColumn() > 0) { $score += 30; $why[] = 'Reported by a host (+30)'; }
        $q = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE report_type = 'tourist' AND target_id = ?");
        $q->execute([(int)$r['tourist_id']]);
        if ((int)$q->fetchColumn() > 0) { $score += 15; $why[] = 'Its author was reported by a host (+15)'; }
    } catch (Throwable $e) {}

    // Links, e-mail addresses or phone numbers (often advertising or steering guests away)
    if (preg_match('~(https?://|www\.|[a-z0-9-]+\.(com|net|co\.ke|org|info)\b|[\w.+-]+@[\w-]+\.\w+|(\+?\d[\d\s().-]{8,}\d))~i', $plain)) {
        $score += 20; $why[] = 'Contains a link, e-mail address or phone number (+20)';
    }

    // Identical text elsewhere
    if ($text !== '') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE review_text = ? AND review_id != ?");
        $q->execute([$text, (int)$r['review_id']]);
        if ((int)$q->fetchColumn() > 0) { $score += 25; $why[] = 'Identical text appears in another review (+25)'; }
    }

    // Several reviews by the same person within minutes
    $q = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE tourist_id = ? AND review_id != ?
                        AND ABS(TIMESTAMPDIFF(MINUTE, created_at, ?)) <= 15");
    $q->execute([(int)$r['tourist_id'], (int)$r['review_id'], $r['created_at']]);
    if ((int)$q->fetchColumn() >= 1) { $score += 15; $why[] = 'Several reviews by the same person within minutes (+15)'; }

    // Very short or shouting
    $letters = preg_replace('/[^A-Za-z]/', '', $plain);
    if (mb_strlen($plain, 'UTF-8') < 20) { $score += 10; $why[] = 'Very short review (+10)'; }
    elseif (strlen($letters) >= 12 && $letters === strtoupper($letters)) { $score += 10; $why[] = 'Written in all capitals (+10)'; }

    // Rating far from what other guests said
    if ((int)($r['other_prop_reviews'] ?? 0) >= 2 && abs((float)$r['rating'] - (float)$r['prop_avg_rating']) >= 2) {
        $score += 15; $why[] = 'Rating is far from the listing\'s average of ' . number_format((float)$r['prop_avg_rating'], 1) . ' (+15)';
    }

    // The existing anomaly-detection score computed at submission time (0-8)
    if ((int)($r['suspicion_score'] ?? 0) >= 4) { $score += 15; $why[] = 'High anomaly score at submission: ' . (int)$r['suspicion_score'] . '/8 (+15)'; }

    $score = min(100, $score);
    return ['score' => $score, 'level' => $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low'), 'reasons' => $why];
}
