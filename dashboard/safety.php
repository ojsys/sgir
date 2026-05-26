<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

$settings     = get_site_settings($pdo);
$current_page = 'safety';

$all_depts = $pdo->query('SELECT id, name FROM departments ORDER BY sort_order, name')->fetchAll();
$all_locs  = $pdo->query('SELECT id, name FROM rig_locations ORDER BY sort_order, name')->fetchAll();

// ── Filters ───────────────────────────────────────────────────────────────
$f_dept       = $_GET['department_id'] ?? '';
$f_loc        = $_GET['location_id']   ?? '';
$f_obs_status = $_GET['obs_status']    ?? '';
$f_status     = $_GET['status']        ?? '';
$f_date_from  = $_GET['date_from']     ?? '';
$f_date_to    = $_GET['date_to']       ?? '';
$f_search     = trim($_GET['search']   ?? '');
$current_page_num = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 20;

$where  = [];
$params = [];

if ($f_dept !== '') {
    $where[]                = 'so.department_id = :dept_id';
    $params[':dept_id']     = (int)$f_dept;
}
if ($f_loc !== '') {
    $where[]                = 'so.location_id = :location_id';
    $params[':location_id'] = (int)$f_loc;
}
if ($f_obs_status !== '') {
    $where[]                = 'so.observation_status = :obs_status';
    $params[':obs_status']  = $f_obs_status;
}
if ($f_status !== '') {
    $where[]                = 'so.status = :status';
    $params[':status']      = $f_status;
}
if ($f_date_from !== '') {
    $where[]                = 'DATE(so.created_at) >= :date_from';
    $params[':date_from']   = $f_date_from;
}
if ($f_date_to !== '') {
    $where[]                = 'DATE(so.created_at) <= :date_to';
    $params[':date_to']     = $f_date_to;
}
if ($f_search !== '') {
    $where[]                = '(so.safety_observation LIKE :s1 OR so.observer_name LIKE :s2 OR so.work_area LIKE :s3)';
    $term                   = '%' . $f_search . '%';
    $params[':s1']          = $term;
    $params[':s2']          = $term;
    $params[':s3']          = $term;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cstmt = $pdo->prepare("SELECT COUNT(*) FROM safety_observations so {$whereSql}");
$cstmt->execute($params);
$total = (int)$cstmt->fetchColumn();

$last_page    = max(1, (int)ceil($total / $per_page));
$current_page_num = min($current_page_num, $last_page);
$offset       = ($current_page_num - 1) * $per_page;

$stmt = $pdo->prepare(
    "SELECT so.*, d.name AS dept_name, d.icon AS dept_icon, rl.name AS loc_name
     FROM safety_observations so
     LEFT JOIN departments d ON d.id = so.department_id
     LEFT JOIN rig_locations rl ON rl.id = so.location_id
     {$whereSql}
     ORDER BY so.created_at DESC
     LIMIT {$per_page} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$qs_parts = ['department_id' => $f_dept, 'location_id' => $f_loc, 'obs_status' => $f_obs_status, 'status' => $f_status,
             'date_from' => $f_date_from, 'date_to' => $f_date_to, 'search' => $f_search];
$qs_base  = http_build_query(array_filter($qs_parts, fn($v) => $v !== ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Safety Observations — <?= h($settings['company_name']) ?></title>
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
      <h1 class="topbar-title">Safety Observations</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-avatar"><?= mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    </div>
  </div>

  <div class="page-content">

    <!-- Filters -->
    <div class="card filter-card">
      <form method="GET" action="" id="filterForm">
        <div class="filter-grid">
          <div class="form-group">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-input form-select auto-submit">
              <option value="">All Departments</option>
              <?php foreach ($all_depts as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $f_dept == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Rig Location</label>
            <select name="location_id" class="form-input form-select auto-submit">
              <option value="">All Locations</option>
              <?php foreach ($all_locs as $l): ?>
                <option value="<?= $l['id'] ?>" <?= $f_loc == $l['id'] ? 'selected' : '' ?>><?= h($l['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Observation Status</label>
            <select name="obs_status" class="form-input form-select auto-submit">
              <option value="">All</option>
              <option value="open"  <?= $f_obs_status === 'open'  ? 'selected' : '' ?>>Open</option>
              <option value="close" <?= $f_obs_status === 'close' ? 'selected' : '' ?>>Closed</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Review Status</label>
            <select name="status" class="form-input form-select auto-submit">
              <option value="">All Statuses</option>
              <option value="new"      <?= $f_status === 'new'      ? 'selected' : '' ?>>New</option>
              <option value="reviewed" <?= $f_status === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
              <option value="actioned" <?= $f_status === 'actioned' ? 'selected' : '' ?>>Actioned</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">From</label>
            <input type="date" name="date_from" class="form-input" value="<?= h($f_date_from) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">To</label>
            <input type="date" name="date_to" class="form-input" value="<?= h($f_date_to) ?>">
          </div>
          <div class="form-group filter-search">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-input" placeholder="Observation, observer, work area..."
                   value="<?= h($f_search) ?>">
          </div>
          <div class="form-group filter-actions">
            <label class="form-label">&nbsp;</label>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary">Filter</button>
              <a href="<?= BASE_URL ?>/dashboard/safety.php" class="btn btn-outline">Reset</a>
            </div>
          </div>
        </div>
      </form>
    </div>

    <!-- Results -->
    <div class="card">
      <div class="card-header flex-between">
        <h3><?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?></h3>
      </div>

      <?php if (empty($rows)): ?>
        <div class="empty-state">
          <div class="empty-icon">🔍</div>
          <h4>No safety observations found</h4>
          <p>Safety observations submitted through the portal will appear here.</p>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Date</th>
              <th>Observer</th>
              <th>Location</th>
              <th>Work Area</th>
              <th>Obs. Status</th>
              <th>Types</th>
              <th>Review Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
            <?php
              $types = [];
              if ($row['stop_work_authority']) $types[] = '🛑 SWA';
              if ($row['is_safe'])             $types[] = '✅ Safe';
              if ($row['unsafe_act'])          $types[] = '⚡ Unsafe Act';
              if ($row['unsafe_condition'])    $types[] = '⚠️ Unsafe Cond.';
              if ($row['near_miss'])           $types[] = '💥 Near Miss';
            ?>
            <tr>
              <td class="td-id"><?= $row['id'] ?></td>
              <td class="td-date"><?= format_date($row['created_at']) ?></td>
              <td><?= h($row['observer_name']) ?></td>
              <td><?= !empty($row['loc_name']) ? '📍 ' . h($row['loc_name']) : '<span class="text-muted">—</span>' ?></td>
              <td><?= h(mb_substr($row['work_area'], 0, 40)) ?></td>
              <td>
                <span class="badge badge-<?= $row['observation_status'] === 'open' ? 'complaint' : 'compliment' ?>">
                  <?= $row['observation_status'] === 'open' ? 'Open' : 'Closed' ?>
                </span>
              </td>
              <td style="font-size:12px"><?= implode(', ', $types) ?: '—' ?></td>
              <td><span class="badge badge-<?= h($row['status']) ?>"><?= ucfirst(h($row['status'])) ?></span></td>
              <td>
                <a href="<?= BASE_URL ?>/dashboard/safety-detail.php?id=<?= $row['id'] ?>"
                   class="btn btn-sm btn-outline">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($last_page > 1): ?>
      <div class="pagination-wrap">
        <div class="pagination">
          <?php if ($current_page_num > 1): ?>
            <a href="?<?= $qs_base ?>&page=<?= $current_page_num - 1 ?>" class="page-btn">← Prev</a>
          <?php endif; ?>
          <?php for ($p = max(1, $current_page_num - 2); $p <= min($last_page, $current_page_num + 2); $p++): ?>
            <a href="?<?= $qs_base ?>&page=<?= $p ?>"
               class="page-btn <?= $p === $current_page_num ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($current_page_num < $last_page): ?>
            <a href="?<?= $qs_base ?>&page=<?= $current_page_num + 1 ?>" class="page-btn">Next →</a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>
</div>
</div>

<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
