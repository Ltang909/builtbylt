<?php
/**
 * Google OAuth landing for the Ads connect flow (Update 11.1).
 *   GET api/google_ads_callback.php?code=...&state=WSID.HMAC
 *
 * This exact URL must be registered as an authorized redirect URI
 * in the operator's Google Cloud OAuth client. The state is an
 * HMAC (see lib/google_ads.php), so no server-side session state
 * is needed to trust it; the panel session is still required to
 * complete the exchange, because connecting an account is an
 * operator action. Google authorization codes are single-use: if
 * the session has expired, log in and connect again.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/google_ads.php';
require_page_auth($pdo);

function gads_callback_page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8">'
        . '<title>' . htmlspecialchars($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;'
        . 'background:#121731;color:#eef0ff;margin:0;padding:40px}'
        . '.card{max-width:560px;margin:8vh auto;background:#1a1f42;'
        . 'border:1px solid #2e3568;border-radius:12px;padding:24px}'
        . 'a{color:#aeb3ff}</style></head><body><div class="card">'
        . '<h2>' . htmlspecialchars($title) . '</h2><p>' . $body
        . '</p><p><a href="../index.php">Back to the panel</a></p>'
        . '</div></body></html>';
    exit;
}

if (isset($_GET['error'])) {
    gads_callback_page('Google sign-in was not completed',
        'Google returned: '
        . htmlspecialchars((string)$_GET['error'])
        . '. Nothing was connected.');
}
$code = (string)($_GET['code'] ?? '');
$state = (string)($_GET['state'] ?? '');
$wsid = $state !== '' ? gads_state_workspace($pdo, $state) : null;
if ($code === '' || $wsid === null) {
    gads_callback_page('This link is not valid',
        'The sign-in link is missing its code or its signature '
        . 'does not check out. Start the connection again from '
        . 'Settings → Google Ads.');
}
try {
    $refresh = gads_exchange_code($pdo, $code);
} catch (Throwable $e) {
    gads_callback_page('Google sign-in failed',
        htmlspecialchars($e->getMessage()));
}
gads_install($pdo);
$pdo->prepare(
    'INSERT INTO workspace_google_ads (workspace_id, refresh_token) '
    . 'VALUES (?, ?) ON DUPLICATE KEY UPDATE '
    . 'refresh_token = VALUES(refresh_token)')
    ->execute([$wsid, $refresh]);
log_ledger($pdo, $wsid, 'google_ads_connect', 'google',
           GADS_API_VERSION, 'ok',
           'workspace connected its Google Ads account');
gads_callback_page('Google Ads connected',
    'This workspace is connected. Head back to Settings → Google '
    . 'Ads in the panel and pick which Google Ads account to push '
    . 'to.');
