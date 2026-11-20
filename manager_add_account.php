<?php
require_once "includes/operations.php";
require_role('manager');
require_once "config/db.php";

$active = 'accounts';
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $role        = $_POST['role'] ?? 'staff';
    $password    = $_POST['password'] ?? '';

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = "All required fields must be filled.";
    } elseif (!in_array($role, ['staff','manager','customer'], true) || strlen($password) < 8 || strlen($password) > 72) {
        $error = 'Choose a valid role and a password of 8 to 72 bytes.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "An account with that email already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $conn->begin_transaction();
            try {
                $created = sql_run($conn, "INSERT INTO tbl_users
                    (first_name, middle_name, last_name, email, password, role, account_status)
                    VALUES (?, ?, ?, ?, ?, ?, 'active')", 'ssssss', $first_name, $middle_name, $last_name, $email, $hashed, $role);
                audit_action($conn, 'Created ' . $role . ' account #' . $created->insert_id);
                $conn->commit();
                redirect_to('manager_manage_accounts.php', 'Account created.');
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $error = 'The account could not be created.';
            }
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Account — TRACKIFY</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/dashboard.css">
<style>
  .alert-error { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; max-width: 520px; }
  
  .form-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 32px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    max-width: 520px;
  }
  
  .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
  @media (max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
  
  .form-group { display: flex; flex-direction: column; gap: 6px; }
  .form-group label { font-size: 13px; font-weight: 600; color: #334155; }
  .form-control { width: 100%; padding: 10px 14px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 8px; background-color: #ffffff; box-sizing: border-box; outline: none; transition: border-color 0.2s; }
  .form-control:focus { border-color: #0f172a; box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.1); }
  
  .form-actions { display: flex; align-items: center; gap: 16px; margin-top: 24px; }
  .btn-submit { background-color: #2563eb; color: #ffffff; padding: 10px 22px; border: none; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: background 0.2s; }
  .btn-submit:hover { background-color: #1d4ed8; }
  .btn-cancel { color: #64748b; text-decoration: none; font-size: 14px; font-weight: 600; }
  .btn-cancel:hover { color: #0f172a; }
</style>
</head>
<body>

<?php include "includes/navbar_manager.php"; ?>

<main class="main-content">
  <div class="page-header">
    <div>
      <h1>Add Account</h1>
      <p class="subtitle">Create staff accounts and control access for existing users.</p>
    </div>
  </div>

  <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="form-card">
    <form method="POST" action="manager_add_account.php">
      <?php csrf_input(); ?>
      <div class="form-grid">
        <div class="form-group">
          <label for="first_name">First Name *</label>
          <input type="text" id="first_name" name="first_name" class="form-control" placeholder="e.g. Juan" required>
        </div>

        <div class="form-group">
          <label for="middle_name">M.I. (Optional)</label>
          <input type="text" id="middle_name" name="middle_name" class="form-control" placeholder="e.g. D" maxlength="2">
        </div>

        <div class="form-group">
          <label for="last_name">Last Name *</label>
          <input type="text" id="last_name" name="last_name" class="form-control" placeholder="e.g. Dela Cruz" required>
        </div>

        <div class="form-group">
          <label for="email">Email Address *</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="user@trackify.com" required>
        </div>

        <div class="form-group">
          <label for="role">Role *</label>
          <select id="role" name="role" class="form-control" required>
            <option value="staff">Staff</option>
            <option value="manager">Manager</option>
          </select>
        </div>

        <div class="form-group">
          <label for="password">Password *</label>
          <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-submit">Create Account</button>
        <a href="manager_manage_accounts.php" class="btn-cancel">Cancel</a>
      </div>
    </form>
  </div>
</main>

<script src="js/main.js"></script>
</body>
</html>