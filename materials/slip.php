<?php
/**
 * Printable Goods Received / Store Issue voucher for one slip (batch).
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

mat_require_login();

$batch = preg_replace('/[^A-Z0-9-]/', '', strtoupper((string)($_GET['batch'] ?? '')));
$st = $pdo->prepare(
    "SELECT t.*, i.code, i.description, i.unit, u.full_name AS user_name, u.job_title AS user_title
     FROM mat_transactions t
     JOIN mat_items i ON i.id = t.item_id
     LEFT JOIN mat_users u ON u.id = t.user_id
     WHERE t.batch = :b AND t.batch <> ''
     ORDER BY t.id"
);
$st->execute([':b' => $batch]);
$rows = $st->fetchAll();

if (!$rows) {
    mat_flash('error', 'That slip could not be found.');
    mat_redirect(MAT_URL . '/transactions.php');
}

$h = $rows[0];
$titles = ['in' => 'Goods Received Voucher', 'out' => 'Store Issue Voucher', 'adjust' => 'Stock Count Correction'];

mat_header($titles[$h['txn_type']] ?? 'Slip', $h['txn_type'] === 'in' ? 'receive' : ($h['txn_type'] === 'out' ? 'issue' : 'transactions'));
?>
<div class="no-print list-head">
  <a href="transactions.php" class="link">← Movement history</a>
  <div class="actions">
    <?php if (!empty($_GET['new']) && mat_can_edit()): ?>
      <a class="btn btn-ghost btn-sm" href="move.php?type=<?= $h['txn_type'] === 'adjust' ? 'count' : e($h['txn_type']) ?>">＋ Another <?= $h['txn_type'] === 'in' ? 'receipt' : ($h['txn_type'] === 'out' ? 'issue' : 'count') ?></a>
    <?php endif; ?>
    <button class="btn btn-primary btn-sm" onclick="window.print()">🖨 Print voucher</button>
  </div>
</div>

<article class="card voucher">
  <header class="voucher-head">
    <div>
      <h2><?= e(APP_NAME) ?></h2>
      <p class="muted">Materials &amp; Warehouse</p>
    </div>
    <div class="right">
      <h3><?= e($titles[$h['txn_type']] ?? 'Slip') ?></h3>
      <p class="mono"><?= e($h['batch']) ?></p>
    </div>
  </header>

  <dl class="voucher-meta">
    <div><dt>Date</dt><dd><?= mat_date($h['txn_date'], false) ?></dd></div>
    <?php if ($h['txn_type'] !== 'adjust'): ?><div><dt><?= $h['txn_type'] === 'in' ? 'How it arrived' : 'How it was collected' ?></dt><dd><?= e(mat_reason_label($h['reason'])) ?></dd></div><?php endif; ?>
    <?php if ($h['party']): ?><div><dt><?= $h['txn_type'] === 'in' ? 'Received from' : 'Collected by' ?></dt><dd><?= e($h['party']) ?></dd></div><?php endif; ?>
    <?php if ($h['department']): ?><div><dt>Department</dt><dd><?= e($h['department']) ?></dd></div><?php endif; ?>
    <?php if ($h['reference']): ?><div><dt>Reference</dt><dd><?= e($h['reference']) ?></dd></div><?php endif; ?>
    <?php if ($h['authorized_by']): ?><div><dt>Authorized by</dt><dd><?= e($h['authorized_by']) ?></dd></div><?php endif; ?>
    <div><dt>Recorded by</dt><dd><?= e($h['user_name'] ?? '—') ?></dd></div>
  </dl>

  <table class="table">
    <thead><tr><th>#</th><th>Code</th><th>Description</th><th class="num">Quantity</th><th>Unit</th><th class="num">Balance after</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $n => $r): ?>
        <tr>
          <td><?= $n + 1 ?></td>
          <td class="mono"><a href="item.php?id=<?= (int)$r['item_id'] ?>"><?= e($r['code']) ?></a></td>
          <td><?= e($r['description']) ?></td>
          <td class="num"><strong><?= $r['txn_type'] === 'adjust' && $r['quantity'] > 0 ? '+' : '' ?><?= qty($r['txn_type'] === 'adjust' ? $r['quantity'] : abs($r['quantity'])) ?></strong></td>
          <td><?= e($r['unit']) ?></td>
          <td class="num"><?= qty($r['balance_after']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($h['notes']): ?><p class="voucher-notes"><strong>Notes:</strong> <?= e($h['notes']) ?></p><?php endif; ?>

  <div class="signatures">
    <div><span class="sig-line"></span><?= $h['txn_type'] === 'out' ? 'Issued by (Materials)' : 'Received by (Materials)' ?><br><small><?= e($h['user_name'] ?? '') ?></small></div>
    <div><span class="sig-line"></span><?= $h['txn_type'] === 'out' ? 'Collected by' : 'Delivered by' ?><br><small><?= e($h['party']) ?></small></div>
    <div><span class="sig-line"></span>Authorized by<br><small><?= e($h['authorized_by']) ?></small></div>
  </div>
</article>
<?php mat_footer();
