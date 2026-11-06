<?php
/**
 * Uploaded image templates (2026-11-06): a workspace operator
 * adds a template by uploading a finished design image; it then
 * appears in the generate form's Template picker and generation
 * re-renders that design with each creative's copy (see
 * lib/ad_templates.php).
 *   POST {action:"upload", workspace_id, name, filename, image_base64}
 *   POST {action:"delete", id}
 *   GET  ?workspace_id=N -> [ row, ... ]
 * Uploads are base64 JSON like the creative image upload,
 * sniffed by content (png/jpg/webp), stored under
 * uploads/templates/ws<N>/ (uploads/ is not web-accessible;
 * api/template_image.php serves the files behind the session).
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);
require_once __DIR__ . '/../lib/ad_templates.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_out(ad_template_uploaded_rows(
        $pdo, (int)($_GET['workspace_id'] ?? 0)));
}
$data = read_json_body();
$action = (string)($data['action'] ?? '');

if ($action === 'upload') {
    $wsid = (int)($data['workspace_id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    if ($wsid <= 0 || $name === '') {
        json_out(['ok' => false,
                  'error' => 'A workspace and a template name are required.'],
                 400);
    }
    $name = substr($name, 0, 120);
    $b64 = (string)($data['image_base64'] ?? '');
    $bytes = $b64 !== '' ? base64_decode($b64, true) : false;
    if ($bytes === false || $bytes === '') {
        json_out(['ok' => false,
                  'error' => 'No image data received (JSON image_base64).'],
                 400);
    }
    if (strlen($bytes) > 8 * 1024 * 1024) {
        json_out(['ok' => false,
                  'error' => 'The design image is larger than 8 MB.'], 400);
    }
    // Validate by sniffing the actual bytes, not the claimed name.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($bytes);
    $extByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg',
                  'image/webp' => 'webp'];
    if (!isset($extByMime[$mime])) {
        json_out(['ok' => false,
                  'error' => "Unsupported image type: $mime "
                      . '(allowed: png, jpg, webp).'], 400);
    }
    $ext = $extByMime[$mime];
    $base = pathinfo((string)($data['filename'] ?? 'template'),
                     PATHINFO_FILENAME);
    $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
    if ($clean === '' || $clean === null) {
        $clean = 'template';
    }
    $dir = PANEL_ROOT . '/uploads/templates/ws' . $wsid;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $stored = time() . '_' . $clean . '.' . $ext;
    file_put_contents($dir . '/' . $stored, $bytes);
    $rel = 'uploads/templates/ws' . $wsid . '/' . $stored;
    ad_templates_install($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO ad_templates (workspace_id, name, image_path) '
        . 'VALUES (?, ?, ?)');
    $stmt->execute([$wsid, $name, $rel]);
    $id = (int)$pdo->lastInsertId();
    json_out(['ok' => true, 'id' => $id, 'key' => 'up-' . $id,
              'name' => $name, 'image_path' => $rel]);
}

if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    ad_templates_install($pdo);
    $stmt = $pdo->prepare(
        'SELECT image_path FROM ad_templates WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        json_out(['error' => 'template not found'], 404);
    }
    $abs = realpath(PANEL_ROOT . '/' . (string)$row['image_path']);
    $root = realpath(PANEL_ROOT . '/uploads/templates');
    if ($abs !== false && $root !== false
        && strpos($abs, $root) === 0 && is_file($abs)) {
        @unlink($abs);
    }
    $pdo->prepare('DELETE FROM ad_templates WHERE id = ?')
        ->execute([$id]);
    json_out(['ok' => true, 'deleted' => $id]);
}

json_out(['error' => 'unknown action'], 400);
