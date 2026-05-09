<?php
/**
 * Session management and authentication helpers
 */

/**
 * Start a secure PHP session (call once per request, before any output).
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
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

/**
 * Check whether a user is currently logged in.
 */
function is_logged_in(): bool
{
    start_session();
    return !empty($_SESSION['user_id']);
}

/**
 * Require the user to be logged in; redirect to login page otherwise.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        $login = BASE_URL . '/dashboard/login.php';
        header('Location: ' . $login);
        exit;
    }
}

/**
 * Store user data in the session after a successful login.
 *
 * @param array $user  Row from admin_users table
 */
function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['user_role'] = $user['role'] ?? 'admin';
}

/**
 * Return the current user's role ('admin' or 'supervisor').
 */
function current_role(): string
{
    start_session();
    return $_SESSION['user_role'] ?? 'admin';
}

/**
 * Check whether the current user has the admin role.
 */
function is_admin(): bool
{
    return current_role() === 'admin';
}

/**
 * Require admin role; redirect supervisors to overview with an error flash.
 */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        start_session();
        $_SESSION['access_error'] = 'You do not have permission to access that page.';
        header('Location: ' . BASE_URL . '/dashboard/overview.php');
        exit;
    }
}

/**
 * Destroy the current session and clear the cookie.
 */
function logout_user(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }
    session_destroy();
}

/**
 * Return the current CSRF token (generate one if not yet set).
 */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify that the submitted CSRF token matches the session token.
 *
 * @param string|null $token  Token from the request
 */
function verify_csrf(?string $token): bool
{
    start_session();
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
