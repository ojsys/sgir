<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

$settings     = get_site_settings($pdo);
$current_page = 'safety';
$csrf         = csrf_token();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) redirect(BASE_URL . '/dashboard/safety.php');

$stmt = $pdo->prepare(
    'SELECT so.*, d.name AS dept_name, d.icon AS dept_icon
     FROM safety_observations so
     LEFT JOIN departments d ON d.id = so.department_id
     WHERE so.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$obs = $stmt->fetch();
if (!$obs) redirect(BASE_URL . '/dashboard/safety.php');

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $new_status = $_POST['status']      ?? $obs['status'];
        $new_notes  = trim($_POST['admin_notes'] ?? '');
        if (!in_array($new_status, ['new', 'reviewed', 'actioned'], true)) {
            $error = 'Invalid status.';
        } else {
            $reviewed_at = $obs['reviewed_at'];
            if (in_array($new_status, ['reviewed', 'actioned'], true) && !$reviewed_at) {
                $reviewed_at = date('Y-m-d H:i:s');
            }
            $upd = $pdo->prepare(
                'UPDATE safety_observations SET status = :status, admin_notes = :notes, reviewed_at = :rev WHERE id = :id'
            );
            $upd->execute([':status' => $new_status, ':notes' => $new_notes ?: null, ':rev' => $reviewed_at, ':id' => $id]);
            $success = 'Observation updated successfully.';
            $stmt->execute([':id' => $id]);
            $obs = $stmt->fetch();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Safety Obs #<?= $id ?> — <?= h($settings['company_name']) ?></title>
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
      <h1 class="topbar-title">Safety Observation #<?= $id ?></h1>
    </div>
    <div class="topbar-right">
      <a href="<?= BASE_URL ?>/dashboard/safety.php" class="btn btn-sm btn-outline">← Back to List</a>
      <div class="topbar-avatar"><?= mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    </div>
  </div>

  <div class="page-content">

    <?php if ($success): ?>
      <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="detail-layout">

      <div class="detail-main">

        <!-- Header badges -->
        <div class="card mb-4">
          <div class="card-header">
            <div class="detail-badges">
              <span class="dept-badge"><?= $obs['dept_icon'] ?? '⚠️' ?> <?= h($obs['dept_name'] ?? 'Safety') ?></span>
              <span class="badge badge-<?= $obs['observation_status'] === 'open' ? 'complaint' : 'compliment' ?>">
                <?= $obs['observation_status'] === 'open' ? '🔴 Open' : '✅ Closed' ?>
              </span>
              <span class="badge badge-<?= h($obs['status']) ?>"><?= ucfirst(h($obs['status'])) ?></span>
            </div>
          </div>
          <div class="card-body">
            <div class="detail-info-grid">
              <div class="detail-info-item">
                <span class="detail-info-label">Task / Activity</span>
                <span class="detail-info-value"><?= h($obs['task_activity']) ?></span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Work Area</span>
                <span class="detail-info-value"><?= h($obs['work_area']) ?></span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Observation Date</span>
                <span class="detail-info-value"><?= h($obs['observation_date']) ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Observation types -->
        <div class="card mb-4">
          <div class="card-header"><h3>Observation Types</h3></div>
          <div class="card-body">
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
              <?php
              $type_map = [
                  'stop_work_authority' => ['🛑', 'Stop Work Authority'],
                  'is_safe'             => ['✅', 'Safe Condition / Act'],
                  'unsafe_act'          => ['⚡', 'Unsafe Act'],
                  'unsafe_condition'    => ['⚠️', 'Unsafe Condition'],
                  'near_miss'           => ['💥', 'Near Miss'],
              ];
              foreach ($type_map as $col => [$icon, $label]):
              ?>
                <span class="badge <?= $obs[$col] ? 'badge-actioned' : '' ?>"
                      style="<?= $obs[$col] ? '' : 'opacity:0.35;' ?>">
                  <?= $icon ?> <?= $label ?>
                </span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Safety observation text -->
        <div class="card mb-4">
          <div class="card-header"><h3>Safety Observation</h3></div>
          <div class="card-body">
            <div class="detail-message"><?= nl2br(h($obs['safety_observation'])) ?></div>
          </div>
        </div>

        <!-- Actions -->
        <?php if ($obs['corrective_action'] || $obs['further_actions']): ?>
        <div class="card mb-4">
          <div class="card-header"><h3>Actions</h3></div>
          <div class="card-body">
            <?php if ($obs['corrective_action']): ?>
            <div class="detail-info-item" style="margin-bottom:12px;">
              <span class="detail-info-label">Immediate Corrective Action</span>
              <span class="detail-info-value"><?= nl2br(h($obs['corrective_action'])) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($obs['further_actions']): ?>
            <div class="detail-info-item">
              <span class="detail-info-label">Further Actions Required</span>
              <span class="detail-info-value"><?= nl2br(h($obs['further_actions'])) ?></span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Observer details -->
        <div class="card">
          <div class="card-header"><h3>Observer Details</h3></div>
          <div class="card-body">
            <div class="detail-info-grid">
              <div class="detail-info-item">
                <span class="detail-info-label">Observer Name</span>
                <span class="detail-info-value"><?= h($obs['observer_name']) ?></span>
              </div>
              <?php if ($obs['observer_company']): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Company</span>
                <span class="detail-info-value"><?= h($obs['observer_company']) ?></span>
              </div>
              <?php endif; ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Submitted</span>
                <span class="detail-info-value"><?= format_date($obs['created_at']) ?></span>
              </div>
              <?php if ($obs['reviewed_at']): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Reviewed</span>
                <span class="detail-info-value"><?= format_date($obs['reviewed_at']) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

      </div><!-- /detail-main -->

      <!-- Sidebar: Admin actions -->
      <div class="detail-sidebar">
        <div class="card">
          <div class="card-header"><h3>Update Status</h3></div>
          <div class="card-body">
            <form method="POST" action="">
              <input type="hidden" name="_token" value="<?= h($csrf) ?>">
              <div class="form-group">
                <label class="form-label">Review Status</label>
                <div class="status-radio-group">
                  <?php foreach (['new', 'reviewed', 'actioned'] as $s): ?>
                    <label class="status-radio-btn status-radio-<?= $s ?>">
                      <input type="radio" name="status" value="<?= $s ?>" <?= $obs['status'] === $s ? 'checked' : '' ?>>
                      <span class="status-radio-label"><?= ucfirst($s) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label" for="admin_notes">Admin Notes</label>
                <textarea name="admin_notes" id="admin_notes" class="form-textarea" rows="5"
                  placeholder="Internal notes..."><?= h($obs['admin_notes'] ?? '') ?></textarea>
              </div>
              <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">Save Changes</button>
            </form>
          </div>
        </div>
        <div class="card mt-4">
          <div class="card-body">
            <p class="text-muted text-sm">
              Observation ID: <strong>#<?= $id ?></strong><br>
              Department: <strong><?= h($obs['dept_name'] ?? 'Safety') ?></strong>
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
