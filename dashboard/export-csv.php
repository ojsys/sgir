<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

// ── Build WHERE clause from GET params ────────────────────────────────────
$where  = [];
$params = [];

if (!empty($_GET['department_id'])) {
    $where[]  = 'f.department_id = :dept_id';
    $params[':dept_id'] = (int)$_GET['department_id'];
}
if (!empty($_GET['category']) && in_array($_GET['category'], ['compliment', 'suggestion', 'complaint'], true)) {
    $where[]  = 'f.category = :category';
    $params[':category'] = $_GET['category'];
}
if (!empty($_GET['date_from'])) {
    $where[]  = 'DATE(f.created_at) >= :date_from';
    $params[':date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[]  = 'DATE(f.created_at) <= :date_to';
    $params[':date_to'] = $_GET['date_to'];
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT f.id, f.created_at, d.name AS dept_name, f.other_department,
               f.category, f.rating, f.message,
               f.is_anonymous, f.submitter_name, f.email, f.phone,
               f.status, f.admin_notes, f.reviewed_at
        FROM feedback f
        LEFT JOIN departments d ON d.id = f.department_id
        {$whereSql}
        ORDER BY f.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Stream CSV ─────────────────────────────────────────────────────────────
$filename = 'sgir-feedback-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, [
    'ID', 'Date', 'Department', 'Category', 'Rating',
    'Message', 'Anonymous', 'Submitter Name', 'Email', 'Phone',
    'Status', 'Admin Notes', 'Reviewed At',
]);

foreach ($rows as $row) {
    $dept = $row['dept_name'] ?: ($row['other_department'] ?: 'General');
    fputcsv($out, [
        $row['id'],
        $row['created_at'],
        $dept,
        $row['category'],
        $row['rating'],
        $row['message'],
        $row['is_anonymous'] ? 'Yes' : 'No',
        $row['submitter_name'] ?? '',
        $row['email']          ?? '',
        $row['phone']          ?? '',
        $row['status'],
        $row['admin_notes']    ?? '',
        $row['reviewed_at']    ?? '',
    ]);
}

fclose($out);
exit;
