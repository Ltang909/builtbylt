<?php
/**
 * Settings and integrations endpoint.
 *   GET  settings.php?client_id=N
 *        Integration status only: booleans and masked tails. Key
 *        values are NEVER returned. LLM providers are read from the
 *        panel vault; Apollo and Hunter keys are stored in the same
 *        vault table by the save_key action below.
 *   POST settings.php?action=save_key {provider: apollo|hunter, key}
 *   POST settings.php?action=save_settings {client_id, ...}
 *        Per-client operating settings. Gmail fields (client id,
 *        client secret, refresh token, account email) are stored as
 *        obe_settings rows and never echoed back; GET reports only
 *        whether Gmail counts as connected.
 *   POST settings.php?action=save_cron_secret {secret}
 *        Stored hashed (password_hash) as a global setting; cron.php
 *        verifies with password_verify.
 */
require __DIR__ . '/bootstrap.php';

function obe_key_tail(PDO $pdo, string $provider): ?string {
    $stmt = $pdo->prepare(
        'SELECT key_value FROM api_keys WHERE provider = ?');
    $stmt->execute([$provider]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false || (string)$row['key_value'] === '') {
        return null;
    }
    return '••••' . substr((string)$row['key_value'], -4);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    $gmailTokenRaw = obe_setting($pdo, $clientId, 'gmail_token', null);
    $gmailToken = $gmailTokenRaw !== null
        ? json_decode((string)$gmailTokenRaw, true) : null;
    $gmailConnected = is_array($gmailToken)
        && !empty($gmailToken['refresh_token']);
    $cronHash = obe_setting($pdo, null, 'cron_secret', null);
    json_out([
        'integrations' => [
            'apollo' => [
                'present' => obe_key_tail($pdo, 'apollo') !== null,
                'masked' => obe_key_tail($pdo, 'apollo'),
            ],
            'hunter' => [
                'present' => obe_key_tail($pdo, 'hunter') !== null,
                'masked' => obe_key_tail($pdo, 'hunter'),
            ],
            'llm' => [
                'openai' => vault_key($pdo, 'openai') !== null,
                'anthropic' => vault_key($pdo, 'anthropic') !== null,
                'typesafe' => vault_key($pdo, 'typesafe') !== null,
                'active' => obe_llm_provider($pdo),
            ],
            'gmail' => [
                'connected' => $gmailConnected,
                'email' => $gmailConnected
                    && !empty($gmailToken['email'])
                    ? (string)$gmailToken['email'] : null,
            ],
            'cron_secret' => [
                'set' => $cronHash !== null && (string)$cronHash !== '',
            ],
        ],
        'settings' => obe_client_settings($pdo, $clientId),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');

if ($action === 'save_key') {
    $provider = (string)($data['provider'] ?? '');
    if (!in_array($provider, ['apollo', 'hunter'], true)) {
        json_out(['error' => 'provider must be apollo or hunter. LLM '
                  . 'keys are managed in the main panel vault.'], 400);
    }
    $key = trim((string)($data['key'] ?? ''));
    if ($key === '') {
        $stmt = $pdo->prepare('DELETE FROM api_keys WHERE provider = ?');
        $stmt->execute([$provider]);
        json_out(['provider' => $provider, 'configured' => false]);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO api_keys (provider, key_value, updated_at) '
        . 'VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE '
        . 'key_value = VALUES(key_value), updated_at = NOW()');
    $stmt->execute([$provider, $key]);
    json_out(['provider' => $provider, 'configured' => true,
              'masked' => '••••' . substr($key, -4)]);
}

if ($action === 'save_cron_secret') {
    $secret = (string)($data['secret'] ?? '');
    if (strlen($secret) < 12) {
        json_out(['error' => 'cron secret must be at least 12 '
                  . 'characters'], 400);
    }
    obe_set_setting($pdo, null, 'cron_secret',
                    password_hash($secret, PASSWORD_DEFAULT));
    json_out(['cron_secret' => 'set']);
}

if ($action === 'save_settings') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    $intKeys = ['enrichment_budget_credits', 'sending_limit_day',
                'message_length'];
    foreach ($intKeys as $k) {
        if (isset($data[$k]) && is_numeric($data[$k])) {
            obe_set_setting($pdo, $clientId, $k,
                            (string)max(0, (int)$data[$k]));
        }
    }
    if (isset($data['default_tone'])) {
        $tone = (string)$data['default_tone'];
        if (!in_array($tone, ['concise', 'conversational', 'professional'],
                      true)) {
            $tone = 'concise';
        }
        obe_set_setting($pdo, $clientId, 'default_tone', $tone);
    }
    if (isset($data['writing_guidelines'])) {
        obe_set_setting($pdo, $clientId, 'writing_guidelines',
                        (string)$data['writing_guidelines']);
    }
    if (isset($data['gmail_client_id'])) {
        obe_set_setting($pdo, $clientId, 'gmail_client_id',
                        trim((string)$data['gmail_client_id']));
    }
    if (isset($data['gmail_client_secret'])
        && trim((string)$data['gmail_client_secret']) !== '') {
        obe_set_setting($pdo, $clientId, 'gmail_client_secret',
                        trim((string)$data['gmail_client_secret']));
    }
    if (isset($data['gmail_refresh_token'])
        && trim((string)$data['gmail_refresh_token']) !== '') {
        $token = ['refresh_token' => trim((string)$data['gmail_refresh_token']),
                  'email' => isset($data['gmail_email'])
                      ? trim((string)$data['gmail_email']) : ''];
        obe_set_setting($pdo, $clientId, 'gmail_token', json_encode($token));
    }
    if (!empty($data['gmail_disconnect'])) {
        $stmt = $pdo->prepare(
            'DELETE FROM obe_settings WHERE client_id = ? AND k = ?');
        $stmt->execute([$clientId, 'gmail_token']);
    }
    json_out(['settings' => obe_client_settings($pdo, $clientId)]);
}

json_out(['error' => 'unknown action'], 400);
