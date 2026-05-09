<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_admin();

$settings     = get_site_settings($pdo);
$current_page = 'export';

$all_depts = $pdo->query('SELECT id, name FROM departments ORDER BY sort_order, name')->fetchAll();

$filters = [
    'department_id' => $_GET['department_id'] ?? '',
    'category'      => $_GET['category']      ?? '',
    'date_from'     => $_GET['date_from']      ?? '',
    'date_to'       => $_GET['date_to']        ?? '',
];
$qs = http_build_query(array_filter($filters, fn($v) => $v !== ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Export — <?= h($settings['company_name']) ?></title>
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
      <h1 class="topbar-title">Export Data</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-avatar"><?= mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    </div>
  </div>

  <div class="page-content">
    <div class="card export-card">
      <div class="card-header">
        <h2>Export Feedback Data</h2>
        <p class="text-muted">Apply filters below, then choose your export format.</p>
      </div>
      <div class="card-body">
        <form method="GET" action="" id="exportFilterForm">

          <div class="export-filter-grid">
            <div class="form-group">
              <label class="form-label">Department</label>
              <select name="department_id" class="form-input form-select">
                <option value="">All Departments</option>
                <?php foreach ($all_depts as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= $filters['department_id'] == $d['id'] ? 'selected' : '' ?>>
                    <?= h($d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Category</label>
              <select name="category" class="form-input form-select">
                <option value="">All Categories</option>
                <option value="compliment" <?= $filters['category'] === 'compliment' ? 'selected' : '' ?>>Compliment</option>
                <option value="suggestion" <?= $filters['category'] === 'suggestion' ? 'selected' : '' ?>>Suggestion</option>
                <option value="complaint"  <?= $filters['category'] === 'complaint'  ? 'selected' : '' ?>>Complaint</option>
              </select>
            </div>

            <div class="form-group">
              <label class="form-label">Date From</label>
              <input type="date" name="date_from" class="form-input"
                     value="<?= h($filters['date_from']) ?>">
            </div>

            <div class="form-group">
              <label class="form-label">Date To</label>
              <input type="date" name="date_to" class="form-input"
                     value="<?= h($filters['date_to']) ?>">
            </div>
          </div>

          <div class="export-actions">
            <a href="<?= BASE_URL ?>/dashboard/export-csv.php?<?= $qs ?>"
               id="csvBtn"
               class="export-btn export-btn--csv">
              <div class="export-btn-icon">📄</div>
              <div class="export-btn-body">
                <span class="export-btn-title">Download CSV</span>
                <span class="export-btn-desc">Comma-separated values. Opens in Excel, Sheets, etc.</span>
              </div>
            </a>

            <a href="<?= BASE_URL ?>/dashboard/export-excel.php?<?= $qs ?>"
               id="xlsxBtn"
               class="export-btn export-btn--xlsx">
              <div class="export-btn-icon">📊</div>
              <div class="export-btn-body">
                <span class="export-btn-title">Download Excel</span>
                <span class="export-btn-desc">Formatted .xlsx with styled headers and alternating rows.</span>
              </div>
            </a>
          </div>

          <!-- Update download links on filter change -->
          <div class="export-filter-footer">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="<?= BASE_URL ?>/dashboard/export.php" class="btn btn-outline">Reset</a>
          </div>
        </form>
      </div>
    </div>

    <!-- Info card -->
    <div class="card mt-4">
      <div class="card-body export-info">
        <div class="export-info-item">
          <span class="export-info-icon">💡</span>
          <p>Exports include all feedback matching the selected filters. If no filters are applied, all feedback is exported.</p>
        </div>
        <div class="export-info-item">
          <span class="export-info-icon">🔒</span>
          <p>Exported files may contain personal data for non-anonymous submissions. Handle with care.</p>
        </div>
      </div>
    </div>

  </div>
</div>
</div>

<script>
// Keep export link query strings in sync with the filter form
document.getElementById('exportFilterForm').addEventListener('change', function() {
  const fd   = new FormData(this);
  const params = new URLSearchParams();
  for (const [k, v] of fd.entries()) { if (v) params.set(k, v); }
  const qs = params.toString();
  document.getElementById('csvBtn').href  = '<?= BASE_URL ?>/dashboard/export-csv.php?'  + qs;
  document.getElementById('xlsxBtn').href = '<?= BASE_URL ?>/dashboard/export-excel.php?' + qs;
});
</script>
<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
