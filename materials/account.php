<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = mat_require_login();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur = $_POST['current'] ?? '';
    $new = $_POST['new'] ?? '';
    $new2 = $_POST['new2'] ?? '';
    $st = $pdo->prepare('SELECT password_hash FROM mat_users WHERE id = :id');
    $st->execute([':id' => $user['id']]);
    $hash = $st->fetchColumn();

    if (!mat_check_csrf()) $errors[] = 'Your session expired. Please try again.';
    elseif (!password_verify($cur, (string)$hash)) $errors[] = 'Your current password is incorrect.';
    elseif (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
    elseif ($new !== $new2) $errors[] = 'The new passwords do not match.';
    else {
        $pdo->prepare('UPDATE mat_users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => $user['id']]);
        mat_audit($pdo, (int)$user['id'], 'password_change', 'Changed own password');
        mat_flash('success', 'Your password has been changed.');
        mat_redirect(MAT_URL . '/account.php');
    }
}

mat_header('My account', 'account');
?>
<div class="card narrow">
  <dl class="kv">
    <div><dt>Name</dt><dd><?= e($user['full_name']) ?></dd></div>
    <div><dt>Username</dt><dd class="mono"><?= e($user['username']) ?></dd></div>
    <div><dt>Job title</dt><dd><?= e($user['job_title'] ?: '—') ?></dd></div>
    <div><dt>Access level</dt><dd><?= e(MAT_ROLES[$user['role']][0] ?? $user['role']) ?> <span class="muted small">— <?= e(MAT_ROLES[$user['role']][1] ?? '') ?></span></dd></div>
  </dl>
</div>

<form method="post" class="card form narrow">
  <h3>Change password</h3>
  <?php if ($errors): ?><div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
  <?= mat_csrf_field() ?>
  <label class="field"><span>Current password</span><input type="password" name="current" required></label>
  <div class="grid-2">
    <label class="field"><span>New password</span><input type="password" name="new" minlength="8" required></label>
    <label class="field"><span>Confirm new password</span><input type="password" name="new2" minlength="8" required></label>
  </div>
  <div class="form-actions"><button class="btn btn-primary">Update password</button></div>
</form>
<?php mat_footer();
