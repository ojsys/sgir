<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

mat_require_login();

[$where, $params, $f] = mat_txn_filters($_GET);

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$base = "FROM mat_transactions t JOIN mat_items i ON i.id = t.item_id LEFT JOIN mat_users u ON u.id = t.user_id $where";

$cnt = $pdo->prepare("SELECT COUNT(*) $base");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$page = min($page, max(1, (int)ceil($total / $perPage)));
$offset = ($page - 1) * $perPage;

$st = $pdo->prepare("SELECT t.*, i.code, i.description, i.unit, u.full_name AS user_name $base ORDER BY t.txn_date DESC, t.id DESC LIMIT $perPage OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();

$users = $pdo->query('SELECT id, full_name FROM mat_users ORDER BY full_name')->fetchAll();
$depts = $pdo->query("SELECT DISTINCT department FROM mat_transactions WHERE department <> '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

$itemLabel = '';
if ($f['item']) {
    $x = $pdo->prepare('SELECT code, description FROM mat_items WHERE id = :id');
    $x->execute([':id' => $f['item']]);
    if ($r = $x->fetch()) $itemLabel = $r['code'] . ' — ' . $r['description'];
}

$exportQs = http_build_query(array_filter(['what' => 'transactions'] + $f));
mat_header('Movement History', 'transactions');
?>
<form class="toolbar wrap" method="get">
  <?php if ($f['item']): ?><input type="hidden" name="item" value="<?= $f['item'] ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Item, person, reference…" class="grow" aria-label="Search">
  <select name="type" aria-label="Type" data-autosubmit>
    <option value="">All movements</option>
    <option value="in"     <?= $f['type'] === 'in' ? 'selected' : '' ?>>Received (in)</option>
    <option value="out"    <?= $f['type'] === 'out' ? 'selected' : '' ?>>Issued (out)</option>
    <option value="adjust" <?= $f['type'] === 'adjust' ? 'selected' : '' ?>>Stock corrections</option>
  </select>
  <select name="reason" aria-label="How" data-autosubmit>
    <option value="">Any reason</option>
    <optgroup label="In">
      <?php foreach (MAT_IN_REASONS + ['opening' => 'Opening balance'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['reason'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </optgroup>
    <optgroup label="Out">
      <?php foreach (MAT_OUT_REASONS as $k => $l): ?><option value="<?= $k ?>" <?= $f['reason'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </optgroup>
  </select>
  <select name="dept" aria-label="Department" data-autosubmit>
    <option value="">All departments</option>
    <?php foreach ($depts as $d): ?><option <?= $f['dept'] === $d ? 'selected' : '' ?>><?= e($d) ?></option><?php endforeach; ?>
  </select>
  <select name="user" aria-label="Recorded by" data-autosubmit>
    <option value="">Recorded by anyone</option>
    <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $f['user'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?>
  </select>
  <label class="inline-field">From <input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label class="inline-field">To <input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <button class="btn btn-primary">Filter</button>
  <?php if (array_filter($f)): ?><a class="btn btn-ghost" href="transactions.php">Clear</a><?php endif; ?>
</form>

<div class="list-head">
  <p class="muted"><strong><?= number_format($total) ?></strong> movement<?= $total === 1 ? '' : 's' ?><?= $itemLabel ? ' for <strong>' . e($itemLabel) . '</strong>' : '' ?></p>
  <a class="btn btn-ghost btn-sm" href="export.php?<?= e($exportQs) ?>">⤓ Export CSV</a>
</div>

<?php if (!$rows): ?>
  <div class="card empty-state"><p><strong>No movements found.</strong></p><p class="muted">Try widening the date range or clearing filters.</p></div>
<?php else: ?>
<div class="card table-card">
  <table class="table">
    <thead><tr><th>Date</th><th>Item</th><th>Type</th><th>How / who</th><th class="num">Qty</th><th class="num hide-sm">Balance</th><th class="hide-sm">Recorded by</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): ?>
      <tr>
        <td class="nowrap" data-label="Date"><?= mat_date($t['txn_date'], false) ?>
          <?php if ($t['batch']): ?><div><a class="small mono" href="slip.php?batch=<?= e($t['batch']) ?>"><?= e($t['batch']) ?></a></div><?php endif; ?>
        </td>
        <td data-label="Item"><a href="item.php?id=<?= (int)$t['item_id'] ?>"><?= e($t['description']) ?></a><div class="muted small mono"><?= e($t['code']) ?></div></td>
        <td data-label="Type"><?= mat_type_badge($t['txn_type']) ?></td>
        <td data-label="How">
          <?= e(mat_reason_label($t['reason'])) ?>
          <?php if ($t['party']): ?><div class="small"><?= $t['txn_type'] === 'out' ? 'Collected by' : 'From' ?> <strong><?= e($t['party']) ?></strong><?= $t['department'] ? ' · ' . e($t['department']) : '' ?></div><?php elseif ($t['department']): ?><div class="small"><?= e($t['department']) ?></div><?php endif; ?>
          <?php if ($t['reference']): ?><div class="muted small">Ref: <?= e($t['reference']) ?></div><?php endif; ?>
        </td>
        <td class="num <?= $t['quantity'] < 0 ? 'txt-out' : 'txt-in' ?>" data-label="Qty"><strong><?= $t['quantity'] > 0 ? '+' : '' ?><?= qty($t['quantity']) ?></strong> <span class="muted small"><?= e($t['unit']) ?></span></td>
        <td class="num hide-sm" data-label="Balance"><?= qty($t['balance_after']) ?></td>
        <td class="hide-sm muted small" data-label="By"><?= e($t['user_name'] ?? 'System') ?><div><?= mat_date($t['created_at']) ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php mat_pager($total, $perPage, $page); ?>
<?php endif; ?>
<?php mat_footer();
