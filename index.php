<?php
// Control panel UI entry. Requires setup (first-run password creation)
// and then a session login — see lib/auth.php. If the DB config is
// missing, say so plainly instead of failing white.
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/auth.php';

if (!empty($GLOBALS['CONFIG_MISSING']) || $pdo === null) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="background:#121731;color:#eef0ff;'
        . 'font-family:sans-serif;padding:40px"><h2>Panel not configured</h2>'
        . '<p>Rename <code>api/config.sample.php</code> to '
        . '<code>api/config.php</code>, fill in the MySQL values from '
        . 'hPanel, and run <code>sql/schema.sql</code> in phpMyAdmin. '
        . 'See DEPLOY.md.</p></body></html>';
    exit;
}
require_page_auth($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<title>Creative Testing System — Control Panel (Universal)</title>
<link rel="stylesheet" href="app.css?v=20261105-1">
</head>
<body>
<header>
  <div>
    <h1>Creative Testing System <span class="sub">universal</span></h1>
    <div class="sub">Workspace: <select id="ws-select"></select>
      <span id="verifier-badge" class="badge">verifier: …</span></div>
  </div>
  <div class="sub">Owner: Leon Tang · every brand is a workspace, nothing is hard-coded
    · <a href="api/auth.php?action=logout" style="color:var(--muted)">Log out</a></div>
</header>
<nav id="tabs">
  <button data-tab="intake">0 · Intake</button>
  <button data-tab="experiments" class="active">1 · Experiments</button>
  <button data-tab="queue">2 · Creative Queue</button>
  <button data-tab="launch">3 · Launch Board</button>
  <button data-tab="performance">4 · Performance</button>
  <button data-tab="learnings">5 · Learnings</button>
  <button data-tab="settings">Settings</button>
</nav>
<main>
  <!-- Update 6: one vertical flow. Every stage is on the page in
       pipeline order; a stage the work has not reached yet renders
       greyed and inert, and finishing a stage scrolls to the next. -->
  <div class="stage minimized" id="stage-intake">
    <div class="stage-head"><span class="stage-num">0</span><h2>Intake — previous creatives</h2><span class="chip src">optional</span><span class="stage-state"></span><button class="btn secondary stage-toggle" onclick="toggleStage('intake')">Show</button></div>
    <section id="tab-intake"></section>
    <div class="stage-foot"><button class="btn secondary stage-next" data-next="experiments">Next: Experiments ↓</button></div>
  </div>
  <div class="stage" id="stage-experiments">
    <div class="stage-head"><span class="stage-num">1</span><h2>Experiments</h2><span class="stage-state"></span></div>
    <section id="tab-experiments"></section>
    <div class="stage-foot"><button class="btn stage-next" data-next="queue">Next: Creative Queue ↓</button></div>
  </div>
  <div class="stage" id="stage-queue">
    <div class="stage-head"><span class="stage-num">2</span><h2>Creative Queue</h2><span class="stage-state"></span></div>
    <section id="tab-queue"></section>
    <div class="stage-foot"><button class="btn stage-next" data-next="launch">Next: Launch Board ↓</button></div>
  </div>
  <div class="stage" id="stage-launch">
    <div class="stage-head"><span class="stage-num">3</span><h2>Launch Board</h2><span class="stage-state"></span></div>
    <section id="tab-launch"></section>
    <div class="stage-foot"><button class="btn stage-next" data-next="performance">Next: Performance ↓</button></div>
  </div>
  <div class="stage" id="stage-performance">
    <div class="stage-head"><span class="stage-num">4</span><h2>Performance</h2><span class="stage-state"></span></div>
    <section id="tab-performance"></section>
    <div class="stage-foot"><button class="btn stage-next" data-next="learnings">Next: Learnings ↓</button></div>
  </div>
  <div class="stage" id="stage-learnings">
    <div class="stage-head"><span class="stage-num">5</span><h2>Learnings</h2><span class="stage-state"></span></div>
    <section id="tab-learnings"></section>
    <div class="stage-foot"><button class="btn secondary stage-next" data-next="settings">Workspace settings ↓</button></div>
  </div>
  <div class="stage" id="stage-settings">
    <div class="stage-head"><span class="stage-num">⚙</span><h2>Settings</h2><span class="stage-state"></span></div>
    <section id="tab-settings"></section>
  </div>
</main>
<script src="app.js?v=20261106-7"></script>
</body>
</html>
