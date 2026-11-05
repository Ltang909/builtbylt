<?php
/**
 * Workspace templates.
 *
 * A template is ONLY seed data applied at workspace-creation time. Nothing
 * in the guardrail engine includes these constants: once a workspace
 * exists, its tokens and rule configs live in the database and are fully
 * editable.
 *
 * Templates:
 *   - "sweeps_casino": the QuickMilly-derived guardrail set from the Python
 *     build (tokens + the 12 parameterized rules, all enabled).
 *   - "blank": empty tokens, the 12 rule rows present but ALL DISABLED with
 *     empty configs. Proves nothing is hard-coded: a blank workspace
 *     verifies nothing until the owner configures and enables rules.
 */

// --- Sweeps-casino template tokens (from the QuickMilly brand sheet v1.0) ---
function sweeps_tokens(): array {
    return [
        'colors' => ['#5B62F9', '#121731', '#E8B84B'],
        'fonts' => ['Poppins', 'Inter'],
        'cta_allowlist' => ['Play Game', 'Play Now', 'Sign Up', 'Learn More'],
        'banned_words' => [
            '\bbet\b', '\bbetting\b', '\bgamble\b', '\bgambling\b',
            '\bguaranteed\b', '\bguarantee\b', 'cash prizes',
            'risk[-\s]?free', '\bjackpot\b',
        ],
        'compliance_strings' => [
            'no purchase necessary', 'void where prohibited', '18+',
        ],
        'offer_table' => [
            [
                'id' => 'first-package-125',
                'text' => '125% extra Gold Coins on your first package',
                'percent' => 125,
                'signature' => ['125%', 'gold coins', 'first package'],
                'allow_dollar_amounts' => false,
                'allow_coin_amounts' => false,
            ],
        ],
        'tone_text' => 'Player-first, direct, benefit-led, lightly playful. '
            . 'One wordplay line max, then plain facts with conditions '
            . 'inline. Never promise or imply guaranteed winnings.',
    ];
}

function blank_tokens(): array {
    return [
        'colors' => [],
        'fonts' => [],
        'cta_allowlist' => [],
        'banned_words' => [],
        'compliance_strings' => [],
        'offer_table' => [],
        'tone_text' => '',
    ];
}

function sweeps_rule_defs(): array {
    $offerPatterns = [
        '\d+\s*%', '\bbonus\b', '\bextra\b', '\bprizes?\b',
        'free coins', '\$\s?\d',
    ];
    // Each rule: [rule_id, name, type, config]. Config values are the
    // rule's own parameters; token-driven rules (R1, R4, R6, R7, R10) read
    // from brand_tokens.
    return [
        ['R1', 'Banned words', 'text', []],
        ['R2', 'No winnings promise', 'text', [
            'patterns' => [
                'guaranteed wins?\b',
                'every\s+(player|one|body)\s+wins',
                '\bwin\b[^.]{0,40}\b(cash|money|prizes?)\b',
                '\byou\s+(will|can)\s+win\b',
                '\balways\s+wins?\b',
            ],
        ]],
        ['R3', 'Coin model integrity', 'text', [
            'buy_patterns' => [
                '\b(buy|purchase|order|shop)\b[^.]{0,50}\b(sweeps coins|sc)\b',
                '\b(sweeps coins|sc)\b[^.]{0,50}\b(for sale|to buy|purchase)\b',
            ],
            'redeem_patterns' => [
                '\b(gold coins|gc)\b[^.]{0,60}\b(redeem|redeemable|cash|exchange|convert|withdraw)\b',
                '\b(redeem|cash out|exchange|convert)\b[^.]{0,60}\b(gold coins|gc)\b',
            ],
        ]],
        ['R4', 'Compliance strings in description', 'text', [
            'field' => 'description',
        ]],
        ['R5', 'Offer honesty attachments', 'text', [
            'offer_patterns' => $offerPatterns,
            'required_phrases' => ['free to play', 'terms apply'],
            'field' => 'primary_text',
        ]],
        ['R6', 'Offer numbers match approved table', 'text', [
            'offer_patterns' => $offerPatterns,
            'coin_terms' => ['gold coins', 'sweeps coins'],
        ]],
        ['R7', 'CTA allowlist', 'text', []],
        ['R8', 'Single named entity (game)', 'text', [
            'known_entities' => [
                'Sugar Merge Up', 'Buffalo Stampede', 'Zeus Thunder',
                'Lucky Sevens', "Dragon's Hoard",
            ],
        ]],
        ['R9', 'Logo present, legible, unobscured', 'image', []],
        ['R10', 'Palette within brand colors', 'image', []],
        ['R11', 'Approved formats', 'meta', [
            'formats' => ['1:1', '4:5', '9:16'],
        ]],
        ['R12', 'Template integrity', 'meta', [
            'require_template_id' => true,
        ]],
        ['R13', 'Google Display length limits', 'text', [
            'headline' => 30, 'description' => 90, 'primary_text' => 90,
        ]],
    ];
}

/** Fresh token dict for a new workspace. */
function template_tokens(string $template): array {
    return $template === 'sweeps_casino' ? sweeps_tokens() : blank_tokens();
}

/** Fresh rule rows: list of [rule_id, name, type, enabled, config]. */
function template_rules(string $template): array {
    $rows = [];
    foreach (sweeps_rule_defs() as $def) {
        [$ruleId, $name, $type, $config] = $def;
        if ($template === 'sweeps_casino') {
            $rows[] = [$ruleId, $name, $type, 1, $config];
        } else { // blank: same rule shells, all disabled, empty configs
            $rows[] = [$ruleId, $name, $type, 0, []];
        }
    }
    return $rows;
}
