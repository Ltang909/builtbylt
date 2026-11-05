<?php
/**
 * Tracked-link redirect (Outbound Engine, Nov 2026).
 *   GET outbound/r.php?t=<token>
 *
 * Public by design: the unguessable token is the only credential.
 * Resolves the token to its destination, records the click against
 * the contact (that is the "links engaged per person" signal the
 * Reporting page reads), and 302-redirects. Unknown tokens land
 * on the engine root. No session, no auth — OBE_NO_AUTH like the
 * cron endpoint, minus any secret.
 */
define('OBE_NO_AUTH', true);
require __DIR__ . '/api/bootstrap.php';

$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{16}$/', $t)) {
    header('Location: https://builtbylt.com/outbound/', true, 302);
    exit;
}
$stmt = $pdo->prepare(
    'SELECT * FROM obe_tracked_links WHERE token = ?');
$stmt->execute([$t]);
$link = $stmt->fetch(PDO::FETCH_ASSOC);
if ($link === false) {
    header('Location: https://builtbylt.com/outbound/', true, 302);
    exit;
}
try {
    $ins = $pdo->prepare(
        'INSERT INTO obe_link_clicks (token, client_id, contact_id, '
        . 'user_agent) VALUES (?, ?, ?, ?)');
    $ins->execute([$t, (int)$link['client_id'],
                   $link['contact_id'] !== null
                       ? (int)$link['contact_id'] : null,
                   substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                          0, 255)]);
} catch (Throwable $e) {
    // A failed click log must never strand the visitor.
}
header('Location: ' . (string)$link['target_url'], true, 302);
exit;
