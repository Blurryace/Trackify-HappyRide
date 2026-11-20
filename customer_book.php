<?php
require_once 'includes/operations.php';
require_role('customer');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'config/db.php';
require_once 'includes/booking_capacity.php';

$stalls = booking_stalls($conn);
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$stall = field('stall', $input);
$pickupText = field('pickup', $input);
$pickup = parse_pickup($pickupText);
$minutes = integer_field(field('minutes', $input), 1, MAX_RENTAL_MINUTES);
$quantity = integer_field(field('quantity', $input), 1, 100);
$validSchedule = in_array($stall, $stalls, true) && $pickup && $minutes !== null;
$now = business_now();

if (isset($_GET['slots'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $slots = $validSchedule ? reservation_slots($conn, $stall, utc_sql($pickup),
        utc_sql($pickup->modify('+' . ($minutes + PICKUP_GRACE_MINUTES) . ' minutes'))) : null;
    echo json_encode(['slots' => $slots]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$validSchedule || $quantity === null) {
        $error = 'Choose a stall, a future pickup time, a valid duration, and the number of scooters.';
    } else {
        $conn->begin_transaction();
        try {
            lock_rental_operations($conn);
            $customerId = (int) $_SESSION['user_id'];
            if (sql_row($conn, "SELECT booking_id FROM tbl_bookings WHERE customer_id = ?
                AND booking_status IN ('pending','confirmed')
                AND TIMESTAMPADD(MINUTE, 30, reservation_date) > UTC_TIMESTAMP() LIMIT 1", 'i', $customerId)) {
                throw new DomainException('You already have an unfinished reservation.');
            }
            $start = utc_sql($pickup);
            $end = utc_sql($pickup->modify('+' . ($minutes + PICKUP_GRACE_MINUTES) . ' minutes'));
            if ($start <= utc_sql(business_now())) throw new DomainException('Choose a pickup time in the future.');
            $slots = reservation_slots($conn, $stall, $start, $end);
            if ($slots < $quantity) throw new DomainException("Only $slots scooter(s) are available for this time.");
            $reference = 'BKG-' . strtoupper(bin2hex(random_bytes(8)));
            sql_run($conn, 'INSERT INTO tbl_bookings
                (booking_ref, customer_id, reservation_date, preferred_stall, duration_minutes, scooter_quantity,
                discount_requested, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                'sissiii', $reference, $customerId, $start, $stall, $minutes, $quantity,
                (int) (field('discount', $_POST) === '1'));
            audit_action($conn, 'Requested reservation ' . $reference);
            $conn->commit();
            redirect_to('customer_bookings.php?ref=' . urlencode($reference), 'Reservation submitted. Save your reference for pickup.');
        } catch (DomainException $e) {
            $conn->rollback();
            $error = $e->getMessage();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log($e->getMessage());
            $error = 'The reservation could not be saved. Try again.';
        }
    }
}

$active = 'book';
$title = 'Book a Scooter';
$subtitle = 'Choose your stall, pickup time, rental duration, and number of scooters.';
require 'includes/workspace_header.php';
?>
<section class="ops-card">
  <?php if (!$stalls): ?><p>There are no stalls accepting reservations yet.</p><?php endif; ?>
  <form method="post" id="booking-form">
    <?php csrf_input(); ?>
    <div class="ops-fields">
      <label>Stall
        <select name="stall" class="form-control" required>
          <option value="">Select stall</option>
          <?php foreach ($stalls as $option): ?><option value="<?= h($option) ?>" <?= $stall === $option ? 'selected' : '' ?>><?= h($option) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Pickup time (Philippine time)
        <input type="datetime-local" name="pickup" class="form-control" min="<?= h($now->format('Y-m-d\TH:i')) ?>" value="<?= h($pickupText) ?>" required>
      </label>
      <label>Duration (minutes)
        <input type="number" name="minutes" class="form-control" min="1" max="<?= MAX_RENTAL_MINUTES ?>" value="<?= h(field('minutes', $input) ?: '30') ?>" required>
      </label>
      <label>Number of scooters
        <input type="number" name="quantity" class="form-control" min="1" max="100" value="<?= h($quantity ?? 1) ?>" required>
      </label>
    </div>
    <label class="ops-check"><input type="checkbox" name="discount" value="1" <?= field('discount', $input) === '1' ? 'checked' : '' ?>> Request PWD / Senior Citizen discount</label>
    <p id="booking-slots" role="status">Choose a stall and pickup time to check availability.</p>
    <p>Your request is pending until staff confirm payment. Pick up within <?= PICKUP_GRACE_MINUTES ?> minutes of your scheduled time.</p>
    <button class="btn-submit" <?= !$stalls ? 'disabled' : '' ?>>Request Reservation</button>
  </form>
</section>
<script src="js/booking.js"></script>
<?php require 'includes/workspace_footer.php'; ?>
