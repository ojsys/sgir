<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

$settings     = get_site_settings($pdo);
$current_page = 'medical';
$csrf         = csrf_token();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) redirect(BASE_URL . '/dashboard/medical.php');

$stmt = $pdo->prepare(
    'SELECT mf.*, d.name AS dept_name, d.icon AS dept_icon,
            rl.name AS loc_name, rl.code AS loc_code
     FROM medical_feedback mf
     LEFT JOIN departments d ON d.id = mf.department_id
     LEFT JOIN rig_locations rl ON rl.id = mf.location_id
     WHERE mf.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$mf = $stmt->fetch();
if (!$mf) redirect(BASE_URL . '/dashboard/medical.php');

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $new_status = $_POST['status']          ?? $mf['status'];
        $new_notes  = trim($_POST['admin_notes'] ?? '');
        if (!in_array($new_status, ['new', 'reviewed', 'actioned'], true)) {
            $error = 'Invalid status.';
        } else {
            $reviewed_at = $mf['reviewed_at'];
            if (in_array($new_status, ['reviewed', 'actioned'], true) && !$reviewed_at) {
                $reviewed_at = date('Y-m-d H:i:s');
            }
            $upd = $pdo->prepare(
                'UPDATE medical_feedback SET status = :status, admin_notes = :notes, reviewed_at = :rev WHERE id = :id'
            );
            $upd->execute([':status' => $new_status, ':notes' => $new_notes ?: null, ':rev' => $reviewed_at, ':id' => $id]);
            $success = 'Medical feedback updated successfully.';
            $stmt->execute([':id' => $id]);
            $mf = $stmt->fetch();
        }
    }
}

$reason_labels = [
    'injury' => '🤕 Injury', 'illness' => '🤒 Illness', 'routine' => '🩺 Routine Check',
    'medication' => '💊 Medication', 'emergency' => '🚨 Emergency',
    'mental_health' => '🧠 Mental Health', 'other' => '💬 Other',
];

function yn(?string $v, array $map = ['yes' => 'Yes', 'no' => 'No']): string
{
    return $v !== null ? (h($map[$v] ?? ucfirst(str_replace('_', ' ', $v)))) : '—';
}

// Fields the trimmed 2-step form no longer collects. Render them only when an
// older record still carries a value, so historical data stays visible.
$legacy_facilities = array_filter(
    [
        $mf['cleanliness_rating'], $mf['medications_available'], $mf['facility_adequacy'],
        $mf['followup_instructions'], $mf['referred_for_further_care'], $mf['fit_to_return'],
    ],
    fn($v) => $v !== null && $v !== ''
);
$has_legacy_facilities = !empty($legacy_facilities);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Medical Feedback #<?= $id ?> — <?= h($settings['company_name']) ?></title>
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
      <h1 class="topbar-title">Medical Feedback #<?= $id ?></h1>
    </div>
    <div class="topbar-right">
      <a href="<?= BASE_URL ?>/dashboard/medical.php" class="btn btn-sm btn-outline">← Back to List</a>
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

    <?php if ($mf['urgent_review']): ?>
      <div class="alert alert-error" style="margin-bottom:16px;">
        🚨 <strong>Urgent Review Requested</strong> — This feedback requires immediate management attention.
      </div>
    <?php endif; ?>

    <div class="detail-layout">

      <div class="detail-main">

        <!-- Header -->
        <div class="card mb-4">
          <div class="card-header">
            <div class="detail-badges">
              <span class="dept-badge"><?= $mf['dept_icon'] ?? '🏥' ?> <?= h($mf['dept_name'] ?? 'Medical Clinic') ?></span>
              <span class="badge badge-<?= h($mf['status']) ?>"><?= ucfirst(h($mf['status'])) ?></span>
              <?php if ($mf['urgent_review']): ?><span class="badge badge-complaint">Urgent</span><?php endif; ?>
            </div>
            <?php if ($mf['overall_rating']): ?>
            <div class="detail-rating">
              <span class="star-display"><?= star_rating((int)$mf['overall_rating']) ?></span>
              <span class="rating-num"><?= $mf['overall_rating'] ?>/5</span>
            </div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <div class="detail-info-grid">
              <div class="detail-info-item">
                <span class="detail-info-label">Rig Location</span>
                <span class="detail-info-value">
                  <?= !empty($mf['loc_name']) ? '📍 ' . h($mf['loc_name']) : '—' ?>
                </span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Visit Date</span>
                <span class="detail-info-value"><?= h($mf['visit_date']) ?></span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Reason for Visit</span>
                <span class="detail-info-value">
                  <?= h($reason_labels[$mf['visit_reason']] ?? $mf['visit_reason']) ?>
                  <?php if ($mf['visit_reason'] === 'other' && $mf['visit_reason_other']): ?>
                    — <?= h($mf['visit_reason_other']) ?>
                  <?php endif; ?>
                </span>
              </div>
              <?php if ($mf['work_area']): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Work Area</span>
                <span class="detail-info-value"><?= h($mf['work_area']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['is_work_related']): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Work-Related</span>
                <span class="detail-info-value">Yes</span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Access & Timeliness -->
        <div class="card mb-4">
          <div class="card-header"><h3>Access &amp; Timeliness</h3></div>
          <div class="card-body">
            <div class="detail-info-grid">
              <div class="detail-info-item">
                <span class="detail-info-label">Response Time</span>
                <span class="detail-info-value"><?= yn($mf['response_time'], ['immediate'=>'Immediate','quick'=>'Quick','acceptable'=>'Acceptable','slow'=>'Slow','very_slow'=>'Very Slow']) ?></span>
              </div>
              <?php if ($mf['clinic_accessible'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Clinic Accessible</span>
                <span class="detail-info-value"><?= yn($mf['clinic_accessible']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['seen_at_reasonable_time'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Seen at Reasonable Time</span>
                <span class="detail-info-value"><?= yn($mf['seen_at_reasonable_time']) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Quality of Care -->
        <div class="card mb-4">
          <div class="card-header"><h3>Quality of Care</h3></div>
          <div class="card-body">
            <div class="detail-info-grid">
              <div class="detail-info-item">
                <span class="detail-info-label">Staff Professionalism</span>
                <span class="detail-info-value"><?= $mf['staff_professionalism'] ? star_rating((int)$mf['staff_professionalism']) : '—' ?></span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Treatment Appropriate</span>
                <span class="detail-info-value"><?= yn($mf['treatment_appropriate'], ['yes'=>'Yes','unsure'=>'Unsure','no'=>'No']) ?></span>
              </div>
              <?php if ($mf['treatment_explained'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Treatment Explained</span>
                <span class="detail-info-value"><?= yn($mf['treatment_explained'], ['yes'=>'Yes','partially'=>'Partially','no'=>'No']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['felt_listened_to'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Felt Listened To</span>
                <span class="detail-info-value"><?= yn($mf['felt_listened_to'], ['yes'=>'Yes','partially'=>'Partially','no'=>'No']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['privacy_maintained'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Privacy Maintained</span>
                <span class="detail-info-value"><?= yn($mf['privacy_maintained']) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Overall Assessment -->
        <div class="card mb-4">
          <div class="card-header"><h3>Overall Assessment</h3></div>
          <div class="card-body">
            <div class="detail-info-grid">
              <div class="detail-info-item">
                <span class="detail-info-label">Overall Rating</span>
                <span class="detail-info-value"><?= $mf['overall_rating'] ? star_rating((int)$mf['overall_rating']) : '—' ?></span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Would Use Clinic Again</span>
                <span class="detail-info-value"><?= yn($mf['confident_future_use'], ['yes'=>'Yes','maybe'=>'Maybe','no'=>'No']) ?></span>
              </div>
              <div class="detail-info-item">
                <span class="detail-info-label">Urgent Review</span>
                <span class="detail-info-value"><?= $mf['urgent_review'] ? 'Yes' : 'No' ?></span>
              </div>
            </div>
          </div>
        </div>

        <?php if ($has_legacy_facilities): ?>
        <!-- Facilities & Outcomes (older records only) -->
        <div class="card mb-4">
          <div class="card-header"><h3>Facilities &amp; Outcomes</h3></div>
          <div class="card-body">
            <div class="detail-info-grid">
              <?php if ($mf['cleanliness_rating']): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Cleanliness</span>
                <span class="detail-info-value"><?= star_rating((int)$mf['cleanliness_rating']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['medications_available'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Medications Available</span>
                <span class="detail-info-value"><?= yn($mf['medications_available'], ['yes'=>'Yes','partially'=>'Partially','no'=>'No']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['facility_adequacy']): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Facility Adequacy</span>
                <span class="detail-info-value"><?= star_rating((int)$mf['facility_adequacy']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['followup_instructions'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Follow-up Instructions</span>
                <span class="detail-info-value"><?= yn($mf['followup_instructions'], ['yes'=>'Yes','no'=>'No','na'=>'N/A']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['referred_for_further_care'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Referred for Further Care</span>
                <span class="detail-info-value"><?= yn($mf['referred_for_further_care'], ['yes'=>'Yes','no'=>'No','not_needed'=>'Not Needed']) ?></span>
              </div>
              <?php endif; ?>
              <?php if ($mf['fit_to_return'] !== null): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Fit to Return to Work</span>
                <span class="detail-info-value"><?= yn($mf['fit_to_return'], ['yes'=>'Yes','no'=>'No','still_on_sick_bay'=>'Still on Sick Bay','na'=>'N/A']) ?></span>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($mf['comments']): ?>
        <div class="card mb-4">
          <div class="card-header"><h3>Additional Comments</h3></div>
          <div class="card-body">
            <div class="detail-message"><?= nl2br(h($mf['comments'])) ?></div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Submitter -->
        <div class="card">
          <div class="card-header"><h3>Submitter Information</h3></div>
          <div class="card-body">
            <?php if ($mf['is_anonymous']): ?>
              <div class="anon-notice">
                <span class="anon-notice-icon">🔒</span>
                <span>Submitted anonymously.</span>
              </div>
            <?php else: ?>
              <div class="detail-info-grid">
                <div class="detail-info-item">
                  <span class="detail-info-label">Name</span>
                  <span class="detail-info-value"><?= h($mf['observer_name'] ?: '—') ?></span>
                </div>
                <?php if ($mf['observer_company']): ?>
                <div class="detail-info-item">
                  <span class="detail-info-label">Company</span>
                  <span class="detail-info-value"><?= h($mf['observer_company']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($mf['employee_id']): ?>
                <div class="detail-info-item">
                  <span class="detail-info-label">Employee ID</span>
                  <span class="detail-info-value"><?= h($mf['employee_id']) ?></span>
                </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <div class="detail-info-grid" style="margin-top:12px;">
              <div class="detail-info-item">
                <span class="detail-info-label">Submitted</span>
                <span class="detail-info-value"><?= format_date($mf['created_at']) ?></span>
              </div>
              <?php if ($mf['reviewed_at']): ?>
              <div class="detail-info-item">
                <span class="detail-info-label">Reviewed</span>
                <span class="detail-info-value"><?= format_date($mf['reviewed_at']) ?></span>
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
                      <input type="radio" name="status" value="<?= $s ?>" <?= $mf['status'] === $s ? 'checked' : '' ?>>
                      <span class="status-radio-label"><?= ucfirst($s) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label" for="admin_notes">Admin Notes</label>
                <textarea name="admin_notes" id="admin_notes" class="form-textarea" rows="5"
                  placeholder="Internal notes..."><?= h($mf['admin_notes'] ?? '') ?></textarea>
              </div>
              <button type="submit" class="btn btn-primary btn-lg" style="width:100%;">Save Changes</button>
            </form>
          </div>
        </div>
        <div class="card mt-4">
          <div class="card-body">
            <p class="text-muted text-sm">
              Medical Feedback ID: <strong>#<?= $id ?></strong><br>
              Department: <strong><?= h($mf['dept_name'] ?? 'Medical Clinic') ?></strong>
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
