<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$me = mat_require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $prefix = strtoupper(trim($_POST['prefix'] ?? ''));
    $sort = (int)($_POST['sort_order'] ?? 0);

    if (!mat_check_csrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'delete') {
        $n = $pdo->prepare('SELECT COUNT(*) FROM mat_items WHERE category_id = :id');
        $n->execute([':id' => $id]);
        if ((int)$n->fetchColumn() > 0) {
            $errors[] = 'This category still has items. Move them to another category first.';
        } else {
            $pdo->prepare('DELETE FROM mat_categories WHERE id = :id')->execute([':id' => $id]);
            mat_audit($pdo, (int)$me['id'], 'category_delete', (string)$id);
            mat_flash('success', 'Category deleted.');
            mat_redirect(MAT_URL . '/admin/categories.php');
        }
    } else {
        if ($name === '') $errors[] = 'Category name is required.';
        if (!preg_match('/^[A-Z]{2,4}$/', $prefix)) $errors[] = 'Code prefix must be 2–4 letters, e.g. SF.';
        if (!$errors) {
            $chk = $pdo->prepare('SELECT id FROM mat_categories WHERE prefix = :p AND id <> :id');
            $chk->execute([':p' => $prefix, ':id' => $id]);
            if ($chk->fetch()) $errors[] = "Prefix $prefix is already used by another category.";
        }
        if (!$errors) {
            if ($action === 'edit' && $id) {
                $pdo->prepare('UPDATE mat_categories SET name = :n, prefix = :p, sort_order = :s WHERE id = :id')
                    ->execute([':n' => $name, ':p' => $prefix, ':s' => $sort, ':id' => $id]);
                mat_flash('success', 'Category updated.');
            } else {
                $pdo->prepare('INSERT INTO mat_categories (name, prefix, sort_order) VALUES (:n, :p, :s)')
                    ->execute([':n' => $name, ':p' => $prefix, ':s' => $sort]);
                mat_flash('success', "Category “{$name}” added. New items in it will get codes like {$prefix}00000001.");
            }
            mat_audit($pdo, (int)$me['id'], 'category_' . ($action === 'edit' ? 'edit' : 'add'), "$prefix $name");
            mat_redirect(MAT_URL . '/admin/categories.php');
        }
    }
}

$cats = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM mat_items i WHERE i.category_id = c.id) AS items
     FROM mat_categories c ORDER BY c.sort_order, c.name'
)->fetchAll();

mat_header('Categories', 'categories');
?>
<?php if ($errors): ?><div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<p class="muted">Categories group the item master. The prefix is the first letters of each item code (e.g. <span class="mono">SC</span>00000123) and is used to number new items automatically.</p>

<div class="card table-card">
  <table class="table">
    <thead><tr><th>Prefix</th><th>Name</th><th class="num">Order</th><th class="num">Items</th><th class="right"></th></tr></thead>
    <tbody>
    <?php foreach ($cats as $c): ?>
      <?php $fid = 'cat' . (int)$c['id']; ?>
      <tr>
        <td data-label="Prefix"><input form="<?= $fid ?>" name="prefix" value="<?= e($c['prefix']) ?>" maxlength="4" class="mono input-xs" required></td>
        <td data-label="Name"><input form="<?= $fid ?>" name="name" value="<?= e($c['name']) ?>" required class="input-full"></td>
        <td class="num" data-label="Order"><input form="<?= $fid ?>" type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>" class="input-xs"></td>
        <td class="num" data-label="Items"><a href="<?= MAT_URL ?>/items.php?cat=<?= (int)$c['id'] ?>"><?= number_format((int)$c['items']) ?></a></td>
        <td class="right nowrap">
          <form method="post" id="<?= $fid ?>" class="inline">
            <?= mat_csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-ghost btn-sm" name="action" value="edit">Save</button>
            <?php if (!(int)$c['items']): ?><button class="btn btn-ghost btn-sm txt-out" name="action" value="delete" data-confirm="Delete this category?">Delete</button><?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<form method="post" class="card form narrow">
  <h3>Add category</h3>
  <?= mat_csrf_field() ?>
  <input type="hidden" name="action" value="add">
  <div class="grid-3">
    <label class="field"><span>Prefix</span><input name="prefix" maxlength="4" class="mono" placeholder="SF" required></label>
    <label class="field span-2"><span>Name</span><input name="name" placeholder="e.g. Fuel &amp; Lubricants" required></label>
  </div>
  <label class="field"><span>Display order</span><input type="number" name="sort_order" value="<?= count($cats) + 1 ?>"></label>
  <div class="form-actions"><button class="btn btn-primary">＋ Add category</button></div>
</form>
<?php mat_footer();
