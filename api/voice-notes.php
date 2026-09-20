<?php
declare(strict_types=1);

/**
 * Voice notes relay for loom-ish.builtbylt.com "Send to Notion".
 *
 * The static Loom-ish app cannot talk to the Notion API directly (no CORS),
 * so it POSTs transcripts here. Notes are queued in private/voice-notes.json
 * (above the docroot, so deploys never overwrite them) and a scheduled job
 * syncs unsynced notes into Notion, then ACKs them.
 *
 * Auth: Authorization: Bearer <token>, verified against the
 * 'voice_notes_token_hash' entry in private/builtbylt.php.
 *
 *   POST /api/voice-notes.php            create a note
 *   GET  /api/voice-notes.php?pending=1  list notes not yet synced to Notion
 *   POST /api/voice-notes.php            {"ack": ["vn_..."]} mark notes synced
 */

require dirname(__DIR__) . '/lib.php';

$origin = 'https://loom-ish.builtbylt.com';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function vn_bearer_token(): string
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

function vn_store_path(): string
{
    // private/ sits above the docroot (same location as private/builtbylt.php),
    // so queued notes survive repo deploys.
    return dirname(__DIR__, 2) . '/private/voice-notes.json';
}

function vn_read_notes(): array
{
    $path = vn_store_path();
    if (!is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }
    flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $notes = json_decode($raw ?: '[]', true);
    return is_array($notes) ? array_values($notes) : [];
}

function vn_write_notes(array $notes): bool
{
    $path = vn_store_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        return false;
    }
    return file_put_contents(
        $path,
        json_encode(array_values($notes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

$tokenHash = (string) (portal_config()['voice_notes_token_hash'] ?? '');
if ($tokenHash === '' || !password_verify(vn_bearer_token(), $tokenHash)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!isset($_GET['pending'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Use ?pending=1 to list unsynced notes.']);
        exit;
    }
    $pending = array_values(array_filter(
        vn_read_notes(),
        static fn(array $n): bool => empty($n['notion_synced'])
    ));
    echo json_encode(['ok' => true, 'notes' => $pending]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

// ACK path: mark notes as synced to Notion.
if (isset($payload['ack']) && is_array($payload['ack'])) {
    $ids = array_values(array_filter(array_map('strval', $payload['ack'])));
    if ($ids === []) {
        http_response_code(422);
        echo json_encode(['error' => 'Nothing to acknowledge.']);
        exit;
    }
    $notes = vn_read_notes();
    $acked = 0;
    foreach ($notes as &$note) {
        if (in_array($note['id'], $ids, true) && empty($note['notion_synced'])) {
            $note['notion_synced'] = true;
            $note['synced_at'] = date(DateTimeInterface::ATOM);
            $acked++;
        }
    }
    unset($note);
    if (!vn_write_notes($notes)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not update the queue.']);
        exit;
    }
    echo json_encode(['ok' => true, 'acked' => $acked]);
    exit;
}

// Create path: queue a new voice note.
$title = trim((string) ($payload['title'] ?? ''));
$transcript = trim((string) ($payload['transcript'] ?? ''));
$durationSec = (int) ($payload['duration_sec'] ?? 0);
$recordedAt = trim((string) ($payload['recorded_at'] ?? ''));
$lang = trim((string) ($payload['lang'] ?? ''));

if ($title === '' || mb_strlen($title) > 140 || mb_strlen($transcript) > 20000 || $durationSec < 0 || $durationSec > 7200 || mb_strlen($lang) > 20) {
    http_response_code(422);
    echo json_encode(['error' => 'Check the note and try again.']);
    exit;
}

try {
    $occurredAt = $recordedAt === '' ? new DateTimeImmutable('now') : new DateTimeImmutable($recordedAt);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => 'Bad recorded_at timestamp.']);
    exit;
}

$note = [
    'id' => 'vn_' . bin2hex(random_bytes(8)),
    'title' => $title,
    'transcript' => $transcript,
    'duration_sec' => $durationSec,
    'recorded_at' => $occurredAt->format(DateTimeInterface::ATOM),
    'lang' => $lang,
    'source' => 'loom-ish',
    'notion_synced' => false,
    'created_at' => date(DateTimeInterface::ATOM),
];

$notes = vn_read_notes();
array_unshift($notes, $note);
$notes = array_slice($notes, 0, 500);

if (!vn_write_notes($notes)) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the note. Check folder permissions.']);
    exit;
}

http_response_code(201);
echo json_encode(['ok' => true, 'id' => $note['id']]);
