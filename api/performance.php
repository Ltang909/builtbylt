<?php
/**
 * Performance board endpoint (workspace-scoped).
 *   GET api/performance.php?workspace_id=N
 *   -> [ {creative, experiment, days[], total_spend,
 *         total_customers, cac,
 *         perf_status, perf_status_reason} ]
 *
 * Update 4: pass/fail/kill is a COMPUTED verdict, never a manual
 * one. Loading this board first runs threshold evaluation for the
 * workspace (lib/evaluate.php) — persisting perf_status + reason on
 * each creative, writing deduped learnings, applying kills, and
 * emailing on transitions into fail/kill — then returns the board.
 * The rows shown are only ever real rows from the performance
 * table: the old simulated seeding endpoint and the manual
 * Pass/Fail/Kill buttons are gone, and the simulated-rows generator
 * file remains on disk but is referenced by nothing in the product
 * surface.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/evaluate.php';
require_api_auth($pdo);

// Update 7: intake writes. A performance row can be attached to a
// creative in this workspace (Step 0 intake of ads that already ran).
// POST {creative_id, date?, spend, impressions, clicks, signups,
//       purchasers} -> the new row id. Verdicts stay
// computed: inserting a row changes nothing until evaluation runs
// (which the next GET of this board does).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $cid = (int)($data['creative_id'] ?? 0);
    $cs = $pdo->prepare(
        'SELECT id FROM creatives WHERE id = ? AND workspace_id = ?');
    $cs->execute([$cid, $wsid]);
    if (!$cs->fetch()) {
        json_out(['error' => 'creative not found in this workspace'], 404);
    }
    $date = !empty($data['date'])
        ? substr((string)$data['date'], 0, 10) : date('Y-m-d');
    $stmt = $pdo->prepare(
        'INSERT INTO performance (workspace_id, creative_id, date, '
        . 'spend, impressions, clicks, signups, purchasers, '
        . 'source) VALUES (?, ?, ?, ?, ?, ?, ?, \'manual\')');
    $stmt->execute([
        $wsid, $cid, $date,
        (float)($data['spend'] ?? 0), (int)($data['impressions'] ?? 0),
        (int)($data['clicks'] ?? 0), (int)($data['signups'] ?? 0),
        (int)($data['purchasers'] ?? 0),
    ]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(['error' => 'method not allowed'], 405);
}

$wsid = resolve_workspace($pdo);
if ($wsid === null) {
    json_out(['error' => 'workspace not found'], 404);
}

// Evaluation trigger: Performance tab load. Guarded inside
// evaluate_workspace — on a pre-migration-003 database it skips and
// the board still renders (without computed statuses).
$evaluation = evaluate_workspace($pdo, $wsid);

$stmt = $pdo->prepare(
    'SELECT * FROM creatives WHERE workspace_id = ? ORDER BY id');
$stmt->execute([$wsid]);
$creatives = $stmt->fetchAll(PDO::FETCH_ASSOC);

$board = [];
foreach ($creatives as $c) {
    $exp = null;
    if (!empty($c['experiment_id'])) {
        $es = $pdo->prepare('SELECT * FROM experiments WHERE id = ?');
        $es->execute([(int)$c['experiment_id']]);
        $exp = $es->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $ts = $pdo->prepare(
        'SELECT COALESCE(SUM(spend),0) AS spend, '
        . 'COALESCE(SUM(purchasers),0) AS purchasers '
        . 'FROM performance WHERE creative_id = ?');
    $ts->execute([(int)$c['id']]);
    $totals = $ts->fetch(PDO::FETCH_ASSOC) ?: [];
    $ds = $pdo->prepare(
        'SELECT * FROM performance WHERE creative_id = ? ORDER BY date');
    $ds->execute([(int)$c['id']]);
    $days = $ds->fetchAll(PDO::FETCH_ASSOC);

    $spend = (float)($totals['spend'] ?? 0);
    $purchasers = (int)($totals['purchasers'] ?? 0);

    $c['id'] = (int)$c['id'];
    $c['workspace_id'] = (int)$c['workspace_id'];
    $c['perf_status'] = $c['perf_status'] ?? null;
    $c['perf_status_reason'] = $c['perf_status_reason'] ?? null;
    if (!empty($c['image_path'])) {
        $c['image_url'] = 'api/image.php?creative_id=' . (int)$c['id'];
    }
    $board[] = [
        'creative' => $c,
        'experiment' => $exp,
        'days' => $days,
        'total_spend' => $spend,
        'total_customers' => $purchasers,
        'cac' => $purchasers > 0 ? $spend / $purchasers : null,
        'perf_status' => $c['perf_status'],
        'perf_status_reason' => $c['perf_status_reason'],
        'evaluation' => $evaluation['skipped'] ?? 'ran',
    ];
}
json_out($board);
