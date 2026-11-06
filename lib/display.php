<?php
/**
 * Google Responsive Display Ads (Update 11.0).
 *
 * A display set is not a new kind of creative. It is the panel's
 * existing creative read against Google's responsive display spec:
 *
 *   headlines      up to 5, each 30 characters or fewer
 *   long headline  1, 90 characters or fewer
 *   descriptions   up to 5, each 90 characters or fewer
 *   business name  25 characters or fewer
 *   images         landscape 1.91:1 and square 1:1 (portrait 4:5
 *                  optional — not derived here)
 *   logo           the workspace's real logo (Settings) — Google
 *                  wants it uploaded as its own asset; the panel
 *                  never invents one, so the export lists it as a
 *                  manual step when files exist, and as missing
 *                  when they do not.
 *
 * Copy mapping (creative field -> display slot):
 *   headline     -> headline 1
 *   offer_text   -> headline 2 (only listed when it fits the cap)
 *   description  -> long headline AND description 1
 *   primary_text -> description 2
 *   cta          -> call-to-action text (Google constrains this to
 *                   its own button list at upload; carried as-is)
 *   workspace name -> business name
 *   final URL    -> not stored in the panel; the export says so
 *                   instead of inventing one.
 *
 * Over-limit copy is never silently truncated — that would put
 * words in the brand's mouth. The spec reports each field's length
 * and pass/fail, the lint layer flags overages on the card, and the
 * export packet shows Google's caps beside the actual values.
 *
 * Images: the stored creative image is the square asset. The
 * landscape asset is a center crop to 1200x628 derived by
 * api/display_image.php (GD) the first time it is asked for, then
 * cached on disk and recorded in creative_assets. No model call,
 * no provider cost. If GD is unavailable on the host, derivation
 * fails with an explicit error and the square still exports —
 * Google's own upload UI can crop it there.
 */

const RDA_HEADLINE_MAX = 30;
const RDA_LONG_HEADLINE_MAX = 90;
const RDA_DESCRIPTION_MAX = 90;
const RDA_BUSINESS_MAX = 25;
const RDA_LANDSCAPE_W = 1200;
const RDA_LANDSCAPE_H = 628;
const RDA_SQUARE_W = 1024;
const RDA_SQUARE_H = 1024;

function rda_len(string $text): int {
    return function_exists('mb_strlen')
        ? mb_strlen($text) : strlen($text);
}

function display_install(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS creative_assets ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, '
        . 'creative_id INT NOT NULL, '
        . 'kind VARCHAR(40) NOT NULL, '
        . 'path VARCHAR(255) NOT NULL, '
        . 'width INT NULL, '
        . 'height INT NULL, '
        . 'created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
        . 'UNIQUE KEY uq_creative_asset (creative_id, kind)) '
        . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** The recorded derived-asset path for a creative, or null. */
function display_asset_path(PDO $pdo, int $cid, string $kind): ?string {
    display_install($pdo);
    $stmt = $pdo->prepare(
        'SELECT path FROM creative_assets WHERE creative_id = ? '
        . 'AND kind = ?');
    $stmt->execute([$cid, $kind]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : (string)$row['path'];
}

function display_record_asset(PDO $pdo, int $cid, string $kind,
                              string $path, int $w, int $h): void {
    display_install($pdo);
    $pdo->prepare(
        'INSERT INTO creative_assets (creative_id, kind, path, '
        . 'width, height) VALUES (?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE path = VALUES(path), '
        . 'width = VALUES(width), height = VALUES(height)')
        ->execute([$cid, $kind, $path, $w, $h]);
}

/**
 * The creative's copy read against the display spec. Pure string
 * math on the row plus the workspace name — no table access, safe
 * to call on every read.
 */
function display_spec(PDO $pdo, array $creative): array {
    $wsStmt = $pdo->prepare('SELECT name FROM workspaces WHERE id = ?');
    $wsStmt->execute([(int)($creative['workspace_id'] ?? 0)]);
    $wsRow = $wsStmt->fetch(PDO::FETCH_ASSOC);
    $business = $wsRow !== false ? (string)$wsRow['name'] : '';

    $headline = trim((string)($creative['headline'] ?? ''));
    $offer = trim((string)($creative['offer_text'] ?? ''));
    $description = trim((string)($creative['description'] ?? ''));
    $primary = trim((string)($creative['primary_text'] ?? ''));

    $headlines = [];
    if ($headline !== '') {
        $headlines[] = $headline;
    }
    if ($offer !== '' && rda_len($offer) <= RDA_HEADLINE_MAX
        && $offer !== $headline) {
        $headlines[] = $offer;
    }
    $descriptions = [];
    if ($description !== '') {
        $descriptions[] = $description;
    }
    if ($primary !== '' && $primary !== $description) {
        $descriptions[] = $primary;
    }

    $checks = [];
    $field = function (string $name, string $value, int $max)
        use (&$checks) {
        $len = rda_len($value);
        $checks[] = [
            'field' => $name,
            'chars' => $len,
            'max' => $max,
            'ok' => $value !== '' && $len <= $max,
            'empty' => $value === '',
        ];
    };
    $field('headline', $headline, RDA_HEADLINE_MAX);
    $field('long_headline', $description, RDA_LONG_HEADLINE_MAX);
    $field('description', $primary !== '' ? $primary : $description,
           RDA_DESCRIPTION_MAX);
    $field('business_name', $business, RDA_BUSINESS_MAX);

    return [
        'format' => 'responsive_display',
        'business_name' => $business,
        'headlines' => $headlines,
        'long_headline' => $description,
        'descriptions' => $descriptions,
        'cta' => (string)($creative['cta'] ?? ''),
        'final_url' => null,
        'final_url_note' => 'Not stored in the panel — set the '
            . 'landing page URL in Google Ads at upload.',
        'logo_note' => 'Upload the workspace logo as its own asset '
            . 'in Google Ads (the panel never renders a logo into '
            . 'the display crop).',
        'checks' => $checks,
        'copy_ok' => !in_array(false,
            array_map(function ($c) { return $c['ok']; }, $checks),
            true),
    ];
}

/**
 * Image assets for a creative: the stored square, plus the
 * landscape derivation (recorded once derived; before that, the
 * derivation endpoint URL). Empty when the creative has no image.
 */
function display_assets_for(PDO $pdo, array $creative): array {
    if (empty($creative['image_path'])) {
        return [];
    }
    $cid = (int)$creative['id'];
    $assets = [];
    $abs = PANEL_ROOT . '/' . (string)$creative['image_path'];
    $w = null;
    $h = null;
    if (is_file($abs)) {
        $info = @getimagesize($abs);
        if ($info !== false) {
            $w = (int)$info[0];
            $h = (int)$info[1];
        }
    }
    // The square asset is the derived 1:1 rendition when the
    // master is not itself square (display_square_bytes); the
    // URL serves exactly the bytes the push uploads.
    $isSquare = $w !== null && $h !== null && $w === $h;
    $assets[] = [
        'kind' => 'square',
        'label' => 'Square 1:1',
        'url' => 'api/display_image.php?creative_id=' . $cid
            . '&kind=square',
        'width' => $isSquare ? $w : RDA_SQUARE_W,
        'height' => $isSquare ? $h : RDA_SQUARE_H,
        'ratio_ok' => true,
        'recorded' => true,
    ];
    $landPath = null;
    try {
        $landPath = display_asset_path($pdo, $cid, 'landscape');
    } catch (Throwable $e) {
        $landPath = null;
    }
    $genLand = display_generated_landscape($creative);
    $assets[] = [
        'kind' => 'landscape',
        'label' => 'Landscape 1.91:1 (1200x628)',
        'url' => 'api/display_image.php?creative_id=' . $cid
            . '&kind=landscape',
        'width' => RDA_LANDSCAPE_W,
        'height' => RDA_LANDSCAPE_H,
        'ratio_ok' => true,
        'recorded' => $landPath !== null || $genLand !== null,
        'generated' => $genLand !== null,
    ];
    return $assets;
}

/**
 * The AI-generated native landscape variant's relative path, when
 * one exists (Update 11.5: api/generate_landscape.php stores
 * <cid>_landscape_gen.<ext> beside the square). Null otherwise.
 */
function display_generated_landscape(array $creative): ?string {
    $cid = (int)$creative['id'];
    $wsid = (int)$creative['workspace_id'];
    foreach (['png', 'jpg', 'webp'] as $ext) {
        $rel = 'uploads/ws' . $wsid . '/' . $cid
            . '_landscape_gen.' . $ext;
        if (is_file(PANEL_ROOT . '/' . $rel)) {
            return $rel;
        }
    }
    return null;
}

/**
 * The square asset's bytes for a creative. A source that is
 * already 1:1 is returned untouched, byte for byte — the
 * long-standing behavior for square-native workspaces. Any other
 * aspect (the Bum Bum portrait masters) is derived once into a
 * true 1024x1024 center crop and cached, because Google rejects
 * a non-1:1 file in the square_marketing_images slot (seen live
 * 2026-11-06: a 1024x1536 master uploaded raw). Shared by
 * api/display_image.php (serves it) and the Google Ads push
 * (uploads it). Same return contract as display_landscape_bytes.
 */
function display_square_bytes(PDO $pdo, array $creative): array {
    if (empty($creative['image_path'])) {
        return ['ok' => false, 'status' => 404,
                'error' => 'creative has no image'];
    }
    $cid = (int)$creative['id'];
    $wsid = (int)$creative['workspace_id'];
    $srcAbs = realpath(PANEL_ROOT . '/'
                       . (string)$creative['image_path']);
    $uploadsRoot = realpath(PANEL_ROOT . '/uploads');
    if ($srcAbs === false || $uploadsRoot === false
        || strpos($srcAbs, $uploadsRoot) !== 0
        || !is_file($srcAbs)) {
        return ['ok' => false, 'status' => 404,
                'error' => 'source image not found'];
    }
    $info = @getimagesize($srcAbs);
    if ($info !== false && (int)$info[0] === (int)$info[1]) {
        $bytes = @file_get_contents($srcAbs);
        if ($bytes !== false) {
            return ['ok' => true, 'bytes' => $bytes,
                    'path' => (string)$creative['image_path']];
        }
    }
    $rel = 'uploads/ws' . $wsid . '/' . $cid . '_square.jpg';
    $abs = PANEL_ROOT . '/' . $rel;
    if (is_file($abs)) {
        $bytes = @file_get_contents($abs);
        if ($bytes !== false) {
            return ['ok' => true, 'bytes' => $bytes, 'path' => $rel];
        }
    }
    if (!function_exists('imagecreatefromstring')) {
        return ['ok' => false, 'status' => 501,
                'error' => 'Square derivation needs the GD '
                    . 'extension, which this PHP does not have. '
                    . 'Generate a native square image for this '
                    . 'creative, or crop the master to 1:1 before '
                    . 'uploading it.'];
    }
    $raw = @file_get_contents($srcAbs);
    $src = $raw === false ? false : @imagecreatefromstring($raw);
    if ($src === false) {
        return ['ok' => false, 'status' => 500,
                'error' => 'source image could not be read'];
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $side = min($sw, $sh);
    $cropX = (int)(($sw - $side) / 2);
    $cropY = (int)(($sh - $side) / 2);
    $dst = imagecreatetruecolor(RDA_SQUARE_W, RDA_SQUARE_H);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY,
                       RDA_SQUARE_W, RDA_SQUARE_H, $side, $side);
    $wsDir = PANEL_ROOT . '/uploads/ws' . $wsid;
    if (!is_dir($wsDir)) {
        mkdir($wsDir, 0775, true);
    }
    $saved = @imagejpeg($dst, $abs, 90);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$saved) {
        return ['ok' => false, 'status' => 500,
                'error' => 'derived image could not be saved under '
                    . 'uploads/'];
    }
    display_record_asset($pdo, $cid, 'square', $rel,
                         RDA_SQUARE_W, RDA_SQUARE_H);
    $bytes = @file_get_contents($abs);
    if ($bytes === false) {
        return ['ok' => false, 'status' => 500,
                'error' => 'derived image saved but not readable'];
    }
    return ['ok' => true, 'bytes' => $bytes, 'path' => $rel];
}

/**
 * The landscape asset's bytes for a creative. Preference order
 * (Update 11.5): the AI-generated native landscape when one
 * exists; otherwise a GD center crop of the square, derived and
 * cached on first use. Shared by api/display_image.php (serves
 * it) and the Google Ads push (uploads it). Returns
 * ['ok' => true, 'bytes' => string, 'path' => relative path] or
 * ['ok' => false, 'error' => string, 'status' => int].
 */
function display_landscape_bytes(PDO $pdo, array $creative): array {
    if (empty($creative['image_path'])) {
        return ['ok' => false, 'status' => 404,
                'error' => 'creative has no image'];
    }
    $cid = (int)$creative['id'];
    $wsid = (int)$creative['workspace_id'];
    $gen = display_generated_landscape($creative);
    if ($gen !== null) {
        $bytes = @file_get_contents(PANEL_ROOT . '/' . $gen);
        if ($bytes !== false) {
            return ['ok' => true, 'bytes' => $bytes, 'path' => $gen];
        }
    }
    $rel = 'uploads/ws' . $wsid . '/' . $cid . '_landscape.jpg';
    $abs = PANEL_ROOT . '/' . $rel;
    if (is_file($abs)) {
        $bytes = @file_get_contents($abs);
        if ($bytes !== false) {
            return ['ok' => true, 'bytes' => $bytes, 'path' => $rel];
        }
    }
    if (!function_exists('imagecreatefromstring')) {
        return ['ok' => false, 'status' => 501,
                'error' => 'Landscape derivation needs the GD '
                    . 'extension, which this PHP does not have. '
                    . 'The square asset still exports; Google Ads '
                    . 'can crop it to 1.91:1 in the upload UI.'];
    }
    $srcAbs = realpath(PANEL_ROOT . '/'
                       . (string)$creative['image_path']);
    $uploadsRoot = realpath(PANEL_ROOT . '/uploads');
    if ($srcAbs === false || $uploadsRoot === false
        || strpos($srcAbs, $uploadsRoot) !== 0
        || !is_file($srcAbs)) {
        return ['ok' => false, 'status' => 404,
                'error' => 'source image not found'];
    }
    $raw = @file_get_contents($srcAbs);
    $src = $raw === false ? false : @imagecreatefromstring($raw);
    if ($src === false) {
        return ['ok' => false, 'status' => 500,
                'error' => 'source image could not be read'];
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
    $saved = @imagejpeg($dst, $abs, 90);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$saved) {
        return ['ok' => false, 'status' => 500,
                'error' => 'derived image could not be saved under '
                    . 'uploads/'];
    }
    display_record_asset($pdo, $cid, 'landscape', $rel,
                         RDA_LANDSCAPE_W, RDA_LANDSCAPE_H);
    $bytes = @file_get_contents($abs);
    if ($bytes === false) {
        return ['ok' => false, 'status' => 500,
                'error' => 'derived image saved but not readable'];
    }
    return ['ok' => true, 'bytes' => $bytes, 'path' => $rel];
}
