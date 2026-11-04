<?php
/**
 * Reporting endpoint.
 *   GET reports.php?client_id=N[&from=YYYY-MM-DD&to=YYYY-MM-DD]
 *        Metric totals with honest denominators, provider usage, and
 *        breakdowns by signal, title, channel, and ICP. Cost figures
 *        come from obe_provider_usage est_cost (LLM estimates logged
 *        at call time). Apollo spend is tracked in credits, not
 *        dollars, and is reported as credits.
 *   GET reports.php?client_id=N&view=recommendations
 *        What the stored outcomes suggest, with sample sizes. Items
 *        under 20 samples are labeled observations, never patterns.
 *   POST reports.php?action=apply_recommendation {client_id, action}
 *        Applies one user approved change (signal importance or ICP
 *        weights) and logs it as an experiment.
 */
require __DIR__ . '/bootstrap.php';

function obe_scalar(PDO $pdo, string $sql, array $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return 0;
    }
    $values = array_values($row);
    return $values[0];
}

/** " AND col >= ? AND col <= ?" fragment for an optional date range. */
function obe_range(string $column, $from, $to, array &$params): string {
    $sql = '';
    if ($from !== null && $from !== '') {
        $sql .= ' AND ' . $column . ' >= ?';
        $params[] = (string)$from . ' 00:00:00';
    }
    if ($to !== null && $to !== '') {
        $sql .= ' AND ' . $column . ' <= ?';
        $params[] = (string)$to . ' 23:59:59';
    }
    return $sql;
}

function obe_norm_weights(array $w): array {
    $fit = isset($w['fit']) && is_numeric($w['fit']) ? (float)$w['fit'] : 40.0;
    $signal = isset($w['signal']) && is_numeric($w['signal'])
        ? (float)$w['signal'] : 40.0;
    $evidence = isset($w['evidence']) && is_numeric($w['evidence'])
        ? (float)$w['evidence'] : 20.0;
    $sum = $fit + $signal + $evidence;
    if ($sum <= 0) {
        return ['fit' => 40, 'signal' => 40, 'evidence' => 20];
    }
    $fitR = (int)round($fit / $sum * 100);
    $signalR = (int)round($signal / $sum * 100);
    $evidenceR = 100 - $fitR - $signalR;
    if ($evidenceR < 0) {
        $evidenceR = 0;
        $signalR = 100 - $fitR;
    }
    return ['fit' => $fitR, 'signal' => $signalR, 'evidence' => $evidenceR];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    $from = isset($_GET['from']) ? (string)$_GET['from'] : null;
    $to = isset($_GET['to']) ? (string)$_GET['to'] : null;

    if ((string)($_GET['view'] ?? '') === 'recommendations') {
        $items = [];
        // Per signal: sends and replies for messages whose match
        // company carries an observation of that signal.
        $stmt = $pdo->prepare(
            'SELECT s.id, s.name, s.importance, '
            . 'COUNT(DISTINCT msg.id) AS sends, '
            . 'COUNT(DISTINCT CASE WHEN e.type = \'reply\' THEN e.id END) AS replies, '
            . 'COUNT(DISTINCT CASE WHEN e.type = \'positive_reply\' THEN e.id END) AS positives '
            . 'FROM obe_intent_signals s '
            . 'JOIN obe_signal_observations o ON o.signal_id = s.id '
            . 'JOIN obe_prospect_matches m ON m.company_id = o.company_id '
            . 'AND m.client_id = s.client_id '
            . 'JOIN obe_messages msg ON msg.match_id = m.id '
            . 'AND msg.status = \'sent\' '
            . 'LEFT JOIN obe_message_events e ON e.message_id = msg.id '
            . 'WHERE s.client_id = ? GROUP BY s.id ORDER BY positives DESC');
        $stmt->execute([$clientId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sends = (int)$row['sends'];
            $positives = (int)$row['positives'];
            if ($sends === 0) {
                continue;
            }
            $rate = $positives / $sends;
            $confidence = $sends >= 20 ? 'pattern' : 'observation';
            $action = null;
            if ($rate >= 0.2 && (int)$row['importance'] < 5 && $sends >= 5) {
                $text = 'Signal "' . $row['name'] . '" produced '
                    . $positives . ' positive replies from ' . $sends
                    . ' sends. Consider raising its importance from '
                    . (int)$row['importance'] . ' to '
                    . ((int)$row['importance'] + 1) . '.';
                $action = ['type' => 'adjust_signal_importance',
                           'payload' => ['signal_id' => (int)$row['id'],
                                         'importance' => (int)$row['importance'] + 1]];
            } elseif ($positives === 0 && $sends >= 10
                      && (int)$row['importance'] > 1) {
                $text = 'Signal "' . $row['name'] . '" has ' . $sends
                    . ' sends and no positive replies yet. Consider '
                    . 'lowering its importance from '
                    . (int)$row['importance'] . ' to '
                    . ((int)$row['importance'] - 1) . '.';
                $action = ['type' => 'adjust_signal_importance',
                           'payload' => ['signal_id' => (int)$row['id'],
                                         'importance' => (int)$row['importance'] - 1]];
            } else {
                $text = 'Signal "' . $row['name'] . '": ' . $positives
                    . ' positive replies from ' . $sends . ' sends ('
                    . (int)$row['replies'] . ' replies total). Not '
                    . 'enough evidence to recommend a change.';
            }
            $items[] = [
                'kind' => 'signal_performance',
                'text' => $text,
                'sample_size' => $sends,
                'confidence' => $confidence,
                'action' => $action,
            ];
        }
        // Weight suggestion from the matches behind positive replies.
        $stmt = $pdo->prepare(
            'SELECT m.icp_id, m.breakdown_json FROM obe_prospect_matches m '
            . 'JOIN obe_messages msg ON msg.match_id = m.id '
            . 'JOIN obe_message_events e ON e.message_id = msg.id '
            . 'AND e.type = \'positive_reply\' WHERE m.client_id = ? '
            . 'GROUP BY m.id');
        $stmt->execute([$clientId]);
        $byIcp = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $icpId = (int)$row['icp_id'];
            if (!isset($byIcp[$icpId])) {
                $byIcp[$icpId] = ['n' => 0, 'signal_points' => 0.0];
            }
            $breakdown = obe_json_field($row['breakdown_json']);
            $byIcp[$icpId]['n']++;
            $byIcp[$icpId]['signal_points'] += isset($breakdown['signal_points'])
                ? (float)$breakdown['signal_points'] : 0.0;
        }
        foreach ($byIcp as $icpId => $agg) {
            if ($agg['n'] < 5) {
                continue;
            }
            $avgSignal = $agg['signal_points'] / $agg['n'];
            if ($avgSignal < 28) {
                continue;
            }
            $stmt = $pdo->prepare(
                'SELECT name, weights_json FROM obe_icp_profiles '
                . 'WHERE id = ?');
            $stmt->execute([$icpId]);
            $icp = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($icp === false) {
                continue;
            }
            $weights = obe_norm_weights(
                obe_json_field($icp['weights_json']));
            $proposed = obe_norm_weights([
                'fit' => max(10, $weights['fit'] - 5),
                'signal' => $weights['signal'] + 5,
                'evidence' => $weights['evidence'],
            ]);
            if ($proposed === $weights) {
                continue;
            }
            $items[] = [
                'kind' => 'icp_weights',
                'text' => 'Positive replies under ICP "' . $icp['name']
                    . '" came from matches with strong signal scores '
                    . '(average ' . round($avgSignal, 1) . ' of 40 '
                    . 'signal points). Consider shifting 5 weight '
                    . 'points from fit to signal for this ICP.',
                'sample_size' => $agg['n'],
                'confidence' => $agg['n'] >= 20 ? 'pattern' : 'observation',
                'action' => ['type' => 'adjust_weights',
                             'payload' => ['icp_id' => $icpId,
                                           'weights' => $proposed]],
            ];
        }
        json_out(['recommendations' => $items,
                  'note' => 'Items labeled observation have small '
                      . 'samples. Treat them as leads to investigate, '
                      . 'not established patterns. Nothing is applied '
                      . 'without your approval.']);
    }

    $p = [];
    $metrics = [];
    $metrics['companies_discovered'] = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(*) FROM obe_companies WHERE client_id = ?'
            . obe_range('created_at', $from, $to, $p),
        array_merge([$clientId], $p));
    $p = [];
    $metrics['contacts_discovered'] = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(*) FROM obe_contacts WHERE client_id = ?'
            . obe_range('created_at', $from, $to, $p),
        array_merge([$clientId], $p));
    $p = [];
    $metrics['contacts_qualified'] = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(DISTINCT contact_id) FROM obe_prospect_matches '
        . 'WHERE client_id = ? AND score >= 75 AND contact_id IS NOT NULL'
            . obe_range('updated_at', $from, $to, $p),
        array_merge([$clientId], $p));
    $p = [];
    $metrics['contacts_enriched'] = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(DISTINCT contact_id) FROM obe_prospect_matches '
        . 'WHERE client_id = ? AND status = \'enriched\' '
        . 'AND contact_id IS NOT NULL'
            . obe_range('updated_at', $from, $to, $p),
        array_merge([$clientId], $p));
    // Coverage is a snapshot ratio, so it ignores the date range.
    $totalContacts = (int)obe_scalar(
        $pdo, 'SELECT COUNT(*) FROM obe_contacts WHERE client_id = ?',
        [$clientId]);
    $withEmail = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(*) FROM obe_contacts WHERE client_id = ? '
        . 'AND email IS NOT NULL AND email <> \'\'',
        [$clientId]);
    $metrics['email_coverage_pct'] = $totalContacts > 0
        ? round($withEmail / $totalContacts * 100, 1) : 0.0;
    $p = [];
    $metrics['messages_drafted'] = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(*) FROM obe_messages WHERE client_id = ?'
            . obe_range('created_at', $from, $to, $p),
        array_merge([$clientId], $p));
    $p = [];
    $metrics['messages_approved'] = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(*) FROM obe_messages WHERE client_id = ? '
        . 'AND status IN (\'approved\', \'sent\')'
            . obe_range('created_at', $from, $to, $p),
        array_merge([$clientId], $p));
    $p = [];
    $metrics['emails_sent'] = (int)obe_scalar(
        $pdo,
        'SELECT COUNT(*) FROM obe_messages WHERE client_id = ? '
        . 'AND channel = \'email\' AND status = \'sent\''
            . obe_range('sent_at', $from, $to, $p),
        array_merge([$clientId], $p));
    foreach (['reply' => 'replies', 'positive_reply' => 'positive_replies',
              'meeting' => 'meetings', 'bounce' => 'bounces',
              'unsubscribe' => 'unsubscribes'] as $type => $label) {
        $p = [];
        $metrics[$label] = (int)obe_scalar(
            $pdo,
            'SELECT COUNT(*) FROM obe_message_events WHERE client_id = ? '
            . 'AND type = ?' . obe_range('created_at', $from, $to, $p),
            array_merge([$clientId, $type], $p));
    }

    $p = [];
    $stmt = $pdo->prepare(
        'SELECT provider, SUM(calls) AS calls, SUM(credits) AS credits, '
        . 'SUM(est_cost) AS est_cost FROM obe_provider_usage '
        . 'WHERE client_id = ?'
        . obe_range('created_at', $from, $to, $p)
        . ' GROUP BY provider ORDER BY provider');
    $stmt->execute(array_merge([$clientId], $p));
    $usage = [];
    $totalCost = 0.0;
    $totalCredits = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $usage[] = ['provider' => $row['provider'],
                    'calls' => (int)$row['calls'],
                    'credits' => (float)$row['credits'],
                    'est_cost' => (float)$row['est_cost']];
        $totalCost += (float)$row['est_cost'];
        $totalCredits += (float)$row['credits'];
    }
    $metrics['api_credits'] = $totalCredits;
    $metrics['est_cost'] = round($totalCost, 4);
    $metrics['cost_per_positive_reply'] = $metrics['positive_replies'] > 0
        ? round($totalCost / $metrics['positive_replies'], 4) : null;
    $metrics['cost_per_meeting'] = $metrics['meetings'] > 0
        ? round($totalCost / $metrics['meetings'], 4) : null;

    // Breakdowns. Signal rows join matches through observations on
    // the same company, so they attribute outcomes to every signal
    // observed at that company (a company with two signals credits
    // both). Documented here so nobody over-reads the numbers.
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name, '
        . '(SELECT COUNT(*) FROM obe_signal_observations o '
        . 'WHERE o.signal_id = s.id) AS observations, '
        . 'COUNT(DISTINCT m.id) AS matches_count, '
        . 'COUNT(DISTINCT CASE WHEN e.type = \'reply\' THEN e.id END) AS replies, '
        . 'COUNT(DISTINCT CASE WHEN e.type = \'positive_reply\' THEN e.id END) AS positive_replies '
        . 'FROM obe_intent_signals s '
        . 'LEFT JOIN obe_signal_observations o2 ON o2.signal_id = s.id '
        . 'LEFT JOIN obe_prospect_matches m ON m.company_id = o2.company_id '
        . 'AND m.client_id = s.client_id '
        . 'LEFT JOIN obe_messages msg ON msg.match_id = m.id '
        . 'LEFT JOIN obe_message_events e ON e.message_id = msg.id '
        . 'AND e.type IN (\'reply\', \'positive_reply\') '
        . 'WHERE s.client_id = ? GROUP BY s.id ORDER BY s.id');
    $stmt->execute([$clientId]);
    $bySignal = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT COALESCE(NULLIF(ct.title, \'\'), \'(no title)\') AS title, '
        . 'COUNT(DISTINCT ct.id) AS contacts, '
        . 'COUNT(DISTINCT CASE WHEN m.score >= 75 THEN ct.id END) AS qualified '
        . 'FROM obe_contacts ct '
        . 'LEFT JOIN obe_prospect_matches m ON m.contact_id = ct.id '
        . 'WHERE ct.client_id = ? GROUP BY title ORDER BY contacts DESC '
        . 'LIMIT 50');
    $stmt->execute([$clientId]);
    $byTitle = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT msg.channel, COUNT(*) AS drafted, '
        . 'SUM(CASE WHEN msg.status IN (\'approved\', \'sent\') THEN 1 ELSE 0 END) AS approved, '
        . 'SUM(CASE WHEN msg.status = \'sent\' THEN 1 ELSE 0 END) AS sent, '
        . 'COUNT(DISTINCT CASE WHEN e.type = \'reply\' THEN e.id END) AS replies, '
        . 'COUNT(DISTINCT CASE WHEN e.type = \'positive_reply\' THEN e.id END) AS positive_replies '
        . 'FROM obe_messages msg '
        . 'LEFT JOIN obe_message_events e ON e.message_id = msg.id '
        . 'AND e.type IN (\'reply\', \'positive_reply\') '
        . 'WHERE msg.client_id = ? GROUP BY msg.channel');
    $stmt->execute([$clientId]);
    $byChannel = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT i.id, i.name, COUNT(*) AS matches_count, '
        . 'ROUND(AVG(m.score), 1) AS avg_score, '
        . 'SUM(CASE WHEN m.score >= 75 THEN 1 ELSE 0 END) AS qualified '
        . 'FROM obe_prospect_matches m '
        . 'JOIN obe_icp_profiles i ON i.id = m.icp_id '
        . 'WHERE m.client_id = ? GROUP BY i.id ORDER BY i.id');
    $stmt->execute([$clientId]);
    $byIcp = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Pipeline loop stages for the dashboard: live totals plus a
    // 24h recency flag per stage (is anything moving) and the counts
    // that are waiting on the user (what is pending). Snapshot based,
    // ignores the date range on purpose.
    $dayAgo = date('Y-m-d H:i:s', time() - 86400);
    $profileStmt = $pdo->prepare(
        'SELECT status FROM obe_client_profiles WHERE client_id = ?');
    $profileStmt->execute([$clientId]);
    $profileRow = $profileStmt->fetch(PDO::FETCH_ASSOC);
    $stages = [
        'profile_status' => $profileRow !== false
            ? (string)$profileRow['status'] : 'missing',
        'icps' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_icp_profiles '
            . 'WHERE client_id = ?', [$clientId]),
        'signals_enabled' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_intent_signals '
            . 'WHERE client_id = ? AND enabled = 1', [$clientId]),
        'companies_new_24h' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_companies '
            . 'WHERE client_id = ? AND created_at >= ?',
            [$clientId, $dayAgo]),
        'observations' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_signal_observations '
            . 'WHERE client_id = ?', [$clientId]),
        'observations_24h' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_signal_observations '
            . 'WHERE client_id = ? AND created_at >= ?',
            [$clientId, $dayAgo]),
        'matches' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_prospect_matches '
            . 'WHERE client_id = ?', [$clientId]),
        'queued_enrich' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_prospect_matches '
            . "WHERE client_id = ? AND status = 'queued_enrich'",
            [$clientId]),
        'matches_24h' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_prospect_matches '
            . 'WHERE client_id = ? AND updated_at >= ?',
            [$clientId, $dayAgo]),
        'messages_pending' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_messages '
            . "WHERE client_id = ? AND status = 'pending'",
            [$clientId]),
        'messages_24h' => (int)obe_scalar(
            $pdo, 'SELECT COUNT(*) FROM obe_messages '
            . 'WHERE client_id = ? AND updated_at >= ?',
            [$clientId, $dayAgo]),
        'last_run_at' => obe_scalar(
            $pdo, 'SELECT MAX(created_at) FROM obe_job_runs '
            . 'WHERE client_id = ?', [$clientId]),
    ];

    json_out([
        'metrics' => $metrics,
        'stages' => $stages,
        'usage' => $usage,
        'breakdowns' => [
            'by_signal' => $bySignal,
            'by_title' => $byTitle,
            'by_channel' => $byChannel,
            'by_icp' => $byIcp,
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');

if ($action === 'apply_recommendation') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    $rec = isset($data['action']) && is_array($data['action'])
        ? $data['action'] : [];
    $type = (string)($rec['type'] ?? '');
    $payload = isset($rec['payload']) && is_array($rec['payload'])
        ? $rec['payload'] : [];
    if ($type === 'adjust_signal_importance') {
        $signalId = isset($payload['signal_id'])
            ? (int)$payload['signal_id'] : 0;
        $importance = isset($payload['importance'])
            ? max(1, min(5, (int)$payload['importance'])) : 0;
        if ($signalId <= 0 || $importance === 0) {
            json_out(['error' => 'payload needs signal_id and '
                      . 'importance'], 400);
        }
        $stmt = $pdo->prepare(
            'UPDATE obe_intent_signals SET importance = ? '
            . 'WHERE id = ? AND client_id = ?');
        $stmt->execute([$importance, $signalId, $clientId]);
        if ($stmt->rowCount() === 0) {
            json_out(['error' => 'unknown signal_id'], 400);
        }
    } elseif ($type === 'adjust_weights') {
        $icpId = isset($payload['icp_id']) ? (int)$payload['icp_id'] : 0;
        $weights = obe_norm_weights(
            isset($payload['weights']) && is_array($payload['weights'])
                ? $payload['weights'] : []);
        $stmt = $pdo->prepare(
            'UPDATE obe_icp_profiles SET weights_json = ? '
            . 'WHERE id = ? AND client_id = ?');
        $stmt->execute([json_encode($weights), $icpId, $clientId]);
        if ($stmt->rowCount() === 0) {
            json_out(['error' => 'unknown icp_id'], 400);
        }
    } else {
        json_out(['error' => 'unknown recommendation type'], 400);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO obe_experiments (client_id, name, change_json, '
        . 'result_note) VALUES (?, ?, ?, ?)');
    $stmt->execute([$clientId, 'Recommendation applied',
                    json_encode($rec),
                    'Applied from the reporting recommendations panel. '
                    . 'Result not yet measured.']);
    obe_log_run($pdo, $clientId, 'recommendation',
                'Applied recommendation: ' . $type);
    json_out(['applied' => $type]);
}

json_out(['error' => 'unknown action'], 400);
