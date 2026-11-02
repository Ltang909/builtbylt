<?php
/**
 * Creative actions endpoint.
 *   POST api/verify.php {creative_id, action: "estimate_verify"}
 *        Cost ESTIMATE for verifying one creative. No provider calls.
 *   POST api/verify.php {creative_id, action: "verify", confirm: true}
 *        Runs Step 3a for the creative's workspace: Jev (TypeSafe) when
 *        a key is configured, local token-driven engine otherwise or on
 *        any Jev failure — the response and the call ledger both say
 *        which one actually ran (see lib/jev.php). Replaces the
 *        creative's verdicts and routes it:
 *          hard fail            -> jev_failed (back to Step 4 edits)
 *          uncertain Jev band   -> claude_review (judgment, not auto-fail)
 *          any local fail       -> jev_failed
 *          all pass             -> claude_review (Step 3b / Step 5)
 *          no rules enabled     -> claude_review, ledger records the skip
 *   POST api/verify.php {batch_id, action: "estimate_verify_batch"}
 *        Cost ESTIMATE for verifying every still-draft creative in a
 *        generation batch. No provider calls.
 *   POST api/verify.php {batch_id, action: "verify_batch", confirm: true}
 *        Verifies those draft creatives through the same shared
 *        procedure as single verify (lib/verifyrun.php).
 *   POST api/verify.php {creative_id, action: "approve"} -> status approved
 *   POST api/verify.php {creative_id, action: "reject"}  -> edits_requested
 *        (Step 5 human gate — never automated.)
 *
 * ESTIMATE-THEN-CONFIRM (Leon's hard rule): verify and verify_batch
 * spend provider budget, so both refuse without confirm:true. The
 * client must first call the matching estimate action, show the number,
 * and only then re-send with confirm:true after the operator confirms.
 * The estimate actions themselves never call a provider.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/verifyrun.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = read_json_body();
$action = (string)($data['action'] ?? ($_GET['action'] ?? ''));

// --- batch actions (no creative_id involved) --------------------------------
if ($action === 'estimate_verify_batch' || $action === 'verify_batch') {
    if (!panel_table_exists($pdo, 'generation_batches')
        || !panel_column_exists($pdo, 'creatives', 'batch_id')) {
        migration_required('Verify batch');
    }
    $batchId = (int)($data['batch_id'] ?? 0);
    $bs = $pdo->prepare('SELECT * FROM generation_batches WHERE id = ?');
    $bs->execute([$batchId]);
    $batch = $bs->fetch(PDO::FETCH_ASSOC);
    if ($batch === false) {
        json_out(['error' => 'batch not found'], 404);
    }
    // Draft state in this panel is 'generated' (the insert default).
    $cs = $pdo->prepare(
        'SELECT * FROM creatives WHERE batch_id = ? AND status = '
        . '\'generated\' ORDER BY id');
    $cs->execute([$batchId]);
    $drafts = $cs->fetchAll(PDO::FETCH_ASSOC);

    if ($action === 'estimate_verify_batch') {
        $est = verify_estimate($pdo, count($drafts));
        $est['batch_id'] = $batchId;
        json_out($est);
    }

    // verify_batch: estimate-then-confirm gate.
    if (empty($data['confirm'])) {
        json_out(['error' => 'confirmation required',
                  'hint' => 'Call action=estimate_verify_batch first, '
                          . 'show the estimate, and re-send with '
                          . 'confirm:true only after the operator '
                          . 'confirms. Nothing has run.'], 400);
    }
    $results = [];
    $counts = [];
    foreach ($drafts as $drow) {
        $r = run_creative_verify($pdo, $drow);
        $results[] = [
            'creative_id' => (int)$drow['id'],
            'status' => $r['status'],
            'failed_rules' => $r['failed_rules'],
            'verifier' => $r['verifier'],
        ];
        $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
    }
    json_out([
        'batch_id' => $batchId,
        'verified' => count($results),
        'outcomes' => $counts,
        'results' => $results,
    ]);
}

// --- single-creative actions --------------------------------------------------
$cid = (int)($data['creative_id'] ?? ($_GET['creative_id'] ?? 0));
$row = fetch_creative($pdo, $cid);
if ($row === null) {
    json_out(['error' => 'creative not found'], 404);
}
$wsid = (int)$row['workspace_id'];

if ($action === 'estimate_verify') {
    $est = verify_estimate($pdo, 1);
    $est['creative_id'] = $cid;
    json_out($est);
}

$respond = function () use ($pdo, $cid) {
    $fresh = fetch_creative($pdo, $cid);
    $fresh['id'] = (int)$fresh['id'];
    $fresh['workspace_id'] = (int)$fresh['workspace_id'];
    return creative_with_verdicts($pdo, $fresh);
};

if ($action === 'approve' || $action === 'reject') {
    $status = $action === 'approve' ? 'approved' : 'edits_requested';
    $stmt = $pdo->prepare('UPDATE creatives SET status = ? WHERE id = ?');
    $stmt->execute([$status, $cid]);
    json_out($respond());
}

if ($action !== 'verify') {
    json_out(['error' => 'unknown action'], 400);
}

// --- verify (estimate-then-confirm gate) --------------------------------------
if (empty($data['confirm'])) {
    json_out(['error' => 'confirmation required',
              'hint' => 'Call action=estimate_verify first, show the '
                      . 'estimate, and re-send with confirm:true only '
                      . 'after the operator confirms. Nothing has run.'],
             400);
}
$r = run_creative_verify($pdo, $row);
$out = $respond();
$out['failed_rules'] = $r['failed_rules'];
$out['verifier'] = $r['verifier'];
json_out($out);
