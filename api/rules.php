<?php
/**
 * Rules endpoint (per workspace).
 *   GET  api/rules.php?workspace_id=N -> [ {rule_id, name, type, enabled,
 *                                          config} ]
 *   POST api/rules.php {workspace_id, rule_id, enabled} -> toggle one rule
 * Only enabled rules produce verdicts at verify time.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    json_out(get_rules($pdo, $wsid));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $ruleId = (string)($data['rule_id'] ?? '');
    $enabled = !empty($data['enabled']);
    $stmt = $pdo->prepare(
        'UPDATE rules SET enabled = ? WHERE workspace_id = ? AND rule_id = ?');
    $stmt->execute([$enabled ? 1 : 0, $wsid, $ruleId]);
    json_out(['ok' => true, 'rule_id' => $ruleId, 'enabled' => $enabled]);
}

json_out(['error' => 'method not allowed'], 405);
