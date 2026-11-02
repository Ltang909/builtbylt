<?php
/**
 * Evaluation cron endpoint (Update 4) — the "push" trigger.
 *   GET api/cron_evaluate.php?token=<cron_token>
 *
 * Runs threshold evaluation for EVERY workspace (persisting
 * perf_status, learnings rows, kills) and flushes the fail/kill
 * transition emails. Point an hPanel cron job at this URL every
 * ~15 minutes for true push notifications; without it, evaluation
 * still happens whenever the Performance tab loads or performance
 * rows are written.
 *
 * Auth is the token, not the session: the cron_token setting
 * (panel-global scope, created/regenerated in Settings) compared
 * with hash_equals. No token configured -> 403 for everyone,
 * including the operator, until one is generated in Settings.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/evaluate.php';

$stored = settings_ready($pdo)
    ? (string)get_setting($pdo, 0, 'cron_token', '') : '';
$given = (string)($_GET['token'] ?? '');
if ($stored === '' || $given === '' || !hash_equals($stored, $given)) {
    json_out(['error' => 'forbidden',
              'hint' => 'Generate a cron token in Settings and call '
                      . 'this URL with ?token=<cron_token>.'], 403);
}

$workspaces = $pdo->query('SELECT id FROM workspaces ORDER BY id')
                  ->fetchAll(PDO::FETCH_COLUMN);
$out = ['ok' => true, 'workspaces' => [], 'notifications' => 0];
foreach ($workspaces as $wid) {
    $res = evaluate_workspace($pdo, (int)$wid);
    $out['workspaces'][] = [
        'workspace_id' => (int)$wid,
        'evaluated' => $res['evaluated'],
        'transitions' => $res['transitions'],
        'notifications' => $res['notifications'],
    ] + (isset($res['skipped']) ? ['skipped' => $res['skipped']] : []);
    $out['notifications'] += $res['notifications'];
}
json_out($out);
