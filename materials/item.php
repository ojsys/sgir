<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = mat_require_login();
$id = (int)($_GET['id'] ?? 0);

$st = $pdo->prepare('SELECT i.*, c.name AS category_name FROM mat_items i LEFT JOIN mat_categories c ON c.id = i.category_id WHERE i.id = :id');
$st->execute([':id' => $id]);
$item = $st->fetch();
if (!$item) {
    mat_flash('error', 'Item not found.');
    mat_redirect(MAT_URL . '/items.php');
}

$sum = $pdo->prepare(
    "SELECT SUM(CASE WHEN txn_type = 'in'  THEN quantity ELSE 0 END) AS total_in,
            SUM(CASE WHEN txn_type = 'out' THEN -quantity ELSE 0 END) AS total_out,
            MAX(CASE WHEN txn_type = 'out' THEN txn_date END) AS last_out,
            MAX(CASE WHEN txn_type = 'in'  THEN txn_date END) AS last_in
     FROM mat_transactions WHERE item_id = :id"
);
$sum->execute([':id' => $id]);
$s = $sum->fetch();

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$cnt = $pdo->prepare('SELECT COUNT(*) FROM mat_transactions WHERE item_id = :id');
$cnt->execute([':id' => $id]);
$total = (int)$cnt->fetchColumn();
$offset = ($page - 1) * $perPage;
$h = $pdo->prepare(
    "SELECT t.*, u.full_name AS user_name FROM mat_transactions t
     LEFT JOIN mat_users u ON u.id = t.user_id
     WHERE t.item_id = :id ORDER BY t.txn_date DESC, t.id DESC LIMIT $perPage OFFSET $offset"
);
$h->execute([':id' => $id]);
$history = $h->fetchAll();

$canEdit = mat_can_edit();
mat_header($item['code'], 'items');
?>
<a href="items.php" class="link back">← Inventory</a>

<section class="item-hero card">
  <div class="item-hero-main">
    <div class="muted small mono"><?= e($item['code']) ?> · <?= e($item['category_name'] ?? 'Uncategorised') ?></div>
    <h2><?= e($item['description']) ?></h2>
    <div class="chips">
      <?= mat_status_badge($item) ?>
      <?php if (!(int)$item['is_active']): ?><span class="badge badge-out">Archived</span><?php endif; ?>
      <?php if ($item['bin_location']): ?><span class="chip">📍 Bin <?= e($item['bin_location']) ?></span><?php endif; ?>
      <?php if ((float)$item['min_qty'] > 0): ?><span class="chip">Re-order at <?= qty($item['min_qty']) ?></span><?php endif; ?>
    </div>
    <?php if ($item['notes']): ?><p class="muted"><?= nl2br(e($item['notes'])) ?></p><?php endif; ?>
  </div>
  <div class="item-hero-qty">
    <span class="big-qty <?= 'q-' . mat_status($item)[0] ?>"><?= qty($item['quantity']) ?></span>
    <span class="muted"><?= e($item['unit']) ?> in store</span>
  </div>
</section>

<?php if ($canEdit && (int)$item['is_active']): ?>
<div class="action-row">
  <a class="btn btn-in btn-lg" href="move.php?type=in&item=<?= $id ?>">↓ Receive</a>
  <a class="btn btn-out btn-lg <?= $item['quantity'] <= 0 ? 'disabled' : '' ?>" href="move.php?type=out&item=<?= $id ?>">↑ Issue</a>
  <a class="btn btn-ghost btn-lg" href="move.php?type=count&item=<?= $id ?>">≈ Stock count</a>
  <a class="btn btn-ghost btn-lg" href="item-edit.php?id=<?= $id ?>">✎ Edit item</a>
</div>
<?php elseif ($canEdit): ?>
<div class="action-row"><a class="btn btn-ghost" href="item-edit.php?id=<?= $id ?>">✎ Edit / restore item</a></div>
<?php endif; ?>

<section class="stats stats-4">
  <div class="stat"><span class="stat-n"><?= qty($s['total_in'] ?? 0) ?></span><span class="stat-l">Total received</span></div>
  <div class="stat"><span class="stat-n"><?= qty($s['total_out'] ?? 0) ?></span><span class="stat-l">Total issued</span></div>
  <div class="stat"><span class="stat-n sm"><?= mat_date($s['last_in'], false) ?></span><span class="stat-l">Last received</span></div>
  <div class="stat"><span class="stat-n sm"><?= mat_date($s['last_out'], false) ?></span><span class="stat-l">Last issued</span></div>
</section>

<section class="card table-card">
  <div class="card-head pad">
    <h3>Stock card (movement history)</h3>
    <a class="btn btn-ghost btn-sm" href="export.php?what=transactions&item=<?= $id ?>">⤓ Export</a>
  </div>
  <?php if (!$history): ?>
    <p class="empty">No movements recorded for this item yet.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Type</th><th>How</th><th>Who / where</th><th class="num">Qty</th><th class="num">Balance</th><th class="hide-sm">By</th></tr></thead>
    <tbody>
    <?php foreach ($history as $t): ?>
      <tr>
        <td class="nowrap" data-label="Date"><?= mat_date($t['txn_date'], false) ?></td>
        <td data-label="Type"><?= mat_type_badge($t['txn_type']) ?></td>
        <td data-label="How"><?= e(mat_reason_label($t['reason'])) ?>
          <?php if ($t['batch']): ?><div><a class="small mono" href="slip.php?batch=<?= e($t['batch']) ?>"><?= e($t['batch']) ?></a></div><?php endif; ?>
        </td>
        <td data-label="Who">
          <?= e($t['party'] ?: '—') ?><?php if ($t['department']): ?> <span class="muted small">(<?= e($t['department']) ?>)</span><?php endif; ?>
          <?php if ($t['reference']): ?><div class="muted small">Ref: <?= e($t['reference']) ?></div><?php endif; ?>
          <?php if ($t['notes']): ?><div class="muted small"><?= e($t['notes']) ?></div><?php endif; ?>
        </td>
        <td class="num <?= $t['quantity'] < 0 ? 'txt-out' : 'txt-in' ?>" data-label="Qty"><strong><?= $t['quantity'] > 0 ? '+' : '' ?><?= qty($t['quantity']) ?></strong></td>
        <td class="num" data-label="Balance"><?= qty($t['balance_after']) ?></td>
        <td class="hide-sm muted small" data-label="By"><?= e($t['user_name'] ?? 'System') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php mat_pager($total, $perPage, $page); ?>
<?php mat_footer();
