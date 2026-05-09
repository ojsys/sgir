<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_admin();

$settings     = get_site_settings($pdo);
$current_page = 'settings';
$csrf         = csrf_token();

$success = '';
$error   = '';

// ── Utility: handle file upload ────────────────────────────────────────────
function handle_upload(array $file_arr, string $subdir, string $field_name): ?string
{
    if (!isset($file_arr['error']) || $file_arr['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // nothing uploaded
    }
    if ($file_arr['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
        ];
        $msg = $upload_errors[$file_arr['error']] ?? "Upload error code {$file_arr['error']}.";
        throw new RuntimeException($msg);
    }

    if ($file_arr['size'] > 2 * 1024 * 1024) {
        throw new RuntimeException("File too large for {$field_name}. Maximum 2 MB.");
    }

    // Allowed MIME types — include all variants seen across different server configs
    $allowed_mimes = [
        'image/png', 'image/x-png',
        'image/jpeg', 'image/jpg', 'image/pjpeg',
        'image/gif',
        'image/webp',
        'image/svg+xml', 'image/svg',
        'image/x-icon', 'image/vnd.microsoft.icon', 'image/ico',
        'application/octet-stream', // ICO files often reported as this on cPanel
    ];

    // Allowed extensions mapped to canonical MIME — used as fallback
    $ext_map = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
    ];

    $ext  = strtolower(pathinfo($file_arr['name'], PATHINFO_EXTENSION));
    $mime = '';

    // Prefer finfo (most reliable), fall back to mime_content_type()
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo ? (string)finfo_file($finfo, $file_arr['tmp_name']) : '';
        if ($finfo) finfo_close($finfo);
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($file_arr['tmp_name']);
    }

    // Validate: MIME must be in allowed list, OR extension must be known image type
    $mime_ok = in_array($mime, $allowed_mimes, true);
    $ext_ok  = isset($ext_map[$ext]);

    if (!$mime_ok && !$ext_ok) {
        throw new RuntimeException(
            "Invalid file type for {$field_name} (detected: {$mime}, extension: .{$ext}). " .
            "Allowed: PNG, JPG, GIF, WEBP, SVG, ICO."
        );
    }

    // Extra safety: reject if MIME is octet-stream but extension is not a known image type
    if ($mime === 'application/octet-stream' && !$ext_ok) {
        throw new RuntimeException("Invalid file type for {$field_name}. Allowed: PNG, JPG, GIF, WEBP, SVG, ICO.");
    }

    $dir = UPLOAD_DIR . $subdir . '/';
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create upload directory. Check that uploads/ is writable (chmod 755).");
        }
    }
    if (!is_writable($dir)) {
        throw new RuntimeException("Upload directory is not writable. Set uploads/{$subdir}/ to chmod 755 in cPanel File Manager.");
    }

    $safe_ext = $ext_map[$ext] ? $ext : 'png'; // default to png if unknown ext
    $filename = $field_name . '_' . time() . '.' . $ext;
    $dest     = $dir . $filename;

    if (!move_uploaded_file($file_arr['tmp_name'], $dest)) {
        throw new RuntimeException("Failed to move uploaded file. Check directory permissions on uploads/{$subdir}/.");
    }

    return 'uploads/' . $subdir . '/' . $filename;
}

// ── Handle POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'branding') {
                // Logo upload
                $new_logo    = handle_upload($_FILES['logo']    ?? [], 'site', 'logo');
                $new_favicon = handle_upload($_FILES['favicon'] ?? [], 'site', 'favicon');

                // Remove current logo?
                if (!empty($_POST['remove_logo'])) {
                    if ($settings['logo_path'] && file_exists(ROOT . '/' . $settings['logo_path'])) {
                        @unlink(ROOT . '/' . $settings['logo_path']);
                    }
                    $new_logo = '';
                }
                if (!empty($_POST['remove_favicon'])) {
                    if ($settings['favicon_path'] && file_exists(ROOT . '/' . $settings['favicon_path'])) {
                        @unlink(ROOT . '/' . $settings['favicon_path']);
                    }
                    $new_favicon = '';
                }

                $upd_fields = [];
                $upd_params = [':id' => 1];

                if ($new_logo !== null) {
                    $upd_fields[] = 'logo_path = :logo';
                    $upd_params[':logo'] = $new_logo ?: null;
                }
                if ($new_favicon !== null) {
                    $upd_fields[] = 'favicon_path = :favicon';
                    $upd_params[':favicon'] = $new_favicon ?: null;
                }

                if ($upd_fields) {
                    $pdo->prepare('UPDATE admin_settings SET ' . implode(', ', $upd_fields) . ' WHERE id = :id')
                        ->execute($upd_params);
                }
                $success = 'Branding updated.';

            } elseif ($action === 'general') {
                $company_name    = trim($_POST['company_name']    ?? '');
                $company_tagline = trim($_POST['company_tagline'] ?? '');
                $notif_emails    = trim($_POST['notification_emails'] ?? '');

                if ($company_name === '') {
                    throw new RuntimeException('Company name is required.');
                }

                $pdo->prepare(
                    'UPDATE admin_settings SET company_name=:name, company_tagline=:tagline, notification_emails=:emails WHERE id=1'
                )->execute([
                    ':name'    => $company_name,
                    ':tagline' => $company_tagline,
                    ':emails'  => $notif_emails ?: null,
                ]);
                $success = 'General settings saved.';

            } elseif ($action === 'add_dept') {
                $dept_name  = trim($_POST['dept_name']  ?? '');
                $dept_icon  = trim($_POST['dept_icon']  ?? '💬');
                $dept_order = (int)($_POST['dept_order'] ?? 0);

                if ($dept_name === '') {
                    throw new RuntimeException('Department name is required.');
                }
                $dept_slug_new = slug($dept_name);

                $pdo->prepare(
                    'INSERT INTO departments (name, slug, icon, sort_order, is_active) VALUES (:n, :s, :i, :o, 1)'
                )->execute([
                    ':n' => $dept_name,
                    ':s' => $dept_slug_new,
                    ':i' => $dept_icon,
                    ':o' => $dept_order,
                ]);
                $success = 'Department "' . $dept_name . '" added.';

            } elseif ($action === 'toggle_dept') {
                $dept_id  = (int)($_POST['dept_id']  ?? 0);
                $new_status = (int)($_POST['new_status'] ?? 0);
                if ($dept_id > 0) {
                    $pdo->prepare('UPDATE departments SET is_active = :s WHERE id = :id')
                        ->execute([':s' => $new_status, ':id' => $dept_id]);
                    $success = 'Department status updated.';
                }
            }

        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        // Reload settings
        $settings = get_site_settings($pdo);
    }
}

// ── Load departments ───────────────────────────────────────────────────────
$departments = $pdo->query('SELECT * FROM departments ORDER BY sort_order, name')->fetchAll();

$logoPath    = $settings['logo_path']    ? BASE_URL . '/' . ltrim($settings['logo_path'],    '/') : null;
$faviconPath = $settings['favicon_path'] ? BASE_URL . '/' . ltrim($settings['favicon_path'], '/') : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings — <?= h($settings['company_name']) ?></title>
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
      <h1 class="topbar-title">Settings</h1>
    </div>
    <div class="topbar-right">
      <div class="topbar-avatar"><?= mb_strtoupper(mb_substr($_SESSION['username'] ?? 'A', 0, 1)) ?></div>
    </div>
  </div>

  <div class="page-content">

    <?php if ($success): ?><div class="alert alert-success" id="pageAlert"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <div class="settings-layout">

      <!-- ── SITE BRANDING ───────────────────────────────────────────── -->
      <div class="card settings-card">
        <div class="card-header settings-card-header">
          <h2>🎨 Site Branding</h2>
          <p>Upload your company logo and favicon.</p>
        </div>
        <div class="card-body">
          <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="branding">

            <div class="branding-row">
              <!-- Logo -->
              <div class="branding-item">
                <h4>Company Logo</h4>
                <div class="upload-box" id="logoBox">
                  <?php if ($logoPath): ?>
                    <img src="<?= h($logoPath) ?>" alt="Current Logo" class="upload-preview">
                  <?php else: ?>
                    <div class="upload-prompt">
                      <div class="upload-icon">🖼️</div>
                      <p>Click to upload logo</p>
                      <small>PNG, JPG, SVG — max 2 MB</small>
                    </div>
                  <?php endif; ?>
                  <input type="file" name="logo" id="logoInput" accept="image/*" class="upload-input"
                         onchange="previewUpload(this, 'logoBox')">
                </div>
                <?php if ($logoPath): ?>
                  <label class="remove-checkbox">
                    <input type="checkbox" name="remove_logo" value="1"> Remove current logo
                  </label>
                <?php endif; ?>
              </div>

              <!-- Favicon -->
              <div class="branding-item">
                <h4>Favicon</h4>
                <div class="upload-box upload-box--small" id="faviconBox">
                  <?php if ($faviconPath): ?>
                    <img src="<?= h($faviconPath) ?>" alt="Current Favicon" class="upload-preview">
                  <?php else: ?>
                    <div class="upload-prompt">
                      <div class="upload-icon">⭐</div>
                      <p>Click to upload favicon</p>
                      <small>ICO, PNG, SVG</small>
                    </div>
                  <?php endif; ?>
                  <input type="file" name="favicon" id="faviconInput" accept="image/*,.ico"
                         class="upload-input" onchange="previewUpload(this, 'faviconBox')">
                </div>
                <?php if ($faviconPath): ?>
                  <label class="remove-checkbox">
                    <input type="checkbox" name="remove_favicon" value="1"> Remove current favicon
                  </label>
                <?php endif; ?>
              </div>
            </div>

            <button type="submit" class="btn btn-primary mt-4">Save Branding</button>
          </form>
        </div>
      </div>

      <!-- ── GENERAL SETTINGS ────────────────────────────────────────── -->
      <div class="card settings-card">
        <div class="card-header settings-card-header">
          <h2>⚙️ General Settings</h2>
          <p>Company information and notification preferences.</p>
        </div>
        <div class="card-body">
          <form method="POST" action="">
            <input type="hidden" name="_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="general">

            <div class="settings-form-grid">
              <div class="form-group">
                <label class="form-label required" for="company_name">Company Name</label>
                <input type="text" id="company_name" name="company_name" class="form-input"
                       value="<?= h($settings['company_name']) ?>" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="company_tagline">Company Tagline</label>
                <input type="text" id="company_tagline" name="company_tagline" class="form-input"
                       value="<?= h($settings['company_tagline']) ?>"
                       placeholder="e.g., Oil Rig Feedback Portal">
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="notification_emails">Notification Emails</label>
              <textarea id="notification_emails" name="notification_emails"
                        class="form-textarea" rows="3"
                        placeholder="admin@example.com, manager@example.com"><?= h($settings['notification_emails'] ?? '') ?></textarea>
              <p class="form-hint">Comma-separated email addresses. These receive a notification on new feedback submissions.</p>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
          </form>
        </div>
      </div>

      <!-- ── DEPARTMENT MANAGEMENT ───────────────────────────────────── -->
      <div class="card settings-card">
        <div class="card-header settings-card-header">
          <h2>🏢 Department Management</h2>
          <p>Manage departments shown in the feedback portal.</p>
        </div>
        <div class="card-body">

          <!-- Department list -->
          <div class="dept-list">
            <?php foreach ($departments as $dept): ?>
            <div class="dept-list-item <?= $dept['is_active'] ? '' : 'dept-list-item--inactive' ?>">
              <div class="dept-list-info">
                <span class="dept-list-icon"><?= $dept['icon'] ?></span>
                <span class="dept-list-name"><?= h($dept['name']) ?></span>
                <span class="badge <?= $dept['is_active'] ? 'badge-actioned' : 'badge-new' ?>">
                  <?= $dept['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
              </div>
              <form method="POST" action="" class="dept-toggle-form">
                <input type="hidden" name="_token"     value="<?= h($csrf) ?>">
                <input type="hidden" name="action"     value="toggle_dept">
                <input type="hidden" name="dept_id"    value="<?= $dept['id'] ?>">
                <input type="hidden" name="new_status" value="<?= $dept['is_active'] ? 0 : 1 ?>">
                <button type="submit" class="btn btn-sm <?= $dept['is_active'] ? 'btn-danger' : 'btn-outline' ?>">
                  <?= $dept['is_active'] ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Add department -->
          <details class="add-dept-details">
            <summary class="add-dept-summary">+ Add New Department</summary>
            <form method="POST" action="" class="add-dept-form">
              <input type="hidden" name="_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="add_dept">
              <div class="add-dept-grid">
                <div class="form-group">
                  <label class="form-label required">Department Name</label>
                  <input type="text" name="dept_name" class="form-input" placeholder="e.g., IT Support" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Icon (emoji)</label>
                  <input type="text" name="dept_icon" class="form-input" value="💬" maxlength="10">
                </div>
                <div class="form-group">
                  <label class="form-label">Sort Order</label>
                  <input type="number" name="dept_order" class="form-input" value="0" min="0">
                </div>
              </div>
              <button type="submit" class="btn btn-accent">Add Department</button>
            </form>
          </details>

        </div>
      </div>

    </div><!-- /settings-layout -->
  </div><!-- /page-content -->
</div><!-- /main-content -->
</div><!-- /dashboard-layout -->

<script>
function previewUpload(input, boxId) {
  const box = document.getElementById(boxId);
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    // Update or create the preview image without touching the file input
    let img = box.querySelector('.upload-preview');
    if (!img) {
      img = document.createElement('img');
      img.alt = 'Preview';
      img.className = 'upload-preview';
      box.insertBefore(img, box.firstChild);
    }
    img.src = e.target.result;
    // Hide the upload prompt text if present
    const prompt = box.querySelector('.upload-prompt');
    if (prompt) prompt.style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
}
</script>
<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
