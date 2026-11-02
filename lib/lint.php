<?php
/**
 * Deterministic lint guardrails (Update 10.7).
 *
 * Why this exists: batch #21 (2026-11-01) ran 13 minutes after the
 * CodeRabbit ad brand rules were saved into the workspace guidelines
 * and still produced "Save 20% of your engineering time." — an
 * invented statistic the brief had implicitly asked for ("lead with
 * the time saved", hook: Bold claim) and the guidelines explicitly
 * forbade. Lesson: rules carried in a prompt are a suggestion; a
 * specific brief beats a general rule, and nothing downstream
 * checked the result. These checks are code, not prose:
 *
 *   - Number lint: any number, percentage, price or count appearing
 *     in a creative's copy that appears NOWHERE in the brief, the
 *     workspace brand tokens, or the workspace guidelines is flagged
 *     as a probable invention. Flags are review markers, not blocks:
 *     the operator decides. (A claim like "24/7" will flag when the
 *     brand facts don't carry it — that is the intended posture:
 *     ungrounded numbers justify themselves or get edited.)
 *   - CTA lint: a generated CTA that is not in the workspace's CTA
 *     allowlist is flagged the same way.
 *
 * Flags attach to every creative on read (creative_with_verdicts),
 * so they also apply retroactively to batches generated before this
 * update, and a summary rides the batch notes at generation time.
 */

/** Extract number tokens from text: [ ['raw' => '20%', 'norm' => '20'], ... ] */
function lint_extract_numbers(string $text): array {
    if (!preg_match_all('/\$?\d[\d,]*(?:\.\d+)?%?/', $text, $m)) {
        return [];
    }
    $out = [];
    $seen = [];
    foreach ($m[0] as $raw) {
        $norm = str_replace(['$', ',', '%', ' '], '', $raw);
        if (strpos($norm, '.') !== false) {
            [$int, $frac] = explode('.', $norm, 2);
            $frac = rtrim($frac, '0');
            $int = ltrim($int, '0');
            $norm = ($int === '' ? '0' : $int)
                . ($frac === '' ? '' : '.' . $frac);
        } else {
            $norm = ltrim($norm, '0');
            if ($norm === '') {
                $norm = '0';
            }
        }
        if (isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $out[] = ['raw' => $raw, 'norm' => $norm];
    }
    return $out;
}

/**
 * The set of grounded numbers for a workspace run: everything numeric
 * in the brand tokens (offer table percentages and texts, compliance
 * strings, tone), the guidelines document, and the run's brief.
 * Returned as a norm => true set.
 */
function lint_number_corpus(array $tokens, ?string $guidelineText,
                              ?string $hypothesis): array {
    $set = [];
    $sources = [
        (string)json_encode($tokens),
        (string)($guidelineText ?? ''),
        (string)($hypothesis ?? ''),
    ];
    foreach ($sources as $src) {
        foreach (lint_extract_numbers($src) as $n) {
            $set[$n['norm']] = true;
        }
    }
    return $set;
}

/**
 * Flags for one creative against a grounded-number corpus and the
 * workspace CTA allowlist. Each flag: kind, field, short (chip text),
 * message (full explanation).
 */
function lint_creative_flags(array $creative, array $corpus,
                             array $ctaAllowlist): array {
    $flags = [];
    $fields = ['headline', 'primary_text', 'description', 'offer_text'];
    foreach ($fields as $field) {
        $text = (string)($creative[$field] ?? '');
        if ($text === '') {
            continue;
        }
        foreach (lint_extract_numbers($text) as $n) {
            if (!isset($corpus[$n['norm']])) {
                $flags[] = [
                    'kind' => 'number',
                    'field' => $field,
                    'short' => "number '" . $n['raw'] . "' ungrounded",
                    'message' => "Number '" . $n['raw'] . "' in the "
                        . $field . ' appears nowhere in the brief, '
                        . 'the brand tokens, or the guidelines. '
                        . 'Ungrounded figures are treated as invented '
                        . 'until the operator grounds or removes them.',
                ];
            }
        }
    }
    $cta = trim((string)($creative['cta'] ?? ''));
    if ($cta !== '' && $ctaAllowlist) {
        $ok = false;
        foreach ($ctaAllowlist as $allowed) {
            if (strcasecmp($cta, trim((string)$allowed)) === 0) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            $flags[] = [
                'kind' => 'cta',
                'field' => 'cta',
                'short' => "CTA '" . $cta . "' not in allowlist",
                'message' => "CTA '" . $cta . "' is not in the "
                    . 'workspace CTA allowlist ('
                    . implode(', ', array_map('strval', $ctaAllowlist))
                    . ').',
            ];
        }
    }
    // Word-count discipline (Update 10.9): the brand systems this
    // panel serves are built on short copy — two-line headlines,
    // one-breath primaries, three-word CTAs. Caps are panel
    // defaults; an over-length field flags as a review marker,
    // exactly like the number and CTA flags above.
    $wordCaps = [
        'headline'     => ['Headline', 10],
        'primary_text' => ['Primary text', 35],
        'description'  => ['Description', 20],
        'offer_text'   => ['Offer', 8],
        'cta'          => ['CTA', 3],
    ];
    foreach ($wordCaps as $field => $cap) {
        $text = trim((string)($creative[$field] ?? ''));
        if ($text === '') {
            continue;
        }
        $n = word_count_text($text);
        if ($n > $cap[1]) {
            $flags[] = [
                'kind' => 'words',
                'field' => $field,
                'short' => $field . ' ' . $n . ' words (max '
                    . $cap[1] . ')',
                'message' => $cap[0] . ' runs ' . $n . ' words; the '
                    . 'cap is ' . $cap[1] . '. The brand templates '
                    . 'are built for short copy — shorten it before '
                    . 'this runs.',
            ];
        }
    }
    return $flags;
}

/** Whitespace word count for lint's word caps. */
function word_count_text(string $text): int {
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    $parts = preg_split('/\s+/u', $text);
    return $parts === false ? 0 : count($parts);
}

/**
 * Flags for a creative row, building (and statically caching) the
 * grounded corpus for its workspace + batch. Used by
 * creative_with_verdicts so every surface that returns a creative
 * carries its flags.
 */
function lint_flags_for(PDO $pdo, array $creative): array {
    static $cache = [];
    $wsid = (int)($creative['workspace_id'] ?? 0);
    $batchId = isset($creative['batch_id']) && $creative['batch_id'] !== null
        ? (int)$creative['batch_id'] : 0;
    $key = $wsid . '|' . $batchId;
    if (!isset($cache[$key])) {
        $tokens = get_tokens($pdo, $wsid);
        $gs = $pdo->prepare(
            'SELECT raw_markdown FROM guidelines WHERE workspace_id = ?');
        $gs->execute([$wsid]);
        $gRow = $gs->fetch(PDO::FETCH_ASSOC);
        $guidelineText = $gRow !== false
            ? (string)$gRow['raw_markdown'] : null;
        $hypothesis = null;
        if ($batchId > 0) {
            $bs = $pdo->prepare(
                'SELECT hypothesis FROM generation_batches WHERE id = ?');
            $bs->execute([$batchId]);
            $bRow = $bs->fetch(PDO::FETCH_ASSOC);
            if ($bRow !== false) {
                $hypothesis = (string)($bRow['hypothesis'] ?? '');
            }
        }
        $cache[$key] = [
            'corpus' => lint_number_corpus(
                $tokens, $guidelineText, $hypothesis),
            'cta_allowlist' => $tokens['cta_allowlist'] ?? [],
        ];
    }
    return lint_creative_flags(
        $creative, $cache[$key]['corpus'], $cache[$key]['cta_allowlist']);
}
