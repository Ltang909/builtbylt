<?php
/**
 * Shared Step 3a verify procedure for ONE creative — extracted from
 * api/verify.php so the single-verify action and the verify_batch
 * action run the exact same code (verdict replacement, status routing,
 * ledger row). Do not fork this logic into endpoints.
 *
 * Also holds the verify COST ESTIMATE used by the estimate-then-confirm
 * gate (Leon's hard rule: no provider spend without an on-screen
 * estimate and an explicit confirmation first). The estimate function
 * makes NO provider calls.
 */

require_once __DIR__ . '/jev.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/learnings.php';

/**
 * Run verification for one creative row and persist everything, exactly
 * as api/verify.php's single verify always has:
 *   status -> 'verifying' during the run, then routed:
 *     hard fail (Jev)     -> jev_failed
 *     uncertain Jev band  -> claude_review
 *     any local fail      -> jev_failed
 *     all pass            -> claude_review
 *     no rules enabled    -> claude_review (ledger records the skip)
 *
 * @return array{status: string, failed_rules: string[],
 *               verifier: array{ran: string, provider: ?string,
 *               model: ?string, status: ?string, note: ?string}}
 */
function run_creative_verify(PDO $pdo, array $row): array {
    $cid = (int)$row['id'];
    $wsid = (int)$row['workspace_id'];

    $pdo->prepare('UPDATE creatives SET status = \'verifying\' WHERE id = ?')
        ->execute([$cid]);

    $tokens = get_tokens($pdo, $wsid);
    $rules = get_rules($pdo, $wsid);
    $apiKey = vault_key($pdo, 'typesafe');
    [$verdicts, $info] = jev_verify_creative(
        $row, $tokens, $rules, $apiKey, PANEL_ROOT);

    $pdo->prepare('DELETE FROM verdicts WHERE creative_id = ?')
        ->execute([$cid]);
    $ins = $pdo->prepare(
        'INSERT INTO verdicts (workspace_id, creative_id, rule_id, '
        . 'verdict, confidence, source, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $failed = [];
    foreach ($verdicts as $v) {
        $ins->execute([$wsid, $cid, $v['rule_id'], $v['verdict'],
                       $v['confidence'], $v['source'], $v['note']]);
        if ($v['verdict'] === 'fail') {
            $failed[] = $v['rule_id'];
        }
    }

    $anyEnabled = false;
    foreach ($rules as $r) {
        if (!empty($r['enabled'])) { $anyEnabled = true; break; }
    }
    if (!$anyEnabled) {
        $newStatus = 'claude_review';
        $note = 'No rules enabled in this workspace; nothing to verify. '
            . $info['note'];
        log_ledger($pdo, $wsid, 'verify_binary', $info['provider'],
                   $info['model'], 'skipped', $note);
    } else {
        if (!empty($info['hard_fail'])) {
            $newStatus = 'jev_failed';
        } elseif (!empty($info['uncertain'])) {
            $newStatus = 'claude_review';
        } elseif ($failed) {
            $newStatus = 'jev_failed';
        } else {
            $newStatus = 'claude_review';
        }
        log_ledger($pdo, $wsid, 'verify_binary', $info['provider'],
                   $info['model'], $info['status'], $info['note']);
    }
    $pdo->prepare('UPDATE creatives SET status = ? WHERE id = ?')
        ->execute([$newStatus, $cid]);

    // Learnings loop (Update 4): a guardrail failure is a learning.
    // The reason leads with the failed rule ids (the prompt-in
    // compression reads exactly that), deduped on the exact reason
    // so re-verifying an unchanged failure does not stack rows.
    if ($newStatus === 'jev_failed' && $failed) {
        try {
            $failNotes = [];
            foreach ($verdicts as $v) {
                if ($v['verdict'] === 'fail') {
                    $failNotes[] = $v['rule_id'] . ': ' . $v['note'];
                }
            }
            record_learning($pdo, $wsid, $cid, 'jev_failed',
                'Guardrail verify failed ' . implode(', ', $failed)
                . '. ' . implode('; ', $failNotes));
        } catch (Throwable $e) {
            log_ledger($pdo, $wsid, 'learnings', null, null, 'error',
                'creative #' . $cid . ': '
                . substr($e->getMessage(), 0, 240));
        }
    }

    return [
        'status' => $newStatus,
        'failed_rules' => $failed,
        'verifier' => [
            'ran' => $info['ran'],
            'provider' => $info['provider'],
            'model' => $info['model'],
            'status' => $info['status'],
            'note' => $info['note'],
        ],
    ];
}

/**
 * Cost ESTIMATE for verifying $count creatives. No provider calls.
 * With a TypeSafe key configured the estimate prices the Jev call;
 * without one, verification runs on the free local engine and the
 * estimate honestly says so (cost 0).
 */
function verify_estimate(PDO $pdo, int $count): array {
    $keySet = vault_key($pdo, 'typesafe') !== null;
    if ($keySet) {
        $cost = est_verify_cost($count, JEV_EST_INPUT_TOKENS_PER_CREATIVE);
        $note = 'Estimate only — nothing has run. Jev (TypeSafe) prices '
            . 'input tokens only; the local-engine fallback, if Jev '
            . 'fails, is free.';
        $provider = 'typesafe';
    } else {
        $cost = 0.0;
        $note = 'Estimate only — nothing has run. No TypeSafe key is '
            . 'configured, so verification runs on the local engine, '
            . 'which is free.';
        $provider = 'local';
    }
    return [
        'estimate' => true,
        'label' => 'estimate',
        'creatives' => $count,
        'provider' => $provider,
        'model' => $keySet ? JEV_MODEL : 'local-engine',
        'est_input_tokens_each' => JEV_EST_INPUT_TOKENS_PER_CREATIVE,
        'est_input_tokens' => $count * JEV_EST_INPUT_TOKENS_PER_CREATIVE,
        'cost_usd' => round($cost, 6),
        'note' => $note,
    ];
}
