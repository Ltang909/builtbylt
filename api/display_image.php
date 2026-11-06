<?php
/**
 * Serves a creative's Google display image assets, behind the panel
 * session (Update 11.0).
 *   GET api/display_image.php?creative_id=N&kind=landscape
 *
 * kind=landscape is the 1.91:1 asset: a center crop of the stored
 * square image, resized to 1200x628, derived once and cached by
 * display_landscape_bytes() in lib/display.php (GD pixel work; if
 * GD is missing the endpoint relays that function's plain error).
 *
 * kind=square is the 1:1 asset: the master itself when it is
 * already square, otherwise the derived center crop from
 * display_square_bytes() — the same bytes the push uploads.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

$cid = (int)($_GET['creative_id'] ?? 0);
$kind = (string)($_GET['kind'] ?? 'landscape');
$creative = fetch_creative($pdo, $cid);
if ($creative === null || empty($creative['image_path'])) {
    json_out(['error' => 'not found'], 404);
}
if ($kind === 'square') {
    $r = display_square_bytes($pdo, $creative);
    if (!$r['ok']) {
        json_out(['error' => $r['error']], (int)$r['status']);
    }
    $abs = PANEL_ROOT . '/' . $r['path'];
    $info = @getimagesize($abs);
    header('Content-Type: ' . ($info !== false && !empty($info['mime'])
        ? $info['mime'] : 'image/jpeg'));
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: inline; filename="creative-' . $cid
        . '-square-1024x1024.jpg"');
    header('Cache-Control: private, max-age=3600');
    readfile($abs);
    exit;
}
if ($kind !== 'landscape') {
    json_out(['error' => 'unknown kind'], 400);
}

$r = display_landscape_bytes($pdo, $creative);
if (!$r['ok']) {
    json_out(['error' => $r['error']], (int)$r['status']);
}
$abs = PANEL_ROOT . '/' . $r['path'];
// The generated variant (Update 11.5) may be PNG/WebP bytes; the
// derived crop is JPEG. Serve the file's real type.
$info = @getimagesize($abs);
header('Content-Type: ' . ($info !== false && !empty($info['mime'])
    ? $info['mime'] : 'image/jpeg'));
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="creative-' . $cid
    . '-landscape-1200x628.jpg"');
header('Cache-Control: private, max-age=3600');
readfile($abs);
exit;
