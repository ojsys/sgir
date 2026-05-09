<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';
require_once ROOT . '/lib/qrcode.php';

start_session();
require_admin();

$settings     = get_site_settings($pdo);
$current_page = 'qrcodes';
$csrf         = csrf_token();

$success = '';
$error   = '';

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'delete') {
            $qr_id = (int)($_POST['qr_id'] ?? 0);
            if ($qr_id > 0) {
                $qr = $pdo->prepare('SELECT * FROM qrcodes WHERE id = :id LIMIT 1');
                $qr->execute([':id' => $qr_id]);
                $qr_row = $qr->fetch();

                if ($qr_row) {
                    // Delete image file
                    $img_path = ROOT . '/' . ltrim($qr_row['image_path'], '/');
                    if ($qr_row['image_path'] && file_exists($img_path)) {
                        @unlink($img_path);
                    }
                    $pdo->prepare('DELETE FROM qrcodes WHERE id = :id')->execute([':id' => $qr_id]);
                    $success = 'QR code deleted.';
                }
            }

        } elseif ($action === 'generate') {
            $dept_id   = (int)($_POST['dept_id'] ?? 0) ?: null;
            $dept_slug = null;

            if ($dept_id) {
                $ds = $pdo->prepare('SELECT slug FROM departments WHERE id = :id LIMIT 1');
                $ds->execute([':id' => $dept_id]);
                $dept_slug = $ds->fetchColumn() ?: null;
            }

            // Build the URL this QR will point to
            $target_url = BASE_URL . '/feedback/index.php';
            // We'll append ?qr=IDENTIFIER after we know the identifier
            // For now generate temp identifier, we'll create it in 2 steps
            $tmp_id = bin2hex(random_bytes(4));
            $qr_url = $target_url . '?qr=' . $tmp_id;
            if ($dept_slug) {
                $qr_url = BASE_URL . '/feedback/form.php?dept=' . urlencode($dept_slug) . '&qr=' . $tmp_id;
            }

            $result = generate_qr_code($qr_url, UPLOAD_DIR);
            if ($result) {
                $ins = $pdo->prepare(
                    'INSERT INTO qrcodes (identifier, department_id, image_path, scan_count, created_at)
                     VALUES (:id, :dept, :path, 0, :created_at)'
                );
                $ins->execute([
                    ':id'         => $result['identifier'],
                    ':dept'       => $dept_id,
                    ':path'       => $result['path'],
                    ':created_at' => date('Y-m-d H:i:s'),
                ]);
                $success = 'QR code generated successfully.';
            } else {
                $error = 'Failed to generate QR code. Check network connectivity to api.qrserver.com.';
            }
        }
    }
}

// ── Load QR codes ─────────────────────────────────────────────────────────
$qr_rows = $pdo->query(
    'SELECT q.*, d.name AS dept_name FROM qrcodes q
     LEFT JOIN departments d ON d.id = q.department_id
     ORDER BY q.created_at DESC'
)->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────
$total_qrs   = count($qr_rows);
$total_scans = array_sum(array_column($qr_rows, 'scan_count'));
$avg_scans   = $total_qrs > 0 ? number_format($total_scans / $total_qrs, 1) : '0';

// ── Departments for form ──────────────────────────────────────────────────
$all_depts = $pdo->query('SELECT id, name, icon FROM departments WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR Codes — <?= h($settings['company_name']) ?></title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/dashboard.css">
</head>
<body class="dashboard-body">
<div class="dashboard-layout">

<?php require_once ROOT . '/includes/sidebar.php'; ?>

<div class="main-content">
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" id="mobileMenuBtn">☰</button>
      <h1 class="topbar-title">QR Codes</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-avatar"><?= mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    </div>
  </div>

  <div class="page-content">

    <?php if ($success): ?><div class="alert alert-success" id="pageAlert"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid stats-grid--3">
      <div class="stat-card stat-card--accent">
        <div class="stat-icon">📱</div>
        <div class="stat-body"><div class="stat-value"><?= $total_qrs ?></div><div class="stat-label">Total QR Codes</div></div>
      </div>
      <div class="stat-card stat-card--info">
        <div class="stat-icon">👁️</div>
        <div class="stat-body"><div class="stat-value"><?= number_format($total_scans) ?></div><div class="stat-label">Total Scans</div></div>
      </div>
      <div class="stat-card stat-card--success">
        <div class="stat-icon">📊</div>
        <div class="stat-body"><div class="stat-value"><?= $avg_scans ?></div><div class="stat-label">Avg Scans / QR</div></div>
      </div>
    </div>

    <!-- Generate form -->
    <div class="card mb-4">
      <div class="card-header"><h3>Generate New QR Code</h3></div>
      <div class="card-body">
        <form method="POST" action="" class="qr-generate-form">
          <input type="hidden" name="_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="generate">
          <div class="qr-generate-row">
            <div class="form-group" style="flex:1;">
              <label class="form-label">Department (optional)</label>
              <select name="dept_id" class="form-input form-select">
                <option value="">General Feedback Portal</option>
                <?php foreach ($all_depts as $d): ?>
                  <option value="<?= $d['id'] ?>"><?= $d['icon'] ?> <?= h($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group qr-generate-btn-wrap">
              <label class="form-label">&nbsp;</label>
              <button type="submit" class="btn btn-accent btn-lg">
                📱 Generate QR Code
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- QR Grid -->
    <?php if (empty($qr_rows)): ?>
      <div class="card">
        <div class="empty-state">
          <div class="empty-icon">📱</div>
          <h4>No QR codes yet</h4>
          <p>Generate your first QR code above.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="qr-grid">
        <?php foreach ($qr_rows as $qr): ?>
          <div class="qr-card">
            <div class="qr-image-wrap">
              <?php if ($qr['image_path'] && file_exists(ROOT . '/' . $qr['image_path'])): ?>
                <img src="<?= BASE_URL . '/' . h($qr['image_path']) ?>"
                     alt="QR Code <?= h($qr['identifier']) ?>"
                     class="qr-image">
              <?php else: ?>
                <div class="qr-image-missing">
                  <span>No image</span>
                </div>
              <?php endif; ?>
            </div>
            <div class="qr-info">
              <div class="qr-identifier"><?= h($qr['identifier']) ?></div>
              <?php if ($qr['dept_name']): ?>
                <span class="dept-badge"><?= h($qr['dept_name']) ?></span>
              <?php else: ?>
                <span class="dept-badge dept-badge--general">General</span>
              <?php endif; ?>
              <div class="qr-meta">
                <span class="qr-meta-item">📅 <?= date('d M Y', strtotime($qr['created_at'])) ?></span>
                <span class="qr-meta-item">👁️ <?= number_format($qr['scan_count']) ?> scan<?= $qr['scan_count'] !== 1 ? 's' : '' ?></span>
              </div>
            </div>
            <div class="qr-actions">
              <?php if ($qr['image_path']): ?>
                <a href="<?= BASE_URL . '/' . h($qr['image_path']) ?>"
                   download="qr-<?= h($qr['identifier']) ?>.png"
                   class="btn btn-sm btn-outline">⬇ Download</a>
              <?php endif; ?>
              <form method="POST" action="" class="qr-delete-form"
                    onsubmit="return confirm('Delete this QR code? This cannot be undone.')">
                <input type="hidden" name="_token"  value="<?= h($csrf) ?>">
                <input type="hidden" name="action"  value="delete">
                <input type="hidden" name="qr_id"   value="<?= $qr['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>
</div>

<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
