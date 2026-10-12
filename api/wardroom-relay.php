<?php
declare(strict_types=1);

/**
 * Wardroom relay for The Wardroom invitation form.
 *
 * Captures a work email address only, same pattern as the waitlist
 * relay. Attio's incoming webhook does not accept cross-site browser
 * posts (its CORS policy only allows https://app.attio.com), so the
 * Wardroom form POSTs here instead and this script forwards the
 * request to Attio server-side, where CORS does not apply.
 *
 *   POST /api/wardroom-relay.php   {"email"}
 *
 * Tagging: the payload carries lead_source = "Wardroom". For that to
 * land in Attio's Lead source attribute, the workflow's Parse JSON
 * step needs a "lead_source" key added and mapped to the attribute.
 * Until then, message = "wardroom" keeps these signups filterable,
 * the same trick the waitlist uses. Personal email providers are
 * rejected. Submissions are throttled per IP.
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

function wr_throttle(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/wardroom-relay-rl';
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
if (!wr_throttle($ip)) {
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

$email = substr(trim((string)($data['email'] ?? '')), 0, 200);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_email']);
    exit;
}
if (is_personal_email($email)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'personal_email']);
    exit;
}

$payload = [
    'name'        => '',
    'email'       => $email,
    'company'     => '',
    'message'     => 'wardroom',
    'lead_source' => 'Wardroom',
];

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
