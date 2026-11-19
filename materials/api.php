<?php
/**
 * Item search for the Receive / Issue picker.  GET ?q=text → JSON list.
 */
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!mat_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

[$where, $params] = mat_item_filters(['q' => $q]);
$st = $pdo->prepare(
    "SELECT i.id, i.code, i.description, i.unit, i.quantity, i.bin_location
     FROM mat_items i $where
     ORDER BY CASE WHEN i.code = :exact THEN 0 ELSE 1 END, CASE WHEN i.quantity > 0 THEN 0 ELSE 1 END, i.description
     LIMIT 15"
);
$st->execute($params + [':exact' => strtoupper($q)]);
$rows = array_map(function ($r) {
    $r['id'] = (int)$r['id'];
    $r['quantity'] = (float)$r['quantity'];
    $r['qty_label'] = qty($r['quantity']);
    return $r;
}, $st->fetchAll());

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
