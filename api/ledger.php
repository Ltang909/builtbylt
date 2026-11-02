<?php
/**
 * Call ledger endpoint: every verify run (and guideline fetch / extraction
 * attempt) with the provider that ACTUALLY ran.
 *   GET api/ledger.php?limit=100&workspace_id=N -> [ row, ... ] newest first
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

$limit = 100;
if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
    $limit = min((int)$_GET['limit'], 500);
}
if (isset($_GET['workspace_id']) && is_numeric($_GET['workspace_id'])) {
    $stmt = $pdo->prepare(
        'SELECT * FROM call_ledger WHERE workspace_id = ? '
        . 'ORDER BY id DESC LIMIT ' . $limit);
    $stmt->execute([(int)$_GET['workspace_id']]);
} else {
    $stmt = $pdo->query(
        'SELECT * FROM call_ledger ORDER BY id DESC LIMIT ' . $limit);
}
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    if ($r['workspace_id'] !== null) {
        $r['workspace_id'] = (int)$r['workspace_id'];
    }
}
json_out($rows);
