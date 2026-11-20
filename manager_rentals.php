<?php
require_once 'includes/operations.php';
require_role(['manager','staff']);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'config/db.php';
require_once 'includes/booking_capacity.php';

$view = field('view', $_GET) === 'history' ? 'history' : 'active';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = integer_field(field('rental_id', $_POST), 1, 2147483647);
    $action = field('action', $_POST);
    $conn->begin_transaction();
    try {
        lock_rental_operations($conn);
        $row = sql_row($conn, 'SELECT r.*, s.stall_location FROM tbl_rentals r
            JOIN tbl_scooters s ON s.scooter_id = r.scooter_id WHERE r.rental_id = ? FOR UPDATE', 'i', $id);
        if (!$row || $row['status'] !== 'ongoing') throw new DomainException('This rental is no longer active.');
        $ref = $row['transaction_ref'];
        if ($action === 'return') {
            if (field('returned_scooter', $_POST) !== '1') throw new DomainException('Confirm the scooter was returned in person.');
            if ($row['id_type_deposited'] && field('returned_id', $_POST) !== '1') {
                throw new DomainException('Confirm that the customer’s deposited ID was returned.');
            }
            if ($row['pager_number'] && field('returned_pager', $_POST) !== '1') {
                throw new DomainException('Confirm the pager was returned.');
            }
            sql_run($conn, "UPDATE tbl_rentals SET status = 'completed', end_time = UTC_TIMESTAMP(),
                is_id_returned = ?, pager_status = IF(pager_number IS NULL, NULL, 'returned') WHERE rental_id = ?",
                'ii', (int) (bool) $row['id_type_deposited'], $id);
            sql_run($conn, "UPDATE tbl_scooters SET status = 'available' WHERE scooter_id = ? AND status = 'rented'",
                'i', (int) $row['scooter_id']);
            log_rental($conn, $id, 'completed', 'Scooter returned' . ($row['id_type_deposited'] ? ' · ID returned' : '') .
                ($row['pager_number'] ? ' · Pager returned' : ''));
            audit_action($conn, 'Completed rental ' . $ref);
            $message = 'Returned scooter for rental ' . $ref . '.';
        } elseif ($action === 'extend') {
            $minutes = integer_field(field('minutes', $_POST), 1, MAX_RENTAL_MINUTES);
            $cash = entered_money(field('cash', $_POST));
            if ($minutes === null || $cash === null) throw new DomainException('Enter valid extension minutes and cash received.');
            $price = rental_price($minutes, stored_money($row['discount_amount']) > 0);
            if ($cash < $price['total']) throw new DomainException('Cash must cover the extension total of ' . pesos($price['total']) . '.');
            $now = utc_sql(business_now());
            $start = max($now, $row['planned_end_time'] ?? $now);
            $end = utc_sql((new DateTimeImmutable($start, new DateTimeZone('UTC')))->modify("+$minutes minutes"));
            if (reservation_slots($conn, $row['stall_location'], $start, $end, null, $id) < 1) {
                throw new DomainException('A reservation needs this scooter during the extension.');
            }
            $change = $cash - $price['total'];
            sql_run($conn, 'UPDATE tbl_rentals SET planned_end_time = ?, total_amount = COALESCE(total_amount,0) + ?,
                discount_amount = COALESCE(discount_amount,0) + ?, amount_paid = COALESCE(amount_paid,0) + ?,
                change_amount = COALESCE(change_amount,0) + ? WHERE rental_id = ?', 'sssssi', $end,
                decimal_amount($price['total']), decimal_amount($price['discount']), decimal_amount($cash), decimal_amount($change), $id);
            record_payment($conn, $id, null, 'extension', $price['total']);
            log_rental($conn, $id, 'extended', $minutes . ' min · ' . pesos($price['total']));
            audit_action($conn, 'Extended rental ' . $ref);
            $message = 'Rental extended. Change ' . pesos($change) . '.';
        } else {
            throw new DomainException('Choose a rental action.');
        }
        $conn->commit();
        redirect_to('manager_rentals.php' . ($action === 'return' ? '?view=history' : ''), $message);
    } catch (DomainException $e) {
        $conn->rollback();
        $error = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log($e->getMessage());
        $error = 'The rental could not be updated.';
    }
}

$filter = $view === 'history' ? "r.status <> 'ongoing'" : "r.status = 'ongoing'";
$rows = sql_rows($conn, "SELECT r.*, s.scooter_code, s.stall_location, b.booking_ref,
    COALESCE(r.customer_name, CONCAT_WS(' ',u.first_name,u.last_name)) AS rider,
    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), r.planned_end_time) AS seconds_remaining
    FROM tbl_rentals r JOIN tbl_scooters s ON s.scooter_id = r.scooter_id
    LEFT JOIN tbl_bookings b ON b.booking_id = r.booking_id
    LEFT JOIN tbl_users u ON u.user_id = r.customer_id
    WHERE $filter ORDER BY r.rental_id DESC");
$active = 'rentals';
$title = 'Manage Rentals';
$subtitle = 'Monitor timers, extend rides, and record scooter, ID, and pager returns.';
require 'includes/workspace_header.php';
?>
<nav class="ops-actions" aria-label="Rental views">
  <a class="ops-filter" href="?view=active" <?= $view === 'active' ? 'aria-current="page"' : '' ?>>Current Rentals</a>
  <a class="ops-filter" href="?view=history" <?= $view === 'history' ? 'aria-current="page"' : '' ?>>Rental History</a>
  <a class="btn-submit" href="manager_start_rental.php">Start New Rental</a>
</nav>
<?php if (!$rows): ?><p class="ops-card"><?= $view === 'history' ? 'No completed rentals yet.' : 'No active rentals.' ?></p><?php endif; ?>
<?php foreach ($rows as $row): ?>
  <?php
  $id = (int) $row['rental_id'];
  $remaining = $row['seconds_remaining'];
  $logs = sql_rows($conn, "SELECT l.*, CONCAT_WS(' ',u.first_name,u.last_name) AS staff
      FROM tbl_rental_logs l LEFT JOIN tbl_users u ON u.user_id = l.logged_by_staff
      WHERE l.rental_id = ? ORDER BY l.log_id DESC", 'i', $id);
  ?>
  <section class="ops-card">
    <h2><?= h($row['scooter_code']) ?> · <?= h($row['rider'] ?: 'Customer') ?></h2>
    <p><?= h($row['transaction_ref']) ?> · <?= h($row['stall_location']) ?> ·
      <?= $row['rental_source'] === 'reservation' || $row['booking_ref'] ? 'Reservation' : 'Walk-in' ?>
      <?= $row['booking_ref'] ? ' · ' . h($row['booking_ref']) : '' ?></p>
    <p>Started <?= h(local_time($row['start_time'])) ?> · Total <?= pesos(stored_money($row['total_amount'])) ?>
      <?php if ($row['pager_number']): ?> · Pager <?= h($row['pager_number']) ?><?php endif; ?></p>
    <?php if ($view === 'active'): ?>
      <p><strong class="rental-time <?= $remaining !== null && $remaining <= 0 ? 'rental-time-expired' : '' ?>"
        <?= $remaining !== null && $remaining > 0 ? 'data-remaining-seconds="' . (int) $remaining . '"' : '' ?>>
        <?php if ($remaining === null): ?>No deadline recorded
        <?php elseif ($remaining <= 0): ?>Time up
        <?php else: ?><?= sprintf('%02d:%02d:%02d', intdiv($remaining, 3600), intdiv($remaining % 3600, 60), $remaining % 60) ?>
        <?php endif; ?></strong></p>
      <details>
        <summary>Extend Rental</summary>
        <form class="ops-extension" method="post" data-discount="<?= stored_money($row['discount_amount']) > 0 ? DISCOUNT_PERCENT : 0 ?>"
              data-hourly-rate="<?= HOURLY_RATE ?>" data-half-hour-rate="<?= HALF_HOUR_RATE ?>">
          <?php csrf_input(); ?><input type="hidden" name="rental_id" value="<?= $id ?>"><input type="hidden" name="action" value="extend">
          <div class="ops-fields">
            <label>Additional minutes<input class="form-control" type="number" name="minutes" min="1" max="<?= MAX_RENTAL_MINUTES ?>" required></label>
            <label>Cash received (₱)<input class="form-control" type="number" name="cash" min="0" max="9999999.99" step=".01" required></label>
          </div>
          <p class="extension-total" role="status">Extensions are billed separately at the same rental rates.</p>
          <button class="btn-submit">Save Extension</button>
        </form>
      </details>
      <details>
        <summary>End Rental and Record Returns</summary>
        <form method="post">
          <?php csrf_input(); ?><input type="hidden" name="rental_id" value="<?= $id ?>"><input type="hidden" name="action" value="return">
          <label class="ops-check"><input type="checkbox" name="returned_scooter" value="1" required> Scooter returned</label>
          <?php if ($row['id_type_deposited']): ?><label class="ops-check"><input type="checkbox" name="returned_id" value="1" required> <?= h($row['id_type_deposited']) ?> returned to customer</label><?php endif; ?>
          <?php if ($row['pager_number']): ?><label class="ops-check"><input type="checkbox" name="returned_pager" value="1" required> Pager <?= h($row['pager_number']) ?> returned</label><?php endif; ?>
          <button class="btn-submit">Complete Return</button>
        </form>
      </details>
    <?php else: ?><p><strong><?= h(ucfirst($row['status'])) ?></strong> · <?= h(local_time($row['end_time'])) ?></p><?php endif; ?>
    <details>
      <summary>Rental Log</summary>
      <?php if (!$logs): ?><p>No events recorded.</p><?php endif; ?>
      <?php foreach ($logs as $log): ?>
        <p class="ops-log"><?= h(local_time($log['created_at'])) ?> · <?= h($log['staff'] ?: 'Staff') ?> · <?= h(ucfirst($log['action_status'])) ?><br><?= h($log['notes']) ?></p>
      <?php endforeach; ?>
    </details>
  </section>
<?php endforeach; ?>
<script src="js/rental_countdown.js"></script>
<script>
for (const form of document.querySelectorAll('.ops-extension')) {
  const input = form.elements.minutes;
  input.addEventListener('input', () => {
    const halfHours = Math.ceil(input.valueAsNumber / 30);
    const subtotal = Math.floor(halfHours / 2) * Number(form.dataset.hourlyRate) + (halfHours % 2) * Number(form.dataset.halfHourRate);
    const total = subtotal * (100 - Number(form.dataset.discount)) / 100;
    form.querySelector('.extension-total').textContent = input.validity.valid
      ? 'Extension cost ' + new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(total)
      : 'Enter the additional minutes to see the extension cost.';
  });
}
</script>
<?php require 'includes/workspace_footer.php'; ?>
