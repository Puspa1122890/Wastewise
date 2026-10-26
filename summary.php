<?php
require_once 'includes/config.php';
require_once 'includes/security.php';
require_login();

$household_id = (int) $_SESSION['household_id'];

// These totals are the value of items the household has RECORDED as used or
// thrown away. They are not claimed to be money "saved" by the system: the
// app cannot know whether an item would have been wasted without it.
$stmt = $pdo->prepare(
    "SELECT status, COUNT(*) AS items, COALESCE(SUM(price), 0) AS value
     FROM Products
     WHERE household_id = ? AND status IN ('used', 'wasted', 'unknown')
     GROUP BY status"
);
$stmt->execute(array($household_id));
$totals = array(
    'used' => array('items' => 0, 'value' => 0.0),
    'wasted' => array('items' => 0, 'value' => 0.0),
    'unknown' => array('items' => 0, 'value' => 0.0),
);
foreach ($stmt->fetchAll() as $row) {
    $totals[$row['status']] = array('items' => (int) $row['items'], 'value' => (float) $row['value']);
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM Products WHERE household_id = ? AND status = 'active' AND expiry_date < CURDATE()"
);
$stmt->execute(array($household_id));
$awaiting = (int) $stmt->fetchColumn();

$confirmed = $totals['used']['items'] + $totals['wasted']['items'];
$waste_share = $confirmed > 0 ? round($totals['wasted']['items'] / $confirmed * 100) : null;

$stmt = $pdo->prepare(
    "SELECT category, status, COALESCE(SUM(price), 0) AS value
     FROM Products
     WHERE household_id = ? AND status IN ('used', 'wasted')
     GROUP BY category, status"
);
$stmt->execute(array($household_id));
$by_cat = array();
foreach ($stmt->fetchAll() as $row) {
    $by_cat[$row['category']][$row['status']] = (float) $row['value'];
}
$labels = array_keys($by_cat);
$used_vals = array();
$wasted_vals = array();
foreach ($labels as $cat) {
    $used_vals[] = isset($by_cat[$cat]['used']) ? $by_cat[$cat]['used'] : 0;
    $wasted_vals[] = isset($by_cat[$cat]['wasted']) ? $by_cat[$cat]['wasted'] : 0;
}

$page_title = 'Summary';
include 'includes/header.php';
?>
<h1>Used and thrown-away food</h1>
<p class="note">These figures show the value of items your household has recorded as used or thrown away.
   They reflect what has been recorded. They are not proof of money saved by the system.</p>

<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-label">Recorded as used</div>
        <div class="stat-value"><?php echo number_format($totals['used']['value'], 2); ?> kr</div>
        <div class="stat-sub"><?php echo $totals['used']['items']; ?> <?php echo $totals['used']['items'] == 1 ? 'item' : 'items'; ?></div>
    </div>
    <div class="stat-card stat-card-waste">
        <div class="stat-label">Recorded as thrown away</div>
        <div class="stat-value"><?php echo number_format($totals['wasted']['value'], 2); ?> kr</div>
        <div class="stat-sub"><?php echo $totals['wasted']['items']; ?> <?php echo $totals['wasted']['items'] == 1 ? 'item' : 'items'; ?></div>
    </div>
    <div class="stat-card stat-card-unknown">
        <div class="stat-label">Outcome unknown</div>
        <div class="stat-value"><?php echo $totals['unknown']['items']; ?> <?php echo $totals['unknown']['items'] == 1 ? 'item' : 'items'; ?></div>
        <div class="stat-sub"><?php echo number_format($totals['unknown']['value'], 2); ?> kr, not counted above</div>
    </div>
</div>

<?php if ($waste_share !== null): ?>
    <p>Of the <?php echo $confirmed; ?> <?php echo $confirmed == 1 ? 'item' : 'items'; ?> with a confirmed outcome, <strong><?php echo $waste_share; ?>%</strong> were recorded as thrown away.</p>
<?php endif; ?>
<?php if ($awaiting > 0): ?>
    <p class="note"><?php echo $awaiting; ?> expired item(s) are waiting for confirmation on the
       <a href="products.php">food page</a>. They are not counted as used or thrown away yet.</p>
<?php endif; ?>

<div class="card">
    <h2>Value by category</h2>
    <?php if (empty($labels)): ?>
        <p class="empty-row">Nothing has been recorded as used or thrown away yet.</p>
    <?php else: ?>
        <div class="chart-wrap"><canvas id="categoryChart"></canvas></div>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
        <script>
            new Chart(document.getElementById('categoryChart'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($labels); ?>,
                    datasets: [
                        { label: 'Recorded as used (kr)', data: <?php echo json_encode($used_vals); ?>, backgroundColor: '#66a56e' },
                        { label: 'Recorded as thrown away (kr)', data: <?php echo json_encode($wasted_vals); ?>, backgroundColor: '#c9605a' }
                    ]
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true, title: { display: true, text: 'Value (kr)' } } }
                }
            });
        </script>
    <?php endif; ?>
</div>

<p><a href="export_dataset.php" class="button-link secondary">Download my household's data (CSV)</a>
   <span class="hint">Contains items, dates and outcomes only. No names or emails.</span></p>
<?php include 'includes/footer.php'; ?>
