<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_admin();

$settings     = get_site_settings($pdo);
$current_page = 'questions';
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
            $dept_id   = $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
            $q_text    = trim($_POST['question_text'] ?? '');
            $q_type    = $_POST['question_type'] ?? 'text';
            $q_req     = isset($_POST['is_required']) ? 1 : 0;
            $q_sort    = (int)($_POST['sort_order'] ?? 0);
            $valid_types = ['text','textarea','radio','checkbox','select','rating'];

            if ($q_text === '') {
                $error = 'Question text is required.';
            } elseif (!in_array($q_type, $valid_types, true)) {
                $error = 'Invalid question type.';
            } else {
                // Build options JSON for types that need it
                $opts_raw = trim($_POST['options_text'] ?? '');
                $options  = null;
                if (in_array($q_type, ['radio','checkbox','select'], true) && $opts_raw !== '') {
                    $opts_arr = array_values(array_filter(array_map('trim', explode("\n", $opts_raw))));
                    $options  = $opts_arr ? json_encode($opts_arr, JSON_UNESCAPED_UNICODE) : null;
                }

                if ($action === 'add') {
                    $stmt = $pdo->prepare(
                        'INSERT INTO department_questions
                            (department_id, question_text, question_type, options, is_required, sort_order, created_at)
                         VALUES (:dept_id, :text, :type, :opts, :req, :sort, :created_at)'
                    );
                    $stmt->execute([
                        ':dept_id'    => $dept_id,
                        ':text'       => $q_text,
                        ':type'       => $q_type,
                        ':opts'       => $options,
                        ':req'        => $q_req,
                        ':sort'       => $q_sort,
                        ':created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $success = 'Question added successfully.';
                } else {
                    $edit_id = (int)($_POST['edit_id'] ?? 0);
                    $stmt = $pdo->prepare(
                        'UPDATE department_questions
                         SET department_id=:dept_id, question_text=:text, question_type=:type,
                             options=:opts, is_required=:req, sort_order=:sort
                         WHERE id=:id'
                    );
                    $stmt->execute([
                        ':dept_id' => $dept_id,
                        ':text'    => $q_text,
                        ':type'    => $q_type,
                        ':opts'    => $options,
                        ':req'     => $q_req,
                        ':sort'    => $q_sort,
                        ':id'      => $edit_id,
                    ]);
                    $success = 'Question updated successfully.';
                    // Clear edit mode after save
                    header('Location: ' . BASE_URL . '/dashboard/questions.php?saved=1');
                    exit;
                }
            }

        } elseif ($action === 'toggle') {
            $qid = (int)($_POST['question_id'] ?? 0);
            $pdo->prepare('UPDATE department_questions SET is_active = 1 - is_active WHERE id = :id')
                ->execute([':id' => $qid]);
            $success = 'Question status updated.';

        } elseif ($action === 'delete') {
            $qid = (int)($_POST['question_id'] ?? 0);
            $pdo->prepare('DELETE FROM department_questions WHERE id = :id')
                ->execute([':id' => $qid]);
            $success = 'Question deleted.';
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'Question updated successfully.';
}

// ── Load departments ────────────────────────────────────────────────────────
$departments = $pdo->query(
    'SELECT * FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
)->fetchAll();

// ── Load all questions keyed by department_id (null → "All Departments") ───
$all_q = $pdo->query(
    'SELECT dq.*, d.name AS dept_name
     FROM department_questions dq
     LEFT JOIN departments d ON d.id = dq.department_id
     ORDER BY dq.department_id ASC, dq.sort_order ASC, dq.id ASC'
)->fetchAll();

$questions_by_dept = []; // key: dept_id or 'all'
foreach ($all_q as $q) {
    $key = $q['department_id'] ?? 'all';
    $questions_by_dept[$key][] = $q;
}

// ── Edit mode: load question to edit ───────────────────────────────────────
$edit_q = null;
$edit_id = (int)($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $e = $pdo->prepare('SELECT * FROM department_questions WHERE id = :id LIMIT 1');
    $e->execute([':id' => $edit_id]);
    $edit_q = $e->fetch() ?: null;
}

$type_labels = [
    'text'     => 'Short Text',
    'textarea' => 'Long Text',
    'radio'    => 'Single Choice',
    'checkbox' => 'Multiple Choice',
    'select'   => 'Dropdown',
    'rating'   => 'Rating (1–5)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Questions — <?= h($settings['company_name']) ?></title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/dashboard.css">
<style>
.q-type-badge{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:#e2e8f0;color:#475569}
.q-options-preview{font-size:12px;color:#94a3b8;margin-top:2px}
.options-textarea{font-size:13px;height:90px;resize:vertical}
.form-hint{font-size:12px;color:#94a3b8;margin-top:4px}
.dept-section{margin-bottom:28px}
.dept-section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px}
.dept-section-title{font-size:16px;font-weight:700;color:#1e293b;display:flex;align-items:center;gap:8px}
.add-q-panel{background:#f8faf8;border:1px solid #e2e8e2;border-radius:10px;padding:20px;margin-top:12px}
.add-q-panel h4{font-size:14px;font-weight:600;color:#1B3A1B;margin:0 0 16px}
.q-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.q-form-grid .span2{grid-column:1/-1}
.edit-banner{background:linear-gradient(135deg,#1B3A1B,#245924);color:#fff;border-radius:12px;padding:20px 24px;margin-bottom:24px}
.edit-banner h3{margin:0 0 4px;color:#44B944;font-size:16px}
.edit-banner p{margin:0;font-size:13px;opacity:.8}
.inactive-row{opacity:.5}
@media(max-width:640px){.q-form-grid{grid-template-columns:1fr}}
</style>
</head>
<body class="dashboard-body">
<div class="dashboard-layout">

<?php require_once ROOT . '/includes/sidebar.php'; ?>

<div class="main-content">
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" id="mobileMenuBtn">☰</button>
      <h1 class="topbar-title">Department Questions</h1>
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

    <!-- ── Edit Mode Banner ─────────────────────────────────────────────── -->
    <?php if ($edit_q): ?>
    <div class="edit-banner">
      <h3>✏️ Editing Question #<?= $edit_q['id'] ?></h3>
      <p><?= h($edit_q['question_text']) ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Add / Edit Form ─────────────────────────────────────────────── -->
    <div class="card mb-4">
      <div class="card-header">
        <h3><?= $edit_q ? 'Edit Question' : 'Add New Question' ?></h3>
        <?php if ($edit_q): ?>
          <a href="<?= BASE_URL ?>/dashboard/questions.php" class="btn btn-sm btn-outline">✕ Cancel Edit</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="POST" action="" id="questionForm">
          <input type="hidden" name="_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action"  value="<?= $edit_q ? 'edit' : 'add' ?>">
          <?php if ($edit_q): ?>
            <input type="hidden" name="edit_id" value="<?= $edit_q['id'] ?>">
          <?php endif; ?>

          <div class="q-form-grid">
            <!-- Department -->
            <div class="form-group">
              <label class="form-label">Department</label>
              <select name="department_id" class="form-input">
                <option value="">— All Departments —</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>"
                    <?= ($edit_q && (int)$edit_q['department_id'] === (int)$d['id']) ? 'selected' : '' ?>>
                    <?= h($d['icon']) ?> <?= h($d['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="form-hint">Leave blank to show on all department forms.</p>
            </div>

            <!-- Type -->
            <div class="form-group">
              <label class="form-label">Answer Type</label>
              <select name="question_type" class="form-input" id="qTypeSelect">
                <?php foreach ($type_labels as $val => $label): ?>
                  <option value="<?= $val ?>"
                    <?= ($edit_q && $edit_q['question_type'] === $val) ? 'selected' : '' ?>>
                    <?= $label ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Question text -->
            <div class="form-group span2">
              <label class="form-label">Question Text <span style="color:#dc2626">*</span></label>
              <input type="text" name="question_text" class="form-input"
                     value="<?= h($edit_q['question_text'] ?? '') ?>"
                     placeholder="e.g. How satisfied were you with the response time?"
                     required maxlength="500">
            </div>

            <!-- Options (for radio/checkbox/select) -->
            <div class="form-group span2" id="optionsWrap" style="<?= ($edit_q && in_array($edit_q['question_type'], ['radio','checkbox','select'])) ? '' : 'display:none' ?>">
              <label class="form-label">Options <span style="color:#dc2626">*</span></label>
              <textarea name="options_text" class="form-input options-textarea"
                        placeholder="Enter one option per line&#10;e.g.&#10;Very Satisfied&#10;Satisfied&#10;Neutral&#10;Dissatisfied"><?php
                if ($edit_q && $edit_q['options']) {
                    $opts = json_decode($edit_q['options'], true) ?? [];
                    echo h(implode("\n", $opts));
                }
              ?></textarea>
              <p class="form-hint">One option per line.</p>
            </div>

            <!-- Sort order -->
            <div class="form-group">
              <label class="form-label">Sort Order</label>
              <input type="number" name="sort_order" class="form-input" min="0"
                     value="<?= (int)($edit_q['sort_order'] ?? 0) ?>" style="width:120px">
              <p class="form-hint">Lower numbers appear first.</p>
            </div>

            <!-- Required -->
            <div class="form-group" style="display:flex;align-items:center;gap:10px;padding-top:28px">
              <label class="toggle-switch" style="flex-shrink:0">
                <input type="checkbox" name="is_required" value="1"
                       <?= ($edit_q && $edit_q['is_required']) ? 'checked' : '' ?>>
                <span class="toggle-slider"></span>
              </label>
              <div>
                <strong style="font-size:14px">Required</strong>
                <div style="font-size:12px;color:#94a3b8">Respondent must answer this question</div>
              </div>
            </div>
          </div>

          <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-primary">
              <?= $edit_q ? '💾 Save Changes' : '➕ Add Question' ?>
            </button>
            <?php if ($edit_q): ?>
              <a href="<?= BASE_URL ?>/dashboard/questions.php" class="btn btn-outline">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Questions by Department ──────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <h3>All Questions</h3>
        <span class="chart-badge"><?= count($all_q) ?> total</span>
      </div>
      <div class="card-body" style="padding:0">

        <!-- All-departments questions -->
        <?php
        $all_dept_qs = $questions_by_dept['all'] ?? [];
        ?>
        <div class="dept-section" style="padding:20px;border-bottom:1px solid #f1f5f1">
          <div class="dept-section-header">
            <div class="dept-section-title">🌐 All Departments <span class="chart-badge"><?= count($all_dept_qs) ?></span></div>
          </div>
          <?php if (empty($all_dept_qs)): ?>
            <p class="text-muted text-sm">No questions set for all departments.</p>
          <?php else: ?>
            <?= render_questions_table($all_dept_qs, $pdo, $csrf, $type_labels) ?>
          <?php endif; ?>
        </div>

        <!-- Per-department questions -->
        <?php foreach ($departments as $dept): ?>
          <?php $dept_qs = $questions_by_dept[$dept['id']] ?? []; ?>
          <div class="dept-section" style="padding:20px;border-bottom:1px solid #f1f5f1">
            <div class="dept-section-header">
              <div class="dept-section-title">
                <?= h($dept['icon']) ?> <?= h($dept['name']) ?>
                <span class="chart-badge"><?= count($dept_qs) ?></span>
              </div>
            </div>
            <?php if (empty($dept_qs)): ?>
              <p class="text-muted text-sm">No questions for this department yet.</p>
            <?php else: ?>
              <?= render_questions_table($dept_qs, $pdo, $csrf, $type_labels) ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

      </div>
    </div>

  </div><!-- /page-content -->
</div><!-- /main-content -->
</div><!-- /dashboard-layout -->

<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
<script>
// Show/hide options textarea based on question type
(function () {
  const typeSelect  = document.getElementById('qTypeSelect');
  const optionsWrap = document.getElementById('optionsWrap');
  const needsOpts   = ['radio', 'checkbox', 'select'];
  function toggleOpts() {
    optionsWrap.style.display = needsOpts.includes(typeSelect.value) ? '' : 'none';
  }
  typeSelect.addEventListener('change', toggleOpts);
})();
</script>
</body>
</html>
<?php

/**
 * Render a table of questions with Edit / Toggle / Delete actions.
 */
function render_questions_table(array $qs, PDO $pdo, string $csrf, array $type_labels): string
{
    ob_start();
    ?>
    <div class="table-responsive">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th>Question</th>
            <th>Type</th>
            <th style="width:80px">Required</th>
            <th style="width:60px">Sort</th>
            <th style="width:80px">Status</th>
            <th style="width:140px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($qs as $q): ?>
          <tr class="<?= !$q['is_active'] ? 'inactive-row' : '' ?>">
            <td class="td-id"><?= $q['id'] ?></td>
            <td>
              <div style="font-weight:500;font-size:13px"><?= h($q['question_text']) ?></div>
              <?php if ($q['options']): ?>
                <div class="q-options-preview">
                  Options: <?= h(implode(', ', array_slice(json_decode($q['options'], true) ?? [], 0, 3))) ?>
                  <?= count(json_decode($q['options'], true) ?? []) > 3 ? '…' : '' ?>
                </div>
              <?php endif; ?>
            </td>
            <td><span class="q-type-badge"><?= h($type_labels[$q['question_type']] ?? $q['question_type']) ?></span></td>
            <td><?= $q['is_required'] ? '<span style="color:#16a34a;font-weight:600">Yes</span>' : '<span style="color:#94a3b8">No</span>' ?></td>
            <td><?= (int)$q['sort_order'] ?></td>
            <td>
              <span class="badge badge-<?= $q['is_active'] ? 'new' : 'reviewed' ?>">
                <?= $q['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <!-- Edit -->
                <a href="?edit=<?= $q['id'] ?>" class="btn btn-sm btn-outline" title="Edit">✏️</a>

                <!-- Toggle -->
                <form method="POST" action="" style="display:inline">
                  <input type="hidden" name="_token"      value="<?= h($csrf) ?>">
                  <input type="hidden" name="action"      value="toggle">
                  <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline" title="<?= $q['is_active'] ? 'Deactivate' : 'Activate' ?>">
                    <?= $q['is_active'] ? '⏸' : '▶️' ?>
                  </button>
                </form>

                <!-- Delete -->
                <form method="POST" action="" style="display:inline"
                      onsubmit="return confirm('Delete this question? Any saved answers will also be removed.')">
                  <input type="hidden" name="_token"      value="<?= h($csrf) ?>">
                  <input type="hidden" name="action"      value="delete">
                  <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline" style="color:#dc2626" title="Delete">🗑</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
}
