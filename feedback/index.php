<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();

// Handle QR scan tracking
$qr_identifier = trim($_GET['qr'] ?? '');
if ($qr_identifier !== '') {
    $stmt = $pdo->prepare('UPDATE qrcodes SET scan_count = scan_count + 1 WHERE identifier = :id');
    $stmt->execute([':id' => $qr_identifier]);
}

// Preserve the QR identifier on the onward link so it survives the location step
$qr_query = $qr_identifier !== '' ? '&qr=' . urlencode($qr_identifier) : '';

// Load active departments
$depts = $pdo->query(
    "SELECT * FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, name ASC"
)->fetchAll();

$settings = get_site_settings($pdo);
$company  = h($settings['company_name']);
$tagline  = h($settings['company_tagline']);
$logoPath = $settings['logo_path'] ? BASE_URL . '/' . ltrim($settings['logo_path'], '/') : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $company ?> — Feedback Portal</title>
<?php if ($settings['favicon_path']): ?>
<link rel="icon" href="<?= BASE_URL . '/' . ltrim($settings['favicon_path'], '/') ?>">
<?php else: ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>⚡</text></svg>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/main.css">
<link rel="stylesheet" href="<?= ASSET_URL ?>/css/feedback.css">
</head>
<body class="feedback-body">

<!-- ─── Header ─────────────────────────────────────────────────────────── -->
<header class="feedback-header">
  <div class="feedback-header-inner">
    <div class="feedback-logo">
      <?php if ($logoPath): ?>
        <img src="<?= h($logoPath) ?>" alt="<?= $company ?> Logo" class="logo-img">
      <?php else: ?>
        <div class="logo-icon-fallback">⚡</div>
      <?php endif; ?>
      <div class="logo-text">
        <span class="logo-company"><?= $company ?></span>
        <span class="logo-tagline"><?= $tagline ?></span>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/dashboard/login.php" class="header-admin-link">Admin ↗</a>
  </div>
</header>

<!-- ─── Hero ────────────────────────────────────────────────────────────── -->
<section class="feedback-hero">
  <div class="feedback-hero-inner">
    <h1 class="hero-title">Share Your Feedback</h1>
    <p class="hero-subtitle">Your voice matters. Help us build a safer, better workplace for everyone on the rig.</p>
  </div>
</section>

<!-- ─── Department Grid ─────────────────────────────────────────────────── -->
<main class="feedback-main">
  <div class="container">
    <p class="section-label">Select a department to continue</p>

    <?php if (empty($depts)): ?>
      <div class="empty-state">
        <div class="empty-icon">🏢</div>
        <h3>No departments configured</h3>
        <p>Please contact your administrator.</p>
      </div>
    <?php else: ?>
      <div class="dept-grid">
        <?php foreach ($depts as $dept): ?>
          <a href="<?= BASE_URL ?>/feedback/location.php?dept=<?= h($dept['slug']) ?><?= $qr_query ?>"
             class="dept-card"
             data-dept="<?= h($dept['slug']) ?>">
            <div class="dept-card-icon"><?= $dept['icon'] ?></div>
            <div class="dept-card-name"><?= h($dept['name']) ?></div>
            <div class="dept-card-arrow">→</div>
            <div class="dept-card-ripple"></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</main>

<!-- ─── Footer ──────────────────────────────────────────────────────────── -->
<footer class="feedback-footer">
  <p>&copy; <?= date('Y') ?> <?= $company ?>. All feedback is confidential.</p>
</footer>

<script src="<?= ASSET_URL ?>/js/feedback.js"></script>
</body>
</html>
