<?php
/**
 * Brand tokens endpoint (per workspace).
 *   GET  api/tokens.php?workspace_id=N  -> parsed token object
 *   POST api/tokens.php  {workspace_id, colors, fonts, cta_allowlist,
 *        banned_words, compliance_strings, offer_table, tone_text}
 *        -> the saved token object
 * The guardrail engine reads every list/string/offer from here at verify
 * time; editing tokens changes the next verify run.
 */
require __DIR__ . '/db.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    json_out(get_tokens($pdo, $wsid));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $wsid = resolve_workspace($pdo, $data);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    $tokens = get_tokens($pdo, $wsid);
    foreach (['colors', 'fonts', 'cta_allowlist', 'banned_words',
              'compliance_strings', 'offer_table'] as $field) {
        if (array_key_exists($field, $data)) {
            $tokens[$field] = $data[$field];
        }
    }
    if (array_key_exists('tone_text', $data)) {
        $tokens['tone_text'] = (string)$data['tone_text'];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO brand_tokens (workspace_id, colors_json, fonts_json, '
        . 'cta_allowlist_json, banned_words_json, compliance_strings_json, '
        . 'offer_table_json, tone_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
        . 'ON DUPLICATE KEY UPDATE colors_json = VALUES(colors_json), '
        . 'fonts_json = VALUES(fonts_json), '
        . 'cta_allowlist_json = VALUES(cta_allowlist_json), '
        . 'banned_words_json = VALUES(banned_words_json), '
        . 'compliance_strings_json = VALUES(compliance_strings_json), '
        . 'offer_table_json = VALUES(offer_table_json), '
        . 'tone_text = VALUES(tone_text)');
    $stmt->execute([
        $wsid,
        json_encode($tokens['colors']), json_encode($tokens['fonts']),
        json_encode($tokens['cta_allowlist']),
        json_encode($tokens['banned_words']),
        json_encode($tokens['compliance_strings']),
        json_encode($tokens['offer_table']), $tokens['tone_text'],
    ]);
    json_out(get_tokens($pdo, $wsid));
}

json_out(['error' => 'method not allowed'], 405);
