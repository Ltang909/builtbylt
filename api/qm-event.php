<?php
declare(strict_types=1);

/**
 * Quartermaster buyer-dashboard data endpoint (read-only).
 *
 * Lets the existing dashboard page populate itself from live Attio data
 * instead of baked-in sample events:
 *
 *   GET /api/qm-event.php?slug=nov-1[&key=<signed>]
 *
 * Chain: slug -> deal (matches the deal's `slug` text attribute)
 *       -> deal.guest_list_id -> people list -> entries (guests + list attrs)
 *       -> person + company records.
 *
 * Returns the dashboard's event object as JSON: { ok:true, event:{...} }.
 *
 * Activation model (admin side is 100% Attio, no separate admin UI):
 * - Deals carry a `dashboard_status` select: Draft / Active / Archived.
 * - This endpoint only serves deals whose status is Active. If the
 *   attribute does not exist yet on a deal, the deal is served (lets the
 *   proof run before the attribute is added).
 * - Flipping Draft -> Active in Attio IS the publish button. The buyer's
 *   page picks it up with no code changes.
 *
 * Buyer scoping:
 * - Tier 1 (now): signed per-event links. With `qm_require_signed_keys`
 *   enabled in private config, every request needs ?key=HMAC(record_id),
 *   so each buyer only ever sees the events they were sent links for.
 * - Tier 2 (later): email login matching associated contacts. Not built.
 *
 * Security: GET only, slug allow-listed, API key never leaves the server
 * (private/builtbylt.php via portal_config()), per-IP throttle, CORS
 * allow-list, only dashboard-needed fields are exposed.
 */

require dirname(__DIR__) . '/lib.php';

header('Content-Type: application/json; charset=utf-8');

$config = portal_config();
$apiKey = (string)($config['attio_api_key'] ?? '');
$secret = (string)($config['qm_dashboard_secret'] ?? '');
$requireKeys = !empty($config['qm_require_signed_keys']);
$allowedOrigins = $config['qm_allowed_origins'] ?? [
    'https://www.quartermaster.studio',
    'https://quartermaster.studio',
    'https://builtbylt.com',
];

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

function qm_fail(int $code, string $error, string $hint = ''): void
{
    http_response_code($code);
    $out = ['ok' => false, 'error' => $error];
    if ($hint !== '') $out['hint'] = $hint;
    echo json_encode($out);
    exit;
}

function qm_throttle(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/qm-event-rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . md5($ip) . '.json';
    $now = time();
    $data = ['t' => $now, 'n' => 0];
    if (is_file($file)) {
        $d = json_decode((string)@file_get_contents($file), true);
        if (is_array($d) && isset($d['t'], $d['n'])) $data = $d;
    }
    if ($now - (int)$data['t'] > 3600) $data = ['t' => $now, 'n' => 0];
    $data['n'] = (int)$data['n'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return $data['n'] <= 240;
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!qm_throttle($ip)) qm_fail(429, 'rate_limited');

$slug = (string)($_GET['slug'] ?? '');
if (!preg_match('/^[a-z0-9-]{1,64}$/', $slug)) qm_fail(400, 'bad_slug');

if ($apiKey === '') {
    qm_fail(500, 'attio_not_configured',
        "Add 'attio_api_key' to private/builtbylt.php (see private-config.example.php).");
}

// --- short cache: one Attio fan-out serves every buyer for 5 minutes ---
$cacheDir = sys_get_temp_dir() . '/qm-event-cache';
$cacheFile = $cacheDir . '/' . md5($slug) . '.json';
$cacheTtl = 300; // 5 minutes
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $cached = @file_get_contents($cacheFile);
    if ($cached !== false && $cached !== '') {
        echo $cached;
        exit;
    }
}

function attio(string $apiKey, string $method, string $path, $body = null): array
{
    $ch = curl_init('https://api.attio.com' . $path);
    $headers = ['Authorization: Bearer ' . $apiKey, 'Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    return [$code, is_array($data) ? $data : []];
}

/** First plain value from an Attio values-dict, handling select/name/ref shapes. */
function v1(array $values, string $attr): string
{
    $items = $values[$attr] ?? [];
    if (!is_array($items) || !$items) return '';
    $it = $items[0] ?? [];
    if (!is_array($it)) return '';
    // select attributes carry the choice under 'option', not 'value'
    $opt = $it['option'] ?? null;
    if (is_array($opt) && !empty($opt['title'])) return (string)$opt['title'];
    $v = $it['value'] ?? null;
    if (is_array($v)) {
        return (string)($v['title'] ?? $v['full_name'] ?? $v['name'] ?? '');
    }
    return $v === null ? '' : (string)$v;
}

function person_name(array $values): string
{
    // personal-name type puts components top-level on the item, not under 'value'
    $items = $values['name'] ?? [];
    $it = (is_array($items) && count($items)) ? $items[0] : [];
    if (!is_array($it)) return 'Unknown';
    $v = (isset($it['value']) && is_array($it['value'])) ? $it['value'] : [];
    $full = (string)($it['full_name'] ?? $v['full_name'] ?? '');
    if ($full !== '') return $full;
    $first = (string)($it['first_name'] ?? $v['first_name'] ?? '');
    $last = (string)($it['last_name'] ?? $v['last_name'] ?? '');
    $full = trim($first . ' ' . $last);
    return $full !== '' ? $full : 'Unknown';
}

/** record-reference puts target_record_id top-level, not under 'value'. */
function record_ref_id($item): string
{
    if (!is_array($item)) return '';
    $v = $item['value'] ?? null;
    if (is_array($v) && !empty($v['target_record_id'])) return (string)$v['target_record_id'];
    return (string)($item['target_record_id'] ?? '');
}

// --- 1. find the deal by slug ---
[$code, $dealsResp] = attio($apiKey, 'POST', '/v2/objects/deals/records/query', ['limit' => 100]);
if ($code !== 200) qm_fail(502, 'attio_error', 'deal query failed');
$deal = null;
foreach (($dealsResp['data'] ?? []) as $d) {
    if (v1($d['values'] ?? [], 'slug') === $slug) { $deal = $d; break; }
}
if ($deal === null) qm_fail(404, 'event_not_found');

$dealValues = $deal['values'] ?? [];
$recordId = '';
if (is_array($deal['id'] ?? null)) $recordId = (string)($deal['id']['record_id'] ?? '');
if ($recordId === '') $recordId = (string)($deal['record_id'] ?? '');
// fall back: some responses nest the id differently
if ($recordId === '' && isset($deal['id']['record_id'])) $recordId = (string)$deal['id']['record_id'];

// --- 2. activation gate: only Active deals are served ---
$status = v1($dealValues, 'dashboard_status');
if ($status !== '' && strcasecmp($status, 'Active') !== 0) {
    qm_fail(404, 'not_published', 'This event is not active on the buyer dashboard.');
}

// --- 3. signed-key check (tier-1 buyer scoping) ---
if ($requireKeys) {
    if ($secret === '') qm_fail(500, 'keys_not_configured');
    $key = (string)($_GET['key'] ?? '');
    $expected = hash_hmac('sha256', $recordId !== '' ? $recordId : $slug, $secret);
    if ($key === '' || !hash_equals($expected, $key)) qm_fail(403, 'bad_key');
}

$dealName   = v1($dealValues, 'name');
$city       = v1($dealValues, 'city');
$planType   = v1($dealValues, 'plan_type');
$guestCount = v1($dealValues, 'guest_count');
$targetDate = v1($dealValues, 'target_date_3');
$listId     = v1($dealValues, 'guest_list_id');
$capacity   = is_numeric($guestCount) ? (int)$guestCount : 0;

$guests = [];
$linked = false;
if ($listId !== '') {
    [$code, $entriesResp] = attio($apiKey, 'POST', '/v2/lists/' . $listId . '/entries/query', ['limit' => 100]);
    if ($code === 403) {
        qm_fail(502, 'list_entries_forbidden',
            'Grant "Read access to the List Entries scope" at Attio Workspace settings -> Developers -> integration -> Scopes.');
    }
    if ($code !== 200) qm_fail(502, 'attio_error', 'list entries query failed');
    $linked = true;

    // Fetch the person record behind each entry (entries carry only
    // parent_record_id + entry_values, not the record itself).
    $people = [];
    foreach (($entriesResp['data'] ?? []) as $e) {
        $pid = (string)($e['parent_record_id'] ?? '');
        if ($pid === '' || isset($people[$pid])) continue;
        [$pc, $pResp] = attio($apiKey, 'GET', '/v2/objects/people/records/' . rawurlencode($pid));
        $people[$pid] = ($pc === 200) ? ($pResp['data']['values'] ?? []) : [];
    }

    $companyIds = [];
    foreach ($people as $rv) {
        $cItems = $rv['company'] ?? [];
        if (is_array($cItems) && $cItems) {
            $tid = record_ref_id($cItems[0]);
            if ($tid !== '') $companyIds[$tid] = true;
        }
    }
    $companyNames = []; $companyBlurbs = [];
    foreach (array_keys($companyIds) as $cid) {
        [$cc, $cResp] = attio($apiKey, 'GET', '/v2/objects/companies/records/' . rawurlencode($cid));
        if ($cc === 200) {
            $cv = $cResp['data']['values'] ?? [];
            $companyNames[$cid] = v1($cv, 'name');
            $companyBlurbs[$cid] = v1($cv, 'description');
        }
    }

    foreach (($entriesResp['data'] ?? []) as $e) {
        $pid = (string)($e['parent_record_id'] ?? '');
        $rv = $people[$pid] ?? [];
        $lv = $e['entry_values'] ?? [];
        $cid = '';
        $cItems = $rv['company'] ?? [];
        if (is_array($cItems) && $cItems) $cid = record_ref_id($cItems[0]);
        $follow = v1($lv, 'follow_up_state');
        // Translate Attio's Attendee Status vocabulary into the dashboard's:
        // Confirmed / Awaiting / Declined / Attended / No-show.
        $statusRaw = v1($lv, 'attendee_status');
        $statusKey = strtolower(trim($statusRaw));
        $statusMap = [
            'accepted'                   => 'Confirmed',
            'pre event notes shared'     => 'Confirmed',
            'invited'                    => 'Awaiting',
            'responded/not yet accepted' => 'Awaiting',
            'attended'                   => 'Attended',
            'no-showed'                  => 'No-show',
            'declined'                   => 'Declined',
        ];
        $status = $statusMap[$statusKey] ?? ($statusRaw !== '' ? $statusRaw : 'Unknown');
        // "Pre Event Notes Shared" means the brief went out: count it as done.
        $fl = strtolower($follow . ' ' . $statusRaw);
        $followState = 'pending';
        foreach (['shar', 'sent', 'done', 'complet'] as $k) {
            if (str_contains($fl, $k)) { $followState = 'done'; break; }
        }
        if ($follow === '' && $followState === 'done') $follow = 'Brief shared';
        $pointsRaw = v1($lv, 'conversation_points');
        $points = [];
        foreach (preg_split('/\r\n|\r|\n/', $pointsRaw) as $line) {
            $line = trim($line, " \t-•");
            if ($line !== '') $points[] = $line;
        }
        $seatRaw = v1($lv, 'seat_number');
        $guests[] = [
            'name'        => person_name($rv),
            'title'       => v1($rv, 'job_title'),
            'company'     => $cid !== '' ? ($companyNames[$cid] ?? '') : '',
            'status'      => $status,
            'seat'        => $seatRaw !== '' ? $seatRaw : null,
            'follow'      => $follow,
            'followState' => $followState,
            'co'          => $cid !== '' ? ($companyBlurbs[$cid] ?? '') : '',
            'why'         => v1($lv, 'briefing_why'),
            'points'      => $points,
        ];
    }
    usort($guests, function ($a, $b) {
        $as = $a['seat'] === null; $bs = $b['seat'] === null;
        if ($as !== $bs) return $as <=> $bs;
        if (!$as && $a['seat'] != $b['seat']) return $a['seat'] <=> $b['seat'];
        return strcmp($a['name'], $b['name']);
    });
}
if ($capacity <= 0) $capacity = count($guests);

$event = [
    'id'          => $slug,
    'city'        => $city !== '' ? $city : 'TBD',
    'tabMeta'     => $targetDate !== '' ? $targetDate : 'Date TBD',
    'status'      => 'upcoming',
    'statusLabel' => $status !== '' ? $status : 'Upcoming',
    'buyer'       => $dealName !== '' ? $dealName : $slug,
    'title'       => $dealName !== '' ? $dealName : $slug,
    'sub'         => 'Live from Attio.',
    'dateLabel'   => $targetDate !== '' ? $targetDate : 'Date TBD',
    'timeLabel'   => 'TBD',
    'venueLabel'  => 'TBD',
    'planLabel'   => trim(($planType !== '' ? $planType : 'TBD') . ' · ' . $capacity . ' seats'),
    'slug'        => $slug,
    'img'         => '',
    'imgAlt'      => '',
    'capacity'    => $capacity,
    'guests'      => $guests,
    'meta'        => ['guest_list_linked' => $linked, 'guest_count' => count($guests)],
];

$out = json_encode(['ok' => true, 'event' => $event]);
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
@file_put_contents($cacheFile, $out, LOCK_EX);
echo $out;
