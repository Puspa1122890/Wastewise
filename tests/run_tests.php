<?php
// Command-line test runner. No database or web server needed.
//
// Run from the project folder:
//     C:\xampp\php\php.exe tests\run_tests.php
// (or just  php tests/run_tests.php  if php is on your PATH)
//
// It checks that the PHP scoring code gives exactly the same answers as the
// independent Python implementation (tests/scoring_vectors.json), and tests
// the security and validation helpers.

require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/security.php';

$passed = 0;
$failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name $detail\n";
    }
}

function near($a, $b, $tol = 1e-9) {
    return abs($a - $b) <= $tol;
}

echo "Scoring: PHP versus Python reference vectors\n";
$json = file_get_contents(__DIR__ . '/scoring_vectors.json');
$vectors = json_decode($json, true);
check('vector file loaded', is_array($vectors) && count($vectors) > 0);

foreach ($vectors as $v) {
    $p = array_merge(ww_default_params(), $v['params']);
    $usage = ww_usage_estimate($v['durations'], $v['category'], $v['household_size'], $p);
    $score = ww_adaptive_score($v['days_remaining'], $v['category'], $usage['days'], $p);
    $flag = ww_baseline_flag($v['days_remaining'], $p);

    $ok = near($usage['days'], $v['expected_usage_days'])
        && $usage['source'] === $v['expected_source']
        && $usage['n'] === $v['expected_history_n']
        && near($score, $v['expected_score'])
        && $flag === $v['expected_baseline_flag'];
    check($v['name'], $ok,
        '(php score ' . $score . ', expected ' . $v['expected_score'] . ')');
}

echo "\nScoring: properties that must always hold\n";
$p = ww_default_params();
$u = ww_usage_estimate(array(), 'Dairy', 1, $p);
$near_dairy = ww_adaptive_score(1, 'Dairy', $u['days'], $p);
$far_dairy = ww_adaptive_score(20, 'Dairy', $u['days'], $p);
check('closer to expiry scores higher', $near_dairy > $far_dairy);

// The category weight is the only difference here, so both items are given
// the same usual time to use. (With each category's own default time, a canned
// item whose household usually takes 180 days can legitimately score higher
// through the mismatch term, which is intended behaviour.)
$same_usual = 4.0;
$canned = ww_adaptive_score(2, 'Canned Goods', $same_usual, $p);
$dairy = ww_adaptive_score(2, 'Dairy', $same_usual, $p);
check('dairy outranks canned goods at equal days remaining and equal usual time', $dairy > $canned);

$all_ok = true;
foreach (array('Dairy', 'Produce', 'Canned Goods', 'Frozen') as $cat) {
    for ($d = -5; $d <= 400; $d += 5) {
        $uu = ww_usage_estimate(array(), $cat, 1, $p);
        $s = ww_adaptive_score($d, $cat, $uu['days'], $p);
        if ($s < 0 || $s > 10) {
            $all_ok = false;
        }
    }
}
check('score always stays between 0 and 10', $all_ok);

$u_hist = ww_usage_estimate(array(5, 5, 5), 'Dairy', 1, $p);
$u_hist_big = ww_usage_estimate(array(5, 5, 5), 'Dairy', 6, $p);
check('household size ignored once history exists', near($u_hist['days'], $u_hist_big['days']));

$u_fb1 = ww_usage_estimate(array(), 'Dairy', 1, $p);
$u_fb4 = ww_usage_estimate(array(), 'Dairy', 4, $p);
check('fallback estimate shrinks with household size', $u_fb4['days'] < $u_fb1['days']);

check('baseline flags item 3 days from expiry', ww_baseline_flag(3, $p) === true);
check('baseline does not flag item 4 days from expiry', ww_baseline_flag(4, $p) === false);
check('baseline ignores already expired items', ww_baseline_flag(-1, $p) === false);

echo "\nSecurity helpers\n";
$_SESSION = array();
$token = csrf_token();
check('csrf token generated', strlen($token) === 64);
check('csrf accepts the right token', csrf_check_value($token) === true);
check('csrf rejects a wrong token', csrf_check_value('wrong') === false);
check('csrf rejects an empty token', csrf_check_value('') === false);
check('e() escapes script tags', e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');
check('e() escapes quotes', e('" onmouseover="x') === '&quot; onmouseover=&quot;x');

echo "\nValidation helpers\n";
check('valid date accepted', valid_date_string('2026-10-06') === true);
check('impossible date rejected', valid_date_string('2026-02-31') === false);
check('wrong format rejected', valid_date_string('06/10/2026') === false);
check('barcode of digits accepted', valid_barcode('5701234567890') === true);
check('empty barcode accepted (optional)', valid_barcode('') === true);
check('barcode with letters rejected', valid_barcode('57012abc') === false);
check('barcode too short rejected', valid_barcode('123') === false);
check('known category accepted', in_array('Dairy', allowed_categories()) === true);
check('unknown category rejected', in_array('Medicine', allowed_categories()) === false);

echo "\nResult: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
