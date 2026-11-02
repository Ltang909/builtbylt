<?php
/**
 * Token-driven guardrail engine (Step 3a) — PHP port of guardrails.py.
 *
 * Rules R1-R12 are parameterized checks. EVERY list, string, offer and
 * format comes from the workspace's brand tokens (brand_tokens table) plus
 * the rule's own per-workspace config (rules table). Nothing brand-specific
 * lives in this file — the sweeps-casino vocabulary exists only as template
 * seed data in templates.php, applied when a workspace is created from
 * that template.
 *
 * Only ENABLED rules produce verdicts. A workspace with no enabled rules
 * verifies nothing (by design — see the "Blank Slate" seed workspace).
 *
 * Source/confidence policy (labeled on every verdict, never hidden):
 *   - Pure string/metadata checks -> source "code", confidence 0.99
 *   - R9 / R10 are vision checks. The local engine has no vision model, so
 *     they return source "mock", confidence 0.80, with a note saying
 *     exactly that. (When Jev is configured, jev.php replaces the whole
 *     verdict set.)
 *   - R11 checks the declared format ratio against the rule's configured
 *     format list (source "code"); a pixel minimum cannot be verified from
 *     metadata and this engine does not pretend otherwise.
 */

const CODE_CONF = 0.99;
const MOCK_CONF = 0.80;

function rule_order(): array {
    $out = [];
    for ($i = 1; $i <= 12; $i++) {
        $out[] = 'R' . $i;
    }
    return $out;
}

// --- helpers ---------------------------------------------------------------

function combined_copy(array $creative): string {
    $parts = [
        $creative['headline'] ?? '',
        $creative['primary_text'] ?? '',
        $creative['description'] ?? '',
        $creative['offer_text'] ?? '',
    ];
    return implode("\n", array_map(fn($p) => (string)$p, $parts));
}

function make_verdict(string $ruleId, bool $ok, string $source,
                      float $confidence, string $note): array {
    return [
        'rule_id' => $ruleId,
        'verdict' => $ok ? 'pass' : 'fail',
        'confidence' => $confidence,
        'source' => $source,
        'note' => $note,
    ];
}

/**
 * Try a token/rule regex (Python re syntax, case-insensitive). If the
 * pattern does not compile under PCRE, fall back to a literal substring
 * search — the same fallback the Python engine uses (re.escape).
 * Returns the matched text, or null.
 */
function regex_search(string $pattern, string $text): ?string {
    $delim = '~';
    $re = $delim . str_replace($delim, '\\' . $delim, $pattern) . $delim . 'i';
    $m = [];
    $r = @preg_match($re, $text, $m);
    if ($r === false) {
        return stripos($text, $pattern) !== false ? $pattern : null;
    }
    return $r === 1 ? $m[0] : null;
}

function regex_find_all(string $pattern, string $text): array {
    $delim = '~';
    $re = $delim . str_replace($delim, '\\' . $delim, $pattern) . $delim . 'i';
    $m = [];
    $r = @preg_match_all($re, $text, $m);
    if ($r === false || $r === 0) {
        return [];
    }
    // With capture groups, return rows of groups (like Python findall).
    if (count($m) > 1) {
        $rows = [];
        $count = count($m[0]);
        for ($i = 0; $i < $count; $i++) {
            $row = [];
            for ($g = 1; $g < count($m); $g++) {
                $row[] = $m[$g][$i];
            }
            $rows[] = $row;
        }
        return $rows;
    }
    return $m[0];
}

function first_match($patterns, string $text): ?string {
    foreach ((array)($patterns ?? []) as $p) {
        $hit = regex_search((string)$p, $text);
        if ($hit !== null) {
            return $hit;
        }
    }
    return null;
}

function offer_present(array $config, string $text): bool {
    foreach ((array)($config['offer_patterns'] ?? []) as $p) {
        if (regex_search((string)$p, $text) !== null) {
            return true;
        }
    }
    return false;
}

// --- the 12 checks -----------------------------------------------------------
// Each takes ($creative, $tokens, $config, $ctx) and returns one verdict.
// $tokens: parsed brand_tokens for the workspace.
// $config: the rule's per-workspace config array.
// $ctx:    ['text' => combined copy, 'lower' => combined copy lowercased]

function check_r1(array $creative, array $tokens, array $config, array $ctx): array {
    $hit = first_match($tokens['banned_words'] ?? [], $ctx['text']);
    return make_verdict('R1', $hit === null, 'code', CODE_CONF,
        $hit !== null ? "Banned term found: '$hit'" : 'No banned terms in copy.');
}

function check_r2(array $creative, array $tokens, array $config, array $ctx): array {
    $hit = first_match($config['patterns'] ?? [], $ctx['text']);
    return make_verdict('R2', $hit === null, 'code', CODE_CONF,
        $hit !== null ? "Winnings promise found: '$hit'" : 'No winnings promise in copy.');
}

function check_r3(array $creative, array $tokens, array $config, array $ctx): array {
    $hit = first_match($config['buy_patterns'] ?? [], $ctx['text'])
        ?? first_match($config['redeem_patterns'] ?? [], $ctx['text']);
    return make_verdict('R3', $hit === null, 'code', CODE_CONF,
        $hit !== null ? "Integrity violation: '$hit'" : 'No coin-model violation detected.');
}

function check_r4(array $creative, array $tokens, array $config, array $ctx): array {
    $field = $config['field'] ?? 'description';
    $value = strtolower((string)($creative[$field] ?? ''));
    $strings = (array)($tokens['compliance_strings'] ?? []);
    $missing = [];
    foreach ($strings as $s) {
        if (strpos($value, strtolower((string)$s)) === false) {
            $missing[] = $s;
        }
    }
    if (!$strings) {
        $note = 'No compliance strings configured for this workspace.';
    } elseif ($missing) {
        $note = ucfirst($field) . ' missing: ' . implode(', ', $missing);
    } else {
        $note = "All compliance strings present in $field.";
    }
    return make_verdict('R4', !$missing, 'code', CODE_CONF, $note);
}

function check_r5(array $creative, array $tokens, array $config, array $ctx): array {
    if (!offer_present($config, $ctx['text'])) {
        return make_verdict('R5', true, 'code', CODE_CONF,
            'No offer language detected; rule not triggered.');
    }
    $field = $config['field'] ?? 'primary_text';
    $value = strtolower((string)($creative[$field] ?? ''));
    $required = (array)($config['required_phrases'] ?? []);
    $missing = [];
    foreach ($required as $p) {
        if (strpos($value, strtolower((string)$p)) === false) {
            $missing[] = $p;
        }
    }
    return make_verdict('R5', !$missing, 'code', CODE_CONF,
        !$missing
            ? "Offer present with all required phrases in $field."
            : "Offer present but phrase(s) missing from $field: " . implode(', ', $missing));
}

function check_r6(array $creative, array $tokens, array $config, array $ctx): array {
    $text = $ctx['text'];
    $lower = $ctx['lower'];
    $offers = (array)($tokens['offer_table'] ?? []);
    $problems = [];
    $approvedPcts = [];
    foreach ($offers as $o) {
        if (isset($o['percent']) && $o['percent'] !== null) {
            $approvedPcts[] = (int)$o['percent'];
        }
    }
    foreach (regex_find_all('(\d+)\s*%', $text) as $row) {
        $pct = is_array($row) ? $row[0] : $row;
        if (!in_array((int)$pct, $approvedPcts, true)) {
            $problems[] = "unapproved percentage: {$pct}%";
        }
    }
    $anyDollar = false;
    foreach ($offers as $o) {
        if (!empty($o['allow_dollar_amounts'])) { $anyDollar = true; break; }
    }
    if (!$anyDollar) {
        foreach (regex_find_all('\$\s?(\d[\d,]*)', $text) as $row) {
            $amt = is_array($row) ? $row[0] : $row;
            $problems[] = "unapproved dollar amount: \$$amt";
        }
    }
    $coinTerms = (array)($config['coin_terms'] ?? []);
    $anyCoin = false;
    foreach ($offers as $o) {
        if (!empty($o['allow_coin_amounts'])) { $anyCoin = true; break; }
    }
    if ($coinTerms && !$anyCoin) {
        $alts = implode('|', array_map(
            fn($t) => preg_quote((string)$t, '~'), $coinTerms));
        foreach (regex_find_all('(\d[\d,]*)\s*(' . $alts . ')\b', $text) as $row) {
            $problems[] = "unapproved coin amount: {$row[0]} {$row[1]}";
        }
    }
    if (offer_present($config, $text)) {
        $sigOk = false;
        foreach ($offers as $o) {
            $all = true;
            foreach ((array)($o['signature'] ?? []) as $s) {
                if (strpos($lower, strtolower((string)$s)) === false) {
                    $all = false;
                    break;
                }
            }
            if ($all) { $sigOk = true; break; }
        }
        if (!$sigOk) {
            $problems[] = 'offer claimed does not match any approved '
                . 'offer signature in the token offer table';
        }
    }
    return make_verdict('R6', !$problems, 'code', CODE_CONF,
        $problems ? implode('; ', $problems)
                  : 'All numbers match the approved offer table.');
}

function check_r7(array $creative, array $tokens, array $config, array $ctx): array {
    $allowlist = (array)($tokens['cta_allowlist'] ?? []);
    $cta = trim((string)($creative['cta'] ?? ''));
    if (!$allowlist) {
        return make_verdict('R7', false, 'code', CODE_CONF,
            'CTA allowlist is empty (not configured for this workspace).');
    }
    $lowerAllow = array_map(fn($c) => strtolower((string)$c), $allowlist);
    $ok = in_array(strtolower($cta), $lowerAllow, true);
    return make_verdict('R7', $ok, 'code', CODE_CONF,
        $ok ? "CTA '$cta' is approved."
            : "CTA '$cta' not in allowlist " . json_encode($allowlist) . '.');
}

function check_r8(array $creative, array $tokens, array $config, array $ctx): array {
    $game = trim((string)($creative['game_name'] ?? ''));
    $known = (array)($config['known_entities'] ?? []);
    $others = [];
    foreach ($known as $g) {
        $gl = strtolower((string)$g);
        if (strpos($ctx['lower'], $gl) !== false && $gl !== strtolower($game)) {
            $others[] = $g;
        }
    }
    if ($game === '') {
        $ok = false;
        $note = 'No product declared for this creative.';
    } elseif ($others) {
        $ok = false;
        $note = 'Additional entity named in copy: ' . implode(', ', $others);
    } else {
        $ok = true;
        $note = "Single declared entity: $game; no other known entity named.";
    }
    return make_verdict('R8', $ok, 'code', CODE_CONF, $note);
}

function check_r9(array $creative, array $tokens, array $config, array $ctx): array {
    if (!empty($creative['image_path'])) {
        $note = 'MOCK vision check: image present (' . $creative['image_path']
            . ') but logo not actually inspected locally. Placeholder until '
            . 'a vision verifier (Jev Box + Noul) is configured.';
    } else {
        $note = 'MOCK vision check: no image supplied; logo not inspected. '
            . 'Placeholder until a vision verifier (Jev Box + Noul) is '
            . 'configured.';
    }
    return make_verdict('R9', true, 'mock', MOCK_CONF, $note);
}

function check_r10(array $creative, array $tokens, array $config, array $ctx): array {
    $colors = (array)($tokens['colors'] ?? []);
    $note = 'MOCK vision check: palette not actually measured locally '
        . '(workspace defines ' . count($colors) . ' brand color(s)). '
        . 'Placeholder until Jev image input or a dominant-hex extractor '
        . 'is wired.';
    return make_verdict('R10', true, 'mock', MOCK_CONF, $note);
}

function check_r11(array $creative, array $tokens, array $config, array $ctx): array {
    $formats = (array)($config['formats'] ?? []);
    $fmt = trim((string)($creative['format'] ?? ''));
    if (!$formats) {
        return make_verdict('R11', false, 'code', CODE_CONF,
            'No approved formats configured for this rule.');
    }
    $ok = in_array($fmt, $formats, true);
    return make_verdict('R11', $ok, 'code', CODE_CONF,
        $ok ? "Format $fmt approved (ratio only; pixel minimum not "
              . 'verifiable from metadata).'
            : "Format '$fmt' not in approved set " . json_encode($formats) . '.');
}

function check_r12(array $creative, array $tokens, array $config, array $ctx): array {
    if (empty($config['require_template_id']) && isset($config['require_template_id'])
        && $config['require_template_id'] !== true) {
        return make_verdict('R12', true, 'code', CODE_CONF,
            "Template not required by this rule's config.");
    }
    $tid = trim((string)($creative['template_id'] ?? ''));
    return make_verdict('R12', $tid !== '', 'code', CODE_CONF,
        $tid !== '' ? "template_id '$tid' present; text composited."
                    : 'No template_id: text may be baked into generated art.');
}

/**
 * Return verdict dicts for one creative, for ENABLED rules only.
 *
 * $creative: assoc array of creative fields.
 * $tokens:   parsed brand_tokens for the creative's workspace.
 * $rules:    list of rule arrays {rule_id, enabled, config} for the workspace.
 */
function run_checks(array $creative, array $tokens, array $rules): array {
    $checks = [
        'R1' => 'check_r1', 'R2' => 'check_r2', 'R3' => 'check_r3',
        'R4' => 'check_r4', 'R5' => 'check_r5', 'R6' => 'check_r6',
        'R7' => 'check_r7', 'R8' => 'check_r8', 'R9' => 'check_r9',
        'R10' => 'check_r10', 'R11' => 'check_r11', 'R12' => 'check_r12',
    ];
    $text = combined_copy($creative);
    $ctx = ['text' => $text, 'lower' => strtolower($text)];
    $byId = [];
    foreach ($rules as $r) {
        $byId[$r['rule_id']] = $r;
    }
    $verdicts = [];
    foreach (rule_order() as $ruleId) {
        $rule = $byId[$ruleId] ?? null;
        if ($rule === null || empty($rule['enabled'])) {
            continue;
        }
        $fn = $checks[$ruleId] ?? null;
        if ($fn === null) {
            continue;
        }
        $verdicts[] = $fn($creative, $tokens, (array)($rule['config'] ?? []), $ctx);
    }
    return $verdicts;
}
