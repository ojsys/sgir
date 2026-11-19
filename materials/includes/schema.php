<?php
/**
 * Creates the Materials tables on first use (MySQL or SQLite) and loads the
 * SGIR Item Master as the opening stock. Safe to run repeatedly.
 */

const MAT_SCHEMA_VERSION = 1;

const MAT_DEFAULT_CATEGORIES = [
    ['SA', 'Stationery & Office Supplies',      1],
    ['SB', 'Rigging, Lifting & Drilling Spares', 2],
    ['SC', 'Mechanical Tools & Spares',         3],
    ['SD', 'Electrical',                        4],
    ['SE', 'Safety, PPE & HSE Forms',           5],
];

function mat_ensure_schema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM mat_meta LIMIT 1');
        return; // already installed
    } catch (Throwable $e) {
        // fall through and install
    }

    $sqlite = mat_is_sqlite($pdo);
    $pk   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    $fk   = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $str  = fn(int $n) => $sqlite ? 'TEXT' : "VARCHAR($n)";
    $num  = $sqlite ? 'REAL' : 'DECIMAL(14,2)';
    $txt  = 'TEXT';
    $dt   = $sqlite ? 'TEXT' : 'DATETIME';
    $date = $sqlite ? 'TEXT' : 'DATE';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $stmts = [
        "CREATE TABLE IF NOT EXISTS mat_meta (
            k {$str(50)} NOT NULL PRIMARY KEY,
            v {$str(255)} NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS mat_users (
            id $pk,
            username {$str(60)} NOT NULL UNIQUE,
            full_name {$str(120)} NOT NULL,
            job_title {$str(80)} NOT NULL DEFAULT '',
            email {$str(150)} NOT NULL DEFAULT '',
            password_hash {$str(255)} NOT NULL,
            role {$str(20)} NOT NULL DEFAULT 'viewer',
            is_active INTEGER NOT NULL DEFAULT 1,
            last_login_at $dt NULL,
            created_at $dt NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS mat_categories (
            id $pk,
            prefix {$str(4)} NOT NULL UNIQUE,
            name {$str(100)} NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        )$tail",

        "CREATE TABLE IF NOT EXISTS mat_items (
            id $pk,
            code {$str(40)} NOT NULL UNIQUE,
            description {$str(255)} NOT NULL,
            unit {$str(30)} NOT NULL DEFAULT 'Pieces',
            category_id $fk NULL,
            quantity $num NOT NULL DEFAULT 0,
            min_qty $num NOT NULL DEFAULT 0,
            bin_location {$str(60)} NOT NULL DEFAULT '',
            notes $txt NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at $dt NOT NULL,
            updated_at $dt NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS mat_transactions (
            id $pk,
            batch {$str(20)} NOT NULL DEFAULT '',
            item_id $fk NOT NULL,
            txn_type {$str(10)} NOT NULL,
            reason {$str(30)} NOT NULL,
            quantity $num NOT NULL,
            balance_after $num NOT NULL,
            party {$str(150)} NOT NULL DEFAULT '',
            department {$str(100)} NOT NULL DEFAULT '',
            reference {$str(100)} NOT NULL DEFAULT '',
            authorized_by {$str(150)} NOT NULL DEFAULT '',
            notes $txt NULL,
            txn_date $date NOT NULL,
            user_id $fk NULL,
            created_at $dt NOT NULL
        )$tail",

        "CREATE TABLE IF NOT EXISTS mat_audit_log (
            id $pk,
            user_id $fk NULL,
            action {$str(60)} NOT NULL,
            details {$str(500)} NOT NULL DEFAULT '',
            created_at $dt NOT NULL
        )$tail",
    ];

    $indexes = [
        'CREATE INDEX idx_mat_items_cat ON mat_items (category_id)',
        'CREATE INDEX idx_mat_txn_item ON mat_transactions (item_id)',
        'CREATE INDEX idx_mat_txn_date ON mat_transactions (txn_date)',
        'CREATE INDEX idx_mat_txn_batch ON mat_transactions (batch)',
    ];

    foreach ($stmts as $sql) {
        $pdo->exec($sql);
    }
    foreach ($indexes as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* index exists */ }
    }

    // Categories
    $cat = $pdo->prepare('INSERT INTO mat_categories (prefix, name, sort_order) VALUES (:p, :n, :s)');
    foreach (MAT_DEFAULT_CATEGORIES as [$p, $n, $s]) {
        try { $cat->execute([':p' => $p, ':n' => $n, ':s' => $s]); } catch (Throwable $e) {}
    }

    // Opening stock from the SGIR Item Master spreadsheet
    $csv = MAT_ROOT . '/data/item_master.csv';
    if (is_readable($csv) && (int)$pdo->query('SELECT COUNT(*) FROM mat_items')->fetchColumn() === 0) {
        mat_import_rows($pdo, mat_read_csv($csv), null, 'opening');
    }

    $pdo->prepare('INSERT INTO mat_meta (k, v) VALUES (:k, :v)')
        ->execute([':k' => 'schema_version', ':v' => (string)MAT_SCHEMA_VERSION]);
}

/** Read a CSV file into rows of [code, description, unit, quantity] */
function mat_read_csv(string $path): array
{
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) return $rows;
    $header = null;
    while (($r = fgetcsv($fh)) !== false) {
        if ($header === null) { $header = $r; continue; }
        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
}

/**
 * Read the first sheet of an .xlsx file (no external library needed).
 * Returns rows (arrays of cell strings), header row excluded.
 */
function mat_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The server is missing the PHP zip extension. Save the sheet as CSV and upload that instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the Excel file.');
    }
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $s = '';
                foreach ($si->r as $run) $s .= (string)$run->t;
                $shared[] = $s;
            }
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) {
        throw new RuntimeException('Could not find a worksheet in the Excel file.');
    }
    $sx = simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET);
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = 0;
            foreach (str_split(preg_replace('/\d/', '', $ref)) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $t = (string)$c['t'];
            if ($t === 's')            $v = $shared[(int)$c->v] ?? '';
            elseif ($t === 'inlineStr') $v = (string)$c->is->t;
            else                        $v = (string)$c->v;
            $cells[$col - 1] = $v;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $cells[$i] ?? '';
            $rows[] = $line;
        }
    }
    array_shift($rows); // header
    return $rows;
}

/**
 * Import rows [code, description, unit, quantity].
 * New codes are created; existing codes get description/unit updated and,
 * if $mode === 'set_qty', their balance set to the sheet quantity (logged as a count).
 *
 * @return array{created:int, updated:int, skipped:int}
 */
function mat_import_rows(PDO $pdo, array $rows, ?array $user, string $mode = 'opening'): array
{
    $cats = [];
    foreach ($pdo->query('SELECT id, prefix FROM mat_categories') as $c) {
        $cats[strtoupper($c['prefix'])] = (int)$c['id'];
    }
    $find = $pdo->prepare('SELECT id, quantity FROM mat_items WHERE code = :c');
    $ins  = $pdo->prepare('INSERT INTO mat_items (code, description, unit, category_id, quantity, created_at, updated_at)
                           VALUES (:c, :d, :u, :cat, :q, :t, :t2)');
    $upd  = $pdo->prepare('UPDATE mat_items SET description = :d, unit = :u, updated_at = :t WHERE id = :id');
    $txn  = $pdo->prepare('INSERT INTO mat_transactions (item_id, txn_type, reason, quantity, balance_after, notes, txn_date, user_id, created_at)
                           VALUES (:i, :type, :reason, :q, :b, :n, :d, :u, :t)');
    $setq = $pdo->prepare('UPDATE mat_items SET quantity = :q, updated_at = :t WHERE id = :id');

    $out = ['created' => 0, 'updated' => 0, 'skipped' => 0];
    $now = mat_now();
    $today = date('Y-m-d');
    $uid = $user['id'] ?? null;

    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $code = strtoupper(trim((string)($r[0] ?? '')));
            $desc = trim(preg_replace('/\s+/u', ' ', (string)($r[1] ?? '')));
            $unit = trim((string)($r[2] ?? '')) ?: 'Pieces';
            $qRaw = trim((string)($r[3] ?? ''));
            $q    = is_numeric($qRaw) ? max(0, (float)$qRaw) : 0;
            if ($code === '' || $desc === '') { $out['skipped']++; continue; }

            $find->execute([':c' => $code]);
            $existing = $find->fetch();
            if ($existing) {
                $upd->execute([':d' => mb_substr($desc, 0, 255), ':u' => mb_substr($unit, 0, 30), ':t' => $now, ':id' => $existing['id']]);
                if ($mode === 'set_qty' && $qRaw !== '' && (float)$existing['quantity'] != $q) {
                    $setq->execute([':q' => $q, ':t' => $now, ':id' => $existing['id']]);
                    $txn->execute([':i' => $existing['id'], ':type' => 'adjust', ':reason' => 'count', ':q' => $q - (float)$existing['quantity'],
                                   ':b' => $q, ':n' => 'Quantity updated from spreadsheet import', ':d' => $today, ':u' => $uid, ':t' => $now]);
                }
                $out['updated']++;
                continue;
            }
            $prefix = substr($code, 0, 2);
            $ins->execute([':c' => $code, ':d' => mb_substr($desc, 0, 255), ':u' => mb_substr($unit, 0, 30),
                           ':cat' => $cats[$prefix] ?? null, ':q' => $q, ':t' => $now, ':t2' => $now]);
            $id = (int)$pdo->lastInsertId();
            if ($q > 0) {
                $txn->execute([':i' => $id, ':type' => 'in', ':reason' => 'opening', ':q' => $q, ':b' => $q,
                               ':n' => 'Opening balance from SGIR Item Master', ':d' => $today, ':u' => $uid, ':t' => $now]);
            }
            $out['created']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $out;
}
