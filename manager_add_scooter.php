<?php
require_once "config/db.php";
require_once "includes/operations.php";
require_role(['manager','staff']);
$active = 'inventory';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $code = strtoupper(field('scooter_code', $_POST));
    $stall = field('stall', $_POST);
    $condition = field('condition', $_POST);
    if (!preg_match('/^[A-Z0-9-]{1,20}$/D', $code) || !in_array($stall, ['Upper Ground','Lower Ground'], true)
        || !in_array($condition, ['good','fair','needs repair'], true)) {
        $error = 'Enter a valid scooter code, stall, and condition.';
    } else {
        $conn->begin_transaction();
        try {
            lock_rental_operations($conn);
            sql_run($conn, "INSERT INTO tbl_scooters (scooter_code, stall_location, condition_status, status)
                VALUES (?, ?, ?, 'available')", 'sss', $code, $stall, $condition);
            audit_action($conn, 'Added scooter ' . $code);
            $conn->commit();
            redirect_to('manager_scooter_inventory.php', 'Scooter added.');
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error = $e->getCode() === 1062 ? 'That scooter code is already in use.' : 'The scooter could not be added.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Scooter — TRACKIFY</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/dashboard.css">
</head>
<body>

<?php include "includes/navbar_manager.php"; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1>Add Scooter</h1>
      <p class="subtitle">Register a new scooter into the inventory module.</p>
    </div>
  </div>

  <div class="form-card">
    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form action="" method="POST">
      <?php csrf_input(); ?>
      <div class="form-group">
        <label class="form-label" for="scooter_code">Scooter Code *</label>
        <input type="text" id="scooter_code" name="scooter_code" class="form-control" placeholder="e.g. SC-006" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="stall">Stall Location *</label>
        <select id="stall" name="stall" class="form-control" required>
          <option value="">Select stall...</option>
          <option value="Upper Ground">Upper Ground</option>
          <option value="Lower Ground">Lower Ground</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label" for="condition">Condition</label>
        <select id="condition" name="condition" class="form-control">
          <option value="good">Good</option>
          <option value="needs repair">Needs Repair</option>
          <option value="fair">Fair</option>
        </select>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-submit">Add Scooter</button>
        <a href="manager_scooter_inventory.php" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </div>
</main>

<script src="js/main.js"></script>
</body>
</html>