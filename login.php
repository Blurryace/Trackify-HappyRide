<?php
require_once "config/db.php";

if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] === 'customer' ? 'customer_dashboard.php' : 'manager_dashboard.php'));
    exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, email, password, role, account_status FROM tbl_users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if ($user['account_status'] === 'disabled') {
                $error = "Your account has been disabled. Please contact an administrator.";
            } elseif (password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name']  = $user['last_name'];
                $_SESSION['email']      = $user['email'];
                $_SESSION['role']       = strtolower($user['role']);

                if ($_SESSION['role'] === 'customer') {
                    header("Location: customer_dashboard.php");
                } else {
                    header("Location: manager_dashboard.php");
                }
                exit;
            } else {
                $error = "Invalid email or password.";
            }
        } else {
            $error = "Invalid email or password.";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — TRACKIFY</title>
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
    max-width: 420px;
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

  .login-card h2 {
    font-size: 24px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px 0;
    text-align: center;
  }

  .login-card .subtitle {
    font-size: 14px;
    color: #64748b;
    margin: 0 0 24px 0;
    text-align: center;
  }

  .alert-error {
    background: #fee2e2;
    border: 1px solid #f87171;
    color: #991b1b;
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 18px;
    font-size: 13px;
    text-align: center;
  }

  .form-group {
    margin-bottom: 18px;
  }

  .form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
  }

  .input-wrapper {
    position: relative;
  }

  .form-control {
    width: 100%;
    padding: 12px 14px;
    font-size: 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    background-color: #f8fafc;
    box-sizing: border-box;
    outline: none;
    transition: all 0.2s ease;
  }

  .form-control:focus {
    border-color: #0f172a;
    background-color: #ffffff;
    box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.1);
  }

  .toggle-password {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
  }

  .btn-submit {
    width: 100%;
    padding: 12px;
    font-size: 15px;
    font-weight: 600;
    color: #ffffff;
    background-color: #0f172a;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    margin-top: 10px;
    transition: background-color 0.2s ease;
  }

  .btn-submit:hover {
    background-color: #1e293b;
  }

  .meta-links {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    margin-top: 12px;
    margin-bottom: 20px;
  }

  .meta-links a {
    color: #64748b;
    text-decoration: none;
  }

  .meta-links a:hover {
    text-decoration: underline;
  }

  .signup-text {
    text-align: center;
    font-size: 13px;
    color: #64748b;
    margin-top: 20px;
  }

  .signup-text a {
    color: #ef4444;
    font-weight: 600;
    text-decoration: none;
  }
</style>
</head>
<body>

<div class="login-container">
  <div class="brand-logo">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="6" cy="18" r="2"></circle>
      <circle cx="18" cy="18" r="2"></circle>
      <path d="M6 18h8l3-10h-6"></path>
      <path d="M12 8V4h3"></path>
    </svg>
    <span>TRACKIFY</span>
  </div>

  <div class="login-card">
    <h2>Welcome Back</h2>
    <p class="subtitle">Please sign in to your account</p>

    <?php if ($error): ?>
      <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" class="form-control" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>

      <div class="form-group">
        <label for="login-password">Password</label>
        <div class="input-wrapper">
          <input type="password" id="login-password" name="password" class="form-control" placeholder="••••••••" required>
          <button type="button" class="toggle-password" data-target="#login-password">Show</button>
        </div>
      </div>

      <div class="meta-links">
        <a href="#">Forgot Password?</a>
        <a href="#">Contact administrator</a>
      </div>

      <button type="submit" class="btn-submit">Login</button>
    </form>

    <div class="signup-text">
      New customer? <a href="signup.php">Create an account</a>
    </div>
  </div>
</div>

<script src="js/main.js"></script>
</body>
</html>