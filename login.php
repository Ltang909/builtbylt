<?php
// Login screen. If no admin password exists yet, this bounces to setup.
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/auth.php';

if (!empty($GLOBALS['CONFIG_MISSING']) || $pdo === null) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="background:#121731;color:#eef0ff;'
        . 'font-family:sans-serif;padding:40px"><h2>Panel not configured</h2>'
        . '<p>Rename <code>api/config.sample.php</code> to '
        . '<code>api/config.php</code>, fill in the MySQL values from '
        . 'hPanel, and run <code>sql/schema.sql</code> in phpMyAdmin '
        . 'first. See DEPLOY.md.</p></body></html>';
    exit;
}
if (!admin_exists($pdo)) {
    header('Location: ' . APP_BASE_PATH . '/setup.php');
    exit;
}
if (is_logged_in()) {
    header('Location: ' . APP_BASE_PATH . '/');
    exit;
}
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel login — Creative Testing System</title>
<style>
  body{background:#121731;color:#eef0ff;font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
  form{background:#1a1f42;padding:28px;border-radius:10px;border:1px solid #2e3568}
  input{display:block;margin:10px 0;padding:9px;width:260px;border-radius:6px;
        border:1px solid #2e3568;background:#222856;color:#eef0ff}
  button{background:#5B62F9;color:#fff;border:none;padding:9px 18px;border-radius:6px;cursor:pointer}
  .err{color:#ff8fa8;font-size:13px}
</style>
</head>
<body>
<form method="post" action="api/auth.php?action=login">
  <h3 style="margin-top:0">Creative Testing System</h3>
  <?php if ($error !== ''): ?>
    <p class="err">Wrong password.</p>
  <?php endif; ?>
  <label>Panel password
    <input type="password" name="password" autofocus autocomplete="current-password"></label>
  <button type="submit">Log in</button>
</form>
</body>
</html>
