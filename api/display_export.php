<?php
/**
 * Google Responsive Display export packet (Update 11.0).
 *   GET api/display_export.php?creative_id=N
 *
 * Downloads one JSON file carrying everything a manual Google Ads
 * upload needs for this creative: the spec-mapped copy with
 * per-field character counts against Google's caps, the asset URLs
 * (session-gated, on this panel's origin), and the honest gaps
 * (final URL is not stored in the panel; the logo uploads as its
 * own asset). Nothing here invents a value the panel does not hold.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

$cid = (int)($_GET['creative_id'] ?? 0);
$creative = fetch_creative($pdo, $cid);
if ($creative === null) {
    json_out(['error' => 'creative not found'], 404);
}
$full = creative_with_verdicts($pdo, $creative);
$packet = [
    'format' => 'google_responsive_display',
    'creative_id' => $cid,
    'exported_from' => 'Creative Control Panel',
    'spec' => $full['display_spec'] ?? display_spec($pdo, $creative),
    'assets' => $full['display_assets']
        ?? display_assets_for($pdo, $creative),
    'source_copy' => [
        'headline' => (string)($creative['headline'] ?? ''),
        'primary_text' => (string)($creative['primary_text'] ?? ''),
        'description' => (string)($creative['description'] ?? ''),
        'cta' => (string)($creative['cta'] ?? ''),
        'offer_text' => (string)($creative['offer_text'] ?? ''),
    ],
];
$json = json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="creative-' . $cid
    . '-responsive-display.json"');
header('Content-Length: ' . strlen((string)$json));
echo $json;
exit;
