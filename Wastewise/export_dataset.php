<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();

$household_id = (int) $_SESSION['household_id'];

// Same column layout as the simulated data, so evaluation/evaluate.py can
// read it directly. No names, emails or invite codes are included.
$stmt = $pdo->prepare(
    "SELECT p.id AS item_id, p.household_id, h.household_size, p.category,
            p.purchase_date, p.expiry_date, p.status AS outcome, p.outcome_date, p.price
     FROM Products p JOIN Households h ON p.household_id = h.id
     WHERE p.household_id = ? AND p.status IN ('used', 'wasted', 'unknown')
     ORDER BY p.purchase_date, p.id"
);
$stmt->execute(array($household_id));
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="wastewise_household_' . $household_id . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fputcsv($out, array('item_id', 'household_id', 'household_size', 'category', 'purchase_date',
                    'expiry_date', 'outcome', 'outcome_date', 'price'), ',', '"', '\\');
foreach ($rows as $r) {
    fputcsv($out, array($r['item_id'], $r['household_id'], $r['household_size'], $r['category'],
                        $r['purchase_date'], $r['expiry_date'], $r['outcome'],
                        $r['outcome_date'] === null ? '' : $r['outcome_date'], $r['price']), ',', '"', '\\');
}
fclose($out);
exit;
