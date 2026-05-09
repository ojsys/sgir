<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_admin();

$settings     = get_site_settings($pdo);
$current_page = 'users';
$csrf         = csrf_token();

$success = '';
$error   = '';

// ── Handle POST actions ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Add new user ───────────────────────────────────────────────────
        if ($action === 'add') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? 'supervisor';

            if ($username === '' || $password === '') {
                $error = 'Username and password are required.';
            } elseif (!in_array($role, ['admin', 'supervisor'], true)) {
                $error = 'Invalid role selected.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                // Check username uniqueness
                $chk = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u LIMIT 1');
                $chk->execute([':u' => $username]);
                if ($chk->fetch()) {
                    $error = 'That username is already taken.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins  = $pdo->prepare(
                        'INSERT INTO admin_users (username, password_hash, role, is_active, created_at)
                         VALUES (:u, :h, :r, 1, :ts)'
                    );
                    $ins->execute([
                        ':u'  => $username,
                        ':h'  => $hash,
                        ':r'  => $role,
                        ':ts' => date('Y-m-d H:i:s'),
                    ]);
                    $success = "User \"{$username}\" created successfully.";
                }
            }

        // ── Edit user details ──────────────────────────────────────────────
        } elseif ($action === 'edit') {
            $uid      = (int)($_POST['user_id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $role     = $_POST['role'] ?? 'supervisor';

            if ($uid < 1 || $username === '') {
                $error = 'Invalid request.';
            } elseif (!in_array($role, ['admin', 'supervisor'], true)) {
                $error = 'Invalid role selected.';
            } elseif ($uid === (int)$_SESSION['user_id'] && $role !== 'admin') {
                $error = 'You cannot remove admin role from your own account.';
            } else {
                // Check username uniqueness (exclude self)
                $chk = $pdo->prepare('SELECT id FROM admin_users WHERE username = :u AND id != :id LIMIT 1');
                $chk->execute([':u' => $username, ':id' => $uid]);
                if ($chk->fetch()) {
                    $error = 'That username is already taken.';
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE admin_users SET username = :u, role = :r WHERE id = :id'
                    );
                    $upd->execute([':u' => $username, ':r' => $role, ':id' => $uid]);
                    $success = 'User updated successfully.';
                    header('Location: ' . BASE_URL . '/dashboard/users.php?saved=1');
                    exit;
                }
            }

        // ── Reset password ─────────────────────────────────────────────────
        } elseif ($action === 'reset_password') {
            $uid      = (int)($_POST['user_id'] ?? 0);
            $password = $_POST['new_password'] ?? '';

            if ($uid < 1 || $password === '') {
                $error = 'Invalid request.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id')
                    ->execute([':h' => $hash, ':id' => $uid]);
                $success = 'Password reset successfully.';
            }

        // ── Toggle active / inactive ───────────────────────────────────────
        } elseif ($action === 'toggle') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === (int)$_SESSION['user_id']) {
                $error = 'You cannot deactivate your own account.';
            } else {
                $pdo->prepare('UPDATE admin_users SET is_active = 1 - is_active WHERE id = :id')
                    ->execute([':id' => $uid]);
                $success = 'User status updated.';
            }

        // ── Delete user ────────────────────────────────────────────────────
        } elseif ($action === 'delete') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === (int)$_SESSION['user_id']) {
                $error = 'You cannot delete your own account.';
            } elseif ($uid < 1) {
                $error = 'Invalid user.';
            } else {
                $pdo->prepare('DELETE FROM admin_users WHERE id = :id')
                    ->execute([':id' => $uid]);
                $success = 'User deleted.';
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'User updated successfully.';
}

// ── Load all users ──────────────────────────────────────────────────────────
$users = $pdo->query(
    "SELECT * FROM admin_users ORDER BY
        CASE role WHEN 'admin' THEN 0 ELSE 1 END ASC,
        username ASC"
)->fetchAll();

// ── Edit mode ───────────────────────────────────────────────────────────────
$edit_user = null;
$edit_id   = (int)($_GET['edit'] ?? 0);
if ($edit_id > 0) {
    $e = $pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
    $e->execute([':id' => $edit_id]);
    $edit_user = $e->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Management — <?= h($settings['company_name']) ?></title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/dashboard.css">
<style>
.role-badge-admin{background:#1B3A1B;color:#44B944;font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px;letter-spacing:.3px}
.role-badge-supervisor{background:#e0f2fe;color:#0369a1;font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px}
.you-badge{font-size:10px;background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:20px;font-weight:600;margin-left:6px}
.user-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.user-form-grid .span2{grid-column:1/-1}
.password-reset-row{display:flex;gap:8px;align-items:flex-end}
.password-reset-row .form-group{flex:1;margin:0}
.edit-banner{background:linear-gradient(135deg,#1B3A1B,#245924);color:#fff;border-radius:12px;padding:20px 24px;margin-bottom:24px}
.edit-banner h3{margin:0 0 4px;color:#44B944;font-size:16px}
.edit-banner p{margin:0;font-size:13px;opacity:.8}
.perm-list{display:flex;flex-direction:column;gap:6px;margin-top:8px}
.perm-item{display:flex;align-items:center;gap:8px;font-size:13px}
.perm-icon{width:18px;text-align:center}
.section-divider{border:none;border-top:1px solid #e2e8f0;margin:24px 0}
@media(max-width:640px){.user-form-grid{grid-template-columns:1fr}}
</style>
</head>
<body class="dashboard-body">
<div class="dashboard-layout">

<?php require_once ROOT . '/includes/sidebar.php'; ?>

<div class="main-content">
  <div class="topbar">
    <div class="topbar-left">
      <button class="mobile-menu-btn" id="mobileMenuBtn">☰</button>
      <h1 class="topbar-title">User Management</h1>
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

    <?php if ($edit_user): ?>
    <div class="edit-banner">
      <h3>✏️ Editing User: <?= h($edit_user['username']) ?></h3>
      <p><?= $edit_user['role'] === 'admin' ? 'Administrator' : 'Supervisor' ?> · <?= $edit_user['is_active'] ? 'Active' : 'Inactive' ?></p>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start">

      <!-- ── Left: Add / Edit Form + User Table ──────────────────────────── -->
      <div>

        <!-- Add / Edit Form -->
        <div class="card mb-4">
          <div class="card-header">
            <h3><?= $edit_user ? 'Edit User' : 'Add New User' ?></h3>
            <?php if ($edit_user): ?>
              <a href="<?= BASE_URL ?>/dashboard/users.php" class="btn btn-sm btn-outline">✕ Cancel</a>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <form method="POST" action="">
              <input type="hidden" name="_token"  value="<?= h($csrf) ?>">
              <input type="hidden" name="action"  value="<?= $edit_user ? 'edit' : 'add' ?>">
              <?php if ($edit_user): ?>
                <input type="hidden" name="user_id" value="<?= $edit_user['id'] ?>">
              <?php endif; ?>

              <div class="user-form-grid">
                <div class="form-group">
                  <label class="form-label">Username <span style="color:#dc2626">*</span></label>
                  <input type="text" name="username" class="form-input"
                         value="<?= h($edit_user['username'] ?? '') ?>"
                         placeholder="e.g. john.doe" required maxlength="80"
                         autocomplete="off">
                </div>

                <div class="form-group">
                  <label class="form-label">Role <span style="color:#dc2626">*</span></label>
                  <select name="role" class="form-input">
                    <option value="supervisor" <?= ($edit_user && $edit_user['role'] === 'supervisor') ? 'selected' : '' ?>>Supervisor</option>
                    <option value="admin"      <?= ($edit_user && $edit_user['role'] === 'admin')      ? 'selected' : '' ?>>Administrator</option>
                  </select>
                </div>

                <?php if (!$edit_user): ?>
                <div class="form-group span2">
                  <label class="form-label">Password <span style="color:#dc2626">*</span></label>
                  <input type="password" name="password" class="form-input"
                         placeholder="Minimum 8 characters" required minlength="8"
                         autocomplete="new-password">
                </div>
                <?php endif; ?>
              </div>

              <div style="margin-top:16px">
                <button type="submit" class="btn btn-primary">
                  <?= $edit_user ? '💾 Save Changes' : '➕ Create User' ?>
                </button>
                <?php if ($edit_user): ?>
                  <a href="<?= BASE_URL ?>/dashboard/users.php" class="btn btn-outline" style="margin-left:8px">Cancel</a>
                <?php endif; ?>
              </div>
            </form>

            <!-- Password Reset (edit mode only) -->
            <?php if ($edit_user): ?>
            <hr class="section-divider">
            <h4 style="font-size:14px;font-weight:600;color:#1e293b;margin:0 0 12px">Reset Password</h4>
            <form method="POST" action="">
              <input type="hidden" name="_token"   value="<?= h($csrf) ?>">
              <input type="hidden" name="action"   value="reset_password">
              <input type="hidden" name="user_id"  value="<?= $edit_user['id'] ?>">
              <div class="password-reset-row">
                <div class="form-group">
                  <label class="form-label">New Password</label>
                  <input type="password" name="new_password" class="form-input"
                         placeholder="Minimum 8 characters" minlength="8"
                         autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-outline" style="height:42px;white-space:nowrap">
                  🔑 Reset
                </button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>

        <!-- Users Table -->
        <div class="card">
          <div class="card-header">
            <h3>All Users</h3>
            <span class="chart-badge"><?= count($users) ?> total</span>
          </div>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th style="width:160px">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                <?php $is_self = ((int)$u['id'] === (int)$_SESSION['user_id']); ?>
                <tr>
                  <td>
                    <strong><?= h($u['username']) ?></strong>
                    <?php if ($is_self): ?>
                      <span class="you-badge">You</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="role-badge-<?= h($u['role']) ?>">
                      <?= $u['role'] === 'admin' ? 'Admin' : 'Supervisor' ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge badge-<?= $u['is_active'] ? 'new' : 'reviewed' ?>">
                      <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                  </td>
                  <td class="td-date"><?= format_date($u['created_at']) ?></td>
                  <td>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                      <!-- Edit -->
                      <a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline" title="Edit">✏️</a>

                      <!-- Toggle active -->
                      <?php if (!$is_self): ?>
                      <form method="POST" action="" style="display:inline">
                        <input type="hidden" name="_token"   value="<?= h($csrf) ?>">
                        <input type="hidden" name="action"   value="toggle">
                        <input type="hidden" name="user_id"  value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline"
                                title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                          <?= $u['is_active'] ? '⏸' : '▶️' ?>
                        </button>
                      </form>

                      <!-- Delete -->
                      <form method="POST" action="" style="display:inline"
                            onsubmit="return confirm('Delete user "<?= h($u['username']) ?>"? This cannot be undone.')">
                        <input type="hidden" name="_token"   value="<?= h($csrf) ?>">
                        <input type="hidden" name="action"   value="delete">
                        <input type="hidden" name="user_id"  value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline"
                                style="color:#dc2626" title="Delete">🗑</button>
                      </form>
                      <?php else: ?>
                        <span style="font-size:11px;color:#94a3b8;padding:4px 2px">Cannot modify own account</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- ── Right: Role Permissions Reference ───────────────────────────── -->
      <div>
        <div class="card">
          <div class="card-header"><h3>Role Permissions</h3></div>
          <div class="card-body">

            <div style="margin-bottom:20px">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <span class="role-badge-admin">Admin</span>
                <span style="font-size:13px;color:#64748b">Full access</span>
              </div>
              <div class="perm-list">
                <?php foreach ([
                  '📊 Overview & analytics',
                  '💬 View all feedback',
                  '❓ Manage questions',
                  '📥 Export reports',
                  '📱 Manage QR codes',
                  '👥 Manage users',
                  '⚙️ Site settings',
                ] as $p): ?>
                  <div class="perm-item">
                    <span style="color:#16a34a;font-weight:700">✓</span>
                    <span><?= $p ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <hr class="section-divider" style="margin:16px 0">

            <div>
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                <span class="role-badge-supervisor">Supervisor</span>
                <span style="font-size:13px;color:#64748b">Read-only access</span>
              </div>
              <div class="perm-list">
                <?php
                $supervisor_perms = [
                  ['📊 Overview & analytics', true],
                  ['💬 View all feedback',    true],
                  ['❓ Manage questions',     false],
                  ['📥 Export reports',       false],
                  ['📱 Manage QR codes',      false],
                  ['👥 Manage users',         false],
                  ['⚙️ Site settings',        false],
                ];
                foreach ($supervisor_perms as [$label, $allowed]):
                ?>
                  <div class="perm-item">
                    <span style="color:<?= $allowed ? '#16a34a' : '#dc2626' ?>;font-weight:700">
                      <?= $allowed ? '✓' : '✗' ?>
                    </span>
                    <span style="color:<?= $allowed ? 'inherit' : '#94a3b8' ?>"><?= $label ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <hr class="section-divider" style="margin:16px 0">

            <p class="text-muted text-sm">
              Supervisors can view feedback and analytics but cannot change settings, manage questions, export data, or modify other users.
            </p>
          </div>
        </div>
      </div>

    </div>

  </div><!-- /page-content -->
</div><!-- /main-content -->
</div><!-- /dashboard-layout -->

<script src="<?= ASSET_URL ?>/js/dashboard.js"></script>
</body>
</html>
