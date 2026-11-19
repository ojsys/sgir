<?php
/**
 * Import / update the item master from an Excel (.xlsx) or CSV file
 * laid out like the SGIR Item Master: Item Name | Description | Unit | Quantity
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$me = mat_require_admin();
$errors = [];
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = ($_POST['mode'] ?? '') === 'set_qty' ? 'set_qty' : 'new_only';
    $file = $_FILES['sheet'] ?? null;

    if (!mat_check_csrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a file to upload.';
    } elseif ($file['size'] > 10 * 1024 * 1024) {
        $errors[] = 'The file is larger than 10 MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        try {
            if ($ext === 'xlsx') {
                $rows = mat_read_xlsx($file['tmp_name']);
            } elseif ($ext === 'csv') {
                $rows = mat_read_csv($file['tmp_name']);
            } else {
                throw new RuntimeException('Upload an .xlsx or .csv file.');
            }
            if (!$rows) throw new RuntimeException('No rows were found in the file.');
            $result = mat_import_rows($pdo, $rows, $me, $mode);
            mat_audit($pdo, (int)$me['id'], 'import', sprintf('%s: %d new, %d updated, %d skipped (%s)', $file['name'], $result['created'], $result['updated'], $result['skipped'], $mode));
        } catch (Throwable $ex) {
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'The file could not be imported. Check that it matches the template.';
            error_log('Materials import failed: ' . $ex->getMessage());
        }
    }
}

mat_header('Import Excel', 'import');
?>
<?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<?php if ($result): ?>
  <div class="alert alert-success">
    Import finished: <strong><?= $result['created'] ?></strong> new item(s) added,
    <strong><?= $result['updated'] ?></strong> existing item(s) updated,
    <strong><?= $result['skipped'] ?></strong> row(s) skipped (blank code or description).
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="card form narrow">
  <h3>Upload item master</h3>
  <p class="muted">Use the same layout as the <em>Spool of Item Master SGIR</em> spreadsheet. The first row is treated as headings.</p>
  <table class="table template-table">
    <thead><tr><th>A — Item Name (code)</th><th>B — Description</th><th>C — Unit</th><th>D — Quantity</th></tr></thead>
    <tbody><tr><td class="mono">SC00000527</td><td>CHAIN TONG 8"</td><td>Pieces</td><td>4</td></tr></tbody>
  </table>
  <?= mat_csrf_field() ?>
  <label class="field">
    <span>File (.xlsx or .csv)</span>
    <input type="file" name="sheet" accept=".xlsx,.csv" required>
  </label>
  <fieldset class="role-pick">
    <legend>For items that already exist</legend>
    <label class="reason"><input type="radio" name="mode" value="new_only" checked>
      <span><strong>Keep current stock</strong><small>Only add new items and update descriptions/units. Quantities already in the system are not touched.</small></span></label>
    <label class="reason"><input type="radio" name="mode" value="set_qty">
      <span><strong>Replace quantities with the sheet</strong><small>Use after a full physical stock count. Each change is logged as a stock correction.</small></span></label>
  </fieldset>
  <div class="form-actions"><button class="btn btn-primary btn-lg">⇪ Import</button></div>
</form>
<?php mat_footer();
