<?php
/**
 * CSV export (opens in Excel). ?what=items|transactions + the same filters as the list pages.
 */
require_once __DIR__ . '/includes/bootstrap.php';

mat_require_login();

$what = ($_GET['what'] ?? '') === 'transactions' ? 'transactions' : 'items';
$filename = 'SGIR_' . ($what === 'items' ? 'Inventory' : 'Stock_Movements') . '_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows symbols correctly

/** Neutralise spreadsheet formula injection */
$cell = function ($v) {
    $v = (string)$v;
    return ($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) ? "'" . $v : $v;
};

if ($what === 'items') {
    [$where, $params] = mat_item_filters($_GET);
    $st = $pdo->prepare("SELECT i.*, c.name AS category_name FROM mat_items i LEFT JOIN mat_categories c ON c.id = i.category_id $where ORDER BY i.code");
    $st->execute($params);
    fputcsv($out, ['Item Code', 'Description', 'Unit', 'Category', 'Quantity', 'Re-order Level', 'Status', 'Bin Location', 'Notes', 'Last Updated']);
    while ($r = $st->fetch()) {
        fputcsv($out, array_map($cell, [
            $r['code'], $r['description'], $r['unit'], $r['category_name'], qty($r['quantity']), qty($r['min_qty']),
            mat_status($r)[1], $r['bin_location'], $r['notes'], $r['updated_at'],
        ]));
    }
} else {
    [$where, $params] = mat_txn_filters($_GET);
    $st = $pdo->prepare(
        "SELECT t.*, i.code, i.description, i.unit, u.full_name AS user_name
         FROM mat_transactions t JOIN mat_items i ON i.id = t.item_id LEFT JOIN mat_users u ON u.id = t.user_id
         $where ORDER BY t.txn_date DESC, t.id DESC"
    );
    $st->execute($params);
    fputcsv($out, ['Date', 'Slip No.', 'Item Code', 'Description', 'Movement', 'How', 'Quantity', 'Unit', 'Balance After',
                   'Collected By / Received From', 'Department', 'Reference', 'Authorized By', 'Notes', 'Recorded By', 'Recorded At']);
    $types = ['in' => 'Received', 'out' => 'Issued', 'adjust' => 'Correction'];
    while ($r = $st->fetch()) {
        fputcsv($out, array_map($cell, [
            $r['txn_date'], $r['batch'], $r['code'], $r['description'], $types[$r['txn_type']] ?? $r['txn_type'],
            mat_reason_label($r['reason']), qty($r['quantity']), $r['unit'], qty($r['balance_after']),
            $r['party'], $r['department'], $r['reference'], $r['authorized_by'], $r['notes'], $r['user_name'], $r['created_at'],
        ]));
    }
}
fclose($out);
