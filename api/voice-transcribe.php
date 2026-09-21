<?php
declare(strict_types=1);

/**
 * Audio transcription for loom-ish voice notes.
 *
 * iPhone Safari has no SpeechRecognition, and on-device Whisper is
 * unreliable on iOS (model download exceeds the mobile cache quota), so
 * the loom-ish app POSTs the recorded audio here and gets a transcript
 * back. The audio is forwarded to Groq's Whisper API (free tier,
 * whisper-large-v3-turbo) and only the transcript is returned.
 *
 * Auth: Authorization: Bearer <token>, same token as voice-notes.php
 * ('voice_notes_token_hash' in private/builtbylt.php).
 * Server config: 'groq_api_key' in private/builtbylt.php
 * (free key from https://console.groq.com/keys).
 *
 *   POST /api/voice-transcribe.php   audio=<file>  language=<bcp47, optional>
 *   -> { "ok": true, "transcript": "..." }
 */

require dirname(__DIR__) . '/lib.php';

$origin = 'https://loom-ish.builtbylt.com';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

function vt_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    if (stripos($header, 'Bearer ') === 0) {
        return trim(substr($header, 7));
    }
    return '';
}

$tokenHash = (string) (portal_config()['voice_notes_token_hash'] ?? '');
if ($tokenHash === '' || !password_verify(vt_bearer_token(), $tokenHash)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$groqKey = (string) (portal_config()['groq_api_key'] ?? '');
if ($groqKey === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Transcription is not set up on the server yet.']);
    exit;
}

$audio = $_FILES['audio'] ?? null;
if (!is_array($audio) || ($audio['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'No audio file received.']);
    exit;
}

$size = (int) ($audio['size'] ?? 0);
// Groq free tier caps transcription uploads at 25 MB.
if ($size <= 0 || $size > 25 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Audio is too long for transcription (25 MB max).']);
    exit;
}

$mime = strtolower((string) ($audio['type'] ?? ''));
$ext = 'm4a'; // iPhone Safari records audio/mp4
if (strpos($mime, 'webm') !== false) $ext = 'webm';
elseif (strpos($mime, 'ogg') !== false) $ext = 'ogg';
elseif (strpos($mime, 'wav') !== false) $ext = 'wav';
elseif (strpos($mime, 'mpeg') !== false) $ext = 'mp3';

$lang = strtolower((string) ($_POST['language'] ?? 'en'));
$lang = preg_match('/^[a-z]{2}/', $lang) ? substr($lang, 0, 2) : 'en';

$ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $groqKey],
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile($audio['tmp_name'], $mime !== '' ? $mime : 'audio/m4a', 'note.' . $ext),
        'model' => 'whisper-large-v3-turbo',
        'language' => $lang,
        'response_format' => 'json',
    ],
]);
$body = curl_exec($ch);
$curlErr = curl_error($ch);
$http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false) {
    error_log('voice-transcribe: curl error: ' . $curlErr);
    http_response_code(502);
    echo json_encode(['error' => 'Transcription service unreachable. Try again.']);
    exit;
}

$data = json_decode((string) $body, true);
if ($http !== 200 || !is_array($data)) {
    $detail = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : '';
    error_log('voice-transcribe: groq HTTP ' . $http . ' ' . substr($detail, 0, 200));
    if ($http === 401 || $http === 403) {
        http_response_code(502);
        echo json_encode(['error' => 'Transcription key was rejected.']);
    } elseif ($http === 429) {
        http_response_code(429);
        echo json_encode(['error' => 'Transcription is busy right now. Try again in a minute.']);
    } else {
        http_response_code(502);
        echo json_encode(['error' => 'Transcription failed. Try again.']);
    }
    exit;
}

$transcript = trim((string) ($data['text'] ?? ''));
echo json_encode(['ok' => true, 'transcript' => $transcript]);
