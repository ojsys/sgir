<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

$settings     = get_site_settings($pdo);
$current_page = 'feedback';

// ── Departments for filter select ─────────────────────────────────────────
$all_depts = $pdo->query('SELECT id, name FROM departments ORDER BY sort_order, name')->fetchAll();

// ── Build filters ─────────────────────────────────────────────────────────
$filters = [
    'department_id' => $_GET['department_id'] ?? '',
    'category'      => $_GET['category']      ?? '',
    'status'        => $_GET['status']        ?? '',
    'rating'        => $_GET['rating']        ?? '',
    'date_from'     => $_GET['date_from']     ?? '',
    'date_to'       => $_GET['date_to']       ?? '',
    'search'        => $_GET['search']        ?? '',
    'page'          => (int)($_GET['page']    ?? 1),
    'per_page'      => 20,
];

$result     = apply_feedback_filters($pdo, $filters);
$rows       = $result['stmt']->fetchAll();
$pagination = $result['pagination'];
$total      = $result['total'];

// ── Build query string for pagination links ───────────────────────────────
$qs_parts = $filters;
unset($qs_parts['page'], $qs_parts['per_page']);
$qs_base = http_build_query(array_filter($qs_parts, fn($v) => $v !== ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Feedback List — <?= h($settings['company_name']) ?></title>
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
      <h1 class="topbar-title">Feedback</h1>
    </div>
    <div class="topbar-right">
      <a href="<?= BASE_URL ?>/dashboard/export.php" class="btn btn-sm btn-outline">📥 Export</a>
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
                <option value="<?= $d['id'] ?>" <?= $filters['department_id'] == $d['id'] ? 'selected' : '' ?>>
                  <?= h($d['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <select name="category" class="form-input form-select auto-submit">
              <option value="">All Categories</option>
              <option value="compliment" <?= $filters['category'] === 'compliment' ? 'selected' : '' ?>>Compliment</option>
              <option value="suggestion" <?= $filters['category'] === 'suggestion' ? 'selected' : '' ?>>Suggestion</option>
              <option value="complaint"  <?= $filters['category'] === 'complaint'  ? 'selected' : '' ?>>Complaint</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-input form-select auto-submit">
              <option value="">All Statuses</option>
              <option value="new"      <?= $filters['status'] === 'new'      ? 'selected' : '' ?>>New</option>
              <option value="reviewed" <?= $filters['status'] === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
              <option value="actioned" <?= $filters['status'] === 'actioned' ? 'selected' : '' ?>>Actioned</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Rating</label>
            <select name="rating" class="form-input form-select auto-submit">
              <option value="">Any Rating</option>
              <?php for ($i = 5; $i >= 1; $i--): ?>
                <option value="<?= $i ?>" <?= $filters['rating'] == $i ? 'selected' : '' ?>><?= str_repeat('★', $i) ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">From</label>
            <input type="date" name="date_from" class="form-input" value="<?= h($filters['date_from']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">To</label>
            <input type="date" name="date_to" class="form-input" value="<?= h($filters['date_to']) ?>">
          </div>
          <div class="form-group filter-search">
            <label class="form-label">Search</label>
            <input type="text" name="search" class="form-input" placeholder="Search messages, names..."
                   value="<?= h($filters['search']) ?>">
          </div>
          <div class="form-group filter-actions">
            <label class="form-label">&nbsp;</label>
            <div class="btn-row">
              <button type="submit" class="btn btn-primary">Filter</button>
              <a href="<?= BASE_URL ?>/dashboard/feedback.php" class="btn btn-outline">Reset</a>
            </div>
          </div>
        </div>
      </form>
    </div>

    <!-- Results -->
    <div class="card">
      <div class="card-header flex-between">
        <h3>
          <?= number_format($total) ?> record<?= $total !== 1 ? 's' : '' ?>
          <?php if ($pagination['from'] && $pagination['to']): ?>
            <small class="text-muted">(showing <?= $pagination['from'] ?>–<?= $pagination['to'] ?>)</small>
          <?php endif; ?>
        </h3>
        <a href="<?= BASE_URL ?>/dashboard/export-csv.php?<?= $qs_base ?>" class="btn btn-sm btn-outline">
          📄 Download CSV
        </a>
      </div>

      <?php if (empty($rows)): ?>
        <div class="empty-state">
          <div class="empty-icon">🔍</div>
          <h4>No feedback found</h4>
          <p>Try adjusting your filters.</p>
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Date &amp; Time</th>
              <th>Department</th>
              <th>Category</th>
              <th>Rating</th>
              <th>Message</th>
              <th>Submitter</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
              <td class="td-id"><?= $row['id'] ?></td>
              <td class="td-date"><?= format_date($row['created_at']) ?></td>
              <td>
                <span class="dept-badge">
                  <?= $row['dept_icon'] ?? '💬' ?> <?= h($row['dept_name'] ?? $row['other_department'] ?? 'General') ?>
                </span>
              </td>
              <td><span class="badge badge-<?= h($row['category']) ?>"><?= ucfirst(h($row['category'])) ?></span></td>
              <td class="td-stars"><?= star_rating((int)$row['rating']) ?></td>
              <td class="td-msg"><?= h(mb_substr($row['message'], 0, 80)) ?><?= mb_strlen($row['message']) > 80 ? '…' : '' ?></td>
              <td><?= $row['is_anonymous'] ? '<em class="text-muted">Anon</em>' : h($row['submitter_name'] ?: '—') ?></td>
              <td><span class="badge badge-<?= h($row['status']) ?>"><?= ucfirst(h($row['status'])) ?></span></td>
              <td>
                <a href="<?= BASE_URL ?>/dashboard/feedback-detail.php?id=<?= $row['id'] ?>"
                   class="btn btn-sm btn-outline">View</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($pagination['last_page'] > 1): ?>
      <div class="pagination-wrap">
        <div class="pagination">
          <?php if ($pagination['has_prev']): ?>
            <a href="?<?= $qs_base ?>&page=<?= $pagination['current_page'] - 1 ?>" class="page-btn">← Prev</a>
          <?php endif; ?>

          <?php if ($pagination['pages'][0] > 1): ?>
            <a href="?<?= $qs_base ?>&page=1" class="page-btn">1</a>
            <?php if ($pagination['pages'][0] > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
          <?php endif; ?>

          <?php foreach ($pagination['pages'] as $p): ?>
            <a href="?<?= $qs_base ?>&page=<?= $p ?>"
               class="page-btn <?= $p === $pagination['current_page'] ? 'active' : '' ?>"><?= $p ?></a>
          <?php endforeach; ?>

          <?php if (end($pagination['pages']) < $pagination['last_page']): ?>
            <?php if (end($pagination['pages']) < $pagination['last_page'] - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
            <a href="?<?= $qs_base ?>&page=<?= $pagination['last_page'] ?>" class="page-btn"><?= $pagination['last_page'] ?></a>
          <?php endif; ?>

          <?php if ($pagination['has_next']): ?>
            <a href="?<?= $qs_base ?>&page=<?= $pagination['current_page'] + 1 ?>" class="page-btn">Next →</a>
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
