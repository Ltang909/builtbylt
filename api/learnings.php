<?php
/**
 * Learnings endpoint (workspace-scoped).
 *   GET  api/learnings.php?workspace_id=N -> [ learning, ... ]
 *   POST api/learnings.php {workspace_id, creative_id, final_verdict,
 *        reason} -> created learning
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/notion.php';
require_once __DIR__ . '/../lib/settings.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM learnings WHERE workspace_id = ? ORDER BY id');
    $stmt->execute([$wsid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['workspace_id'] = (int)$r['workspace_id'];
    }
    json_out($rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO learnings (workspace_id, creative_id, final_verdict, '
        . 'reason) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $wsid,
        isset($data['creative_id']) ? (int)$data['creative_id'] : null,
        $data['final_verdict'] ?? null, $data['reason'] ?? null,
    ]);
    $newId = (int)$pdo->lastInsertId();
    // Learnings loop (Update 4): manually recorded learnings also
    // push to the workspace's Notion experiments doc when configured.
    // Best-effort; a sync failure never fails this request.
    try {
        notion_sync_learning($pdo, $newId);
    } catch (Throwable $e) {
        // logged inside the sync; nothing further to do
    }
    $stmt = $pdo->prepare('SELECT * FROM learnings WHERE id = ?');
    $stmt->execute([$newId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row['id'] = (int)$row['id'];
    $row['workspace_id'] = (int)$row['workspace_id'];
    json_out($row, 201);
}

json_out(['error' => 'method not allowed'], 405);
