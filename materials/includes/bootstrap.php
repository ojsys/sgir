<?php
/**
 * SGIR Materials (Warehouse Inventory) — bootstrap
 *
 * This module is intentionally independent from the Feedback dashboard:
 *  - its own session cookie (sgir_materials), so logins never mix
 *  - its own user table (mat_users) and roles
 *  - its own tables, all prefixed mat_
 * It only shares the .env file and the database connection settings.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once ROOT . '/config/database.php';

define('MAT_ROOT', dirname(__DIR__));
define('MAT_URL', BASE_URL . '/materials');

/** Roles: key => [label, description] */
const MAT_ROLES = [
    'admin'      => ['Administrator',     'Full access, including users, categories and imports'],
    'storekeeper'=> ['Warehouse Manager', 'Receive and issue stock, create and edit items'],
    'viewer'     => ['View Only',         'Can view stock levels, item history and reports'],
];

/** How stock came IN */
const MAT_IN_REASONS = [
    'purchase'        => 'New delivery / Purchase order',
    'transfer_in'     => 'Transfer from another rig / base',
    'returned'        => 'Returned by crew / department',
    'found'           => 'Found during stock check',
    'other_in'        => 'Other',
];

/** How stock went OUT (how it was collected) */
const MAT_OUT_REASONS = [
    'issued'          => 'Issued to crew / department',
    'transfer_out'    => 'Transfer to another rig / base',
    'returned_vendor' => 'Returned to supplier',
    'damaged'         => 'Damaged / Scrapped',
    'lost'            => 'Lost / Missing',
    'other_out'       => 'Other',
];

const MAT_DEPARTMENTS = [
    'Drilling', 'Mechanical', 'Electrical', 'Deck / Roustabouts', 'Derrick',
    'Motorman', 'Welding', 'HSE', 'Medical', 'Catering', 'Camp Boss',
    'Rig Manager Office', 'Toolpusher Office', 'Materials / Store', 'Client Rep',
];

// ── Session (separate cookie from the feedback dashboard) ───────────────────
function mat_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('sgir_materials');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function mat_user(): ?array
{
    mat_session();
    return $_SESSION['mat_user'] ?? null;
}

function mat_role(): string
{
    return mat_user()['role'] ?? '';
}

function mat_can_edit(): bool
{
    return in_array(mat_role(), ['admin', 'storekeeper'], true);
}

function mat_is_admin(): bool
{
    return mat_role() === 'admin';
}

function mat_require_login(): array
{
    global $pdo;
    $u = mat_user();
    if (!$u) {
        mat_redirect(MAT_URL . '/login.php');
    }
    // Re-check the account every request so deactivation / role change applies immediately
    $st = $pdo->prepare('SELECT id, username, full_name, job_title, role, is_active FROM mat_users WHERE id = :id');
    $st->execute([':id' => $u['id']]);
    $row = $st->fetch();
    if (!$row || !(int)$row['is_active']) {
        mat_logout();
        mat_redirect(MAT_URL . '/login.php?disabled=1');
    }
    $_SESSION['mat_user'] = $row;
    return $row;
}

function mat_require_editor(): array
{
    $u = mat_require_login();
    if (!mat_can_edit()) {
        mat_flash('error', 'Your account is view-only. Ask an administrator for Warehouse Manager access.');
        mat_redirect(MAT_URL . '/index.php');
    }
    return $u;
}

function mat_require_admin(): array
{
    $u = mat_require_login();
    if (!mat_is_admin()) {
        mat_flash('error', 'Only administrators can open that page.');
        mat_redirect(MAT_URL . '/index.php');
    }
    return $u;
}

function mat_login(array $user): void
{
    mat_session();
    session_regenerate_id(true);
    $_SESSION['mat_user'] = [
        'id' => (int)$user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'],
        'job_title' => $user['job_title'], 'role' => $user['role'], 'is_active' => 1,
    ];
}

function mat_logout(): void
{
    mat_session();
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    session_destroy();
}

// ── CSRF ────────────────────────────────────────────────────────────────────
function mat_csrf(): string
{
    mat_session();
    if (empty($_SESSION['mat_csrf'])) {
        $_SESSION['mat_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mat_csrf'];
}

function mat_csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . mat_csrf() . '">';
}

function mat_check_csrf(): bool
{
    mat_session();
    $t = $_POST['_token'] ?? '';
    return $t !== '' && !empty($_SESSION['mat_csrf']) && hash_equals($_SESSION['mat_csrf'], $t);
}

// ── Flash messages ──────────────────────────────────────────────────────────
function mat_flash(string $type, string $msg): void
{
    mat_session();
    $_SESSION['mat_flash'][] = [$type, $msg];
}

function mat_take_flashes(): array
{
    mat_session();
    $f = $_SESSION['mat_flash'] ?? [];
    unset($_SESSION['mat_flash']);
    return $f;
}

// ── Small helpers ───────────────────────────────────────────────────────────
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function mat_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function mat_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Format a quantity: 12 → "12", 2.5 → "2.5" */
function qty($n): string
{
    $n = (float)$n;
    if (floor($n) == $n) {
        return number_format($n, 0);
    }
    return rtrim(rtrim(number_format($n, 2), '0'), '.');
}

function mat_date(?string $dt, bool $time = true): string
{
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date($time ? 'd M Y, H:i' : 'd M Y', $ts) : '—';
}

function mat_reason_label(string $key): string
{
    return MAT_IN_REASONS[$key] ?? MAT_OUT_REASONS[$key] ?? ($key === 'count' ? 'Stock count correction' : ($key === 'opening' ? 'Opening balance' : ucfirst(str_replace('_', ' ', $key))));
}

/** Stock status for an item row */
function mat_status(array $item): array
{
    $q = (float)$item['quantity'];
    $min = (float)($item['min_qty'] ?? 0);
    if ($q <= 0)              return ['out', 'Out of stock'];
    if ($min > 0 && $q <= $min) return ['low', 'Low stock'];
    return ['ok', 'In stock'];
}

function mat_is_sqlite(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/** Build next item code for a category prefix, e.g. SC → SC00000527 */
function mat_next_code(PDO $pdo, string $prefix): string
{
    $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $prefix)) ?: 'SX';
    $st = $pdo->prepare('SELECT code FROM mat_items WHERE code LIKE :p');
    $st->execute([':p' => $prefix . '%']);
    $max = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $c, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 8, '0', STR_PAD_LEFT);
}

/**
 * Record a stock movement and update the item balance.
 *
 * @param string $direction 'in' | 'out' | 'count'
 * @param bool   $ownTxn    false when the caller already opened a transaction (multi-line slips)
 * @return array [bool ok, string message]
 */
function mat_move_stock(PDO $pdo, int $itemId, string $direction, float $amount, array $meta, array $user, bool $ownTxn = true): array
{
    if ($amount < 0 || ($direction !== 'count' && $amount <= 0)) {
        return [false, 'Enter a quantity greater than zero.'];
    }
    $fail = function (string $msg) use ($pdo, $ownTxn) {
        if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
        return [false, $msg];
    };
    if ($ownTxn) $pdo->beginTransaction();
    try {
        $lock = mat_is_sqlite($pdo) ? '' : ' FOR UPDATE';
        $st = $pdo->prepare('SELECT id, code, description, unit, quantity, is_active FROM mat_items WHERE id = :id' . $lock);
        $st->execute([':id' => $itemId]);
        $item = $st->fetch();
        if (!$item) {
            return $fail('Item not found.');
        }
        $before = (float)$item['quantity'];

        if ($direction === 'in') {
            $change = $amount;
        } elseif ($direction === 'out') {
            if ($amount > $before + 1e-9) {
                return $fail(sprintf('Only %s %s of %s in stock — cannot issue %s.', qty($before), $item['unit'], $item['code'], qty($amount)));
            }
            $change = -$amount;
        } else { // count: set the balance to what was physically counted
            $change = $amount - $before;
            if (abs($change) < 1e-9) {
                return $fail(sprintf('%s: counted quantity matches the system — nothing to correct.', $item['code']));
            }
        }

        // Guarded update (never lets stock go negative)
        $upd = $pdo->prepare('UPDATE mat_items SET quantity = quantity + :c, updated_at = :ts WHERE id = :id AND quantity + :c2 >= 0');
        $upd->execute([':c' => $change, ':c2' => $change, ':ts' => mat_now(), ':id' => $itemId]);
        if ($upd->rowCount() !== 1) {
            return $fail('Stock changed while you were working. Please try again.');
        }

        $ins = $pdo->prepare(
            'INSERT INTO mat_transactions
             (batch, item_id, txn_type, reason, quantity, balance_after, party, department, reference, authorized_by, notes, txn_date, user_id, created_at)
             VALUES (:batch, :item, :type, :reason, :qty, :bal, :party, :dept, :ref, :auth, :notes, :tdate, :uid, :ts)'
        );
        $ins->execute([
            ':batch'  => $meta['batch'] ?? '',
            ':item'   => $itemId,
            ':type'   => $direction === 'count' ? 'adjust' : $direction,
            ':reason' => $meta['reason'] ?? ($direction === 'count' ? 'count' : 'other_' . $direction),
            ':qty'    => $change,
            ':bal'    => $before + $change,
            ':party'  => mb_substr(trim($meta['party'] ?? ''), 0, 150),
            ':dept'   => mb_substr(trim($meta['department'] ?? ''), 0, 100),
            ':ref'    => mb_substr(trim($meta['reference'] ?? ''), 0, 100),
            ':auth'   => mb_substr(trim($meta['authorized_by'] ?? ''), 0, 150),
            ':notes'  => mb_substr(trim($meta['notes'] ?? ''), 0, 1000),
            ':tdate'  => $meta['txn_date'] ?? date('Y-m-d'),
            ':uid'    => $user['id'],
            ':ts'     => mat_now(),
        ]);
        if ($ownTxn) $pdo->commit();
    } catch (Throwable $ex) {
        error_log('Materials stock move failed: ' . $ex->getMessage());
        return $fail('Could not save the movement. Please try again.');
    }

    $verb = $direction === 'in' ? 'Received' : ($direction === 'out' ? 'Issued' : 'Corrected');
    $shown = $direction === 'count' ? 'to ' . qty($amount) : qty($amount);
    return [true, sprintf('%s %s %s — %s. New balance: %s.', $verb, $shown, $item['unit'], $item['code'], qty($before + $change))];
}

/** Short unique reference for one receipt / issue slip, e.g. IS-261119-4F2A */
function mat_new_batch(string $direction): string
{
    $p = ['in' => 'RV', 'out' => 'IS', 'count' => 'SC'][$direction] ?? 'MV';
    return $p . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

/** Write a simple audit line for admin actions (users, items, imports) */
function mat_audit(PDO $pdo, ?int $userId, string $action, string $details = ''): void
{
    try {
        $pdo->prepare('INSERT INTO mat_audit_log (user_id, action, details, created_at) VALUES (:u, :a, :d, :t)')
            ->execute([':u' => $userId, ':a' => $action, ':d' => mb_substr($details, 0, 500), ':t' => mat_now()]);
    } catch (Throwable $ex) {
        error_log('Materials audit failed: ' . $ex->getMessage());
    }
}

/** Distinct values used before, for autocomplete lists */
function mat_suggestions(PDO $pdo, string $column, int $limit = 60): array
{
    if (!in_array($column, ['party', 'department', 'authorized_by'], true)) return [];
    $st = $pdo->query("SELECT $column AS v, MAX(id) AS last_id FROM mat_transactions WHERE $column <> '' GROUP BY $column ORDER BY last_id DESC LIMIT " . (int)$limit);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function mat_categories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM mat_categories ORDER BY sort_order, name')->fetchAll();
}

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/queries.php';
mat_ensure_schema($pdo);
