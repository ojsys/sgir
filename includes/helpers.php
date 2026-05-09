<?php
/**
 * Utility / helper functions
 */

/**
 * Escape a string for safe HTML output.
 */
function h(?string $str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Send a JSON response and exit immediately.
 *
 * @param mixed $data
 * @param int   $code  HTTP status code
 */
function json_response(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Perform an HTTP redirect.
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Fetch the singleton admin_settings row (id = 1).
 *
 * @return array<string,mixed>
 */
function get_site_settings(PDO $pdo): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $stmt = $pdo->query('SELECT * FROM admin_settings WHERE id = 1 LIMIT 1');
    $row  = $stmt->fetch();
    $settings = $row ?: [
        'id'                  => 1,
        'notification_emails' => '',
        'company_name'        => APP_NAME,
        'company_tagline'     => APP_TAGLINE,
        'logo_path'           => null,
        'favicon_path'        => null,
    ];
    return $settings;
}

/**
 * Return a Unicode star-rating string, e.g. "★★★☆☆" for rating 3.
 */
function star_rating(int $rating): string
{
    $rating  = max(1, min(5, $rating));
    $filled  = str_repeat('★', $rating);
    $empty   = str_repeat('☆', 5 - $rating);
    return $filled . $empty;
}

/**
 * Format a datetime string (MySQL DATETIME) into a human-readable form.
 */
function format_date(?string $dt): string
{
    if (!$dt) {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($dt);
        return $d->format('d M Y, g:i A');
    } catch (Throwable) {
        return $dt;
    }
}

/**
 * Generate a URL-friendly slug from a string.
 */
function slug(string $str): string
{
    $str = mb_strtolower(trim($str));
    $str = preg_replace('/[^\w\s-]/u', '', $str);
    $str = preg_replace('/[\s_]+/', '-', $str);
    $str = preg_replace('/-+/', '-', $str);
    return trim($str, '-');
}

/**
 * Build a pagination metadata array.
 *
 * @return array{total: int, per_page: int, current_page: int, last_page: int,
 *               from: int, to: int, has_prev: bool, has_next: bool,
 *               pages: int[]}
 */
function paginate(int $total, int $per_page, int $current_page): array
{
    $per_page     = max(1, $per_page);
    $last_page    = (int)ceil($total / $per_page);
    $current_page = max(1, min($current_page, $last_page ?: 1));
    $from         = ($current_page - 1) * $per_page + 1;
    $to           = min($current_page * $per_page, $total);

    // Build page window (±2 pages around current)
    $start = max(1, $current_page - 2);
    $end   = min($last_page, $current_page + 2);
    $pages = range($start, $end);

    return [
        'total'        => $total,
        'per_page'     => $per_page,
        'current_page' => $current_page,
        'last_page'    => $last_page,
        'from'         => $total > 0 ? $from : 0,
        'to'           => $total > 0 ? $to   : 0,
        'has_prev'     => $current_page > 1,
        'has_next'     => $current_page < $last_page,
        'pages'        => $pages,
    ];
}

/**
 * Build a filtered PDO statement for the feedback table.
 *
 * Supported filters (all optional):
 *   department_id, category, status, rating, date_from, date_to, search, page, per_page
 *
 * @param  array<string,mixed> $filters
 * @return array{stmt: PDOStatement, total: int, pagination: array}
 */
function apply_feedback_filters(PDO $pdo, array $filters): array
{
    $where  = [];
    $params = [];

    if (!empty($filters['department_id'])) {
        $where[]  = 'f.department_id = :dept_id';
        $params[':dept_id'] = (int)$filters['department_id'];
    }
    if (!empty($filters['category'])) {
        $where[]  = 'f.category = :category';
        $params[':category'] = $filters['category'];
    }
    if (!empty($filters['status'])) {
        $where[]  = 'f.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['rating'])) {
        $where[]  = 'f.rating = :rating';
        $params[':rating'] = (int)$filters['rating'];
    }
    if (!empty($filters['date_from'])) {
        $where[]  = 'DATE(f.created_at) >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[]  = 'DATE(f.created_at) <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $where[]  = '(f.message LIKE :search OR f.submitter_name LIKE :search2 OR d.name LIKE :search3)';
        $term     = '%' . $filters['search'] . '%';
        $params[':search']  = $term;
        $params[':search2'] = $term;
        $params[':search3'] = $term;
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $countSql = "SELECT COUNT(*) FROM feedback f LEFT JOIN departments d ON d.id = f.department_id {$whereSql}";
    $cstmt    = $pdo->prepare($countSql);
    $cstmt->execute($params);
    $total    = (int)$cstmt->fetchColumn();

    // Pagination
    $per_page     = (int)($filters['per_page'] ?? 20);
    $current_page = (int)($filters['page']     ?? 1);
    $pagination   = paginate($total, $per_page, $current_page);
    $offset       = ($pagination['current_page'] - 1) * $per_page;

    $sql = "SELECT f.*, d.name AS dept_name, d.slug AS dept_slug, d.icon AS dept_icon
            FROM feedback f
            LEFT JOIN departments d ON d.id = f.department_id
            {$whereSql}
            ORDER BY f.created_at DESC
            LIMIT {$per_page} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [
        'stmt'       => $stmt,
        'total'      => $total,
        'pagination' => $pagination,
    ];
}
