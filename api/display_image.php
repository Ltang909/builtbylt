<?php
/**
 * Serves a creative's Google display image assets, behind the panel
 * session (Update 11.0).
 *   GET api/display_image.php?creative_id=N&kind=landscape
 *
 * kind=landscape is the 1.91:1 asset: a center crop of the stored
 * square image, resized to 1200x628. The crop is pixel work, so it
 * needs GD; when GD is missing the endpoint says so plainly (the
 * square still exports, and Google's upload UI can crop there).
 * The derived file is cached on disk and recorded in
 * creative_assets, so derivation happens once per creative.
 *
 * Square needs no derivation — api/image.php already serves it;
 * asking for kind=square here just redirects there.
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
    header('Location: image.php?creative_id=' . $cid);
    exit;
}
if ($kind !== 'landscape') {
    json_out(['error' => 'unknown kind'], 400);
}

$wsid = (int)$creative['workspace_id'];
$derivedRel = 'uploads/ws' . $wsid . '/' . $cid . '_landscape.jpg';
$derivedAbs = PANEL_ROOT . '/' . $derivedRel;

if (!is_file($derivedAbs)) {
    if (!function_exists('imagecreatefromstring')) {
        json_out(['error' => 'Landscape derivation needs the GD '
            . 'extension, which this PHP does not have. The square '
            . 'asset still exports; Google Ads can crop it to '
            . '1.91:1 in the upload UI.'], 501);
    }
    $srcAbs = realpath(PANEL_ROOT . '/'
                       . (string)$creative['image_path']);
    $uploadsRoot = realpath(PANEL_ROOT . '/uploads');
    if ($srcAbs === false || $uploadsRoot === false
        || strpos($srcAbs, $uploadsRoot) !== 0 || !is_file($srcAbs)) {
        json_out(['error' => 'source image not found'], 404);
    }
    $bytes = @file_get_contents($srcAbs);
    $src = $bytes === false ? false : @imagecreatefromstring($bytes);
    if ($src === false) {
        json_out(['error' => 'source image could not be read'], 500);
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $target = RDA_LANDSCAPE_W / RDA_LANDSCAPE_H;
    if ($sw / $sh > $target) {
        $cropW = (int)round($sh * $target);
        $cropH = $sh;
        $cropX = (int)(($sw - $cropW) / 2);
        $cropY = 0;
    } else {
        $cropW = $sw;
        $cropH = (int)round($sw / $target);
        $cropX = 0;
        $cropY = (int)(($sh - $cropH) / 2);
    }
    $dst = imagecreatetruecolor(RDA_LANDSCAPE_W, RDA_LANDSCAPE_H);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY,
                       RDA_LANDSCAPE_W, RDA_LANDSCAPE_H,
                       $cropW, $cropH);
    $wsDir = PANEL_ROOT . '/uploads/ws' . $wsid;
    if (!is_dir($wsDir)) {
        mkdir($wsDir, 0775, true);
    }
    if (!@imagejpeg($dst, $derivedAbs, 90)) {
        json_out(['error' => 'derived image could not be saved '
            . 'under uploads/'], 500);
    }
    imagedestroy($src);
    imagedestroy($dst);
    display_record_asset($pdo, $cid, 'landscape', $derivedRel,
                         RDA_LANDSCAPE_W, RDA_LANDSCAPE_H);
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($derivedAbs));
header('Content-Disposition: inline; filename="creative-' . $cid
    . '-landscape-1200x628.jpg"');
header('Cache-Control: private, max-age=3600');
readfile($derivedAbs);
exit;
