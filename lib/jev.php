<?php
/**
 * Jev (TypeSafe System One) client — Step 3a verifier. PHP port of jev.py.
 *
 * Key resolution (done by the caller, passed in): panel key vault first,
 * then the TYPESAFE_API_KEY environment variable.
 *
 * If a key is available, jev_verify_creative() attempts the REAL Jev call:
 *
 *     POST {JEV_BASE_URL}{JEV_EVAL_PATH}
 *     Authorization: Bearer <key>
 *     TypeSafe-Danger-Zone-Images: true        (only when an image is attached)
 *     body: {"state": {...}, "model": "jev-latest",
 *            "questions": {"R1": {"type": "noul", "instructions": ...}, ...},
 *            "assets": {"creative_image": <image payload>}}   # Jev 1.14 pattern
 *
 *   *** VERIFY endpoint path on first live use ***
 *   JEV_EVAL_PATH and the exact assets encoding are the two details to
 *   confirm against the live TypeSafe docs/console on the first real call.
 *   ANY failure (network, HTTP status, malformed response) falls back to
 *   the local engine in guardrails.php, and the returned run_info says so
 *   plainly. Verdicts are labeled source "jev" ONLY when Jev actually
 *   produced them.
 *
 * Noul answers are read as the probability that the creative COMPLIES with
 * the rule. Mapping:
 *   p >= 0.9  -> pass, confidence p
 *   p <= 0.1  -> fail, confidence 1-p          (hard fail)
 *   between   -> verdict by majority side, flagged "uncertain"; the panel
 *                routes the creative to judgment review instead of auto-fail.
 *
 * jev_verify_creative() returns [verdicts, run_info] where run_info
 * describes what actually ran: {ran, provider, model, status, note,
 * hard_fail, uncertain} — the caller writes it to the call ledger.
 */

require_once __DIR__ . '/guardrails.php';

// VERIFY endpoint path on first live use (see header comment).
const JEV_BASE_URL = 'https://api.typesafe.ai';
const JEV_EVAL_PATH = '/v1/systemone'; // verified 2026-10-17 against api.typesafe.ai/openapi.json
const JEV_MODEL = 'jev-latest';
const JEV_TIMEOUT_S = 10;

const JEV_PASS_P = 0.9;
const JEV_FAIL_P = 0.1;

/** Short criteria string so Jev judges against the workspace's tokens. */
function jev_criteria(array $rule, array $tokens): string {
    $rid = $rule['rule_id'];
    $bits = [];
    if ($rid === 'R1') {
        $bits[] = 'banned words/patterns: '
            . json_encode($tokens['banned_words'] ?? []);
    } elseif ($rid === 'R4') {
        $bits[] = 'all of these strings must appear in the description: '
            . json_encode($tokens['compliance_strings'] ?? []);
    } elseif ($rid === 'R6') {
        $sigs = [];
        foreach ((array)($tokens['offer_table'] ?? []) as $o) {
            $sigs[] = [$o['text'] ?? null, $o['signature'] ?? null];
        }
        $bits[] = 'approved offers (text + signature): ' . json_encode($sigs);
    } elseif ($rid === 'R7') {
        $bits[] = 'allowed CTAs: ' . json_encode($tokens['cta_allowlist'] ?? []);
    } elseif ($rid === 'R10') {
        $bits[] = 'approved brand colors: ' . json_encode($tokens['colors'] ?? []);
    }
    $cfg = (array)($rule['config'] ?? []);
    if ($cfg) {
        $bits[] = 'rule config: ' . substr((string)json_encode($cfg), 0, 400);
    }
    return $bits ? implode('; ', $bits)
                 : 'judge from the rule name and the copy.';
}

function jev_build_request(array $creative, array $tokens, array $rules,
                           string $baseDir): array {
    $state = [
        'headline' => $creative['headline'] ?? '',
        'primary_text' => $creative['primary_text'] ?? '',
        'description' => $creative['description'] ?? '',
        'cta' => $creative['cta'] ?? '',
        'offer_text' => $creative['offer_text'] ?? '',
        'game_name' => $creative['game_name'] ?? '',
        'format' => $creative['format'] ?? '',
        'template_id' => $creative['template_id'] ?? '',
        'kind' => $creative['kind'] ?? 'static',
        'brand_tokens' => [
            'colors' => $tokens['colors'] ?? [],
            'cta_allowlist' => $tokens['cta_allowlist'] ?? [],
            'compliance_strings' => $tokens['compliance_strings'] ?? [],
            'tone' => $tokens['tone_text'] ?? '',
        ],
    ];
    $questions = [];
    foreach ($rules as $rule) {
        if (empty($rule['enabled'])) {
            continue;
        }
        $rid = $rule['rule_id'];
        $questions[$rid] = [
            'type' => 'noul',
            'instructions' => sprintf(
                'Does this creative COMPLY with rule %s (%s)? '
                . 'Answer yes only if the creative fully satisfies the rule. '
                . 'Criteria: %s',
                $rid, $rule['name'] ?? $rid, jev_criteria($rule, $tokens)),
        ];
    }
    $body = ['state' => $state, 'model' => JEV_MODEL, 'questions' => $questions];

    $imageNote = null;
    $imagePath = $creative['image_path'] ?? null;
    if ($imagePath) {
        // Jev 1.14 pattern: image listed once in a top-level assets map and
        // referenced from state by its key. Endpoint path and asset shape
        // verified 2026-10-17 against api.typesafe.ai/openapi.json.
        $state['image'] = 'creative_image';
        $body['state'] = $state;
        $payload = null;
        $full = rtrim($baseDir, '/') . '/' . $imagePath;
        if (is_file($full)) {
            $raw = file_get_contents($full);
            if ($raw !== false) {
                $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION)) ?: 'png';
                // Verified 2026-10-17 against the TypeSafe OpenAPI schema:
                // assets are objects {type: "image", format, data_base64}.
                $payload = ['type' => 'image', 'format' => $ext,
                            'data_base64' => base64_encode($raw)];
            }
        }
        if ($payload !== null) {
            $body['assets'] = ['creative_image' => $payload];
            $imageNote = 'image attached via assets map';
        } else {
            $imageNote = 'image_path set but file unreadable; sent without image';
        }
    }
    return [$body, $imageNote];
}

/** Pull a 0-1 probability out of a Noul answer, tolerating shape drift. */
function jev_extract_probability($answer): ?float {
    if (is_int($answer) || is_float($answer)) {
        return (float)$answer;
    }
    if (is_array($answer)) {
        foreach (['noul', 'probability', 'value', 'score'] as $key) {
            $v = $answer[$key] ?? null;
            if (is_int($v) || is_float($v)) {
                return (float)$v;
            }
        }
    }
    return null;
}

/** @return array [verdicts, hard_fail, uncertain] — throws on any failure. */
function jev_call(array $creative, array $tokens, array $rules,
                  string $apiKey, string $baseDir): array {
    [$body, $imageNote] = jev_build_request($creative, $tokens, $rules, $baseDir);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    if (isset($body['assets'])) {
        $headers[] = 'TypeSafe-Danger-Zone-Images: true';
    }
    $ch = curl_init(JEV_BASE_URL . JEV_EVAL_PATH);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => JEV_TIMEOUT_S,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP ' . $status);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data) || !isset($data['answers']) || !is_array($data['answers'])) {
        throw new RuntimeException("response has no 'answers' object");
    }
    $answers = $data['answers'];

    $verdicts = [];
    $uncertain = [];
    $hardFail = false;
    foreach ($rules as $rule) {
        if (empty($rule['enabled'])) {
            continue;
        }
        $rid = $rule['rule_id'];
        $p = jev_extract_probability($answers[$rid] ?? null);
        if ($p === null) {
            throw new RuntimeException("no usable answer for $rid");
        }
        if ($p >= JEV_PASS_P) {
            $verdict = 'pass';
            $conf = $p;
        } elseif ($p <= JEV_FAIL_P) {
            $verdict = 'fail';
            $conf = 1.0 - $p;
            $hardFail = true;
        } else {
            $verdict = $p >= 0.5 ? 'pass' : 'fail';
            $conf = max($p, 1.0 - $p);
            $uncertain[] = $rid;
        }
        $note = sprintf('Jev p(compliant)=%.2f.', $p);
        if (in_array($rid, $uncertain, true)) {
            $note .= ' Uncertain band — routed to judgment review.';
        }
        if ($imageNote !== null && ($rid === 'R9' || $rid === 'R10')) {
            $note .= " ($imageNote)";
        }
        $verdicts[] = [
            'rule_id' => $rid,
            'verdict' => $verdict,
            'confidence' => round($conf, 4),
            'source' => 'jev',
            'note' => $note,
        ];
    }
    return [$verdicts, $hardFail, $uncertain];
}

/**
 * Return [verdicts, run_info]. Falls back to the local engine on any Jev
 * failure and says so in run_info — never mislabels the source.
 */
function jev_verify_creative(array $creative, array $tokens, array $rules,
                             ?string $apiKey = null, string $baseDir = '.'): array {
    if ($apiKey) {
        try {
            [$verdicts, $hardFail, $uncertain] = jev_call(
                $creative, $tokens, $rules, $apiKey, $baseDir);
            return [$verdicts, [
                'ran' => 'jev',
                'provider' => 'typesafe',
                'model' => JEV_MODEL,
                'status' => 'ok',
                'note' => sprintf('Jev verified %d rule(s)%s.',
                    count($verdicts),
                    $uncertain ? '; uncertain: ' . implode(', ', $uncertain) : ''),
                'hard_fail' => $hardFail,
                'uncertain' => $uncertain,
            ]];
        } catch (Throwable $exc) {
            $verdicts = run_checks($creative, $tokens, $rules);
            return [$verdicts, [
                'ran' => 'local',
                'provider' => 'local',
                'model' => 'local-engine',
                'status' => 'fallback',
                'note' => sprintf('Jev call failed (%s: %s); local engine used.',
                    get_class($exc),
                    substr($exc->getMessage(), 0, 160)),
                'hard_fail' => (bool)array_filter(
                    $verdicts, fn($v) => $v['verdict'] === 'fail'),
                'uncertain' => [],
            ]];
        }
    }
    $verdicts = run_checks($creative, $tokens, $rules);
    return [$verdicts, [
        'ran' => 'local',
        'provider' => 'local',
        'model' => 'local-engine',
        'status' => 'ok',
        'note' => 'No TypeSafe key configured; local engine used.',
        'hard_fail' => (bool)array_filter(
            $verdicts, fn($v) => $v['verdict'] === 'fail'),
        'uncertain' => [],
    ]];
}
