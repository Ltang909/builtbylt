<?php
/**
 * Scheduled discovery runner. NO panel session auth: this endpoint
 * is called by an external scheduler (an hPanel cron job or a cron
 * service hitting the URL), and it gates on the shared cron secret
 * instead. The secret is stored hashed in obe_settings (global row,
 * key cron_secret) by settings.php and checked here with
 * password_verify.
 *
 *   GET cron.php?secret=<redacted>
 *        For every Active campaign, run one discovery pass for the
 *        campaign's client and ICP.
 *   GET cron.php?secret=<redacted>
 *        For every client with an ICP configured, run one
 *        discovery pass (the workspace's first ICP). This is the
 *        action a daily schedule should call: it keeps companies,
 *        boards, signals and scores fresh for workspaces that are
 *        not running a campaign.
 *
 * Per-client research limits are respected in both modes: a client
 * whose last discovery run finished less than 6 hours ago is
 * skipped, so overlapping scheduler hits cannot double-process the
 * same companies back to back. Scheduled runs are DISCOVERY ONLY:
 * board reads and news checks are free, and nothing here spends
 * Apollo credits — people search and enrichment stay manual,
 * estimate-then-confirm actions in the UI.
 *
 * Notification: when the global notify_email setting holds an
 * address and a call produced new signal observations or run
 * errors, one digest email is sent listing the new signals (with
 * their evidence), per-client run totals and any failures. The
 * call's JSON response carries a "notify" field saying what
 * happened (not_configured / nothing_new / sent / mail_failed).
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
if ($action !== 'run_due' && $action !== 'run_all') {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}

// Watermark for the notification digest: observations created by
// THIS call are the ones with an id above the current maximum.
$wmStmt = $pdo->query(
    'SELECT COALESCE(MAX(id), 0) FROM obe_signal_observations');
$watermark = (int)$wmStmt->fetchColumn();

$lastRun = $pdo->prepare(
    'SELECT created_at FROM obe_job_runs WHERE client_id = ? '
    . 'AND type = \'discovery\' ORDER BY id DESC LIMIT 1');
$clientStmt = $pdo->prepare('SELECT * FROM obe_clients WHERE id = ?');
$results = [];
$processedLabel = '';

if ($action === 'run_due') {
    $stmt = $pdo->query(
        'SELECT c.*, cl.name AS client_name FROM obe_campaigns c '
        . 'JOIN obe_clients cl ON cl.id = c.client_id '
        . 'WHERE c.state = \'Active\' ORDER BY c.id');
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    $processedLabel = 'run_due processed ' . count($campaigns)
        . ' active campaigns.';
} else {
    $stmt = $pdo->query(
        'SELECT cl.* FROM obe_clients cl WHERE EXISTS '
        . '(SELECT 1 FROM obe_icp_profiles p '
        . 'WHERE p.client_id = cl.id) ORDER BY cl.id');
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($clients as $clientRow) {
        $clientId = (int)$clientRow['id'];
        $lastRun->execute([$clientId]);
        $last = $lastRun->fetch(PDO::FETCH_ASSOC);
        if ($last !== false
            && strtotime((string)$last['created_at']) > time() - 6 * 3600) {
            $results[] = ['client_id' => $clientId,
                          'skipped' => 'discovery ran less than 6 hours ago'];
            continue;
        }
        $clientRow['id'] = $clientId;
        $summary = obe_run_discovery($pdo, $clientRow, null);
        $results[] = ['client_id' => $clientId,
                      'summary' => $summary];
    }
    $processedLabel = 'run_all processed ' . count($clients)
        . ' configured clients.';
}

// --- notification digest ------------------------------------------------
$totalNew = 0;
$totalErrors = [];
foreach ($results as $r) {
    if (isset($r['summary']) && is_array($r['summary'])) {
        $totalNew += (int)($r['summary']['new_observations'] ?? 0);
        foreach ((array)($r['summary']['errors'] ?? []) as $e) {
            $totalErrors[] = (string)$e;
        }
    }
}
$notifyEmail = trim((string)obe_setting($pdo, null, 'notify_email', ''));
$notify = 'not_configured';
if ($notifyEmail !== '') {
    if ($totalNew === 0 && count($totalErrors) === 0) {
        $notify = 'nothing_new';
    } else {
        $lines = [];
        $lines[] = 'Scheduled discovery run (' . $action . ') — '
            . date('Y-m-d H:i') . ' server time.';
        $lines[] = '';
        if ($totalNew > 0) {
            $lines[] = 'NEW SIGNALS (' . $totalNew . '):';
            $obsStmt = $pdo->prepare(
                'SELECT o.evidence, o.source_type, co.name AS company, '
                . 's.name AS signal_name FROM obe_signal_observations o '
                . 'JOIN obe_companies co ON co.id = o.company_id '
                . 'JOIN obe_intent_signals s ON s.id = o.signal_id '
                . 'WHERE o.id > ? ORDER BY o.id');
            $obsStmt->execute([$watermark]);
            $shown = 0;
            foreach ($obsStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                if ($shown >= 30) {
                    $lines[] = '  …and more in the discovery feed.';
                    break;
                }
                $lines[] = '  • ' . (string)$o['company'] . ' — '
                    . (string)$o['signal_name'] . ' ['
                    . (string)$o['source_type'] . ']: '
                    . (string)$o['evidence'];
                $shown++;
            }
            $lines[] = '';
        }
        $lines[] = 'RUN TOTALS:';
        foreach ($results as $r) {
            if (!isset($r['summary'])) {
                $lines[] = '  • client ' . (int)$r['client_id']
                    . ': skipped ('
                    . (string)($r['skipped'] ?? 'recent run') . ')';
                continue;
            }
            $s = $r['summary'];
            $lines[] = '  • client ' . (int)$r['client_id'] . ': '
                . (int)$s['companies_checked'] . ' companies, '
                . (int)$s['jobs_seen'] . ' jobs, '
                . (int)$s['new_observations'] . ' new signals, '
                . (int)$s['queued'] . ' queued for enrichment, '
                . (int)($s['probes_made'] ?? 0) . ' probes, '
                . (int)($s['news_failed'] ?? 0) . ' news failures.';
        }
        if (count($totalErrors) > 0) {
            $lines[] = '';
            $lines[] = 'FAILURES:';
            foreach (array_slice($totalErrors, 0, 10) as $e) {
                $lines[] = '  • ' . $e;
            }
        }
        $lines[] = '';
        $lines[] = 'Open the engine: https://builtbylt.com/outbound/';
        $subject = 'Outbound engine: ' . $totalNew . ' new signal'
            . ($totalNew === 1 ? '' : 's')
            . (count($totalErrors) > 0
                ? ' (+ ' . count($totalErrors) . ' failures)' : '');
        $sent = @mail($notifyEmail, $subject,
                      implode("\n", $lines),
                      'From: outbound@builtbylt.com' . "\r\n"
                      . 'Content-Type: text/plain; charset=utf-8');
        $notify = $sent ? 'sent' : 'mail_failed';
    }
}

obe_log_run($pdo, null, 'cron',
            $processedLabel . ' New observations: ' . $totalNew
            . '. Notify: ' . $notify . '.');
echo json_encode(['ran' => count($results),
                  'new_observations' => $totalNew,
                  'notify' => $notify,
                  'results' => $results]);
