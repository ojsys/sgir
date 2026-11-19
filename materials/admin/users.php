<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

$me = mat_require_admin();

const MAT_JOB_TITLES = [
    'Materials Man / Warehouse Manager', 'Assistant Materials Man', 'Toolpusher', 'Senior Toolpusher',
    'Night Toolpusher', 'Rig Manager', 'Driller', 'Chief Mechanic', 'Chief Electrician', 'HSE Officer',
    'Camp Boss', 'Medic', 'Company Man / Client Rep', 'Logistics Coordinator', 'Accounts / Procurement',
];

$errors = [];
$editId = (int)($_GET['edit'] ?? 0);

$activeAdmins = fn() => (int)$pdo->query("SELECT COUNT(*) FROM mat_users WHERE role = 'admin' AND is_active = 1")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);
    $target = null;
    if ($uid) {
        $st = $pdo->prepare('SELECT * FROM mat_users WHERE id = :id');
        $st->execute([':id' => $uid]);
        $target = $st->fetch() ?: null;
    }

    if (!mat_check_csrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'add' || $action === 'edit') {
        $d = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'username'  => trim($_POST['username'] ?? ''),
            'job_title' => trim($_POST['job_title'] ?? ''),
            'email'     => trim($_POST['email'] ?? ''),
            'role'      => $_POST['role'] ?? 'viewer',
        ];
        $pw = $_POST['password'] ?? '';

        if ($action === 'edit' && !$target) $errors[] = 'User not found.';
        if ($d['full_name'] === '') $errors[] = 'Full name is required.';
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $d['username'])) $errors[] = 'Username must be 3–60 characters: letters, numbers, dot, dash or underscore.';
        if (!array_key_exists($d['role'], MAT_ROLES)) $errors[] = 'Choose an access level.';
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address looks incorrect.';
        if ($action === 'add' && strlen($pw) < 8) $errors[] = 'Temporary password must be at least 8 characters.';
        if ($action === 'edit' && $target && $target['role'] === 'admin' && $d['role'] !== 'admin') {
            if ($uid === (int)$me['id']) $errors[] = 'You cannot remove your own administrator access.';
            elseif ($activeAdmins() <= 1) $errors[] = 'At least one active administrator is required.';
        }
        if (!$errors) {
            $chk = $pdo->prepare('SELECT id FROM mat_users WHERE username = :u AND id <> :id');
            $chk->execute([':u' => $d['username'], ':id' => $uid]);
            if ($chk->fetch()) $errors[] = 'That username is already taken.';
        }
        if (!$errors) {
            if ($action === 'add') {
                $pdo->prepare('INSERT INTO mat_users (username, full_name, job_title, email, password_hash, role, is_active, created_at)
                               VALUES (:u, :n, :j, :e, :h, :r, 1, :t)')
                    ->execute([':u' => $d['username'], ':n' => $d['full_name'], ':j' => $d['job_title'], ':e' => $d['email'],
                               ':h' => password_hash($pw, PASSWORD_DEFAULT), ':r' => $d['role'], ':t' => mat_now()]);
                mat_audit($pdo, (int)$me['id'], 'user_add', "{$d['username']} ({$d['role']})");
                mat_flash('success', "Account created for {$d['full_name']}. Share the username “{$d['username']}” and temporary password with them — they can change it under My account.");
            } else {
                $pdo->prepare('UPDATE mat_users SET username = :u, full_name = :n, job_title = :j, email = :e, role = :r WHERE id = :id')
                    ->execute([':u' => $d['username'], ':n' => $d['full_name'], ':j' => $d['job_title'], ':e' => $d['email'], ':r' => $d['role'], ':id' => $uid]);
                mat_audit($pdo, (int)$me['id'], 'user_edit', "{$d['username']} ({$d['role']})");
                mat_flash('success', "Saved changes for {$d['full_name']}.");
            }
            mat_redirect(MAT_URL . '/admin/users.php');
        }
        if ($action === 'edit') $editId = $uid;
    } elseif (!$target) {
        $errors[] = 'User not found.';
    } elseif ($action === 'reset') {
        $pw = $_POST['new_password'] ?? '';
        if (strlen($pw) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
            $editId = $uid;
        } else {
            $pdo->prepare('UPDATE mat_users SET password_hash = :h WHERE id = :id')
                ->execute([':h' => password_hash($pw, PASSWORD_DEFAULT), ':id' => $uid]);
            mat_audit($pdo, (int)$me['id'], 'user_reset_pw', $target['username']);
            mat_flash('success', "Password reset for {$target['full_name']}.");
            mat_redirect(MAT_URL . '/admin/users.php');
        }
    } elseif ($action === 'toggle') {
        if ($uid === (int)$me['id']) $errors[] = 'You cannot disable your own account.';
        elseif ($target['role'] === 'admin' && (int)$target['is_active'] && $activeAdmins() <= 1) $errors[] = 'At least one active administrator is required.';
        else {
            $pdo->prepare('UPDATE mat_users SET is_active = :a WHERE id = :id')->execute([':a' => (int)$target['is_active'] ? 0 : 1, ':id' => $uid]);
            mat_audit($pdo, (int)$me['id'], (int)$target['is_active'] ? 'user_disable' : 'user_enable', $target['username']);
            mat_flash('success', (int)$target['is_active'] ? "{$target['full_name']} can no longer sign in." : "{$target['full_name']} can sign in again.");
            mat_redirect(MAT_URL . '/admin/users.php');
        }
    } elseif ($action === 'delete') {
        $used = $pdo->prepare('SELECT COUNT(*) FROM mat_transactions WHERE user_id = :id');
        $used->execute([':id' => $uid]);
        if ($uid === (int)$me['id']) $errors[] = 'You cannot delete your own account.';
        elseif ((int)$used->fetchColumn() > 0) $errors[] = "{$target['full_name']} has recorded stock movements, so the account is kept for the audit trail. Disable it instead.";
        elseif ($target['role'] === 'admin' && $activeAdmins() <= 1) $errors[] = 'At least one active administrator is required.';
        else {
            $pdo->prepare('DELETE FROM mat_users WHERE id = :id')->execute([':id' => $uid]);
            mat_audit($pdo, (int)$me['id'], 'user_delete', $target['username']);
            mat_flash('success', "Deleted the account for {$target['full_name']}.");
            mat_redirect(MAT_URL . '/admin/users.php');
        }
    }
}

$users = $pdo->query(
    "SELECT u.*, (SELECT COUNT(*) FROM mat_transactions t WHERE t.user_id = u.id) AS moves
     FROM mat_users u
     ORDER BY u.is_active DESC, CASE u.role WHEN 'admin' THEN 0 WHEN 'storekeeper' THEN 1 ELSE 2 END, u.full_name"
)->fetchAll();

$editing = null;
foreach ($users as $u) if ((int)$u['id'] === $editId) $editing = $u;
if ($editing && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $editing = array_merge($editing, array_intersect_key($_POST, array_flip(['full_name', 'username', 'job_title', 'email', 'role'])));
}
$new = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') ? $_POST : ['full_name' => '', 'username' => '', 'job_title' => '', 'email' => '', 'role' => 'storekeeper'];

mat_header('Users & Access', 'users');

$roleField = function (string $current, string $name = 'role') {
    echo '<fieldset class="role-pick"><legend>Access level</legend>';
    foreach (MAT_ROLES as $k => [$label, $desc]) {
        echo '<label class="reason"><input type="radio" name="' . $name . '" value="' . e($k) . '"' . ($current === $k ? ' checked' : '') . '>'
           . '<span><strong>' . e($label) . '</strong><small>' . e($desc) . '</small></span></label>';
    }
    echo '</fieldset>';
};
?>
<?php if ($errors): ?>
  <div class="alert alert-error"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="cols cols-wide-left">
  <section class="card table-card">
    <div class="card-head pad"><h3>People with access (<?= count($users) ?>)</h3></div>
    <table class="table">
      <thead><tr><th>Name</th><th>Access</th><th class="hide-sm">Last sign-in</th><th class="right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr class="<?= (int)$u['is_active'] ? '' : 'row-muted' ?>">
          <td data-label="Name">
            <strong><?= e($u['full_name']) ?></strong><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="muted small">(you)</span>' : '' ?>
            <div class="muted small"><span class="mono"><?= e($u['username']) ?></span><?= $u['job_title'] ? ' · ' . e($u['job_title']) : '' ?></div>
          </td>
          <td data-label="Access">
            <span class="role-pill role-<?= e($u['role']) ?>"><?= e(MAT_ROLES[$u['role']][0] ?? $u['role']) ?></span>
            <?php if (!(int)$u['is_active']): ?><span class="badge badge-out">Disabled</span><?php endif; ?>
          </td>
          <td class="hide-sm muted small" data-label="Last sign-in"><?= mat_date($u['last_login_at']) ?></td>
          <td class="right nowrap" data-label="">
            <a class="btn btn-ghost btn-sm" href="?edit=<?= (int)$u['id'] ?>#edit">Edit</a>
            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
            <form method="post" class="inline">
              <?= mat_csrf_field() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn-ghost btn-sm" name="action" value="toggle"><?= (int)$u['is_active'] ? 'Disable' : 'Enable' ?></button>
              <?php if (!(int)$u['moves']): ?>
                <button class="btn btn-ghost btn-sm txt-out" name="action" value="delete" data-confirm="Delete the account for <?= e($u['full_name']) ?>?">Delete</button>
              <?php endif; ?>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <div class="stack">
    <?php if ($editing): ?>
    <form method="post" class="card form" id="edit">
      <div class="card-head"><h3>Edit <?= e($editing['full_name']) ?></h3><a href="users.php" class="link">Close</a></div>
      <?= mat_csrf_field() ?>
      <input type="hidden" name="action" value="edit"><input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
      <label class="field"><span>Full name</span><input name="full_name" value="<?= e($editing['full_name']) ?>" required></label>
      <label class="field"><span>Job title</span><input name="job_title" value="<?= e($editing['job_title']) ?>" list="jobList"></label>
      <div class="grid-2">
        <label class="field"><span>Username</span><input name="username" value="<?= e($editing['username']) ?>" required autocapitalize="none"></label>
        <label class="field"><span>Email (optional)</span><input type="email" name="email" value="<?= e($editing['email']) ?>"></label>
      </div>
      <?php $roleField($editing['role']); ?>
      <div class="form-actions"><button class="btn btn-primary">Save changes</button></div>
    </form>
    <form method="post" class="card form">
      <h3>Reset password</h3>
      <?= mat_csrf_field() ?>
      <input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
      <label class="field"><span>New temporary password</span>
        <span class="with-btn"><input type="text" name="new_password" minlength="8" required autocomplete="off" data-pw><button type="button" class="btn btn-ghost btn-sm" data-gen-pw>Generate</button></span>
      </label>
      <div class="form-actions"><button class="btn btn-ghost">Reset password</button></div>
    </form>
    <?php endif; ?>

    <form method="post" class="card form" id="add">
      <h3>Add a person</h3>
      <p class="muted small">Warehouse managers can receive and issue stock. Toolpushers, rig managers and others who only need to see stock levels should get <strong>View Only</strong>.</p>
      <?= mat_csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <label class="field"><span>Full name</span><input name="full_name" value="<?= e($new['full_name'] ?? '') ?>" required></label>
      <label class="field"><span>Job title</span><input name="job_title" value="<?= e($new['job_title'] ?? '') ?>" list="jobList" placeholder="e.g. Toolpusher"></label>
      <div class="grid-2">
        <label class="field"><span>Username</span><input name="username" value="<?= e($new['username'] ?? '') ?>" required autocapitalize="none" placeholder="e.g. j.okafor"></label>
        <label class="field"><span>Email (optional)</span><input type="email" name="email" value="<?= e($new['email'] ?? '') ?>"></label>
      </div>
      <label class="field"><span>Temporary password</span>
        <span class="with-btn"><input type="text" name="password" minlength="8" required autocomplete="off" data-pw><button type="button" class="btn btn-ghost btn-sm" data-gen-pw>Generate</button></span>
      </label>
      <?php $roleField($new['role'] ?? 'storekeeper'); ?>
      <div class="form-actions"><button class="btn btn-primary">＋ Add person</button></div>
    </form>
  </div>
</div>
<datalist id="jobList"><?php foreach (MAT_JOB_TITLES as $j): ?><option value="<?= e($j) ?>"><?php endforeach; ?></datalist>
<?php mat_footer();
