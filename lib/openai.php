<?php
/**
 * OpenAI client for generation (Step 2) — used by api/generate.php only,
 * and only ever after Leon's estimate-then-confirm gate (the run action
 * requires confirm:true; see README).
 *
 * The key is resolved by the caller via vault_key($pdo, 'openai') and
 * passed in. It is sent ONLY in the Authorization header: never logged,
 * never stored in the ledger, never part of any error message this file
 * produces on purpose. Provider errors are surfaced VERBATIM (billing /
 * quota errors included) — nothing is swallowed or faked.
 */

const OPENAI_BASE_URL = 'https://api.openai.com/v1';
const OPENAI_TIMEOUT_S = 60;

/**
 * POST JSON to OpenAI. Returns the decoded response array.
 * Throws RuntimeException with the provider's own error message on any
 * HTTP or transport failure.
 */
function openai_http_post(string $apiKey, string $path, array $payload): array {
    $ch = curl_init(OPENAI_BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => OPENAI_TIMEOUT_S,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('OpenAI transport error: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        // Surface the provider's message verbatim — a billing/quota error
        // must reach the operator exactly as OpenAI phrased it.
        $msg = is_array($data) && isset($data['error']['message'])
            ? (string)$data['error']['message']
            : ('HTTP ' . $status . ': ' . substr((string)$raw, 0, 300));
        throw new RuntimeException('OpenAI error: ' . $msg);
    }
    if (!is_array($data)) {
        throw new RuntimeException('OpenAI returned a non-JSON response.');
    }
    return $data;
}

/**
 * Chat completion that must come back as a JSON object.
 * Returns [decoded_array, usage] where usage is
 * ['input_tokens' => int, 'output_tokens' => int] from the API's own
 * usage object (prompt_tokens / completion_tokens).
 */
function openai_chat_json(string $apiKey, string $model, string $system,
                          string $user): array {
    $data = openai_http_post($apiKey, '/chat/completions', [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'response_format' => ['type' => 'json_object'],
    ]);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException(
            'OpenAI chat response had no message content.');
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            'OpenAI chat content was not valid JSON: '
            . substr($content, 0, 200));
    }
    $usage = [
        'input_tokens' => (int)($data['usage']['prompt_tokens'] ?? 0),
        'output_tokens' => (int)($data['usage']['completion_tokens'] ?? 0),
    ];
    return [$decoded, $usage];
}

/**
 * Generate one image. Returns the raw image bytes (decoded from the
 * API's base64 payload). Throws with the provider's message on failure.
 */
function openai_image(string $apiKey, string $model, string $prompt,
                      string $size = '1024x1024',
                      string $quality = 'medium'): string {
    $data = openai_http_post($apiKey, '/images/generations', [
        'model' => $model,
        'prompt' => $prompt,
        'size' => $size,
        'quality' => $quality,
        'n' => 1,
    ]);
    $b64 = $data['data'][0]['b64_json'] ?? null;
    if (!is_string($b64) || $b64 === '') {
        throw new RuntimeException(
            'OpenAI image response had no b64_json payload.');
    }
    $bytes = base64_decode($b64, true);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException(
            'OpenAI image payload was not valid base64.');
    }
    return $bytes;
}

/**
 * Image generation WITH reference images (Update 10.9): the edits
 * endpoint takes the prompt plus one or more input images. The
 * panel uses it to hand the model the brand's REAL logo files (from
 * the brand's own site) so the header logo is reproduced from the
 * reference instead of invented. Same error surfacing and return
 * contract as openai_image(): decoded PNG bytes or a
 * RuntimeException carrying the provider's message.
 */
function openai_image_edit(string $apiKey, string $model,
                           string $prompt, array $imagePaths,
                           string $size = '1024x1024',
                           string $quality = 'medium'): string {
    $fields = [
        'model' => $model,
        'prompt' => $prompt,
        'size' => $size,
        'quality' => $quality,
        'n' => '1',
    ];
    foreach ($imagePaths as $path) {
        $fields['image[]'] = new CURLFile(
            $path, 'image/png', basename($path));
    }
    $ch = curl_init(OPENAI_BASE_URL . '/images/edits');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => OPENAI_TIMEOUT_S,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('OpenAI transport error: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($data) && isset($data['error']['message'])
            ? (string)$data['error']['message']
            : ('HTTP ' . $status . ': ' . substr((string)$raw, 0, 300));
        throw new RuntimeException('OpenAI error: ' . $msg);
    }
    $b64 = $data['data'][0]['b64_json'] ?? null;
    if (!is_string($b64) || $b64 === '') {
        throw new RuntimeException(
            'OpenAI image response had no b64_json payload.');
    }
    $bytes = base64_decode($b64, true);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException(
            'OpenAI image payload was not valid base64.');
    }
    return $bytes;
}

/**
 * Canvas size for a creative format (Hotfix 4.5). The copy model
 * composes each variant for its format (a 9:16 story, a 4:5 feed
 * post), but the image call used to hardcode a square canvas, so a
 * vertical composition got squeezed into 1024x1024 and its edges —
 * the top line of a stacked headline first — ran off the frame.
 * Portrait formats get the portrait canvas, landscape the landscape
 * canvas, everything else (including an empty or unknown format)
 * stays square. gpt-image-1 offers exactly these three sizes.
 */
function image_size_for_format(?string $format): string {
    $f = str_replace([' ', '_'], '', strtolower(trim((string)$format)));
    $portrait = ['9:16', '4:5', '2:3', '3:4', '9x16', '4x5',
                 'portrait', 'vertical', 'story', 'stories',
                 'reel', 'reels'];
    $landscape = ['16:9', '3:2', '16x9', 'landscape', 'wide', 'banner'];
    if (in_array($f, $portrait, true)) {
        return '1024x1536';
    }
    if (in_array($f, $landscape, true)) {
        return '1536x1024';
    }
    return '1024x1024';
}

/**
 * The prompt actually sent to the image model (Hotfix 4.5, strengthened
 * in 4.6): a quantified composition rule FIRST, then the variant's
 * stored prompt, then a short closing guard. The rule is applied at
 * call time so it covers every stored prompt, including ones written
 * before this fix.
 *
 * Why this shape: batch #7 ran under 4.5 (the portrait canvas was in
 * effect, verified from the screenshot's 2:3 proportions) and the
 * headline STILL lost its first line off the top — a margins sentence
 * appended at the end of a long prompt was too weak, and "generous
 * margins" is unquantified. So the rule now leads the prompt and
 * speaks in numbers: the top 15% of the frame stays text-free scene
 * background, the headline sits in the middle band in at most
 * 3 lines, nothing touches an edge.
 */
function build_final_image_prompt(string $prompt, string $imageText,
                                  string $brandFrame = ''): string {
    // Update 10.6: $imageText is accepted for signature compatibility
    // and IGNORED. The text-free art option was removed entirely
    // (Leon's call, 2026-11-01: "there's no world where we should
    // generate text free art") — every generated image carries its
    // headline, so the headline composition rules always apply.
    $rule = 'Composition rule, highest priority: the finished design '
        . 'fits entirely inside this image. Keep the top 15% of the '
        . 'frame as scene background with no text in it. '
        . 'Set the headline in the middle band of the frame, '
        . 'in at most 3 lines, sized so every word fits with '
        . 'clear space around it. '
        . 'Nothing touches or crosses any edge. ';
    $out = $rule
        . ($brandFrame !== '' ? $brandFrame . ' ' : '')
        . rtrim($prompt)
        . ' Keep the entire composition fully inside the frame with'
        . ' clear margins on all sides; nothing important touches or'
        . ' crosses an edge.'
        . ' All headline text must sit completely inside the'
        . ' image with generous margins, especially at the top;'
        . ' if the headline is long, make the type smaller so'
        . ' every letter fits and nothing is cut off.';
    return $out;
}

/**
 * The mandatory brand frame for image generation (Update 10.7):
 * built from the workspace's token palette and injected into every
 * final image prompt server-side, so the art model never sees an
 * off-palette direction as viable — batch #21's blue tech collage
 * came from the copy model's free-text art direction, and prompt
 * prose alone did not stop it. Empty palette -> empty frame.
 */
function build_brand_frame(array $tokens): string {
    $colors = [];
    foreach ((array)($tokens['colors'] ?? []) as $c) {
        if (is_string($c) && $c !== '') {
            $colors[] = $c;
        } elseif (is_array($c)) {
            $v = (string)($c['hex'] ?? $c['value'] ?? $c['name'] ?? '');
            if ($v !== '') {
                $colors[] = $v;
            }
        }
    }
    if (!$colors) {
        return '';
    }
    return 'Brand frame (mandatory; it overrides any conflicting art '
        . 'direction): use ONLY these brand colors: '
        . implode(', ', $colors) . '. Flat, minimal composition with '
        . 'large negative space. No gradients, no photo collages, no '
        . 'busy multi-panel layouts, and no colors outside the brand '
        . 'palette.';
}

// --- Structured hypothesis (Update 3) ---------------------------------------
//
// experiments.hypothesis (and generation_batches.hypothesis, which
// stores the same string at run time) hold EITHER a legacy plain-text
// hypothesis OR a JSON object serialized by the structured form in the
// Experiments tab:
//   {"angle","hook_type","audience","game","offer","tone"}
// 'offer' stores the workspace offer_table entry id, or the sentinel
// 'none' for an explicit "No offer" brief. hypothesis_parse()
// normalizes both shapes; a non-JSON value is a plain hypothesis whose
// text lands in 'angle' with every other field unset and 'structured'
// false, so the old free-text path keeps working untouched.

const HYPOTHESIS_FIELDS = ['angle', 'hook_type', 'audience', 'game',
                           'offer', 'tone'];
const HYPOTHESIS_NO_OFFER = 'none';

/** Hook type -> the directive line it adds to the generation prompt. */
const HOOK_DIRECTIVES = [
    'Bold claim' =>
        'Hook type: BOLD CLAIM — every variant\'s headline must open '
        . 'with a bold, specific claim about the product or the '
        . 'experience. Stay inside the brand rules: no winnings '
        . 'promises, no invented numbers.',
    'Question' =>
        'Hook type: QUESTION — every variant\'s headline must be '
        . 'phrased as a question to the reader.',
    'Curiosity gap' =>
        'Hook type: CURIOSITY GAP — every variant\'s headline must '
        . 'tease something the reader only gets by tapping through; '
        . 'withhold the payoff detail from the headline itself.',
    'Offer-led' =>
        'Hook type: OFFER-LED — every variant\'s headline must lead '
        . 'with the approved offer in play below; the offer is the '
        . 'hook and everything else supports it.',
    'Gameplay moment' =>
        'Hook type: GAMEPLAY MOMENT — every variant\'s headline must '
        . 'describe one concrete in-game moment (a spin, a board '
        . 'state, a feature triggering), not a general claim.',
    'Social proof' =>
        'Hook type: SOCIAL PROOF — every variant\'s headline must use '
        . 'popularity/belonging framing WITHOUT invented numbers, '
        . 'player counts, or testimonials.',
];

/**
 * Normalize a stored hypothesis string. Returns an array with keys:
 *   structured (bool), angle, hook_type, audience, game, offer, tone,
 *   raw (the original string).
 */
function hypothesis_parse(string $raw): array {
    $out = [
        'structured' => false,
        'angle' => trim($raw),
        'hook_type' => '', 'audience' => '', 'game' => '',
        'offer' => '', 'tone' => '',
        'raw' => $raw,
    ];
    $trimmed = trim($raw);
    if ($trimmed !== '' && $trimmed[0] === '{') {
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $known = false;
            foreach (HYPOTHESIS_FIELDS as $f) {
                if (array_key_exists($f, $decoded)) {
                    $known = true;
                    $out[$f] = is_scalar($decoded[$f])
                        ? trim((string)$decoded[$f]) : '';
                }
            }
            if ($known) {
                $out['structured'] = true;
            }
        }
    }
    return $out;
}

/**
 * Build the generation prompts. Grounding comes ONLY from the workspace
 * tokens (+ the stored guideline doc as background). Callers enrich
 * $tokens with:
 *   brand_name (workspace name), games (list, e.g. from rule R8's
 *   known_entities), formats (allowed formats, e.g. from rule R11).
 *
 * $hypothesis is the stored hypothesis string: a structured JSON brief
 * (hypothesis_parse) narrows the prompt — angle becomes the brief,
 * hook_type adds a headline directive, a chosen game restricts
 * game_name, a chosen offer restricts offer_text (or forbids offers
 * entirely with the 'none' sentinel), audience/tone are woven in.
 * Plain-text hypotheses take the original path unchanged.
 *
 * Returns ['system' => string, 'user' => string]. The model is required
 * to answer with strict JSON:
 *   {"variants":[{"game_name","headline","primary_text","description",
 *                  "cta","offer_text","format","art_direction",
 *                  "image_prompt"}]}
 * with exactly $variants entries.
 */
function build_generation_prompt(array $tokens, ?string $guidelineText,
                                 string $hypothesis, int $variants,
                                 string $imageText = 'headline'): array {
    $brand = (string)($tokens['brand_name'] ?? 'the brand');
    $games = array_values((array)($tokens['games'] ?? []));
    $formats = array_values((array)($tokens['formats'] ?? []))
        ?: ['1:1', '4:5', '9:16'];
    $ctas = array_values((array)($tokens['cta_allowlist'] ?? []));
    $compliance = array_values((array)($tokens['compliance_strings'] ?? []));
    $banned = array_values((array)($tokens['banned_words'] ?? []));
    $offers = [];
    foreach ((array)($tokens['offer_table'] ?? []) as $o) {
        if (isset($o['text']) && $o['text'] !== '') {
            $offers[] = (string)$o['text'];
        }
    }
    $tone = trim((string)($tokens['tone_text'] ?? ''));

    // Structured briefs narrow the run: a chosen game restricts the
    // games list to that one game; a chosen offer restricts the offer
    // list to that one entry; the 'none' sentinel forbids offers even
    // though the brand has approved ones.
    $hyp = hypothesis_parse($hypothesis);
    $forcedNoOffer = false;
    if ($hyp['structured']) {
        if ($hyp['game'] !== '') {
            $games = [$hyp['game']];
        }
        if ($hyp['offer'] === HYPOTHESIS_NO_OFFER) {
            $offers = [];
            $forcedNoOffer = true;
        } elseif ($hyp['offer'] !== '') {
            foreach ((array)($tokens['offer_table'] ?? []) as $o) {
                $oid = (string)($o['id'] ?? '');
                $otext = (string)($o['text'] ?? '');
                if ($otext !== ''
                    && ($oid === $hyp['offer'] || $otext === $hyp['offer'])) {
                    $offers = [$otext];
                    break;
                }
            }
        }
    }

    $system = "You are an ad copywriter for $brand. You write compliant "
        . "social-casino ad variants. You follow the brand rules in the "
        . "user message exactly; they override any habit of yours. You "
        . "answer with a single JSON object and nothing else.";

    $lines = [];
    if ($hyp['structured']) {
        $lines[] = 'CREATIVE BRIEF (structured hypothesis — every '
            . 'variant must execute THIS brief):';
        $lines[] = '- Angle: ' . ($hyp['angle'] !== ''
            ? $hyp['angle']
            : '(no angle text — execute the hook and audience fields '
            . 'below as literally as possible)');
        if ($hyp['hook_type'] !== ''
            && isset(HOOK_DIRECTIVES[$hyp['hook_type']])) {
            $lines[] = '- ' . HOOK_DIRECTIVES[$hyp['hook_type']];
        }
        if ($hyp['audience'] !== '') {
            $lines[] = '- Target audience: ' . $hyp['audience']
                . ' — write to them specifically.';
        }
        if ($hyp['game'] !== '') {
            $lines[] = '- This brief is for ONE product only: '
                . $hyp['game'] . '.';
        }
        if ($hyp['tone'] !== '') {
            $lines[] = '- Tone note for this brief: ' . $hyp['tone'];
        }
    } else {
        $lines[] = "Hypothesis being tested: $hypothesis";
    }
    $lines[] = '';
    $lines[] = 'BRAND RULES (hard constraints, from the workspace tokens):';
    if ($games) {
        $lines[] = '- Games you may name (exactly one per variant, used '
            . 'verbatim): ' . implode(' | ', $games);
    } else {
        $lines[] = '- Name exactly one game per variant; invent no '
            . 'game names beyond what the hypothesis names.';
    }
    if ($offers) {
        $lines[] = '- Approved offers (the ONLY offers allowed; '
            . 'offer_text must be one of these verbatim, or empty):';
        foreach ($offers as $o) {
            $lines[] = '  * ' . $o;
        }
    } elseif ($forcedNoOffer) {
        $lines[] = '- This brief runs WITHOUT an offer (the operator '
            . 'chose "No offer" for it): offer_text must be empty and '
            . 'the copy must make no offer, bonus, percentage, or prize '
            . 'claims of any kind, even though the brand has approved '
            . 'offers for other briefs.';
    } else {
        $lines[] = '- No approved offers exist: offer_text must be '
            . 'empty and the copy must make no offer, bonus, percentage, '
            . 'or prize claims of any kind.';
    }
    if ($ctas) {
        $lines[] = '- CTA must be exactly one of: ' . implode(' | ', $ctas);
    }
    if ($compliance) {
        $lines[] = '- The description of EVERY variant must contain '
            . 'all of these strings (case-insensitive): '
            . implode(' | ', $compliance);
    }
    if ($banned) {
        $lines[] = '- Never use these words/patterns anywhere in the '
            . 'copy: ' . implode(', ', $banned);
    }
    $lines[] = '- Never promise or imply guaranteed winnings. Sweeps '
        . 'Coins are never bought, only given; Gold Coins are never '
        . 'redeemable.';
    if ($tone !== '') {
        $lines[] = "- Tone: $tone";
    }
    $lines[] = '- format must be one of: ' . implode(' | ', $formats);
    if ($guidelineText !== null && trim($guidelineText) !== '') {
        $lines[] = '';
        $lines[] = 'BRAND GUIDELINES (background, from the workspace '
            . 'guideline doc; the hard constraints above win on any '
            . 'conflict):';
        $lines[] = substr(trim($guidelineText), 0, 4000);
    }
    // Learnings loop, prompt-in half (Update 4): the caller passes
    // the workspace's recent learnings (one compressed line each) in
    // $tokens['learnings'] when the workspace toggle is on. Capped
    // at 12 lines here as well as at the fetch. Zero learnings ->
    // the section is omitted entirely, byte for byte.
    $learningLines = array_values(array_filter(
        (array)($tokens['learnings'] ?? []),
        fn($l) => is_string($l) && trim($l) !== ''));
    if ($learningLines) {
        $lines[] = '';
        $lines[] = 'Learnings from previous experiments in this '
            . 'workspace. Do not repeat the failure patterns; reuse '
            . 'what passed:';
        foreach (array_slice($learningLines, 0, 12) as $l) {
            $lines[] = '- ' . $l;
        }
    }
    $lines[] = '';
    if ($hyp['structured']) {
        $lines[] = "Write exactly $variants distinct variants. Vary "
            . 'the execution (phrasing, imagery, emphasis) while '
            . 'staying inside the brief above and obeying every rule.';
    } else {
        $lines[] = "Write exactly $variants distinct variants. Vary the "
            . 'angle (seasonal hook, game feature, offer-led, social proof '
            . 'without invented numbers) while obeying every rule.';
    }
    $lines[] = 'HARD RULE on numbers: never state a number, '
        . 'percentage, price, count or date that does not appear '
        . 'in the brief, the brand tokens, or the guidelines above. '
        . 'If the brief asks for a quantified claim and no figure '
        . 'is supplied, write the claim without a number. The panel '
        . 'flags invented figures automatically.';
    $lines[] = 'WORD LIMITS: headline at most 10 words; primary '
        . 'text at most 35 words; description at most 20 words; '
        . 'offer at most 8 words; CTA at most 3 words. The brand '
        . 'templates fit two-line headlines and one-breath '
        . 'primaries. The panel flags over-length copy '
        . 'automatically.';
    // Template mode (Update 10.8): images render from the
    // workspace's fixed ad template — composition, palette,
    // typography and headline placement all belong to the template,
    // never to this model. The model's only visual job is the
    // one-line product-window visual. (The 10.6 headline-on-image
    // instruction is retired with free-form art; $imageText is
    // ignored.)
    $lines[] = 'Also write, per variant: art_direction (one short '
        . 'line naming the product visual that belongs in the ad '
        . 'template\'s product window) and image_prompt (ONE line '
        . 'describing only that product visual — a '
        . 'developer-console scene, or a realistic product-'
        . 'interface moment such as a review finding or a code '
        . 'diff. No layout, colors, typography, backgrounds, or '
        . 'text of any kind: the workspace ad template supplies '
        . 'the entire composition and renders the headline '
        . 'itself).';
    $lines[] = '';
    $lines[] = 'Answer with strict JSON only, exactly this shape: '
        . '{"variants":[{"game_name":"...","headline":"...",'
        . '"primary_text":"...","description":"...","cta":"...",'
        . '"offer_text":"...","format":"...","art_direction":"...",'
        . '"image_prompt":"..."}]}';

    return ['system' => $system, 'user' => implode("\n", $lines)];
}

/**
 * Select the usable variants from the raw list a generation response
 * returned, given how many were requested.
 *
 * Partial results are ACCEPTED (Update 4.2): the model was already
 * paid for its answer, so 1..$requested valid variants is a successful
 * run with fewer creatives, and more than $requested is trimmed to
 * the first $requested (never create beyond the confirmed estimate).
 * Individual invalid entries (not an object, or neither headline nor
 * primary_text) are dropped and counted, not fatal. The returned
 * 'note' describes any shortfall/trim/drops for the batch notes, and
 * is null when the return matched the request exactly.
 *
 * Throws RuntimeException only when ZERO valid variants remain —
 * that is still a failed generation, exactly as a provider error or
 * an unparseable response is.
 *
 * @return array{use: array, note: ?string, returned: int, invalid: int}
 */
/**
 * Fix-rerun prompt (Update 10.10): rewrite ONE creative so it stops
 * failing the rules it failed. The failures arrive with the detail
 * assembled by attach_verdict_details (rule name, Jev's probability
 * note, the local engine's diagnosis). The constraints restated here
 * are the same hard rules generation runs under — the lint still
 * re-checks the result on read, so a bad fix flags itself.
 */
function build_fix_prompt(array $creative, array $failures,
                          array $tokens,
                          ?string $guidelineText): array {
    $system = 'You are the copy fixer inside a creative testing '
        . 'panel. You rewrite ONE existing ad creative so it '
        . 'passes the brand rules it failed, changing only what '
        . 'the failures require. Keep the product, the angle, and '
        . 'the voice. Never invent numbers, prices, dates, or '
        . 'claims. Obey the word limits and the CTA allowlist.';
    $lines = [];
    if ($guidelineText !== null && trim($guidelineText) !== '') {
        $lines[] = 'BRAND GUIDELINES (these govern):';
        $lines[] = substr($guidelineText, 0, 3500);
        $lines[] = '';
    }
    $lines[] = 'THE CREATIVE AS IT STANDS:';
    foreach (['game_name' => 'Product', 'headline' => 'Headline',
              'primary_text' => 'Primary text',
              'description' => 'Description', 'cta' => 'CTA',
              'offer_text' => 'Offer'] as $key => $label) {
        $lines[] = $label . ': ' . (string)($creative[$key] ?? '');
    }
    $lines[] = '';
    $lines[] = 'WHAT FAILED (fix every one of these):';
    foreach ($failures as $f) {
        $line = '- ' . (string)($f['rule_id'] ?? '')
            . ' ' . (string)($f['rule_name'] ?? '')
            . ' — ' . (string)($f['note'] ?? '');
        if (!empty($f['local_note'])) {
            $line .= ' Panel\'s own check: '
                . (string)($f['local_verdict'] ?? '') . ' — '
                . (string)$f['local_note'];
        }
        $lines[] = $line;
    }
    $lines[] = '';
    $lines[] = 'HARD RULES for the rewrite: never state a number, '
        . 'percentage, price, count or date that does not appear '
        . 'in the creative above, the failures, or the guidelines. '
        . 'Word limits: headline at most 10 words; primary text '
        . 'at most 35 words; description at most 20 words; offer '
        . 'at most 8 words; CTA at most 3 words.';
    $allow = array_values(array_filter(array_map('strval',
        (array)($tokens['cta_allowlist'] ?? []))));
    if ($allow !== []) {
        $lines[] = 'The CTA must be exactly one of: '
            . implode(', ', $allow) . '.';
    }
    $banned = array_values(array_filter(array_map('strval',
        (array)($tokens['banned_words'] ?? []))));
    if ($banned !== []) {
        $lines[] = 'Banned words (never use): '
            . implode(', ', $banned) . '.';
    }
    $lines[] = 'Also rewrite image_prompt as ONE line describing '
        . 'only the product visual for the ad template (a '
        . 'developer-console scene or product-interface moment; no '
        . 'layout, colors, typography, or text).';
    $lines[] = '';
    $lines[] = 'Answer with strict JSON only, exactly this shape: '
        . '{"headline":"...","primary_text":"...",'
        . '"description":"...","cta":"...","offer_text":"...",'
        . '"image_prompt":"..."}';
    return ['system' => $system, 'user' => implode("\n", $lines)];
}

function select_variants(array $returned, int $requested): array {
    $keys = ['game_name', 'headline', 'primary_text', 'description',
             'cta', 'offer_text', 'format', 'art_direction',
             'image_prompt'];
    $valid = [];
    $invalid = 0;
    foreach ($returned as $v) {
        if (!is_array($v)) {
            $invalid++;
            continue;
        }
        $row = [];
        foreach ($keys as $k) {
            $row[$k] = isset($v[$k]) && is_scalar($v[$k])
                ? trim((string)$v[$k]) : '';
        }
        if ($row['headline'] === '' && $row['primary_text'] === '') {
            $invalid++;
            continue;
        }
        $valid[] = $row;
    }
    $returnedCount = count($returned);
    if (!$valid) {
        throw new RuntimeException($returnedCount === 0
            ? 'Generation returned 0 variants; nothing usable to create.'
            : sprintf('Generation returned %d variant(s), but none were '
                . 'usable (%d invalid dropped).',
                $returnedCount, $invalid));
    }
    $use = array_slice($valid, 0, max(1, $requested));
    $parts = [];
    if ($invalid > 0) {
        $parts[] = sprintf('%d invalid dropped', $invalid);
    }
    if (count($valid) > $requested) {
        $parts[] = sprintf('model returned %d variants, more than the '
            . '%d requested; extras dropped', $returnedCount, $requested);
    } elseif (count($valid) < $requested) {
        $parts[] = sprintf('model returned %d of %d requested variants',
            count($valid), $requested);
    }
    $note = $parts
        ? ucfirst(implode('; ', $parts))
            . sprintf('; created %d.', count($use))
        : null;
    return ['use' => $use, 'note' => $note,
            'returned' => $returnedCount, 'invalid' => $invalid];
}

/**
 * Validate + normalize a decoded generation response (the JSON object
 * the chat model returned) into a list of variant arrays with the nine
 * expected keys, all strings. Throws RuntimeException only when the
 * response has no "variants" array or zero usable variants; a short
 * or over-long return is selected per select_variants() instead of
 * failing (Update 4.2).
 */
function extract_variants(array $decoded, int $expected): array {
    $variants = $decoded['variants'] ?? null;
    if (!is_array($variants)) {
        throw new RuntimeException(
            'Generation response had no "variants" array.');
    }
    return select_variants($variants, $expected)['use'];
}

/**
 * Vision chat completion that must come back as a JSON object
 * (Update 8 vision pass). $images is a list of
 * ['label' => string, 'mime' => string, 'base64' => string]; each
 * image rides as its own content part right after a text label, so
 * the model can attribute visuals to the corpus rows they belong
 * to. Images are sent at low detail — enough to read subject,
 * layout, palette, and baked-in text, at a fraction of the tokens.
 * Returns [decoded_array, usage], exactly like openai_chat_json.
 */
function openai_chat_vision_json(string $apiKey, string $model,
                                 string $system, string $userText,
                                 array $images): array {
    $parts = [['type' => 'text', 'text' => $userText]];
    foreach ($images as $img) {
        $parts[] = ['type' => 'text',
                    'text' => (string)($img['label'] ?? 'Image:')];
        $parts[] = ['type' => 'image_url', 'image_url' => [
            'url' => 'data:' . $img['mime'] . ';base64,' . $img['base64'],
            'detail' => 'low',
        ]];
    }
    $data = openai_http_post($apiKey, '/chat/completions', [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $parts],
        ],
        'response_format' => ['type' => 'json_object'],
    ]);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException(
            'OpenAI vision response had no message content.');
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            'OpenAI vision content was not valid JSON: '
            . substr($content, 0, 200));
    }
    $usage = [
        'input_tokens' => (int)($data['usage']['prompt_tokens'] ?? 0),
        'output_tokens' => (int)($data['usage']['completion_tokens'] ?? 0),
    ];
    return [$decoded, $usage];
}
