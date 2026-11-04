<?php
/**
 * Client profile endpoint.
 *   GET  profile.php?client_id=N                 -> profile row or null
 *   POST profile.php?action=save    {client_id, fields...}
 *        Upsert the profile. List fields accept arrays or strings.
 *   POST profile.php?action=analyze {client_id, url, confirmed,
 *        replace_draft}
 *        Fetch the site, keep the evidence, then either return the
 *        evidence plus an LLM estimate (unconfirmed) or structure it
 *        into a DRAFT profile (confirmed). Structuring uses the vault
 *        LLM when a key exists, and falls back to a deterministic
 *        heuristic otherwise. A confirmed profile is never silently
 *        overwritten: without replace_draft the analyze returns 409.
 */
require __DIR__ . '/bootstrap.php';

/** Profile row with JSON columns decoded, or null. */
function obe_profile_out(PDO $pdo, int $clientId): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_client_profiles WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }
    $row['id'] = (int)$row['id'];
    $row['client_id'] = (int)$row['client_id'];
    $row['value_props'] = obe_json_field($row['value_props']);
    $row['differentiators'] = obe_json_field($row['differentiators']);
    $row['use_cases'] = obe_json_field($row['use_cases']);
    $row['source_urls'] = obe_json_field($row['source_urls']);
    return $row;
}

/** A list field stored as JSON; accepts array or newline string. */
function obe_profile_list($value): string {
    return json_encode(obe_list_field($value));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    json_out(['profile' => obe_profile_out($pdo, (int)$client['id'])]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');
$client = obe_client($pdo);
$clientId = (int)$client['id'];

if ($action === 'save') {
    $status = isset($data['status'])
        && in_array((string)$data['status'], ['draft', 'confirmed'], true)
        ? (string)$data['status'] : null;
    $existing = obe_profile_out($pdo, $clientId);
    $pick = function ($key, $default) use ($data, $existing) {
        if (isset($data[$key])) {
            return $data[$key];
        }
        if ($existing !== null && isset($existing[$key])) {
            return $existing[$key];
        }
        return $default;
    };
    $product = (string)$pick('product', '');
    $audiences = (string)$pick('audiences', '');
    $valueProps = obe_profile_list($pick('value_props', []));
    $differentiators = obe_profile_list($pick('differentiators', []));
    $useCases = obe_profile_list($pick('use_cases', []));
    $summary = (string)$pick('value_prop_summary', '');
    $guidelines = (string)$pick('writing_guidelines', '');
    $sourceUrls = isset($data['source_urls'])
        ? json_encode(obe_list_field($data['source_urls']))
        : ($existing !== null ? json_encode($existing['source_urls'])
                              : json_encode([]));
    $evidence = (string)$pick('evidence', '');
    if ($status === null) {
        $status = $existing !== null ? (string)$existing['status'] : 'draft';
    }
    if ($existing !== null) {
        $stmt = $pdo->prepare(
            'UPDATE obe_client_profiles SET product = ?, audiences = ?, '
            . 'value_props = ?, differentiators = ?, use_cases = ?, '
            . 'value_prop_summary = ?, writing_guidelines = ?, '
            . 'source_urls = ?, evidence = ?, status = ? '
            . 'WHERE client_id = ?');
        $stmt->execute([$product, $audiences, $valueProps, $differentiators,
                        $useCases, $summary, $guidelines, $sourceUrls,
                        $evidence, $status, $clientId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO obe_client_profiles (client_id, product, '
            . 'audiences, value_props, differentiators, use_cases, '
            . 'value_prop_summary, writing_guidelines, source_urls, '
            . 'evidence, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$clientId, $product, $audiences, $valueProps,
                        $differentiators, $useCases, $summary, $guidelines,
                        $sourceUrls, $evidence, $status]);
    }
    json_out(['profile' => obe_profile_out($pdo, $clientId)]);
}

if ($action === 'analyze') {
    $url = trim((string)($data['url'] ?? ''));
    if ($url === '') {
        $url = trim((string)($client['website'] ?? ''));
    }
    if ($url === '') {
        json_out(['error' => 'url is required'], 400);
    }
    if (strpos($url, '://') === false) {
        $url = 'https://' . $url;
    }
    $page = obe_fetch_page_evidence($url);
    if ($page === null) {
        json_out(['error' => 'fetch_failed',
                  'hint' => 'Could not fetch that URL from the server. '
                          . 'Check the address and try again.'], 502);
    }
    $evidenceText = 'URL: ' . $page['url'] . "\n"
        . 'Title: ' . $page['title'] . "\n"
        . 'Meta description: ' . $page['meta_description'] . "\n"
        . 'Headings: ' . implode(' | ', $page['headings']) . "\n\n"
        . $page['text'];
    $confirmed = !empty($data['confirmed']);

    if (!$confirmed) {
        $provider = obe_llm_provider($pdo);
        json_out([
            'needs_confirm' => true,
            'mode' => $provider !== null ? 'llm' : 'heuristic',
            'provider' => $provider,
            'estimate' => $provider !== null
                ? obe_llm_estimate($provider, strlen($evidenceText) + 700)
                : null,
            'sources' => [$page['url']],
            'evidence_summary' => [
                'title' => $page['title'],
                'meta_description' => $page['meta_description'],
                'headings' => $page['headings'],
                'text_preview' => substr($page['text'], 0, 600),
                'text_chars' => strlen($page['text']),
            ],
        ]);
    }

    // Confirmed: structure the evidence into a draft profile.
    $structured = null;
    $mode = 'heuristic';
    $provider = obe_llm_provider($pdo);
    if ($provider !== null) {
        $system = 'You extract a structured company profile from website '
            . 'text. Use ONLY facts present in the text. Never invent '
            . 'customers, metrics, or claims. Return ONLY a JSON object '
            . 'with keys: product (string), audiences (string), '
            . 'value_props (array of strings), differentiators (array '
            . 'of strings), use_cases (array of strings), icp_hints '
            . '(string describing who they sell to).';
        try {
            list($text, $tokens) = obe_llm_call(
                $pdo, $provider, $system,
                "Website evidence:\n" . $evidenceText);
            $parsed = obe_parse_json($text);
            if ($parsed !== null) {
                $structured = $parsed;
                $mode = 'llm';
                obe_usage($pdo, $clientId, $provider, 1, 0.0,
                          obe_llm_cost($provider, $tokens),
                          'Website analysis structuring');
            }
        } catch (Exception $e) {
            $structured = null; // fall through to the heuristic
        }
    }
    if ($structured === null) {
        $productLine = $page['title'];
        $cut = strpos($productLine, '|');
        if ($cut !== false) {
            $productLine = trim(substr($productLine, 0, $cut));
        }
        $structured = [
            'product' => $productLine,
            'audiences' => '',
            'value_props' => $page['meta_description'] !== ''
                ? [$page['meta_description']] : [],
            'differentiators' => [],
            'use_cases' => array_slice($page['headings'], 0, 8),
            'icp_hints' => '',
        ];
        $mode = 'heuristic';
    }

    $existing = obe_profile_out($pdo, $clientId);
    if ($existing !== null && $existing['status'] === 'confirmed'
        && empty($data['replace_draft'])) {
        json_out(['error' => 'confirmed_profile_exists',
                  'message' => 'This client already has a confirmed '
                      . 'profile. Re-run with replace_draft set to save '
                      . 'this analysis as a new draft instead.'], 409);
    }
    $summary = isset($structured['value_prop_summary'])
        ? (string)$structured['value_prop_summary']
        : (string)($structured['product'] ?? '');
    $audiences = (string)($structured['audiences'] ?? '');
    if ($audiences === '' && !empty($structured['icp_hints'])) {
        $audiences = (string)$structured['icp_hints'];
    }
    $values = [
        (string)($structured['product'] ?? ''),
        $audiences,
        json_encode(obe_list_field($structured['value_props'] ?? [])),
        json_encode(obe_list_field($structured['differentiators'] ?? [])),
        json_encode(obe_list_field($structured['use_cases'] ?? [])),
        $summary,
        $existing !== null ? (string)($existing['writing_guidelines'] ?? '')
                           : '',
        json_encode([$page['url']]),
        substr($evidenceText, 0, 8000),
        'draft',
    ];
    if ($existing !== null) {
        $stmt = $pdo->prepare(
            'UPDATE obe_client_profiles SET product = ?, audiences = ?, '
            . 'value_props = ?, differentiators = ?, use_cases = ?, '
            . 'value_prop_summary = ?, writing_guidelines = ?, '
            . 'source_urls = ?, evidence = ?, status = ? '
            . 'WHERE client_id = ?');
        $values[] = $clientId;
        $stmt->execute($values);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO obe_client_profiles (client_id, product, '
            . 'audiences, value_props, differentiators, use_cases, '
            . 'value_prop_summary, writing_guidelines, source_urls, '
            . 'evidence, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        array_unshift($values, $clientId);
        $stmt->execute($values);
    }
    if (empty($client['website'])) {
        $stmt = $pdo->prepare(
            'UPDATE obe_clients SET website = ? WHERE id = ?');
        $stmt->execute([$page['url'], $clientId]);
    }
    obe_log_run($pdo, $clientId, 'profile_analyze',
                'Website analysis (' . $mode . ') for ' . $page['url']
                . ' saved as draft profile.');
    json_out(['mode' => $mode,
              'profile' => obe_profile_out($pdo, $clientId)]);
}

json_out(['error' => 'unknown action'], 400);
