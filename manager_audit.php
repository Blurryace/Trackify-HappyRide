<?php
require_once 'includes/operations.php';
require_role('manager');
require_once 'config/db.php';
$before = integer_field(field('before', $_GET), 1, 2147483647);
$logs = sql_rows($conn, "SELECT a.*, CONCAT_WS(' ', u.first_name, u.last_name) AS actor
    FROM tbl_audit_logs a LEFT JOIN tbl_users u ON u.user_id = a.performed_by
    WHERE a.audit_id < COALESCE(?, 2147483647) ORDER BY a.audit_id DESC LIMIT 51", 'i', $before);
$more = count($logs) > 50;
if ($more) array_pop($logs);
$active = 'audit';
$title = 'Audit Log';
$subtitle = 'Review recorded account, reservation, rental, inventory, and sales activity.';
require 'includes/workspace_header.php';
?>
<section class="ops-card">
  <?php if (!$logs): ?><p>No activity has been logged.</p><?php else: ?>
    <div class="ops-table"><table class="data-table">
      <thead><tr><th scope="col">Time</th><th scope="col">User</th><th scope="col">Role</th><th scope="col">Activity</th></tr></thead>
      <tbody><?php foreach ($logs as $log): ?>
        <tr><td><?= h(local_time($log['timestamp'])) ?></td><td><?= h($log['actor'] ?: '—') ?></td>
          <td><?= h(ucfirst($log['performed_by_type'])) ?></td><td><?= h($log['action_taken']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php if ($more): ?><p><a class="ops-link" href="?before=<?= (int) end($logs)['audit_id'] ?>">Older activity</a></p><?php endif; ?>
  <?php endif; ?>
</section>
<?php require 'includes/workspace_footer.php'; ?>
