<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();

$settings = get_site_settings($pdo);
$company  = h($settings['company_name']);
$logoPath = $settings['logo_path'] ? BASE_URL . '/' . ltrim($settings['logo_path'], '/') : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Thank You — <?= $company ?></title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/feedback.css">
</head>
<body class="feedback-body feedback-body--success">

<main class="success-page">
  <div class="success-card">

    <!-- Animated checkmark -->
    <div class="success-check-wrap">
      <div class="success-ripple"></div>
      <div class="success-ripple success-ripple--2"></div>
      <svg class="success-svg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle class="success-circle" cx="50" cy="50" r="44"
                stroke="#44B944" stroke-width="5"
                stroke-linecap="round" fill="none"/>
        <polyline class="success-check" points="28,52 43,67 72,35"
                  stroke="#44B944" stroke-width="5"
                  stroke-linecap="round" stroke-linejoin="round" fill="none"/>
      </svg>
    </div>

    <!-- Branding -->
    <div class="success-brand">
      <?php if ($logoPath): ?>
        <img src="<?= h($logoPath) ?>" alt="<?= $company ?>" class="success-logo">
      <?php else: ?>
        <div class="success-brand-icon">⚡</div>
      <?php endif; ?>
      <span class="success-brand-name"><?= $company ?></span>
    </div>

    <h1 class="success-title">Thank You!</h1>
    <p class="success-message">
      Your feedback has been received and will be reviewed by our team.
      Your voice helps us build a safer, better workplace for everyone on the rig.
    </p>

    <div class="success-meta">
      <div class="success-meta-item">
        <span class="success-meta-icon">🔒</span>
        <span>Fully confidential</span>
      </div>
      <div class="success-meta-item">
        <span class="success-meta-icon">⚡</span>
        <span>Reviewed promptly</span>
      </div>
      <div class="success-meta-item">
        <span class="success-meta-icon">🤝</span>
        <span>Action taken</span>
      </div>
    </div>

    <div class="success-actions">
      <a href="<?= BASE_URL ?>/feedback/index.php" class="btn btn-accent btn-lg">
        Submit Another Feedback
      </a>
      <a href="<?= BASE_URL ?>/feedback/index.php" class="btn btn-outline-light btn-lg">
        Back to Home
      </a>
    </div>

  </div>
</main>

<footer class="feedback-footer">
  <p>&copy; <?= date('Y') ?> <?= $company ?>. All feedback is confidential.</p>
</footer>

</body>
</html>
