<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once "config/db.php";
require_once "includes/operations.php";
require_role(['manager','staff']);
$active = 'inventory';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    require_csrf();
    header('Content-Type: application/json');
    $scooterId = filter_var($_POST['scooter_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $status = $_POST['status'] ?? '';

    if ($scooterId === false || !in_array($status, ['available', 'rented', 'maintenance'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Choose a valid scooter and status.']);
        exit;
    }

    $conn->begin_transaction();
    try {
        lock_rental_operations($conn);
        $scooter = $conn->prepare("SELECT scooter_id FROM tbl_scooters WHERE scooter_id = ? FOR UPDATE");
        $scooter->bind_param("i", $scooterId);
        $scooter->execute();
        if (!$scooter->get_result()->fetch_assoc()) {
            throw new DomainException('That scooter is no longer in the inventory.');
        }

        $rental = $conn->prepare("SELECT 1 FROM tbl_rentals WHERE scooter_id = ? AND status = 'ongoing' LIMIT 1");
        $rental->bind_param("i", $scooterId);
        $rental->execute();
        if ($rental->get_result()->fetch_assoc()) {
            throw new DomainException('This scooter belongs to an active rental. Refresh the inventory for its current status.');
        }

        $update = $conn->prepare("UPDATE tbl_scooters SET status = ? WHERE scooter_id = ?");
        $update->bind_param("si", $status, $scooterId);
        $update->execute();
        audit_action($conn, 'Changed scooter #' . $scooterId . ' status to ' . $status);
        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (DomainException $e) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log('Trackify scooter status update failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'The scooter status could not be updated.']);
    }
    exit;
}

$result = $conn->query("SELECT s.*, EXISTS (SELECT 1 FROM tbl_rentals AS r
    WHERE r.scooter_id = s.scooter_id AND r.status = 'ongoing') AS has_active_rental
    FROM tbl_scooters AS s");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scooter Inventory — TRACKIFY</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/dashboard.css">
</head>
<body>

<?php include "includes/navbar_manager.php"; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1>Scooter Inventory</h1>
      <p class="subtitle">All registered scooters across every stall.</p>
    </div>
  </div>

  <?php if ($notice = flash_message()): ?><p role="status"><?= h($notice) ?></p><?php endif; ?>
  <div class="table-card">
    <div class="table-toolbar">
      <input type="text" class="search-input" id="scooterSearch" placeholder="Search by code or stall...">
      <label>Status
        <select class="status-select" id="status-filter">
          <option value="">All statuses</option><option value="available">Available</option>
          <option value="rented">Rented</option><option value="maintenance">Under Maintenance</option>
        </select>
      </label>
      <a href="manager_add_scooter.php" class="btn-add-scooter">➕ Add Scooter</a>
    </div>

    <?php if (!$result): ?>
      <div style="padding: 20px; color: #ef4444; font-weight: 600;">
        Database Error: <?= htmlspecialchars($conn->error) ?>
      </div>
    <?php else: ?>
    <table class="data-table" id="scooterTable">
      <thead>
        <tr>
          <th>Scooter Code</th>
          <th>Stall</th>
          <th>Status</th>
          <th>Condition</th>
          <th>Times Rented</th>
          <th>Last Maintenance</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($result->num_rows > 0): ?>
          <?php while ($row =$result->fetch_assoc()): ?>
            <?php 
              $id =$row['scooter_id'] ?? $row['id'] ?? $row['scooter_code'] ?? '';
              $code =$row['scooter_code'] ?? $row['code'] ?? $row['scooter_id'] ?? '';
              $stall =$row['stall'] ?? $row['stall_location'] ?? $row['location'] ?? 'Upper Ground';
              $status = strtolower($row['status'] ?? 'available');
              $condition =$row['condition_status'] ?? $row['condition'] ?? 'good';$timesRented = $row['times_rented'] ?? $row['rented_count'] ?? 0;
              $lastMaint =$row['last_maintenance'] ?? '—';
              if (empty($lastMaint))$lastMaint = '—';
            ?>
            <tr data-scooter-status="<?= h($status) ?>">
              <td><a href="manager_scooter_profile.php?id=<?= (int) $id ?>"><strong><?= h($code) ?></strong></a></td>
              <td><?= htmlspecialchars($stall) ?></td>
              <td>
                <?php if ($row['has_active_rental']): ?>
                  <span>Rented · Active session</span>
                <?php else: ?>
                  <select class="status-select scooter-status-change" aria-label="Status for <?= htmlspecialchars($code) ?>" data-id="<?= htmlspecialchars($id) ?>">
                    <option value="available" <?= $status === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="rented" <?= $status === 'rented' ? 'selected' : '' ?>>Rented</option>
                    <option value="maintenance" <?= $status === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                  </select>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($condition) ?></td>
              <td><?= htmlspecialchars($timesRented) ?></td>
              <td><?= htmlspecialchars($lastMaint) ?></td>
              <td>
                <?php if (!$row['has_active_rental']): ?>
                  <form action="delete_scooter.php" method="post" onsubmit="return confirm('Delete this scooter?');">
                    <?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int) $id ?>">
                    <button class="btn-delete">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr>
            <td colspan="7" style="text-align: center; color: #64748b; padding: 30px;">No scooters found in inventory.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</main>

<script>
const scooterSearch = document.getElementById('scooterSearch');
const statusFilter = document.getElementById('status-filter');
function filterScooterTable() {
  const search = scooterSearch.value.toLowerCase();
  for (const row of document.querySelectorAll('[data-scooter-status]')) {
    row.hidden = !row.textContent.toLowerCase().includes(search)
      || (statusFilter.value !== '' && row.dataset.scooterStatus !== statusFilter.value);
  }
}
scooterSearch.addEventListener('input', filterScooterTable);
statusFilter.addEventListener('change', filterScooterTable);

document.querySelectorAll('.scooter-status-change').forEach(selectElement => {
  let savedStatus = selectElement.value;
  selectElement.addEventListener('change', async function() {
    const scooterId = this.dataset.id;
    const newStatus = this.value;

    const formData = new FormData();
    formData.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    formData.append('action', 'update_status');
    formData.append('scooter_id', scooterId);
    formData.append('status', newStatus);

    this.disabled = true;
    try {
      const response = await fetch(window.location.href, { method: 'POST', body: formData });
      const data = await response.json();
      if (!data.success) throw new Error(data.error || 'The scooter status could not be updated.');
      savedStatus = newStatus;
      this.closest('tr').dataset.scooterStatus = newStatus;
      filterScooterTable();
    } catch (error) {
      this.value = savedStatus;
      alert(error.message);
    } finally {
      this.disabled = false;
    }
  });
});
</script>
<script src="js/main.js"></script>
</body>
</html>
