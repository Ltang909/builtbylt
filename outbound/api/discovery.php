<?php
/**
 * Prospect discovery endpoint.
 *   GET  discovery.php?client_id=N&view=prospects|watchlist|companies
 *   POST discovery.php?action=import_csv {client_id, kind, rows[]}
 *        CSV import is the always available fallback when an API is
 *        unavailable. Companies dedupe by normalized domain; contacts
 *        by (company, email), else (company, name + title).
 *   POST discovery.php?action=detect_boards {client_id, company_id}
 *        Find a Greenhouse or Lever board from the company's URLs, or
 *        by scanning its careers page links.
 *   POST discovery.php?action=run {client_id, icp_id}
 *        Fetch boards, record signal observations, rescore matches.
 *   POST discovery.php?action=apollo_search {client_id, icp_id, confirmed}
 *        Apollo People Search: credit free. Unconfirmed returns the
 *        estimate note; confirmed runs one search page (25 people).
 *   POST discovery.php?action=enrich {client_id, match_id, confirmed}
 *        Apollo people match for ONE qualified prospect (score 75+),
 *        about 1 credit. Budget checked before any call.
 */
require __DIR__ . '/bootstrap.php';

function obe_match_out(PDO $pdo, array $row): array {
    $row['id'] = (int)$row['id'];
    $row['score'] = (int)$row['score'];
    $row['breakdown'] = obe_json_field($row['breakdown_json'] ?? null);
    unset($row['breakdown_json']);
    $row['contact_name'] = null;
    if (!empty($row['first_name']) || !empty($row['last_name'])) {
        $row['contact_name'] = trim((string)($row['first_name'] ?? '')
            . ' ' . (string)($row['last_name'] ?? ''));
    }
    return $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $client = obe_client($pdo);
    $clientId = (int)$client['id'];
    $view = (string)($_GET['view'] ?? 'prospects');
    if ($view === 'companies') {
        $stmt = $pdo->prepare(
            'SELECT c.*, '
            . '(SELECT COUNT(*) FROM obe_contacts ct WHERE ct.company_id = c.id) AS contacts, '
            . '(SELECT COUNT(*) FROM obe_signal_observations o WHERE o.company_id = c.id) AS observations '
            . 'FROM obe_companies c WHERE c.client_id = ? ORDER BY c.name');
        $stmt->execute([$clientId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int)$row['id'];
            $row['contacts'] = (int)$row['contacts'];
            $row['observations'] = (int)$row['observations'];
            $out[] = $row;
        }
        json_out($out);
    }
    $sql = 'SELECT m.*, co.name AS company_name, co.domain AS company_domain, '
        . 'ct.first_name, ct.last_name, ct.title AS contact_title, '
        . 'ct.email AS contact_email, ct.linkedin_url, '
        . 'i.name AS icp_name '
        . 'FROM obe_prospect_matches m '
        . 'JOIN obe_companies co ON co.id = m.company_id '
        . 'LEFT JOIN obe_contacts ct ON ct.id = m.contact_id '
        . 'LEFT JOIN obe_icp_profiles i ON i.id = m.icp_id '
        . 'WHERE m.client_id = ? ';
    $params = [$clientId];
    if ($view === 'watchlist') {
        $sql .= 'AND m.status = ? ';
        $params[] = 'watchlist';
    } elseif (!empty($_GET['status'])) {
        $sql .= 'AND m.status = ? ';
        $params[] = (string)$_GET['status'];
    }
    $sql .= 'ORDER BY m.score DESC, m.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = obe_match_out($pdo, $row);
    }
    json_out($out);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = obe_body();
$action = (string)($_GET['action'] ?? '');
$client = obe_client($pdo);
$clientId = (int)$client['id'];

if ($action === 'import_csv') {
    $kind = (string)($data['kind'] ?? 'companies');
    $rows = isset($data['rows']) && is_array($data['rows'])
        ? $data['rows'] : [];
    $inserted = 0;
    $skipped = 0;
    if ($kind === 'companies') {
        foreach ($rows as $r) {
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') {
                $skipped++;
                continue;
            }
            $website = trim((string)($r['website'] ?? ''));
            $careers = trim((string)($r['careers_url'] ?? ''));
            $domain = obe_domain_from($website);
            if ($domain === '') {
                $domain = obe_domain_from($careers);
            }
            $before = $pdo->prepare(
                'SELECT id FROM obe_companies WHERE client_id = ? '
                . 'AND domain = ?');
            $checkDomain = $domain !== ''
                ? $domain : obe_company_fallback_key($name);
            $before->execute([$clientId, $checkDomain]);
            $exists = $before->fetch(PDO::FETCH_ASSOC) !== false;
            $company = obe_find_or_create_company(
                $pdo, $clientId, $name, $domain,
                $website !== '' ? $website : null,
                $careers !== '' ? $careers : null);
            if ($exists) {
                $skipped++;
                if ($careers !== '' && empty($company['careers_url'])) {
                    $upd = $pdo->prepare(
                        'UPDATE obe_companies SET careers_url = ? '
                        . 'WHERE id = ?');
                    $upd->execute([$careers, (int)$company['id']]);
                }
            } else {
                $inserted++;
            }
        }
        obe_log_run($pdo, $clientId, 'import',
                    'CSV import (companies): ' . $inserted
                    . ' inserted, ' . $skipped . ' skipped.');
        json_out(['inserted' => $inserted, 'skipped' => $skipped]);
    }
    if ($kind === 'contacts') {
        $findContactByEmail = $pdo->prepare(
            'SELECT id FROM obe_contacts WHERE company_id = ? '
            . 'AND LOWER(email) = ? LIMIT 1');
        $findContactByName = $pdo->prepare(
            'SELECT id FROM obe_contacts WHERE company_id = ? '
            . 'AND LOWER(CONCAT(COALESCE(first_name, \'\'), \' \', '
            . 'COALESCE(last_name, \'\'), \'|\', COALESCE(title, \'\'))) = ? '
            . 'LIMIT 1');
        $insContact = $pdo->prepare(
            'INSERT INTO obe_contacts (client_id, company_id, first_name, '
            . 'last_name, title, seniority, email, linkedin_url) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $r) {
            $companyName = trim((string)($r['company'] ?? ''));
            $first = trim((string)($r['first_name'] ?? ''));
            $last = trim((string)($r['last_name'] ?? ''));
            if ($companyName === '' || ($first === '' && $last === '')) {
                $skipped++;
                continue;
            }
            $email = strtolower(trim((string)($r['email'] ?? '')));
            $domain = $email !== '' ? obe_domain_from($email) : '';
            $generic = ['gmail.com', 'yahoo.com', 'hotmail.com',
                        'outlook.com', 'icloud.com', 'aol.com'];
            if (in_array($domain, $generic, true)) {
                $domain = '';
            }
            $company = obe_find_or_create_company($pdo, $clientId,
                                                  $companyName, $domain);
            $companyId = (int)$company['id'];
            $title = trim((string)($r['title'] ?? ''));
            $dupe = false;
            if ($email !== '') {
                $findContactByEmail->execute([$companyId, $email]);
                $dupe = $findContactByEmail->fetch(PDO::FETCH_ASSOC) !== false;
            } else {
                $key = strtolower($first . ' ' . $last . '|' . $title);
                $findContactByName->execute([$companyId, $key]);
                $dupe = $findContactByName->fetch(PDO::FETCH_ASSOC) !== false;
            }
            if ($dupe) {
                $skipped++;
                continue;
            }
            $insContact->execute([
                $clientId, $companyId, $first !== '' ? $first : null,
                $last !== '' ? $last : null, $title !== '' ? $title : null,
                isset($r['seniority']) ? (string)$r['seniority'] : null,
                $email !== '' ? $email : null,
                trim((string)($r['linkedin_url'] ?? '')) !== ''
                    ? trim((string)$r['linkedin_url']) : null,
            ]);
            $inserted++;
        }
        obe_log_run($pdo, $clientId, 'import',
                    'CSV import (contacts): ' . $inserted
                    . ' inserted, ' . $skipped . ' skipped.');
        json_out(['inserted' => $inserted, 'skipped' => $skipped]);
    }
    json_out(['error' => 'kind must be companies or contacts'], 400);
}

if ($action === 'detect_boards') {
    $companyId = isset($data['company_id']) ? (int)$data['company_id'] : 0;
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_companies WHERE id = ? AND client_id = ?');
    $stmt->execute([$companyId, $clientId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($company === false) {
        json_out(['error' => 'unknown company_id'], 400);
    }
    $found = obe_detect_board_for_company($pdo, $company);
    if ($found === null) {
        json_out(['detected' => false,
                  'message' => 'No Greenhouse or Lever board found in '
                      . 'the company URLs or on its careers page.']);
    }
    json_out(['detected' => true, 'board_type' => $found['type'],
              'board_token' => $found['token']]);
}

if ($action === 'run') {
    $icp = null;
    $icpId = isset($data['icp_id']) ? (int)$data['icp_id'] : 0;
    if ($icpId > 0) {
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_icp_profiles WHERE id = ? AND client_id = ?');
        $stmt->execute([$icpId, $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $icp = $row;
        }
    }
    json_out(obe_run_discovery($pdo, $client, $icp));
}

if ($action === 'apollo_companies') {
    $key = vault_key($pdo, 'apollo');
    if ($key === null || $key === '') {
        json_out(['error' => 'no_apollo_key',
                  'message' => 'No Apollo API key is saved. Add one in '
                      . 'Settings and Integrations to enable Apollo '
                      . 'search.'], 400);
    }
    if (empty($data['confirmed'])) {
        json_out([
            'needs_confirm' => true,
            'estimate' => [
                'provider' => 'apollo',
                'calls' => 1,
                'credits' => 0,
                'results' => 'Up to 25 companies per search',
                'note' => 'Apollo company search is credit free. It '
                    . 'fills the Companies list from your ICP filters; '
                    . 'discovery then checks those companies for '
                    . 'signals, and enrichment stays a separate '
                    . 'manual step.',
            ],
        ]);
    }
    $icpId = isset($data['icp_id']) ? (int)$data['icp_id'] : 0;
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_icp_profiles WHERE client_id = ? '
        . ($icpId > 0 ? 'AND id = ? ' : '') . 'ORDER BY id LIMIT 1');
    $stmt->execute($icpId > 0 ? [$clientId, $icpId] : [$clientId]);
    $icp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($icp === false) {
        json_out(['error' => 'No ICP configured for this client yet.'], 400);
    }
    $filters = obe_json_field($icp['filters_json'] ?? null);
    $payload = ['per_page' => 25, 'page' => 1];
    if (!empty($filters['size_min']) || !empty($filters['size_max'])) {
        $lo = !empty($filters['size_min']) ? (int)$filters['size_min'] : 1;
        $hi = !empty($filters['size_max']) ? (int)$filters['size_max']
                                           : 100000;
        $payload['organization_num_employees_ranges'] = [$lo . ',' . $hi];
    }
    $geos = isset($filters['geos']) ? $filters['geos'] : [];
    if (count($geos) > 0) {
        $payload['organization_locations'] = $geos;
    }
    $industries = isset($filters['industries'])
        ? $filters['industries'] : [];
    if (count($industries) > 0) {
        $payload['q_organization_keyword_tags'] = $industries;
    }
    $res = obe_http('https://api.apollo.io/api/v1/mixed_companies/search', [
        'method' => 'POST',
        'timeout' => 30,
        'headers' => ['Content-Type: application/json',
                      'Accept: application/json',
                      'X-Api-Key: ' . $key],
        'body' => json_encode($payload),
    ]);
    obe_usage($pdo, $clientId, 'apollo', 1, 0.0, 0.0,
              'Company Search (credit free)');
    if ($res['error'] !== null || $res['status'] < 200
        || $res['status'] >= 300) {
        json_out(['error' => 'apollo_failed',
                  'message' => 'Apollo company search failed (HTTP '
                      . $res['status'] . '). '
                      . substr($res['body'], 0, 300)], 502);
    }
    $decoded = json_decode($res['body'], true);
    $orgs = [];
    if (isset($decoded['organizations'])
        && is_array($decoded['organizations'])) {
        $orgs = $decoded['organizations'];
    } elseif (isset($decoded['companies'])
        && is_array($decoded['companies'])) {
        $orgs = $decoded['companies'];
    }
    $insCompany = $pdo->prepare(
        'INSERT IGNORE INTO obe_companies (client_id, name, domain, '
        . 'website, industry, size_band) VALUES (?, ?, ?, ?, ?, ?)');
    $inserted = 0;
    $skipped = 0;
    foreach ($orgs as $org) {
        $name = trim((string)($org['name'] ?? ''));
        $domain = trim((string)($org['primary_domain'] ?? ''));
        if ($domain === '' && !empty($org['website_url'])) {
            $host = parse_url((string)$org['website_url'],
                              PHP_URL_HOST);
            $domain = $host !== null && $host !== false
                ? (string)$host : '';
        }
        $domain = strtolower(preg_replace('/^www\./', '', $domain));
        if ($name === '' || $domain === '') {
            $skipped++;
            continue;
        }
        $sizeBand = null;
        if (isset($org['estimated_num_employees'])
            && (int)$org['estimated_num_employees'] > 0) {
            $emp = (int)$org['estimated_num_employees'];
            if ($emp < 50) {
                $sizeBand = '1-50';
            } elseif ($emp <= 500) {
                $sizeBand = '50-500';
            } elseif ($emp <= 1000) {
                $sizeBand = '500-1000';
            } else {
                $sizeBand = '1000+';
            }
        }
        $insCompany->execute([
            $clientId, $name, $domain,
            'https://' . $domain,
            isset($org['industry']) && $org['industry'] !== ''
                ? (string)$org['industry'] : null,
            $sizeBand,
        ]);
        if ($insCompany->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }
    obe_log_run($pdo, $clientId, 'apollo',
                'Apollo company search: ' . count($orgs) . ' found, '
                . $inserted . ' added, ' . $skipped . ' skipped.');
    json_out(['found' => count($orgs), 'inserted' => $inserted,
              'skipped' => $skipped]);
}

if ($action === 'apollo_search') {
    $key = vault_key($pdo, 'apollo');
    if ($key === null || $key === '') {
        json_out(['error' => 'no_apollo_key',
                  'message' => 'No Apollo API key is saved. Add one in '
                      . 'Settings and Integrations to enable Apollo '
                      . 'search.'], 400);
    }
    if (empty($data['confirmed'])) {
        json_out([
            'needs_confirm' => true,
            'estimate' => [
                'provider' => 'apollo',
                'calls' => 1,
                'credits' => 0,
                'results' => 'Up to 25 people per search',
                'note' => 'Apollo People Search is credit free. '
                    . 'LinkedIn URLs and emails are NOT returned by '
                    . 'search; they require enrichment, about 1 credit '
                    . 'per person, and only for qualified prospects.',
            ],
        ]);
    }
    $icpId = isset($data['icp_id']) ? (int)$data['icp_id'] : 0;
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_icp_profiles WHERE client_id = ? '
        . ($icpId > 0 ? 'AND id = ? ' : '') . 'ORDER BY id LIMIT 1');
    $stmt->execute($icpId > 0 ? [$clientId, $icpId] : [$clientId]);
    $icp = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($icp === false) {
        json_out(['error' => 'No ICP configured for this client yet.'], 400);
    }
    $filters = obe_json_field($icp['filters_json'] ?? null);
    $payload = ['per_page' => 25, 'page' => 1];
    $titles = isset($filters['titles']) ? $filters['titles'] : [];
    if (count($titles) > 0) {
        $payload['person_titles'] = $titles;
    }
    $geos = isset($filters['geos']) ? $filters['geos'] : [];
    if (count($geos) > 0) {
        $payload['person_locations'] = $geos;
    }
    if (!empty($filters['size_min']) || !empty($filters['size_max'])) {
        $lo = !empty($filters['size_min']) ? (int)$filters['size_min'] : 1;
        $hi = !empty($filters['size_max']) ? (int)$filters['size_max']
                                           : 100000;
        $payload['organization_num_employees_ranges'] = [$lo . ',' . $hi];
    }
    $res = obe_http('https://api.apollo.io/api/v1/mixed_people/search', [
        'method' => 'POST',
        'timeout' => 30,
        'headers' => ['Content-Type: application/json',
                      'Accept: application/json',
                      'X-Api-Key: ' . $key],
        'body' => json_encode($payload),
    ]);
    obe_usage($pdo, $clientId, 'apollo', 1, 0.0, 0.0,
              'People Search (credit free)');
    if ($res['error'] !== null || $res['status'] < 200
        || $res['status'] >= 300) {
        json_out(['error' => 'apollo_failed',
                  'message' => 'Apollo search failed (HTTP '
                      . $res['status'] . '). '
                      . substr($res['body'], 0, 300)], 502);
    }
    $decoded = json_decode($res['body'], true);
    $people = isset($decoded['people']) && is_array($decoded['people'])
        ? $decoded['people'] : [];
    $inserted = 0;
    $skipped = 0;
    $findByApollo = $pdo->prepare(
        'SELECT id FROM obe_contacts WHERE client_id = ? AND apollo_id = ? '
        . 'LIMIT 1');
    $insContact = $pdo->prepare(
        'INSERT INTO obe_contacts (client_id, company_id, first_name, '
        . 'last_name, title, email, linkedin_url, apollo_id) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($people as $person) {
        $org = isset($person['organization'])
               && is_array($person['organization'])
            ? $person['organization'] : [];
        $orgName = trim((string)($org['name'] ?? ''));
        if ($orgName === '') {
            $skipped++;
            continue;
        }
        $domain = obe_domain_from((string)($org['primary_domain'] ?? ''));
        if ($domain === '') {
            $domain = obe_domain_from((string)($org['website_url'] ?? ''));
        }
        $company = obe_find_or_create_company(
            $pdo, $clientId, $orgName, $domain,
            isset($org['website_url']) ? (string)$org['website_url'] : null);
        $apolloId = isset($person['id']) ? (string)$person['id'] : '';
        if ($apolloId !== '') {
            $findByApollo->execute([$clientId, $apolloId]);
            if ($findByApollo->fetch(PDO::FETCH_ASSOC) !== false) {
                $skipped++;
                continue;
            }
        }
        $insContact->execute([
            $clientId, (int)$company['id'],
            isset($person['first_name']) ? (string)$person['first_name'] : null,
            isset($person['last_name']) ? (string)$person['last_name'] : null,
            isset($person['title']) ? (string)$person['title'] : null,
            null,
            null,
            isset($person['linkedin_url'])
                ? (string)$person['linkedin_url'] : null,
            $apolloId !== '' ? $apolloId : null,
        ]);
        $inserted++;
    }
    obe_log_run($pdo, $clientId, 'apollo_search',
                'Apollo People Search: ' . count($people) . ' found, '
                . $inserted . ' inserted, ' . $skipped . ' skipped.');
    json_out(['found' => count($people), 'inserted' => $inserted,
              'skipped' => $skipped]);
}

if ($action === 'enrich') {
    $matchId = isset($data['match_id']) ? (int)$data['match_id'] : 0;
    $stmt = $pdo->prepare(
        'SELECT m.*, ct.id AS ct_id, ct.first_name, ct.last_name, '
        . 'ct.email, ct.linkedin_url, ct.apollo_id, co.name AS company_name, '
        . 'co.domain AS company_domain FROM obe_prospect_matches m '
        . 'LEFT JOIN obe_contacts ct ON ct.id = m.contact_id '
        . 'JOIN obe_companies co ON co.id = m.company_id '
        . 'WHERE m.id = ? AND m.client_id = ?');
    $stmt->execute([$matchId, $clientId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($match === false) {
        json_out(['error' => 'unknown match_id'], 400);
    }
    if (empty($match['ct_id'])) {
        json_out(['error' => 'This is a company level match with no '
                  . 'contact to enrich.'], 400);
    }
    // Enrichment credits are only spent on qualified prospects.
    if ((int)$match['score'] < 75
        && !in_array((string)$match['status'],
                     ['queued_enrich', 'enriched'], true)) {
        json_out(['error' => 'not_qualified',
                  'message' => 'Enrichment is reserved for qualified '
                      . 'prospects scoring 75 or more.'], 409);
    }
    $settings = obe_client_settings($pdo, $clientId);
    $used = obe_apollo_credits_used($pdo, $clientId);
    $remaining = $settings['enrichment_budget_credits'] - $used;
    $key = vault_key($pdo, 'apollo');
    if ($key === null || $key === '') {
        json_out(['error' => 'no_apollo_key',
                  'message' => 'No Apollo API key is saved. Add one in '
                      . 'Settings and Integrations.'], 400);
    }
    if (empty($data['confirmed'])) {
        json_out([
            'needs_confirm' => true,
            'estimate' => [
                'provider' => 'apollo',
                'credits' => 1,
                'budget_credits' => $settings['enrichment_budget_credits'],
                'credits_used_this_month' => $used,
                'credits_remaining' => $remaining,
                'note' => 'Estimate: about 1 credit for an email and '
                    . 'LinkedIn URL match. Phone reveal is off.',
            ],
        ]);
    }
    if ($remaining < 1) {
        json_out(['error' => 'budget_exceeded',
                  'message' => 'The enrichment budget for this client '
                      . 'is used up for this month. Raise it in Settings '
                      . 'to continue.'], 409);
    }
    $payload = ['reveal_personal_emails' => false,
                'reveal_phone_number' => false];
    if (!empty($match['apollo_id'])) {
        $payload['id'] = (string)$match['apollo_id'];
    } else {
        $payload['first_name'] = (string)($match['first_name'] ?? '');
        $payload['last_name'] = (string)($match['last_name'] ?? '');
        $payload['organization_name'] = (string)$match['company_name'];
        if (!empty($match['company_domain'])
            && strpos((string)$match['company_domain'], 'name-') !== 0) {
            $payload['domain'] = (string)$match['company_domain'];
        }
    }
    $res = obe_http('https://api.apollo.io/api/v1/people/match', [
        'method' => 'POST',
        'timeout' => 30,
        'headers' => ['Content-Type: application/json',
                      'Accept: application/json',
                      'X-Api-Key: ' . $key],
        'body' => json_encode($payload),
    ]);
    if ($res['error'] !== null || $res['status'] < 200
        || $res['status'] >= 300) {
        json_out(['error' => 'apollo_failed',
                  'message' => 'Apollo enrichment failed (HTTP '
                      . $res['status'] . '). '
                      . substr($res['body'], 0, 300)], 502);
    }
    $decoded = json_decode($res['body'], true);
    $person = isset($decoded['person']) && is_array($decoded['person'])
        ? $decoded['person'] : [];
    $email = isset($person['email']) && (string)$person['email'] !== ''
        ? (string)$person['email'] : $match['email'];
    $linkedin = isset($person['linkedin_url'])
        && (string)$person['linkedin_url'] !== ''
        ? (string)$person['linkedin_url'] : $match['linkedin_url'];
    $upd = $pdo->prepare(
        'UPDATE obe_contacts SET email = ?, linkedin_url = ? WHERE id = ?');
    $upd->execute([$email, $linkedin, (int)$match['ct_id']]);
    $upd = $pdo->prepare(
        'UPDATE obe_prospect_matches SET status = ? WHERE id = ?');
    $upd->execute(['enriched', $matchId]);
    obe_usage($pdo, $clientId, 'apollo', 1, 1.0, 0.0,
              'People match enrichment for match ' . $matchId);
    json_out(['enriched' => true,
              'contact' => ['id' => (int)$match['ct_id'], 'email' => $email,
                            'linkedin_url' => $linkedin],
              'credits_used_this_month' => $used + 1,
              'credits_remaining' => $remaining - 1]);
}

json_out(['error' => 'unknown action'], 400);
