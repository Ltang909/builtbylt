<?php
/**
 * Single-image generation endpoint (Update 10.5).
 *   POST api/generate_image.php {creative_id, confirm: true}
 *        (image_text is accepted for compatibility and ignored —
 *        headline-on-image is the only mode since Update 10.6.)
 *
 * Generates ONE image for ONE creative, in its own request. Split out
 * of generate.php's run because one request holding a chat call plus
 * several image calls outlives the hosting request-time budget — the
 * server killed it mid-image, before anything could be logged or
 * saved (batches #11-#13, Nov 1 2026). One image per request fits
 * comfortably; a failure loses only that image, is logged, and never
 * fails the batch — the creative stays text-only.
 *
 * Spend discipline: this endpoint is called by the client only as
 * part of an estimate-then-confirm generation run (the confirmed
 * estimate already priced these images), and it still refuses
 * without confirm:true. It is never auto-retried: if the request
 * dies mid-call, OpenAI may have completed (and charged for) an
 * image whose bytes never arrived, so a retry is the operator's
 * explicit decision, not the client's.
 *
 * The image prompt comes from the creative's stored fields
 * (image_prompt, falling back to art_direction), the canvas from its
 * format, the quality from its batch row, and the framing guard from
 * build_final_image_prompt() with headline-on-image framing —
 * always, since Update 10.6 removed the text-free art option.
 * On success the unit cost (pricing table; the image API returns no
 * usage) is added to the batch's actual_cost_usd and logged.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/openai.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = read_json_body();
$action = (string)($data['action'] ?? 'generate');
if ($action !== 'estimate' && empty($data['confirm'])) {
    json_out(['error' => 'confirmation required',
              'hint' => 'Images are generated only as part of a '
                      . 'confirmed generation run. Nothing has run.'], 400);
}
$cid = (int)($data['creative_id'] ?? 0);
// Always 'headline' (Update 10.6): the text-free art option was
// removed entirely; any client-sent image_text is ignored.
$imageText = 'headline';
if ($cid <= 0) {
    json_out(['error' => 'creative_id required'], 400);
}
$creative = fetch_creative($pdo, $cid);
if ($creative === null) {
    json_out(['error' => 'creative not found'], 404);
}
$wsid = (int)$creative['workspace_id'];
if (!empty($creative['image_path'])) {
    json_out(['ok' => true, 'creative_id' => $cid, 'already' => true,
              'image_path' => $creative['image_path'], 'cost_usd' => 0.0]);
}
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
$prompt = trim((string)($creative['image_prompt'] ?? '')) !== ''
    ? (string)$creative['image_prompt']
    : (string)($creative['art_direction'] ?? '');
if (trim($prompt) === '') {
    if ($action !== 'estimate') {
        log_ledger($pdo, $wsid, 'art_gen', 'openai',
            (string)(get_route($pdo, 'art_gen')['model']
                     ?: 'gpt-image-2.5-sunburst'), 'error',
            'creative #' . $cid . ': no image prompt or art '
            . 'direction — nothing generated');
    }
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'creative has no image prompt or art direction']);
}
if ($action === 'estimate') {
    json_out(['ok' => true, 'creative_id' => $cid,
              'cost_usd' => image_unit_price($quality, '1024x1024'),
              'size' => '1024x1024', 'quality' => $quality]);
}
$artRoute = get_route($pdo, 'art_gen');
$artModel = (string)($artRoute['model'] ?: 'gpt-image-2.5-sunburst');
$apiKey = vault_key($pdo, 'openai');
if ($apiKey === null) {
    log_ledger($pdo, $wsid, 'art_gen', 'openai', $artModel, 'error',
        'creative #' . $cid . ': no OpenAI key in the vault — '
        . 'nothing generated');
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'No OpenAI key in the vault.'], 400);
}
// Template mode (Updates 10.8 + 10.9): the image prompt IS the
// workspace's chosen ad template with the creative's copy slotted
// in. No free-form art, no composition guard, no brand frame — the
// template owns the entire composition. No template = hard stop.
// The operator picks the template in the generate form; an absent
// or unknown key falls back to the workspace's first template.
$tplKey = trim((string)($data['template'] ?? ''));
$template = ad_template_text($pdo, $wsid,
                             $tplKey !== '' ? $tplKey : null);
if ($template === null) {
    log_ledger($pdo, $wsid, 'art_gen', 'openai', $artModel, 'error',
        'creative #' . $cid . ': no image template set for this '
        . 'workspace (free-form art was removed in Update 10.8)');
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'No image template is set for this workspace. '
                  . 'Free-form art generation was removed; a workspace '
                  . 'needs an image template before it can generate '
                  . 'images. Nothing was generated or charged.']);
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
    // CR-PRODUCT-001 slots (Update 10.9). Standing brand facts come
    // from ad_slot_defaults; an unset social proof or integrations
    // value becomes an explicit NONE instruction so the model omits
    // the section instead of inventing a claim.
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
$size = '1024x1024';
$finalPrompt = build_template_image_prompt($template, $slots);
// Real-logo reference (Update 10.9): when the workspace has logo
// files on disk (taken from the brand's own site), they ride along
// as reference images and the prompt says to reproduce them — the
// logo is never invented.
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
               'creative #' . $cid . ': '
               . substr($exc->getMessage(), 0, 240));
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => $exc->getMessage()]);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($bytes);
$extByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg',
              'image/webp' => 'webp'];
$ext = $extByMime[$mime] ?? 'png';
$wsDir = PANEL_ROOT . '/uploads/ws' . $wsid;
if (!is_dir($wsDir)) {
    mkdir($wsDir, 0775, true);
}
$storedName = $cid . '_gen.' . $ext;
$written = is_dir($wsDir)
    ? @file_put_contents($wsDir . '/' . $storedName, $bytes) : false;
if ($written === false) {
    // Never record an image that is not actually on disk.
    log_ledger($pdo, $wsid, 'art_gen', 'openai', $artModel, 'error',
               'creative #' . $cid . ': image bytes received but the '
               . 'file write failed in uploads/ws' . $wsid);
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'Image generated but could not be saved under '
                       . 'uploads/ (folder not writable on the server).']);
}
$imagePath = 'uploads/ws' . $wsid . '/' . $storedName;
$pdo->prepare('UPDATE creatives SET image_path = ? WHERE id = ?')
    ->execute([$imagePath, $cid]);
$unit = image_unit_price($quality, $size);
if ($batchId > 0) {
    $pdo->prepare(
        'UPDATE generation_batches SET actual_cost_usd = '
        . 'actual_cost_usd + ? WHERE id = ?')
        ->execute([$unit, $batchId]);
}
log_ledger($pdo, $wsid, 'art_gen', 'openai', $artModel, 'ok',
    sprintf('creative #%d: image %s cost $%.4f (price table × count; '
        . 'the image API returns no usage)', $cid, $size, $unit));
json_out(['ok' => true, 'creative_id' => $cid,
          'image_path' => $imagePath, 'cost_usd' => $unit]);
