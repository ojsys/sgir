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

// ── Helper: clean nullable ENUM value ─────────────────────────────────────
$cleanEnum = function(?string $val, array $allowed): ?string {
    $v = trim($val ?? '');
    return in_array($v, $allowed, true) ? $v : null;
};

$cleanRating = function(?string $val): ?int {
    $v = (int)($val ?? 0);
    return ($v >= 1 && $v <= 5) ? $v : null;
};

// ── Collect ────────────────────────────────────────────────────────────────
$dept_slug        = trim($_POST['dept_slug']           ?? '');
$location_id      = resolve_location_id($pdo, $_POST['location_id'] ?? null);
$visit_date       = trim($_POST['visit_date']           ?? '');
$visit_reason     = $cleanEnum($_POST['visit_reason']   ?? '', ['injury','illness','routine','medication','emergency','mental_health','other']);
$visit_reason_oth = trim($_POST['visit_reason_other']   ?? '');
$work_area        = trim($_POST['work_area']            ?? '');
$is_work_related  = (int)(!empty($_POST['is_work_related']) && $_POST['is_work_related'] === '1');

$response_time    = $cleanEnum($_POST['response_time']  ?? '', ['immediate','quick','acceptable','slow','very_slow']);
$clinic_acc       = $cleanEnum($_POST['clinic_accessible'] ?? '', ['yes','no']);
$seen_reasonable  = $cleanEnum($_POST['seen_at_reasonable_time'] ?? '', ['yes','no']);
$staff_prof       = $cleanRating($_POST['staff_professionalism']  ?? '');
$treatment_exp    = $cleanEnum($_POST['treatment_explained'] ?? '', ['yes','partially','no']);
$felt_listened    = $cleanEnum($_POST['felt_listened_to']    ?? '', ['yes','partially','no']);
$privacy          = $cleanEnum($_POST['privacy_maintained']  ?? '', ['yes','no']);
$treatment_app    = $cleanEnum($_POST['treatment_appropriate'] ?? '', ['yes','unsure','no']);
$cleanliness      = $cleanRating($_POST['cleanliness_rating']    ?? '');
$meds_avail       = $cleanEnum($_POST['medications_available'] ?? '', ['yes','partially','no']);
$facility_adeq    = $cleanRating($_POST['facility_adequacy']      ?? '');
$followup_inst    = $cleanEnum($_POST['followup_instructions']   ?? '', ['yes','no','na']);
$referred         = $cleanEnum($_POST['referred_for_further_care'] ?? '', ['yes','no','not_needed']);
$fit_return       = $cleanEnum($_POST['fit_to_return']           ?? '', ['yes','no','still_on_sick_bay','na']);
$overall_rating   = $cleanRating($_POST['overall_rating']         ?? '');
$confident        = $cleanEnum($_POST['confident_future_use']    ?? '', ['yes','maybe','no']);
$urgent_review    = !empty($_POST['urgent_review']) && $_POST['urgent_review'] === '1' ? 1 : 0;
$comments         = trim($_POST['comments']                       ?? '');
$is_anon          = !empty($_POST['is_anonymous']);
$observer_name    = trim($_POST['observer_name']    ?? '');
$observer_company = trim($_POST['observer_company'] ?? '');
$employee_id      = trim($_POST['employee_id']      ?? '');

// ── Validate ───────────────────────────────────────────────────────────────
$errors = [];

if ($visit_date === '') {
    $errors['visit_date'] = 'Visit date is required.';
} elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visit_date)) {
    $errors['visit_date'] = 'Invalid date format.';
} elseif ($visit_date > date('Y-m-d')) {
    $errors['visit_date'] = 'Visit date cannot be in the future.';
}

if (!$visit_reason) {
    $errors['visit_reason'] = 'Please select a reason for your visit.';
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
    'INSERT INTO medical_feedback (
        department_id, location_id, visit_date, visit_reason, visit_reason_other,
        work_area, is_work_related, response_time, clinic_accessible,
        seen_at_reasonable_time, staff_professionalism, treatment_explained,
        felt_listened_to, privacy_maintained, treatment_appropriate,
        cleanliness_rating, medications_available, facility_adequacy,
        followup_instructions, referred_for_further_care, fit_to_return,
        overall_rating, confident_future_use, urgent_review, comments,
        is_anonymous, observer_name, observer_company, employee_id,
        status, created_at
     ) VALUES (
        :dept_id, :location_id, :vdate, :vreason, :vr_other,
        :warea, :is_work, :resp_time, :clinic_acc,
        :seen_reas, :staff_prof, :treat_exp,
        :felt_list, :privacy, :treat_app,
        :clean, :meds, :facility,
        :followup, :referred, :fit_ret,
        :overall, :confident, :urgent, :comments,
        :is_anon, :obs_name, :obs_comp, :emp_id,
        :status, :created_at
     )'
);

$insert->execute([
    ':dept_id'    => $dept_id,
    ':location_id'=> $location_id,
    ':vdate'      => $visit_date,
    ':vreason'    => $visit_reason,
    ':vr_other'   => $visit_reason === 'other' ? ($visit_reason_oth ?: null) : null,
    ':warea'      => $work_area      ?: null,
    ':is_work'    => $is_work_related,
    ':resp_time'  => $response_time,
    ':clinic_acc' => $clinic_acc,
    ':seen_reas'  => $seen_reasonable,
    ':staff_prof' => $staff_prof,
    ':treat_exp'  => $treatment_exp,
    ':felt_list'  => $felt_listened,
    ':privacy'    => $privacy,
    ':treat_app'  => $treatment_app,
    ':clean'      => $cleanliness,
    ':meds'       => $meds_avail,
    ':facility'   => $facility_adeq,
    ':followup'   => $followup_inst,
    ':referred'   => $referred,
    ':fit_ret'    => $fit_return,
    ':overall'    => $overall_rating,
    ':confident'  => $confident,
    ':urgent'     => $urgent_review,
    ':comments'   => $comments  ?: null,
    ':is_anon'    => $is_anon ? 1 : 0,
    ':obs_name'   => $is_anon ? null : ($observer_name    ?: null),
    ':obs_comp'   => $is_anon ? null : ($observer_company ?: null),
    ':emp_id'     => $is_anon ? null : ($employee_id      ?: null),
    ':status'     => 'new',
    ':created_at' => date('Y-m-d H:i:s'),
]);

$new_id = (int)$pdo->lastInsertId();

// ── Save custom question answers ───────────────────────────────────────────
if (!empty($_POST['custom_q']) && is_array($_POST['custom_q'])) {
    try {
        $ans_stmt = $pdo->prepare(
            'INSERT INTO medical_feedback_answers (medical_id, question_id, answer, created_at)
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
        error_log('Medical feedback answers save failed: ' . $e->getMessage());
    }
}

json_response([
    'success'  => true,
    'redirect' => BASE_URL . '/feedback/success.php',
]);
