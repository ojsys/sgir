<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

mat_session();

// First run: no users yet → create the first administrator
if ((int)$pdo->query('SELECT COUNT(*) FROM mat_users')->fetchColumn() === 0) {
    mat_redirect(MAT_URL . '/setup.php');
}
if (mat_user()) {
    mat_redirect(MAT_URL . '/index.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Basic brute-force slowdown per session
    $_SESSION['mat_fail'] = $_SESSION['mat_fail'] ?? 0;
    if ($_SESSION['mat_fail'] >= 5) {
        sleep(min(5, $_SESSION['mat_fail'] - 4));
    }

    if (!mat_check_csrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {
        $st = $pdo->prepare('SELECT * FROM mat_users WHERE username = :u LIMIT 1');
        $st->execute([':u' => $username]);
        $user = $st->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!(int)$user['is_active']) {
                $error = 'This account has been disabled. Contact the administrator.';
            } else {
                $_SESSION['mat_fail'] = 0;
                $pdo->prepare('UPDATE mat_users SET last_login_at = :t WHERE id = :id')
                    ->execute([':t' => mat_now(), ':id' => $user['id']]);
                mat_login($user);
                mat_redirect(MAT_URL . '/index.php');
            }
        } else {
            $_SESSION['mat_fail']++;
            $error = 'Incorrect username or password.';
        }
    }
}

mat_header('Sign in');
?>
<div class="auth-card">
  <div class="auth-brand">
    <span class="brand-mark lg">S</span>
    <div>
      <h1><?= e(APP_NAME) ?> Materials</h1>
      <p>Warehouse &amp; inventory system</p>
    </div>
  </div>

  <?php if (isset($_GET['out'])): ?><div class="alert alert-success">You have signed out.</div><?php endif; ?>
  <?php if (isset($_GET['disabled'])): ?><div class="alert alert-error">Your account was disabled or removed.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="form" autocomplete="on">
    <?= mat_csrf_field() ?>
    <label class="field">
      <span>Username</span>
      <input type="text" name="username" value="<?= e($username) ?>" required autofocus autocapitalize="none">
    </label>
    <label class="field">
      <span>Password</span>
      <input type="password" name="password" required>
    </label>
    <button class="btn btn-primary btn-block btn-lg" type="submit">Sign in</button>
  </form>
  <p class="muted small center">No account? Ask the rig administrator to add you.</p>
</div>
<?php mat_footer();
