<?php
/**
 * Serves an uploaded template's design image, behind the panel
 * session.   GET api/template_image.php?id=N
 * The path comes from the ad_templates row and is pinned inside
 * PANEL_ROOT/uploads/templates/ — never taken from the request.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);
require_once __DIR__ . '/../lib/ad_templates.php';

$id = (int)($_GET['id'] ?? 0);
ad_templates_install($pdo);
$stmt = $pdo->prepare(
    'SELECT image_path FROM ad_templates WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    json_out(['error' => 'not found'], 404);
}
$full = realpath(PANEL_ROOT . '/' . (string)$row['image_path']);
$root = realpath(PANEL_ROOT . '/uploads/templates');
if ($full === false || $root === false
    || strpos($full, $root) !== 0 || !is_file($full)) {
    json_out(['error' => 'not found'], 404);
}
$mimeByExt = ['png' => 'image/png', 'jpg' => 'image/jpeg',
              'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mimeByExt[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($full));
header('Cache-Control: private, max-age=3600');
readfile($full);
exit;
