<?php
/**
 * Receive stock (in), Issue stock (out) or Stock count correction (count).
 * One submission = one slip, which may contain several items.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = mat_require_editor();

$type = in_array($_REQUEST['type'] ?? '', ['in', 'out', 'count'], true) ? $_REQUEST['type'] : 'out';
$reasons = $type === 'in' ? MAT_IN_REASONS : ($type === 'out' ? MAT_OUT_REASONS : []);

$errors = [];
$form = [
    'reason'        => $type === 'in' ? 'purchase' : ($type === 'out' ? 'issued' : 'count'),
    'party'         => '',
    'department'    => '',
    'reference'     => '',
    'authorized_by' => '',
    'txn_date'      => date('Y-m-d'),
    'notes'         => '',
];
$lines = []; // [item row + 'qty']

$loadItems = function (array $ids) use ($pdo): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, code, description, unit, quantity, bin_location FROM mat_items WHERE is_active = 1 AND id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['id']] = $r;
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? $form[$k]));
    $postedIds = (array)($_POST['item_id'] ?? []);
    $postedQty = (array)($_POST['qty'] ?? []);
    $found = $loadItems($postedIds);

    foreach ($postedIds as $n => $id) {
        $id = (int)$id;
        if (!isset($found[$id])) continue;
        $q = str_replace(',', '', trim((string)($postedQty[$n] ?? '')));
        $lines[$id] = $found[$id] + ['qty' => $q];
    }

    if (!mat_check_csrf()) {
        $errors[] = 'Your session expired. Please submit again.';
    }
    if (!$lines) {
        $errors[] = 'Add at least one item.';
    }
    foreach ($lines as $l) {
        if (!is_numeric($l['qty']) || (float)$l['qty'] < 0 || ($type !== 'count' && (float)$l['qty'] <= 0)) {
            $errors[] = 'Enter a valid quantity for ' . $l['code'] . '.';
        } elseif ($type === 'out' && (float)$l['qty'] > (float)$l['quantity']) {
            $errors[] = sprintf('%s: only %s %s in stock.', $l['code'], qty($l['quantity']), $l['unit']);
        }
    }
    if ($type !== 'count' && !array_key_exists($form['reason'], $reasons)) {
        $errors[] = 'Choose how the stock ' . ($type === 'in' ? 'arrived' : 'left the store') . '.';
    }
    if ($type === 'out' && in_array($form['reason'], ['issued', 'transfer_out'], true) && $form['party'] === '') {
        $errors[] = 'Enter the name of the person who collected the items.';
    }
    if ($type === 'out' && $form['reason'] === 'issued' && $form['department'] === '') {
        $errors[] = 'Choose the department the items were issued to.';
    }
    if ($type === 'count' && $form['notes'] === '') {
        $errors[] = 'Give a short reason for the correction (e.g. "Monthly stock count").';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['txn_date']) || $form['txn_date'] > date('Y-m-d')) {
        $errors[] = 'Enter a valid date (not in the future).';
    }

    if (!$errors) {
        $batch = mat_new_batch($type);
        $meta = $form + ['batch' => $batch];
        if ($type === 'count') $meta['reason'] = 'count';
        $pdo->beginTransaction();
        $ok = true;
        $msgs = [];
        foreach ($lines as $l) {
            [$ok, $msg] = mat_move_stock($pdo, (int)$l['id'], $type, (float)$l['qty'], $meta, $user, false);
            if (!$ok) { $errors[] = $msg; break; }
            $msgs[] = $msg;
        }
        if ($ok) {
            $pdo->commit();
            $n = count($lines);
            mat_flash('success', $n === 1 ? $msgs[0] : sprintf('%s %d items (slip %s).', $type === 'in' ? 'Received' : ($type === 'out' ? 'Issued' : 'Corrected'), $n, $batch));
            mat_redirect(MAT_URL . '/slip.php?batch=' . urlencode($batch) . '&new=1');
        }
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
} else {
    // Pre-select an item when coming from the inventory list
    if (!empty($_GET['item'])) {
        foreach ($loadItems([(int)$_GET['item']]) as $id => $r) {
            $lines[$id] = $r + ['qty' => ''];
        }
    }
}

$deptList = array_values(array_unique(array_merge(MAT_DEPARTMENTS, mat_suggestions($pdo, 'department'))));
$people   = mat_suggestions($pdo, 'party');
$auths    = mat_suggestions($pdo, 'authorized_by');

$titles = ['in' => 'Receive Stock', 'out' => 'Issue Stock', 'count' => 'Stock Count Correction'];
$active = ['in' => 'receive', 'out' => 'issue', 'count' => 'items'][$type];
mat_header($titles[$type], $active);
?>
<div class="seg" role="tablist" aria-label="Movement type">
  <a href="move.php?type=in<?= !empty($_GET['item']) ? '&item=' . (int)$_GET['item'] : '' ?>"  class="seg-in <?= $type === 'in' ? 'on' : '' ?>">↓ Receive</a>
  <a href="move.php?type=out<?= !empty($_GET['item']) ? '&item=' . (int)$_GET['item'] : '' ?>" class="seg-out <?= $type === 'out' ? 'on' : '' ?>">↑ Issue</a>
  <a href="move.php?type=count<?= !empty($_GET['item']) ? '&item=' . (int)$_GET['item'] : '' ?>" class="seg-cnt <?= $type === 'count' ? 'on' : '' ?>">≈ Stock count</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error">
    <strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" class="move-form" id="moveForm" data-type="<?= e($type) ?>" novalidate>
  <?= mat_csrf_field() ?>
  <input type="hidden" name="type" value="<?= e($type) ?>">

  <section class="card">
    <div class="card-head">
      <h3><span class="step">1</span> <?= $type === 'count' ? 'Items counted' : 'Items' ?></h3>
      <span class="muted small" id="lineCount"></span>
    </div>
    <div class="picker">
      <input type="search" id="itemSearch" placeholder="Type an item name or code to add it…" autocomplete="off" aria-label="Search items to add">
      <ul class="picker-results" id="pickerResults" role="listbox" hidden></ul>
    </div>
    <table class="table lines-table">
      <thead>
        <tr><th>Item</th><th class="num">In store</th><th class="num"><?= $type === 'count' ? 'Counted qty' : ($type === 'in' ? 'Qty received' : 'Qty issued') ?></th><th></th></tr>
      </thead>
      <tbody id="lines">
        <?php foreach ($lines as $l): ?>
        <tr data-id="<?= (int)$l['id'] ?>" data-stock="<?= e((float)$l['quantity']) ?>">
          <td>
            <input type="hidden" name="item_id[]" value="<?= (int)$l['id'] ?>">
            <strong><?= e($l['description']) ?></strong>
            <div class="muted small mono"><?= e($l['code']) ?><?= $l['bin_location'] ? ' · Bin ' . e($l['bin_location']) : '' ?></div>
          </td>
          <td class="num"><?= qty($l['quantity']) ?> <span class="muted small"><?= e($l['unit']) ?></span></td>
          <td class="num"><input class="qty-input" type="number" name="qty[]" min="0" step="any" inputmode="decimal" value="<?= e($l['qty']) ?>" required></td>
          <td class="right"><button type="button" class="icon-btn remove-line" aria-label="Remove">✕</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="empty" id="noLines" <?= $lines ? 'hidden' : '' ?>>No items added yet. Search above to add one or more items to this <?= $type === 'count' ? 'count' : 'slip' ?>.</p>
  </section>

  <?php if ($type !== 'count'): ?>
  <section class="card">
    <div class="card-head"><h3><span class="step">2</span> <?= $type === 'in' ? 'How did it arrive?' : 'How was it collected?' ?></h3></div>
    <div class="reason-grid">
      <?php foreach ($reasons as $key => $label): ?>
        <label class="reason">
          <input type="radio" name="reason" value="<?= e($key) ?>" <?= $form['reason'] === $key ? 'checked' : '' ?>>
          <span><?= e($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h3><span class="step"><?= $type === 'count' ? '2' : '3' ?></span> Details</h3></div>
    <div class="grid-2">
      <?php if ($type === 'out'): ?>
        <label class="field" data-show-for="issued transfer_out returned_vendor other_out">
          <span>Collected by <em class="req" data-req-for="issued transfer_out">*</em></span>
          <input name="party" value="<?= e($form['party']) ?>" list="peopleList" placeholder="Full name of the person who collected">
        </label>
        <label class="field" data-show-for="issued other_out">
          <span>Department <em class="req" data-req-for="issued">*</em></span>
          <input name="department" value="<?= e($form['department']) ?>" list="deptList" placeholder="e.g. Drilling, Mechanical">
        </label>
        <label class="field">
          <span>Authorized / approved by</span>
          <input name="authorized_by" value="<?= e($form['authorized_by']) ?>" list="authList" placeholder="e.g. Toolpusher name">
        </label>
        <label class="field">
          <span>Requisition / reference no.</span>
          <input name="reference" value="<?= e($form['reference']) ?>" placeholder="e.g. MR-0231, waybill no.">
        </label>
      <?php elseif ($type === 'in'): ?>
        <label class="field">
          <span>Received from</span>
          <input name="party" value="<?= e($form['party']) ?>" list="peopleList" placeholder="Supplier, rig/base, or person returning it">
        </label>
        <label class="field">
          <span>PO / Waybill / Delivery note no.</span>
          <input name="reference" value="<?= e($form['reference']) ?>" placeholder="e.g. PO-4471, WB-118">
        </label>
        <label class="field" data-show-for="returned">
          <span>Returned from department</span>
          <input name="department" value="<?= e($form['department']) ?>" list="deptList">
        </label>
        <label class="field">
          <span>Checked / approved by</span>
          <input name="authorized_by" value="<?= e($form['authorized_by']) ?>" list="authList">
        </label>
      <?php endif; ?>
      <label class="field">
        <span>Date</span>
        <input type="date" name="txn_date" value="<?= e($form['txn_date']) ?>" max="<?= date('Y-m-d') ?>" required>
      </label>
    </div>
    <label class="field">
      <span><?= $type === 'count' ? 'Reason for correction *' : 'Notes' ?></span>
      <textarea name="notes" rows="2" placeholder="<?= $type === 'count' ? 'e.g. Monthly physical stock count, found 2 extra in bin C4' : 'Optional — condition, serial numbers, job it was used for…' ?>"><?= e($form['notes']) ?></textarea>
    </label>
  </section>

  <div class="form-actions sticky-actions">
    <a href="items.php" class="btn btn-ghost">Cancel</a>
    <button type="submit" class="btn btn-lg <?= $type === 'in' ? 'btn-in' : ($type === 'out' ? 'btn-out' : 'btn-primary') ?>">
      <?= $type === 'in' ? '✓ Save receipt' : ($type === 'out' ? '✓ Save issue' : '✓ Save correction') ?>
    </button>
  </div>
</form>

<datalist id="deptList"><?php foreach ($deptList as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="peopleList"><?php foreach ($people as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<datalist id="authList"><?php foreach ($auths as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?></datalist>
<?php mat_footer();
