<?php
/**
 * ICP profiles endpoint.
 *   GET  icps.php?client_id=N                  -> list
 *   POST icps.php {id?, client_id, name, nl_text, filters, weights}
 *        Create or update. Weights are normalized to sum to 100.
 *   POST icps.php?action=structure {client_id, nl_text, confirmed}
 *        Unconfirmed: return the LLM estimate (or heuristic mode).
 *        Confirmed: convert the natural language description into a
 *        filters object and return it WITHOUT saving, so the user
 *        reviews and edits before the save call.
 *   POST icps.php?action=delete {id}
 */
require __DIR__ . '/bootstrap.php';

function obe_icp_out(array $row): array {
    $row['id'] = (int)$row['id'];
    $row['client_id'] = (int)$row['client_id'];
    $row['filters'] = obe_icp_filters(obe_json_field($row['filters_json'] ?? null));
    $row['weights'] = obe_icp_weights(obe_json_field($row['weights_json'] ?? null));
    unset($row['filters_json'], $row['weights_json']);
    return $row;
}

/** Filters object with every known key present. */
function obe_icp_filters(array $f): array {
    $defaults = [
        'industries' => [], 'geos' => [], 'size_min' => null,
        'size_max' => null, 'revenue' => '', 'funding' => [],
        'titles' => [], 'seniorities' => [], 'tech' => [],
        'exclude_companies' => [], 'exclude_industries' => [],
        'exclude_titles' => [],
    ];
    $out = $defaults;
    foreach ($defaults as $key => $default) {
        if (!isset($f[$key])) {
            continue;
        }
        if (is_array($default)) {
            $out[$key] = obe_list_field($f[$key]);
        } elseif ($key === 'size_min' || $key === 'size_max') {
            $out[$key] = is_numeric($f[$key]) ? (int)$f[$key] : null;
        } else {
            $out[$key] = (string)$f[$key];
        }
    }
    return $out;
}

/** Weights normalized to sum to 100 (defaults 40 / 40 / 20). */
function obe_icp_weights(array $w): array {
    $fit = isset($w['fit']) && is_numeric($w['fit']) ? (float)$w['fit'] : 40.0;
    $signal = isset($w['signal']) && is_numeric($w['signal'])
        ? (float)$w['signal'] : 40.0;
    $evidence = isset($w['evidence']) && is_numeric($w['evidence'])
        ? (float)$w['evidence'] : 20.0;
    $sum = $fit + $signal + $evidence;
    if ($sum <= 0) {
        return ['fit' => 40, 'signal' => 40, 'evidence' => 20];
    }
    $fitR = (int)round($fit / $sum * 100);
    $signalR = (int)round($signal / $sum * 100);
    $evidenceR = 100 - $fitR - $signalR;
    if ($evidenceR < 0) {
        $evidenceR = 0;
        $signalR = 100 - $fitR;
    }
    return ['fit' => $fitR, 'signal' => $signalR, 'evidence' => $evidenceR];
}

/**
 * Deterministic fallback parser for the structure action, used when
 * no LLM key exists (and labeled heuristic in the response). Pulls an
 * employee range, known geography words, title lists after cues like
 * "include" or "such as", and exclusions after "exclude" / "not".
 */
function obe_icp_heuristic(string $text): array {
    $filters = obe_icp_filters([]);
    if (preg_match('/(\d[\d,]*)\s*(?:-|to|–)\s*(\d[\d,]*)\s*(?:employees|people|staff|team members)/i',
                   $text, $m)) {
        $filters['size_min'] = (int)str_replace(',', '', $m[1]);
        $filters['size_max'] = (int)str_replace(',', '', $m[2]);
    }
    $geoWords = ['North America', 'United States', 'USA', 'US', 'Canada',
                 'EMEA', 'Europe', 'United Kingdom', 'UK', 'APAC',
                 'Latin America', 'Australia', 'Global', 'Worldwide'];
    foreach ($geoWords as $geo) {
        if (preg_match('/\b' . preg_quote($geo, '/') . '\b/i', $text)) {
            $filters['geos'][] = $geo;
        }
    }
    $titleCues = '/(?:decision[ -]?makers(?: include)?|titles?(?: include)?|such as|include|including|target(?:ing)?)\s*:?\s*([^.;]+)/i';
    if (preg_match($titleCues, $text, $m)) {
        $items = preg_split('/,|\band\b/', $m[1]);
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '' && strlen($item) <= 60) {
                $filters['titles'][] = $item;
            }
        }
    }
    if (preg_match('/(?:exclude|excluding|not|no)\s+:?\s*([^.;]+)/i',
                   $text, $m)) {
        $items = preg_split('/,|\band\b/', $m[1]);
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '' || strlen($item) > 60) {
                continue;
            }
            if (preg_match('/\b(vp|vice president|director|manager|head|chief|lead|officer)\b/i',
                           $item)) {
                $filters['exclude_titles'][] = $item;
            } else {
                $filters['exclude_companies'][] = $item;
            }
        }
    }
    return $filters;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_icp_profiles WHERE client_id = ? ORDER BY id');
    $stmt->execute([(int)$client['id']]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = obe_icp_out($row);
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
                ? obe_llm_estimate($provider, strlen($nlText) + 600)
                : null,
        ]);
    }
    $filters = null;
    $mode = 'heuristic';
    if ($provider !== null) {
        $system = 'You convert a natural language ideal customer '
            . 'profile into structured filters. Use ONLY what the text '
            . 'says; leave fields empty when the text does not mention '
            . 'them. Return ONLY a JSON object with keys: industries '
            . '(array), geos (array), size_min (number or null), '
            . 'size_max (number or null), revenue (string), funding '
            . '(array), titles (array), seniorities (array), tech '
            . '(array), exclude_companies (array), exclude_industries '
            . '(array), exclude_titles (array).';
        try {
            list($text, $tokens) = obe_llm_call($pdo, $provider, $system,
                                                $nlText);
            $parsed = obe_parse_json($text);
            if ($parsed !== null) {
                $filters = obe_icp_filters($parsed);
                $mode = 'llm';
                obe_usage($pdo, (int)$client['id'], $provider, 1, 0.0,
                          obe_llm_cost($provider, $tokens),
                          'ICP structuring');
            }
        } catch (Exception $e) {
            $filters = null;
        }
    }
    if ($filters === null) {
        $filters = obe_icp_heuristic($nlText);
        $mode = 'heuristic';
    }
    json_out(['mode' => $mode, 'filters' => $filters, 'saved' => false]);
}

if ($action === 'delete') {
    $client = obe_client($pdo);
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    $stmt = $pdo->prepare(
        'DELETE FROM obe_icp_profiles WHERE id = ? AND client_id = ?');
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
    $filters = obe_icp_filters(
        isset($data['filters']) && is_array($data['filters'])
            ? $data['filters'] : []);
    $weights = obe_icp_weights(
        isset($data['weights']) && is_array($data['weights'])
            ? $data['weights']
            : (isset($data['scoring_weights'])
               && is_array($data['scoring_weights'])
               ? $data['scoring_weights'] : []));
    $nlText = (string)($data['nl_text'] ?? '');
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'UPDATE obe_icp_profiles SET name = ?, nl_text = ?, '
            . 'filters_json = ?, weights_json = ? '
            . 'WHERE id = ? AND client_id = ?');
        $stmt->execute([$name, $nlText, json_encode($filters),
                        json_encode($weights), $id, $clientId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO obe_icp_profiles (client_id, name, nl_text, '
            . 'filters_json, weights_json) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$clientId, $name, $nlText, json_encode($filters),
                        json_encode($weights)]);
        $id = (int)$pdo->lastInsertId();
    }
    $stmt = $pdo->prepare('SELECT * FROM obe_icp_profiles WHERE id = ?');
    $stmt->execute([$id]);
    $wasUpdate = isset($data['id']) && (int)$data['id'] > 0;
    json_out(obe_icp_out($stmt->fetch(PDO::FETCH_ASSOC)),
             $wasUpdate ? 200 : 201);
}

json_out(['error' => 'unknown action'], 400);
