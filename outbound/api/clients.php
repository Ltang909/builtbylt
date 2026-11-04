<?php
/**
 * Client workspaces endpoint.
 *   GET  clients.php                       -> list with per-client counts
 *   POST clients.php {name, website}       -> create
 *   POST clients.php?action=update    {id, name, website, status}
 *   POST clients.php?action=duplicate {id} -> deep copy of profile,
 *        ICPs, signals, and settings (not prospects or messages)
 *   POST clients.php?action=delete    {id} -> delete client + all rows
 */
require __DIR__ . '/bootstrap.php';

function obe_client_row(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM obe_clients WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $pdo->query('SELECT * FROM obe_clients ORDER BY id')
                ->fetchAll(PDO::FETCH_ASSOC);
    $counts = $pdo->query(
        'SELECT c.id, '
        . '(SELECT COUNT(*) FROM obe_icp_profiles i WHERE i.client_id = c.id) AS icps, '
        . '(SELECT COUNT(*) FROM obe_intent_signals s WHERE s.client_id = c.id) AS signals, '
        . '(SELECT COUNT(*) FROM obe_prospect_matches m WHERE m.client_id = c.id) AS prospects, '
        . '(SELECT COUNT(*) FROM obe_companies co WHERE co.client_id = c.id) AS companies, '
        . '(SELECT COUNT(*) FROM obe_contacts ct WHERE ct.client_id = c.id) AS contacts, '
        . '(SELECT COUNT(*) FROM obe_messages ms WHERE ms.client_id = c.id '
        . 'AND ms.status IN (\'draft\', \'pending\')) AS queued_messages '
        . 'FROM obe_clients c ORDER BY c.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $byId = [];
    foreach ($counts as $c) {
        $byId[(int)$c['id']] = $c;
    }
    foreach ($rows as &$r) {
        $id = (int)$r['id'];
        $r['id'] = $id;
        $c = isset($byId[$id]) ? $byId[$id] : [];
        $r['counts'] = [
            'icps' => isset($c['icps']) ? (int)$c['icps'] : 0,
            'signals' => isset($c['signals']) ? (int)$c['signals'] : 0,
            'prospects' => isset($c['prospects']) ? (int)$c['prospects'] : 0,
            'companies' => isset($c['companies']) ? (int)$c['companies'] : 0,
            'contacts' => isset($c['contacts']) ? (int)$c['contacts'] : 0,
            'queued_messages' => isset($c['queued_messages'])
                ? (int)$c['queued_messages'] : 0,
        ];
    }
    unset($r);
    json_out($rows);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');

if ($action === '') {
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        json_out(['error' => 'name is required'], 400);
    }
    $website = trim((string)($data['website'] ?? ''));
    $stmt = $pdo->prepare(
        'INSERT INTO obe_clients (name, website, status) VALUES (?, ?, ?)');
    $stmt->execute([$name, $website !== '' ? $website : null, 'active']);
    json_out(obe_client_row($pdo, (int)$pdo->lastInsertId()), 201);
}

if ($action === 'update') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $existing = $id > 0 ? obe_client_row($pdo, $id) : null;
    if ($existing === null) {
        json_out(['error' => 'unknown client id'], 400);
    }
    $name = isset($data['name'])
        ? trim((string)$data['name']) : (string)$existing['name'];
    if ($name === '') {
        json_out(['error' => 'name is required'], 400);
    }
    $website = isset($data['website'])
        ? trim((string)$data['website']) : (string)($existing['website'] ?? '');
    $status = isset($data['status'])
        ? (string)$data['status'] : (string)$existing['status'];
    if (!in_array($status, ['active', 'archived'], true)) {
        $status = 'active';
    }
    $stmt = $pdo->prepare(
        'UPDATE obe_clients SET name = ?, website = ?, status = ? '
        . 'WHERE id = ?');
    $stmt->execute([$name, $website !== '' ? $website : null, $status, $id]);
    json_out(obe_client_row($pdo, $id));
}

if ($action === 'duplicate') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $src = $id > 0 ? obe_client_row($pdo, $id) : null;
    if ($src === null) {
        json_out(['error' => 'unknown client id'], 400);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO obe_clients (name, website, status) VALUES (?, ?, ?)');
        $stmt->execute([(string)$src['name'] . ' copy',
                        $src['website'], 'active']);
        $newId = (int)$pdo->lastInsertId();

        // Profile (one row).
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_client_profiles WHERE client_id = ?');
        $stmt->execute([$id]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($profile !== false) {
            $ins = $pdo->prepare(
                'INSERT INTO obe_client_profiles (client_id, product, '
                . 'audiences, value_props, differentiators, use_cases, '
                . 'value_prop_summary, writing_guidelines, source_urls, '
                . 'evidence, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ins->execute([$newId, $profile['product'], $profile['audiences'],
                           $profile['value_props'],
                           $profile['differentiators'], $profile['use_cases'],
                           $profile['value_prop_summary'],
                           $profile['writing_guidelines'],
                           $profile['source_urls'], $profile['evidence'],
                           $profile['status']]);
        }

        // ICPs first, so signal icp_id references can be remapped.
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_icp_profiles WHERE client_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $icpMap = [];
        $insIcp = $pdo->prepare(
            'INSERT INTO obe_icp_profiles (client_id, name, nl_text, '
            . 'filters_json, weights_json) VALUES (?, ?, ?, ?, ?)');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $icp) {
            $insIcp->execute([$newId, $icp['name'], $icp['nl_text'],
                              $icp['filters_json'], $icp['weights_json']]);
            $icpMap[(int)$icp['id']] = (int)$pdo->lastInsertId();
        }

        // Signals.
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_intent_signals WHERE client_id = ? ORDER BY id');
        $stmt->execute([$id]);
        $insSig = $pdo->prepare(
            'INSERT INTO obe_intent_signals (client_id, icp_id, name, '
            . 'nl_text, source_types_json, keywords_json, freshness_days, '
            . 'importance, detectability, enabled) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sig) {
            $mappedIcp = null;
            if ($sig['icp_id'] !== null
                && isset($icpMap[(int)$sig['icp_id']])) {
                $mappedIcp = $icpMap[(int)$sig['icp_id']];
            }
            $insSig->execute([$newId, $mappedIcp, $sig['name'],
                              $sig['nl_text'], $sig['source_types_json'],
                              $sig['keywords_json'],
                              (int)$sig['freshness_days'],
                              (int)$sig['importance'], $sig['detectability'],
                              (int)$sig['enabled']]);
        }

        // Per-client settings (never global rows).
        $stmt = $pdo->prepare(
            'SELECT k, v FROM obe_settings WHERE client_id = ?');
        $stmt->execute([$id]);
        $insSet = $pdo->prepare(
            'INSERT INTO obe_settings (client_id, k, v) VALUES (?, ?, ?)');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $insSet->execute([$newId, $s['k'], $s['v']]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_out(['error' => 'duplicate failed: ' . $e->getMessage()], 500);
    }
    json_out(obe_client_row($pdo, $newId), 201);
}

if ($action === 'delete') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0 || obe_client_row($pdo, $id) === null) {
        json_out(['error' => 'unknown client id'], 400);
    }
    $tables = ['obe_message_events', 'obe_messages', 'obe_prospect_matches',
               'obe_signal_observations', 'obe_contacts', 'obe_companies',
               'obe_campaigns', 'obe_intent_signals', 'obe_icp_profiles',
               'obe_client_profiles', 'obe_provider_usage', 'obe_job_runs',
               'obe_experiments', 'obe_suppression', 'obe_settings'];
    $pdo->beginTransaction();
    try {
        foreach ($tables as $table) {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE client_id = ?");
            $stmt->execute([$id]);
        }
        $stmt = $pdo->prepare('DELETE FROM obe_clients WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        json_out(['error' => 'delete failed: ' . $e->getMessage()], 500);
    }
    json_out(['deleted' => $id]);
}

json_out(['error' => 'unknown action'], 400);
