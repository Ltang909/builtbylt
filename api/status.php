<?php
/**
 * Status endpoint. Public surface = a minimal health flag only:
 *   {ok: true, setup_required: bool}
 * With an authenticated session it returns the full status the UI badges
 * read (verifier mode, which providers are configured, workspace count).
 * Key VALUES are never part of any response — configured booleans only.
 */
require __DIR__ . '/db.php';

if (!admin_exists($pdo)) {
    json_out(['ok' => true, 'setup_required' => true]);
}
if (!is_logged_in()) {
    json_out(['ok' => true, 'setup_required' => false, 'auth' => 'on']);
}

$providersConfigured = [];
foreach (PANEL_PROVIDERS as $p) {
    $providersConfigured[$p] = vault_key($pdo, $p) !== null;
}
$tsKey = vault_key($pdo, 'typesafe');
$count = $pdo->query('SELECT COUNT(*) AS n FROM workspaces')
             ->fetch(PDO::FETCH_ASSOC);

json_out([
    'ok' => true,
    'setup_required' => false,
    'auth' => 'on',
    'jev_configured' => (bool)$tsKey,
    'verifier' => $tsKey ? 'jev (local fallback on failure)'
                         : 'local code/mock fallback',
    'providers_configured' => $providersConfigured,
    'workspaces' => $count !== false ? (int)$count['n'] : 0,
]);
