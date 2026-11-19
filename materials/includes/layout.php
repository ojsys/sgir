<?php
/**
 * Page chrome for the Materials module.
 * mat_header($title, $active) … page content … mat_footer();
 */

function mat_header(string $title, string $active = ''): void
{
    $u = mat_user();
    $canEdit = mat_can_edit();
    $isAdmin = mat_is_admin();

    $nav = [
        ['index',        '▦', 'Dashboard',        MAT_URL . '/index.php',              true],
        ['items',        '☰', 'Inventory',        MAT_URL . '/items.php',              true],
        ['receive',      '↓', 'Receive Stock',    MAT_URL . '/move.php?type=in',       $canEdit],
        ['issue',        '↑', 'Issue Stock',      MAT_URL . '/move.php?type=out',      $canEdit],
        ['transactions', '⟲', 'Movement History', MAT_URL . '/transactions.php',       true],
    ];
    $adminNav = [
        ['users',      '👥', 'Users & Access', MAT_URL . '/admin/users.php'],
        ['categories', '🗂', 'Categories',     MAT_URL . '/admin/categories.php'],
        ['import',     '⇪', 'Import Excel',   MAT_URL . '/admin/import.php'],
        ['audit',      '🛈', 'Activity Log',   MAT_URL . '/admin/audit.php'],
    ];
    $roleLabel = MAT_ROLES[$u['role'] ?? ''][0] ?? '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — SGIR Materials</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= MAT_URL ?>/assets/materials.css?v=1">
</head>
<body>
<?php if ($u): ?>
<div class="shell">
  <aside class="side" id="side">
    <a class="brand" href="<?= MAT_URL ?>/index.php">
      <span class="brand-mark">S</span>
      <span class="brand-text"><strong><?= e(APP_NAME) ?></strong><small>Materials &amp; Warehouse</small></span>
    </a>
    <nav class="side-nav" aria-label="Main">
      <?php foreach ($nav as [$key, $icon, $label, $url, $show]): if (!$show) continue; ?>
        <a href="<?= e($url) ?>" class="<?= $active === $key ? 'active' : '' ?>"><span class="ni"><?= $icon ?></span><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if ($isAdmin): ?>
        <div class="side-label">Administration</div>
        <?php foreach ($adminNav as [$key, $icon, $label, $url]): ?>
          <a href="<?= e($url) ?>" class="<?= $active === $key ? 'active' : '' ?>"><span class="ni"><?= $icon ?></span><?= e($label) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <div class="side-user">
      <div class="avatar"><?= e(mb_strtoupper(mb_substr($u['full_name'] ?: $u['username'], 0, 1))) ?></div>
      <div class="who">
        <strong><?= e($u['full_name'] ?: $u['username']) ?></strong>
        <small><?= e($u['job_title'] ?: $roleLabel) ?></small>
      </div>
    </div>
    <div class="side-links">
      <a href="<?= MAT_URL ?>/account.php" class="<?= $active === 'account' ? 'active' : '' ?>">My account</a>
      <a href="<?= MAT_URL ?>/logout.php">Sign out</a>
    </div>
  </aside>
  <div class="scrim" id="scrim"></div>
  <main class="main">
    <header class="topbar">
      <button class="menu-btn" id="menuBtn" aria-label="Open menu">☰</button>
      <h1><?= e($title) ?></h1>
      <span class="role-pill role-<?= e($u['role']) ?>"><?= e($roleLabel) ?></span>
    </header>
    <div class="content">
      <?php foreach (mat_take_flashes() as [$type, $msg]): ?>
        <div class="alert alert-<?= e($type) ?>" role="status"><?= e($msg) ?></div>
      <?php endforeach; ?>
<?php else: ?>
<div class="auth-wrap">
<?php endif;
}

function mat_footer(): void
{
    if (mat_user()): ?>
    </div>
  </main>
</div>
<?php else: ?>
</div>
<?php endif; ?>
<script src="<?= MAT_URL ?>/assets/materials.js?v=1"></script>
</body>
</html>
<?php
}

/** Render pagination links preserving the current query string */
function mat_pager(int $total, int $perPage, int $page): void
{
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return;
    $q = $_GET;
    $link = function (int $p) use ($q) { $q['page'] = $p; return '?' . http_build_query($q); };
    echo '<nav class="pager" aria-label="Pages">';
    if ($page > 1) echo '<a href="' . e($link($page - 1)) . '">‹ Prev</a>';
    $start = max(1, $page - 2); $end = min($pages, $page + 2);
    if ($start > 1) echo '<a href="' . e($link(1)) . '">1</a>' . ($start > 2 ? '<span>…</span>' : '');
    for ($i = $start; $i <= $end; $i++) {
        echo $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . e($link($i)) . '">' . $i . '</a>';
    }
    if ($end < $pages) echo ($end < $pages - 1 ? '<span>…</span>' : '') . '<a href="' . e($link($pages)) . '">' . $pages . '</a>';
    if ($page < $pages) echo '<a href="' . e($link($page + 1)) . '">Next ›</a>';
    echo '</nav>';
}

function mat_status_badge(array $item): string
{
    [$cls, $label] = mat_status($item);
    return '<span class="badge badge-' . $cls . '">' . e($label) . '</span>';
}

function mat_type_badge(string $type): string
{
    $map = ['in' => ['in', 'Received'], 'out' => ['out', 'Issued'], 'adjust' => ['adj', 'Adjusted']];
    [$c, $l] = $map[$type] ?? ['adj', $type];
    return '<span class="badge badge-t' . $c . '">' . $l . '</span>';
}
