<?php
/**
 * Panel authentication: single admin, PHP session cookie.
 *
 * There is NO hard-coded password and NO env var. The admin password hash
 * lives in the panel_admin table:
 *   - Table empty  -> SETUP mode. index.php/setup.php send the first
 *     visitor to the setup screen to create the password. API endpoints
 *     (except auth.php login/setup/me and status.php's health flag) refuse
 *     with {error: setup_required} until setup is done. Setup mode is
 *     unmistakable in the UI: whoever reaches the page first claims the
 *     panel, so it must be completed immediately after deploy.
 *   - Table has a row -> login is required for every page and endpoint.
 *
 * Hashing is PHP password_hash() / password_verify() (bcrypt).
 */

function panel_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('PANEL_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE_PATH !== '' ? APP_BASE_PATH : '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function admin_exists(PDO $pdo): bool {
    $row = $pdo->query('SELECT COUNT(*) AS n FROM panel_admin')
               ->fetch(PDO::FETCH_ASSOC);
    return $row !== false && (int)$row['n'] > 0;
}

function is_logged_in(): bool {
    panel_session_start();
    return !empty($_SESSION['panel_admin']);
}

function do_login_session(): void {
    panel_session_start();
    session_regenerate_id(true);
    $_SESSION['panel_admin'] = true;
}

function do_logout_session(): void {
    panel_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'],
                  $params['domain'] ?? '', (bool)($params['secure'] ?? false),
                  (bool)($params['httponly'] ?? true));
    }
    session_destroy();
}

/** Gate for HTML pages (index.php). Redirects to setup or login. */
function require_page_auth(PDO $pdo): void {
    if (!admin_exists($pdo)) {
        header('Location: ' . APP_BASE_PATH . '/setup.php');
        exit;
    }
    if (!is_logged_in()) {
        header('Location: ' . APP_BASE_PATH . '/login/');
        exit;
    }
}

/** Gate for API endpoints. JSON errors, in the site's style. */
function require_api_auth(PDO $pdo): void {
    if (!admin_exists($pdo)) {
        json_out(['error' => 'setup_required',
                  'hint' => 'Open the panel in a browser and create the '
                          . 'admin password first.'], 403);
    }
    if (!is_logged_in()) {
        json_out(['error' => 'login required'], 401);
    }
}
