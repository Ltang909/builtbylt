<?php
/**
 * Creatives endpoint (workspace-scoped).
 *   GET  api/creatives.php?workspace_id=N -> [ creative + verdicts, ... ]
 *        (image_path is joined by image_url -> api/image.php, which serves
 *        the file behind the session; uploads are never directly public)
 *   POST api/creatives.php {workspace_id, experiment_id, kind, game_name,
 *        headline, primary_text, description, cta, offer_text,
 *        template_id, format} -> created creative
 *   POST api/creatives.php {action: "image", creative_id, filename,
 *        image_base64} -> creative with the image stored under
 *        uploads/ws<workspace_id>/ . Base64 JSON only (kept simple, like
 *        the later v2 intake). Mime is sniffed from the bytes and must be
 *        png / jpg / jpeg / webp.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM creatives WHERE workspace_id = ? ORDER BY id');
    $stmt->execute([$wsid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $row['workspace_id'] = (int)$row['workspace_id'];
        $out[] = creative_with_verdicts($pdo, $row);
    }
    json_out($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $action = (string)($data['action'] ?? 'create');

    if ($action === 'image') {
        $cid = (int)($data['creative_id'] ?? 0);
        $row = fetch_creative($pdo, $cid);
        if ($row === null) {
            json_out(['error' => 'creative not found'], 404);
        }
        $wsid = (int)$row['workspace_id'];
        $bytes = base64_decode((string)($data['image_base64'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            json_out(['ok' => false,
                      'error' => 'No image data received (JSON image_base64).'],
                     400);
        }
        // Validate by sniffing the actual bytes, not the claimed name.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        $extByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg',
                      'image/webp' => 'webp'];
        if (!isset($extByMime[$mime])) {
            json_out(['ok' => false,
                      'error' => "Unsupported image type: $mime "
                               . '(allowed: png, jpg, jpeg, webp).'], 400);
        }
        $ext = $extByMime[$mime];
        $base = pathinfo((string)($data['filename'] ?? 'upload'), PATHINFO_FILENAME);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        if ($clean === '' || $clean === null) {
            $clean = 'upload';
        }
        $storedName = $cid . '_' . $clean . '.' . $ext;
        $wsDir = PANEL_ROOT . '/uploads/ws' . $wsid;
        if (!is_dir($wsDir)) {
            mkdir($wsDir, 0775, true);
        }
        file_put_contents($wsDir . '/' . $storedName, $bytes);
        $rel = 'uploads/ws' . $wsid . '/' . $storedName;
        $stmt = $pdo->prepare(
            'UPDATE creatives SET image_path = ? WHERE id = ?');
        $stmt->execute([$rel, $cid]);
        $fresh = fetch_creative($pdo, $cid);
        $fresh['id'] = (int)$fresh['id'];
        $fresh['workspace_id'] = (int)$fresh['workspace_id'];
        json_out(creative_with_verdicts($pdo, $fresh));
    }

    // default: create
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO creatives (workspace_id, experiment_id, kind, '
        . 'game_name, headline, primary_text, description, cta, offer_text, '
        . 'template_id, format, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'generated\')');
    $stmt->execute([
        $wsid,
        isset($data['experiment_id']) && $data['experiment_id'] !== ''
            ? (int)$data['experiment_id'] : null,
        $data['kind'] ?? 'static', $data['game_name'] ?? null,
        $data['headline'] ?? null, $data['primary_text'] ?? null,
        $data['description'] ?? null, $data['cta'] ?? null,
        $data['offer_text'] ?? null, $data['template_id'] ?? null,
        $data['format'] ?? null,
    ]);
    $row = fetch_creative($pdo, (int)$pdo->lastInsertId());
    $row['id'] = (int)$row['id'];
    $row['workspace_id'] = (int)$row['workspace_id'];
    json_out(creative_with_verdicts($pdo, $row), 201);
}

json_out(['error' => 'method not allowed'], 405);
