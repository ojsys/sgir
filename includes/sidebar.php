<?php
/**
 * Dashboard sidebar partial.
 * Expects: $settings (from get_site_settings), $current_page (string key)
 */
$company  = h($settings['company_name'] ?? APP_NAME);
$logoPath = !empty($settings['logo_path'])
    ? BASE_URL . '/' . ltrim($settings['logo_path'], '/')
    : null;
$username = h($_SESSION['username'] ?? 'Admin');

$_sidebar_role = $_SESSION['user_role'] ?? 'admin';

$nav_items_all = [
    'overview'  => ['icon' => '📊', 'label' => 'Overview',    'url' => BASE_URL . '/dashboard/overview.php',  'roles' => ['admin','supervisor']],
    'feedback'  => ['icon' => '💬', 'label' => 'Feedback',    'url' => BASE_URL . '/dashboard/feedback.php',  'roles' => ['admin','supervisor']],
    'safety'    => ['icon' => '⚠️', 'label' => 'Safety Obs', 'url' => BASE_URL . '/dashboard/safety.php',    'roles' => ['admin','supervisor']],
    'medical'   => ['icon' => '🏥', 'label' => 'Medical',     'url' => BASE_URL . '/dashboard/medical.php',   'roles' => ['admin','supervisor']],
    'questions' => ['icon' => '❓', 'label' => 'Questions',   'url' => BASE_URL . '/dashboard/questions.php', 'roles' => ['admin']],
    'locations' => ['icon' => '📍', 'label' => 'Rig Locations','url' => BASE_URL . '/dashboard/locations.php', 'roles' => ['admin']],
    'export'    => ['icon' => '📥', 'label' => 'Export',      'url' => BASE_URL . '/dashboard/export.php',    'roles' => ['admin']],
    'qrcodes'   => ['icon' => '📱', 'label' => 'QR Codes',    'url' => BASE_URL . '/dashboard/qr-codes.php', 'roles' => ['admin']],
    'users'     => ['icon' => '👥', 'label' => 'Users',       'url' => BASE_URL . '/dashboard/users.php',     'roles' => ['admin']],
    'settings'  => ['icon' => '⚙️', 'label' => 'Settings',   'url' => BASE_URL . '/dashboard/settings.php',  'roles' => ['admin']],
];

$nav_items = array_filter(
    $nav_items_all,
    fn($item) => in_array($_sidebar_role, $item['roles'], true)
);
?>
<!-- ──────────────────── Sidebar ──────────────────────────────────────────── -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo-wrap">
    <?php if ($logoPath): ?>
      <img src="<?= h($logoPath) ?>" alt="<?= $company ?>" class="sidebar-logo-img">
    <?php else: ?>
      <div class="sidebar-logo-icon">⚡</div>
    <?php endif; ?>
    <div class="sidebar-logo-text">
      <span class="sidebar-company"><?= $company ?></span>
      <span class="sidebar-sub">Admin Panel</span>
    </div>
  </div>

  <nav class="sidebar-nav" role="navigation" aria-label="Main navigation">
    <?php foreach ($nav_items as $key => $item): ?>
      <a href="<?= $item['url'] ?>"
         class="sidebar-nav-item <?= $current_page === $key ? 'active' : '' ?>"
         <?= $current_page === $key ? 'aria-current="page"' : '' ?>>
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <span class="nav-label"><?= $item['label'] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-bottom">
    <div class="sidebar-user">
      <div class="user-avatar"><?= mb_strtoupper(mb_substr($username, 0, 1)) ?></div>
      <div class="user-info">
        <span class="user-name"><?= $username ?></span>
        <span class="user-role"><?= $_sidebar_role === 'admin' ? 'Administrator' : 'Supervisor' ?></span>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/dashboard/logout.php" class="sidebar-logout-btn">
      <span>🚪</span> Sign Out
    </a>
    <a href="<?= BASE_URL ?>/feedback/index.php" class="sidebar-portal-link" target="_blank">
      ↗ View Feedback Portal
    </a>
  </div>
</aside>

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>
