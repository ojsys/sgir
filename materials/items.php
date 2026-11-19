<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

mat_require_login();

[$where, $params, $f] = mat_item_filters($_GET);

$sorts = [
    'code'     => 'i.code ASC',
    'name'     => 'i.description ASC',
    'qty_desc' => 'i.quantity DESC, i.code ASC',
    'qty_asc'  => 'i.quantity ASC, i.code ASC',
    'updated'  => 'i.updated_at DESC',
];
$sort = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'code';

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));

$cnt = $pdo->prepare("SELECT COUNT(*) FROM mat_items i $where");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $perPage)));
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare(
    "SELECT i.*, c.name AS category_name
     FROM mat_items i LEFT JOIN mat_categories c ON c.id = i.category_id
     $where ORDER BY {$sorts[$sort]} LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$items = $st->fetchAll();

$cats = mat_categories($pdo);
$canEdit = mat_can_edit();
$exportQs = http_build_query(array_filter(['what' => 'items', 'q' => $f['q'], 'cat' => $f['cat'] ?: null, 'status' => $f['status']]));

mat_header('Inventory', 'items');
?>
<form class="toolbar" method="get" id="filterForm">
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, code or bin…" class="grow" aria-label="Search">
  <select name="cat" aria-label="Category" data-autosubmit>
    <option value="">All categories</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $f['cat'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" aria-label="Stock status" data-autosubmit>
    <option value="">Any stock level</option>
    <option value="in"  <?= $f['status'] === 'in'  ? 'selected' : '' ?>>In stock</option>
    <option value="low" <?= $f['status'] === 'low' ? 'selected' : '' ?>>Low stock</option>
    <option value="out" <?= $f['status'] === 'out' ? 'selected' : '' ?>>Out of stock</option>
    <option value="inactive" <?= $f['status'] === 'inactive' ? 'selected' : '' ?>>Archived items</option>
  </select>
  <select name="sort" aria-label="Sort" data-autosubmit>
    <option value="code"     <?= $sort === 'code' ? 'selected' : '' ?>>Sort: Code</option>
    <option value="name"     <?= $sort === 'name' ? 'selected' : '' ?>>Sort: Name A–Z</option>
    <option value="qty_desc" <?= $sort === 'qty_desc' ? 'selected' : '' ?>>Sort: Most stock</option>
    <option value="qty_asc"  <?= $sort === 'qty_asc' ? 'selected' : '' ?>>Sort: Least stock</option>
    <option value="updated"  <?= $sort === 'updated' ? 'selected' : '' ?>>Sort: Recently changed</option>
  </select>
  <button class="btn btn-primary">Filter</button>
  <?php if ($f['q'] || $f['cat'] || $f['status'] || $sort !== 'code'): ?><a class="btn btn-ghost" href="items.php">Clear</a><?php endif; ?>
</form>

<div class="list-head">
  <p class="muted"><strong><?= number_format($total) ?></strong> item<?= $total === 1 ? '' : 's' ?><?= $f['q'] ? ' matching “' . e($f['q']) . '”' : '' ?></p>
  <div class="actions">
    <a class="btn btn-ghost btn-sm" href="export.php?<?= e($exportQs) ?>">⤓ Export CSV</a>
    <?php if ($canEdit): ?><a class="btn btn-primary btn-sm" href="item-edit.php">＋ New item</a><?php endif; ?>
  </div>
</div>

<?php if (!$items): ?>
  <div class="card empty-state">
    <p><strong>No items found.</strong></p>
    <p class="muted">Try a shorter search word or clear the filters.</p>
  </div>
<?php else: ?>
<div class="card table-card">
  <table class="table items-table">
    <thead>
      <tr>
        <th>Code</th>
        <th>Description</th>
        <th class="hide-sm">Category</th>
        <th class="hide-sm">Bin</th>
        <th class="num">In stock</th>
        <th>Status</th>
        <?php if ($canEdit): ?><th class="right">Quick action</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr>
        <td class="mono" data-label="Code"><a href="item.php?id=<?= (int)$i['id'] ?>"><?= e($i['code']) ?></a></td>
        <td data-label="Item"><a href="item.php?id=<?= (int)$i['id'] ?>" class="item-name"><?= e($i['description']) ?></a></td>
        <td class="hide-sm muted small" data-label="Category"><?= e($i['category_name'] ?? '—') ?></td>
        <td class="hide-sm small" data-label="Bin"><?= e($i['bin_location'] ?: '—') ?></td>
        <td class="num" data-label="In stock"><strong><?= qty($i['quantity']) ?></strong> <span class="muted small"><?= e($i['unit']) ?></span></td>
        <td data-label="Status"><?= mat_status_badge($i) ?></td>
        <?php if ($canEdit): ?>
        <td class="right nowrap" data-label="">
          <a class="btn btn-in btn-sm" href="move.php?type=in&item=<?= (int)$i['id'] ?>" title="Receive">↓ In</a>
          <a class="btn btn-out btn-sm <?= $i['quantity'] <= 0 ? 'disabled' : '' ?>" href="move.php?type=out&item=<?= (int)$i['id'] ?>" title="Issue">↑ Out</a>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php mat_pager($total, $perPage, $page); ?>
<?php endif; ?>
<?php mat_footer();
