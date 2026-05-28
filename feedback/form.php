<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();

$dept_slug = trim($_GET['dept'] ?? '');
if ($dept_slug === '') {
    redirect(BASE_URL . '/feedback/index.php');
}

// Load department
$stmt = $pdo->prepare('SELECT * FROM departments WHERE slug = :slug AND is_active = 1 LIMIT 1');
$stmt->execute([':slug' => $dept_slug]);
$dept = $stmt->fetch();

if (!$dept) {
    redirect(BASE_URL . '/feedback/index.php');
}

// Preserve QR tracking identifier (set when arriving from a scanned code)
$qr = trim($_GET['qr'] ?? '');

// ── Rig location ─────────────────────────────────────────────────────────────
// The observer must pick their rig location before sharing feedback. If
// locations exist but none was selected, send them to the location layer first.
// (QR codes link straight here, so this is the single enforcement point.)
$active_locations = get_active_locations($pdo);
$location_id      = resolve_location_id($pdo, $_GET['loc'] ?? null);
$location         = null;

if (!empty($active_locations) && $location_id === null) {
    $params = ['dept' => $dept_slug];
    if ($qr !== '') {
        $params['qr'] = $qr;
    }
    redirect(BASE_URL . '/feedback/location.php?' . http_build_query($params));
}

if ($location_id !== null) {
    $loc_stmt = $pdo->prepare('SELECT * FROM rig_locations WHERE id = :id LIMIT 1');
    $loc_stmt->execute([':id' => $location_id]);
    $location = $loc_stmt->fetch() ?: null;
}

$settings = get_site_settings($pdo);
$company  = h($settings['company_name']);
$logoPath = $settings['logo_path'] ? BASE_URL . '/' . ltrim($settings['logo_path'], '/') : null;
$csrf     = csrf_token();

// Determine form type
$form_type = match($dept_slug) {
    'safety'         => 'safety',
    'medical-clinic' => 'medical',
    default          => 'general',
};

// ── Load custom questions for this department ────────────────────────────────
$q_stmt = $pdo->prepare(
    'SELECT * FROM department_questions
     WHERE (department_id = :dept_id OR department_id IS NULL)
       AND is_active = 1
     ORDER BY sort_order ASC, id ASC'
);
$q_stmt->execute([':dept_id' => $dept['id']]);
$custom_questions = $q_stmt->fetchAll();
$has_custom_q     = !empty($custom_questions);

// Step counts per form type (base + optional custom questions step)
$general_steps = $has_custom_q ? 5 : 4;
$safety_steps  = $has_custom_q ? 6 : 5;
$medical_steps = 2; // Trimmed to two tabs: "Visit & Care" + "Overall & You"

// The custom questions step number in each form
$gen_cq_step = 4;                                  // always step 4 in general
$saf_cq_step = 5;                                  // always step 5 in safety

// The final identity/submit step number per form type
$gen_id_step = $has_custom_q ? 5 : 4;
$saf_id_step = $has_custom_q ? 6 : 5;

/**
 * Render a single custom question as form HTML.
 */
function render_custom_question(array $q): string
{
    $qid      = (int)$q['id'];
    $name     = "custom_q[{$qid}]";
    $field_id = "cq_{$qid}";
    $req      = $q['is_required'] ? 'required' : '';
    $req_star = $q['is_required'] ? ' <span style="color:#dc2626">*</span>' : '';
    $options  = ($q['options']) ? (json_decode($q['options'], true) ?? []) : [];

    $html  = '<div class="form-group" data-qid="' . $qid . '">';
    $html .= '<label class="form-label">' . htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') . $req_star . '</label>';

    switch ($q['question_type']) {
        case 'text':
            $html .= '<input type="text" name="' . $name . '" id="' . $field_id . '" class="form-input" ' . $req . '>';
            break;

        case 'textarea':
            $html .= '<textarea name="' . $name . '" id="' . $field_id . '" class="form-textarea" rows="4" ' . $req . '></textarea>';
            break;

        case 'radio':
            $html .= '<div class="cq-options-group">';
            foreach ($options as $opt) {
                $esc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
                $html .= '<label class="cq-option-label"><input type="radio" name="' . $name . '" value="' . $esc . '" ' . $req . '> ' . $esc . '</label>';
            }
            $html .= '</div>';
            break;

        case 'checkbox':
            $html .= '<div class="cq-options-group">';
            foreach ($options as $opt) {
                $esc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
                $html .= '<label class="cq-option-label"><input type="checkbox" name="' . $name . '[]" value="' . $esc . '"> ' . $esc . '</label>';
            }
            $html .= '</div>';
            break;

        case 'select':
            $html .= '<select name="' . $name . '" id="' . $field_id . '" class="form-input" ' . $req . '>';
            $html .= '<option value="">— Select an option —</option>';
            foreach ($options as $opt) {
                $esc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
                $html .= '<option value="' . $esc . '">' . $esc . '</option>';
            }
            $html .= '</select>';
            break;

        case 'rating':
            $html .= '<div class="rating-row cq-rating-row">';
            for ($i = 1; $i <= 5; $i++) {
                $html .= '<label class="rating-btn"><input type="radio" name="' . $name . '" value="' . $i . '" ' . $req . '><span>' . $i . '</span></label>';
            }
            $html .= '<span class="rating-labels"><span>1 = Poor</span><span>5 = Excellent</span></span>';
            $html .= '</div>';
            break;
    }

    $html .= '<div class="field-error" id="' . $field_id . '_error"></div>';
    $html .= '</div>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($dept['name']) ?> Feedback — <?= $company ?></title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/feedback.css">
<style>
.cq-options-group{display:flex;flex-direction:column;gap:8px;margin-top:4px}
.cq-option-label{display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;transition:border-color .15s,background .15s}
.cq-option-label:hover{border-color:#44B944;background:#f0faf0}
.cq-option-label input{accent-color:#44B944}
.cq-rating-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
</style>
</head>
<body class="feedback-body">

<!-- Header -->
<header class="feedback-header">
  <div class="feedback-header-inner">
    <div class="feedback-logo">
      <?php if ($logoPath): ?>
        <img src="<?= h($logoPath) ?>" alt="<?= $company ?>" class="logo-img">
      <?php else: ?>
        <div class="logo-icon-fallback">⚡</div>
      <?php endif; ?>
      <div class="logo-text">
        <span class="logo-company"><?= $company ?></span>
        <span class="logo-tagline">
          <?= h($dept['icon']) ?> <?= h($dept['name']) ?>
          <?php if ($location): ?>
            &nbsp;·&nbsp; 📍 <?= h($location['name']) ?>
          <?php endif; ?>
        </span>
      </div>
    </div>
    <?php
      // "Back" returns to the location step (so the observer can change location)
      $back_params = ['dept' => $dept_slug];
      if ($qr !== '') { $back_params['qr'] = $qr; }
      $back_url = !empty($active_locations)
          ? BASE_URL . '/feedback/location.php?' . http_build_query($back_params)
          : BASE_URL . '/feedback/index.php';
    ?>
    <a href="<?= h($back_url) ?>" class="header-admin-link">← Back</a>
  </div>
</header>

<main class="feedback-main">
  <div class="container">
    <div class="form-wrapper">

<?php if ($form_type === 'general'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     GENERAL FEEDBACK FORM
══════════════════════════════════════════════════════════════════════════ -->
<div class="form-card" id="generalForm">
  <div class="progress-bar-wrap">
    <div class="progress-bar-fill" id="progressFill" style="width:<?= round(100/$general_steps) ?>%"></div>
  </div>
  <div class="form-card-body">

    <div class="step-indicator" id="stepIndicator">
      <?php for ($i = 1; $i <= $general_steps; $i++): ?>
        <span class="step-dot <?= $i === 1 ? 'active' : '' ?>" data-step="<?= $i ?>"><?= $i ?></span>
        <?php if ($i < $general_steps): ?><span class="step-line"></span><?php endif; ?>
      <?php endfor; ?>
    </div>

    <form id="feedbackForm" novalidate>
      <input type="hidden" name="_token"    value="<?= h($csrf) ?>">
      <input type="hidden" name="dept_slug" value="<?= h($dept_slug) ?>">
      <input type="hidden" name="location_id" value="<?= $location_id ?? '' ?>">

      <!-- Step 1: Rating -->
      <div class="form-step active" id="step1">
        <h2 class="step-title">How would you rate your experience?</h2>
        <p class="step-desc">Tap a star to rate — 1 is poor, 5 is excellent</p>
        <div class="star-rating-group" id="starGroup">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <button type="button" class="star-btn" data-value="<?= $i ?>" aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>">★</button>
          <?php endfor; ?>
        </div>
        <input type="hidden" name="rating" id="ratingInput" value="">
        <div class="field-error" id="ratingError"></div>
        <div class="step-actions">
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="2">Next →</button>
        </div>
      </div>

      <!-- Step 2: Category -->
      <div class="form-step" id="step2">
        <h2 class="step-title">What type of feedback is this?</h2>
        <p class="step-desc">Choose the category that best describes your feedback</p>
        <div class="category-chips">
          <button type="button" class="cat-chip" data-value="compliment">
            <span class="cat-icon">👍</span>
            <span class="cat-label">Compliment</span>
            <span class="cat-desc">Share positive feedback</span>
          </button>
          <button type="button" class="cat-chip" data-value="suggestion">
            <span class="cat-icon">💡</span>
            <span class="cat-label">Suggestion</span>
            <span class="cat-desc">Propose an improvement</span>
          </button>
          <button type="button" class="cat-chip" data-value="complaint">
            <span class="cat-icon">⚠️</span>
            <span class="cat-label">Complaint</span>
            <span class="cat-desc">Report an issue</span>
          </button>
        </div>
        <input type="hidden" name="category" id="categoryInput" value="">
        <div class="field-error" id="categoryError"></div>
        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="1">← Back</button>
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="3">Next →</button>
        </div>
      </div>

      <!-- Step 3: Message -->
      <div class="form-step" id="step3">
        <h2 class="step-title">Tell us more</h2>
        <p class="step-desc">Please provide details about your experience (minimum 10 characters)</p>
        <div class="textarea-wrap">
          <textarea name="message" id="messageInput" class="form-textarea" rows="5"
            placeholder="Write your feedback here..." maxlength="2000"></textarea>
          <span class="char-counter" id="charCounter">0 / 2000</span>
        </div>
        <div class="field-error" id="messageError"></div>
        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="2">← Back</button>
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="4">Next →</button>
        </div>
      </div>

      <?php if ($has_custom_q): ?>
      <!-- Step 4: Custom Questions -->
      <div class="form-step" id="step4">
        <h2 class="step-title">A few more questions</h2>
        <p class="step-desc">Please answer the following questions about your experience</p>
        <?php foreach ($custom_questions as $q): echo render_custom_question($q); endforeach; ?>
        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="3">← Back</button>
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="5">Next →</button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Step <?= $gen_id_step ?>: Identity -->
      <div class="form-step" id="step<?= $gen_id_step ?>">
        <h2 class="step-title">Your identity</h2>
        <p class="step-desc">Submit anonymously or share your contact details for a follow-up</p>

        <div class="anon-toggle-row">
          <div class="anon-toggle-label">
            <span class="anon-icon">🔒</span>
            <div>
              <strong>Submit anonymously</strong>
              <small>Your identity will not be recorded</small>
            </div>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="is_anonymous" id="anonToggle" value="1" checked>
            <span class="toggle-slider"></span>
          </label>
        </div>

        <div class="contact-fields" id="contactFields" style="display:none;">
          <div class="form-group">
            <label class="form-label" for="submitter_name">Full Name</label>
            <input type="text" name="submitter_name" id="submitter_name" class="form-input" placeholder="Your full name">
          </div>
          <div class="form-group">
            <label class="form-label" for="email">Email Address</label>
            <input type="email" name="email" id="email" class="form-input" placeholder="your@email.com">
          </div>
          <div class="form-group">
            <label class="form-label" for="phone">Phone Number (optional)</label>
            <input type="tel" name="phone" id="phone" class="form-input" placeholder="+234 xxx xxx xxxx">
          </div>
        </div>

        <div class="alert-box" id="formAlert" style="display:none;"></div>

        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="<?= $gen_id_step - 1 ?>">← Back</button>
          <button type="submit" class="btn btn-accent btn-lg" id="submitBtn">
            <span class="btn-text">Submit Feedback</span>
            <span class="btn-spinner" style="display:none;">⏳</span>
          </button>
        </div>
      </div>

    </form>
  </div><!-- /form-card-body -->
</div><!-- /form-card -->

<?php elseif ($form_type === 'safety'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     SAFETY OBSERVATION FORM
══════════════════════════════════════════════════════════════════════════ -->
<div class="form-card form-card--safety" id="safetyFormCard">
  <div class="progress-bar-wrap">
    <div class="progress-bar-fill progress-bar-fill--safety" id="progressFill" style="width:<?= round(100/$safety_steps) ?>%"></div>
  </div>
  <div class="form-card-header">
    <div class="form-card-icon">⚠️</div>
    <div>
      <h1 class="form-card-title">Safety Observation</h1>
      <p class="form-card-subtitle">Report a safety observation on the rig</p>
    </div>
  </div>
  <div class="form-card-body">
    <div class="step-indicator" id="stepIndicator">
      <?php for ($i = 1; $i <= $safety_steps; $i++): ?>
        <span class="step-dot <?= $i === 1 ? 'active' : '' ?>" data-step="<?= $i ?>"><?= $i ?></span>
        <?php if ($i < $safety_steps): ?><span class="step-line"></span><?php endif; ?>
      <?php endfor; ?>
    </div>

    <div class="swa-warning" id="swaWarning" style="display:none;">
      <div class="swa-warning-icon">🛑</div>
      <div>
        <strong>STOP WORK AUTHORITY INVOKED</strong>
        <p>Work must stop immediately until the hazard is addressed. Notify your supervisor.</p>
      </div>
    </div>

    <form id="safetyForm" novalidate>
      <input type="hidden" name="_token"    value="<?= h($csrf) ?>">
      <input type="hidden" name="dept_slug" value="<?= h($dept_slug) ?>">
      <input type="hidden" name="location_id" value="<?= $location_id ?? '' ?>">

      <!-- Step 1: Task & Work Area -->
      <div class="form-step active" id="step1">
        <h2 class="step-title">Task & Work Area</h2>
        <div class="form-group">
          <label class="form-label required">Task / Activity Being Performed</label>
          <input type="text" name="task_activity" class="form-input" placeholder="e.g., Drilling operations, pipe handling..." required>
          <div class="field-error" id="taskError"></div>
        </div>
        <div class="form-group">
          <label class="form-label required">Work Area / Location</label>
          <input type="text" name="work_area" class="form-input" placeholder="e.g., Drill floor, Engine room, Deck..." required>
          <div class="field-error" id="areaError"></div>
        </div>
        <div class="step-actions">
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="2">Next →</button>
        </div>
      </div>

      <!-- Step 2: Observation Status -->
      <div class="form-step" id="step2">
        <h2 class="step-title">Observation Status</h2>
        <p class="step-desc">Is this observation currently open or resolved?</p>
        <div class="status-chips">
          <button type="button" class="status-chip status-chip--open active" data-value="open">
            <span class="status-icon">🔴</span>
            <span class="status-label">Open</span>
            <span class="status-desc">Issue not yet resolved</span>
          </button>
          <button type="button" class="status-chip status-chip--close" data-value="close">
            <span class="status-icon">✅</span>
            <span class="status-label">Closed</span>
            <span class="status-desc">Issue has been resolved</span>
          </button>
        </div>
        <input type="hidden" name="observation_status" id="obsStatusInput" value="open">
        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="1">← Back</button>
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="3">Next →</button>
        </div>
      </div>

      <!-- Step 3: Observation Types -->
      <div class="form-step" id="step3">
        <h2 class="step-title">Observation Types</h2>
        <p class="step-desc">Select all that apply</p>
        <div class="obs-type-grid">
          <label class="obs-type-card obs-type-card--swa">
            <input type="checkbox" name="stop_work_authority" id="swaCheck" value="1">
            <div class="obs-type-content"><span class="obs-type-icon">🛑</span><span class="obs-type-label">Stop Work Authority</span></div>
            <div class="obs-type-check">✓</div>
          </label>
          <label class="obs-type-card obs-type-card--safe">
            <input type="checkbox" name="is_safe" id="isSafeCheck" value="1">
            <div class="obs-type-content"><span class="obs-type-icon">✅</span><span class="obs-type-label">Safe Condition / Act</span></div>
            <div class="obs-type-check">✓</div>
          </label>
          <label class="obs-type-card">
            <input type="checkbox" name="unsafe_act" value="1">
            <div class="obs-type-content"><span class="obs-type-icon">⚡</span><span class="obs-type-label">Unsafe Act</span></div>
            <div class="obs-type-check">✓</div>
          </label>
          <label class="obs-type-card">
            <input type="checkbox" name="unsafe_condition" value="1">
            <div class="obs-type-content"><span class="obs-type-icon">⚠️</span><span class="obs-type-label">Unsafe Condition</span></div>
            <div class="obs-type-check">✓</div>
          </label>
          <label class="obs-type-card">
            <input type="checkbox" name="near_miss" value="1">
            <div class="obs-type-content"><span class="obs-type-icon">💥</span><span class="obs-type-label">Near Miss</span></div>
            <div class="obs-type-check">✓</div>
          </label>
        </div>
        <div class="field-error" id="obsTypeError"></div>
        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="2">← Back</button>
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="4">Next →</button>
        </div>
      </div>

      <!-- Step 4: Observation Details -->
      <div class="form-step" id="step4">
        <h2 class="step-title">Observation Details & Actions</h2>
        <div class="form-group">
          <label class="form-label required">Safety Observation</label>
          <textarea name="safety_observation" class="form-textarea" rows="4"
            placeholder="Describe what you observed in detail..." maxlength="2000" required></textarea>
          <div class="field-error" id="safetyObsError"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Immediate Corrective Action Taken</label>
          <textarea name="corrective_action" class="form-textarea" rows="3"
            placeholder="What immediate action was taken?..." maxlength="1000"></textarea>
        </div>
        <div class="form-group">
          <label class="form-label">Further Actions Required</label>
          <textarea name="further_actions" class="form-textarea" rows="3"
            placeholder="What further actions are needed?..." maxlength="1000"></textarea>
        </div>
        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="3">← Back</button>
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="5">Next →</button>
        </div>
      </div>

      <?php if ($has_custom_q): ?>
      <!-- Step 5: Custom Questions -->
      <div class="form-step" id="step5">
        <h2 class="step-title">A few more questions</h2>
        <p class="step-desc">Please answer the following questions</p>
        <?php foreach ($custom_questions as $q): echo render_custom_question($q); endforeach; ?>
        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="4">← Back</button>
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="6">Next →</button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Step <?= $saf_id_step ?>: Observer Details -->
      <div class="form-step" id="step<?= $saf_id_step ?>">
        <h2 class="step-title">Observer Details</h2>
        <div class="form-group">
          <label class="form-label required">Observer Name</label>
          <input type="text" name="observer_name" class="form-input" placeholder="Your full name" required>
          <div class="field-error" id="observerNameError"></div>
        </div>
        <div class="form-group">
          <label class="form-label">Company / Contractor</label>
          <input type="text" name="observer_company" class="form-input" placeholder="Your company name">
        </div>
        <div class="form-group">
          <label class="form-label required">Observation Date</label>
          <input type="date" name="observation_date" class="form-input" value="<?= date('Y-m-d') ?>" required>
          <div class="field-error" id="obsDateError"></div>
        </div>

        <div class="alert-box" id="formAlert" style="display:none;"></div>

        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="<?= $saf_id_step - 1 ?>">← Back</button>
          <button type="submit" class="btn btn-accent btn-lg" id="submitBtn">
            <span class="btn-text">Submit Observation</span>
            <span class="btn-spinner" style="display:none;">⏳</span>
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php elseif ($form_type === 'medical'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     MEDICAL FEEDBACK FORM
══════════════════════════════════════════════════════════════════════════ -->
<div class="form-card form-card--medical" id="medicalFormCard">
  <div class="progress-bar-wrap">
    <div class="progress-bar-fill progress-bar-fill--medical" id="progressFill" style="width:<?= round(100/$medical_steps) ?>%"></div>
  </div>
  <div class="form-card-header">
    <div class="form-card-icon">🏥</div>
    <div>
      <h1 class="form-card-title">Medical Clinic Feedback</h1>
      <p class="form-card-subtitle">Help us improve our medical services</p>
    </div>
  </div>
  <div class="form-card-body">
    <div class="step-indicator" id="stepIndicator">
      <?php for ($i = 1; $i <= $medical_steps; $i++): ?>
        <span class="step-dot <?= $i === 1 ? 'active' : '' ?>" data-step="<?= $i ?>"><?= $i ?></span>
        <?php if ($i < $medical_steps): ?><span class="step-line"></span><?php endif; ?>
      <?php endfor; ?>
    </div>

    <form id="medicalForm" novalidate>
      <input type="hidden" name="_token"    value="<?= h($csrf) ?>">
      <input type="hidden" name="dept_slug" value="<?= h($dept_slug) ?>">
      <input type="hidden" name="location_id" value="<?= $location_id ?? '' ?>">

      <!-- Step 1: Visit & Care -->
      <div class="form-step active" id="step1">
        <h2 class="step-title medical-title">Visit & Care</h2>
        <div class="form-group">
          <label class="form-label required">Date of Visit</label>
          <input type="date" name="visit_date" class="form-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
          <div class="field-error" id="visitDateError"></div>
        </div>
        <div class="form-group">
          <label class="form-label required">Reason for Visit</label>
          <div class="reason-grid">
            <?php
            $reasons = ['injury'=>'🤕 Injury','illness'=>'🤒 Illness','routine'=>'🩺 Routine Check','medication'=>'💊 Medication','emergency'=>'🚨 Emergency','mental_health'=>'🧠 Mental Health','other'=>'💬 Other'];
            foreach ($reasons as $val => $label):
            ?>
              <label class="reason-chip">
                <input type="radio" name="visit_reason" value="<?= $val ?>" <?= $val === 'routine' ? 'checked' : '' ?>>
                <span><?= $label ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div id="visitReasonOtherWrap" class="mt-2" style="display:none;">
            <input type="text" name="visit_reason_other" class="form-input" placeholder="Please specify...">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">How quickly were you seen?</label>
          <div class="likert-row">
            <?php foreach (['immediate'=>'Immediate','quick'=>'Quick','acceptable'=>'Acceptable','slow'=>'Slow','very_slow'=>'Very Slow'] as $v => $l): ?>
              <label class="likert-btn"><input type="radio" name="response_time" value="<?= $v ?>"><span><?= $l ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Staff Professionalism</label>
          <div class="rating-row" data-field="staff_professionalism">
            <?php for ($i=1;$i<=5;$i++): ?>
              <label class="rating-btn"><input type="radio" name="staff_professionalism" value="<?= $i ?>"><span><?= $i ?></span></label>
            <?php endfor; ?>
            <span class="rating-labels"><span>Poor</span><span>Excellent</span></span>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Was the treatment appropriate?</label>
          <div class="yesno-row yesno-row--3">
            <label class="yesno-btn"><input type="radio" name="treatment_appropriate" value="yes"> Yes</label>
            <label class="yesno-btn"><input type="radio" name="treatment_appropriate" value="unsure"> Unsure</label>
            <label class="yesno-btn"><input type="radio" name="treatment_appropriate" value="no"> No</label>
          </div>
        </div>
        <div class="step-actions">
          <button type="button" class="btn btn-primary btn-lg btn-next" data-next="2">Next →</button>
        </div>
      </div>

      <!-- Step 2: Overall & You -->
      <div class="form-step" id="step2">
        <h2 class="step-title medical-title">Overall & You</h2>
        <div class="form-group">
          <label class="form-label">Overall Experience Rating</label>
          <div class="rating-row">
            <?php for ($i=1;$i<=5;$i++): ?>
              <label class="rating-btn"><input type="radio" name="overall_rating" value="<?= $i ?>"><span><?= $i ?></span></label>
            <?php endfor; ?>
            <span class="rating-labels"><span>Very Poor</span><span>Excellent</span></span>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Would you confidently use this clinic again?</label>
          <div class="yesno-row yesno-row--3">
            <label class="yesno-btn"><input type="radio" name="confident_future_use" value="yes"> Yes</label>
            <label class="yesno-btn"><input type="radio" name="confident_future_use" value="maybe"> Maybe</label>
            <label class="yesno-btn"><input type="radio" name="confident_future_use" value="no"> No</label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Should this require urgent management review?</label>
          <div class="yesno-row">
            <label class="yesno-btn"><input type="radio" name="urgent_review" value="1"> Yes — Urgent</label>
            <label class="yesno-btn active"><input type="radio" name="urgent_review" value="0" checked> No</label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Additional Comments (optional)</label>
          <textarea name="comments" class="form-textarea" rows="4" placeholder="Any other feedback..." maxlength="2000"></textarea>
        </div>

        <?php if ($has_custom_q): ?>
        <?php foreach ($custom_questions as $q): echo render_custom_question($q); endforeach; ?>
        <?php endif; ?>

        <div class="anon-toggle-row">
          <div class="anon-toggle-label">
            <span class="anon-icon">🔒</span>
            <div>
              <strong>Submit anonymously</strong>
              <small>Your identity will not be recorded</small>
            </div>
          </div>
          <label class="toggle-switch">
            <input type="checkbox" name="is_anonymous" id="anonToggle" value="1" checked>
            <span class="toggle-slider"></span>
          </label>
        </div>
        <div class="contact-fields" id="contactFields" style="display:none;">
          <div class="form-group">
            <label class="form-label">Full Name</label>
            <input type="text" name="observer_name" class="form-input" placeholder="Your full name">
          </div>
          <div class="form-group">
            <label class="form-label">Company</label>
            <input type="text" name="observer_company" class="form-input" placeholder="Your company">
          </div>
          <div class="form-group">
            <label class="form-label">Employee ID (optional)</label>
            <input type="text" name="employee_id" class="form-input" placeholder="Your employee ID">
          </div>
        </div>

        <div class="alert-box" id="formAlert" style="display:none;"></div>

        <div class="step-actions two-col">
          <button type="button" class="btn btn-outline btn-lg btn-prev" data-prev="1">← Back</button>
          <button type="submit" class="btn btn-accent btn-lg" id="submitBtn">
            <span class="btn-text">Submit Feedback</span>
            <span class="btn-spinner" style="display:none;">⏳</span>
          </button>
        </div>
      </div>

    </form>
  </div>
</div>

<?php endif; ?>

    </div><!-- /form-wrapper -->
  </div><!-- /container -->
</main>

<footer class="feedback-footer">
  <p>&copy; <?= date('Y') ?> <?= $company ?>. All submissions are confidential.</p>
</footer>

<script>
  window.FORM_TYPE    = <?= json_encode($form_type) ?>;
  window.BASE_URL     = <?= json_encode(BASE_URL) ?>;
  window.DEPT_SLUG    = <?= json_encode($dept_slug) ?>;
  window.CSRF_TOKEN   = <?= json_encode($csrf) ?>;
  window.TOTAL_STEPS  = <?= json_encode(
      $form_type === 'general' ? $general_steps :
      ($form_type === 'safety' ? $safety_steps : $medical_steps)
  ) ?>;
</script>
<script src="<?= ASSET_URL ?>/js/feedback.js"></script>
</body>
</html>
