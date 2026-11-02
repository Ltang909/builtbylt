<?php
/**
 * Serves an uploaded creative image, behind the panel session.
 *   GET api/image.php?creative_id=N
 * uploads/ itself denies all direct web access (.htaccess); this endpoint
 * is the only way in, and it never takes a raw path from the request —
 * the path comes from the creative's DB row and is pinned inside
 * PANEL_ROOT/uploads/.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

$cid = (int)($_GET['creative_id'] ?? 0);
$row = fetch_creative($pdo, $cid);
if ($row === null || empty($row['image_path'])) {
    json_out(['error' => 'not found'], 404);
}
$full = realpath(PANEL_ROOT . '/' . $row['image_path']);
$uploadsRoot = realpath(PANEL_ROOT . '/uploads');
if ($full === false || $uploadsRoot === false
    || strpos($full, $uploadsRoot) !== 0 || !is_file($full)) {
    json_out(['error' => 'not found'], 404);
}
$mimeByExt = ['png' => 'image/png', 'jpg' => 'image/jpeg',
              'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
              'gif' => 'image/gif'];
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
header('Content-Type: ' . ($mimeByExt[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($full));
header('Cache-Control: private, max-age=3600');
readfile($full);
exit;
