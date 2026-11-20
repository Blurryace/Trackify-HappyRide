<?php
require_once 'includes/operations.php';
require_role(['manager','staff']);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'config/db.php';

$id = integer_field(field('id', $_GET), 1, 2147483647);
$scooter = sql_row($conn, 'SELECT * FROM tbl_scooters WHERE scooter_id = ?', 'i', $id);
if (!$scooter) redirect_to('manager_scooter_inventory.php', 'Scooter not found.');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $status = field('status', $_POST);
    $condition = field('condition', $_POST);
    $notes = field('notes', $_POST);
    $maintenance = field('maintenance_notes', $_POST);
    $recordService = field('record_service', $_POST) === '1';
    if (!in_array($status, ['available','rented','maintenance'], true)
        || !in_array($condition, ['good','fair','needs repair'], true)
        || strlen($notes) > 8000 || strlen($maintenance) > 8000) {
        $error = 'Enter valid scooter information and limit notes to 8,000 bytes.';
    } else {
        $conn->begin_transaction();
        try {
            lock_rental_operations($conn);
            $current = sql_row($conn, 'SELECT status FROM tbl_scooters WHERE scooter_id = ? FOR UPDATE', 'i', $id);
            if (!$current) throw new DomainException('This scooter was removed.');
            $activeRental = sql_row($conn, "SELECT rental_id FROM tbl_rentals WHERE scooter_id = ? AND status = 'ongoing' LIMIT 1", 'i', $id);
            if ($activeRental && ($status !== $current['status'] || $recordService)) throw new DomainException('Return the scooter before changing its status or recording maintenance.');
            sql_run($conn, 'UPDATE tbl_scooters SET status = ?, condition_status = ?, notes = ?, maintenance_notes = ?,
                last_maintenance = IF(? = 1, UTC_TIMESTAMP(), last_maintenance) WHERE scooter_id = ?',
                'ssssii', $status, $condition, $notes, $maintenance, (int) $recordService, $id);
            if ($recordService) {
                sql_run($conn, 'INSERT INTO tbl_maintenance_logs (scooter_id, scooter_code, notes, recorded_by, recorded_at)
                    VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', 'issi', $id, $scooter['scooter_code'], $maintenance, (int) $_SESSION['user_id']);
            }
            audit_action($conn, ($recordService ? 'Recorded maintenance for ' : 'Updated scooter ') . $scooter['scooter_code']);
            $conn->commit();
            redirect_to('manager_scooter_profile.php?id=' . $id, 'Scooter profile saved.');
        } catch (DomainException $e) {
            $conn->rollback();
            $error = $e->getMessage();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log($e->getMessage());
            $error = 'The scooter profile could not be updated.';
        }
    }
}

$history = sql_rows($conn, 'SELECT transaction_ref, start_time, end_time, status FROM tbl_rentals
    WHERE scooter_id = ? ORDER BY rental_id DESC LIMIT 50', 'i', $id);
$serviceHistory = sql_rows($conn, "SELECT m.notes, m.recorded_at, CONCAT_WS(' ',u.first_name,u.last_name) AS staff
    FROM tbl_maintenance_logs m JOIN tbl_users u ON u.user_id = m.recorded_by WHERE m.scooter_id = ?
    ORDER BY m.maintenance_id DESC LIMIT 50", 'i', $id);
$activeRental = in_array('ongoing', array_column($history, 'status'), true);
$active = 'inventory';
$title = 'Scooter ' . $scooter['scooter_code'];
$subtitle = $scooter['stall_location'];
require 'includes/workspace_header.php';
?>
<div class="ops-actions"><a href="manager_scooter_inventory.php">Back to Scooter Inventory</a></div>
<section class="ops-card">
  <h2>Condition and Maintenance</h2>
  <p><?= (int) $scooter['times_rented'] ?> rental(s) · Last used <?= h(local_time($scooter['last_used'])) ?></p>
  <p>Last maintenance <?= h(local_time($scooter['last_maintenance'])) ?></p>
  <form method="post">
    <?php csrf_input(); ?>
    <div class="ops-fields">
      <label>Status
        <select class="form-control" name="status" <?= $activeRental ? 'disabled' : '' ?>>
          <?php foreach (['available'=>'Available','rented'=>'Rented','maintenance'=>'Under Maintenance'] as $key => $label): ?>
            <option value="<?= $key ?>" <?= $scooter['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($activeRental): ?><input type="hidden" name="status" value="<?= h($scooter['status']) ?>"><?php endif; ?>
      </label>
      <label>Condition
        <select class="form-control" name="condition">
          <?php foreach (['good','fair','needs repair'] as $option): ?>
            <option value="<?= $option ?>" <?= $scooter['condition_status'] === $option ? 'selected' : '' ?>><?= h(ucfirst($option)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Operational notes<textarea class="form-control" name="notes" maxlength="2000"><?= h($notes ?? $scooter['notes']) ?></textarea></label>
    <label>Maintenance notes<textarea class="form-control" name="maintenance_notes" maxlength="2000"><?= h($maintenance ?? $scooter['maintenance_notes']) ?></textarea></label>
    <?php if (!$activeRental): ?><label class="ops-check"><input type="checkbox" name="record_service" value="1"> Record maintenance performed now</label><?php endif; ?>
    <button class="btn-submit">Save Scooter Profile</button>
  </form>
</section>
<section class="ops-card">
  <h2>Maintenance History</h2>
  <?php if (!$serviceHistory): ?><p>No completed maintenance recorded yet.</p><?php endif; ?>
  <?php foreach ($serviceHistory as $service): ?>
    <p class="ops-log"><?= h(local_time($service['recorded_at'])) ?> · <?= h($service['staff']) ?><br><?= h($service['notes']) ?></p>
  <?php endforeach; ?>
</section>
<section class="ops-card">
  <h2>Recent Rental History</h2>
  <?php if (!$history): ?><p>No rental records for this scooter.</p><?php else: ?>
    <div class="ops-table"><table class="data-table">
      <thead><tr><th scope="col">Reference</th><th scope="col">Start</th><th scope="col">Return</th><th scope="col">Status</th></tr></thead>
      <tbody><?php foreach ($history as $rental): ?>
        <tr><td><?= h($rental['transaction_ref']) ?></td><td><?= h(local_time($rental['start_time'])) ?></td>
          <td><?= h(local_time($rental['end_time'])) ?></td><td><?= h(ucfirst($rental['status'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php require 'includes/workspace_footer.php'; ?>
