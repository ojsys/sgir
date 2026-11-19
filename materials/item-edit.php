<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = mat_require_editor();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$cats = mat_categories($pdo);

$item = [
    'code' => '', 'description' => '', 'unit' => 'Pieces', 'category_id' => $cats[0]['id'] ?? null,
    'min_qty' => 0, 'bin_location' => '', 'notes' => '', 'is_active' => 1, 'quantity' => 0,
];
if ($id) {
    $st = $pdo->prepare('SELECT * FROM mat_items WHERE id = :id');
    $st->execute([':id' => $id]);
    $item = $st->fetch();
    if (!$item) {
        mat_flash('error', 'Item not found.');
        mat_redirect(MAT_URL . '/items.php');
    }
}

$units = $pdo->query("SELECT DISTINCT unit FROM mat_items WHERE unit <> '' ORDER BY unit")->fetchAll(PDO::FETCH_COLUMN);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if (!mat_check_csrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($id && in_array($action, ['archive', 'restore'], true)) {
        $active = $action === 'restore' ? 1 : 0;
        $pdo->prepare('UPDATE mat_items SET is_active = :a, updated_at = :t WHERE id = :id')
            ->execute([':a' => $active, ':t' => mat_now(), ':id' => $id]);
        mat_audit($pdo, (int)$user['id'], $active ? 'item_restore' : 'item_archive', $item['code']);
        mat_flash('success', $active ? 'Item restored to the active list.' : 'Item archived. It is hidden from the inventory but its history is kept.');
        mat_redirect(MAT_URL . '/item.php?id=' . $id);
    } else {
        $in = [
            'description'  => trim(preg_replace('/\s+/u', ' ', $_POST['description'] ?? '')),
            'unit'         => trim($_POST['unit'] ?? ''),
            'category_id'  => (int)($_POST['category_id'] ?? 0) ?: null,
            'min_qty'      => trim($_POST['min_qty'] ?? '0'),
            'bin_location' => trim($_POST['bin_location'] ?? ''),
            'notes'        => trim($_POST['notes'] ?? ''),
            'code'         => strtoupper(trim($_POST['code'] ?? '')),
            'opening'      => trim($_POST['opening'] ?? ''),
        ];
        $origCode = $item['code'];
        if ($id && $in['code'] === '') $in['code'] = $origCode;
        $item = array_merge($item, $in);

        if ($in['description'] === '') $errors[] = 'Description is required.';
        if ($in['unit'] === '') $errors[] = 'Unit of measure is required.';
        if ($in['min_qty'] === '' ) $in['min_qty'] = '0';
        if (!is_numeric($in['min_qty']) || $in['min_qty'] < 0) $errors[] = 'Re-order level must be zero or a positive number.';
        if (!$id && $in['opening'] !== '' && (!is_numeric($in['opening']) || $in['opening'] < 0)) $errors[] = 'Opening quantity must be zero or more.';
        if ($in['code'] !== '' && !preg_match('/^[A-Z0-9\-\/\.]{2,40}$/', $in['code'])) $errors[] = 'Item code may only contain letters, numbers, dash, slash or dot.';

        // Category prefix for auto code
        $prefix = 'SX';
        foreach ($cats as $c) if ((int)$c['id'] === (int)$in['category_id']) $prefix = $c['prefix'];
        if (!$id && $in['code'] === '') $in['code'] = mat_next_code($pdo, $prefix);

        if ($in['code'] !== '' && !$errors) {
            $chk = $pdo->prepare('SELECT id FROM mat_items WHERE code = :c AND id <> :id');
            $chk->execute([':c' => $in['code'], ':id' => $id]);
            if ($chk->fetch()) $errors[] = 'Another item already uses code ' . $in['code'] . '.';
        }

        if (!$errors) {
            $now = mat_now();
            if ($id) {
                $pdo->prepare('UPDATE mat_items SET code = :code, description = :d, unit = :u, category_id = :c, min_qty = :m,
                               bin_location = :b, notes = :n, updated_at = :t WHERE id = :id')
                    ->execute([':code' => $in['code'], ':d' => mb_substr($in['description'], 0, 255), ':u' => mb_substr($in['unit'], 0, 30),
                               ':c' => $in['category_id'], ':m' => (float)$in['min_qty'], ':b' => mb_substr($in['bin_location'], 0, 60),
                               ':n' => $in['notes'], ':t' => $now, ':id' => $id]);
                mat_audit($pdo, (int)$user['id'], 'item_edit', $in['code']);
                mat_flash('success', 'Item details saved.');
            } else {
                $pdo->prepare('INSERT INTO mat_items (code, description, unit, category_id, quantity, min_qty, bin_location, notes, is_active, created_at, updated_at)
                               VALUES (:code, :d, :u, :c, 0, :m, :b, :n, 1, :t, :t2)')
                    ->execute([':code' => $in['code'], ':d' => mb_substr($in['description'], 0, 255), ':u' => mb_substr($in['unit'], 0, 30),
                               ':c' => $in['category_id'], ':m' => (float)$in['min_qty'], ':b' => mb_substr($in['bin_location'], 0, 60),
                               ':n' => $in['notes'], ':t' => $now, ':t2' => $now]);
                $id = (int)$pdo->lastInsertId();
                mat_audit($pdo, (int)$user['id'], 'item_create', $in['code']);
                if ($in['opening'] !== '' && (float)$in['opening'] > 0) {
                    mat_move_stock($pdo, $id, 'in', (float)$in['opening'],
                        ['reason' => 'opening', 'notes' => 'Opening quantity when item was created', 'batch' => mat_new_batch('in')], $user);
                }
                mat_flash('success', 'New item ' . $in['code'] . ' created.');
            }
            mat_redirect(MAT_URL . '/item.php?id=' . $id);
        }
    }
}

mat_header($id ? 'Edit item' : 'New item', 'items');
?>
<a href="<?= $id ? 'item.php?id=' . $id : 'items.php' ?>" class="link back">← Back</a>

<?php if ($errors): ?>
  <div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="card form narrow">
  <?= mat_csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <label class="field">
    <span>Description *</span>
    <input name="description" value="<?= e($item['description']) ?>" required maxlength="255" placeholder="e.g. CHAIN HOIST/BLOCK 5 TON X 3M">
  </label>
  <div class="grid-2">
    <label class="field">
      <span>Category</span>
      <select name="category_id">
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$item['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['prefix'] . ' — ' . $c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field">
      <span>Item code</span>
      <input name="code" value="<?= e($item['code']) ?>" maxlength="40" placeholder="<?= $id ? '' : 'Leave blank to auto-generate' ?>" class="mono">
    </label>
    <label class="field">
      <span>Unit of measure *</span>
      <input name="unit" value="<?= e($item['unit']) ?>" list="unitList" required maxlength="30">
    </label>
    <label class="field">
      <span>Re-order level</span>
      <input type="number" name="min_qty" value="<?= e((float)$item['min_qty']) ?>" min="0" step="any">
      <small class="hint">You’ll get a “Low stock” alert at or below this. 0 = no alert.</small>
    </label>
    <label class="field">
      <span>Bin / shelf location</span>
      <input name="bin_location" value="<?= e($item['bin_location']) ?>" maxlength="60" placeholder="e.g. Container 2, Shelf C4">
    </label>
    <?php if (!$id): ?>
    <label class="field">
      <span>Opening quantity in store</span>
      <input type="number" name="opening" value="<?= e($item['opening'] ?? '') ?>" min="0" step="any" placeholder="0">
    </label>
    <?php else: ?>
    <div class="field">
      <span>Quantity in store</span>
      <div class="readonly"><?= qty($item['quantity']) ?> <?= e($item['unit']) ?> <a class="small link" href="move.php?type=count&item=<?= $id ?>">Correct via stock count →</a></div>
    </div>
    <?php endif; ?>
  </div>
  <label class="field">
    <span>Notes</span>
    <textarea name="notes" rows="3" placeholder="Part numbers, specifications, supplier…"><?= e($item['notes']) ?></textarea>
  </label>
  <div class="form-actions">
    <a href="<?= $id ? 'item.php?id=' . $id : 'items.php' ?>" class="btn btn-ghost">Cancel</a>
    <button class="btn btn-primary btn-lg" name="action" value="save"><?= $id ? 'Save changes' : 'Create item' ?></button>
  </div>
</form>

<?php if ($id): ?>
<form method="post" class="card form narrow danger-zone">
  <?= mat_csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <?php if ((int)$item['is_active']): ?>
    <div><strong>Archive this item</strong><p class="muted small">Hides it from the inventory and pickers. History is kept and it can be restored at any time.</p></div>
    <button class="btn btn-danger" name="action" value="archive" data-confirm="Archive <?= e($item['code']) ?>?">Archive</button>
  <?php else: ?>
    <div><strong>This item is archived</strong><p class="muted small">Restore it to make it available again.</p></div>
    <button class="btn btn-primary" name="action" value="restore">Restore</button>
  <?php endif; ?>
</form>
<?php endif; ?>

<datalist id="unitList"><?php foreach ($units as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
<?php mat_footer();
