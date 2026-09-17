<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

portal_start_session();
$config = portal_config();
$setupRequired = empty($config['password_hash']);

if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    portal_logout();
    header('Location: /');
    exit;
}

$loginError = null;
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    if ($setupRequired) {
        $loginError = 'Portal access has not been configured yet.';
    } elseif (!portal_login_allowed()) {
        $loginError = 'Too many attempts. Wait a moment and try again.';
    } elseif (password_verify((string) ($_POST['password'] ?? ''), $config['password_hash'])) {
        portal_mark_authenticated();
        header('Location: /');
        exit;
    } else {
        portal_record_failed_login();
        $loginError = 'That password did not match.';
    }
}

if (!portal_is_authenticated()) {
    portal_render_login($setupRequired, $loginError);
    exit;
}

$projects = portal_projects();
$activity = portal_read_activity();
$posthogMetrics = portal_posthog_metrics($projects);
$today = (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');
$todayActivity = array_values(array_filter($activity, static fn(array $item): bool => str_starts_with($item['timestamp'], $today)));
$activeCount = count(array_filter($projects, static fn(array $project): bool => $project['status'] === 'active'));
$latest = $activity[0]['timestamp'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Built by LT — Founder Console</title>
  <link rel="stylesheet" href="/app.css?v=1">
</head>
<body>
  <div class="grain" aria-hidden="true"></div>
  <header class="topbar">
    <a class="brand" href="/" aria-label="Built by LT dashboard"><span>BUILT</span><i>by</i><span>LT</span></a>
    <div class="topbar-meta"><span class="live-dot"></span><span><?= htmlspecialchars(strtoupper($config['timezone'])) ?></span><span id="clock">--:--:--</span></div>
    <button class="button button-accent" type="button" data-open-log>+ Log update</button>
    <form method="post"><input type="hidden" name="action" value="logout"><button class="icon-button" title="Lock console" aria-label="Lock console">↗</button></form>
  </header>

  <main>
    <section class="hero">
      <div><p class="eyebrow">Founder console / <?= htmlspecialchars(date('D · M j')) ?></p><h1>What shipped<br><em>today?</em></h1></div>
      <div class="dispatch"><span>Daily dispatch</span><strong><?= count($todayActivity) ?></strong><p><?= count($todayActivity) === 1 ? 'record on the board' : 'records on the board' ?></p></div>
    </section>

    <section class="scorecard" aria-label="Portfolio scorecard">
      <article><span>Properties</span><strong><?= count($projects) ?></strong><small>in portfolio</small></article>
      <article><span>In motion</span><strong><?= $activeCount ?></strong><small>active builds</small></article>
      <article><span>Today</span><strong><?= count($todayActivity) ?></strong><small>updates logged</small></article>
      <article><span>Last ship</span><strong class="small-value"><?= $latest ? htmlspecialchars(portal_relative_time($latest)) : '—' ?></strong><small><?= $latest ? htmlspecialchars(portal_format_time($latest)) : 'nothing logged yet' ?></small></article>
    </section>

    <section class="section-head"><div><span>01</span><h2>Properties</h2></div><p>A living index of the things under construction.</p></section>
    <section class="project-grid">
      <?php foreach ($projects as $index => $project): ?>
      <?php $projectActivity = portal_activity_for_project($project['id'], $activity); $lastProjectUpdate = $projectActivity[0] ?? null; $freshness = portal_freshness($lastProjectUpdate['timestamp'] ?? null); $updates30d = count(array_filter($projectActivity, static fn(array $item): bool => portal_date($item['timestamp'])->getTimestamp() >= time() - 2592000)); $deploys30d = count(array_filter($projectActivity, static fn(array $item): bool => $item['type'] === 'deploy' && portal_date($item['timestamp'])->getTimestamp() >= time() - 2592000)); ?>
      <article class="project-card tone-<?= ($index % 4) + 1 ?>">
        <div class="project-number">0<?= $index + 1 ?></div>
        <div class="project-main"><span class="status status-<?= htmlspecialchars($project['status']) ?>"><?= htmlspecialchars($project['status']) ?></span><h3><?= htmlspecialchars($project['name']) ?></h3><a href="https://<?= htmlspecialchars($project['domain']) ?>" target="_blank" rel="noreferrer"><?= htmlspecialchars($project['domain']) ?> ↗</a></div>
        <p><?= htmlspecialchars($project['note']) ?></p>
        <div class="business-metrics">
          <div><strong><?= $posthogMetrics[$project['id']]['views'] === null ? '—' : number_format($posthogMetrics[$project['id']]['views']) ?></strong><span>30d views</span></div>
          <div><strong><?= $posthogMetrics[$project['id']]['visitors'] === null ? '—' : number_format($posthogMetrics[$project['id']]['visitors']) ?></strong><span>visitors</span></div>
          <div><strong><?= $updates30d ?></strong><span>updates</span></div>
          <div><strong><?= $deploys30d ?></strong><span>deploys</span></div>
        </div>
        <div class="last-shipped"><span class="health-dot <?= $freshness['tone'] ?>"></span><div><small>Last update shipped</small><strong><?= htmlspecialchars($lastProjectUpdate['title'] ?? 'Nothing logged') ?></strong><em><?= htmlspecialchars($freshness['label']) ?></em></div></div>
        <?php if ($projectActivity): ?><div class="mini-log"><small>Recent change log</small><?php foreach (array_slice($projectActivity, 0, 3) as $change): ?><div><time><?= htmlspecialchars(portal_format_day($change['timestamp'])) ?></time><span><?= htmlspecialchars($change['title']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
        <div class="card-foot"><span><?= htmlspecialchars($project['phase']) ?></span><button type="button" data-open-log data-project="<?= htmlspecialchars($project['id']) ?>">Log ↗</button></div>
      </article>
      <?php endforeach; ?>
    </section>

    <section class="section-head timeline-heading"><div><span>02</span><h2>Ship log</h2></div><div class="filters"><button class="filter active" data-filter="all">All</button><button class="filter" data-filter="deploy">Deploys</button><button class="filter" data-filter="update">Updates</button></div></section>
    <section class="timeline" id="timeline">
      <?php if (!$activity): ?><div class="empty"><strong>The board is clean.</strong><p>Log the first update and start the record.</p></div><?php endif; ?>
      <?php foreach ($activity as $item): ?>
      <article class="timeline-item" data-type="<?= htmlspecialchars($item['type']) ?>">
        <time datetime="<?= htmlspecialchars($item['timestamp']) ?>"><strong><?= htmlspecialchars(portal_format_day($item['timestamp'])) ?></strong><span><?= htmlspecialchars(portal_format_time($item['timestamp'])) ?></span></time>
        <i class="timeline-mark"></i>
        <div><span class="event-meta"><?= htmlspecialchars($item['type']) ?> · <?= htmlspecialchars(portal_project_name($item['project'], $projects)) ?></span><h3><?= htmlspecialchars($item['title']) ?></h3><?php if ($item['detail']): ?><p><?= nl2br(htmlspecialchars($item['detail'])) ?></p><?php endif; ?></div>
      </article>
      <?php endforeach; ?>
    </section>
  </main>

  <dialog id="log-dialog">
    <form id="log-form" method="dialog">
      <div class="dialog-head"><div><span>Quick entry</span><h2>Log an update</h2></div><button class="icon-button" type="button" data-close-log aria-label="Close">×</button></div>
      <label>Property<select name="project" required><?php foreach ($projects as $project): ?><option value="<?= htmlspecialchars($project['id']) ?>"><?= htmlspecialchars($project['name']) ?></option><?php endforeach; ?></select></label>
      <fieldset><legend>Entry type</legend><label><input type="radio" name="type" value="update" checked><span>Update</span></label><label><input type="radio" name="type" value="deploy"><span>Deploy</span></label><label><input type="radio" name="type" value="milestone"><span>Milestone</span></label></fieldset>
      <label>What shipped?<input name="title" maxlength="120" required placeholder="Tight, specific, done."></label>
      <label>Notes <span>(optional)</span><textarea name="detail" maxlength="600" rows="3" placeholder="Context, outcome, or the next move."></textarea></label>
      <div class="dialog-actions"><span id="form-status" role="status"></span><button class="button button-dark" type="submit">Put it on the board →</button></div>
    </form>
  </dialog>
  <script src="/app.js?v=1" defer></script>
</body>
</html>

