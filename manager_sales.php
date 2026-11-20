<?php
require_once 'includes/operations.php';
require_role('manager');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'config/db.php';

$periods = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];
$period = field('period', $_GET);
$period = isset($periods[$period]) ? $period : 'day';
$now = business_now();
$selected = field('date', $_GET);
$anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $selected, business_zone());
if (!$anchor || $anchor->format('Y-m-d') !== $selected || $anchor > $now) $anchor = $now->setTime(0, 0);
$today = $now->format('Y-m-d');
$start = match ($period) {
    'week' => $anchor->modify('monday this week'),
    'month' => $anchor->modify('first day of this month'),
    'year' => $anchor->setDate((int) $anchor->format('Y'), 1, 1),
    default => $anchor,
};
$end = $start->modify('+1 ' . $period);
$expenseDate = field('expense_date', $_POST) ?: $today;
$description = field('description', $_POST);
$cost = field('amount', $_POST);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $amount = entered_money($cost);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expenseDate, business_zone());
    if ($amount === null || $amount <= 0 || !preg_match('/^[^\p{Cc}]{1,150}$/uD', $description)
        || !$date || $date->format('Y-m-d') !== $expenseDate || $date > $now) {
        $error = 'Enter a description, a positive amount, and a valid expense date up to today.';
    } else {
        $conn->begin_transaction();
        try {
            $expense = sql_run($conn, 'INSERT INTO tbl_expenses (description, amount, expense_date, recorded_by)
                VALUES (?, ?, ?, ?)', 'sssi', $description, decimal_amount($amount), $expenseDate, (int) $_SESSION['user_id']);
            audit_action($conn, 'Recorded operating expense #' . $expense->insert_id);
            $conn->commit();
            redirect_to('manager_sales.php?period=' . $period . '&date=' . $expenseDate, 'Expense recorded.');
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log($e->getMessage());
            $error = 'The expense could not be saved.';
        }
    }
}

$payments = sql_rows($conn, "SELECT p.*, COALESCE(r.transaction_ref, b.booking_ref) AS reference
    FROM tbl_payments p LEFT JOIN tbl_rentals r ON r.rental_id = p.rental_id
    LEFT JOIN tbl_bookings b ON b.booking_id = p.booking_id
    WHERE p.recorded_at >= ? AND p.recorded_at < ? ORDER BY p.recorded_at DESC, p.payment_id DESC",
    'ss', utc_sql($start), utc_sql($end));
$expenses = sql_rows($conn, 'SELECT expense_date, description, amount FROM tbl_expenses
    WHERE expense_date >= ? AND expense_date < ? ORDER BY expense_date DESC, expense_id DESC',
    'ss', $start->format('Y-m-d'), $end->format('Y-m-d'));
$revenue = 0;
$costs = 0;
$trend = [];
$group = $period === 'year' ? 'Y-m' : ($period === 'day' ? 'H' : 'Y-m-d');
$step = $period === 'year' ? '+1 month' : ($period === 'day' ? '+1 hour' : '+1 day');
for ($point = $start; $point < $end; $point = $point->modify($step)) {
    $trend[$point->format($group)] = ['label' => $point->format($period === 'year' ? 'M' :
        ($period === 'day' ? 'g A' : ($period === 'week' ? 'D' : 'j'))), 'sales' => 0];
}
foreach ($payments as $payment) {
    $amount = stored_money($payment['amount']);
    $revenue += $amount;
    $key = (new DateTimeImmutable($payment['recorded_at'], new DateTimeZone('UTC')))
        ->setTimezone(business_zone())->format($group);
    $trend[$key]['sales'] += $amount;
}
foreach ($expenses as $expense) $costs += stored_money($expense['amount']);
$peak = max(1, ...array_column($trend, 'sales'));
$isCurrent = $start <= $now && $now < $end;
$active = 'sales';
$title = 'Sales Reports';
$subtitle = 'Recorded payments, operating expenses, and profit in Philippine time.';
require 'includes/workspace_header.php';
?>
<nav class="ops-actions" aria-label="Current sales periods">
  <?php foreach ($periods as $key => $label): ?>
    <a class="ops-filter" href="?period=<?= $key ?>" <?= $period === $key && $isCurrent ? 'aria-current="page"' : '' ?>>
      <?= $key === 'day' ? 'Today' : 'This ' . $label ?>
    </a>
  <?php endforeach; ?>
</nav>
<section class="ops-card">
  <form method="get" class="ops-actions">
    <label>Period
      <select class="form-control" name="period"><?php foreach ($periods as $key => $label): ?>
        <option value="<?= $key ?>" <?= $period === $key ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?></select>
    </label>
    <label>Date within period<input class="form-control" type="date" name="date" max="<?= $today ?>" value="<?= h($anchor->format('Y-m-d')) ?>" required></label>
    <button class="btn-submit">View Report</button>
  </form>
  <h2><?= h($start->format('M j, Y')) ?><?= $period === 'day' ? '' : ' to ' . h($end->modify('-1 day')->format('M j, Y')) ?></h2>
  <p>Profit equals recorded payments minus recorded expenses. Add all operating costs for an accurate total.</p>
</section>
<div class="ops-metrics">
  <section class="ops-card"><h2>Sales Collected</h2><strong><?= pesos($revenue) ?></strong></section>
  <section class="ops-card"><h2>Recorded Expenses</h2><strong><?= pesos($costs) ?></strong></section>
  <section class="ops-card"><h2>Profit After Expenses</h2><strong><?= pesos($revenue - $costs) ?></strong></section>
  <section class="ops-card"><h2>Payments</h2><strong><?= count($payments) ?></strong></section>
</div>
<section class="ops-card">
  <h2>Sales by <?= $period === 'day' ? 'Hour' : ($period === 'year' ? 'Month' : 'Day') ?></h2>
  <?php if (!$revenue): ?><p>No payments recorded for this period.</p>
  <?php else: ?>
    <div class="sales-chart-scroll"><ul class="sales-chart">
      <?php foreach ($trend as $bar): ?>
        <li title="<?= h($bar['label'] . ' · ' . pesos($bar['sales'])) ?>">
          <div class="sales-bar-track"><span style="height:<?= h((string) (100 * $bar['sales'] / $peak)) ?>%"></span></div>
          <span><?= h($bar['label']) ?></span>
        </li>
      <?php endforeach; ?>
    </ul></div>
  <?php endif; ?>
</section>
<section class="ops-card">
  <h2>Payment Details</h2>
  <?php if (!$payments): ?><p>Checkout, extension, and prepaid booking payments will appear here when recorded.</p>
  <?php else: ?>
    <div class="ops-table"><table class="data-table">
      <thead><tr><th scope="col">Recorded At</th><th scope="col">Reference</th><th scope="col">Payment Type</th><th scope="col">Amount</th></tr></thead>
      <tbody><?php foreach ($payments as $payment): ?>
        <tr><td><?= h(local_time($payment['recorded_at'])) ?></td><td><?= h($payment['reference']) ?></td>
            <td><?= h(ucfirst($payment['kind'])) ?></td><td><?= pesos(stored_money($payment['amount'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</section>
<section class="ops-card">
  <h2>Operating Expenses</h2>
  <form method="post">
    <?php csrf_input(); ?>
    <div class="ops-fields">
      <label>Description<input class="form-control" name="description" maxlength="150" value="<?= h($description) ?>" required></label>
      <label>Amount (₱)<input class="form-control" type="number" name="amount" min=".01" max="9999999.99" step=".01" value="<?= h($cost) ?>" required></label>
      <label>Expense date<input class="form-control" type="date" name="expense_date" max="<?= $today ?>" value="<?= h($expenseDate) ?>" required></label>
    </div>
    <button class="btn-submit">Record Expense</button>
  </form>
  <?php if ($expenses): ?>
    <div class="ops-table"><table class="data-table">
      <thead><tr><th scope="col">Date</th><th scope="col">Description</th><th scope="col">Amount</th></tr></thead>
      <tbody><?php foreach ($expenses as $expense): ?><tr><td><?= h($expense['expense_date']) ?></td>
      <td><?= h($expense['description']) ?></td><td><?= pesos(stored_money($expense['amount'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php require 'includes/workspace_footer.php'; ?>
