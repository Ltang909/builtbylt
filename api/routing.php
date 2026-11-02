<?php
/**
 * Provider routing endpoint: which provider + model runs each task.
 *   GET  api/routing.php                    -> [ {task, provider, model} ]
 *   POST api/routing.php {task, provider, model} -> saved row (upsert)
 * Generation tasks (copy_gen, art_gen) are routing config only in this
 * build — their endpoints are TODO stubs and say so in the UI.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query('SELECT * FROM routing ORDER BY task')
                ->fetchAll(PDO::FETCH_ASSOC);
    json_out($rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $task = (string)($data['task'] ?? '');
    if ($task === '') {
        json_out(['error' => 'task is required'], 400);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO routing (task, provider, model) VALUES (?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE provider = VALUES(provider), '
        . 'model = VALUES(model)');
    $stmt->execute([$task, $data['provider'] ?? null, $data['model'] ?? null]);
    $stmt = $pdo->prepare('SELECT * FROM routing WHERE task = ?');
    $stmt->execute([$task]);
    json_out($stmt->fetch(PDO::FETCH_ASSOC));
}

json_out(['error' => 'method not allowed'], 405);
