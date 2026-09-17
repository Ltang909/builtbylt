<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib.php';
portal_require_auth();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit; }
$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
$projects = portal_projects();
$projectIds = array_column($projects, 'id');
$project = trim((string) ($payload['project'] ?? ''));
$type = trim((string) ($payload['type'] ?? ''));
$title = trim((string) ($payload['title'] ?? ''));
$detail = trim((string) ($payload['detail'] ?? ''));

if (!in_array($project, $projectIds, true) || !in_array($type, ['update', 'deploy', 'milestone'], true) || $title === '' || mb_strlen($title) > 120 || mb_strlen($detail) > 600) {
    http_response_code(422); echo json_encode(['error' => 'Check the entry and try again.']); exit;
}
$date = trim((string) ($payload['date'] ?? ''));
try { $occurredAt = $date === '' ? new DateTimeImmutable('now', new DateTimeZone(portal_config()['timezone'])) : new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone(portal_config()['timezone'])); } catch (Throwable $e) { http_response_code(422); echo json_encode(['error' => 'Choose a valid date.']); exit; }
$item = ['id' => bin2hex(random_bytes(8)), 'project' => $project, 'type' => $type, 'title' => $title, 'detail' => $detail, 'timestamp' => $occurredAt->format(DateTimeInterface::ATOM)];
if (!portal_append_activity($item)) { http_response_code(500); echo json_encode(['error' => 'Could not write to storage. Check folder permissions.']); exit; }
portal_posthog_capture($item);
http_response_code(201); echo json_encode(['ok' => true, 'item' => $item]);

