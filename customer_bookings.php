<?php
require_once 'includes/operations.php';
require_role('customer');
require_once 'config/db.php';
require_once 'includes/booking_capacity.php';

$bookings = sql_rows($conn, "SELECT b.*,
    (SELECT COUNT(*) FROM tbl_rentals r WHERE r.booking_id = b.booking_id) AS checked_out
    FROM tbl_bookings b WHERE b.customer_id = ?
    ORDER BY (b.booking_status IN ('pending','confirmed') AND TIMESTAMPADD(MINUTE, 30, b.reservation_date) > UTC_TIMESTAMP()) DESC,
    b.reservation_date DESC", 'i', (int) $_SESSION['user_id']);
$selectedRef = field('ref', $_GET);
$active = 'history';
$title = 'My Bookings';
$subtitle = 'Check reservation decisions, pickup reminders, and booking references.';
require 'includes/workspace_header.php';
?>
<div class="ops-actions"><a class="btn-submit" href="customer_book.php">Book a Scooter</a></div>
<?php if (!$bookings): ?><p class="ops-card">You have no reservations yet.</p><?php endif; ?>
<?php foreach ($bookings as $booking): ?>
  <?php $status = booking_status_label($booking); $pickup = local_time($booking['reservation_date']); ?>
  <section class="ops-card" aria-label="<?= h($booking['booking_ref']) ?>">
    <h2><?= h($booking['booking_ref']) ?> <span class="ops-badge"><?= h($status) ?></span></h2>
    <dl>
      <div><dt>Stall</dt><dd><?= h($booking['preferred_stall']) ?></dd></div>
      <div><dt>Pickup</dt><dd><?= h($pickup) ?></dd></div>
      <div><dt>Duration</dt><dd><?= (int) $booking['duration_minutes'] ?> min</dd></div>
      <div><dt>Scooters</dt><dd><?= (int) $booking['scooter_quantity'] ?></dd></div>
    </dl>
    <?php if ($status === 'Approved'): ?>
      <?php $secondsToPickup = strtotime($booking['reservation_date'] . ' UTC') - time(); ?>
      <p><?= $secondsToPickup <= 0 ? 'Pickup is open. Show this reference at the counter.' : ($secondsToPickup <= 86400 ? 'Pickup is within the next 24 hours. Have this reference ready.' : 'Bring this reference to the counter at your pickup time.') ?></p>
    <?php elseif ($status === 'Rejected'): ?><p>Staff declined this reservation.</p>
    <?php elseif ($status === 'Pending review'): ?><p>Staff are reviewing this request.</p><?php endif; ?>
    <?php if ($selectedRef === $booking['booking_ref']): ?><p><strong>Save this reference for your records.</strong></p><?php endif; ?>
    <button class="btn-submit booking-download" type="button" hidden
      data-ref="<?= h($booking['booking_ref']) ?>" data-status="<?= h($status) ?>" data-pickup="<?= h($pickup) ?>"
      data-stall="<?= h($booking['preferred_stall']) ?>" data-detail="<?= (int) $booking['scooter_quantity'] ?> scooter(s), <?= (int) $booking['duration_minutes'] ?> min">Download Booking Image</button>
  </section>
<?php endforeach; ?>
<script src="js/booking.js"></script>
<?php require 'includes/workspace_footer.php'; ?>
