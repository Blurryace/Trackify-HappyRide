<?php
require_once 'includes/operations.php';
require_role('customer');
require_once 'config/db.php';
require_once 'includes/booking_capacity.php';

$recent = sql_rows($conn, "SELECT b.* FROM tbl_bookings b WHERE customer_id = ?
    AND (b.reviewed_at IS NOT NULL OR b.booking_status IN ('confirmed','converted_to_rental'))
    ORDER BY COALESCE(reviewed_at, created_at) DESC LIMIT 5", 'i', (int) $_SESSION['user_id']);
$active = 'dashboard';
$title = 'Hi, ' . ($_SESSION['first_name'] ?? 'Customer');
$subtitle = 'Reserve a scooter and track your pickup.';
require 'includes/workspace_header.php';
?>
<div class="ops-actions">
  <a class="btn-submit" href="customer_book.php">Book a Scooter</a>
  <a class="ops-filter" href="customer_bookings.php">My Bookings</a>
</div>
<section class="ops-card">
  <h2>Booking Updates</h2>
  <?php if (!$recent): ?><p>You have no booking updates yet.</p><?php endif; ?>
  <?php foreach ($recent as $booking): ?>
    <p><a class="ops-link" href="customer_bookings.php?ref=<?= h(rawurlencode($booking['booking_ref'])) ?>"><?= h($booking['booking_ref']) ?></a>
      · <?= h(booking_status_label($booking)) ?> · <?= h(local_time($booking['reservation_date'])) ?></p>
  <?php endforeach; ?>
</section>
<?php require 'includes/workspace_footer.php'; ?>
