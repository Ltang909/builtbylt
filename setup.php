<?php
// First-run SETUP screen. Shown only while panel_admin is empty: the
// first visitor creates the admin password and is logged in immediately.
// Once a password exists this page just bounces to login.
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
if (admin_exists($pdo)) {
    header('Location: ' . APP_BASE_PATH . '/login.php');
    exit;
}
$error = $_GET['error'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up the Creative Testing System panel</title>
<style>
  body{background:#121731;color:#eef0ff;font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
  .box{background:#1a1f42;padding:28px;border-radius:10px;border:1px solid #2e3568;max-width:520px}
  .warn{background:#3a1224;border:1px solid #ef476f;color:#ffb3c6;border-radius:8px;
        padding:14px 16px;font-size:14px;line-height:1.5;margin-bottom:18px}
  input{display:block;margin:10px 0;padding:9px;width:100%;border-radius:6px;
        border:1px solid #2e3568;background:#222856;color:#eef0ff;box-sizing:border-box}
  button{background:#5B62F9;color:#fff;border:none;padding:9px 18px;border-radius:6px;cursor:pointer}
  .err{color:#ff8fa8;font-size:13px}
</style>
</head>
<body>
<div class="box">
  <h3 style="margin-top:0">Creative Testing System — first-run setup</h3>
  <div class="warn"><strong>SETUP MODE: create your admin password now — anyone who
    reaches this page first can claim it.</strong> This panel holds live API keys.
    Set the password immediately, then log in and add your keys in Settings.</div>
  <?php if ($error === 'short'): ?>
    <p class="err">Password must be at least 8 characters. Pick something long.</p>
  <?php endif; ?>
  <form method="post" action="api/auth.php?action=setup">
    <label>Admin password (min 8 characters, longer is better)
      <input type="password" name="password" autofocus autocomplete="new-password"></label>
    <button type="submit">Create password &amp; log in</button>
  </form>
</div>
</body>
</html>
