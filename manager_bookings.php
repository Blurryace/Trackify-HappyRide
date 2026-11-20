<?php
require_once 'includes/operations.php';
require_role(['manager','staff']);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'config/db.php';
require_once 'includes/booking_capacity.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = integer_field(field('booking_id', $_POST), 1, 2147483647);
    $decision = field('decision', $_POST);
    $conn->begin_transaction();
    try {
        lock_rental_operations($conn);
        $booking = sql_row($conn, 'SELECT * FROM tbl_bookings WHERE booking_id = ? FOR UPDATE', 'i', $id);
        if (!$booking || $booking['booking_status'] !== 'pending') {
            throw new DomainException('This request has already been reviewed or is unavailable.');
        }
        if (!in_array($decision, ['approve','reject'], true)) throw new DomainException('Choose an approval or rejection.');
        $discount = false;
        $status = 'cancelled';
        if ($decision === 'approve') {
            if (field('confirm_payment', $_POST) !== '1') throw new DomainException('Confirm that the payment was received before approving.');
            if (booking_status_label($booking) === 'Pickup expired') throw new DomainException('This pickup window has already expired.');
            $discount = $booking['discount_requested'] && field('approve_discount', $_POST) === '1';
            $minutes = (int) $booking['duration_minutes'];
            $date = new DateTimeImmutable($booking['reservation_date'], new DateTimeZone('UTC'));
            $end = utc_sql($date->modify('+' . ($minutes + PICKUP_GRACE_MINUTES) . ' minutes'));
            $slots = reservation_slots($conn, $booking['preferred_stall'], $booking['reservation_date'], $end, $id);
            if ($slots < (int) $booking['scooter_quantity']) throw new DomainException('The reservation can no longer fit the available scooters.');
            $total = rental_price($minutes, $discount)['total'] * (int) $booking['scooter_quantity'];
            record_payment($conn, null, $id, 'booking', $total);
            $status = 'confirmed';
        }
        sql_run($conn, 'UPDATE tbl_bookings SET booking_status = ?, discount_approved = ?, reviewed_by = ?,
            reviewed_at = UTC_TIMESTAMP() WHERE booking_id = ?', 'siii', $status, (int) $discount, (int) $_SESSION['user_id'], $id);
        audit_action($conn, ucfirst($decision) . ' reservation ' . $booking['booking_ref']);
        $conn->commit();
        redirect_to('manager_bookings.php', 'Reservation ' . ($decision === 'approve' ? 'approved.' : 'rejected.'));
    } catch (DomainException $e) {
        $conn->rollback();
        $error = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log($e->getMessage());
        $error = 'The reservation could not be updated.';
    }
}

$bookings = sql_rows($conn, "SELECT b.*, CONCAT_WS(' ', u.first_name, u.last_name) AS customer_name,
    p.amount AS paid, (SELECT COUNT(*) FROM tbl_rentals r WHERE r.booking_id = b.booking_id) AS checked_out
    FROM tbl_bookings b JOIN tbl_users u ON u.user_id = b.customer_id
    LEFT JOIN tbl_payments p ON p.booking_id = b.booking_id
    ORDER BY (b.booking_status = 'pending' AND TIMESTAMPADD(MINUTE, 30, b.reservation_date) > UTC_TIMESTAMP()) DESC,
        b.reservation_date DESC");
$active = 'bookings';
$title = 'Manage Bookings';
$subtitle = 'Review requests, confirm payment, and check in approved reservations.';
require 'includes/workspace_header.php';
?>
<?php if (!$bookings): ?><p class="ops-card">No reservation requests yet.</p><?php endif; ?>
<?php foreach ($bookings as $booking): ?>
  <?php $status = booking_status_label($booking); $quantity = (int) $booking['scooter_quantity']; ?>
  <section class="ops-card">
    <h2><?= h($booking['booking_ref']) ?> <span class="ops-badge"><?= h($status) ?></span></h2>
    <p><?= h($booking['customer_name']) ?> · <?= h($booking['preferred_stall']) ?> · <?= h(local_time($booking['reservation_date'])) ?></p>
    <p><?= $quantity ?> scooter(s) · <?= (int) $booking['duration_minutes'] ?> min each · <?= (int) $booking['checked_out'] ?> picked up</p>
    <?php if ($booking['paid'] !== null): ?><p>Payment received <?= pesos(stored_money($booking['paid'])) ?></p><?php endif; ?>
    <?php if ($booking['booking_status'] === 'pending'): ?>
      <form action="manager_bookings.php" method="post">
        <?php csrf_input(); ?><input type="hidden" name="booking_id" value="<?= (int) $booking['booking_id'] ?>">
        <?php if ($status !== 'Pickup expired'): ?>
          <?php $regular = rental_price((int) $booking['duration_minutes'], false)['total'] * $quantity; ?>
          <p>Regular total <?= pesos($regular) ?>.
          <?php if ($booking['discount_requested']): ?>
            <?= pesos(rental_price((int) $booking['duration_minutes'], true)['total'] * $quantity) ?> if approved for the discount.
          <?php endif; ?></p>
          <?php if ($booking['discount_requested']): ?>
            <label class="ops-check"><input type="checkbox" name="approve_discount" value="1"> Verify and apply PWD / Senior Citizen discount</label>
          <?php endif; ?>
          <label class="ops-check"><input type="checkbox" name="confirm_payment" value="1"> Full reservation payment received</label>
          <button class="btn-submit" name="decision" value="approve">Approve</button>
        <?php endif; ?>
        <button class="btn-submit ops-danger" name="decision" value="reject">Reject</button>
      </form>
    <?php elseif ($status === 'Approved'): ?>
      <div class="ops-actions"><a href="manager_start_rental.php?ref=<?= h(rawurlencode($booking['booking_ref'])) ?>">Check In Scooter</a></div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
<?php require 'includes/workspace_footer.php'; ?>
