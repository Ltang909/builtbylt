<?php
/**
 * Workspaces endpoint.
 *   GET  api/workspaces.php                  -> [ {id, name, created_at} ]
 *   POST api/workspaces.php  {name, template} -> the created workspace
 * template is "sweeps_casino" or "blank" (see lib/templates.php).
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query('SELECT * FROM workspaces ORDER BY id')
                ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        // Update 10.9: the generate form's template picker reads
        // the workspace's available ad templates from here.
        $r['image_templates'] = [];
        foreach (ad_templates_for($pdo, (int)$r['id']) as $k => $t) {
            $r['image_templates'][] = ['key' => $k, 'name' => $t['name'], 'size' => $t['size'] ?? null, 'uploaded' => !empty($t['uploaded']), 'id' => $t['id'] ?? null];
        }
    }
    json_out($rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        json_out(['error' => 'name is required'], 400);
    }
    $template = (string)($data['template'] ?? 'blank');
    if (!in_array($template, ['sweeps_casino', 'blank'], true)) {
        json_out(['error' => 'unknown template'], 400);
    }
    $wsid = create_workspace($pdo, $name, $template);
    $stmt = $pdo->prepare('SELECT * FROM workspaces WHERE id = ?');
    $stmt->execute([$wsid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row['id'] = (int)$row['id'];
    json_out($row, 201);
}

json_out(['error' => 'method not allowed'], 405);
