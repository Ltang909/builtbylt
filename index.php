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
$posthogTrends = portal_posthog_trends($projects);
$tasks = portal_read_tasks();
$ideas = portal_read_ideas();
$calendarEvents = portal_calendar_events();
$openTasks = array_values(array_filter($tasks, static fn(array $task): bool => empty($task['done'])));
$totalViews = array_sum(array_map(static fn(array $metric): int => (int) ($metric['views'] ?? 0), $posthogMetrics));
$totalVisitors = array_sum(array_map(static fn(array $metric): int => (int) ($metric['visitors'] ?? 0), $posthogMetrics));
$totalRevenue = array_sum(array_map(static fn(array $metric): float => (float) ($metric['revenue'] ?? 0), $posthogMetrics));
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
  <link rel="stylesheet" href="/app.css?v=5">
  <style>
    .hero{min-height:220px;padding:40px 0 28px}.hero h1{font-size:clamp(42px,5vw,72px);line-height:.86}.dispatch strong{font-size:54px}.scorecard article{padding:20px 16px 16px;min-height:160px}.scorecard strong{font-size:40px;margin:9px 0 4px}.revenue-scorecard .metric-revenue.is-zero strong{color:#d6382f}.revenue-scorecard .metric-revenue.is-positive strong{color:#187c43}.project-grid{grid-template-columns:repeat(2,1fr)}.project-card{min-height:auto!important;border-bottom:1px solid}.project-card:nth-child(2n){border-right:0}.project-card:nth-last-child(-n+2){border-bottom:0}.project-number{margin-bottom:22px}.section-head{padding:46px 0 18px}.timeline-item{padding:20px 0}
    @media(max-width:700px){.project-grid{grid-template-columns:1fr}.project-card{border-right:0}.hero{min-height:190px}.hero h1{font-size:45px}}
  </style>
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

    <section class="scorecard revenue-scorecard" aria-label="Portfolio scorecard">
      <article class="metric-traffic"><span>Total web traffic</span><strong><?= number_format($totalViews) ?></strong><small>page views · 30 days</small><?= portal_sparkline_svg($posthogTrends['views'], '#195cff') ?></article>
      <article class="metric-revenue <?= $totalRevenue > 0 ? 'is-positive' : 'is-zero' ?>"><span>Total revenue</span><strong>$<?= number_format($totalRevenue, 0) ?></strong><small>tracked · 30 days</small><?= portal_sparkline_svg($posthogTrends['revenue'], $totalRevenue > 0 ? '#187c43' : '#d6382f') ?></article>
      <article class="metric-users"><span>Unique users</span><strong><?= number_format($totalVisitors) ?></strong><small>visitors · 30 days</small><?= portal_sparkline_svg($posthogTrends['visitors'], '#6943c6') ?></article>
      <article><span>Open to ship</span><strong><?= count($openTasks) ?></strong><small>things needing action</small></article>
    </section>

    <div class="cockpit-layout"><div class="cockpit-main">
    <section class="section-head"><div><span>01</span><h2>Properties</h2></div><p>A living index of the things under construction.</p></section>
    <section class="project-grid">
      <?php foreach ($projects as $index => $project): ?>
      <?php
        $projectActivity = portal_activity_for_project($project['id'], $activity);
        $lastProjectUpdate = $projectActivity[0] ?? null;
        $freshness = portal_freshness($lastProjectUpdate['timestamp'] ?? null);
        $updates30d = count(array_filter($projectActivity, static fn(array $item): bool => portal_date($item['timestamp'])->getTimestamp() >= time() - 2592000));
        $projectVisitors = (int) ($posthogMetrics[$project['id']]['visitors'] ?? 0);
        $projectRevenue = (float) ($posthogMetrics[$project['id']]['revenue'] ?? 0);
        $activityText = strtolower(implode(' ', array_map(static fn(array $item): string => ($item['title'] ?? '') . ' ' . ($item['detail'] ?? ''), $projectActivity)));
        $launched = str_contains($activityText, 'launch') || in_array($project['status'], ['live', 'active'], true);
        $gtm = str_contains($activityText, 'go to market') || str_contains($activityText, 'organic social') || str_contains($activityText, 'social post');
        $milestones = [
          ['Pre-product', true], ['Launched', $launched], ['First visits', $projectVisitors > 0],
          ['30 visitors', $projectVisitors >= 30], ['Go to market', $gtm], ['First revenue', $projectRevenue > 0],
          ['Repeatable growth', $projectVisitors >= 100 && $projectRevenue > 0],
        ];
        $milestonesDone = count(array_filter($milestones, static fn(array $milestone): bool => $milestone[1]));
      ?>
      <article class="project-card tone-<?= ($index % 4) + 1 ?>">
        <div class="project-number">0<?= $index + 1 ?></div>
        <div class="project-main"><span class="status status-<?= htmlspecialchars($project['status']) ?>"><?= htmlspecialchars($project['status']) ?></span><h3><?= htmlspecialchars($project['name']) ?></h3><a href="https://<?= htmlspecialchars($project['domain']) ?>" target="_blank" rel="noreferrer"><?= htmlspecialchars($project['domain']) ?> ↗</a></div>
        <p><?= htmlspecialchars($project['note']) ?></p>
        <div class="business-metrics">
          <div><strong><?= $posthogMetrics[$project['id']]['views'] === null ? '—' : number_format($posthogMetrics[$project['id']]['views']) ?></strong><span>30d views</span></div>
          <div><strong><?= $posthogMetrics[$project['id']]['visitors'] === null ? '—' : number_format($posthogMetrics[$project['id']]['visitors']) ?></strong><span>visitors</span></div>
          <div><strong>$<?= number_format((float) ($posthogMetrics[$project['id']]['revenue'] ?? 0), 0) ?></strong><span>revenue</span></div>
          <div><strong><?= $updates30d ?></strong><span>updates</span></div>
        </div>
        <div class="last-shipped"><span class="health-dot <?= $freshness['tone'] ?>"></span><div><small>Last update shipped</small><strong><?= htmlspecialchars($lastProjectUpdate['title'] ?? 'Nothing logged') ?></strong><em><?= htmlspecialchars($freshness['label']) ?></em></div></div>
        <div class="milestone-track"><div class="milestone-head"><small>Milestones</small><strong><?= $milestonesDone ?>/<?= count($milestones) ?></strong></div><div class="milestone-progress"><i style="width:<?= round(($milestonesDone / count($milestones)) * 100) ?>%"></i></div><ol><?php foreach ($milestones as [$milestoneLabel, $milestoneDone]): ?><li class="<?= $milestoneDone ? 'done' : '' ?>"><i></i><span><?= htmlspecialchars($milestoneLabel) ?></span></li><?php endforeach; ?></ol></div>
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

    <section class="section-head"><div><span>03</span><h2>Open things to ship</h2></div><p>The work that turns traffic into revenue.</p></section>
    <section class="ship-queue">
      <form id="task-form"><select name="project" required><?php foreach ($projects as $project): ?><option value="<?= htmlspecialchars($project['id']) ?>"><?= htmlspecialchars($project['name']) ?></option><?php endforeach; ?></select><input name="title" maxlength="140" required placeholder="What needs to ship next?"><button class="button button-dark">Add to queue →</button></form>
      <div class="task-list">
        <?php if (!$openTasks): ?><div class="empty-task">Nothing open. Add the next revenue-moving ship.</div><?php endif; ?>
        <?php foreach ($openTasks as $task): ?><label class="task-row"><input type="checkbox" data-task-id="<?= htmlspecialchars($task['id']) ?>"><span></span><strong><?= htmlspecialchars($task['title']) ?></strong><em><?= htmlspecialchars(portal_project_name($task['project'], $projects)) ?></em></label><?php endforeach; ?>
      </div>
    </section>
    </div>

    <aside class="idea-rail" aria-label="Founder planning rail">
      <section class="ideas-panel">
        <div class="rail-kicker">Always visible</div><h2>Ideas to ship</h2><p>Capture the promising things before they disappear.</p>
        <form id="idea-form"><select name="project" aria-label="Property" required><?php foreach ($projects as $project): ?><option value="<?= htmlspecialchars($project['id']) ?>"><?= htmlspecialchars($project['name']) ?></option><?php endforeach; ?></select><input name="title" maxlength="180" required placeholder="One sharp idea…"><button class="button button-dark">Add idea →</button></form>
        <div class="idea-list"><?php if (!$ideas): ?><div class="rail-empty">No scraped ideas are connected yet. Add the best ones here as they surface.</div><?php endif; ?><?php foreach (array_slice($ideas, 0, 12) as $idea): ?><article><span><?= htmlspecialchars(portal_project_name($idea['project'], $projects)) ?></span><strong><?= htmlspecialchars($idea['title']) ?></strong></article><?php endforeach; ?></div>
      </section>
      <section class="calendar-panel">
        <div class="rail-kicker">Weekly attention</div><h2>Time split</h2>
        <div class="allocation"><div><span>Revenue</span><strong>60%</strong></div><i><b style="width:60%"></b></i><div><span>Shipping</span><strong>40%</strong></div><i><b style="width:40%"></b></i></div>
        <div class="calendar-events"><?php if (!$calendarEvents): ?><div class="rail-empty">Connect your private Google Calendar iCal link to see upcoming focus blocks here.</div><?php endif; ?><?php foreach ($calendarEvents as $event): ?><article><time><?= htmlspecialchars(portal_date($event['timestamp'])->format('D · M j · g:ia')) ?></time><strong><?= htmlspecialchars($event['title']) ?></strong></article><?php endforeach; ?></div>
        <a class="calendar-link" href="https://calendar.google.com/calendar/u/0/r/week" target="_blank" rel="noreferrer">Open Google Calendar ↗</a>
      </section>
    </aside></div>
  </main>

  <dialog id="log-dialog">
    <form id="log-form" method="dialog">
      <div class="dialog-head"><div><span>Quick entry</span><h2>Log an update</h2></div><button class="icon-button" type="button" data-close-log aria-label="Close">×</button></div>
      <label>Property<select name="project" required><?php foreach ($projects as $project): ?><option value="<?= htmlspecialchars($project['id']) ?>"><?= htmlspecialchars($project['name']) ?></option><?php endforeach; ?></select></label>
      <fieldset><legend>Entry type</legend><label><input type="radio" name="type" value="update" checked><span>Update</span></label><label><input type="radio" name="type" value="deploy"><span>Deploy</span></label><label><input type="radio" name="type" value="milestone"><span>Milestone</span></label></fieldset>
      <label>What shipped?<input name="title" maxlength="120" required placeholder="Tight, specific, done."></label>
      <label>Ship date<input name="date" type="date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required></label>
      <label>Notes <span>(optional)</span><textarea name="detail" maxlength="600" rows="3" placeholder="Context, outcome, or the next move."></textarea></label>
      <div class="dialog-actions"><span id="form-status" role="status"></span><button class="button button-dark" type="submit">Put it on the board →</button></div>
    </form>
  </dialog>
  <script src="/app.js?v=5" defer></script>
</body>
</html>

