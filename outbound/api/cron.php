<?php
/**
 * Scheduled discovery runner. NO panel session auth: this endpoint
 * is called by an external scheduler (a cron service hitting the
 * URL), and it gates on the shared cron secret instead. The secret
 * is stored hashed in obe_settings (global row, key cron_secret) by
 * settings.php and checked here with password_verify.
 *
 *   GET cron.php?secret=...&action=run_due
 *        For every Active campaign, run one discovery pass for the
 *        campaign's client and ICP. Per-client research limits are
 *        respected: a client whose last discovery run finished less
 *        than 6 hours ago is skipped, so overlapping scheduler hits
 *        cannot double-process the same companies back to back.
 */
define('OBE_NO_AUTH', true);
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$hash = obe_setting($pdo, null, 'cron_secret', null);
if ($hash === null || (string)$hash === '') {
    http_response_code(403);
    echo json_encode(['error' => 'cron_secret_not_set',
                      'hint' => 'Set a cron secret in Settings and '
                              . 'Integrations first.']);
    exit;
}
$secret = (string)($_GET['secret'] ?? '');
if ($secret === '' || !password_verify($secret, (string)$hash)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_secret']);
    exit;
}

$action = (string)($_GET['action'] ?? '');
if ($action !== 'run_due') {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}

$stmt = $pdo->query(
    'SELECT c.*, cl.name AS client_name FROM obe_campaigns c '
    . 'JOIN obe_clients cl ON cl.id = c.client_id '
    . 'WHERE c.state = \'Active\' ORDER BY c.id');
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lastRun = $pdo->prepare(
    'SELECT created_at FROM obe_job_runs WHERE client_id = ? '
    . 'AND type = \'discovery\' ORDER BY id DESC LIMIT 1');
$results = [];
foreach ($campaigns as $campaign) {
    $clientId = (int)$campaign['client_id'];
    $lastRun->execute([$clientId]);
    $last = $lastRun->fetch(PDO::FETCH_ASSOC);
    if ($last !== false
        && strtotime((string)$last['created_at']) > time() - 6 * 3600) {
        $results[] = ['campaign_id' => (int)$campaign['id'],
                      'client_id' => $clientId,
                      'skipped' => 'discovery ran less than 6 hours ago'];
        continue;
    }
    $clientStmt = $pdo->prepare('SELECT * FROM obe_clients WHERE id = ?');
    $clientStmt->execute([$clientId]);
    $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if ($clientRow === false) {
        continue;
    }
    $clientRow['id'] = $clientId;
    $icp = null;
    if (!empty($campaign['icp_id'])) {
        $icpStmt = $pdo->prepare(
            'SELECT * FROM obe_icp_profiles WHERE id = ? '
            . 'AND client_id = ?');
        $icpStmt->execute([(int)$campaign['icp_id'], $clientId]);
        $icpRow = $icpStmt->fetch(PDO::FETCH_ASSOC);
        if ($icpRow !== false) {
            $icp = $icpRow;
        }
    }
    $summary = obe_run_discovery($pdo, $clientRow, $icp);
    $results[] = ['campaign_id' => (int)$campaign['id'],
                  'client_id' => $clientId,
                  'summary' => $summary];
}
obe_log_run($pdo, null, 'cron',
            'run_due processed ' . count($campaigns)
            . ' active campaigns.');
echo json_encode(['ran' => count($results), 'results' => $results]);
