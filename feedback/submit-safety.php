<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ── CSRF ───────────────────────────────────────────────────────────────────
if (!verify_csrf($_POST['_token'] ?? '')) {
    json_response(['success' => false, 'message' => 'Invalid security token.'], 403);
}

// ── Collect ────────────────────────────────────────────────────────────────
$dept_slug          = trim($_POST['dept_slug']          ?? '');
$task_activity      = trim($_POST['task_activity']       ?? '');
$work_area          = trim($_POST['work_area']           ?? '');
$safety_observation = trim($_POST['safety_observation']  ?? '');
$obs_status         = trim($_POST['observation_status']  ?? 'open');
$stop_work          = !empty($_POST['stop_work_authority']) ? 1 : 0;
$is_safe            = !empty($_POST['is_safe'])            ? 1 : 0;
$unsafe_act         = !empty($_POST['unsafe_act'])         ? 1 : 0;
$unsafe_condition   = !empty($_POST['unsafe_condition'])   ? 1 : 0;
$near_miss          = !empty($_POST['near_miss'])          ? 1 : 0;
$corrective_action  = trim($_POST['corrective_action']   ?? '');
$further_actions    = trim($_POST['further_actions']     ?? '');
$observer_name      = trim($_POST['observer_name']       ?? '');
$observer_company   = trim($_POST['observer_company']    ?? '');
$obs_date           = trim($_POST['observation_date']    ?? '');

// ── Validate ───────────────────────────────────────────────────────────────
$errors = [];

if ($task_activity === '') {
    $errors['task_activity'] = 'Task / activity is required.';
}
if ($work_area === '') {
    $errors['work_area'] = 'Work area is required.';
}
if (mb_strlen($safety_observation) < 10) {
    $errors['safety_observation'] = 'Safety observation must be at least 10 characters.';
}

// At least one observation type must be selected
$obs_types_selected = $stop_work || $is_safe || $unsafe_act || $unsafe_condition || $near_miss;
if (!$obs_types_selected) {
    $errors['obs_type'] = 'Please select at least one observation type.';
}

if ($observer_name === '') {
    $errors['observer_name'] = 'Observer name is required.';
}
if ($obs_date === '') {
    $errors['observation_date'] = 'Observation date is required.';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $obs_date)) {
    $errors['observation_date'] = 'Invalid date format.';
}

if (!in_array($obs_status, ['open', 'close'], true)) {
    $obs_status = 'open';
}

if (!empty($errors)) {
    json_response(['success' => false, 'errors' => $errors], 422);
}

// ── Look up department ─────────────────────────────────────────────────────
$dept_id = null;
if ($dept_slug !== '') {
    $stmt = $pdo->prepare('SELECT id FROM departments WHERE slug = :slug LIMIT 1');
    $stmt->execute([':slug' => $dept_slug]);
    $dept_id = $stmt->fetchColumn() ?: null;
}

// ── Insert ─────────────────────────────────────────────────────────────────
$insert = $pdo->prepare(
    'INSERT INTO safety_observations
        (department_id, task_activity, work_area, safety_observation,
         observation_status, stop_work_authority, is_safe, unsafe_act,
         unsafe_condition, near_miss, corrective_action, further_actions,
         observer_name, observer_company, observation_date, status, created_at)
     VALUES
        (:dept_id, :task, :area, :obs,
         :obs_status, :swa, :safe, :ua,
         :uc, :nm, :ca, :fa,
         :oname, :ocompany, :odate, :status, :created_at)'
);
$insert->execute([
    ':dept_id'    => $dept_id,
    ':task'       => $task_activity,
    ':area'       => $work_area,
    ':obs'        => $safety_observation,
    ':obs_status' => $obs_status,
    ':swa'        => $stop_work,
    ':safe'       => $is_safe,
    ':ua'         => $unsafe_act,
    ':uc'         => $unsafe_condition,
    ':nm'         => $near_miss,
    ':ca'         => $corrective_action ?: null,
    ':fa'         => $further_actions   ?: null,
    ':oname'      => $observer_name,
    ':ocompany'   => $observer_company  ?: null,
    ':odate'      => $obs_date,
    ':status'     => 'new',
    ':created_at' => date('Y-m-d H:i:s'),
]);

$new_id = (int)$pdo->lastInsertId();

// ── Save custom question answers ───────────────────────────────────────────
if (!empty($_POST['custom_q']) && is_array($_POST['custom_q'])) {
    try {
        $ans_stmt = $pdo->prepare(
            'INSERT INTO safety_observation_answers (observation_id, question_id, answer, created_at)
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
    } catch (PDOException $e) {
        error_log('Safety observation answers save failed: ' . $e->getMessage());
    }
}

json_response([
    'success'  => true,
    'redirect' => BASE_URL . '/feedback/success.php',
]);
