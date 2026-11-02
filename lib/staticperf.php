<?php
/**
 * STATIC (simulated) performance rows for the MVP proof of concept.
 *
 * These numbers are FAKE and are always stored with source='static' and
 * labeled STATIC in the UI. They exist so the Performance board's
 * pass/fail/kill logic and the apply_verdict flow can be exercised end
 * to end before any real ad platform is connected (Meta stays simulated
 * until Step 0 clears). Nothing here ever calls a provider.
 *
 * Generation is a pure function of (creative_id, start_date): a small
 * LCG PRNG seeded from the creative id, so the same creative always
 * produces the same rows — re-seeding is refused by the endpoint
 * instead of stacking a second, different fiction on top of the first.
 */

/** Tiny deterministic PRNG (LCG) — same sequence on every PHP build. */
function static_prng(int $seed): callable {
    $state = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
    if ($state === 0) {
        $state = 987654321;
    }
    return function () use (&$state): float {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
        return $state / 0x7FFFFFFF; // 0.0 .. 1.0
    };
}

/**
 * Seven daily performance rows for a creative.
 * Plausible ranges: daily spend $40–120; CTR 0.8–2.2%; click→signup
 * 6–12%; signup→customer 12–25%. Verdicts read CAC (spend over
 * customers) only; the legacy rngr_d7 column is written as 0 and
 * unused since the CAC switch (Update 10.3).
 *
 * @return array<int, array{date:string, spend:float, impressions:int,
 *   clicks:int, signups:int, purchasers:int, rngr_d7:float,
 *   source:string}>
 */
function static_perf_rows(int $creativeId, string $startDate): array {
    $next = static_prng($creativeId);
    $start = new DateTimeImmutable($startDate);
    $rows = [];
    for ($i = 0; $i < 7; $i++) {
        $spend = round(40 + $next() * 80, 2);                    // 40–120
        $impressions = (int)round($spend * (60 + $next() * 90)); // CPM ~$7–17
        $ctr = 0.008 + $next() * 0.014;                          // 0.8–2.2%
        $clicks = (int)round($impressions * $ctr);
        $signups = (int)round($clicks * (0.06 + $next() * 0.06));
        $purchasers = (int)round($signups * (0.12 + $next() * 0.13));
        if ($purchasers > $signups) {
            $purchasers = $signups;
        }
        $rows[] = [
            'date' => $start->modify("+$i days")->format('Y-m-d'),
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'signups' => $signups,
            'purchasers' => $purchasers,
            'rngr_d7' => 0.0, // legacy column, unused (see docblock)
            'source' => 'static',
        ];
    }
    return $rows;
}
