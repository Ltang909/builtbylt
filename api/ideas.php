<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php'; portal_require_auth(); header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit; }
$payload = json_decode(file_get_contents('php://input') ?: '{}', true); $ideas = portal_read_ideas(); $title = trim((string) ($payload['title'] ?? '')); $project = trim((string) ($payload['project'] ?? ''));
if ($title === '' || mb_strlen($title) > 180 || !in_array($project, array_column(portal_projects(), 'id'), true)) { http_response_code(422); echo json_encode(['error' => 'Check the idea and try again.']); exit; }
array_unshift($ideas, ['id' => bin2hex(random_bytes(8)), 'project' => $project, 'title' => $title, 'created_at' => date(DateTimeInterface::ATOM)]); echo json_encode(['ok' => portal_write_ideas(array_slice($ideas, 0, 100))]);

