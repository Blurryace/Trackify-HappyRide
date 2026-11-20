<?php
require_once "includes/operations.php";
require_role(['manager', 'staff']);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once "config/db.php";
require_once "includes/booking_capacity.php";

$active = 'start_rental';
$_SESSION['start_rental_token'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['start_rental_token'];
$customerName = '';
$bookingRef = strtoupper(field('ref', $_GET));
$cashInput = '';
$pagerNumber = '';
$idType = '';
$booking = null;
$applyDiscount = false;
$hourlyRate = HOURLY_RATE;
$halfHourRate = HALF_HOUR_RATE;
$discountPercent = DISCOUNT_PERCENT;
$scooterChoice = '';
$durationInput = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($token, $submittedToken)) {
        http_response_code(403);
        $errors[] = 'This form has expired. Reload the page and try again.';
    } else {
        $customerName = is_string($_POST['customer_name'] ?? null) ? trim($_POST['customer_name']) : '';
        $bookingRef = strtoupper(field('booking_ref', $_POST));
        $cashInput = field('cash', $_POST);
        $pagerNumber = strtoupper(field('pager', $_POST));
        $idType = field('id_type', $_POST);
        $applyDiscount = ($_POST['apply_discount'] ?? '') === '1';
        $scooterChoice = is_string($_POST['scooter_id'] ?? null) ? $_POST['scooter_id'] : '';
        $durationInput = is_string($_POST['duration_minutes'] ?? null) ? $_POST['duration_minutes'] : '';
        $idOptions = ['options' => ['min_range' => 1, 'max_range' => 2147483647]];
        $scooterId = filter_var($scooterChoice, FILTER_VALIDATE_INT, $idOptions);
        $duration = filter_var($durationInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);

        if (!preg_match('/^[^\p{Cc}]{1,100}$/uD', $customerName)) {
            $errors[] = 'Enter a customer name with 1 to 100 characters.';
        }
        if ($scooterId === false) {
            $errors[] = 'Select an available scooter.';
        }
        if ($pagerNumber !== '' && !preg_match('/^[A-Z0-9][A-Z0-9-]{0,19}$/D', $pagerNumber)) {
            $errors[] = 'Enter a valid pager label, such as PAGER-01.';
        }
        if (!in_array($idType, ['Government ID', 'School ID', 'Other valid ID'], true)) {
            $errors[] = 'Verify the customer’s ID and select the type deposited.';
        }
        if ($duration === false) {
            $errors[] = 'Enter a whole number of minutes from 1 to 1,440.';
        }

        if (!$errors) {
            $staffId = (int) $_SESSION['user_id'];
            $reference = 'RNT-' . strtoupper(bin2hex(random_bytes(12)));
            $conn->begin_transaction();
            try {
                lock_rental_operations($conn);
                $bookingId = null;
                $rentalSource = 'walk_in';
                $scooterRow = sql_row($conn, 'SELECT stall_location, status FROM tbl_scooters WHERE scooter_id = ? FOR UPDATE', 'i', $scooterId);
                if (!$scooterRow || $scooterRow['status'] !== 'available') {
                    throw new DomainException('That scooter is no longer available. Select another scooter.');
                }
                $now = business_now();
                if ($bookingRef !== '') {
                    $booking = sql_row($conn, "SELECT b.*,
                        (SELECT COUNT(*) FROM tbl_rentals WHERE booking_id = b.booking_id) AS picked_up
                        FROM tbl_bookings b WHERE b.booking_ref = ? FOR UPDATE", 's', $bookingRef);
                    if (!$booking || booking_status_label($booking) !== 'Approved'
                        || (int) $booking['picked_up'] >= (int) $booking['scooter_quantity']) {
                        throw new DomainException('This reference has no available approved pickups.');
                    }
                    if ($booking['reservation_date'] > utc_sql($now)) throw new DomainException('This pickup window has not opened yet.');
                    if ($booking['preferred_stall'] !== $scooterRow['stall_location']) {
                        throw new DomainException('Choose a scooter at the reservation’s stall.');
                    }
                    $bookingId = (int) $booking['booking_id'];
                    $duration = (int) $booking['duration_minutes'];
                    $applyDiscount = (bool) $booking['discount_approved'];
                    $rentalSource = 'reservation';
                }
                $end = utc_sql($now->modify('+' . $duration . ' minutes'));
                if (reservation_slots($conn, $scooterRow['stall_location'], utc_sql($now), $end, $bookingId) < 1) {
                    throw new DomainException('This ride would overlap a reservation that needs the remaining scooters.');
                }
                if ($pagerNumber !== '' && sql_row($conn, "SELECT rental_id FROM tbl_rentals
                    WHERE status = 'ongoing' AND pager_number = ? LIMIT 1", 's', $pagerNumber)) {
                    throw new DomainException('That pager is already checked out.');
                }
                $price = rental_price($duration, $applyDiscount);
                $cash = $bookingId === null ? entered_money($cashInput) : $price['total'];
                if ($cash === null || $cash < $price['total']) throw new DomainException('Enter cash received that covers the total.');
                $totalAmount = decimal_amount($price['total']);
                $discountAmount = decimal_amount($price['discount']);
                $amountPaid = decimal_amount($cash);
                $changeAmount = decimal_amount($cash - $price['total']);
                $pager = $pagerNumber === '' ? null : $pagerNumber;
                $pagerStatus = $pager === null ? null : 'active';

                // Reserve the scooter row before creating the rental so simultaneous starts cannot use it twice.
                $scooter = $conn->prepare("UPDATE tbl_scooters AS s
                    SET status = 'rented', times_rented = times_rented + 1, last_used = UTC_TIMESTAMP()
                    WHERE s.scooter_id = ? AND s.status = 'available'
                      AND NOT EXISTS (SELECT 1 FROM tbl_rentals AS r
                          WHERE r.scooter_id = s.scooter_id AND r.status = 'ongoing')");
                $scooter->bind_param("i", $scooterId);
                $scooter->execute();
                if ($scooter->affected_rows !== 1) {
                    throw new DomainException('That scooter is no longer available. Select another scooter.');
                }

                $rental = sql_run($conn, "INSERT INTO tbl_rentals
                    (transaction_ref, customer_name, rental_source, scooter_id, staff_id, booking_id,
                     pager_number, pager_status, id_type_deposited, start_time, planned_end_time, total_amount,
                     discount_amount, amount_paid, change_amount)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(),
                            TIMESTAMPADD(MINUTE, ?, UTC_TIMESTAMP()), ?, ?, ?, ?)",
                    'sssiiisssissss', $reference, $customerName, $rentalSource, $scooterId, $staffId, $bookingId,
                    $pager, $pagerStatus, $idType, $duration, $totalAmount, $discountAmount, $amountPaid, $changeAmount);
                $rentalId = $rental->insert_id;
                if ($bookingId === null) {
                    record_payment($conn, $rentalId, null, 'rental', $price['total']);
                } elseif ((int) $booking['picked_up'] + 1 >= (int) $booking['scooter_quantity']) {
                    sql_run($conn, "UPDATE tbl_bookings SET booking_status = 'converted_to_rental' WHERE booking_id = ?", 'i', $bookingId);
                }
                log_rental($conn, $rentalId, 'started', $customerName . ' · ' .
                    ($bookingId === null ? 'Walk-in' : 'Reservation ' . $bookingRef));
                audit_action($conn, 'Started rental ' . $reference);
                $conn->commit();
                redirect_to('manager_rentals.php', 'Rental ' . $reference . ' started. Change ' . pesos($cash - $price['total']) . '.');
            } catch (DomainException $e) {
                $conn->rollback();
                $errors[] = $e->getMessage();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                error_log('Trackify rental start failed: ' . $e->getMessage());
                $errors[] = 'The rental could not be saved. Please try again.';
            }
        }
    }
}

if ($bookingRef !== '') {
    $booking = sql_row($conn, "SELECT b.*, CONCAT_WS(' ', u.first_name, u.last_name) AS booking_customer,
        (SELECT COUNT(*) FROM tbl_rentals WHERE booking_id = b.booking_id) AS picked_up
        FROM tbl_bookings b JOIN tbl_users u ON u.user_id = b.customer_id WHERE b.booking_ref = ?", 's', $bookingRef);
    if (!$booking || booking_status_label($booking) !== 'Approved') {
        $errors[] = 'This booking reference has no approved pickup. Clear it to start a walk-in rental.';
        $booking = null;
    } else {
        $customerName = $customerName ?: $booking['booking_customer'];
        $durationInput = (string) $booking['duration_minutes'];
        $applyDiscount = (bool) $booking['discount_approved'];
    }
}

$scooters = $conn->query("SELECT s.scooter_id, s.scooter_code, s.stall_location FROM tbl_scooters AS s
    WHERE s.status = 'available'
      AND NOT EXISTS (SELECT 1 FROM tbl_rentals AS r
          WHERE r.scooter_id = s.scooter_id AND r.status = 'ongoing')
    ORDER BY s.scooter_code")->fetch_all(MYSQLI_ASSOC);
$stalls = booking_stalls($conn);
if ($booking) {
    $stalls = [$booking['preferred_stall']];
    $scooters = array_values(array_filter($scooters, fn($scooter) =>
        $scooter['stall_location'] === $booking['preferred_stall']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Start New Rental — TRACKIFY</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/start_rental.css">
</head>
<body>

<?php include "includes/navbar_manager.php"; ?>

<main class="main-content">
  <div class="rental-workspace">
    <div class="page-header">
      <h1>Start New Rental</h1>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger" role="alert">
        <?php foreach ($errors as $error): ?>
          <p><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="get" class="form-group">
      <label class="form-label" for="booking-search">Booking reference (optional)</label>
      <input class="form-control" id="booking-search" name="ref" maxlength="50"
             placeholder="BKG-…" value="<?= h($bookingRef) ?>">
      <div class="form-actions">
        <button class="btn-submit">Find Booking</button>
        <?php if ($bookingRef !== ''): ?><a class="btn-cancel" href="manager_start_rental.php">Clear Reference</a><?php endif; ?>
      </div>
      <?php if ($booking): ?>
        <p class="rental-rate"><?= h($booking['preferred_stall']) ?> · <?= h(local_time($booking['reservation_date'])) ?> ·
          <?= (int) $booking['picked_up'] ?> of <?= (int) $booking['scooter_quantity'] ?> picked up · Prepaid</p>
      <?php endif; ?>
    </form>

    <form method="post" id="rental-form" data-reserved="<?= $booking ? '1' : '0' ?>" data-invalid-booking="<?= ($bookingRef !== '' && !$booking) ? '1' : '0' ?>"
          data-booking-stall="<?= h($booking['preferred_stall'] ?? '') ?>" data-hourly-rate="<?= $hourlyRate ?>"
          data-half-hour-rate="<?= $halfHourRate ?>" data-discount-percent="<?= $discountPercent ?>">
      <input type="hidden" name="booking_ref" value="<?= h($bookingRef) ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">

      <div class="rental-form-grid">
        <section class="rental-panel rental-details" aria-labelledby="rental-details-title">
          <h2 id="rental-details-title">Rental Details</h2>

          <?php if ($stalls): ?>
            <fieldset class="stall-options" id="stall-options" hidden>
              <legend class="form-label">Choose stall</legend>
              <?php foreach ($stalls as $stall): ?>
                <label>
                  <input type="radio" name="stall_filter" value="<?= htmlspecialchars($stall) ?>">
                  <?= htmlspecialchars($stall) ?>
                </label>
              <?php endforeach; ?>
            </fieldset>
          <?php endif; ?>

          <div class="form-group">
            <label class="form-label" for="customer_name">Customer name</label>
            <input class="form-control" id="customer_name" name="customer_name" type="text" maxlength="100"
                   placeholder="Full name" value="<?= htmlspecialchars($customerName) ?>" required>
          </div>

          <div class="rental-flags">
            <label>
              <input type="checkbox" name="apply_discount" value="1" <?= $applyDiscount ? 'checked' : '' ?> <?= $booking ? 'disabled' : '' ?>>
              PWD / Senior Citizen (<?= $discountPercent ?>% discount)
            </label>
          </div>
          <div class="form-group">
            <label class="form-label" for="id_type">Verified ID deposited</label>
            <select class="form-control" id="id_type" name="id_type" required>
              <option value="">Select verified ID type</option>
              <?php foreach (['Government ID', 'School ID', 'Other valid ID'] as $option): ?>
                <option <?= $idType === $option ? 'selected' : '' ?>><?= h($option) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="pager">Pager label (optional)</label>
            <input class="form-control" id="pager" name="pager" maxlength="20" placeholder="PAGER-01" value="<?= h($pagerNumber) ?>">
          </div>
          <?php if (!$booking): ?>
            <div class="form-group">
              <label class="form-label" for="cash">Cash received (₱)</label>
              <input class="form-control" id="cash" name="cash" type="number" min="0" max="9999999.99" step=".01" value="<?= h($cashInput) ?>" required>
            </div>
          <?php endif; ?>
        </section>

        <fieldset class="rental-panel rental-scooters">
          <legend>Choose Scooter</legend>
          <?php if ($scooters): ?>
            <div class="rental-scooter-list">
              <?php foreach ($scooters as $scooter): ?>
                <label class="rental-scooter-option" data-stall="<?= htmlspecialchars($scooter['stall_location']) ?>">
                  <input type="radio" name="scooter_id" value="<?= (int) $scooter['scooter_id'] ?>"
                         data-code="<?= htmlspecialchars($scooter['scooter_code']) ?>"
                         <?= $scooterChoice === (string) $scooter['scooter_id'] ? 'checked' : '' ?> required>
                  <span>
                    <strong><?= htmlspecialchars($scooter['scooter_code']) ?></strong>
                    <small><?= htmlspecialchars($scooter['stall_location']) ?></small>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <p id="stall-empty" class="rental-muted" role="status" hidden>No scooters are available at this stall.</p>
          <?php else: ?>
            <p class="rental-muted">No scooters are available right now.</p>
          <?php endif; ?>
        </fieldset>

        <section class="rental-panel rental-duration" aria-labelledby="rental-duration-title">
          <h2 id="rental-duration-title">Duration</h2>
          <div class="rental-presets" id="rental-presets" hidden>
            <button type="button" data-minutes="60">+1 hour</button>
            <button type="button" data-minutes="30">+30 min</button>
          </div>
          <label class="form-label" for="duration_minutes">Duration in minutes</label>
          <input class="form-control" id="duration_minutes" name="duration_minutes" type="number"
                 min="1" max="1440" step="1" inputmode="numeric" placeholder="Enter minutes"
                 value="<?= htmlspecialchars($durationInput) ?>" aria-describedby="duration-rates" <?= $booking ? 'readonly' : '' ?> required>
          <p class="rental-muted rental-rate" id="duration-rates">
            ₱<?= $hourlyRate ?> per hour, ₱<?= $halfHourRate ?> for a remaining half hour. Custom durations round up to the next 30 minutes for billing.
          </p>
        </section>
      </div>

      <section class="rental-panel rental-summary" id="rental-summary" aria-labelledby="rental-summary-title" hidden>
        <h2 id="rental-summary-title">Order Summary</h2>
        <dl>
          <div>
            <dt>Stall</dt>
            <dd id="summary-stall">—</dd>
          </div>
          <div>
            <dt>Scooter</dt>
            <dd id="summary-scooter">—</dd>
          </div>
          <div>
            <dt>Total duration</dt>
            <dd id="summary-duration">—</dd>
          </div>
          <div>
            <dt>Rental source</dt>
            <dd id="summary-source">—</dd>
          </div>
          <div>
            <dt>Subtotal</dt>
            <dd id="summary-subtotal">—</dd>
          </div>
          <div>
            <dt>Discount</dt>
            <dd id="summary-discount">—</dd>
          </div>
          <div>
            <dt>Total cost</dt>
            <dd id="summary-total">—</dd>
          </div>
        </dl>
      </section>

      <div class="rental-actions">
        <button type="submit" class="btn-submit" id="rental-submit" <?= (!$scooters || ($bookingRef !== '' && !$booking)) ? 'disabled' : '' ?>>Start Rental</button>
        <a href="manager_dashboard.php" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </div>
</main>

<script src="js/main.js"></script>
<script src="js/start_rental.js"></script>
</body>
</html>
