<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

mat_require_admin();

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = (int)$pdo->query('SELECT COUNT(*) FROM mat_audit_log')->fetchColumn();
$offset = ($page - 1) * $perPage;
$rows = $pdo->query(
    "SELECT a.*, u.full_name FROM mat_audit_log a LEFT JOIN mat_users u ON u.id = a.user_id
     ORDER BY a.id DESC LIMIT $perPage OFFSET $offset"
)->fetchAll();

$labels = [
    'setup' => 'System set up', 'user_add' => 'Added user', 'user_edit' => 'Edited user', 'user_reset_pw' => 'Reset password',
    'user_disable' => 'Disabled user', 'user_enable' => 'Enabled user', 'user_delete' => 'Deleted user',
    'password_change' => 'Changed own password', 'item_create' => 'Created item', 'item_edit' => 'Edited item',
    'item_archive' => 'Archived item', 'item_restore' => 'Restored item', 'import' => 'Imported spreadsheet',
    'category_add' => 'Added category', 'category_edit' => 'Edited category', 'category_delete' => 'Deleted category',
];

mat_header('Activity Log', 'audit');
?>
<p class="muted">Administrative changes (users, items, imports). Stock movements are in <a class="link" href="<?= MAT_URL ?>/transactions.php">Movement History</a>.</p>
<div class="card table-card">
  <?php if (!$rows): ?><p class="empty">No activity yet.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="nowrap small" data-label="When"><?= mat_date($r['created_at']) ?></td>
          <td data-label="Who"><?= e($r['full_name'] ?? '—') ?></td>
          <td data-label="Action"><?= e($labels[$r['action']] ?? $r['action']) ?></td>
          <td class="muted small" data-label="Details"><?= e($r['details']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php mat_pager($total, $perPage, $page); ?>
<?php mat_footer();
