<?php
require_once "config/db.php";

if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] === 'customer' ? 'customer_dashboard.php' : 'manager_dashboard.php'));
    exit;
}

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    if ($first_name === '' || $middle_name === '' || $last_name === '' || $email === '' || $password === '') {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        $check = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error = "An account with that email already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO tbl_users (first_name, middle_name, last_name, email, password, role, account_status) VALUES (?, ?, ?, ?, ?, 'customer', 'active')");
            $stmt->bind_param("sssss", $first_name, $middle_name, $last_name, $email, $hashed);
            if ($stmt->execute()) {
                $success = "Account created successfully! You may now log in.";
            } else {
                $error = "Something went wrong. Please try again.";
            }
            $stmt->close();
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
<title>Create Account — TRACKIFY</title>
<link rel="stylesheet" href="css/style.css">
<style>
  body {
    margin: 0;
    padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: url('images/happyride_logo.jpg') no-repeat center center fixed;
    background-size: cover;
    position: relative;
  }
  body::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.70);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
    z-index: 1;
  }
  .login-container {
    position: relative;
    z-index: 2;
    width: 100%;
    max-width: 480px;
    padding: 20px;
  }
  .brand-logo {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    color: #ffffff;
    font-size: 22px;
    font-weight: 800;
    letter-spacing: 1px;
    margin-bottom: 24px;
    text-decoration: none;
  }
  .brand-logo svg {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    stroke: #ffffff;
  }
  .login-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 40px 36px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
  }
  .login-card h2 { font-size: 24px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0; text-align: center; }
  .login-card .subtitle { font-size: 14px; color: #64748b; margin: 0 0 24px 0; text-align: center; }
  .alert-error { background: #fee2e2; border: 1px solid #f87171; color: #991b1b; padding: 10px 14px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; text-align: center; }
  .alert-success { background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 10px 14px; border-radius: 8px; margin-bottom: 18px; font-size: 13px; text-align: center; }
  .alert-success a { color: #15803d; font-weight: 700; text-decoration: underline; }
  .form-group { margin-bottom: 18px; }
  .form-group label { display: block; font-size: 13px; font-weight: 600; color: #334155; margin-bottom: 6px; }
  .input-wrapper { position: relative; }
  .form-control { width: 100%; padding: 12px 14px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 10px; background-color: #f8fafc; box-sizing: border-box; outline: none; transition: all 0.2s ease; }
  .form-control:focus { border-color: #0f172a; background-color: #ffffff; box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.1); }
  .toggle-password { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #64748b; font-size: 12px; font-weight: 600; cursor: pointer; }
  .btn-submit { width: 100%; padding: 12px; font-size: 15px; font-weight: 600; color: #ffffff; background-color: #0f172a; border: none; border-radius: 10px; cursor: pointer; margin-top: 10px; transition: background-color 0.2s ease; }
  .btn-submit:hover { background-color: #1e293b; }
  .signup-text { text-align: center; font-size: 13px; color: #64748b; margin-top: 20px; }
  .signup-text a { color: #ef4444; font-weight: 600; text-decoration: none; }
  
  .name-row { display: flex; gap: 12px; }
  .name-row .col-first { flex: 2; }
  .name-row .col-mi { flex: 1; max-width: 80px; }
  .name-row .col-last { flex: 2; }
</style>
</head>
<body>
<div class="login-container">
  <a href="index.php" class="brand-logo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="6" cy="18" r="2"></circle>
      <circle cx="18" cy="18" r="2"></circle>
      <path d="M6 18h8l3-10h-6"></path>
      <path d="M12 8V4h3"></path>
    </svg>
    <span>TRACKIFY</span>
  </a>
  <div class="login-card">
    <h2>Create Account</h2>
    <p class="subtitle">Sign up to start booking scooters</p>
    
    <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert-success"><?= $success ?> <br><br><a href="login.php">Go to Login</a></div><?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" action="signup.php">
      <div class="name-row">
        <div class="form-group col-first">
          <label for="first_name">First Name</label>
          <input type="text" id="first_name" name="first_name" class="form-control" required>
        </div>
        <div class="form-group col-mi">
          <label for="middle_name">M.I.</label>
          <input type="text" id="middle_name" name="middle_name" class="form-control" maxlength="2" required>
        </div>
        <div class="form-group col-last">
          <label for="last_name">Last Name</label>
          <input type="text" id="last_name" name="last_name" class="form-control" required>
        </div>
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" class="form-control" required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-wrapper">
          <input type="password" id="password" name="password" class="form-control" minlength="8" required>
          <button type="button" class="toggle-password" data-target="#password">Show</button>
        </div>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" class="form-control" minlength="8" required>
      </div>
      <button type="submit" class="btn-submit">Create Account</button>
    </form>
    <?php endif; ?>

    <div class="signup-text">Already have an account? <a href="login.php">Log in</a></div>
  </div>
</div>
<script src="js/main.js"></script>
</body>
</html>