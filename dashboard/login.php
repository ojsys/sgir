<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();

// Already logged in? Go to overview
if (is_logged_in()) {
    redirect(BASE_URL . '/dashboard/overview.php');
}

$error  = '';
$csrf   = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password']      ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = :u AND is_active = 1 LIMIT 1');
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                login_user($user);
                redirect(BASE_URL . '/dashboard/overview.php');
            } else {
                $error = 'Invalid username or password. Please try again.';
                // Regenerate CSRF after failed login
                unset($_SESSION['csrf_token']);
                $csrf = csrf_token();
            }
        }
    }
}

$settings = get_site_settings($pdo);
$company  = h($settings['company_name']);
$logoPath = $settings['logo_path'] ? BASE_URL . '/' . ltrim($settings['logo_path'], '/') : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — <?= $company ?></title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/dashboard.css">
</head>
<body class="login-body">

<div class="login-page">
  <div class="login-card">
    <!-- Logo / Brand -->
    <div class="login-brand">
      <?php if ($logoPath): ?>
        <img src="<?= h($logoPath) ?>" alt="<?= $company ?>" class="login-logo">
      <?php else: ?>
        <div class="login-brand-icon">⚡</div>
      <?php endif; ?>
      <h1 class="login-company"><?= $company ?></h1>
      <p class="login-tagline">Admin Dashboard</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-error" role="alert">
        <span class="alert-icon">⚠️</span>
        <?= h($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
      <input type="hidden" name="_token" value="<?= h($csrf) ?>">

      <div class="form-group">
        <label class="form-label" for="username">Username</label>
        <div class="input-with-icon">
          <span class="input-icon">👤</span>
          <input type="text" id="username" name="username"
                 class="form-input form-input--icon"
                 value="<?= h($_POST['username'] ?? '') ?>"
                 placeholder="Enter your username"
                 autocomplete="username"
                 autofocus required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">Password</label>
        <div class="input-with-icon">
          <span class="input-icon">🔐</span>
          <input type="password" id="password" name="password"
                 class="form-input form-input--icon"
                 placeholder="Enter your password"
                 autocomplete="current-password" required>
        </div>
      </div>

      <button type="submit" class="btn btn-accent btn-lg login-submit-btn">
        Sign In →
      </button>
    </form>

    <div class="login-footer-link">
      <a href="<?= BASE_URL ?>/feedback/index.php">← Back to Feedback Portal</a>
    </div>
  </div>
</div>

</body>
</html>
