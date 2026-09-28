<?php
declare(strict_types=1);

/**
 * Attio relay for the quartermaster.studio contact form.
 *
 * Attio's incoming webhook does not accept cross-site browser posts
 * (its CORS policy only allows https://app.attio.com), so the
 * Squarespace form POSTs here instead and this script forwards the
 * payload to Attio server-side, where CORS does not apply.
 *
 *   POST /api/attio-relay.php   {"name","email","company","message",
 *                               "city","plan_type","target_date","guest_count"}
 *
 * Security: the destination is hardcoded (not an open relay), only the
 * expected fields are forwarded with length caps, name/email/target
 * date/guest count/city are mandatory, personal email providers are
 * rejected, and submissions are throttled per IP.
 */

$PERSONAL_DOMAINS = [
    'gmail.com', 'googlemail.com',
    'yahoo.com', 'yahoo.ca', 'yahoo.co.uk', 'ymail.com',
    'hotmail.com', 'hotmail.ca', 'hotmail.co.uk',
    'outlook.com', 'outlook.ca', 'live.com', 'live.ca', 'msn.com',
    'aol.com', 'icloud.com', 'me.com', 'mac.com',
    'protonmail.com', 'proton.me', 'pm.me',
    'gmx.com', 'gmx.net', 'mail.com', 'yandex.com',
];

$ALLOWED_ORIGINS = [
    'https://www.quartermaster.studio',
    'https://quartermaster.studio',
];
$ATTIO_WEBHOOK = 'https://hooks.attio.com/w/273e79ee-fa63-4cc1-9451-70a764f4737a/af6babff-46a7-46bf-a92f-3389a9e30023';

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

function rl_throttle(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/attio-relay-rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . md5($ip) . '.json';
    $now = time();
    $window = 3600; // 1 hour
    $max = 20;      // max submissions per IP per window
    $data = ['t' => $now, 'n' => 0];
    if (is_file($file)) {
        $d = json_decode((string)@file_get_contents($file), true);
        if (is_array($d) && isset($d['t'], $d['n'])) {
            $data = $d;
        }
    }
    if ($now - (int)$data['t'] > $window) {
        $data = ['t' => $now, 'n' => 0];
    }
    $data['n'] = (int)$data['n'] + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return $data['n'] <= $max;
}

function is_personal_email(string $email): bool
{
    global $PERSONAL_DOMAINS;
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    return in_array(strtolower(substr($email, $at + 1)), $PERSONAL_DOMAINS, true);
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!rl_throttle($ip)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

$raw = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_json']);
    exit;
}

$payload = [
    'name'        => substr(trim((string)($data['name'] ?? '')), 0, 200),
    'email'       => substr(trim((string)($data['email'] ?? '')), 0, 200),
    'company'     => substr(trim((string)($data['company'] ?? '')), 0, 200),
    'message'     => substr(trim((string)($data['message'] ?? '')), 0, 5000),
    'city'        => substr(trim((string)($data['city'] ?? '')), 0, 200),
    'plan_type'   => substr(trim((string)($data['plan_type'] ?? '')), 0, 200),
    'target_date' => substr(trim((string)($data['target_date'] ?? '')), 0, 200),
    'guest_count' => substr(trim((string)($data['guest_count'] ?? '')), 0, 50),
];
if ($payload['name'] === '' || !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_fields']);
    exit;
}
// Mandatory: name, email, target date, guest count, event city.
// Email must be a work address (no free personal providers).
foreach (['name', 'email', 'target_date', 'guest_count', 'city'] as $req) {
    if ($payload[$req] === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'missing_' . $req]);
        exit;
    }
}
if (is_personal_email($payload['email'])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'personal_email']);
    exit;
}
if (!ctype_digit($payload['guest_count']) || (int)$payload['guest_count'] < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_guest_count']);
    exit;
}

$ch = curl_init($ATTIO_WEBHOOK);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

$ok = $code >= 200 && $code < 300;
http_response_code($ok ? 200 : 502);
echo json_encode(['ok' => $ok, 'attio_status' => $code, 'error' => $ok ? null : ($err !== '' ? 'forward_failed' : 'attio_rejected')]);
