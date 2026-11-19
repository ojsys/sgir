<?php
/**
 * First-run setup: create the first administrator.
 * Locked automatically as soon as any Materials user exists.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

mat_session();

if ((int)$pdo->query('SELECT COUNT(*) FROM mat_users')->fetchColumn() > 0) {
    mat_redirect(MAT_URL . '/login.php');
}

$error = '';
$f = ['full_name' => '', 'username' => '', 'job_title' => 'Rig Manager'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($f as $k => $_) $f[$k] = trim($_POST[$k] ?? '');
    $pw  = $_POST['password'] ?? '';
    $pw2 = $_POST['password2'] ?? '';

    if (!mat_check_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($f['full_name'] === '' || $f['username'] === '') {
        $error = 'Full name and username are required.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $f['username'])) {
        $error = 'Username must be 3–60 characters: letters, numbers, dot, dash or underscore.';
    } elseif (strlen($pw) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $error = 'The two passwords do not match.';
    } else {
        $pdo->prepare('INSERT INTO mat_users (username, full_name, job_title, password_hash, role, is_active, created_at)
                       VALUES (:u, :n, :j, :h, :r, 1, :t)')
            ->execute([':u' => $f['username'], ':n' => $f['full_name'], ':j' => $f['job_title'],
                       ':h' => password_hash($pw, PASSWORD_DEFAULT), ':r' => 'admin', ':t' => mat_now()]);
        $id = (int)$pdo->lastInsertId();
        mat_audit($pdo, $id, 'setup', 'First administrator created: ' . $f['username']);
        $st = $pdo->prepare('SELECT * FROM mat_users WHERE id = :id');
        $st->execute([':id' => $id]);
        mat_login($st->fetch());
        mat_flash('success', 'Welcome! Your administrator account is ready. Next, add your warehouse managers and toolpushers under Users & Access.');
        mat_redirect(MAT_URL . '/index.php');
    }
}

mat_header('First-time setup');
?>
<div class="auth-card">
  <div class="auth-brand">
    <span class="brand-mark lg">S</span>
    <div>
      <h1>Set up Materials</h1>
      <p>Create the first administrator account</p>
    </div>
  </div>
  <p class="muted small">The SGIR Item Master (<?= (int)$pdo->query('SELECT COUNT(*) FROM mat_items')->fetchColumn() ?> items) has been loaded as opening stock.
     This page only works once — after this, new users are added by an administrator.</p>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= mat_csrf_field() ?>
    <label class="field"><span>Full name</span><input name="full_name" value="<?= e($f['full_name']) ?>" required></label>
    <label class="field"><span>Job title</span><input name="job_title" value="<?= e($f['job_title']) ?>"></label>
    <label class="field"><span>Username</span><input name="username" value="<?= e($f['username']) ?>" required autocapitalize="none"></label>
    <div class="grid-2">
      <label class="field"><span>Password</span><input type="password" name="password" minlength="8" required></label>
      <label class="field"><span>Confirm password</span><input type="password" name="password2" minlength="8" required></label>
    </div>
    <button class="btn btn-primary btn-block btn-lg">Create administrator</button>
  </form>
</div>
<?php mat_footer();
