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
        ['id' => 'loom-ish', 'name' => 'Loom-ish', 'domain' => 'loom-ish.builtbylt.com', 'status' => 'active', 'phase' => 'Product build', 'note' => 'Fast, focused async video without the enterprise drag.'],
        ['id' => 'wellfinder', 'name' => 'WellFinder', 'domain' => 'wellfinder.ca', 'status' => 'active', 'phase' => 'Validation', 'note' => 'Making the path to the right wellness support less opaque.'],
        ['id' => 'leontang', 'name' => 'leontang.ca', 'domain' => 'leontang.ca', 'status' => 'live', 'phase' => 'Portfolio', 'note' => 'The public front door: selected work, point of view, contact.'],
        ['id' => 'staging', 'name' => 'Staging', 'domain' => 'staging.leontang.ca', 'status' => 'active', 'phase' => 'Workshop', 'note' => 'The proving ground before anything earns a public URL.'],
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

function portal_activity_for_project(string $projectId, array $activity): array
{
    return array_values(array_filter($activity, static fn(array $item): bool => $item['project'] === $projectId));
}

function portal_freshness(?string $timestamp): array
{
    if (!$timestamp) return ['tone' => 'red', 'label' => 'No updates yet'];
    $days = max(0, (int) floor((time() - portal_date($timestamp)->getTimestamp()) / 86400));
    if ($days <= 7) return ['tone' => 'green', 'label' => $days === 0 ? 'Shipped today' : "Shipped {$days}d ago"];
    if ($days <= 14) return ['tone' => 'yellow', 'label' => "Shipped {$days}d ago"];
    return ['tone' => 'red', 'label' => "Shipped {$days}d ago"];
}

function portal_posthog_metrics(array $projects): array
{
    $config = portal_config()['posthog'] ?? [];
    $empty = array_fill_keys(array_column($projects, 'id'), ['views' => null, 'visitors' => null]);
    if (empty($config['personal_api_key']) || empty($config['project_id'])) return $empty;
    $cachePath = __DIR__ . '/storage/posthog-metrics.json';
    if (is_file($cachePath) && filemtime($cachePath) > time() - 600) {
        $cached = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($cached)) return array_replace($empty, $cached);
    }
    $domainToId = []; foreach ($projects as $project) $domainToId[$project['domain']] = $project['id'];
    $quoted = implode(',', array_map(static fn(string $domain): string => "'" . str_replace("'", "''", $domain) . "'", array_keys($domainToId)));
    $sql = "SELECT properties.\$host AS host, count() AS views, uniqExact(distinct_id) AS visitors FROM events WHERE event = '\$pageview' AND timestamp >= now() - INTERVAL 30 DAY AND properties.\$host IN ({$quoted}) GROUP BY host LIMIT 20";
    $body = json_encode(['query' => ['kind' => 'HogQLQuery', 'query' => $sql], 'name' => 'Built by LT portfolio metrics']);
    $host = rtrim((string) ($config['api_host'] ?? 'https://us.posthog.com'), '/');
    $response = portal_http_post("{$host}/api/projects/" . rawurlencode((string) $config['project_id']) . '/query/', $body, ['Authorization: Bearer ' . $config['personal_api_key']]);
    if (!$response) return $empty;
    $decoded = json_decode($response, true); if (!isset($decoded['results']) || !is_array($decoded['results'])) return $empty;
    $metrics = $empty;
    foreach ($decoded['results'] as $row) if (isset($row[0], $domainToId[$row[0]])) $metrics[$domainToId[$row[0]]] = ['views' => (int) ($row[1] ?? 0), 'visitors' => (int) ($row[2] ?? 0)];
    @file_put_contents($cachePath, json_encode($metrics), LOCK_EX);
    return $metrics;
}

function portal_posthog_capture(array $item): void
{
    $config = portal_config()['posthog'] ?? [];
    if (empty($config['project_api_key'])) return;
    $host = rtrim((string) ($config['capture_host'] ?? 'https://us.i.posthog.com'), '/');
    $payload = json_encode(['api_key' => $config['project_api_key'], 'event' => 'update_shipped', 'distinct_id' => 'builtbylt-founder', 'timestamp' => $item['timestamp'], 'properties' => ['business' => $item['project'], 'update_type' => $item['type'], 'title' => $item['title'], 'source' => 'builtbylt_portal']]);
    portal_http_post("{$host}/capture/", $payload, [], 3);
}

function portal_http_post(string $url, string $body, array $headers = [], int $timeout = 8): ?string
{
    if (!function_exists('curl_init')) return null;
    $curl = curl_init($url); if (!$curl) return null;
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    $response = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    return is_string($response) && $status >= 200 && $status < 300 ? $response : null;
}

function portal_append_activity(array $item): bool
{
    $directory = dirname(portal_storage_path());
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return false;
    $handle = fopen(portal_storage_path(), 'c+'); if (!$handle) return false;
    if (!flock($handle, LOCK_EX)) { fclose($handle); return false; }
    $raw = stream_get_contents($handle); $items = json_decode($raw ?: '[]', true); if (!is_array($items)) $items = [];
    array_unshift($items, $item); $items = array_slice($items, 0, 250);
    rewind($handle); ftruncate($handle, 0); $ok = fwrite($handle, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false; fflush($handle); flock($handle, LOCK_UN); fclose($handle);
    return $ok;
}

function portal_project_name(string $id, array $projects): string { foreach ($projects as $project) if ($project['id'] === $id) return $project['name']; return 'Unknown'; }
function portal_date(string $timestamp): DateTimeImmutable { try { return new DateTimeImmutable($timestamp); } catch (Throwable $e) { return new DateTimeImmutable(); } }
function portal_format_day(string $timestamp): string { return portal_date($timestamp)->format('M j'); }
function portal_format_time(string $timestamp): string { return portal_date($timestamp)->format('g:i A'); }
function portal_relative_time(string $timestamp): string { $seconds = max(0, time() - portal_date($timestamp)->getTimestamp()); if ($seconds < 60) return 'now'; if ($seconds < 3600) return floor($seconds / 60) . 'm ago'; if ($seconds < 86400) return floor($seconds / 3600) . 'h ago'; return floor($seconds / 86400) . 'd ago'; }

function portal_render_login(bool $setupRequired, ?string $error): void
{
    http_response_code($setupRequired ? 503 : 200);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Built by LT — Private</title><link rel="stylesheet" href="/app.css?v=1"></head><body class="login-page"><div class="grain"></div><main class="login-shell"><div class="login-mark"><span>BUILT</span><i>by</i><span>LT</span></div><section class="login-panel"><p class="eyebrow">Private founder console</p><h1>The work<br>behind the work.</h1><?php if ($setupRequired): ?><div class="setup-notice"><strong>One last setup step.</strong><p>Add a password hash outside the public web directory, then reload. The exact two-minute setup is in <code>README.md</code>.</p></div><?php else: ?><form method="post" class="login-form"><input type="hidden" name="action" value="login"><label for="password">Access key</label><div><input id="password" name="password" type="password" autocomplete="current-password" autofocus required placeholder="Enter password"><button class="button button-accent">Unlock →</button></div><?php if ($error): ?><p class="form-error" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?></form><?php endif; ?><footer><span>builtbylt.com</span><span>Eyes only</span></footer></section></main></body></html><?php
}

