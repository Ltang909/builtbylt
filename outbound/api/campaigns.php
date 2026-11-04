<?php
/**
 * Campaigns + suppression endpoint.
 *   GET  campaigns.php?client_id=N                  -> campaigns w/ counts
 *   GET  campaigns.php?client_id=N&view=suppression -> suppression list
 *        (this client's entries plus the global ones)
 *   POST campaigns.php {client_id, name, icp_id, channel, strategy}
 *   POST campaigns.php?action=set_state {id, state}
 *        state is one of Draft, Active, Paused, Completed
 *   POST campaigns.php?action=suppress {client_id?, email, reason}
 *        No client_id means the global suppression list.
 *   POST campaigns.php?action=unsuppress {id}
 */
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    if ((string)($_GET['view'] ?? '') === 'suppression') {
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_suppression WHERE client_id = ? '
            . 'OR client_id IS NULL ORDER BY created_at DESC LIMIT 500');
        $stmt->execute([$clientId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int)$row['id'];
            $row['client_id'] = $row['client_id'] !== null
                ? (int)$row['client_id'] : null;
            $row['scope'] = $row['client_id'] === null ? 'global' : 'client';
            $out[] = $row;
        }
        json_out($out);
    }
    $stmt = $pdo->prepare(
        'SELECT c.*, i.name AS icp_name, '
        . '(SELECT COUNT(*) FROM obe_messages m WHERE m.campaign_id = c.id) AS messages, '
        . '(SELECT COUNT(*) FROM obe_messages m WHERE m.campaign_id = c.id AND m.status = \'sent\') AS sent, '
        . '(SELECT COUNT(*) FROM obe_messages m WHERE m.campaign_id = c.id AND m.status IN (\'draft\', \'pending\')) AS pending '
        . 'FROM obe_campaigns c '
        . 'LEFT JOIN obe_icp_profiles i ON i.id = c.icp_id '
        . 'WHERE c.client_id = ? ORDER BY c.id DESC');
    $stmt->execute([$clientId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $row['icp_id'] = $row['icp_id'] !== null ? (int)$row['icp_id'] : null;
        $row['messages'] = (int)$row['messages'];
        $row['sent'] = (int)$row['sent'];
        $row['pending'] = (int)$row['pending'];
        $out[] = $row;
    }
    json_out($out);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');

if ($action === 'suppress') {
    // Suppression is the one action where client_id is optional:
    // absent (or zero) means the global list.
    $targetClient = null;
    if (isset($data['client_id']) && is_numeric($data['client_id'])
        && (int)$data['client_id'] > 0) {
        $targetClient = (int)$data['client_id'];
        $stmt = $pdo->prepare('SELECT id FROM obe_clients WHERE id = ?');
        $stmt->execute([$targetClient]);
        if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
            json_out(['error' => 'unknown client_id'], 400);
        }
    }
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || strpos($email, '@') === false) {
        json_out(['error' => 'a valid email is required'], 400);
    }
    $reason = (string)($data['reason'] ?? 'manual');
    if ($targetClient === null) {
        $stmt = $pdo->prepare(
            'SELECT id FROM obe_suppression WHERE client_id IS NULL '
            . 'AND email = ?');
        $stmt->execute([$email]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id FROM obe_suppression WHERE client_id = ? '
            . 'AND email = ?');
        $stmt->execute([$targetClient, $email]);
    }
    if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
        $ins = $pdo->prepare(
            'INSERT INTO obe_suppression (client_id, email, reason) '
            . 'VALUES (?, ?, ?)');
        $ins->execute([$targetClient, $email, $reason]);
    }
    json_out(['suppressed' => $email,
              'scope' => $targetClient === null ? 'global' : 'client']);
}

if ($action === 'unsuppress') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $stmt = $pdo->prepare('DELETE FROM obe_suppression WHERE id = ?');
    $stmt->execute([$id]);
    json_out(['unsuppressed' => $id]);
}

// Everything below is client scoped.
$client = obe_client($pdo);
$clientId = (int)$client['id'];

if ($action === 'set_state') {
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $state = (string)($data['state'] ?? '');
    if (!in_array($state, ['Draft', 'Active', 'Paused', 'Completed'],
                  true)) {
        json_out(['error' => 'state must be Draft, Active, Paused, '
                  . 'or Completed'], 400);
    }
    $stmt = $pdo->prepare(
        'UPDATE obe_campaigns SET state = ? WHERE id = ? '
        . 'AND client_id = ?');
    $stmt->execute([$state, $id, $clientId]);
    json_out(['id' => $id, 'state' => $state]);
}

if ($action === '') {
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        json_out(['error' => 'name is required'], 400);
    }
    $channel = (string)($data['channel'] ?? 'email');
    if (!in_array($channel, ['email', 'linkedin'], true)) {
        $channel = 'email';
    }
    $stmt = $pdo->prepare(
        'INSERT INTO obe_campaigns (client_id, icp_id, name, channel, '
        . 'strategy, state) VALUES (?, ?, ?, ?, ?, \'Draft\')');
    $stmt->execute([
        $clientId,
        isset($data['icp_id']) && is_numeric($data['icp_id'])
            ? (int)$data['icp_id'] : null,
        $name, $channel, (string)($data['strategy'] ?? ''),
    ]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM obe_campaigns WHERE id = ?');
    $stmt->execute([$id]);
    json_out($stmt->fetch(PDO::FETCH_ASSOC), 201);
}

json_out(['error' => 'unknown action'], 400);
