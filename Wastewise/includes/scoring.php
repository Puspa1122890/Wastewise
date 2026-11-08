<?php
/**
 * WasteWise scoring engine.
 *
 * Two approaches are implemented side by side so they can be compared fairly:
 *   1. The ADAPTIVE waste-risk score (the project's proposal)
 *   2. The FIXED-DATE baseline (alert N days before the expiry date)
 *
 * The first half of this file is "pure": plain functions that take numbers
 * and return numbers, with no database. That is deliberate. It lets the same
 * formula be unit-tested (tests/run_tests.php) and re-implemented
 * independently in Python (evaluation/scoring_ref.py) for the evaluation.
 * The shared test vectors in tests/scoring_vectors.json check that both
 * implementations give identical answers.
 *
 * Point-in-time rule: every function here uses only information that exists
 * on the day the score is calculated. Outcomes recorded later are never
 * used to score an earlier decision. See docs/EVALUATION_PROTOCOL.md.
 */

// ---------------------------------------------------------------------
// Parameters. All of these are design choices, not measured facts.
// They are documented and sensitivity-tested in docs/DESIGN_NOTES.md.
// ---------------------------------------------------------------------

function ww_default_params() {
    return array(
        'w_urgency' => 0.4,          // weight of expiry urgency
        'w_category' => 0.2,         // weight of category perishability
        'w_mismatch' => 0.4,         // weight of usage-time mismatch
        'urgency_horizon' => 10,     // days before expiry at which urgency starts
        'min_history' => 3,          // used items needed before own history is trusted
        'history_window' => 20,      // most recent used items considered
        'baseline_days' => 3,        // fixed-date baseline lead time
        'fallback_size_exponent' => 1.0, // default days / household_size ^ exponent
        'default_scale' => 1.0,      // multiplier on the category default days
    );
}

// How quickly this TYPE of food generally becomes a problem (0 = barely
// perishable, 10 = spoils very quickly). Author-assigned values.
function ww_category_weights() {
    return array(
        'Dairy' => 9,
        'Produce' => 8,
        'Meat & Fish' => 9,
        'Bread & Bakery' => 6,
        'Frozen' => 2,
        'Canned Goods' => 1,
        'Other' => 5,
    );
}

// Assumed typical days to use up an item for a single person. Used ONLY
// until a household has logged enough of its own history. Author-assigned.
function ww_category_default_days() {
    return array(
        'Dairy' => 4,
        'Produce' => 3,
        'Meat & Fish' => 2,
        'Bread & Bakery' => 4,
        'Frozen' => 60,
        'Canned Goods' => 180,
        'Other' => 7,
    );
}

function ww_clamp($x, $lo, $hi) {
    return max($lo, min($hi, $x));
}

function ww_category_weight($category) {
    $w = ww_category_weights();
    return isset($w[$category]) ? $w[$category] : 5;
}

function ww_category_default($category, $p) {
    $d = ww_category_default_days();
    $base = isset($d[$category]) ? $d[$category] : 7;
    return $base * $p['default_scale'];
}

/**
 * Estimate how many days this household typically takes to use an item in
 * a category.
 *
 * $durations: days from purchase to reported use, for previously USED items
 *             in this category, most recent first.
 * With enough history (min_history) the household's own average is used.
 * Otherwise a category default divided by household size is used, as a
 * starting assumption. Household size has no effect once history exists.
 */
function ww_usage_estimate($durations, $category, $household_size, $p) {
    $recent = array_slice($durations, 0, $p['history_window']);
    $n = count($recent);

    if ($n >= $p['min_history'] && $n > 0) {
        $sum = 0.0;
        foreach ($recent as $d) {
            $sum += max(0, $d);
        }
        return array('days' => $sum / $n, 'source' => 'history', 'n' => $n);
    }

    $size = max(1, (int) $household_size);
    $days = ww_category_default($category, $p) / pow($size, $p['fallback_size_exponent']);
    return array('days' => $days, 'source' => 'fallback', 'n' => $n);
}

/**
 * The adaptive waste-risk score, from 0 (no risk) to 10 (very high risk).
 *
 *   score = w_urgency  * urgency
 *         + w_category * category_weight
 *         + w_mismatch * mismatch
 *
 *   urgency  = how close the expiry date is, scaled 0..10
 *   mismatch = how far the household's usual time-to-use exceeds the days
 *              left, as a share of the usual time, scaled 0..10
 */
function ww_adaptive_score($days_remaining, $category, $usage_days, $p) {
    $h = $p['urgency_horizon'];
    $urgency = ww_clamp($h - $days_remaining, 0, $h) * (10.0 / $h);

    $category_weight = ww_category_weight($category);

    $mismatch = 0.0;
    if ($usage_days > 0) {
        $mismatch = ww_clamp(($usage_days - $days_remaining) / $usage_days * 10.0, 0, 10);
    }

    return $p['w_urgency'] * $urgency
         + $p['w_category'] * $category_weight
         + $p['w_mismatch'] * $mismatch;
}

// The fixed-date baseline: flag everything within N days of expiry,
// regardless of category, household or history.
function ww_baseline_flag($days_remaining, $p) {
    return $days_remaining >= 0 && $days_remaining <= $p['baseline_days'];
}

// Display only. These cut-offs are NOT used in the evaluation.
function ww_risk_label($score) {
    if ($score >= 6) {
        return array('label' => 'High', 'class' => 'risk-high');
    }
    if ($score >= 4) {
        return array('label' => 'Moderate', 'class' => 'risk-moderate');
    }
    return array('label' => 'Low', 'class' => 'risk-low');
}

// ---------------------------------------------------------------------
// Database helpers (these wrap the pure functions above)
// ---------------------------------------------------------------------

function ww_days_between($from, $to) {
    $a = new DateTime($from);
    $b = new DateTime($to);
    return (int) $a->diff($b)->format('%r%a');
}

function ww_household_size($pdo, $household_id) {
    $stmt = $pdo->prepare("SELECT household_size FROM Households WHERE id = ?");
    $stmt->execute(array($household_id));
    $v = $stmt->fetchColumn();
    return max(1, (int) $v);
}

// Days from purchase to reported use for earlier USED items in a category.
// Only items whose reported use date is strictly BEFORE $as_of are included,
// so a score never uses outcomes from the day it is calculated or later.
function ww_history_durations($pdo, $household_id, $category, $as_of, $window) {
    $window = (int) $window;
    $stmt = $pdo->prepare(
        "SELECT DATEDIFF(outcome_date, purchase_date) AS d
         FROM Products
         WHERE household_id = ? AND category = ? AND status = 'used'
           AND outcome_date IS NOT NULL AND outcome_date < ?
         ORDER BY outcome_date DESC, id DESC
         LIMIT " . $window
    );
    $stmt->execute(array($household_id, $category, $as_of));
    $out = array();
    foreach ($stmt->fetchAll() as $row) {
        $out[] = max(0, (int) $row['d']);
    }
    return $out;
}

// Score a list of products. Returns the same list with extra fields.
function ww_score_products($pdo, $products, $household_id, $as_of = null, $p = null) {
    if ($as_of === null) {
        $as_of = date('Y-m-d');
    }
    if ($p === null) {
        $p = ww_default_params();
    }
    $size = ww_household_size($pdo, $household_id);
    $cache = array();

    foreach ($products as $i => $product) {
        $cat = $product['category'];
        if (!isset($cache[$cat])) {
            $cache[$cat] = ww_history_durations($pdo, $household_id, $cat, $as_of, $p['history_window']);
        }
        $days_remaining = ww_days_between($as_of, $product['expiry_date']);
        $usage = ww_usage_estimate($cache[$cat], $cat, $size, $p);
        $score = ww_adaptive_score($days_remaining, $cat, $usage['days'], $p);

        $products[$i]['days_remaining'] = $days_remaining;
        $products[$i]['usage_days'] = $usage['days'];
        $products[$i]['usage_source'] = $usage['source'];
        $products[$i]['history_n'] = $usage['n'];
        $products[$i]['risk_score'] = $score;
        $products[$i]['risk_level'] = ww_risk_label($score);
        $products[$i]['baseline_flag'] = ww_baseline_flag($days_remaining, $p);
    }
    return $products;
}
