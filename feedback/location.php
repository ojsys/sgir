<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();

// ── Department context ───────────────────────────────────────────────────────
$dept_slug = trim($_GET['dept'] ?? '');
if ($dept_slug === '') {
    redirect(BASE_URL . '/feedback/index.php');
}

$stmt = $pdo->prepare('SELECT * FROM departments WHERE slug = :slug AND is_active = 1 LIMIT 1');
$stmt->execute([':slug' => $dept_slug]);
$dept = $stmt->fetch();
if (!$dept) {
    redirect(BASE_URL . '/feedback/index.php');
}

// Preserve QR tracking identifier through to the form, if present
$qr = trim($_GET['qr'] ?? '');

// Build a query string for the onward link to the form
function forward_qs(string $dept_slug, int $loc_id, string $qr): string
{
    $params = ['dept' => $dept_slug, 'loc' => $loc_id];
    if ($qr !== '') {
        $params['qr'] = $qr;
    }
    return http_build_query($params);
}

// ── Locations ────────────────────────────────────────────────────────────────
$locations = get_active_locations($pdo);

// If no locations are configured yet, skip this layer entirely so the portal
// keeps working until an admin adds them.
if (empty($locations)) {
    $skip = ['dept' => $dept_slug];
    if ($qr !== '') {
        $skip['qr'] = $qr;
    }
    redirect(BASE_URL . '/feedback/form.php?' . http_build_query($skip));
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
<title><?= h($dept['name']) ?> — Select Location — <?= $company ?></title>
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
        <span class="logo-tagline"><?= h($dept['icon']) ?> <?= h($dept['name']) ?></span>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/feedback/index.php" class="header-admin-link">← Back</a>
  </div>
</header>

<!-- ─── Hero ────────────────────────────────────────────────────────────── -->
<section class="feedback-hero">
  <div class="feedback-hero-inner">
    <h1 class="hero-title">Where are you located?</h1>
    <p class="hero-subtitle">Select your rig location so we know where this feedback is coming from.</p>
  </div>
</section>

<!-- ─── Location Grid ───────────────────────────────────────────────────── -->
<main class="feedback-main">
  <div class="container">
    <p class="section-label">Select a location to continue</p>

    <div class="dept-grid">
      <?php foreach ($locations as $loc): ?>
        <a href="<?= BASE_URL ?>/feedback/form.php?<?= h(forward_qs($dept_slug, (int)$loc['id'], $qr)) ?>"
           class="dept-card"
           data-loc="<?= (int)$loc['id'] ?>">
          <div class="dept-card-icon">📍</div>
          <div class="dept-card-name"><?= h($loc['name']) ?></div>
          <?php if (!empty($loc['code'])): ?>
            <div class="dept-card-code"><?= h($loc['code']) ?></div>
          <?php endif; ?>
          <div class="dept-card-arrow">→</div>
          <div class="dept-card-ripple"></div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</main>

<!-- ─── Footer ──────────────────────────────────────────────────────────── -->
<footer class="feedback-footer">
  <p>&copy; <?= date('Y') ?> <?= $company ?>. All feedback is confidential.</p>
</footer>

<script src="<?= ASSET_URL ?>/js/feedback.js"></script>
</body>
</html>
