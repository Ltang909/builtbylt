<?php
declare(strict_types=1);

function portal_config(): array
{
    $config = ['password_hash' => getenv('PORTAL_PASSWORD_HASH') ?: '', 'timezone' => getenv('PORTAL_TIMEZONE') ?: 'America/Toronto', 'posthog' => []];
    $privateConfig = dirname(__DIR__) . '/private/builtbylt.php';
    if (is_file($privateConfig)) {
        $values = require $privateConfig;
        if (is_array($values)) $config = array_merge($config, $values);
    }
    if (!in_array($config['timezone'], timezone_identifiers_list(), true)) $config['timezone'] = 'America/Toronto';
    date_default_timezone_set($config['timezone']);
    return $config;
}

function portal_start_session(): void
{
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('builtbylt_portal');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}

function portal_is_authenticated(): bool { return isset($_SESSION['authenticated_at']) && (time() - (int) $_SESSION['authenticated_at']) < 43200; }
function portal_mark_authenticated(): void { session_regenerate_id(true); $_SESSION = ['authenticated_at' => time()]; }
function portal_logout(): void { $_SESSION = []; if (ini_get('session.use_cookies')) setcookie(session_name(), '', time() - 3600, '/'); session_destroy(); }
function portal_login_allowed(): bool { return !isset($_SESSION['login_blocked_until']) || time() >= (int) $_SESSION['login_blocked_until']; }
function portal_record_failed_login(): void { $attempts = ((int) ($_SESSION['login_attempts'] ?? 0)) + 1; $_SESSION['login_attempts'] = $attempts; if ($attempts >= 5) { $_SESSION['login_blocked_until'] = time() + 60; $_SESSION['login_attempts'] = 0; } }

function portal_require_auth(): void
{
    portal_start_session();
    if (!portal_is_authenticated()) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => 'Session expired. Refresh and unlock the console.']); exit; }
}

function portal_projects(): array
{
    return [
        ['id' => 'loom-ish', 'name' => 'Loom-ish', 'domain' => 'loom-ish.builtbylt.com', 'status' => 'live', 'phase' => 'Product build', 'note' => 'Fast, focused async video without the enterprise drag.'],
        ['id' => 'wellfinder', 'name' => 'WellFinder', 'domain' => 'wellfinder.ca', 'status' => 'live', 'phase' => 'Live / validate', 'note' => 'Live in market. Measure real usage and learn from demand before expanding it.'],
        ['id' => 'leontang', 'name' => 'leontang.ca', 'domain' => 'leontang.ca', 'status' => 'live', 'phase' => 'Live / optimize', 'note' => 'Live chat, sharper copy, and product-quality fixes shipped. Monitor visitor response and keep tightening conversion.'],
        ['id' => 'staging', 'name' => 'Staging', 'domain' => 'staging.leontang.ca', 'status' => 'live', 'phase' => 'Workshop', 'note' => 'The proving ground before anything earns a public URL.'],
    ];
}

function portal_storage_path(): string { return __DIR__ . '/storage/activity.json'; }
function portal_read_activity(): array
{
    $path = portal_storage_path();
    if (!is_file($path)) return [];
    $handle = fopen($path, 'rb'); if (!$handle) return [];
    flock($handle, LOCK_SH); $raw = stream_get_contents($handle); flock($handle, LOCK_UN); fclose($handle);
    $items = json_decode($raw ?: '[]', true); if (!is_array($items)) return [];
    usort($items, static fn(array $a, array $b): int => strcmp($b['timestamp'], $a['timestamp']));
    return array_slice($items, 0, 250);
}
function portal_activity_for_project(string $projectId, array $activity): array { return array_values(array_filter($activity, static fn(array $item): bool => $item['project'] === $projectId)); }
function portal_freshness(?string $timestamp): array
{
    if (!$timestamp) return ['tone' => 'red', 'label' => 'No updates yet'];
    $hours = max(0, (int) floor((time() - portal_date($timestamp)->getTimestamp()) / 3600));
    $label = $hours < 1 ? 'Shipped just now' : ($hours < 48 ? "Shipped {$hours}h ago" : 'Shipped ' . floor($hours / 24) . 'd ago');
    if ($hours <= 48) return ['tone' => 'green', 'label' => $label]; if ($hours <= 94) return ['tone' => 'yellow', 'label' => $label]; return ['tone' => 'red', 'label' => $label];
}
function portal_posthog_metrics(array $projects): array
{
    $config = portal_config()['posthog'] ?? []; $empty = array_fill_keys(array_column($projects, 'id'), ['views' => null, 'visitors' => null, 'revenue' => null]);
    if (empty($config['personal_api_key']) || empty($config['project_id'])) return $empty;
    $cachePath = __DIR__ . '/storage/posthog-metrics.json'; if (is_file($cachePath) && filemtime($cachePath) > time() - 600) { $cached = json_decode((string) file_get_contents($cachePath), true); if (is_array($cached)) return array_replace($empty, $cached); }
    $domainToId = []; foreach ($projects as $project) $domainToId[$project['domain']] = $project['id'];
    $quoted = implode(',', array_map(static fn(string $domain): string => "'" . str_replace("'", "''", $domain) . "'", array_keys($domainToId)));
    $sql = "SELECT properties.\$host AS host, countIf(event = '\$pageview') AS views, uniqExactIf(distinct_id, event = '\$pageview') AS visitors, sum(toFloatOrZero(properties.revenue)) AS revenue FROM events WHERE timestamp >= now() - INTERVAL 30 DAY AND properties.\$host IN ({$quoted}) GROUP BY host LIMIT 20";
    $body = json_encode(['query' => ['kind' => 'HogQLQuery', 'query' => $sql], 'name' => 'Built by LT portfolio metrics']); $host = rtrim((string) ($config['api_host'] ?? 'https://us.posthog.com'), '/');
    $response = portal_http_post("{$host}/api/projects/" . rawurlencode((string) $config['project_id']) . '/query/', $body, ['Authorization: Bearer ' . $config['personal_api_key']]); if (!$response) return $empty;
    $decoded = json_decode($response, true); if (!isset($decoded['results']) || !is_array($decoded['results'])) return $empty; $metrics = $empty;
    foreach ($decoded['results'] as $row) if (isset($row[0], $domainToId[$row[0]])) $metrics[$domainToId[$row[0]]] = ['views' => (int) ($row[1] ?? 0), 'visitors' => (int) ($row[2] ?? 0), 'revenue' => (float) ($row[3] ?? 0)];
    @file_put_contents($cachePath, json_encode($metrics), LOCK_EX); return $metrics;
}
function portal_posthog_capture(array $item): void
{
    $config = portal_config()['posthog'] ?? []; if (empty($config['project_api_key'])) return; $host = rtrim((string) ($config['capture_host'] ?? 'https://us.i.posthog.com'), '/');
    $payload = json_encode(['api_key' => $config['project_api_key'], 'event' => 'update_shipped', 'distinct_id' => 'builtbylt-founder', 'timestamp' => $item['timestamp'], 'properties' => ['business' => $item['project'], 'update_type' => $item['type'], 'title' => $item['title'], 'source' => 'builtbylt_portal']]); portal_http_post("{$host}/capture/", $payload, [], 3);
}
function portal_posthog_trends(array $projects): array
{
    $config = portal_config()['posthog'] ?? []; $empty = ['views' => [], 'revenue' => [], 'visitors' => []]; if (empty($config['personal_api_key']) || empty($config['project_id'])) return $empty;
    $cachePath = __DIR__ . '/storage/posthog-trends.json'; if (is_file($cachePath) && filemtime($cachePath) > time() - 600) { $cached = json_decode((string) file_get_contents($cachePath), true); if (is_array($cached)) return array_replace($empty, $cached); }
    $quoted = implode(',', array_map(static fn(array $project): string => "'" . str_replace("'", "''", $project['domain']) . "'", $projects));
    $sql = "SELECT toDate(timestamp) AS day, countIf(event = '\$pageview') AS views, uniqExactIf(distinct_id, event = '\$pageview') AS visitors, sum(toFloatOrZero(properties.revenue)) AS revenue FROM events WHERE timestamp >= now() - INTERVAL 30 DAY AND properties.\$host IN ({$quoted}) GROUP BY day ORDER BY day LIMIT 31";
    $body = json_encode(['query' => ['kind' => 'HogQLQuery', 'query' => $sql], 'name' => 'Built by LT portfolio trends']); $host = rtrim((string) ($config['api_host'] ?? 'https://us.posthog.com'), '/'); $response = portal_http_post("{$host}/api/projects/" . rawurlencode((string) $config['project_id']) . '/query/', $body, ['Authorization: Bearer ' . $config['personal_api_key']]); if (!$response) return $empty;
    $decoded = json_decode($response, true); if (!isset($decoded['results']) || !is_array($decoded['results'])) return $empty; $trends = $empty; foreach ($decoded['results'] as $row) { $trends['views'][] = (float) ($row[1] ?? 0); $trends['visitors'][] = (float) ($row[2] ?? 0); $trends['revenue'][] = (float) ($row[3] ?? 0); } @file_put_contents($cachePath, json_encode($trends), LOCK_EX); return $trends;
}
function portal_sparkline_svg(array $values, string $color): string
{
    if (!$values) $values = [0, 0]; if (count($values) === 1) $values[] = $values[0]; $width = 240; $height = 54; $max = max($values); $min = min($values); $range = max(1, $max - $min); $last = count($values) - 1; $points = []; foreach ($values as $index => $value) { $points[] = round(($index / $last) * $width, 1) . ',' . round($height - 4 - ((($value - $min) / $range) * ($height - 8)), 1); } return '<svg class="metric-sparkline" viewBox="0 0 240 54" preserveAspectRatio="none" aria-hidden="true"><line x1="0" y1="50" x2="240" y2="50" stroke="currentColor" opacity=".15"/><polyline points="' . implode(' ', $points) . '" fill="none" stroke="' . htmlspecialchars($color) . '" stroke-width="3" vector-effect="non-scaling-stroke"/></svg>';
}
function portal_http_post(string $url, string $body, array $headers = [], int $timeout = 8): ?string { if (!function_exists('curl_init')) return null; $curl = curl_init($url); if (!$curl) return null; curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]); $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl); return is_string($response) && $status >= 200 && $status < 300 ? $response : null; }
function portal_tasks_path(): string { return __DIR__ . '/storage/tasks.json'; }
function portal_read_tasks(): array { $path = portal_tasks_path(); if (!is_file($path)) return []; $tasks = json_decode((string) file_get_contents($path), true); return is_array($tasks) ? $tasks : []; }
function portal_ideas_path(): string { return __DIR__ . '/storage/ideas.json'; }
function portal_read_ideas(): array { $path = portal_ideas_path(); if (!is_file($path)) return []; $ideas = json_decode((string) file_get_contents($path), true); return is_array($ideas) ? $ideas : []; }
function portal_write_ideas(array $ideas): bool { return file_put_contents(portal_ideas_path(), json_encode(array_values($ideas), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false; }
function portal_daily_log_path(): string { return __DIR__ . '/storage/daily-log.json'; }
function portal_read_daily_log(): array
{
    $path = portal_daily_log_path(); if (!is_file($path)) return [];
    $days = json_decode((string) file_get_contents($path), true); if (!is_array($days)) return [];
    $days = array_values(array_filter($days, static fn($day): bool => is_array($day) && !empty($day['date'])));
    usort($days, static fn(array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));
    return $days;
}
function portal_posthog_project_sessions(array $projects): array
{
    $config = portal_config()['posthog'] ?? []; $empty = array_fill_keys(array_column($projects, 'id'), []);
    if (empty($config['personal_api_key']) || empty($config['project_id'])) return $empty;
    $cachePath = __DIR__ . '/storage/posthog-project-sessions.json';
    if (is_file($cachePath) && filemtime($cachePath) > time() - 600) { $cached = json_decode((string) file_get_contents($cachePath), true); if (is_array($cached)) return array_replace($empty, $cached); }
    $domainToId = []; foreach ($projects as $project) $domainToId[$project['domain']] = $project['id'];
    $quoted = implode(',', array_map(static fn(string $domain): string => "'" . str_replace("'", "''", $domain) . "'", array_keys($domainToId)));
    $sql = "SELECT toDate(timestamp) AS day, properties.\$host AS host, uniqExactIf(properties.\$session_id, event = '\$pageview') AS sessions FROM events WHERE timestamp >= now() - INTERVAL 30 DAY AND properties.\$host IN ({$quoted}) GROUP BY day, host ORDER BY day LIMIT 500";
    $body = json_encode(['query' => ['kind' => 'HogQLQuery', 'query' => $sql], 'name' => 'Built by LT property sessions']);
    $host = rtrim((string) ($config['api_host'] ?? 'https://us.posthog.com'), '/');
    $response = portal_http_post("{$host}/api/projects/" . rawurlencode((string) $config['project_id']) . '/query/', $body, ['Authorization: Bearer ' . $config['personal_api_key']]); if (!$response) return $empty;
    $decoded = json_decode($response, true); if (!isset($decoded['results']) || !is_array($decoded['results'])) return $empty;
    $byProjectDay = []; foreach ($decoded['results'] as $row) if (isset($row[0], $row[1], $domainToId[$row[1]])) $byProjectDay[$domainToId[$row[1]]][(string) $row[0]] = (int) ($row[2] ?? 0);
    $sessions = $empty; $start = new DateTimeImmutable('-29 days');
    foreach ($projects as $project) for ($offset = 0; $offset < 30; $offset++) { $day = $start->modify("+{$offset} days")->format('Y-m-d'); $sessions[$project['id']][] = (int) ($byProjectDay[$project['id']][$day] ?? 0); }
    @file_put_contents($cachePath, json_encode($sessions), LOCK_EX); return $sessions;
}
function portal_calendar_events(): array
{
    $url = (string) (portal_config()['calendar_ics_url'] ?? ''); if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return []; $context = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'BuiltByLT/1.0']]); $raw = @file_get_contents($url, false, $context); if (!$raw) return []; preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $raw, $matches); $events = [];
    foreach ($matches[1] ?? [] as $block) { preg_match('/DTSTART[^:]*:(\d{8})(?:T(\d{6})Z?)?/', $block, $date); preg_match('/SUMMARY:(.*)/', $block, $summary); if (!$date || !$summary) continue; $stamp = $date[1] . ($date[2] ?? '090000'); $eventDate = DateTimeImmutable::createFromFormat('YmdHis', $stamp, new DateTimeZone(portal_config()['timezone'])); if ($eventDate && $eventDate->getTimestamp() >= time() - 86400) $events[] = ['title' => trim(str_replace(['\\,','\\n'], [',',' '], $summary[1])), 'timestamp' => $eventDate->format(DateTimeInterface::ATOM)]; } usort($events, static fn(array $a, array $b): int => strcmp($a['timestamp'], $b['timestamp'])); return array_slice($events, 0, 6);
}
function portal_write_tasks(array $tasks): bool { $directory = dirname(portal_tasks_path()); if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return false; return file_put_contents(portal_tasks_path(), json_encode(array_values($tasks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false; }
function portal_append_activity(array $item): bool
{
    $directory = dirname(portal_storage_path()); if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return false; $handle = fopen(portal_storage_path(), 'c+'); if (!$handle) return false; if (!flock($handle, LOCK_EX)) { fclose($handle); return false; } $raw = stream_get_contents($handle); $items = json_decode($raw ?: '[]', true); if (!is_array($items)) $items = []; array_unshift($items, $item); $items = array_slice($items, 0, 250); rewind($handle); ftruncate($handle, 0); $ok = fwrite($handle, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false; fflush($handle); flock($handle, LOCK_UN); fclose($handle); return $ok;
}
function portal_project_name(string $id, array $projects): string { foreach ($projects as $project) if ($project['id'] === $id) return $project['name']; return 'Unknown'; }
function portal_date(string $timestamp): DateTimeImmutable { try { return new DateTimeImmutable($timestamp); } catch (Throwable $e) { return new DateTimeImmutable(); } }
function portal_format_day(string $timestamp): string { return portal_date($timestamp)->format('M j'); }
function portal_format_time(string $timestamp): string { return portal_date($timestamp)->format('g:i a'); }
function portal_make_id(): string { return bin2hex(random_bytes(8)); }

function portal_render_login(bool $setupRequired, ?string $error): void
{
    http_response_code($setupRequired ? 503 : 200); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Built by LT — Private</title><link rel="stylesheet" href="/app.css?v=4"></head><body class="login-page"><div class="grain"></div><main class="lock-shell"><div class="lock-mark"><span>BUILT</span><i>by</i><span>LT</span></div><div class="lock-copy"><p class="eyebrow">Private founder console</p><h1>Not for<br><em>spectators.</em></h1><p>Ship. Measure. Decide what deserves another day.</p></div><form class="lock-form" method="post"><input type="hidden" name="action" value="login"><label for="password">Access key</label><div><input id="password" name="password" type="password" autocomplete="current-password" autofocus <?= $setupRequired ? 'disabled' : '' ?>><button <?= $setupRequired ? 'disabled' : '' ?>>Enter →</button></div><?php if ($setupRequired): ?><p class="lock-error">Setup required: add a password hash outside public_html before this console can unlock.</p><?php elseif ($error): ?><p class="lock-error"><?= htmlspecialchars($error) ?></p><?php endif; ?></form><footer>builtbylt.com · <?= date('Y') ?></footer></main></body></html><?php
}

