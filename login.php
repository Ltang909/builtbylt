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
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<title>Built by LT — Agentic Outbound + Creative Testing</title>
<style>
  *{box-sizing:border-box}
  body{background:#121731;color:#eef0ff;font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
       margin:0;padding:48px 20px 64px}
  .wrap{max-width:1080px;margin:0 auto}
  .brand{color:#e8b84b;font-weight:700;letter-spacing:.14em;font-size:13px;text-transform:uppercase}
  h1{font-size:clamp(30px,4.5vw,46px);line-height:1.08;margin:12px 0 10px}
  .sub{color:#b9bee8;font-size:17px;max-width:640px;margin:0 0 36px;line-height:1.5}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
  @media (max-width:820px){.grid{grid-template-columns:1fr}}
  .card{background:#1a1f42;border:1px solid #2e3568;border-radius:12px;padding:22px}
  .card h2{margin:2px 0 4px;font-size:21px}
  .kick{color:#e8b84b;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
  .card video{width:100%;border-radius:8px;border:1px solid #2e3568;background:#000;margin:12px 0 6px}
  .card ul{margin:10px 0 4px;padding-left:19px;color:#c9cdf5;line-height:1.55;font-size:15px}
  .card li{margin:7px 0}
  .foot{color:#8f95c9;font-size:13.5px;margin-top:12px}
  .cta-row{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin:34px 0 26px}
  .cta{display:inline-block;background:#5B62F9;color:#fff;text-decoration:none;font-weight:700;
       padding:13px 22px;border-radius:8px;font-size:16px}
  .cta-note{color:#b9bee8;font-size:14.5px}
  .login-card{background:#1a1f42;border:1px solid #2e3568;border-radius:12px;padding:22px;max-width:420px}
  .login-card h3{margin:0 0 4px;font-size:17px}
  .login-card p{color:#8f95c9;font-size:13.5px;margin:0 0 8px}
  input{display:block;margin:10px 0;padding:10px;width:100%;border-radius:6px;
        border:1px solid #2e3568;background:#222856;color:#eef0ff;font-size:15px}
  button{background:#222856;color:#eef0ff;border:1px solid #454e96;padding:10px 18px;
         border-radius:6px;cursor:pointer;font-size:15px}
  .err{color:#ff8fa8;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <p class="brand">Built by LT</p>
  <h1>Three tools, behind a login.</h1>
  <p class="sub">Working systems I built and run for real client work: one finds and
  drafts outreach to the right companies, the other generates and grades ad
  creative against a brand book. Watch the demos, then ask for an account.</p>

  <div class="grid">
    <section class="card">
      <p class="kick">Prospecting</p>
      <h2>Agentic Outbound Engine</h2>
      <video controls preload="metadata" playsinline
        src="https://leontang.ca/assets/agentic-outbound-Nov62026.mp4"></video>
      <ul>
        <li>Reads hiring signals across nine job-board platforms and scores
        companies against your ICP on fit, signal, and evidence.</li>
        <li>Enriches prospects with Apollo only behind an on-screen credit
        estimate and your click. Scheduled runs do discovery only and never
        spend credits.</li>
        <li>Drafts outreach for human approval, then sends from your own
        Gmail with tracked links.</li>
        <li>Campaign reporting and CSV export built in.</li>
      </ul>
      <p class="foot">Built for Quartermaster's executive-dinner pipeline first.</p>
    </section>

    <section class="card">
      <p class="kick">Creative</p>
      <h2>Agentic Creative Testing System</h2>
      <video controls preload="metadata" playsinline
        src="https://leontang.ca/assets/agentic-creative-testing-Nov62026.mp4"></video>
      <ul>
        <li>One workspace per brand: tokens, rules, and guidelines travel
        with every generation.</li>
        <li>Generates ad copy and images, then grades them against the
        brand book before anything ships.</li>
        <li>Pushes Google Display campaigns in a paused state, with
        account-level content guardrails applied first.</li>
        <li>Every provider call is estimated and confirmed before it runs,
        and logged in a cost ledger. Bring your own API keys.</li>
      </ul>
      <p class="foot">Live for multiple brand workspaces today.</p>
    </section>

    <section class="card">
      <p class="kick">Content</p>
      <h2>Interview Studio</h2>
      <ul>
        <li>Talk through a story, get an honest verdict on whether it is
        worth posting, and draft the LinkedIn post in your own words.</li>
        <li>Voice or text interview. The editorial check can say "skip
        it", and the draft sticks to what the transcript actually says.</li>
      </ul>
      <p class="foot"><a href="/interview-studio/" style="color:#e8b84b;font-weight:700">Open the Interview Studio</a><br>
      It uses its own studio sign-in, separate from the panel login.</p>
    </section>
  </div>

  <div class="cta-row">
    <a class="cta" href="mailto:leon@leontang.ca?subject=Demo%20account%20request">Email Leon for a demo account</a>
    <span class="cta-note">Tell me what you would point it at, and I will set you up.</span>
  </div>

  <form class="login-card" method="post" action="api/auth.php?action=login">
    <h3>Already have access?</h3>
    <p>Log in with the panel password.</p>
    <?php if ($error !== ''): ?>
      <p class="err">Wrong password.</p>
    <?php endif; ?>
    <label>Panel password
      <input type="password" name="password" autocomplete="current-password"></label>
    <button type="submit">Log in</button>
  </form>
</div>
</body>
</html>
