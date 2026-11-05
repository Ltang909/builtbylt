<?php
// Outbound Engine UI entry. Shares the panel's single admin login:
// same setup flow, same session. If the DB config is missing, say so
// plainly instead of failing white.
require __DIR__ . '/../lib/common.php';
require __DIR__ . '/../lib/auth.php';

if (!empty($GLOBALS['CONFIG_MISSING']) || $pdo === null) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="background:#121731;color:#eef0ff;'
        . 'font-family:sans-serif;padding:40px"><h2>Outbound Engine not '
        . 'configured</h2><p>The panel database config is missing. '
        . 'Rename <code>api/config.sample.php</code> to '
        . '<code>api/config.php</code> in the site root, fill in the '
        . 'MySQL values, and reload.</p></body></html>';
    exit;
}
if (!admin_exists($pdo)) {
    header('Location: ../setup.php');
    exit;
}
if (!is_logged_in()) {
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Outbound Engine</title>
<link rel="stylesheet" href="app.css?v=8">
</head>
<body>
<header>
  <div class="brand">
    <h1>Outbound Engine</h1>
    <span class="sub">Client agnostic outbound prospecting</span>
  </div>
  <div class="clientbar">
    <label for="clientSwitch">Client workspace</label>
    <select id="clientSwitch"></select>
    <a class="logout" href="../api/auth.php?action=logout">Log out</a>
  </div>
</header>
<div class="layout">
  <nav id="sidenav">
    <button data-view="dashboard" class="active">Dashboard</button>
    <button data-view="clients">Clients</button>
    <button data-view="icp">ICP &amp; Intent Signals</button>
    <button data-view="discovery">Prospect Discovery</button>
    <button data-view="queue">Outreach Queue</button>
    <button data-view="campaigns">Campaigns</button>
    <button data-view="reporting">Reporting</button>
    <button data-view="settings">Settings &amp; Integrations</button>
  </nav>
  <main id="view"></main>
</div>
<div id="modal" class="modal hidden">
  <div class="modal-card">
    <h3 id="modalTitle"></h3>
    <div id="modalBody"></div>
    <div class="modal-actions">
      <button id="modalCancel" class="btn secondary">Cancel</button>
      <button id="modalConfirm" class="btn">Confirm</button>
    </div>
  </div>
</div>
<div id="toast" class="toast hidden"></div>
<script src="app.js?v=10"></script>
</body>
</html>
