<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_admin();

$settings     = get_site_settings($pdo);
$current_page = 'locations';
$csrf         = csrf_token();

$success = '';
$error   = '';

// ── Handle POST actions ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $name = trim($_POST['name'] ?? '');
            $code = trim($_POST['code'] ?? '');
            $sort = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '') {
                $error = 'Location name is required.';
            } else {
                if ($action === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO rig_locations (name, code, is_active, sort_order, created_at)
                         VALUES (:name, :code, :active, :sort, :created_at)'
                    );
                    $stmt->execute([
                        ':name'       => $name,
                        ':code'       => $code !== '' ? $code : null,
                        ':active'     => $is_active,
                        ':sort'       => $sort,
                        ':created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $success = 'Location added successfully.';
                } else {
                    $edit_id = (int)($_POST['edit_id'] ?? 0);
                    $stmt = $pdo->prepare(
                        'UPDATE rig_locations
                         SET name = :name, code = :code, is_active = :active, sort_order = :sort
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':name'   => $name,
                        ':code'   => $code !== '' ? $code : null,
                        ':active' => $is_active,
                        ':sort'   => $sort,
                        ':id'     => $edit_id,
                    ]);
                    header('Location: ' . BASE_URL . '/dashboard/locations.php?saved=1');
                    exit;
                }
            }

        } elseif ($action === 'toggle') {
            $lid = (int)($_POST['location_id'] ?? 0);
            $pdo->prepare('UPDATE rig_locations SET is_active = 1 - is_active WHERE id = :id')
                ->execute([':id' => $lid]);
            $success = 'Location status updated.';

        } elseif ($action === 'delete') {
            $lid = (int)($_POST['location_id'] ?? 0);
            // Submitted records keep their history: location_id is set to NULL on delete.
            $pdo->prepare('DELETE FROM rig_locations WHERE id = :id')->execute([':id' => $lid]);
            $success = 'Location deleted.';
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'Location updated successfully.';
}

// ── Load all locations ──────────────────────────────────────────────────────
$locations = $pdo->query(
    'SELECT * FROM rig_locations ORDER BY sort_order ASC, name ASC'
)->fetchAll();

// ── Edit mode ────────────────────────────────────────────────────────────────
$edit_loc = null;
$edit_id  = (int)($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $e = $pdo->prepare('SELECT * FROM rig_locations WHERE id = :id LIMIT 1');
    $e->execute([':id' => $edit_id]);
    $edit_loc = $e->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rig Locations — <?= h($settings['company_name']) ?></title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/dashboard.css">
<style>
.loc-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.loc-form-grid .span2{grid-column:1/-1}
.form-hint{font-size:12px;color:#94a3b8;margin-top:4px}
.edit-banner{background:linear-gradient(135deg,#1B3A1B,#245924);color:#fff;border-radius:12px;padding:20px 24px;margin-bottom:24px}
.edit-banner h3{margin:0 0 4px;color:#44B944;font-size:16px}
.edit-banner p{margin:0;font-size:13px;opacity:.8}
.inactive-row{opacity:.5}
.loc-code-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:#e2e8f0;color:#475569}
@media(max-width:640px){.loc-form-grid{grid-template-columns:1fr}}
</style>
</head>
<body class="dashboard-body">
<div class="dashboard-layout">

<?php require_once ROOT . '/includes/sidebar.php'; ?>

<div class="main-content">
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" id="mobileMenuBtn">☰</button>
      <h1 class="topbar-title">Rig Locations</h1>
    </div>
    <div class="topbar-right">
      <span class="topbar-date"><?= date('l, d F Y') ?></span>
      <div class="topbar-avatar"><?= mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    </div>
  </div>

  <div class="page-content">

    <?php if ($success): ?>
      <div class="alert alert-success" id="pageAlert"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <p class="text-muted" style="margin:-4px 0 20px;max-width:680px">
      Locations appear as a selection step after a visitor picks a department, so every
      feedback, safety observation and medical record captures where the observer is based.
    </p>

    <!-- ── Edit Mode Banner ─────────────────────────────────────────────── -->
    <?php if ($edit_loc): ?>
    <div class="edit-banner">
      <h3>✏️ Editing Location #<?= $edit_loc['id'] ?></h3>
      <p><?= h($edit_loc['name']) ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Add / Edit Form ─────────────────────────────────────────────── -->
    <div class="card mb-4">
      <div class="card-header">
        <h3><?= $edit_loc ? 'Edit Location' : 'Add New Location' ?></h3>
        <?php if ($edit_loc): ?>
          <a href="<?= BASE_URL ?>/dashboard/locations.php" class="btn btn-sm btn-outline">✕ Cancel Edit</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" action="">
          <input type="hidden" name="_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="<?= $edit_loc ? 'edit' : 'add' ?>">
          <?php if ($edit_loc): ?>
            <input type="hidden" name="edit_id" value="<?= $edit_loc['id'] ?>">
          <?php endif; ?>

          <div class="loc-form-grid">
            <div class="form-group span2">
              <label class="form-label">Location Name <span style="color:#dc2626">*</span></label>
              <input type="text" name="name" class="form-input"
                     value="<?= h($edit_loc['name'] ?? '') ?>"
                     placeholder="e.g. Offshore Platform A" required maxlength="150">
            </div>

            <div class="form-group">
              <label class="form-label">Code / Short Tag</label>
              <input type="text" name="code" class="form-input"
                     value="<?= h($edit_loc['code'] ?? '') ?>"
                     placeholder="e.g. PLAT-A" maxlength="50">
              <p class="form-hint">Optional short identifier shown on the location card.</p>
            </div>

            <div class="form-group">
              <label class="form-label">Sort Order</label>
              <input type="number" name="sort_order" class="form-input" min="0"
                     value="<?= (int)($edit_loc['sort_order'] ?? 0) ?>" style="width:120px">
              <p class="form-hint">Lower numbers appear first.</p>
            </div>

            <div class="form-group span2" style="display:flex;align-items:center;gap:10px">
              <label class="toggle-switch" style="flex-shrink:0">
                <input type="checkbox" name="is_active" value="1"
                       <?= (!$edit_loc || $edit_loc['is_active']) ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <div>
                <strong style="font-size:14px">Active</strong>
                <div style="font-size:12px;color:#94a3b8">Only active locations are shown to visitors.</div>
              </div>
            </div>
          </div>

          <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-primary">
              <?= $edit_loc ? '💾 Save Changes' : '➕ Add Location' ?>
            </button>
            <?php if ($edit_loc): ?>
              <a href="<?= BASE_URL ?>/dashboard/locations.php" class="btn btn-outline">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Locations Table ──────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <h3>All Locations</h3>
        <span class="chart-badge"><?= count($locations) ?> total</span>
      </div>
      <div class="card-body" style="padding:0">
        <?php if (empty($locations)): ?>
          <div class="empty-state" style="padding:40px">
            <div class="empty-icon">📍</div>
            <h4>No locations yet</h4>
            <p>Add your first rig location using the form above.</p>
          </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Name</th>
                <th>Code</th>
                <th style="width:60px">Sort</th>
                <th style="width:80px">Status</th>
                <th style="width:140px">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($locations as $loc): ?>
              <tr class="<?= !$loc['is_active'] ? 'inactive-row' : '' ?>">
                <td class="td-id"><?= $loc['id'] ?></td>
                <td style="font-weight:500"><?= h($loc['name']) ?></td>
                <td><?= $loc['code'] ? '<span class="loc-code-badge">' . h($loc['code']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                <td><?= (int)$loc['sort_order'] ?></td>
                <td>
                  <span class="badge badge-<?= $loc['is_active'] ? 'new' : 'reviewed' ?>">
                    <?= $loc['is_active'] ? 'Active' : 'Inactive' ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <a href="?edit=<?= $loc['id'] ?>" class="btn btn-sm btn-outline" title="Edit">✏️</a>

                    <form method="POST" action="" style="display:inline">
                      <input type="hidden" name="_token"       value="<?= h($csrf) ?>">
                      <input type="hidden" name="action"       value="toggle">
                      <input type="hidden" name="location_id"  value="<?= $loc['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline" title="<?= $loc['is_active'] ? 'Deactivate' : 'Activate' ?>">
                        <?= $loc['is_active'] ? '⏸' : '▶️' ?>
                      </button>
                    </form>

                    <form method="POST" action="" style="display:inline"
                          onsubmit="return confirm('Delete this location? Existing records will keep their data but lose the location link.')">
                      <input type="hidden" name="_token"       value="<?= h($csrf) ?>">
                      <input type="hidden" name="action"       value="delete">
                      <input type="hidden" name="location_id"  value="<?= $loc['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline" style="color:#dc2626" title="Delete">🗑</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /page-content -->
</div><!-- /main-content -->
</div><!-- /dashboard-layout -->

<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
