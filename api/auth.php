<?php
/**
 * Auth endpoint: first-run setup, login, logout, and a small "me" probe.
 *
 * Form posts (from setup.php / login.php) redirect like a normal site:
 *   POST api/auth.php?action=setup    {password}  — only while panel_admin
 *                                     is empty; creates the hash, logs in.
 *   POST api/auth.php?action=login    {password}
 *   GET|POST api/auth.php?action=logout
 *   GET  api/auth.php?action=me       — JSON {setup_required, logged_in};
 *                                     one of the two endpoints reachable
 *                                     without a session (with status.php's
 *                                     health flag), and it reveals nothing
 *                                     else.
 */
require __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'me') {
    json_out([
        'setup_required' => !admin_exists($pdo),
        'logged_in' => is_logged_in(),
    ]);
}

if ($action === 'setup') {
    if (admin_exists($pdo)) {
        header('Location: ' . APP_BASE_PATH . '/login.php');
        exit;
    }
    $password = (string)($_POST['password'] ?? '');
    if (strlen($password) < 8) {
        header('Location: ' . APP_BASE_PATH . '/setup.php?error=short');
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO panel_admin (password_hash) VALUES (?)');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT)]);
    do_login_session();
    header('Location: ' . APP_BASE_PATH . '/');
    exit;
}

if ($action === 'login') {
    if (!admin_exists($pdo)) {
        header('Location: ' . APP_BASE_PATH . '/setup.php');
        exit;
    }
    $password = (string)($_POST['password'] ?? '');
    $row = $pdo->query('SELECT password_hash FROM panel_admin ORDER BY id LIMIT 1')
               ->fetch(PDO::FETCH_ASSOC);
    if ($row !== false && password_verify($password, (string)$row['password_hash'])) {
        do_login_session();
        header('Location: ' . APP_BASE_PATH . '/');
        exit;
    }
    header('Location: ' . APP_BASE_PATH . '/login.php?error=1');
    exit;
}

if ($action === 'logout') {
    do_logout_session();
    header('Location: ' . APP_BASE_PATH . '/login.php');
    exit;
}

json_out(['error' => 'unknown action'], 400);
