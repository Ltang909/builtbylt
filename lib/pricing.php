<?php
/**
 * Provider price ESTIMATES — the single source of truth for every cost
 * number the panel shows before or after a provider call.
 *
 * All figures are ESTIMATES for planning and for Leon's
 * estimate-then-confirm gate (see README). They are list prices as last
 * checked, not billing truth: the provider's invoice wins any argument.
 * Actual spend per call is computed from the REAL usage the API returns
 * (chat calls) or from this table (image calls, which return no usage
 * object), and is written to the call ledger + generation_batches.
 *
 * Prices are USD. Token prices are per 1M tokens.
 */

// --- Price table (estimate, checked 2026-10-17) ------------------------------

// Chat models: [input, output] USD per 1M tokens.
const CHAT_PRICES_USD_PER_1M = [
    'gpt-4o-mini' => [0.15, 0.60],   // estimate, checked 2026-10-17
    'gpt-4o'      => [2.50, 10.00],  // estimate, checked 2026-10-17
];
// Fallback for an unlisted chat model: price it as gpt-4o so an estimate
// errs high rather than low.
const CHAT_PRICE_FALLBACK_MODEL = 'gpt-4o';

// gpt-image-1, per image by size and quality (Hotfix 4.5: the canvas
// follows each variant's format, so non-square sizes are priced too).
const IMAGE_PRICES_USD_BY_SIZE = [   // estimate, checked 2026-10-22
    '1024x1024' => ['low' => 0.011, 'medium' => 0.042, 'high' => 0.167],
    '1024x1536' => ['low' => 0.016, 'medium' => 0.063, 'high' => 0.250],
    '1536x1024' => ['low' => 0.016, 'medium' => 0.063, 'high' => 0.250],
];
// The square row as its own constant: older code refers to it.
const IMAGE_PRICES_USD = [           // estimate, checked 2026-10-17
    'low'    => 0.011,
    'medium' => 0.042,
    'high'   => 0.167,
];
const IMAGE_PRICE_FALLBACK_QUALITY = 'medium';

// TypeSafe Jev: input tokens only, output is free.
const JEV_PRICE_USD_PER_1M_INPUT = 0.042;  // estimate, checked 2026-10-17

// Heuristic: estimated Jev input tokens per creative verification. The
// request state carries the creative's copy + brand tokens, and one noul
// question per enabled rule; measured states land in the high hundreds.
const JEV_EST_INPUT_TOKENS_PER_CREATIVE = 900;

// --- Helpers -----------------------------------------------------------------

/** Estimated (or actual, when fed real usage) cost of a chat call, USD. */
function est_copy_cost(string $model, int $inputTokens, int $outputTokens): float {
    $rates = CHAT_PRICES_USD_PER_1M[$model]
        ?? CHAT_PRICES_USD_PER_1M[CHAT_PRICE_FALLBACK_MODEL];
    return $inputTokens * $rates[0] / 1_000_000
         + $outputTokens * $rates[1] / 1_000_000;
}

/** Estimated cost of $count generated images at a quality tier, USD.
 *  Estimates price every image at the LARGEST size's rate: the canvas
 *  size is only known once the copy call returns each variant's format
 *  (Hotfix 4.5), and the estimate gate must never under-promise.
 *  Actuals use image_unit_price() at the real size. */
function est_image_cost(string $quality, int $count): float {
    $each = 0.0;
    foreach (IMAGE_PRICES_USD_BY_SIZE as $byQuality) {
        $p = $byQuality[$quality]
            ?? $byQuality[IMAGE_PRICE_FALLBACK_QUALITY];
        $each = max($each, $p);
    }
    return $each * $count;
}

/** Per-image price at a quality tier and size, USD (used for actuals:
 *  the image API returns no usage object, so actuals are this table
 *  × count). Unknown sizes fall back to the square row. */
function image_unit_price(string $quality, string $size = '1024x1024'): float {
    $byQuality = IMAGE_PRICES_USD_BY_SIZE[$size]
        ?? IMAGE_PRICES_USD_BY_SIZE['1024x1024'];
    return $byQuality[$quality]
        ?? $byQuality[IMAGE_PRICE_FALLBACK_QUALITY];
}

/** Estimated cost of verifying $creativeCount creatives with Jev, USD. */
function est_verify_cost(int $creativeCount, int $estInputTokensEach): float {
    return $creativeCount * $estInputTokensEach
         * JEV_PRICE_USD_PER_1M_INPUT / 1_000_000;
}
