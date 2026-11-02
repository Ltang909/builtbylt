<?php
/**
 * Key vault endpoint.
 *   GET    api/keys.php -> [ {provider, configured, source, masked,
 *                             updated_at} ] for every known provider
 *   POST   api/keys.php {provider, key}              -> set/replace a key
 *   POST   api/keys.php {provider, action: "delete"} -> remove a key
 *   DELETE api/keys.php?provider=typesafe           -> remove a key
 *
 * SECURITY: keys are write-only. Every response carries at most the
 * masked tail ("••••" + last 4 chars). The key value itself is never
 * echoed, never written to the ledger or any log — it only ever leaves
 * the DB inside vault_key() on its way to the provider's API.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $out = [];
    foreach (PANEL_PROVIDERS as $provider) {
        $stmt = $pdo->prepare(
            'SELECT key_value, updated_at FROM api_keys WHERE provider = ?');
        $stmt->execute([$provider]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $key = $row !== false ? (string)$row['key_value'] : '';
        $env = PROVIDER_ENV[$provider] ?? null;
        $envVal = $env ? getenv($env) : false;
        $envSet = $envVal !== false && $envVal !== '';
        $out[] = [
            'provider' => $provider,
            'configured' => $key !== '' || $envSet,
            'source' => $key !== '' ? 'vault' : ($envSet ? 'env' : null),
            'masked' => $key !== '' ? '••••' . substr($key, -4) : null,
            'updated_at' => $row !== false ? $row['updated_at'] : null,
        ];
    }
    json_out($out);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $provider = (string)($_GET['provider'] ?? '');
    if (!in_array($provider, PANEL_PROVIDERS, true)) {
        json_out(['error' => 'unknown provider'], 400);
    }
    $pdo->prepare('DELETE FROM api_keys WHERE provider = ?')
        ->execute([$provider]);
    json_out(['provider' => $provider, 'configured' => false]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $provider = (string)($data['provider'] ?? '');
    if (!in_array($provider, PANEL_PROVIDERS, true)) {
        json_out(['error' => 'unknown provider'], 400);
    }
    if (($data['action'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM api_keys WHERE provider = ?')
            ->execute([$provider]);
        json_out(['provider' => $provider, 'configured' => false]);
    }
    $key = trim((string)($data['key'] ?? ''));
    if ($key === '') {
        json_out(['error' => 'key is required'], 400);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO api_keys (provider, key_value, updated_at) '
        . 'VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE '
        . 'key_value = VALUES(key_value), updated_at = NOW()');
    $stmt->execute([$provider, $key]);
    // Respond with the masked tail only — never the key itself.
    json_out(['provider' => $provider, 'configured' => true,
              'masked' => '••••' . substr($key, -4)]);
}

json_out(['error' => 'method not allowed'], 405);
