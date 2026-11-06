<?php
/**
 * Creatives endpoint (workspace-scoped).
 *   GET  api/creatives.php?workspace_id=N -> [ creative + verdicts, ... ]
 *        (image_path is joined by image_url -> api/image.php, which serves
 *        the file behind the session; uploads are never directly public)
 *   POST api/creatives.php {workspace_id, experiment_id, kind, game_name,
 *        headline, primary_text, description, cta, offer_text,
 *        template_id, format} -> created creative
 *   POST api/creatives.php {action: "update_copy", creative_id,
 *        headline?, primary_text?, description?, cta?, offer_text?}
 *        -> hand-edit the produced copy (Update 11.4). Editing wipes
 *        the creative's verdicts and returns it to 'generated':
 *        edited copy must re-verify before it can be approved.
 *        Creatives already launched/live refuse edits.
 *   POST api/creatives.php {action: "image", creative_id, filename,
 *        image_base64} -> creative with the image stored under
 *        uploads/ws<workspace_id>/ . Base64 JSON only (kept simple, like
 *        the later v2 intake). Mime is sniffed from the bytes and must be
 *        png / jpg / jpeg / webp.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM creatives WHERE workspace_id = ? ORDER BY id');
    $stmt->execute([$wsid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id'] = (int)$row['id'];
        $row['workspace_id'] = (int)$row['workspace_id'];
        $out[] = creative_with_verdicts($pdo, $row);
    }
    json_out($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $action = (string)($data['action'] ?? 'create');

    if ($action === 'image') {
        $cid = (int)($data['creative_id'] ?? 0);
        $row = fetch_creative($pdo, $cid);
        if ($row === null) {
            json_out(['error' => 'creative not found'], 404);
        }
        $wsid = (int)$row['workspace_id'];
        $bytes = base64_decode((string)($data['image_base64'] ?? ''), true);
        if ($bytes === false || $bytes === '') {
            json_out(['ok' => false,
                      'error' => 'No image data received (JSON image_base64).'],
                     400);
        }
        // Validate by sniffing the actual bytes, not the claimed name.
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($bytes);
        $extByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg',
                      'image/webp' => 'webp'];
        if (!isset($extByMime[$mime])) {
            json_out(['ok' => false,
                      'error' => "Unsupported image type: $mime "
                               . '(allowed: png, jpg, jpeg, webp).'], 400);
        }
        $ext = $extByMime[$mime];
        $base = pathinfo((string)($data['filename'] ?? 'upload'), PATHINFO_FILENAME);
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        if ($clean === '' || $clean === null) {
            $clean = 'upload';
        }
        $storedName = $cid . '_' . $clean . '.' . $ext;
        $wsDir = PANEL_ROOT . '/uploads/ws' . $wsid;
        if (!is_dir($wsDir)) {
            mkdir($wsDir, 0775, true);
        }
        file_put_contents($wsDir . '/' . $storedName, $bytes);
        $rel = 'uploads/ws' . $wsid . '/' . $storedName;
        $stmt = $pdo->prepare(
            'UPDATE creatives SET image_path = ? WHERE id = ?');
        $stmt->execute([$rel, $cid]);
        $fresh = fetch_creative($pdo, $cid);
        $fresh['id'] = (int)$fresh['id'];
        $fresh['workspace_id'] = (int)$fresh['workspace_id'];
        json_out(creative_with_verdicts($pdo, $fresh));
    }

    if ($action === 'update_copy') {
        $cid = (int)($data['creative_id'] ?? 0);
        $row = fetch_creative($pdo, $cid);
        if ($row === null) {
            json_out(['error' => 'creative not found'], 404);
        }
        if (in_array((string)$row['status'],
                     ['launched', 'live'], true)) {
            json_out(['error' => 'This creative is already in '
                . 'market; duplicate it instead of editing it.'], 400);
        }
        $fields = ['headline', 'primary_text', 'description',
                   'cta', 'offer_text'];
        $sets = [];
        $vals = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $sets[] = $f . ' = ?';
                $vals[] = $data[$f] === null
                    ? null : (string)$data[$f];
            }
        }
        if ($sets) {
            $vals[] = $cid;
            $pdo->prepare('UPDATE creatives SET '
                . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($vals);
        }
        if ($sets) {
            // Keep the attached copy variant set in step (Update
            // 11.7): the edited face copy replaces its own entry in
            // the set, matched on the pre-edit headline + primary
            // text. If it is not found, the edit becomes the whole
            // set — the operator rewrote the copy either way.
            creative_copy_variants_install($pdo);
            $set = creative_copy_variants($row);
            $merged = $row;
            foreach ($fields as $f) {
                if (array_key_exists($f, $data)) {
                    $merged[$f] = $data[$f] === null
                        ? '' : (string)$data[$f];
                }
            }
            $entry = [
                'headline' => (string)($merged['headline'] ?? ''),
                'primary_text' =>
                    (string)($merged['primary_text'] ?? ''),
                'description' =>
                    (string)($merged['description'] ?? ''),
                'cta' => (string)($merged['cta'] ?? ''),
                'offer_text' => (string)($merged['offer_text'] ?? ''),
            ];
            $hit = null;
            foreach ($set as $i => $sv) {
                if ($sv['headline']
                        === (string)($row['headline'] ?? '')
                    && $sv['primary_text']
                        === (string)($row['primary_text'] ?? '')) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit !== null) {
                $set[$hit] = $entry;
            } else {
                $set = [$entry];
            }
            $pdo->prepare('UPDATE creatives SET copy_variants = ? '
                . 'WHERE id = ?')
                ->execute([json_encode($set), $cid]);
        }
        // The verdicts judged the old copy; they no longer apply.
        $pdo->prepare('DELETE FROM verdicts WHERE creative_id = ?')
            ->execute([$cid]);
        $pdo->prepare("UPDATE creatives SET status = 'generated' "
            . 'WHERE id = ?')->execute([$cid]);
        $fresh = fetch_creative($pdo, $cid);
        $fresh['id'] = (int)$fresh['id'];
        $fresh['workspace_id'] = (int)$fresh['workspace_id'];
        json_out(creative_with_verdicts($pdo, $fresh));
    }

    // default: create
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO creatives (workspace_id, experiment_id, kind, '
        . 'game_name, headline, primary_text, description, cta, offer_text, '
        . 'template_id, format, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'generated\')');
    $stmt->execute([
        $wsid,
        isset($data['experiment_id']) && $data['experiment_id'] !== ''
            ? (int)$data['experiment_id'] : null,
        $data['kind'] ?? 'static', $data['game_name'] ?? null,
        $data['headline'] ?? null, $data['primary_text'] ?? null,
        $data['description'] ?? null, $data['cta'] ?? null,
        $data['offer_text'] ?? null, $data['template_id'] ?? null,
        $data['format'] ?? null,
    ]);
    $row = fetch_creative($pdo, (int)$pdo->lastInsertId());
    $row['id'] = (int)$row['id'];
    $row['workspace_id'] = (int)$row['workspace_id'];
    json_out(creative_with_verdicts($pdo, $row), 201);
}

json_out(['error' => 'method not allowed'], 405);
