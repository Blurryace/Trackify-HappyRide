<?php
require_once "includes/operations.php";
require_role('manager');
require_once "config/db.php";

$active = 'accounts';
$error = '';
$success = flash_message() ?? field('success', $_GET);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = integer_field(field('user_id', $_POST), 1, 2147483647);
    $status = field('status', $_POST);
    if (!$id || $id === (int) $_SESSION['user_id'] || !in_array($status, ['active','disabled'], true)) {
        $error = 'Choose another account and a valid status.';
    } else {
        $conn->begin_transaction();
        try {
            $update = sql_run($conn, 'UPDATE tbl_users SET account_status = ? WHERE user_id = ?', 'si', $status, $id);
            if ($update->affected_rows) audit_action($conn, 'Set account #' . $id . ' to ' . $status);
            $conn->commit();
            redirect_to('manager_manage_accounts.php', 'Account status saved.');
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            $error = 'The account status could not be saved.';
        }
    }
}

// Fetch all accounts from database
$users_result = $conn->query("SELECT * FROM tbl_users ORDER BY user_id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Accounts — TRACKIFY</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/dashboard.css">
<style>
  .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; text-transform: capitalize; }
  .badge-staff { background: #fef3c7; color: #d97706; }
  .badge-manager { background: #e0e7ff; color: #3730a3; }
  .badge-customer { background: #dcfce7; color: #15803d; }
  .badge-active { background: #dcfce7; color: #15803d; }
  .badge-disabled { background: #fee2e2; color: #b91c1c; }
  .btn-sm { padding: 6px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; text-decoration: none; display: inline-block; }
  .btn-enable { background: #22c55e; color: #ffffff; }
  .btn-disable { background: #ef4444; color: #ffffff; }
  .text-muted { color: #94a3b8; font-size: 13px; font-weight: 500; }
  .alert-error { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
  .alert-success { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
</style>
</head>
<body>

<?php include "includes/navbar_manager.php"; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1>Manage Accounts</h1>
      <p class="subtitle">Create staff accounts and control access for existing users.</p>
    </div>
  </div>

  <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <div class="table-card">
    <div class="table-toolbar">
      <input type="text" class="search-input" id="accountSearch" placeholder="Search by name or email...">
      <a href="manager_add_account.php" class="btn-add-scooter">➕ Add Account</a>
    </div>

    <table class="data-table" id="accountsTable">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($users_result && $users_result->num_rows > 0): ?>
          <?php while ($u = $users_result->fetch_assoc()): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars(($u['first_name'] ?? '') . ' ' . (!empty($u['middle_name']) ? $u['middle_name'] . '. ' : '') . ($u['last_name'] ?? '')) ?></strong>
              </td>
              <td><?= htmlspecialchars($u['email']) ?></td>
              <td>
                <?php 
                  $role = strtolower($u['role'] ?? 'customer');
                  $roleBadgeClass = ($role === 'manager') ? 'badge-manager' : (($role === 'staff') ? 'badge-staff' : 'badge-customer');
                ?>
                <span class="badge <?= $roleBadgeClass ?>"><?= htmlspecialchars($role) ?></span>
              </td>
              <td>
                <?php $status = strtolower($u['account_status'] ?? 'active'); ?>
                <span class="badge <?= $status === 'active' ? 'badge-active' : 'badge-disabled' ?>">
                  <?= htmlspecialchars($status) ?>
                </span>
              </td>
              <td>
                <?php if ((int)$u['user_id'] === (int)$_SESSION['user_id']): ?>
                  <span class="text-muted">Current User</span>
                <?php else: ?>
                  <form method="post">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="user_id" value="<?= (int) $u['user_id'] ?>">
                    <button class="btn-sm <?= $status === 'active' ? 'btn-disable' : 'btn-enable' ?>" name="status"
                      value="<?= $status === 'active' ? 'disabled' : 'active' ?>"><?= $status === 'active' ? 'Disable' : 'Enable' ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" style="text-align: center; color: #64748b; padding: 30px;">No accounts found.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<script>
document.getElementById('accountSearch')?.addEventListener('keyup', function() {
  const filter = this.value.toLowerCase();
  const rows = document.querySelectorAll('#accountsTable tbody tr');
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(filter) ? '' : 'none';
  });
});
</script>
<script src="js/main.js"></script>
</body>
</html>