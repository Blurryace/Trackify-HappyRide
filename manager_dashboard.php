<?php
require_once "config/db.php";
require_once "includes/operations.php";
require_role(['manager','staff']);
$active = 'dashboard';

// Quick stats
$totalScooters = $conn->query("SELECT COUNT(*) c FROM tbl_scooters")->fetch_assoc()['c'];
$available     = $conn->query("SELECT COUNT(*) c FROM tbl_scooters WHERE status='available'")->fetch_assoc()['c'];
$rented        = $conn->query("SELECT COUNT(*) c FROM tbl_scooters WHERE status='rented'")->fetch_assoc()['c'];
$activeRentals = $conn->query("SELECT r.transaction_ref, s.scooter_code, s.stall_location,
    COALESCE(r.customer_name, CONCAT(u.first_name, ' ', u.last_name)) AS customer_name,
    COALESCE(r.rental_source, CASE WHEN r.booking_id IS NOT NULL THEN 'reservation' ELSE 'walk_in' END) AS rental_source,
    r.total_amount,
    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), r.planned_end_time) AS seconds_remaining
    FROM tbl_rentals AS r
    JOIN tbl_scooters AS s ON s.scooter_id = r.scooter_id
    LEFT JOIN tbl_users AS u ON u.user_id = r.customer_id
    WHERE r.status = 'ongoing'
    ORDER BY r.planned_end_time IS NULL, r.planned_end_time, r.rental_id");
$pendingBookings = (int) sql_row($conn, "SELECT COUNT(*) AS units FROM tbl_bookings WHERE booking_status = 'pending'
    AND TIMESTAMPADD(MINUTE,30,reservation_date) > UTC_TIMESTAMP()")['units'];
$overdueRentals = (int) sql_row($conn, "SELECT COUNT(*) AS units FROM tbl_rentals
    WHERE status = 'ongoing' AND planned_end_time <= UTC_TIMESTAMP()")['units'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — TRACKIFY</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/operations.css">
</head>
<body>

<?php include "includes/navbar_manager.php"; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1>Welcome back, <?= htmlspecialchars($_SESSION['first_name']) ?> !!!</h1>
      <p class="subtitle">Here's what's happening at Happy Ride today.</p>
    </div>
  </div>

  <!-- Enlarged Stat Cards -->
  <div class="stat-grid-lg">
    <div class="stat-card-lg accent-green">
      <div class="stat-info">
        <div class="value"><?= $available ?></div>
        <div class="label">Available Scooters</div>
      </div>
    </div>

    <div class="stat-card-lg accent-red">
      <div class="stat-info">
        <div class="value"><?= $rented ?></div>
        <div class="label">Currently Rented</div>
      </div>
    </div>

    <div class="stat-card-lg accent-blue">
      <div class="stat-info">
        <div class="value"><?= $totalScooters ?></div>
        <div class="label">Total Scooter Fleet</div>
      </div>
    </div>
  </div>

  <section class="ops-card" style="margin-top:24px">
    <h2>Needs Attention</h2>
    <div class="ops-actions">
      <a href="manager_bookings.php"><?= $pendingBookings ?> pending reservation(s)</a>
      <a href="manager_rentals.php"><?= $overdueRentals ?> rental(s) past due</a>
      <?php if ($_SESSION['role'] === 'manager'): ?><a href="manager_sales.php">Sales Reports</a><?php endif; ?>
    </div>
  </section>
  <section id="active-rentals" class="table-card active-rentals" aria-labelledby="active-rentals-title">
    <div class="table-toolbar">
      <h2 id="active-rentals-title">Active Rentals</h2>
      <a href="manager_start_rental.php" class="btn-add-scooter">Start New Rental</a>
    </div>

      <?php if (!$activeRentals->num_rows): ?>
      <p class="rentals-empty">No active rental sessions.</p>
    <?php else: ?>
      <div class="rental-table-scroll">
        <table class="data-table">
          <thead>
            <tr>
              <th scope="col">Scooter</th>
              <th scope="col">Customer</th>
              <th scope="col">Rental Source</th>
              <th scope="col">Total Cost</th>
              <th scope="col">Reference</th>
              <th scope="col">Time Remaining</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($rental = $activeRentals->fetch_assoc()): ?>
              <?php $remaining = $rental['seconds_remaining']; ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($rental['scooter_code']) ?></strong>
                  <span class="rental-stall"><?= htmlspecialchars($rental['stall_location']) ?></span>
                </td>
                <td><?= htmlspecialchars($rental['customer_name'] ?? '—') ?></td>
                <td><?= $rental['rental_source'] === 'reservation' ? 'Reservation' : 'Walk-in' ?></td>
                <td><?= $rental['total_amount'] > 0 ? '₱' . number_format((float) $rental['total_amount'], 2) : '—' ?></td>
                <td><?= htmlspecialchars($rental['transaction_ref']) ?></td>
                <td>
                  <?php if ($remaining === null): ?>
                    —
                  <?php elseif ($remaining <= 0): ?>
                    <span class="rental-time rental-time-expired">Time up</span>
                  <?php else: ?>
                    <span class="rental-time" data-remaining-seconds="<?= (int) $remaining ?>">
                      <?= sprintf('%02d:%02d:%02d', intdiv($remaining, 3600), intdiv($remaining % 3600, 60), $remaining % 60) ?>
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

<script src="js/main.js"></script>
<script src="js/rental_countdown.js"></script>
</body>
</html>
