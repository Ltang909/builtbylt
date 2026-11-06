<?php
/**
 * Native landscape image generation endpoint (Update 11.5).
 *   POST api/generate_landscape.php {creative_id, action: "estimate"}
 *        -> {ok, cost_usd, size, quality} — pure pricing-table math,
 *        no provider call.
 *   POST api/generate_landscape.php {creative_id, confirm: true,
 *        template?}
 *        -> generates ONE native 1.91:1 landscape variant of the
 *        creative's templated design with the art model, stored as
 *        uploads/ws<id>/<cid>_landscape_gen.<ext>.
 *
 * Why: the Google Display landscape asset used to be a GD center
 * crop of the square image, which slices the square layout badly.
 * The operator asked for the landscape to be generated separately
 * by OpenAI instead (2026-11-05). The prompt is the same workspace
 * ad template with the same slots as the square generation, plus a
 * canvas instruction to compose natively for the wide frame.
 * display_landscape_bytes() prefers this file over the crop, so
 * the preview links, the export packet and the Google Ads push all
 * pick it up with no further wiring.
 *
 * Spend discipline: same as generate_image.php — refuses without
 * confirm:true, never auto-retried, a failure loses only this
 * variant and leaves the creative's square untouched.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/openai.php';
require_api_auth($pdo);
// Image generations can outlast the web server's patience:
// finish the job (file write + ledger) even if the client or
// proxy hangs up first, instead of dying mid-call with an
// empty response (batch #36, 2026-11-06).
ignore_user_abort(true);
set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = read_json_body();
$cid = (int)($data['creative_id'] ?? 0);
if ($cid <= 0) {
    json_out(['error' => 'creative_id required'], 400);
}
$creative = fetch_creative($pdo, $cid);
if ($creative === null) {
    json_out(['error' => 'creative not found'], 404);
}
$wsid = (int)$creative['workspace_id'];
$size = '1216x640';

$batchId = isset($creative['batch_id']) && $creative['batch_id'] !== null
    ? (int)$creative['batch_id'] : 0;
$quality = 'medium';
if ($batchId > 0) {
    $bs = $pdo->prepare(
        'SELECT image_quality FROM generation_batches WHERE id = ?');
    $bs->execute([$batchId]);
    $bRow = $bs->fetch(PDO::FETCH_ASSOC);
    if ($bRow !== false
        && isset(IMAGE_PRICES_USD[(string)$bRow['image_quality']])) {
        $quality = (string)$bRow['image_quality'];
    }
}

$action = (string)($data['action'] ?? 'generate');
if ($action === 'estimate') {
    json_out(['ok' => true, 'creative_id' => $cid,
              'cost_usd' => image_unit_price($quality, $size),
              'size' => $size, 'quality' => $quality]);
}

if (empty($data['confirm'])) {
    json_out(['error' => 'confirmation required',
              'hint' => 'Landscape generation spends provider money; '
                      . 'it runs only inside a confirmed generation '
                      . 'run or after the on-screen estimate is '
                      . 'confirmed. Nothing has run.'], 400);
}
if (empty($creative['image_path'])) {
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'creative has no square image yet — the '
                       . 'landscape is a variant of that design'], 400);
}
$wsDir = PANEL_ROOT . '/uploads/ws' . $wsid;
foreach (['png', 'jpg', 'webp'] as $ext) {
    $existing = $wsDir . '/' . $cid . '_landscape_gen.' . $ext;
    if (is_file($existing)) {
        json_out(['ok' => true, 'creative_id' => $cid,
                  'already' => true,
                  'image_path' => 'uploads/ws' . $wsid . '/'
                      . $cid . '_landscape_gen.' . $ext,
                  'cost_usd' => 0.0]);
    }
}
$prompt = trim((string)($creative['image_prompt'] ?? '')) !== ''
    ? (string)$creative['image_prompt']
    : (string)($creative['art_direction'] ?? '');
if (trim($prompt) === '') {
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'creative has no image prompt or art direction']);
}
$artRoute = get_route($pdo, 'art_gen');
$artModel = (string)($artRoute['model'] ?: 'gpt-image-2.5-sunburst');
$apiKey = vault_key($pdo, 'openai');
if ($apiKey === null) {
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'No OpenAI key in the vault.'], 400);
}
$tplKey = trim((string)($data['template'] ?? ''));
$template = ad_template_text($pdo, $wsid,
                             $tplKey !== '' ? $tplKey : null);
if ($template === null) {
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'No image template is set for this workspace. '
                  . 'Nothing was generated or charged.']);
}
$wsStmt = $pdo->prepare('SELECT name FROM workspaces WHERE id = ?');
$wsStmt->execute([$wsid]);
$wsRow = $wsStmt->fetch(PDO::FETCH_ASSOC);
$subheadline = trim((string)($creative['primary_text'] ?? '')) !== ''
    ? (string)$creative['primary_text']
    : (string)($creative['description'] ?? '');
$defaults = ad_slot_defaults($pdo, $wsid);
$slots = [
    'BRAND' => $wsRow !== false ? (string)$wsRow['name'] : 'the brand',
    'HEADLINE' => (string)($creative['headline'] ?? ''),
    'SUBHEADLINE' => $subheadline,
    'CTA' => strtoupper(trim((string)($creative['cta'] ?? ''))),
    'PRODUCT_VISUAL' => $prompt,
    'LOGO_DESCRIPTION' => $defaults['LOGO_DESCRIPTION']
        ?? 'the brand logo (reproduce the attached reference image '
            . 'exactly)',
    'PRODUCT_INTERFACE_TYPE' => trim(
        (string)($creative['game_name'] ?? '')) !== ''
        ? (string)$creative['game_name'] : 'the product',
    'PRODUCT_UI_CONTENT' => $prompt,
    'SOCIAL_PROOF' => $defaults['SOCIAL_PROOF']
        ?? 'NONE — omit the social proof statement entirely; do '
            . 'not invent one.',
    'INTEGRATIONS' => $defaults['INTEGRATIONS']
        ?? 'NONE — show no integration logos.',
];
$finalPrompt = build_template_image_prompt($template, $slots);
$finalPrompt .= "\n\nCANVAS: Compose this same design natively as "
    . 'a WIDE landscape banner, 1216x640 (1.91:1, the Google '
    . 'Display landscape format). Re-lay the layout horizontally '
    . 'for the wide frame — brand header, headline, supporting '
    . 'line and call to action arranged for width. Do not crop, '
    . 'letterbox, stretch or squeeze a square layout; compose for '
    . 'the wide frame from the start.';
$logoFiles = workspace_logo_files($pdo, $wsid);
if ($logoFiles !== []) {
    $finalPrompt .= "\n\nLOGO: Reproduce the attached logo "
        . 'reference image(s) exactly in the brand header — same '
        . 'mark, same colors, same wordmark. Do not invent or '
        . 'redesign the logo.';
}
try {
    $bytes = $logoFiles !== []
        ? openai_image_edit($apiKey, $artModel, $finalPrompt,
                            $logoFiles, $size, $quality)
        : openai_image($apiKey, $artModel, $finalPrompt, $size,
                       $quality);
} catch (Throwable $exc) {
    log_ledger($pdo, $wsid, 'art_gen', 'openai', $artModel, 'error',
               'creative #' . $cid . ' landscape: '
               . substr($exc->getMessage(), 0, 240));
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => $exc->getMessage()]);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($bytes);
$extByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg',
              'image/webp' => 'webp'];
$ext = $extByMime[$mime] ?? 'png';
if (!is_dir($wsDir)) {
    mkdir($wsDir, 0775, true);
}
$storedName = $cid . '_landscape_gen.' . $ext;
$written = is_dir($wsDir)
    ? @file_put_contents($wsDir . '/' . $storedName, $bytes) : false;
if ($written === false) {
    log_ledger($pdo, $wsid, 'art_gen', 'openai', $artModel, 'error',
               'creative #' . $cid . ' landscape: bytes received but '
               . 'the file write failed in uploads/ws' . $wsid);
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'Landscape generated but could not be saved '
                       . 'under uploads/ (folder not writable).']);
}
$unit = image_unit_price($quality, $size);
if ($batchId > 0) {
    $pdo->prepare(
        'UPDATE generation_batches SET actual_cost_usd = '
        . 'actual_cost_usd + ? WHERE id = ?')
        ->execute([$unit, $batchId]);
}
log_ledger($pdo, $wsid, 'art_gen', 'openai', $artModel, 'ok',
    sprintf('creative #%d: landscape %s cost $%.4f (price table; '
        . 'the image API returns no usage)', $cid, $size, $unit));
json_out(['ok' => true, 'creative_id' => $cid,
          'image_path' => 'uploads/ws' . $wsid . '/' . $storedName,
          'cost_usd' => $unit]);
