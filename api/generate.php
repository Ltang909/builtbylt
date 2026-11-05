<?php
/**
 * Generation endpoint (Step 2) — MVP build.
 *   POST api/generate.php {action: "estimate", workspace_id,
 *        experiment_id?, hypothesis, variants=6, images=2,
 *        copy_model?, image_quality="medium",
 *        image_text: accepted but ignored — always "headline"
 *        since Update 10.6 removed the text-free art option}
 *        Itemized cost ESTIMATE. Makes NO provider calls and writes
 *        nothing. Every line is labeled an estimate.
 *   POST api/generate.php {action: "run", ...same..., confirm: true}
 *        Refuses without confirm:true (estimate-then-confirm gate —
 *        Leon's hard rule: no provider spend without an on-screen
 *        estimate and an explicit confirmation first).
 *        Creates a generation_batches row, calls OpenAI chat for the
 *        copy variants, inserts them as creatives in the draft state
 *        ('generated') stamped with the batch id and their intended
 *        compositing template (tmpl-gen-<format>), and finalizes the
 *        batch with the copy cost. Images are generated separately:
 *        the response carries pending_images (the first `images`
 *        creative ids) and the client calls api/generate_image.php
 *        once per creative (Update 10.5 — one request per image, so
 *        no single request has to outlive the hosting time budget).
 *        verbatim (billing/quota included) and are recorded on the
 *        batch row and in the call ledger. Image failures never fail
 *        the batch: the creative stays text-only and the note says why.
 *   POST api/generate.php {action: "batch", id}
 *        Returns one batch row + its creatives (with verdicts).
 *   POST api/generate.php {action: "batches", workspace_id}
 *        Returns the workspace's batch rows (id, experiment_id,
 *        hypothesis, status, counts, created_at) — the Creative
 *        Queue builds its batch headers from these.
 *   POST api/generate.php {action: "clear_batch", batch_id,
 *        confirm: true} / {action: "clear_queue", workspace_id,
 *        confirm: true}
 *        Update 3 clearing. Destructive, POST-only, session-gated,
 *        refuses without confirm:true, and calls NO provider.
 *        clear_batch removes one batch's creatives (+ their verdicts
 *        and performance rows), best-effort-unlinks their image
 *        files, and removes the batch row. clear_queue does the same
 *        for ALL batched creatives in the workspace; unbatched
 *        (seeded/manual) creatives are never selected, and the
 *        response says how many were left untouched.
 *
 * Costs: estimates come from lib/pricing.php heuristics. Actuals are
 * computed from the REAL usage the chat API returns; image actuals are
 * the pricing table × count (the image API returns no usage object).
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/openai.php';
require_once __DIR__ . '/../lib/learnings.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = read_json_body();
$action = (string)($data['action'] ?? '');

// Documented estimate heuristics for the copy call: a fixed-size
// prompt (tokens + rules + guideline excerpt) plus ~220 output tokens
// per variant.
const GEN_EST_INPUT_TOKENS = 1200;
const GEN_EST_OUTPUT_TOKENS_PER_VARIANT = 220;

/** Resolve + clamp the generation parameters shared by estimate/run. */
function gen_params(PDO $pdo, array $data): array {
    $copyRoute = get_route($pdo, 'copy_gen');
    $artRoute = get_route($pdo, 'art_gen');
    $variants = (int)($data['variants'] ?? 6);
    $variants = max(1, min(24, $variants));
    $images = (int)($data['images'] ?? 2);
    $images = max(0, min($variants, $images));
    $quality = (string)($data['image_quality'] ?? 'medium');
    if (!isset(IMAGE_PRICES_USD[$quality])) {
        $quality = 'medium';
    }
    // Image text is ALWAYS 'headline' (Update 10.6): Leon removed
    // the text-free art option entirely ("there's no world where we
    // should generate text free art", 2026-11-01). Any submitted
    // image_text value is ignored.
    $imageText = 'headline';
    return [
        'variants' => $variants,
        'images' => $images,
        'image_quality' => $quality,
        'image_text' => $imageText,
        'has_template' => ($wsidTpl = resolve_workspace($pdo, $data))
            !== null
            && workspace_image_template($pdo, $wsidTpl) !== null,
        'copy_provider' => $copyRoute['provider'] ?: 'openai',
        'copy_model' => (string)($data['copy_model']
            ?? ($copyRoute['model'] ?: 'gpt-4o-mini')),
        'art_model' => (string)($artRoute['model'] ?: 'gpt-image-2.5-sunburst'),
    ];
}

/** Itemized estimate lines + total. Pure math — no provider calls. */
function gen_estimate(array $p): array {
    $copyCost = est_copy_cost($p['copy_model'], GEN_EST_INPUT_TOKENS,
        GEN_EST_OUTPUT_TOKENS_PER_VARIANT * $p['variants']);
    $imageCost = est_image_cost($p['image_quality'], $p['images']);
    $verifyCost = est_verify_cost(
        $p['variants'], JEV_EST_INPUT_TOKENS_PER_CREATIVE);
    $lines = [
        ['item' => 'copy', 'label' => 'estimate',
         'detail' => sprintf('%s: ~%d in / ~%d out tokens for %d variants',
             $p['copy_model'], GEN_EST_INPUT_TOKENS,
             GEN_EST_OUTPUT_TOKENS_PER_VARIANT * $p['variants'],
             $p['variants']),
         'cost_usd' => round($copyCost, 6)],
        ['item' => 'images', 'label' => 'estimate',
         'detail' => sprintf('%s: %d image(s) at %s quality; %s; '
             . 'priced at the largest size',
             $p['art_model'], $p['images'], $p['image_quality'],
             !empty($p['has_template'])
                 ? 'rendered from the workspace ad template '
                     . '(square 1024x1024), headline on image'
                 : 'headline rendered on the image; canvas follows '
                     . 'each variant\'s format'),
         'cost_usd' => round($imageCost, 6)],
        ['item' => 'verify', 'label' => 'estimate',
         'detail' => sprintf('Jev verify of the %d new creatives '
             . '(priced separately at verify time; shown for the full '
             . 'loop)', $p['variants']),
         'cost_usd' => round($verifyCost, 6)],
    ];
    return [
        'estimate' => true,
        'label' => 'estimate',
        'currency' => 'USD',
        'lines' => $lines,
        'total_usd' => round($copyCost + $imageCost + $verifyCost, 6),
        'note' => 'Estimate only — list prices from lib/pricing.php '
            . '(checked 2026-10-17). Nothing has run and nothing has '
            . 'been charged. Actuals are recorded per batch from the '
            . 'usage the API returns.',
    ];
}

function batch_out(PDO $pdo, int $batchId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM generation_batches WHERE id = ?');
    $stmt->execute([$batchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['workspace_id'] = (int)$row['workspace_id'];
    $row['experiment_id'] = $row['experiment_id'] !== null
        ? (int)$row['experiment_id'] : null;
    $row['variants'] = (int)$row['variants'];
    $row['images'] = (int)$row['images'];
    return $row;
}

// --- estimate ---------------------------------------------------------------
if ($action === 'estimate') {
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $p = gen_params($pdo, $data);
    $est = gen_estimate($p);
    $est['workspace_id'] = $wsid;
    $est['copy_model'] = $p['copy_model'];
    $est['art_model'] = $p['art_model'];
    $est['image_quality'] = $p['image_quality'];
    $est['variants'] = $p['variants'];
    $est['images'] = $p['images'];
    // Learnings loop (Update 4): how many workspace learnings the
    // prompt will carry if the run is confirmed (0 when the toggle
    // is off or nothing has been learned yet). Capped at 12, the
    // same cap the prompt builder applies.
    $est['learnings_count'] = learnings_in_prompt_enabled($pdo, $wsid)
        ? min(12, workspace_learnings_count($pdo, $wsid)) : 0;
    json_out($est);
}

// Everything below writes rows and/or spends provider budget.
if (!panel_table_exists($pdo, 'generation_batches')
    || !panel_column_exists($pdo, 'creatives', 'batch_id')) {
    migration_required('Generation');
}

// --- batch list (Queue headers) ----------------------------------------------
if ($action === 'batches') {
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'SELECT id, experiment_id, hypothesis, status, variants, '
        . 'images, created_at FROM generation_batches '
        . 'WHERE workspace_id = ? ORDER BY id DESC');
    $stmt->execute([$wsid]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['id'] = (int)$r['id'];
        $r['workspace_id'] = $wsid;
        $r['experiment_id'] = $r['experiment_id'] !== null
            ? (int)$r['experiment_id'] : null;
        $r['variants'] = (int)$r['variants'];
        $r['images'] = (int)$r['images'];
        $rows[] = $r;
    }
    json_out(['batches' => $rows]);
}

// --- clearing (Update 3) --------------------------------------------------------

/** Best-effort unlink of a creative image file, confined to uploads/. */
function clear_unlink_image(?string $imagePath): bool {
    if ($imagePath === null || trim($imagePath) === '') {
        return false;
    }
    $uploadsRoot = realpath(PANEL_ROOT . '/uploads');
    if ($uploadsRoot === false) {
        return false;
    }
    $full = realpath(PANEL_ROOT . '/' . ltrim($imagePath, '/'));
    $uploadsPrefix = $uploadsRoot . DIRECTORY_SEPARATOR;
    if ($full === false
        || substr($full, 0, strlen($uploadsPrefix)) !== $uploadsPrefix) {
        return false;
    }
    return @unlink($full);
}

/**
 * Remove the given creatives and everything that hangs off them
 * (verdicts, performance rows, image files). The caller selects $ids
 * with a workspace/batch-scoped query first; the id list is int-cast
 * before interpolation, and every statement below carries its own
 * scope predicate on top of that. Returns deletion counts.
 */
function clear_delete_creatives(PDO $pdo, int $wsid, array $ids): array {
    $counts = ['creatives' => 0, 'verdicts' => 0, 'performance' => 0,
               'images_unlinked' => 0];
    if (!$ids) {
        return $counts;
    }
    $idList = implode(',', array_map('intval', $ids));
    $sel = $pdo->prepare(
        "SELECT image_path FROM creatives WHERE id IN ($idList)");
    $sel->execute();
    $paths = $sel->fetchAll(PDO::FETCH_COLUMN);
    $dv = $pdo->prepare(
        "DELETE FROM verdicts WHERE creative_id IN ($idList)");
    $dv->execute();
    $counts['verdicts'] = $dv->rowCount();
    $dp = $pdo->prepare(
        "DELETE FROM performance WHERE creative_id IN ($idList)");
    $dp->execute();
    $counts['performance'] = $dp->rowCount();
    $dc = $pdo->prepare(
        "DELETE FROM creatives WHERE workspace_id = ? "
        . "AND id IN ($idList)");
    $dc->execute([$wsid]);
    $counts['creatives'] = $dc->rowCount();
    foreach ($paths as $p) {
        if (clear_unlink_image(is_string($p) ? $p : null)) {
            $counts['images_unlinked']++;
        }
    }
    return $counts;
}

if ($action === 'clear_batch' || $action === 'clear_queue') {
    if (empty($data['confirm'])) {
        json_out(['error' => 'confirmation required',
                  'hint' => 'Clearing is destructive. Re-send with '
                          . 'confirm:true only after the operator '
                          . 'confirms. Nothing was deleted.'], 400);
    }
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    if ($action === 'clear_batch') {
        $batchId = (int)($data['batch_id'] ?? 0);
        $bs = $pdo->prepare(
            'SELECT id FROM generation_batches WHERE id = ? '
            . 'AND workspace_id = ?');
        $bs->execute([$batchId, $wsid]);
        if ($bs->fetch(PDO::FETCH_ASSOC) === false) {
            json_out(['error' => 'batch not found in this workspace'],
                     404);
        }
        $cs = $pdo->prepare(
            'SELECT id FROM creatives WHERE batch_id = ? '
            . 'AND workspace_id = ?');
        $cs->execute([$batchId, $wsid]);
        $ids = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN));
        $pdo->beginTransaction();
        try {
            $counts = clear_delete_creatives($pdo, $wsid, $ids);
            $db = $pdo->prepare(
                'DELETE FROM generation_batches WHERE id = ? '
                . 'AND workspace_id = ?');
            $db->execute([$batchId, $wsid]);
            $counts['batches'] = $db->rowCount();
            $pdo->commit();
        } catch (Throwable $exc) {
            $pdo->rollBack();
            json_out(['error' => 'clear failed: '
                      . $exc->getMessage()], 500);
        }
        json_out(['ok' => true, 'action' => 'clear_batch',
                  'batch_id' => $batchId, 'deleted' => $counts]);
    }
    // clear_queue: ALL batched creatives in the workspace. Rows with
    // batch_id IS NULL (seeded / hand-entered) are excluded by the
    // selection itself, so they cannot be touched here.
    $cs = $pdo->prepare(
        'SELECT id FROM creatives WHERE workspace_id = ? '
        . 'AND batch_id IS NOT NULL');
    $cs->execute([$wsid]);
    $ids = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN));
    $us = $pdo->prepare(
        'SELECT COUNT(*) FROM creatives WHERE workspace_id = ? '
        . 'AND batch_id IS NULL');
    $us->execute([$wsid]);
    $untouched = (int)$us->fetchColumn();
    $pdo->beginTransaction();
    try {
        $counts = clear_delete_creatives($pdo, $wsid, $ids);
        $db = $pdo->prepare(
            'DELETE FROM generation_batches WHERE workspace_id = ?');
        $db->execute([$wsid]);
        $counts['batches'] = $db->rowCount();
        $pdo->commit();
    } catch (Throwable $exc) {
        $pdo->rollBack();
        json_out(['error' => 'clear failed: ' . $exc->getMessage()], 500);
    }
    json_out(['ok' => true, 'action' => 'clear_queue',
              'deleted' => $counts,
              'untouched_unbatched' => $untouched,
              'note' => 'Only generated (batched) work was deleted. '
                  . 'Manual / seeded creatives were not touched.']);
}

// --- batch lookup -------------------------------------------------------------
if ($action === 'batch') {
    $batchId = (int)($data['id'] ?? 0);
    $batch = batch_out($pdo, $batchId);
    if ($batch === null) {
        json_out(['error' => 'batch not found'], 404);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM creatives WHERE batch_id = ? ORDER BY id');
    $stmt->execute([$batchId]);
    $creatives = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $row['workspace_id'] = (int)$row['workspace_id'];
        $creatives[] = creative_with_verdicts($pdo, $row);
    }
    json_out(['batch' => $batch, 'creatives' => $creatives]);
}

if ($action !== 'run') {
    json_out(['error' => 'unknown action'], 400);
}

// --- run (estimate-then-confirm gate) -----------------------------------------
if (empty($data['confirm'])) {
    json_out(['error' => 'confirmation required',
              'hint' => 'Call action=estimate first, show the estimate, '
                      . 'and re-send with confirm:true only after the '
                      . 'operator confirms. Nothing has run.'], 400);
}
$wsid = resolve_workspace($pdo, $data);
if ($wsid === null) {
    json_out(['error' => 'workspace not found'], 404);
}
$hypothesis = trim((string)($data['hypothesis'] ?? ''));
if ($hypothesis === '') {
    json_out(['error' => 'hypothesis is required'], 400);
}
$experimentId = null;
if (isset($data['experiment_id']) && $data['experiment_id'] !== '') {
    $experimentId = (int)$data['experiment_id'];
    $es = $pdo->prepare(
        'SELECT id FROM experiments WHERE id = ? AND workspace_id = ?');
    $es->execute([$experimentId, $wsid]);
    if ($es->fetch(PDO::FETCH_ASSOC) === false) {
        json_out(['error' => 'experiment not found in this workspace'], 404);
    }
}
$p = gen_params($pdo, $data);
if ($p['copy_provider'] !== 'openai') {
    json_out(['ok' => false, 'status' => 'unsupported_route',
              'message' => 'copy_gen is routed to '
                  . $p['copy_provider'] . '; generation is implemented '
                  . 'for openai only in this build. Change the route in '
                  . 'Settings → Provider routing.'], 400);
}
$apiKey = vault_key($pdo, 'openai');
if ($apiKey === null) {
    $msg = 'Generation needs an OpenAI key: add one in Settings → API '
        . 'keys (provider: openai) first. Nothing was generated.';
    log_ledger($pdo, $wsid, 'copy_gen', 'openai', $p['copy_model'],
               'skipped', $msg);
    json_out(['ok' => false, 'status' => 'needs_key', 'message' => $msg]);
}

$est = gen_estimate($p);
// The estimate a batch is held to covers generation only (copy +
// images); verify is estimated and confirmed separately at verify time.
$estGenOnly = round($est['lines'][0]['cost_usd']
                  + $est['lines'][1]['cost_usd'], 4);
$stmt = $pdo->prepare(
    'INSERT INTO generation_batches (workspace_id, experiment_id, '
    . 'hypothesis, copy_model, image_quality, variants, images, status, '
    . 'est_cost_usd) VALUES (?, ?, ?, ?, ?, ?, ?, \'running\', ?)');
$stmt->execute([$wsid, $experimentId, $hypothesis, $p['copy_model'],
                $p['image_quality'], $p['variants'], $p['images'],
                $estGenOnly]);
$batchId = (int)$pdo->lastInsertId();

$failBatch = function (string $status, string $error) use ($pdo, $batchId) {
    $pdo->prepare(
        'UPDATE generation_batches SET status = ?, error = ? WHERE id = ?')
        ->execute([$status, $error, $batchId]);
};

// Grounding: workspace tokens + the games/formats the workspace's own
// rules know about. Nothing is invented here.
$tokens = get_tokens($pdo, $wsid);
$wsRow = $pdo->prepare('SELECT name FROM workspaces WHERE id = ?');
$wsRow->execute([$wsid]);
$wsName = $wsRow->fetch(PDO::FETCH_ASSOC);
$tokens['brand_name'] = $wsName !== false ? (string)$wsName['name'] : 'the brand';
$rules = get_rules($pdo, $wsid);
foreach ($rules as $r) {
    if ($r['rule_id'] === 'R8' && !empty($r['config']['known_entities'])) {
        $tokens['games'] = array_values((array)$r['config']['known_entities']);
    }
    if ($r['rule_id'] === 'R11' && !empty($r['config']['formats'])) {
        $tokens['formats'] = array_values((array)$r['config']['formats']);
    }
}
$gs = $pdo->prepare('SELECT raw_markdown FROM guidelines WHERE workspace_id = ?');
$gs->execute([$wsid]);
$gRow = $gs->fetch(PDO::FETCH_ASSOC);
$guidelineText = $gRow !== false ? (string)$gRow['raw_markdown'] : null;

// Learnings loop, prompt-in half (Update 4): the workspace's most
// recent learnings ride into the brief when the toggle is on, so
// generation starts from what previous experiments taught instead
// of repeating their failure patterns.
if (learnings_in_prompt_enabled($pdo, $wsid)) {
    $tokens['learnings'] = workspace_learning_lines($pdo, $wsid, 12);
}

$prompts = build_generation_prompt(
    $tokens, $guidelineText, $hypothesis, $p['variants'],
    $p['image_text']);

try {
    [$decoded, $usage] = openai_chat_json(
        $apiKey, $p['copy_model'], $prompts['system'], $prompts['user']);
} catch (Throwable $exc) {
    $failBatch('failed', $exc->getMessage());
    log_ledger($pdo, $wsid, 'copy_gen', 'openai', $p['copy_model'],
               'error', 'batch #' . $batchId . ': '
               . substr($exc->getMessage(), 0, 280));
    json_out(['ok' => false, 'status' => 'provider_error',
              'error' => $exc->getMessage(), 'batch_id' => $batchId], 502);
}
$chatCost = est_copy_cost(
    $p['copy_model'], $usage['input_tokens'], $usage['output_tokens']);
log_ledger($pdo, $wsid, 'copy_gen', 'openai', $p['copy_model'], 'ok',
    sprintf('batch #%d: in=%d out=%d tokens; chat cost $%.4f '
        . '(actual, from API usage)', $batchId, $usage['input_tokens'],
        $usage['output_tokens'], $chatCost));

// Spend-gate principle: one confirmed run = ONE copy call, and
// whatever it returns is what the run uses. A short or over-long
// return is accepted as-is — select_variants() trims extras and the
// batch notes record the shortfall — and is NEVER re-rolled: a
// retry would be a second provider call spending money that was
// never confirmed. Hard failures stay hard: no "variants" array,
// zero usable variants, an unparseable response, or a provider
// error (handled above) all fail the batch as before.
$rawVariants = $decoded['variants'] ?? null;
if (!is_array($rawVariants)) {
    $msg = 'Generation response had no "variants" array.';
    $failBatch('failed', $msg);
    log_ledger($pdo, $wsid, 'copy_gen', 'openai', $p['copy_model'],
               'error', 'batch #' . $batchId . ': '
               . substr($msg, 0, 280));
    json_out(['ok' => false, 'status' => 'malformed_response',
              'error' => $msg, 'batch_id' => $batchId], 502);
}
try {
    $selection = select_variants($rawVariants, $p['variants']);
} catch (Throwable $exc) {
    $failBatch('failed', $exc->getMessage());
    log_ledger($pdo, $wsid, 'copy_gen', 'openai', $p['copy_model'],
               'error', 'batch #' . $batchId . ': '
               . substr($exc->getMessage(), 0, 280));
    json_out(['ok' => false, 'status' => 'malformed_response',
              'error' => $exc->getMessage(), 'batch_id' => $batchId], 502);
}
$variants = $selection['use'];
$countNote = $selection['note'];

// Insert the creatives in the panel's existing draft state.
$ins = $pdo->prepare(
    'INSERT INTO creatives (workspace_id, experiment_id, batch_id, kind, '
    . 'game_name, headline, primary_text, description, cta, offer_text, '
    . 'template_id, format, art_direction, image_prompt, status) '
    . 'VALUES (?, ?, ?, \'static\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '
    . '\'generated\')');
$createdIds = [];
foreach ($variants as $v) {
    // Template mode (Update 10.8): the workspace template is a
    // square composition, so template workspaces' creatives are
    // 1:1 regardless of the format the copy model picked.
    $fmt = !empty($p['has_template']) ? '1:1' : (string)$v['format'];
    $fmtSlug = preg_replace('/[^A-Za-z0-9]/', '', $fmt);
    $ins->execute([
        $wsid, $experimentId, $batchId, $v['game_name'], $v['headline'],
        $v['primary_text'], $v['description'], $v['cta'], $v['offer_text'],
        'tmpl-gen-' . ($fmtSlug !== '' ? $fmtSlug : 'draft'),
        $fmt, $v['art_direction'], $v['image_prompt'],
    ]);
    $createdIds[] = (int)$pdo->lastInsertId();
}

// Images are NOT generated inside this request (Update 10.5). One
// request holding a chat call plus several image calls outlives the
// hosting request-time budget: the server kills it mid-image, before
// anything can be logged or saved (batches #11-#13, Nov 1 2026 —
// #13 landed its first image, then died during the second). So the
// run finalizes with the copy only and hands the client the pending
// creative ids; the client calls api/generate_image.php once per
// creative, each image in its own request with its own time budget,
// and that endpoint adds its unit cost to this batch's
// actual_cost_usd as images land. The variant-count note
// (shortfall/trim/drops, if any) rides the batch's error column,
// which the UI surfaces as "Notes:", and marks the batch 'partial'.
// Update 10.7 lint: deterministic guardrails on the saved copy —
// ungrounded numbers and off-allowlist CTAs ride the batch notes so
// they are visible before anyone verifies or approves the batch.
$lintNotes = [];
foreach ($createdIds as $cid) {
    $saved = fetch_creative($pdo, $cid);
    if ($saved === null) {
        continue;
    }
    foreach (lint_flags_for($pdo, $saved) as $flag) {
        $lintNotes[] = 'Lint: creative #' . $cid . ' — '
            . $flag['message'];
    }
}
$notes = array_merge(
    $countNote !== null ? [$countNote] : [], $lintNotes);
$pendingImages = array_slice($createdIds, 0, $p['images']);
$actual = round($chatCost, 4);
$pdo->prepare(
    'UPDATE generation_batches SET status = ?, input_tokens = ?, '
    . 'output_tokens = ?, actual_cost_usd = ?, error = ? WHERE id = ?')
    ->execute([
        $notes ? 'partial' : 'done',
        $usage['input_tokens'], $usage['output_tokens'], $actual,
        $notes ? implode(' ', $notes) : null, $batchId,
    ]);

$creatives = [];
foreach ($createdIds as $cid) {
    $row = fetch_creative($pdo, $cid);
    $row['id'] = (int)$row['id'];
    $row['workspace_id'] = (int)$row['workspace_id'];
    $creatives[] = creative_with_verdicts($pdo, $row);
}
json_out([
    'ok' => true,
    'batch' => batch_out($pdo, $batchId),
    'creatives' => $creatives,
    'requested' => $p['variants'],
    'created' => count($createdIds),
    'images_made' => 0,
    'pending_images' => $pendingImages,
    'actual_cost_usd' => $actual,
]);
