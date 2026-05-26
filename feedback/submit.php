<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';
require_once ROOT . '/includes/email.php';

start_session();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ── CSRF ───────────────────────────────────────────────────────────────────
$token = $_POST['_token'] ?? '';
if (!verify_csrf($token)) {
    json_response(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.'], 403);
}

// ── Collect & sanitize input ───────────────────────────────────────────────
$dept_slug   = trim($_POST['dept_slug']       ?? '');
$location_id = resolve_location_id($pdo, $_POST['location_id'] ?? null);
$rating      = (int)($_POST['rating']          ?? 0);
$category    = trim($_POST['category']         ?? '');
$message     = trim($_POST['message']          ?? '');
$is_anon     = !empty($_POST['is_anonymous']);
$name        = trim($_POST['submitter_name']   ?? '');
$email       = trim($_POST['email']            ?? '');
$phone       = trim($_POST['phone']            ?? '');

// ── Validate ───────────────────────────────────────────────────────────────
$errors = [];

if ($dept_slug === '') {
    $errors['dept_slug'] = 'Department is required.';
}

if ($rating < 1 || $rating > 5) {
    $errors['rating'] = 'Please select a rating between 1 and 5.';
}

if (!in_array($category, ['compliment', 'suggestion', 'complaint'], true)) {
    $errors['category'] = 'Please select a feedback category.';
}

if (mb_strlen($message) < 10) {
    $errors['message'] = 'Message must be at least 10 characters.';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}

if (!empty($errors)) {
    json_response(['success' => false, 'errors' => $errors], 422);
}

// ── Look up department ─────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM departments WHERE slug = :slug AND is_active = 1 LIMIT 1');
$stmt->execute([':slug' => $dept_slug]);
$dept = $stmt->fetch();

if (!$dept) {
    json_response(['success' => false, 'message' => 'Department not found.'], 404);
}

// ── Insert ─────────────────────────────────────────────────────────────────
$insert = $pdo->prepare(
    'INSERT INTO feedback
        (department_id, location_id, rating, category, message, is_anonymous, submitter_name, email, phone, status, created_at)
     VALUES
        (:dept_id, :location_id, :rating, :category, :message, :is_anon, :name, :email, :phone, :status, :created_at)'
);
$insert->execute([
    ':dept_id'    => $dept['id'],
    ':location_id'=> $location_id,
    ':rating'     => $rating,
    ':category'   => $category,
    ':message'    => $message,
    ':is_anon'    => $is_anon ? 1 : 0,
    ':name'       => $is_anon ? null : ($name ?: null),
    ':email'      => $is_anon ? null : ($email ?: null),
    ':phone'      => $is_anon ? null : ($phone ?: null),
    ':status'     => 'new',
    ':created_at' => date('Y-m-d H:i:s'),
]);

$new_id = (int)$pdo->lastInsertId();

// ── Save custom question answers ───────────────────────────────────────────
if (!empty($_POST['custom_q']) && is_array($_POST['custom_q'])) {
    $ans_stmt = $pdo->prepare(
        'INSERT INTO feedback_answers (feedback_id, question_id, answer, created_at)
         VALUES (:fid, :qid, :answer, :ts)'
    );
    foreach ($_POST['custom_q'] as $qid => $answer) {
        $qid    = (int)$qid;
        $answer = is_array($answer)
            ? implode(', ', array_map('trim', $answer))
            : trim((string)$answer);
        if ($qid > 0) {
            $ans_stmt->execute([
                ':fid'    => $new_id,
                ':qid'    => $qid,
                ':answer' => $answer !== '' ? $answer : null,
                ':ts'     => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

// ── Send email notification ────────────────────────────────────────────────
$feedback_data = [
    'id'             => $new_id,
    'dept_name'      => $dept['name'],
    'rating'         => $rating,
    'category'       => $category,
    'message'        => $message,
    'is_anonymous'   => $is_anon,
    'submitter_name' => $is_anon ? null : $name,
    'created_at'     => date('Y-m-d H:i:s'),
];
send_feedback_notification($pdo, $feedback_data);

json_response([
    'success'  => true,
    'redirect' => BASE_URL . '/feedback/success.php',
]);
