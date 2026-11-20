<?php
require_once 'includes/operations.php';
require_login();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once 'config/db.php';

$id = (int) $_SESSION['user_id'];
$user = sql_row($conn, 'SELECT * FROM tbl_users WHERE user_id = ?', 'i', $id);
if (!$user) redirect_to('logout.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $first = field('first_name', $_POST);
    $last = field('last_name', $_POST);
    $middle = field('middle_name', $_POST);
    if (!preg_match('/^[^\p{Cc}]{1,50}$/uD', $first) || !preg_match('/^[^\p{Cc}]{1,50}$/uD', $last)
        || !preg_match('/^[^\p{Cc}]{0,50}$/uD', $middle)) {
        $error = 'Enter valid names of up to 50 characters each.';
    } else {
        $conn->begin_transaction();
        try {
            sql_run($conn, 'UPDATE tbl_users SET first_name = ?, middle_name = ?, last_name = ? WHERE user_id = ?',
                'sssi', $first, $middle, $last, $id);
            audit_action($conn, 'Updated own profile');
            $conn->commit();
            $_SESSION['first_name'] = $first;
            $_SESSION['last_name'] = $last;
            redirect_to('profile.php', 'Profile updated.');
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log($e->getMessage());
            $error = 'The profile could not be updated.';
        }
    }
}
$active = 'profile';
$title = 'My Profile';
require 'includes/workspace_header.php';
?>
<section class="ops-card">
  <p><?= h($user['email']) ?> · <?= h(ucfirst($user['role'])) ?></p>
  <form method="post">
    <?php csrf_input(); ?>
    <div class="ops-fields">
      <label>First name<input class="form-control" name="first_name" maxlength="50" value="<?= h($first ?? $user['first_name']) ?>" required></label>
      <label>Middle name<input class="form-control" name="middle_name" maxlength="50" value="<?= h($middle ?? $user['middle_name']) ?>"></label>
      <label>Last name<input class="form-control" name="last_name" maxlength="50" value="<?= h($last ?? $user['last_name']) ?>" required></label>
    </div>
    <button class="btn-submit">Save Profile</button>
  </form>
</section>
<?php require 'includes/workspace_footer.php'; ?>
