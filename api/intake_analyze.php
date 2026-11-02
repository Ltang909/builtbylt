<?php
/**
 * Intake analysis endpoint (Update 8).
 *
 *   POST api/intake_analyze.php
 *     {action: "estimate", workspace_id, creative_ids: [...]}
 *       -> {creatives, model, lines, total_usd} — pure math over the
 *          corpus the run would read. Calls NO provider.
 *     {action: "run", workspace_id, creative_ids: [...],
 *      confirm: true}
 *       -> refuses without confirm:true (the estimate-then-confirm
 *          gate). One routed copy-model call reads the batch — copy
 *          plus performance totals — and returns pattern learnings,
 *          which are written to the learnings table (verdicts
 *          "intake: keep" / "intake: avoid") so they ride into the
 *          next generation prompt like any other learning. Actuals
 *          come from the chat usage the API returns and are written
 *          to the call ledger.
 *
 * The model reads copy, numbers, AND the images themselves (vision
 * pass): up to INTAKE_MAX_IMAGES stored creative images ride the
 * call at low detail, each labeled with its creative id so visual
 * patterns can be tied back to results. Creatives past the cap, or
 * whose files are missing, are still analyzed on copy + stats.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/openai.php';
require_once __DIR__ . '/../lib/pricing.php';
require_once __DIR__ . '/../lib/learnings.php';
require_api_auth($pdo);

$data = read_json_body();
$action = (string)($data['action'] ?? '');
$wsid = resolve_workspace($pdo, $data);
if ($wsid === null) {
    json_out(['error' => 'workspace not found'], 404);
}

$route = get_route($pdo, 'copy_gen');
$model = (string)($route['model'] ?: 'gpt-4o-mini');
$provider = (string)($route['provider'] ?: 'openai');

const INTAKE_MAX_CREATIVES = 60;
const INTAKE_EST_OUTPUT_TOKENS = 1200;
const INTAKE_MAX_IMAGES = 12;
// Estimate heuristic per image at low detail. Deliberately high
// (the house rule: estimates err high); actuals come from usage.
const INTAKE_VISION_TOKENS_PER_IMAGE = 1000;
const INTAKE_MAX_IMAGE_BYTES = 8388608; // 8 MB

/** Corpus blocks + vision images for the given creatives. */
function intake_corpus(PDO $pdo, int $wsid, array $ids): array {
    $ids = array_slice(array_values(array_unique(array_map('intval', $ids))),
                       0, INTAKE_MAX_CREATIVES);
    if (!$ids) {
        return ['', 0, []];
    }
    $in = implode(',', $ids);
    $rows = $pdo->query(
        "SELECT * FROM creatives WHERE workspace_id = $wsid "
        . "AND id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $blocks = [];
    $images = [];
    $uploadsRoot = realpath(PANEL_ROOT . '/uploads');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($rows as $c) {
        $ts = $pdo->prepare(
            'SELECT COALESCE(SUM(spend),0) AS spend, '
            . 'COALESCE(SUM(purchasers),0) AS purchasers '
            . 'FROM performance WHERE creative_id = ?');
        $ts->execute([(int)$c['id']]);
        $t = $ts->fetch(PDO::FETCH_ASSOC) ?: [];
        $spend = (float)($t['spend'] ?? 0);
        $purch = (int)($t['purchasers'] ?? 0);
        $stats = 'no stats recorded';
        if ($spend > 0 || $purch > 0) {
            $stats = 'spend $' . number_format($spend, 2)
                . ', customers ' . $purch
                . ($purch > 0
                    ? ', CAC $' . number_format($spend / $purch, 2)
                    : '')
                . ', computed verdict: ' . ($c['perf_status'] ?: 'none');
        }
        $imageNote = 'no';
        if (!empty($c['image_path'])) {
            $imageNote = 'yes (file not readable, copy only)';
            $full = realpath(PANEL_ROOT . '/' . $c['image_path']);
            if ($full !== false && $uploadsRoot !== false
                && strpos($full, $uploadsRoot) === 0 && is_file($full)
                && count($images) < INTAKE_MAX_IMAGES
                && filesize($full) <= INTAKE_MAX_IMAGE_BYTES) {
                $bytes = file_get_contents($full);
                $mime = $finfo->buffer($bytes);
                if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    $images[] = [
                        'label' => 'Image for creative #' . (int)$c['id'] . ':',
                        'mime' => $mime,
                        'base64' => base64_encode($bytes),
                    ];
                    $imageNote = 'yes (shown to you in this call)';
                }
            } elseif (count($images) >= INTAKE_MAX_IMAGES) {
                $imageNote = 'yes (past the image cap for this run, copy only)';
            }
        }
        $blocks[] = '#' . (int)$c['id']
            . ' [' . ($c['game_name'] ?: 'unnamed') . ']'
            . ' format ' . ($c['format'] ?: '?')
            . ', image: ' . $imageNote
            . "\nHeadline: " . substr((string)($c['headline'] ?? ''), 0, 200)
            . "\nPrimary text: " . substr((string)($c['primary_text'] ?? ''), 0, 500)
            . "\nDescription: " . substr((string)($c['description'] ?? ''), 0, 300)
            . "\nCTA: " . ($c['cta'] ?? '') . ' · Offer: ' . ($c['offer_text'] ?? '')
            . "\nResults: " . $stats;
    }
    return [implode("\n\n", $blocks), count($blocks), $images];
}

function intake_prompts(string $corpus, bool $vision): array {
    $system = 'You are a performance-marketing analyst. You read a batch '
        . 'of ad creatives that already ran, with their results, and '
        . 'extract the patterns a creative team should repeat or avoid. '
        . 'Only claim patterns the data supports; when most creatives '
        . 'lack stats, say so and lean on copy patterns only. Never '
        . 'invent numbers. Answer with JSON only.';
    $visual = $vision
        ? ' The creatives\' images follow the text, each labeled with '
          . 'its creative id: weigh what they show — subject, layout, '
          . 'palette, any text baked into the image — and flag '
          . 'mismatches between an image and its copy.'
        : '';
    $user = "Here is the batch:\n\n" . $corpus . "\n\n"
        . 'Return JSON: {"summary": "3-5 sentences on what this batch '
        . 'shows", "learnings": [{"direction": "keep" or "avoid", '
        . '"text": "one concrete, self-contained sentence the team can '
        . 'act on, naming the pattern and the evidence"}]}. '
        . 'At most 8 learnings, strongest first. Compare winners and '
        . 'losers on CAC where stats exist.'
        . $visual;
    return [$system, $user];
}

$ids = isset($data['creative_ids']) && is_array($data['creative_ids'])
    ? $data['creative_ids'] : [];
[$corpus, $count, $images] = intake_corpus($pdo, $wsid, $ids);
if ($count === 0) {
    json_out(['ok' => false,
              'error' => 'No creatives to analyze in this workspace.'], 400);
}
$imageCount = count($images);
[$system, $user] = intake_prompts($corpus, $imageCount > 0);
$estIn = (int)ceil((strlen($system) + strlen($user)) / 3.5)
    + $imageCount * INTAKE_VISION_TOKENS_PER_IMAGE;
$estCost = est_copy_cost($model, $estIn, INTAKE_EST_OUTPUT_TOKENS);

if ($action === 'estimate') {
    $lines = [[
        'item' => 'analysis',
        'label' => 'estimate',
        'detail' => "$count creative(s), ~$estIn input tokens est.",
        'cost_usd' => $estCost,
    ]];
    if ($imageCount > 0) {
        $lines[] = [
            'item' => 'vision',
            'label' => 'estimate',
            'detail' => "$imageCount image(s) read at low detail "
                . '(included in the token estimate above).',
            'cost_usd' => 0.0,
        ];
    }
    json_out([
        'estimate' => true,
        'creatives' => $count,
        'images' => $imageCount,
        'model' => $model,
        'lines' => $lines,
        'total_usd' => $estCost,
    ]);
}

if ($action === 'run') {
    if (empty($data['confirm'])) {
        json_out(['ok' => false, 'status' => 'needs_confirm',
                  'message' => 'Analysis runs a paid model call. Fetch '
                      . 'the estimate and resend with confirm:true.'], 409);
    }
    if ($provider !== 'openai') {
        json_out(['ok' => false, 'status' => 'unsupported_route',
                  'message' => 'copy_gen is routed to ' . $provider
                      . '; the analysis call is implemented for openai '
                      . 'only in this build.'], 400);
    }
    $apiKey = vault_key($pdo, 'openai');
    if (!$apiKey) {
        json_out(['ok' => false, 'status' => 'no_key',
                  'message' => 'No OpenAI key in the vault. Add one in '
                      . 'Settings → API keys first. Nothing ran.'], 400);
    }
    [$decoded, $usage] = $imageCount > 0
        ? openai_chat_vision_json($apiKey, $model, $system, $user, $images)
        : openai_chat_json($apiKey, $model, $system, $user);
    $actual = est_copy_cost($model, (int)($usage['input_tokens'] ?? 0),
                            (int)($usage['output_tokens'] ?? 0));
    log_ledger($pdo, $wsid, 'intake_analysis', 'openai', $model, 'ok',
               'actual $' . number_format($actual, 4)
               . ' est $' . number_format($estCost, 4)
               . " over $count creative(s), $imageCount image(s)");
    if (!is_array($decoded)) {
        json_out(['ok' => false, 'status' => 'bad_response',
                  'message' => 'The model did not return usable JSON. '
                      . 'Cost so far: $' . number_format($actual, 4) . '.'],
                 502);
    }
    $summary = trim((string)($decoded['summary'] ?? ''));
    $written = [];
    foreach ((array)($decoded['learnings'] ?? []) as $l) {
        if (count($written) >= 8) {
            break;
        }
        $text = is_array($l) ? trim((string)($l['text'] ?? ''))
                             : trim((string)$l);
        if ($text === '') {
            continue;
        }
        $dir = is_array($l) && ($l['direction'] ?? '') === 'avoid'
            ? 'intake: avoid' : 'intake: keep';
        $text = substr($text, 0, 600);
        record_learning($pdo, $wsid, null, $dir, $text);
        $written[] = ['verdict' => $dir, 'reason' => $text];
    }
    json_out([
        'ok' => true,
        'summary' => $summary,
        'learnings' => $written,
        'creatives' => $count,
        'images_read' => $imageCount,
        'model' => $model,
        'cost_usd' => $actual,
    ]);
}

json_out(['error' => 'unknown action'], 400);
