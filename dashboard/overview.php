<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

$settings     = get_site_settings($pdo);
$current_page = 'overview';

// Flash message from require_admin() redirect
$access_error = '';
if (!empty($_SESSION['access_error'])) {
    $access_error = $_SESSION['access_error'];
    unset($_SESSION['access_error']);
}

// ── Stats ──────────────────────────────────────────────────────────────────
$total_feedback = (int)$pdo->query('SELECT COUNT(*) FROM feedback')->fetchColumn();
$total_safety   = (int)$pdo->query('SELECT COUNT(*) FROM safety_observations')->fetchColumn();
$total_medical  = (int)$pdo->query('SELECT COUNT(*) FROM medical_feedback')->fetchColumn();
$total_all      = $total_feedback + $total_safety + $total_medical;

$new_count_fb   = (int)$pdo->query("SELECT COUNT(*) FROM feedback WHERE status = 'new'")->fetchColumn();
$new_count_sa   = (int)$pdo->query("SELECT COUNT(*) FROM safety_observations WHERE status = 'new'")->fetchColumn();
$new_count_me   = (int)$pdo->query("SELECT COUNT(*) FROM medical_feedback WHERE status = 'new'")->fetchColumn();
$new_count      = $new_count_fb + $new_count_sa + $new_count_me;

$avg_rating_row = $pdo->query('SELECT AVG(rating) FROM feedback WHERE rating > 0')->fetchColumn();
$avg_rating     = $avg_rating_row ? number_format((float)$avg_rating_row, 1) : '—';
$date_30ago     = date('Y-m-d H:i:s', strtotime('-30 days'));
$stmt_30        = $pdo->prepare(
    "SELECT (SELECT COUNT(*) FROM feedback WHERE created_at >= :s1) +
            (SELECT COUNT(*) FROM safety_observations WHERE created_at >= :s2) +
            (SELECT COUNT(*) FROM medical_feedback WHERE created_at >= :s3) AS cnt"
);
$stmt_30->execute([':s1' => $date_30ago, ':s2' => $date_30ago, ':s3' => $date_30ago]);
$last30         = (int)$stmt_30->fetchColumn();

// ── 14-day trend ───────────────────────────────────────────────────────────
$date_13ago  = date('Y-m-d', strtotime('-13 days'));
$stmt_trend  = $pdo->prepare(
    "SELECT date(created_at) AS day, COUNT(*) AS cnt
     FROM feedback
     WHERE date(created_at) >= :since
     GROUP BY date(created_at)
     ORDER BY day ASC"
);
$stmt_trend->execute([':since' => $date_13ago]);
$trend_rows  = $stmt_trend->fetchAll();

$trend_map = [];
foreach ($trend_rows as $r) {
    $trend_map[$r['day']] = (int)$r['cnt'];
}

$trend_labels = [];
$trend_data   = [];
for ($i = 13; $i >= 0; $i--) {
    $d  = date('Y-m-d', strtotime("-{$i} days"));
    $trend_labels[] = date('d M', strtotime($d));
    $trend_data[]   = $trend_map[$d] ?? 0;
}

// ── Category breakdown ─────────────────────────────────────────────────────
$cat_rows = $pdo->query(
    "SELECT category, COUNT(*) AS cnt FROM feedback GROUP BY category"
)->fetchAll();
$cat_map = ['compliment' => 0, 'suggestion' => 0, 'complaint' => 0];
foreach ($cat_rows as $r) {
    $cat_map[$r['category']] = (int)$r['cnt'];
}

// ── Top departments ────────────────────────────────────────────────────────
$dept_rows = $pdo->query(
    "SELECT d.name, COUNT(f.id) AS cnt
     FROM feedback f
     LEFT JOIN departments d ON d.id = f.department_id
     GROUP BY f.department_id, d.name
     ORDER BY cnt DESC
     LIMIT 6"
)->fetchAll();
$dept_labels = array_column($dept_rows, 'name');
$dept_data   = array_map(fn($r) => (int)$r['cnt'], $dept_rows);

// ── Rating distribution ────────────────────────────────────────────────────
$rating_rows = $pdo->query(
    "SELECT rating, COUNT(*) AS cnt FROM feedback WHERE rating > 0 GROUP BY rating ORDER BY rating"
)->fetchAll();
$rating_map = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach ($rating_rows as $r) {
    $rating_map[(int)$r['rating']] = (int)$r['cnt'];
}

// ── Recent activity (feedback + safety + medical combined) ──────────────────
$recent_fb = $pdo->query(
    "SELECT f.id, f.created_at, f.status, f.is_anonymous, f.submitter_name,
            f.category, f.rating, f.message, f.other_department,
            d.name AS dept_name, d.icon AS dept_icon
     FROM feedback f
     LEFT JOIN departments d ON d.id = f.department_id
     ORDER BY f.created_at DESC LIMIT 10"
)->fetchAll();

$recent_sa = $pdo->query(
    "SELECT s.id, s.created_at, s.status, s.observer_name,
            s.safety_observation, s.observation_status, s.stop_work_authority,
            d.name AS dept_name, d.icon AS dept_icon
     FROM safety_observations s
     LEFT JOIN departments d ON d.id = s.department_id
     ORDER BY s.created_at DESC LIMIT 10"
)->fetchAll();

$recent_me = $pdo->query(
    "SELECT m.id, m.created_at, m.status, m.is_anonymous, m.observer_name,
            m.visit_reason, m.overall_rating, m.comments, m.urgent_review,
            d.name AS dept_name, d.icon AS dept_icon
     FROM medical_feedback m
     LEFT JOIN departments d ON d.id = m.department_id
     ORDER BY m.created_at DESC LIMIT 10"
)->fetchAll();

$recent = [];

foreach ($recent_fb as $r) {
    $recent[] = [
        'type'       => 'Feedback',
        'type_class' => 'feedback',
        'id'         => (int)$r['id'],
        'created_at' => $r['created_at'],
        'dept_icon'  => $r['dept_icon'] ?? '💬',
        'dept_name'  => $r['dept_name'] ?? $r['other_department'] ?? 'General',
        'detail'     => '<span class="badge badge-' . h($r['category']) . '">' . ucfirst(h($r['category'])) . '</span>',
        'rating'     => (int)$r['rating'],
        'message'    => (string)$r['message'],
        'submitter'  => $r['is_anonymous'] ? null : ($r['submitter_name'] ?: null),
        'status'     => $r['status'],
        'url'        => BASE_URL . '/dashboard/feedback-detail.php?id=' . (int)$r['id'],
    ];
}

foreach ($recent_sa as $r) {
    $detail = $r['stop_work_authority']
        ? '🛑 Stop Work'
        : ($r['observation_status'] === 'open' ? '🔴 Open' : '✅ Closed');
    $recent[] = [
        'type'       => 'Safety',
        'type_class' => 'safety',
        'id'         => (int)$r['id'],
        'created_at' => $r['created_at'],
        'dept_icon'  => $r['dept_icon'] ?? '⚠️',
        'dept_name'  => $r['dept_name'] ?? 'Safety',
        'detail'     => '<span class="text-muted">' . h($detail) . '</span>',
        'rating'     => 0,
        'message'    => (string)$r['safety_observation'],
        'submitter'  => $r['observer_name'] ?: null,
        'status'     => $r['status'],
        'url'        => BASE_URL . '/dashboard/safety-detail.php?id=' . (int)$r['id'],
    ];
}

$reason_labels = [
    'injury' => '🤕 Injury', 'illness' => '🤒 Illness', 'routine' => '🩺 Routine',
    'medication' => '💊 Medication', 'emergency' => '🚨 Emergency',
    'mental_health' => '🧠 Mental Health', 'other' => '💬 Other',
];
foreach ($recent_me as $r) {
    $recent[] = [
        'type'       => 'Medical',
        'type_class' => 'medical',
        'id'         => (int)$r['id'],
        'created_at' => $r['created_at'],
        'dept_icon'  => $r['dept_icon'] ?? '🏥',
        'dept_name'  => $r['dept_name'] ?? 'Medical Clinic',
        'detail'     => '<span class="text-muted">' . h($reason_labels[$r['visit_reason']] ?? ucfirst((string)$r['visit_reason'])) . '</span>',
        'rating'     => (int)$r['overall_rating'],
        'message'    => $r['urgent_review'] ? '⚠️ Urgent review — ' . (string)$r['comments'] : (string)$r['comments'],
        'submitter'  => $r['is_anonymous'] ? null : ($r['observer_name'] ?: null),
        'status'     => $r['status'],
        'url'        => BASE_URL . '/dashboard/medical-detail.php?id=' . (int)$r['id'],
    ];
}

usort($recent, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
$recent = array_slice($recent, 0, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard Overview — <?= h($settings['company_name']) ?></title>
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

<!-- ─── Main ──────────────────────────────────────────────────────────────── -->
<div class="main-content">
  <!-- Top bar -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle sidebar">☰</button>
      <h1 class="topbar-title">Overview</h1>
    </div>
    <div class="topbar-right">
      <span class="topbar-date"><?= date('l, d F Y') ?></span>
      <div class="topbar-avatar"><?= mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    </div>
  </div>

  <div class="page-content">

    <?php if ($access_error): ?>
      <div class="alert alert-error"><?= h($access_error) ?></div>
    <?php endif; ?>

    <!-- ─── Stats Grid ──────────────────────────────────────────────────── -->
    <div class="stats-grid">
      <div class="stat-card stat-card--accent">
        <div class="stat-icon">💬</div>
        <div class="stat-body">
          <div class="stat-value"><?= number_format($total_all) ?></div>
          <div class="stat-label">Total Submissions</div>
        </div>
      </div>
      <div class="stat-card stat-card--warning">
        <div class="stat-icon">🔔</div>
        <div class="stat-body">
          <div class="stat-value"><?= number_format($new_count) ?></div>
          <div class="stat-label">Awaiting Review</div>
        </div>
      </div>
      <div class="stat-card" style="background:linear-gradient(135deg,#fff1f1,#fff);border-left:4px solid #dc2626;">
        <div class="stat-icon">⚠️</div>
        <div class="stat-body">
          <div class="stat-value"><?= number_format($total_safety) ?></div>
          <div class="stat-label">Safety Observations</div>
        </div>
      </div>
      <div class="stat-card stat-card--info">
        <div class="stat-icon">🏥</div>
        <div class="stat-body">
          <div class="stat-value"><?= number_format($total_medical) ?></div>
          <div class="stat-label">Medical Feedback</div>
        </div>
      </div>
    </div>

    <!-- ─── Charts Row ──────────────────────────────────────────────────── -->
    <div class="charts-row">
      <div class="chart-card chart-card--wide">
        <div class="chart-card-header">
          <h3>14-Day Trend</h3>
          <span class="chart-badge">Last 2 weeks</span>
        </div>
        <div class="chart-container">
          <canvas id="trendChart" width="600" height="220"></canvas>
        </div>
      </div>
      <div class="chart-card">
        <div class="chart-card-header">
          <h3>Categories</h3>
        </div>
        <div class="chart-container">
          <canvas id="categoryChart" width="300" height="220"></canvas>
        </div>
      </div>
    </div>

    <div class="charts-row">
      <div class="chart-card">
        <div class="chart-card-header">
          <h3>Top Departments</h3>
        </div>
        <div class="chart-container">
          <canvas id="deptChart" width="300" height="220"></canvas>
        </div>
      </div>
      <div class="chart-card">
        <div class="chart-card-header">
          <h3>Rating Distribution</h3>
        </div>
        <div class="chart-container">
          <canvas id="ratingChart" width="300" height="220"></canvas>
        </div>
      </div>
    </div>

    <!-- ─── Recent Activity ─────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header flex-between">
        <h3>Recent Activity</h3>
        <a href="<?= BASE_URL ?>/dashboard/feedback.php" class="btn btn-sm btn-outline">View All</a>
      </div>
      <?php if (empty($recent)): ?>
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <h4>No submissions yet</h4>
          <p>Feedback, safety observations, and medical feedback will appear here.</p>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Date</th>
              <th>Department</th>
              <th>Detail</th>
              <th>Rating</th>
              <th>Message</th>
              <th>Submitter</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $row): ?>
            <tr onclick="window.location='<?= h($row['url']) ?>'" style="cursor:pointer;">
              <td><span class="badge badge-<?= h($row['type_class']) ?>"><?= h($row['type']) ?></span></td>
              <td class="td-date"><?= format_date($row['created_at']) ?></td>
              <td>
                <span class="dept-badge">
                  <?= $row['dept_icon'] ?> <?= h($row['dept_name']) ?>
                </span>
              </td>
              <td><?= $row['detail'] ?></td>
              <td class="td-stars"><?= $row['rating'] > 0 ? star_rating($row['rating']) : '<span class="text-muted">—</span>' ?></td>
              <td class="td-msg"><?= $row['message'] !== '' ? h(mb_substr($row['message'], 0, 80)) . (mb_strlen($row['message']) > 80 ? '…' : '') : '<span class="text-muted">—</span>' ?></td>
              <td><?= $row['submitter'] === null ? '<em class="text-muted">Anonymous</em>' : h($row['submitter']) ?></td>
              <td><span class="badge badge-<?= h($row['status']) ?>"><?= ucfirst(h($row['status'])) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /page-content -->
</div><!-- /main-content -->
</div><!-- /dashboard-layout -->

<script>
window.chartData = {
  trend:    { labels: <?= json_encode($trend_labels) ?>, data: <?= json_encode($trend_data) ?> },
  category: { labels: ['Compliments','Suggestions','Complaints'], data: [<?= $cat_map['compliment'] ?>,<?= $cat_map['suggestion'] ?>,<?= $cat_map['complaint'] ?>] },
  dept:     { labels: <?= json_encode($dept_labels) ?>, data: <?= json_encode($dept_data) ?> },
  rating:   { labels: ['1★','2★','3★','4★','5★'], data: [<?= implode(',', array_values($rating_map)) ?>] }
};
</script>
<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
