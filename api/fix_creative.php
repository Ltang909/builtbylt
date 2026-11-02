<?php
/**
 * Fix rerun endpoint (Update 10.10).
 *   POST api/fix_creative.php {action: "estimate_fix", creative_id}
 *        Cost of ONE copy-model call to rewrite the creative so it
 *        stops failing the rules it failed. Runs nothing.
 *   POST api/fix_creative.php {action: "fix", creative_id,
 *        confirm: true}
 *        The rerun: the failed verdicts (with the detail assembled
 *        on read — rule name, Jev note, local diagnosis) become the
 *        brief; the routed copy model rewrites the copy fields;
 *        the creative is updated in place; its verdicts are
 *        cleared (they described the old copy — it is unverified
 *        again until Step 3a runs); if the headline changed, the
 *        stored image is detached, because template images render
 *        the headline and would otherwise show stale copy.
 *
 * Same estimate-then-confirm gate as every provider call in this
 * panel. One confirmed fix = one provider call, never re-rolled.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/openai.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}
$data = read_json_body();
$action = (string)($data['action'] ?? '');
$cid = (int)($data['creative_id'] ?? 0);
if ($cid <= 0) {
    json_out(['error' => 'creative_id required'], 400);
}
$creative = fetch_creative($pdo, $cid);
if ($creative === null) {
    json_out(['error' => 'creative not found'], 404);
}
$wsid = (int)$creative['workspace_id'];

$stmt = $pdo->prepare(
    'SELECT * FROM verdicts WHERE creative_id = ? AND verdict = ? '
    . 'ORDER BY CAST(SUBSTRING(rule_id, 2) AS UNSIGNED)');
$stmt->execute([$cid, 'fail']);
$failures = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($failures === []) {
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'This creative has no failed verdicts — '
                  . 'there is nothing for a fix rerun to fix.']);
}
$rules = get_rules($pdo, $wsid);
$tokens = get_tokens($pdo, $wsid);
$local = run_checks($creative, $tokens, $rules);
$failures = attach_verdict_details($failures, $rules, $local);

$route = get_route($pdo, 'copy_gen');
$model = (string)($route['model'] ?: 'gpt-4o-mini');

if ($action === 'estimate_fix') {
    // Heuristic, labeled as such: the real usage-based actual is
    // logged after the run, exactly like generation.
    $cost = est_copy_cost($model, 2600, 450);
    json_out(['ok' => true, 'creative_id' => $cid,
              'provider' => (string)($route['provider'] ?? 'openai'),
              'model' => $model,
              'cost_usd' => round($cost, 6),
              'failed_rules' => array_map(
                  fn($f) => (string)$f['rule_id'], $failures),
              'note' => 'One copy-model call (' . $model . ') '
                  . 'rewrites the copy to fix '
                  . implode(', ', array_map(
                      fn($f) => (string)$f['rule_id'], $failures))
                  . '. Estimate uses typical prompt sizes; the '
                  . 'ledger records the actual from API usage.']);
}

if ($action !== 'fix') {
    json_out(['error' => 'unknown action'], 400);
}
if (empty($data['confirm'])) {
    json_out(['error' => 'confirmation required',
              'hint' => 'Fixes run only after the estimate is '
                      . 'confirmed. Nothing has run.'], 400);
}
$apiKey = vault_key($pdo, 'openai');
if ($apiKey === null) {
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => 'No OpenAI key in the vault.'], 400);
}
$gs = $pdo->prepare(
    'SELECT raw_markdown FROM guidelines WHERE workspace_id = ?');
$gs->execute([$wsid]);
$gRow = $gs->fetch(PDO::FETCH_ASSOC);
$guidelineText = $gRow !== false ? (string)$gRow['raw_markdown'] : null;

$prompts = build_fix_prompt($creative, $failures, $tokens,
                            $guidelineText);
try {
    [$decoded, $usage] = openai_chat_json(
        $apiKey, $model, $prompts['system'], $prompts['user']);
} catch (Throwable $exc) {
    log_ledger($pdo, $wsid, 'copy_gen', 'openai', $model, 'error',
        'fix creative #' . $cid . ': '
        . substr($exc->getMessage(), 0, 240));
    json_out(['ok' => false, 'creative_id' => $cid,
              'error' => $exc->getMessage()], 502);
}
$nv = isset($decoded['variant']) && is_array($decoded['variant'])
    ? $decoded['variant'] : $decoded;
$fields = ['headline', 'primary_text', 'description', 'cta',
           'offer_text', 'image_prompt', 'art_direction'];
$new = [];
$changed = [];
foreach ($fields as $field) {
    $val = isset($nv[$field]) && is_string($nv[$field])
        ? trim($nv[$field]) : null;
    $new[$field] = $val !== null && $val !== ''
        ? $val : (string)($creative[$field] ?? '');
    if ($new[$field] !== (string)($creative[$field] ?? '')) {
        $changed[] = $field;
    }
}
$upd = $pdo->prepare(
    'UPDATE creatives SET headline = ?, primary_text = ?, '
    . 'description = ?, cta = ?, offer_text = ?, image_prompt = ?, '
    . 'art_direction = ? WHERE id = ?');
$upd->execute([$new['headline'], $new['primary_text'],
               $new['description'], $new['cta'], $new['offer_text'],
               $new['image_prompt'], $new['art_direction'], $cid]);
$imageDetached = false;
if (in_array('headline', $changed, true)
    && !empty($creative['image_path'])) {
    $pdo->prepare('UPDATE creatives SET image_path = NULL '
                  . 'WHERE id = ?')->execute([$cid]);
    $imageDetached = true;
}
// The old verdicts described the old copy. Clear them; the creative
// returns to "not verified yet" and Step 3a decides the new copy.
$pdo->prepare('DELETE FROM verdicts WHERE creative_id = ?')
    ->execute([$cid]);
$actual = est_copy_cost($model, (int)$usage['input_tokens'],
                        (int)$usage['output_tokens']);
log_ledger($pdo, $wsid, 'copy_gen', 'openai', $model, 'ok',
    sprintf('fix creative #%d: in=%d out=%d tokens; $%.4f actual; '
        . 'changed: %s%s', $cid, (int)$usage['input_tokens'],
        (int)$usage['output_tokens'], $actual,
        $changed !== [] ? implode(', ', $changed) : 'nothing',
        $imageDetached ? '; image detached (headline changed)' : ''));
$fresh = fetch_creative($pdo, $cid);
json_out(['ok' => true, 'creative_id' => $cid,
          'cost_usd' => round($actual, 6),
          'changed' => $changed,
          'image_detached' => $imageDetached,
          'creative' => $fresh !== null
              ? creative_with_verdicts($pdo, $fresh) : null]);
