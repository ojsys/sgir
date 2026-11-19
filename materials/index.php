<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = mat_require_login();

$stats = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN quantity > 0 THEN 1 ELSE 0 END) AS in_stock,
            SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_stock,
            SUM(CASE WHEN quantity > 0 AND min_qty > 0 AND quantity <= min_qty THEN 1 ELSE 0 END) AS low_stock
     FROM mat_items WHERE is_active = 1"
)->fetch();

$since = date('Y-m-d', strtotime('-30 days'));
$st = $pdo->prepare(
    "SELECT SUM(CASE WHEN txn_type = 'in'  AND reason <> 'opening' THEN 1 ELSE 0 END) AS receipts,
            SUM(CASE WHEN txn_type = 'out' THEN 1 ELSE 0 END) AS issues
     FROM mat_transactions WHERE txn_date >= :d"
);
$st->execute([':d' => $since]);
$month = $st->fetch();

$low = $pdo->query(
    "SELECT * FROM mat_items
     WHERE is_active = 1 AND min_qty > 0 AND quantity <= min_qty
     ORDER BY (quantity / min_qty) ASC, code LIMIT 8"
)->fetchAll();

$recent = $pdo->query(
    "SELECT t.*, i.code, i.description, i.unit, u.full_name AS user_name
     FROM mat_transactions t
     JOIN mat_items i ON i.id = t.item_id
     LEFT JOIN mat_users u ON u.id = t.user_id
     WHERE t.reason <> 'opening'
     ORDER BY t.id DESC LIMIT 10"
)->fetchAll();

$byCat = $pdo->query(
    "SELECT c.id, c.prefix, c.name,
            COUNT(i.id) AS items,
            SUM(CASE WHEN i.quantity > 0 THEN 1 ELSE 0 END) AS stocked
     FROM mat_categories c
     LEFT JOIN mat_items i ON i.category_id = c.id AND i.is_active = 1
     GROUP BY c.id, c.prefix, c.name, c.sort_order
     ORDER BY c.sort_order, c.name"
)->fetchAll();

$firstName = explode(' ', trim($user['full_name']))[0] ?: $user['username'];
mat_header('Dashboard', 'index');
?>
<section class="hello">
  <div>
    <h2>Good <?= (int)date('H') < 12 ? 'morning' : ((int)date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= e($firstName) ?></h2>
    <p class="muted"><?= date('l, d F Y') ?></p>
  </div>
  <form action="items.php" method="get" class="hello-search">
    <input type="search" name="q" placeholder="Find an item by name or code…" aria-label="Search inventory">
    <button class="btn btn-primary">Search</button>
  </form>
</section>

<?php if (mat_can_edit()): ?>
<section class="quick">
  <a class="quick-card q-in" href="move.php?type=in">
    <span class="q-icon">↓</span>
    <span><strong>Receive stock</strong><small>Deliveries, transfers in, returns</small></span>
  </a>
  <a class="quick-card q-out" href="move.php?type=out">
    <span class="q-icon">↑</span>
    <span><strong>Issue stock</strong><small>Record who collected what</small></span>
  </a>
  <a class="quick-card q-new" href="item-edit.php">
    <span class="q-icon">＋</span>
    <span><strong>New item</strong><small>Add an item to the master list</small></span>
  </a>
</section>
<?php endif; ?>

<section class="stats">
  <a class="stat" href="items.php"><span class="stat-n"><?= number_format((int)$stats['total']) ?></span><span class="stat-l">Items on master list</span></a>
  <a class="stat s-ok" href="items.php?status=in"><span class="stat-n"><?= number_format((int)$stats['in_stock']) ?></span><span class="stat-l">In stock</span></a>
  <a class="stat s-low" href="items.php?status=low"><span class="stat-n"><?= number_format((int)$stats['low_stock']) ?></span><span class="stat-l">Low stock</span></a>
  <a class="stat s-out" href="items.php?status=out"><span class="stat-n"><?= number_format((int)$stats['out_stock']) ?></span><span class="stat-l">Out of stock</span></a>
  <a class="stat" href="transactions.php?type=in&from=<?= $since ?>"><span class="stat-n"><?= number_format((int)$month['receipts']) ?></span><span class="stat-l">Receipts (30 days)</span></a>
  <a class="stat" href="transactions.php?type=out&from=<?= $since ?>"><span class="stat-n"><?= number_format((int)$month['issues']) ?></span><span class="stat-l">Issues (30 days)</span></a>
</section>

<div class="cols">
  <section class="card">
    <div class="card-head">
      <h3>Recent movements</h3>
      <a href="transactions.php" class="link">View all →</a>
    </div>
    <?php if (!$recent): ?>
      <p class="empty">No stock has been received or issued yet.<?= mat_can_edit() ? ' Use <strong>Receive stock</strong> or <strong>Issue stock</strong> to get started.' : '' ?></p>
    <?php else: ?>
      <ul class="feed">
        <?php foreach ($recent as $t): ?>
          <li>
            <span class="feed-dot d-<?= e($t['txn_type']) ?>"><?= $t['txn_type'] === 'in' ? '↓' : ($t['txn_type'] === 'out' ? '↑' : '≈') ?></span>
            <div class="feed-body">
              <a href="item.php?id=<?= (int)$t['item_id'] ?>"><strong><?= e($t['description']) ?></strong></a>
              <div class="muted small">
                <?= $t['quantity'] > 0 ? '+' : '' ?><?= qty($t['quantity']) ?> <?= e($t['unit']) ?> ·
                <?= e(mat_reason_label($t['reason'])) ?>
                <?php if ($t['party']): ?> · <?= $t['txn_type'] === 'out' ? 'collected by' : 'from' ?> <?= e($t['party']) ?><?php endif; ?>
              </div>
            </div>
            <span class="muted small nowrap"><?= mat_date($t['created_at']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <div class="stack">
    <section class="card">
      <div class="card-head">
        <h3>Needs re-ordering</h3>
        <a href="items.php?status=low" class="link">All →</a>
      </div>
      <?php if (!$low): ?>
        <p class="empty">Nothing is below its re-order level. <span class="muted">Set a re-order level on an item to get alerts here.</span></p>
      <?php else: ?>
        <ul class="mini-list">
          <?php foreach ($low as $i): ?>
            <li>
              <a href="item.php?id=<?= (int)$i['id'] ?>"><?= e($i['description']) ?></a>
              <span class="nowrap"><strong class="<?= $i['quantity'] <= 0 ? 'txt-out' : 'txt-low' ?>"><?= qty($i['quantity']) ?></strong> / <?= qty($i['min_qty']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h3>By category</h3></div>
      <ul class="mini-list">
        <?php foreach ($byCat as $c): $pct = $c['items'] ? round($c['stocked'] / $c['items'] * 100) : 0; ?>
          <li class="cat-row">
            <a href="items.php?cat=<?= (int)$c['id'] ?>"><?= e($c['name']) ?></a>
            <span class="muted small nowrap"><?= (int)$c['stocked'] ?> of <?= (int)$c['items'] ?> stocked</span>
            <span class="bar" aria-hidden="true"><span style="width: <?= $pct ?>%"></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  </div>
</div>
<?php mat_footer();
