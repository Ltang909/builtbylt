<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
portal_require_auth();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit; }
$payload = json_decode(file_get_contents('php://input') ?: '{}', true); $tasks = portal_read_tasks(); $action = (string) ($payload['action'] ?? 'add');
if ($action === 'toggle') { $id = (string) ($payload['id'] ?? ''); foreach ($tasks as &$task) if ($task['id'] === $id) $task['done'] = !$task['done']; unset($task); }
else { $title = trim((string) ($payload['title'] ?? '')); $project = trim((string) ($payload['project'] ?? '')); if ($title === '' || mb_strlen($title) > 140 || !in_array($project, array_column(portal_projects(), 'id'), true)) { http_response_code(422); echo json_encode(['error' => 'Check the task and try again.']); exit; } array_unshift($tasks, ['id' => bin2hex(random_bytes(8)), 'project' => $project, 'title' => $title, 'done' => false, 'created_at' => date(DateTimeInterface::ATOM)]); }
if (!portal_write_tasks($tasks)) { http_response_code(500); echo json_encode(['error' => 'Could not save tasks.']); exit; }
echo json_encode(['ok' => true]);

