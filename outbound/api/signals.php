<?php
/**
 * Intent signals endpoint.
 *   GET  signals.php?client_id=N               -> list
 *   POST signals.php {id?, client_id, ...}     -> create or update
 *   POST signals.php?action=structure {client_id, nl_text, confirmed}
 *        Convert a plain English signal description into source types,
 *        keywords, and a detectability judgment for user review.
 *        LLM when a vault key exists, keyword heuristics otherwise.
 *   POST signals.php?action=toggle  {id, enabled}
 *   POST signals.php?action=delete  {id}
 *
 * Detectability is honest by design: "observable" means a public
 * source shows it directly, "inferred" means the sources hint at it,
 * and "unreliable" flags signals no current detector can support.
 */
require __DIR__ . '/bootstrap.php';

function obe_signal_out(array $row): array {
    $row['id'] = (int)$row['id'];
    $row['client_id'] = (int)$row['client_id'];
    $row['icp_id'] = $row['icp_id'] !== null ? (int)$row['icp_id'] : null;
    $row['source_types'] = obe_json_field($row['source_types_json'] ?? null);
    $row['keywords'] = obe_json_field($row['keywords_json'] ?? null);
    $row['freshness_days'] = (int)$row['freshness_days'];
    $row['importance'] = (int)$row['importance'];
    $row['enabled'] = (int)$row['enabled'] === 1;
    unset($row['source_types_json'], $row['keywords_json']);
    return $row;
}

/**
 * Rule based structure fallback. Maps plain English to the detector
 * sources that can actually observe the signal, pulls quoted phrases
 * and comma lists as keywords, and marks intent that no job post or
 * news page can show as unreliable rather than pretending otherwise.
 */
function obe_signal_heuristic(string $text): array {
    $lower = strtolower($text);
    $sources = [];
    $detectability = 'inferred';
    $add = function ($s) use (&$sources) {
        if (!in_array($s, $sources, true)) {
            $sources[] = $s;
        }
    };
    if (preg_match('/\b(hiring|hire|job|role|recruit|vacancy|opening)\b/',
                   $lower)) {
        $add('greenhouse');
        $add('lever');
        $add('careers');
        $detectability = 'observable';
    }
    if (preg_match('/\b(expand|expansion|new office|new city|new region|new market|opening (a|an|in))\b/',
                   $lower)) {
        $add('news');
        $add('careers');
    }
    if (preg_match('/\b(event|conference|field marketing|dinner|roundtable|summit|experiential)\b/',
                   $lower)) {
        $add('careers');
        $add('news');
    }
    if (preg_match('/\b(funding|raised|seed round|series [a-e]\b|investment|acquisition|acquired)\b/',
                   $lower)) {
        $add('news');
        $detectability = 'observable';
    }
    if (count($sources) === 0) {
        $detectability = 'unreliable';
    }
    $keywords = [];
    if (preg_match_all('/"([^"]{2,60})"/', $text, $mm)) {
        foreach ($mm[1] as $phrase) {
            $keywords[] = trim($phrase);
        }
    }
    if (preg_match('/(?:keywords?|phrases?)\s*:?\s*([^.;]+)/i', $text, $m)) {
        foreach (preg_split('/,/', $m[1]) as $piece) {
            $piece = trim((string)$piece);
            if ($piece !== '') {
                $keywords[] = $piece;
            }
        }
    }
    if (count($keywords) === 0) {
        foreach (['field marketing', 'event marketing', 'events manager',
                  'new office', 'expansion', 'funding', 'roundtable',
                  'executive dinner'] as $candidate) {
            if (strpos($lower, $candidate) !== false) {
                $keywords[] = $candidate;
            }
        }
    }
    return ['source_types' => $sources, 'keywords' => $keywords,
            'detectability' => $detectability];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_intent_signals WHERE client_id = ? ORDER BY id');
    $stmt->execute([(int)$client['id']]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = obe_signal_out($row);
    }
    json_out($out);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');

if ($action === 'structure') {
    $client = obe_client($pdo);
    $nlText = trim((string)($data['nl_text'] ?? ''));
    if ($nlText === '') {
        json_out(['error' => 'nl_text is required'], 400);
    }
    $confirmed = !empty($data['confirmed']);
    $provider = obe_llm_provider($pdo);
    if (!$confirmed) {
        json_out([
            'needs_confirm' => true,
            'mode' => $provider !== null ? 'llm' : 'heuristic',
            'provider' => $provider,
            'estimate' => $provider !== null
                ? obe_llm_estimate($provider, strlen($nlText) + 700)
                : null,
        ]);
    }
    $result = null;
    $mode = 'heuristic';
    if ($provider !== null) {
        $system = 'You convert a plain English buying signal '
            . 'description into detection rules. Available source '
            . 'types: greenhouse and lever (public job board APIs), '
            . 'careers (company careers pages), news (company news and '
            . 'announcement pages). Judge detectability honestly: '
            . '"observable" when a source shows the signal directly, '
            . '"inferred" when sources only hint at it, "unreliable" '
            . 'when none of these sources can show it. Return ONLY a '
            . 'JSON object: {source_types: array, keywords: array of '
            . 'short phrases to match in job titles or headlines, '
            . 'detectability: string}.';
        try {
            list($text, $tokens) = obe_llm_call($pdo, $provider, $system,
                                                $nlText);
            $parsed = obe_parse_json($text);
            if ($parsed !== null && isset($parsed['source_types'])) {
                $det = (string)($parsed['detectability'] ?? 'inferred');
                if (!in_array($det, ['observable', 'inferred', 'unreliable'],
                              true)) {
                    $det = 'inferred';
                }
                $result = [
                    'source_types' => obe_list_field($parsed['source_types']),
                    'keywords' => obe_list_field($parsed['keywords'] ?? []),
                    'detectability' => $det,
                ];
                $mode = 'llm';
                obe_usage($pdo, (int)$client['id'], $provider, 1, 0.0,
                          obe_llm_cost($provider, $tokens),
                          'Signal structuring');
            }
        } catch (Exception $e) {
            $result = null;
        }
    }
    if ($result === null) {
        $result = obe_signal_heuristic($nlText);
        $mode = 'heuristic';
    }
    $allowed = ['greenhouse', 'lever', 'careers', 'news'];
    $result['source_types'] = array_values(array_filter(
        $result['source_types'],
        function ($s) use ($allowed) {
            return in_array($s, $allowed, true);
        }));
    $result['mode'] = $mode;
    $result['saved'] = false;
    json_out($result);
}

if ($action === 'toggle') {
    $client = obe_client($pdo);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $enabled = !empty($data['enabled']) ? 1 : 0;
    $stmt = $pdo->prepare(
        'UPDATE obe_intent_signals SET enabled = ? '
        . 'WHERE id = ? AND client_id = ?');
    $stmt->execute([$enabled, $id, (int)$client['id']]);
    json_out(['id' => $id, 'enabled' => $enabled === 1]);
}

if ($action === 'delete') {
    $client = obe_client($pdo);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $stmt = $pdo->prepare(
        'DELETE FROM obe_intent_signals WHERE id = ? AND client_id = ?');
    $stmt->execute([$id, (int)$client['id']]);
    json_out(['deleted' => $id]);
}

if ($action === '') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        json_out(['error' => 'name is required'], 400);
    }
    $detectability = (string)($data['detectability'] ?? 'observable');
    if (!in_array($detectability, ['observable', 'inferred', 'unreliable'],
                  true)) {
        $detectability = 'observable';
    }
    $importance = isset($data['importance'])
        ? max(1, min(5, (int)$data['importance'])) : 3;
    $freshness = isset($data['freshness_days'])
        ? max(1, (int)$data['freshness_days']) : 30;
    $sourceTypes = json_encode(obe_list_field($data['source_types'] ?? []));
    $keywords = json_encode(obe_list_field($data['keywords'] ?? []));
    $icpId = isset($data['icp_id']) && is_numeric($data['icp_id'])
        ? (int)$data['icp_id'] : null;
    $enabled = isset($data['enabled']) ? (!empty($data['enabled']) ? 1 : 0) : 1;
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE obe_intent_signals SET icp_id = ?, name = ?, '
            . 'nl_text = ?, source_types_json = ?, keywords_json = ?, '
            . 'freshness_days = ?, importance = ?, detectability = ?, '
            . 'enabled = ? WHERE id = ? AND client_id = ?');
        $stmt->execute([$icpId, $name, (string)($data['nl_text'] ?? ''),
                        $sourceTypes, $keywords, $freshness, $importance,
                        $detectability, $enabled, $id, $clientId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO obe_intent_signals (client_id, icp_id, name, '
            . 'nl_text, source_types_json, keywords_json, freshness_days, '
            . 'importance, detectability, enabled) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$clientId, $icpId, $name,
                        (string)($data['nl_text'] ?? ''), $sourceTypes,
                        $keywords, $freshness, $importance, $detectability,
                        $enabled]);
        $id = (int)$pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM obe_intent_signals WHERE id = ?');
    $stmt->execute([$id]);
    json_out(obe_signal_out($stmt->fetch(PDO::FETCH_ASSOC)));
}

json_out(['error' => 'unknown action'], 400);
