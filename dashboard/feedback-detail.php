<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

$settings     = get_site_settings($pdo);
$current_page = 'feedback';
$csrf         = csrf_token();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    redirect(BASE_URL . '/dashboard/feedback.php');
}

// Load feedback row
$stmt = $pdo->prepare(
    'SELECT f.*, d.name AS dept_name, d.icon AS dept_icon, d.slug AS dept_slug,
            rl.name AS loc_name, rl.code AS loc_code
     FROM feedback f
     LEFT JOIN departments d ON d.id = f.department_id
     LEFT JOIN rig_locations rl ON rl.id = f.location_id
     WHERE f.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$feedback = $stmt->fetch();

if (!$feedback) {
    redirect(BASE_URL . '/dashboard/feedback.php');
}

$success = '';
$error   = '';

// Load custom question answers for this feedback entry
$ans_stmt = $pdo->prepare(
    'SELECT fa.answer, dq.question_text, dq.question_type
     FROM feedback_answers fa
     JOIN department_questions dq ON dq.id = fa.question_id
     WHERE fa.feedback_id = :fid
     ORDER BY dq.sort_order ASC, dq.id ASC'
);
$ans_stmt->execute([':fid' => $id]);
$custom_answers = $ans_stmt->fetchAll();

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $new_status     = $_POST['status']      ?? $feedback['status'];
        $new_notes      = trim($_POST['admin_notes'] ?? '');
        $valid_statuses = ['new', 'reviewed', 'actioned'];

        if (!in_array($new_status, $valid_statuses, true)) {
            $error = 'Invalid status selected.';
        } else {
            $reviewed_at = $feedback['reviewed_at'];
            if (
                in_array($new_status, ['reviewed', 'actioned'], true) &&
                !$reviewed_at
            ) {
                $reviewed_at = date('Y-m-d H:i:s');
            }

            $upd = $pdo->prepare(
                'UPDATE feedback SET status = :status, admin_notes = :notes, reviewed_at = :rev
                 WHERE id = :id'
            );
            $upd->execute([
                ':status' => $new_status,
                ':notes'  => $new_notes ?: null,
                ':rev'    => $reviewed_at,
                ':id'     => $id,
            ]);

            $success = 'Feedback updated successfully.';

            // Refresh
            $stmt->execute([':id' => $id]);
            $feedback = $stmt->fetch();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Feedback #<?= $id ?> — <?= h($settings['company_name']) ?></title>
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
      <h1 class="topbar-title">Feedback #<?= $id ?></h1>
    </div>
    <div class="topbar-right">
      <a href="<?= BASE_URL ?>/dashboard/feedback.php" class="btn btn-sm btn-outline">← Back to List</a>
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

    <div class="detail-layout">

      <!-- Left column: Feedback content -->
      <div class="detail-main">
        <!-- Metadata badges -->
        <div class="card mb-4">
          <div class="card-header">
            <div class="detail-badges">
              <span class="dept-badge">
                <?= $feedback['dept_icon'] ?? '💬' ?>
                <?= h($feedback['dept_name'] ?? $feedback['other_department'] ?? 'General') ?>
              </span>
              <?php if (!empty($feedback['loc_name'])): ?>
                <span class="dept-badge">📍 <?= h($feedback['loc_name']) ?></span>
              <?php endif; ?>
              <span class="badge badge-<?= h($feedback['category']) ?>">
                <?= ucfirst(h($feedback['category'])) ?>
              </span>
              <span class="badge badge-<?= h($feedback['status']) ?>">
                <?= ucfirst(h($feedback['status'])) ?>
              </span>
            </div>
            <div class="detail-rating">
              <span class="star-display"><?= star_rating((int)$feedback['rating']) ?></span>
              <span class="rating-num"><?= $feedback['rating'] ?>/5</span>
            </div>
          </div>
          <div class="card-body">
            <div class="detail-message">
              <?= nl2br(h($feedback['message'])) ?>
            </div>
          </div>
        </div>

        <!-- Submitter info -->
        <div class="card mb-4">
          <div class="card-header"><h3>Submitter Information</h3></div>
          <div class="card-body">
            <?php if ($feedback['is_anonymous']): ?>
              <div class="anon-notice">
                <span class="anon-notice-icon">🔒</span>
                <span>This feedback was submitted anonymously.</span>
              </div>
            <?php else: ?>
              <div class="detail-info-grid">
                <div class="detail-info-item">
                  <span class="detail-info-label">Name</span>
                  <span class="detail-info-value"><?= h($feedback['submitter_name'] ?: '—') ?></span>
                </div>
                <div class="detail-info-item">
                  <span class="detail-info-label">Email</span>
                  <span class="detail-info-value">
                    <?php if ($feedback['email']): ?>
                      <a href="mailto:<?= h($feedback['email']) ?>"><?= h($feedback['email']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                  </span>
                </div>
                <div class="detail-info-item">
                  <span class="detail-info-label">Phone</span>
                  <span class="detail-info-value"><?= h($feedback['phone'] ?: '—') ?></span>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Timestamps -->
        <div class="card <?= !empty($custom_answers) ? 'mb-4' : '' ?>">
          <div class="card-body">
            <div class="detail-info-grid">
              <div class="detail-info-item">
                <span class="detail-info-label">Submitted</span>
                <span class="detail-info-value"><?= format_date($feedback['created_at']) ?></span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Reviewed</span>
                <span class="detail-info-value"><?= $feedback['reviewed_at'] ? format_date($feedback['reviewed_at']) : '—' ?></span>
              </div>
            </div>
          </div>
        </div>

        <?php if (!empty($custom_answers)): ?>
        <!-- Custom Question Answers -->
        <div class="card">
          <div class="card-header"><h3>Additional Questions</h3></div>
          <div class="card-body">
            <div class="detail-info-grid" style="grid-template-columns:1fr">
              <?php foreach ($custom_answers as $ans): ?>
              <div class="detail-info-item">
                <span class="detail-info-label"><?= h($ans['question_text']) ?></span>
                <span class="detail-info-value">
                  <?= $ans['answer'] !== null && $ans['answer'] !== ''
                      ? h($ans['answer'])
                      : '<em class="text-muted">No answer</em>' ?>
                </span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Right column: Admin actions -->
      <div class="detail-sidebar">
        <div class="card">
          <div class="card-header"><h3>Update Status</h3></div>
          <div class="card-body">
            <form method="POST" action="">
              <input type="hidden" name="_token" value="<?= h($csrf) ?>">

              <div class="form-group">
                <label class="form-label">Status</label>
                <div class="status-radio-group">
                  <?php foreach (['new', 'reviewed', 'actioned'] as $s): ?>
                    <label class="status-radio-btn status-radio-<?= $s ?>">
                      <input type="radio" name="status" value="<?= $s ?>"
                             <?= $feedback['status'] === $s ? 'checked' : '' ?>>
                      <span class="status-radio-label"><?= ucfirst($s) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="form-group">
                <label class="form-label" for="admin_notes">Admin Notes</label>
                <textarea name="admin_notes" id="admin_notes" class="form-textarea" rows="5"
                  placeholder="Internal notes about this feedback..."><?= h($feedback['admin_notes'] ?? '') ?></textarea>
              </div>

              <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">Save Changes</button>
            </form>
          </div>
        </div>

        <div class="card mt-4">
          <div class="card-body">
            <p class="text-muted text-sm">
              Feedback ID: <strong>#<?= $id ?></strong><br>
              Department: <strong><?= h($feedback['dept_name'] ?? 'General') ?></strong>
            </p>
          </div>
        </div>
      </div>

    </div><!-- /detail-layout -->
  </div><!-- /page-content -->
</div><!-- /main-content -->
</div><!-- /dashboard-layout -->

<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
