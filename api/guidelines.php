<?php
/**
 * Guideline doc endpoint (one per workspace; the brand's source of truth,
 * kept raw).
 *   GET  api/guidelines.php?workspace_id=N -> {guideline: {...}|null}
 *   POST api/guidelines.php {workspace_id, action: "paste", text}
 *   POST api/guidelines.php {workspace_id, action: "file", filename,
 *                            content_base64}
 *   POST api/guidelines.php {workspace_id, action: "notion", page_url}
 *   POST api/guidelines.php {workspace_id, action: "extract"}
 *
 * Notion fetch is real when a "notion" key is in the vault: it calls
 * api.notion.com and converts blocks to markdown (lib/notion.php). A
 * failed fetch stores NOTHING and returns the error as-is. The extract
 * action is an honest TODO, exactly as in the Python build: without an
 * LLM key it says so; with one it still reports the pass as unimplemented
 * and changes nothing.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/notion.php';
require_api_auth($pdo);

function store_guideline(PDO $pdo, int $wsid, string $sourceType,
                         ?string $sourceRef, string $raw): array {
    $stmt = $pdo->prepare(
        'INSERT INTO guidelines (workspace_id, source_type, source_ref, '
        . 'raw_markdown, extracted_at) VALUES (?, ?, ?, ?, NOW()) '
        . 'ON DUPLICATE KEY UPDATE source_type = VALUES(source_type), '
        . 'source_ref = VALUES(source_ref), '
        . 'raw_markdown = VALUES(raw_markdown), '
        . 'extracted_at = VALUES(extracted_at)');
    $stmt->execute([$wsid, $sourceType, $sourceRef, $raw]);
    $stmt = $pdo->prepare('SELECT * FROM guidelines WHERE workspace_id = ?');
    $stmt->execute([$wsid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row['id'] = (int)$row['id'];
    $row['workspace_id'] = (int)$row['workspace_id'];
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $stmt = $pdo->prepare('SELECT * FROM guidelines WHERE workspace_id = ?');
    $stmt->execute([$wsid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $row['id'] = (int)$row['id'];
        $row['workspace_id'] = (int)$row['workspace_id'];
    }
    json_out(['guideline' => $row !== false ? $row : null]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $action = (string)($data['action'] ?? '');

    if ($action === 'paste') {
        $text = (string)($data['text'] ?? '');
        if (trim($text) === '') {
            json_out(['ok' => false,
                      'error' => 'Paste some guideline text first.'], 400);
        }
        $out = store_guideline($pdo, $wsid, 'paste',
            $data['source_ref'] ?? 'pasted in panel', $text);
        json_out(['ok' => true, 'guideline' => $out]);
    }

    if ($action === 'file') {
        $raw = base64_decode((string)($data['content_base64'] ?? ''), true);
        if ($raw === false) {
            json_out(['ok' => false,
                      'error' => 'Could not decode the uploaded file.'], 400);
        }
        $out = store_guideline($pdo, $wsid, 'file',
            $data['filename'] ?? 'uploaded file', (string)$raw);
        json_out(['ok' => true, 'guideline' => $out]);
    }

    if ($action === 'notion') {
        $url = trim((string)($data['page_url'] ?? ''));
        $token = vault_key($pdo, 'notion');
        if ($token === null) {
            $msg = 'No Notion integration token configured. Add one in '
                . 'Settings → API keys (provider: notion), create an '
                . 'internal integration at notion.so/my-integrations, and '
                . 'share the guideline page with it.';
            log_ledger($pdo, $wsid, 'guideline_fetch', 'notion', null,
                       'error', $msg);
            json_out(['ok' => false, 'error' => $msg], 400);
        }
        try {
            $md = notion_fetch_page_markdown($url, $token);
        } catch (NotionError $exc) {
            log_ledger($pdo, $wsid, 'guideline_fetch', 'notion', null,
                       'error', substr($exc->getMessage(), 0, 300));
            json_out(['ok' => false, 'error' => $exc->getMessage()], 502);
        }
        $out = store_guideline($pdo, $wsid, 'notion', $url, $md);
        log_ledger($pdo, $wsid, 'guideline_fetch', 'notion', null, 'ok',
            sprintf('Fetched %d chars of markdown from Notion.', strlen($md)));
        json_out(['ok' => true, 'guideline' => $out]);
    }

    if ($action === 'extract') {
        $stmt = $pdo->prepare('SELECT * FROM routing WHERE task = ?');
        $stmt->execute(['extraction']);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);
        $provider = $route !== false ? (string)$route['provider'] : 'anthropic';
        $model = $route !== false ? $route['model'] : null;
        if (vault_key($pdo, $provider) === null) {
            $msg = "Extraction needs an LLM key: add the $provider key in "
                . 'Settings → API keys first. Tokens stay hand-editable '
                . 'meanwhile; nothing was changed.';
            log_ledger($pdo, $wsid, 'extraction', $provider, $model,
                       'skipped', $msg);
            json_out(['ok' => false, 'status' => 'needs_key',
                      'message' => $msg]);
        }
        // Honest TODO: the extraction pass itself is not implemented in
        // this build. No tokens are changed and nothing is faked.
        $msg = "TODO: the LLM token-extraction pass is not implemented in "
            . "this build (a $provider key IS configured). No tokens were "
            . 'changed; edit them manually in Settings.';
        log_ledger($pdo, $wsid, 'extraction', $provider, $model, 'todo', $msg);
        json_out(['ok' => false, 'status' => 'todo', 'message' => $msg]);
    }

    json_out(['error' => 'unknown action'], 400);
}

json_out(['error' => 'method not allowed'], 405);
