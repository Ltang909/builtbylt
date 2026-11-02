<?php
/**
 * Experiments endpoint (workspace-scoped).
 *   GET  api/experiments.php?workspace_id=N -> [ experiment, ... ]
 *   POST api/experiments.php {workspace_id, name, hypothesis, start_date,
 *        end_date, pass_metric, fail_metric, kill_metric, pass_value,
 *        fail_value, kill_cac, spend_floor, status} -> created experiment
 *   POST api/experiments.php {workspace_id, id, hypothesis, ...}
 *        -> updates the given fields of an existing experiment (the
 *        Generate panel saves the hypothesis this way). Only fields
 *        present in the body are changed.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM experiments WHERE workspace_id = ? ORDER BY id');
    $stmt->execute([$wsid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['workspace_id'] = (int)$r['workspace_id'];
    }
    json_out($rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $num = fn($k) => isset($data[$k]) && $data[$k] !== ''
        ? (float)$data[$k] : null;

    // Update path: an id means "edit this experiment", not "create".
    if (!empty($data['id'])) {
        $expId = (int)$data['id'];
        $textFields = ['name', 'hypothesis', 'start_date', 'end_date',
                       'pass_metric', 'fail_metric', 'kill_metric',
                       'status'];
        $numFields = ['pass_value', 'fail_value', 'kill_cac',
                      'spend_floor'];
        $sets = [];
        $vals = [];
        foreach ($textFields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $vals[] = $data[$f];
            }
        }
        foreach ($numFields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = "$f = ?";
                $vals[] = $num($f);
            }
        }
        if (!$sets) {
            json_out(['error' => 'nothing to update'], 400);
        }
        $vals[] = $expId;
        $vals[] = $wsid;
        $stmt = $pdo->prepare(
            'UPDATE experiments SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND workspace_id = ?');
        $stmt->execute($vals);
        $stmt = $pdo->prepare('SELECT * FROM experiments WHERE id = ?');
        $stmt->execute([$expId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            json_out(['error' => 'experiment not found'], 404);
        }
        $row['id'] = (int)$row['id'];
        $row['workspace_id'] = (int)$row['workspace_id'];
        json_out($row);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO experiments (workspace_id, name, hypothesis, '
        . 'start_date, end_date, pass_metric, fail_metric, kill_metric, '
        . 'pass_value, fail_value, kill_cac, spend_floor, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $wsid, $data['name'] ?? null, $data['hypothesis'] ?? null,
        $data['start_date'] ?? null, $data['end_date'] ?? null,
        $data['pass_metric'] ?? null, $data['fail_metric'] ?? null,
        $data['kill_metric'] ?? null, $num('pass_value'), $num('fail_value'),
        $num('kill_cac'), $num('spend_floor'),
        $data['status'] ?? 'active',
    ]);
    $stmt = $pdo->prepare('SELECT * FROM experiments WHERE id = ?');
    $stmt->execute([(int)$pdo->lastInsertId()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row['id'] = (int)$row['id'];
    $row['workspace_id'] = (int)$row['workspace_id'];
    json_out($row, 201);
}

json_out(['error' => 'method not allowed'], 405);
