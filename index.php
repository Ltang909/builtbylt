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
$projectSessions = portal_posthog_project_sessions($projects);
$ideas = portal_read_ideas();
$priorityOrder = ['P0' => 0, 'P1' => 1, 'P2' => 2, 'PARKED' => 3, 'WATCHLIST' => 4];
usort($ideas, static function (array $a, array $b) use ($priorityOrder): int {
    $aPriority = strtoupper((string) ($a['priority'] ?? ''));
    $bPriority = strtoupper((string) ($b['priority'] ?? ''));
    $aRank = $priorityOrder[$aPriority] ?? ($priorityOrder[strtoupper((string) ($a['stage'] ?? ''))] ?? 5);
    $bRank = $priorityOrder[$bPriority] ?? ($priorityOrder[strtoupper((string) ($b['stage'] ?? ''))] ?? 5);
    return $aRank <=> $bRank ?: strcmp((string) ($a['ship_by'] ?? '9999-12-31'), (string) ($b['ship_by'] ?? '9999-12-31'));
});
$dailyLog = portal_read_daily_log();
$dailyLogByDate = []; $verticalCoverage = []; $dailyEntriesByProduct = [];
foreach ($dailyLog as $day) {
    $entries = is_array($day['entries'] ?? null) ? $day['entries'] : [];
    $dailyLogByDate[(string) $day['date']] = ['entries' => $entries, 'tomorrow_target' => (string) ($day['tomorrow_target'] ?? '')];
    foreach ($entries as $entry) {
        $vertical = trim((string) ($entry['vertical'] ?? '')) ?: 'Unclassified'; $verticalCoverage[$vertical] = ($verticalCoverage[$vertical] ?? 0) + 1;
        $productKey = strtolower(trim((string) ($entry['product'] ?? ''))); if ($productKey !== '') $dailyEntriesByProduct[$productKey][] = ['date' => (string) $day['date']] + $entry;
    }
}
arsort($verticalCoverage);
$maxVerticalCount = $verticalCoverage ? max($verticalCoverage) : 0;
$calendarAnchor = $dailyLog ? new DateTimeImmutable((string) $dailyLog[0]['date']) : new DateTimeImmutable('today');
$calendarStart = $calendarAnchor->modify('first day of this month');
$calendarLeading = (int) $calendarStart->format('N') - 1;
$calendarDays = (int) $calendarStart->format('t');
$totalViews = array_sum(array_map(static fn(array $metric): int => (int) ($metric['views'] ?? 0), $posthogMetrics));
$totalVisitors = array_sum(array_map(static fn(array $metric): int => (int) ($metric['visitors'] ?? 0), $posthogMetrics));
$totalRevenue = array_sum(array_map(static fn(array $metric): float => (float) ($metric['revenue'] ?? 0), $posthogMetrics));
$today = (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');
$todayActivity = array_values(array_filter($activity, static fn(array $item): bool => str_starts_with($item['timestamp'], $today)));
$latest = $activity[0]['timestamp'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Built by LT — Founder Console</title>
  <link rel="stylesheet" href="/app.css?v=8">
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
      <article><span>Priority queue</span><strong><?= count($ideas) ?></strong><small>ordered things to ship</small></article>
    </section>

    <div class="cockpit-layout"><div class="cockpit-main">
    <section class="section-head"><div><span>01</span><h2>Properties</h2></div><p>A living index of the things under construction.</p></section>
    <section class="project-grid">
      <?php foreach ($projects as $index => $project): ?>
      <?php
        $projectActivity = portal_activity_for_project($project['id'], $activity);
        $dailyProjectActivity = $dailyEntriesByProduct[strtolower($project['domain'])] ?? $dailyEntriesByProduct[strtolower($project['name'])] ?? [];
        $changeLog = $projectActivity;
        foreach ($dailyProjectActivity as $dailyChange) $changeLog[] = ['timestamp' => $dailyChange['date'], 'title' => (string) ($dailyChange['title'] ?? 'Daily log update'), 'detail' => (string) ($dailyChange['impact'] ?? ''), 'type' => (string) ($dailyChange['kind'] ?? 'update')];
        usort($changeLog, static fn(array $a, array $b): int => strcmp((string) $b['timestamp'], (string) $a['timestamp']));
        $seenChanges = []; $changeLog = array_values(array_filter($changeLog, static function (array $change) use (&$seenChanges): bool { $key = substr((string) ($change['timestamp'] ?? ''), 0, 10) . '|' . strtolower(trim((string) ($change['title'] ?? ''))); if (isset($seenChanges[$key])) return false; $seenChanges[$key] = true; return true; }));
        $lastProjectUpdate = $changeLog[0] ?? null;
        $freshness = portal_freshness($lastProjectUpdate['timestamp'] ?? null);
        $updates30d = count(array_filter($changeLog, static fn(array $item): bool => portal_date((string) $item['timestamp'])->getTimestamp() >= time() - 2592000));
        $projectVisitors = (int) ($posthogMetrics[$project['id']]['visitors'] ?? 0);
        $projectRevenue = (float) ($posthogMetrics[$project['id']]['revenue'] ?? 0);
        $activityText = strtolower(implode(' ', array_map(static fn(array $item): string => ($item['title'] ?? '') . ' ' . ($item['detail'] ?? ''), $changeLog)));
        $sessionTrend = $projectSessions[$project['id']] ?? [];
        $sessionColor = ['#195cff', '#187c43', '#6943c6', '#ff6a3d'][$index % 4];
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
        <div class="property-sessions"><div><span>Sessions · 30 days</span><strong><?= $sessionTrend ? number_format(array_sum($sessionTrend)) : '—' ?></strong></div><?php if ($sessionTrend): ?><?= portal_sparkline_svg($sessionTrend, $sessionColor) ?><?php else: ?><small>Session trend unavailable</small><?php endif; ?></div>
        <div class="last-shipped"><span class="health-dot <?= $freshness['tone'] ?>"></span><div><small>Last update shipped</small><strong><?= htmlspecialchars($lastProjectUpdate['title'] ?? 'Nothing logged') ?></strong><em><?= htmlspecialchars($freshness['label']) ?></em></div></div>
        <div class="milestone-track"><div class="milestone-head"><small>Milestones</small><strong><?= $milestonesDone ?>/<?= count($milestones) ?></strong></div><div class="milestone-progress"><i style="width:<?= round(($milestonesDone / count($milestones)) * 100) ?>%"></i></div><ol><?php foreach ($milestones as [$milestoneLabel, $milestoneDone]): ?><li class="<?= $milestoneDone ? 'done' : '' ?>"><i></i><span><?= htmlspecialchars($milestoneLabel) ?></span></li><?php endforeach; ?></ol></div>
        <?php if ($changeLog): ?><div class="mini-log"><small>Recent change log</small><?php foreach (array_slice($changeLog, 0, 3) as $change): ?><div><time><?= htmlspecialchars(portal_format_day((string) $change['timestamp'])) ?></time><span><?= htmlspecialchars((string) $change['title']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
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

    </div>

    <aside class="priority-rail" aria-label="Founder planning rail">
      <section class="priorities-panel">
        <div class="rail-kicker">Ordered queue</div><h2>Things to ship</h2><p>Highest priority first. Managed from the source data.</p>
        <div class="panel-key" aria-label="Priority color key"><span class="key-p0">P0</span><span class="key-p1">P1</span><span class="key-p2">P2</span><span class="key-parked">Parked</span></div>
        <div class="priority-list"><?php if (!$ideas): ?><div class="rail-empty">No priorities recorded.</div><?php endif; ?><?php foreach ($ideas as $idea): $rawPriority = strtoupper((string) ($idea['priority'] ?? '')); $priority = in_array($rawPriority, ['P0','P1','P2'], true) ? $rawPriority : strtoupper((string) ($idea['stage'] ?? $rawPriority ?: 'NEXT')); $priorityClass = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $priority)); ?><article class="priority-<?= htmlspecialchars($priorityClass) ?>"><span class="priority-badge"><?= htmlspecialchars($priority) ?></span><div><strong><?= htmlspecialchars(preg_replace('/^(P\d|PARKED|WATCHLIST)\s*·\s*/i', '', (string) $idea['title'])) ?></strong><small><?= htmlspecialchars((string) ($idea['vertical'] ?? 'Unclassified')) ?><?php if (!empty($idea['ship_by'])): ?> · <?= htmlspecialchars((new DateTimeImmutable($idea['ship_by']))->format('M j')) ?><?php endif; ?></small></div></article><?php endforeach; ?></div>
      </section>
      <section class="coverage-panel">
        <div class="rail-kicker">Completed actions</div><h2>Vertical coverage</h2>
        <div class="coverage-list"><?php if (!$verticalCoverage): ?><div class="rail-empty">No daily-log activity yet.</div><?php endif; ?><?php foreach ($verticalCoverage as $vertical => $count): ?><div><span><?= htmlspecialchars($vertical) ?></span><i><b style="width:<?= $maxVerticalCount ? round(($count / $maxVerticalCount) * 100) : 0 ?>%"></b></i><strong><?= $count ?></strong></div><?php endforeach; ?></div>
      </section>
    </aside></div>

    <section class="section-head log-calendar-heading"><div><span>03</span><h2>Daily logs</h2></div><p>Factual activity from the daily record.</p></section>
    <section class="daily-log-panel">
      <div class="month-calendar"><header><strong><?= htmlspecialchars($calendarAnchor->format('F Y')) ?></strong><span><?= count($dailyLogByDate) ?> active <?= count($dailyLogByDate) === 1 ? 'day' : 'days' ?></span></header><div class="weekdays"><?php foreach (['M','T','W','T','F','S','S'] as $weekday): ?><span><?= $weekday ?></span><?php endforeach; ?></div><div class="calendar-grid"><?php for ($blank = 0; $blank < $calendarLeading; $blank++): ?><span class="calendar-blank"></span><?php endfor; ?><?php for ($dayNumber = 1; $dayNumber <= $calendarDays; $dayNumber++): $date = $calendarStart->format('Y-m-') . str_pad((string) $dayNumber, 2, '0', STR_PAD_LEFT); $dayData = $dailyLogByDate[$date] ?? null; ?><button type="button" class="calendar-day<?= $dayData ? ' has-activity' : '' ?>" data-log-date="<?= $date ?>" <?= $dayData ? '' : 'disabled' ?>><span><?= $dayNumber ?></span><?php if ($dayData): ?><i></i><small><?= count($dayData['entries']) ?></small><?php endif; ?></button><?php endfor; ?></div></div>
      <div class="day-detail" id="day-detail"><?php if (!$dailyLog): ?><div class="empty"><strong>No daily logs yet.</strong><p>Add entries to storage/daily-log.json to build the calendar.</p></div><?php else: ?><?php foreach ($dailyLogByDate as $date => $dayData): ?><section data-log-detail="<?= htmlspecialchars($date) ?>" <?= $date === array_key_first($dailyLogByDate) ? '' : 'hidden' ?>><header><div><span>Selected day</span><h3><?= htmlspecialchars((new DateTimeImmutable($date))->format('D · M j, Y')) ?></h3></div><strong><?= count($dayData['entries']) ?> actions</strong></header><div class="activity-key"><span class="key-shipped">Shipped</span><span class="key-decision">Decision</span><span class="key-problem">Bug / miss</span><span class="key-waste">Waste / killed</span></div><div class="day-entries"><?php foreach ($dayData['entries'] as $entry): $kindClass = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) ($entry['kind'] ?? 'activity'))); ?><article class="kind-<?= htmlspecialchars($kindClass) ?>"><span><b><?= htmlspecialchars((string) ($entry['kind'] ?? 'activity')) ?></b> · <?= htmlspecialchars((string) ($entry['vertical'] ?? 'Unclassified')) ?></span><strong><?= htmlspecialchars((string) ($entry['title'] ?? 'Untitled activity')) ?></strong><small><?= htmlspecialchars((string) ($entry['product'] ?? '—')) ?><?php if (!empty($entry['priority'])): ?> · <?= htmlspecialchars((string) $entry['priority']) ?><?php endif; ?></small></article><?php endforeach; ?></div><?php if ($dayData['tomorrow_target'] !== ''): ?><p class="tomorrow-target"><strong>Next →</strong> <?= htmlspecialchars($dayData['tomorrow_target']) ?></p><?php endif; ?></section><?php endforeach; ?><?php endif; ?></div>
    </section>
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
  <script src="/app.js?v=6" defer></script>
</body>
</html>


