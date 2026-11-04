<?php
/**
 * Outbound Engine shared bootstrap.
 *
 * Loads the panel's common bootstrap + auth (same database, same single
 * admin login, same key vault), turns config/DB problems into JSON
 * errors in the panel's style, installs the engine's own obe_* tables
 * on first use, and provides the shared helpers every engine endpoint
 * uses: client resolution, settings, usage/run logging, HTTP, the LLM
 * estimate/call pair, scoring, and the discovery routine.
 *
 * Tenant rule: the active client workspace id is NEVER stored in the
 * session (the panel rotates its session cookie on guarded responses,
 * which can drop custom session values). Every request carries an
 * explicit client_id, validated here against obe_clients on each call.
 *
 * Define OBE_NO_AUTH before requiring this file to skip the session
 * gate (only cron.php does, and it checks its own shared secret).
 */
require_once __DIR__ . '/../../lib/common.php';
require_once __DIR__ . '/../../lib/auth.php';

if (!empty($GLOBALS['CONFIG_MISSING'])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'config_missing',
                      'hint' => 'Rename api/config.sample.php to api/config.php and fill it in.']);
    exit;
}
if (!isset($pdo) || $pdo === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'db_connection_failed']);
    exit;
}

// --- request helpers ---------------------------------------------------------

/** JSON request body, read once and cached for the whole request. */
function obe_body(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = read_json_body();
    }
    return $cache;
}

/**
 * Resolve and validate the client workspace for this request.
 * client_id comes from the query string or the JSON body. Emits a
 * JSON 400 and exits when it is missing or unknown.
 */
function obe_client(PDO $pdo): array {
    $cid = null;
    if (isset($_GET['client_id']) && is_numeric($_GET['client_id'])) {
        $cid = (int)$_GET['client_id'];
    } else {
        $body = obe_body();
        if (isset($body['client_id']) && is_numeric($body['client_id'])) {
            $cid = (int)$body['client_id'];
        }
    }
    if ($cid === null || $cid <= 0) {
        json_out(['error' => 'client_id is required'], 400);
    }
    $stmt = $pdo->prepare('SELECT * FROM obe_clients WHERE id = ?');
    $stmt->execute([$cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        json_out(['error' => 'unknown client_id'], 400);
    }
    $row['id'] = (int)$row['id'];
    return $row;
}

/** Decode a JSON column to an array; $default when empty or invalid. */
function obe_json_field($value, array $default = []): array {
    if (is_array($value)) {
        return $value;
    }
    if ($value === null || trim((string)$value) === '') {
        return $default;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $default;
}

/**
 * Normalize a list field that may arrive as an array or a comma /
 * newline separated string into a clean array of strings.
 */
function obe_list_field($value): array {
    if (is_array($value)) {
        $items = $value;
    } elseif ($value === null) {
        $items = [];
    } else {
        $items = preg_split('/[,\n]/', (string)$value);
    }
    $out = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item !== '') {
            $out[] = $item;
        }
    }
    return $out;
}

/** Extract a bare domain from a URL, host, or email-ish string. */
function obe_domain_from($value): string {
    $v = trim(strtolower((string)$value));
    if ($v === '') {
        return '';
    }
    if (strpos($v, '@') !== false) {
        $parts = explode('@', $v);
        $v = (string)end($parts);
    }
    if (strpos($v, '://') !== false) {
        $host = parse_url($v, PHP_URL_HOST);
        $v = is_string($host) ? $host : '';
    } else {
        $slash = strpos($v, '/');
        if ($slash !== false) {
            $v = substr($v, 0, $slash);
        }
    }
    $colon = strpos($v, ':');
    if ($colon !== false) {
        $v = substr($v, 0, $colon);
    }
    if (substr($v, 0, 4) === 'www.') {
        $v = substr($v, 4);
    }
    return strpos($v, '.') !== false ? $v : '';
}

/** Fallback company key when no real domain is known. */
function obe_company_fallback_key(string $name): string {
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim((string)$slug, '-');
    return 'name-' . ($slug !== '' ? $slug : 'unknown');
}

// --- install: schema + demo seed ----------------------------------------------

function obe_install(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $ddl = [
        "CREATE TABLE IF NOT EXISTS obe_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            website VARCHAR(512) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_client_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL UNIQUE,
            product TEXT NULL,
            audiences TEXT NULL,
            value_props TEXT NULL,
            differentiators TEXT NULL,
            use_cases TEXT NULL,
            value_prop_summary TEXT NULL,
            writing_guidelines TEXT NULL,
            source_urls TEXT NULL,
            evidence TEXT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_icp_profiles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            name VARCHAR(191) NOT NULL,
            nl_text TEXT NULL,
            filters_json TEXT NULL,
            weights_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_icp_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_intent_signals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            icp_id INT NULL,
            name VARCHAR(191) NOT NULL,
            nl_text TEXT NULL,
            source_types_json TEXT NULL,
            keywords_json TEXT NULL,
            freshness_days INT NOT NULL DEFAULT 30,
            importance INT NOT NULL DEFAULT 3,
            detectability VARCHAR(16) NOT NULL DEFAULT 'observable',
            enabled TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sig_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            name VARCHAR(191) NOT NULL,
            domain VARCHAR(191) NOT NULL DEFAULT '',
            website VARCHAR(512) NULL,
            careers_url VARCHAR(512) NULL,
            board_type VARCHAR(32) NULL,
            board_token VARCHAR(191) NULL,
            industry VARCHAR(191) NULL,
            size_band VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_company_domain (client_id, domain),
            INDEX idx_co_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            company_id INT NOT NULL,
            first_name VARCHAR(128) NULL,
            last_name VARCHAR(128) NULL,
            title VARCHAR(191) NULL,
            seniority VARCHAR(64) NULL,
            email VARCHAR(191) NULL,
            linkedin_url VARCHAR(512) NULL,
            apollo_id VARCHAR(128) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ct_client (client_id),
            INDEX idx_ct_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_signal_observations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            company_id INT NOT NULL,
            contact_id INT NULL,
            signal_id INT NOT NULL,
            source_type VARCHAR(32) NULL,
            source_url VARCHAR(512) NULL,
            evidence TEXT NULL,
            confidence DECIMAL(3,2) NOT NULL DEFAULT 0.90,
            observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_obs (company_id, signal_id, source_url(191)),
            INDEX idx_obs_client (client_id),
            INDEX idx_obs_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_prospect_matches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            company_id INT NOT NULL,
            contact_id INT NULL,
            icp_id INT NOT NULL,
            score INT NOT NULL DEFAULT 0,
            breakdown_json TEXT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'watchlist',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_match (client_id, company_id, contact_id, icp_id),
            INDEX idx_pm_client (client_id),
            INDEX idx_pm_status (client_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            icp_id INT NULL,
            name VARCHAR(191) NOT NULL,
            channel VARCHAR(32) NULL,
            strategy TEXT NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'Draft',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_cp_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            match_id INT NOT NULL,
            campaign_id INT NULL,
            contact_id INT NULL,
            channel VARCHAR(16) NOT NULL DEFAULT 'email',
            subject VARCHAR(512) NULL,
            body TEXT NULL,
            version INT NOT NULL DEFAULT 1,
            status VARCHAR(16) NOT NULL DEFAULT 'draft',
            inputs_json TEXT NULL,
            external_id VARCHAR(191) NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_msg_client (client_id),
            INDEX idx_msg_match (match_id),
            INDEX idx_msg_status (client_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_message_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            message_id INT NOT NULL,
            type VARCHAR(32) NOT NULL,
            detail TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ev_message (message_id),
            INDEX idx_ev_client (client_id, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_suppression (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            email VARCHAR(191) NOT NULL,
            reason VARCHAR(191) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_supp (client_id, email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_provider_usage (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            provider VARCHAR(64) NOT NULL,
            calls INT NOT NULL DEFAULT 0,
            credits DECIMAL(8,2) NOT NULL DEFAULT 0,
            est_cost DECIMAL(10,4) NOT NULL DEFAULT 0,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pu_client (client_id, provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_job_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            type VARCHAR(64) NOT NULL,
            summary TEXT NULL,
            meta_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_jr_client (client_id, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NULL,
            k VARCHAR(64) NOT NULL,
            v TEXT NULL,
            UNIQUE KEY uq_setting (client_id, k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS obe_experiments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            name VARCHAR(191) NOT NULL,
            change_json TEXT NULL,
            result_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ex_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }
    $count = $pdo->query('SELECT COUNT(*) AS n FROM obe_clients')
                 ->fetch(PDO::FETCH_ASSOC);
    if ($count !== false && (int)$count['n'] === 0) {
        obe_seed_demo($pdo);
    }
}

/**
 * Demo seed, run only when obe_clients is completely empty. Two
 * workspaces: Quartermaster (the real first test client) and a clearly
 * fictional second client in an unrelated industry, which is what
 * proves the schema carries no client-specific logic. All copy is
 * plain description; no metrics, customers, or outcomes are claimed.
 */
function obe_seed_demo(PDO $pdo): void {
    $insClient = $pdo->prepare(
        'INSERT INTO obe_clients (name, website, status) VALUES (?, ?, ?)');
    $insProfile = $pdo->prepare(
        'INSERT INTO obe_client_profiles (client_id, product, audiences, '
        . 'value_props, differentiators, use_cases, value_prop_summary, '
        . 'writing_guidelines, source_urls, evidence, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insIcp = $pdo->prepare(
        'INSERT INTO obe_icp_profiles (client_id, name, nl_text, '
        . 'filters_json, weights_json) VALUES (?, ?, ?, ?, ?)');
    $insSignal = $pdo->prepare(
        'INSERT INTO obe_intent_signals (client_id, icp_id, name, nl_text, '
        . 'source_types_json, keywords_json, freshness_days, importance, '
        . 'detectability, enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');

    // --- Client 1: Quartermaster -------------------------------------------
    $insClient->execute(['Quartermaster', 'https://quartermaster.studio', 'active']);
    $qm = (int)$pdo->lastInsertId();
    $insProfile->execute([
        $qm,
        'The Quartermaster platform: software for B2B marketing teams '
            . 'who run executive dinners and roundtables. It tracks every '
            . 'registration, manages guest approval against your ICP, '
            . 'plans seating, briefs your team, and drafts next-morning '
            . 'follow-ups, all in one workspace.',
        'Marketing leaders at B2B SaaS companies: CMOs, VP Marketing, '
            . 'heads of field marketing, and demand generation leaders who '
            . 'need real conversations with senior executives at target '
            . 'accounts.',
        json_encode([
            'See every dinner registration by person and by account, and approve the guests who fit your ICP',
            'A briefing on every guest before the night: why they are at the table, and conversation starters matched to your target accounts',
            'Every seat planned against a target account, with your rep seated beside the guests they need to meet',
            'The morning after, every guest already has a follow-up draft with the topics they raised and the next step',
            'Attendance and outcomes land in your CRM: Attio, HubSpot, or Salesforce',
        ]),
        json_encode([
            'Built only for executive dinners and roundtables, not generic event management',
            'You send every invite: drafts are written in your own Gmail, and the platform never emails a guest itself',
            'AI suggests and explains, but never changes a guest status on its own: a human approves every guest',
            'Runs on your own accounts (Apollo, Gmail, Luma, your CRM), and your guest list stays yours, exportable any time',
        ]),
        json_encode([
            'Executive dinners alongside major industry conferences',
            'Regional field marketing dinners when entering a new market',
            'Account based roundtables for a named target account list',
        ]),
        'Quartermaster is the platform for executive dinners that fill '
            . 'your pipeline: track registrations, approve the right '
            . 'guests, brief your team, plan every seat, and follow up '
            . 'the next morning, all in one place.',
        'Concise and conversational. Operator tone: specific, plain, no '
            . 'hype, no buzzwords. Short sentences. Reference only the '
            . 'real signal provided. One clear ask: a short introductory '
            . 'call.',
        json_encode(['https://quartermaster.studio/platform']),
        'Seeded by hand from quartermaster.studio/platform (the platform '
            . 'offering). Run Website Analysis in the engine to refresh '
            . 'this from the live site.',
        'draft',
    ]);
    $insIcp->execute([
        $qm,
        'B2B SaaS CMO / field marketing',
        'B2B SaaS companies with 50-500 employees in North America. '
            . 'Decision-makers include CMOs, VP Marketing, Heads of Field '
            . 'Marketing, and Demand Generation leaders.',
        json_encode([
            'industries' => ['B2B SaaS'],
            'geos' => ['North America', 'United States', 'Canada'],
            'size_min' => 50,
            'size_max' => 500,
            'revenue' => '',
            'funding' => [],
            'titles' => ['CMO', 'VP Marketing', 'Head of Field Marketing',
                         'Director of Field Marketing', 'VP Demand Generation',
                         'Director of Demand Generation'],
            'seniorities' => ['Chief', 'VP', 'Head', 'Director'],
            'tech' => [],
            'exclude_companies' => [],
            'exclude_industries' => [],
            'exclude_titles' => [],
        ]),
        json_encode(['fit' => 40, 'signal' => 40, 'evidence' => 20]),
    ]);
    $qmIcp = (int)$pdo->lastInsertId();
    $qmSignals = [
        ['Hiring field marketing roles',
         'The company is hiring field marketing or event marketing roles.',
         ['greenhouse', 'lever', 'careers'],
         ['field marketing', 'event marketing'], 30, 5, 'observable'],
        ['Expanding into new cities or regions',
         'The company is opening offices or hiring in new cities or regions.',
         ['news', 'careers'],
         ['new office', 'expansion', 'now hiring in'], 45, 4, 'inferred'],
        ['Launching executive event programs',
         'The company is announcing or staffing executive dinners, roundtables, or event programs.',
         ['news', 'careers'],
         ['executive dinner', 'roundtable', 'field events', 'event series'],
         45, 4, 'inferred'],
        ['Increasing investment in enterprise marketing events',
         'The company is hiring events or experiential staff, suggesting more spend on enterprise marketing events.',
         ['careers', 'news'],
         ['events manager', 'field marketing manager', 'experiential'],
         45, 3, 'inferred'],
    ];
    foreach ($qmSignals as $s) {
        $insSignal->execute([$qm, $qmIcp, $s[0], $s[1], json_encode($s[2]),
                             json_encode($s[3]), $s[4], $s[5], $s[6]]);
    }

    // --- Client 2: fictional demo in an unrelated industry ------------------
    $insClient->execute(['Demo — Brightpath Learning', 'https://example.com', 'active']);
    $bp = (int)$pdo->lastInsertId();
    $insProfile->execute([
        $bp,
        'Brightpath Learning is a fictional demo client. In this demo it '
            . 'makes curriculum planning and assessment software for K-12 '
            . 'school districts.',
        'District administrators: superintendents, directors of '
            . 'curriculum, and district technology leads.',
        json_encode([
            'Curriculum maps aligned to state standards',
            'Implementation and training included for district staff',
        ]),
        json_encode([
            'Built for district wide rollouts rather than single classrooms',
        ]),
        json_encode([
            'District curriculum adoption cycles',
            'New school year implementation projects',
        ]),
        'Demo data only. Brightpath Learning is a fictional second '
            . 'workspace used to prove the engine is client agnostic.',
        'Plain and helpful. This is a demo workspace.',
        json_encode(['https://example.com']),
        'Fictional demo seed. No real company data.',
        'draft',
    ]);
    $insIcp->execute([
        $bp,
        'US K-12 district leadership',
        'US public school districts and mid sized education buyers. '
            . 'Decision-makers: Superintendents, Directors of Curriculum, '
            . 'and district CTOs.',
        json_encode([
            'industries' => ['K-12 Education', 'Education Technology'],
            'geos' => ['United States'],
            'size_min' => null,
            'size_max' => null,
            'revenue' => '',
            'funding' => [],
            'titles' => ['Superintendent', 'Director of Curriculum',
                         'Chief Technology Officer', 'District CTO'],
            'seniorities' => ['Chief', 'Director'],
            'tech' => [],
            'exclude_companies' => [],
            'exclude_industries' => [],
            'exclude_titles' => [],
        ]),
        json_encode(['fit' => 40, 'signal' => 40, 'evidence' => 20]),
    ]);
    $bpIcp = (int)$pdo->lastInsertId();
    $bpSignals = [
        ['Hiring implementation staff',
         'The district or vendor is hiring implementation or onboarding staff.',
         ['greenhouse', 'lever'],
         ['implementation', 'onboarding specialist'], 30, 4, 'observable'],
        ['New funding announced',
         'A funding, grant, or bond announcement suggests budget for new programs.',
         ['news'],
         ['funding', 'grant', 'bond'], 60, 3, 'inferred'],
    ];
    foreach ($bpSignals as $s) {
        $insSignal->execute([$bp, $bpIcp, $s[0], $s[1], json_encode($s[2]),
                             json_encode($s[3]), $s[4], $s[5], $s[6]]);
    }
}

// --- logging, settings, suppression -------------------------------------------

function obe_log_run(PDO $pdo, ?int $clientId, string $type,
                     string $summary, $meta = null): void {
    $stmt = $pdo->prepare(
        'INSERT INTO obe_job_runs (client_id, type, summary, meta_json) '
        . 'VALUES (?, ?, ?, ?)');
    $stmt->execute([$clientId, $type, $summary,
                    $meta === null ? null : json_encode($meta)]);
}

function obe_usage(PDO $pdo, ?int $clientId, string $provider,
                   int $calls = 1, float $credits = 0.0,
                   float $estCost = 0.0, ?string $note = null): void {
    $stmt = $pdo->prepare(
        'INSERT INTO obe_provider_usage (client_id, provider, calls, '
        . 'credits, est_cost, note) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$clientId, $provider, $calls, $credits, $estCost, $note]);
}

/**
 * One obe_settings row. client_id null means a global setting. The
 * UNIQUE key cannot dedupe NULL client ids in MySQL, so writes go
 * through obe_set_setting's select-then-write.
 */
function obe_setting(PDO $pdo, ?int $clientId, string $key,
                     $default = null) {
    if ($clientId === null) {
        $stmt = $pdo->prepare(
            'SELECT v FROM obe_settings WHERE client_id IS NULL AND k = ?');
        $stmt->execute([$key]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT v FROM obe_settings WHERE client_id = ? AND k = ?');
        $stmt->execute([$clientId, $key]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? $default : $row['v'];
}

function obe_set_setting(PDO $pdo, ?int $clientId, string $key,
                         $value): void {
    if ($clientId === null) {
        $stmt = $pdo->prepare(
            'SELECT id FROM obe_settings WHERE client_id IS NULL AND k = ?');
        $stmt->execute([$key]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id FROM obe_settings WHERE client_id = ? AND k = ?');
        $stmt->execute([$clientId, $key]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $upd = $pdo->prepare('UPDATE obe_settings SET v = ? WHERE id = ?');
        $upd->execute([$value, (int)$row['id']]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO obe_settings (client_id, k, v) VALUES (?, ?, ?)');
        $ins->execute([$clientId, $key, $value]);
    }
}

/** Per-client operating settings with their defaults filled in. */
function obe_client_settings(PDO $pdo, int $clientId): array {
    $get = function ($key, $default) use ($pdo, $clientId) {
        $v = obe_setting($pdo, $clientId, $key, null);
        return $v === null ? $default : $v;
    };
    return [
        'enrichment_budget_credits' => (int)$get('enrichment_budget_credits', 50),
        'sending_limit_day' => (int)$get('sending_limit_day', 50),
        'writing_guidelines' => (string)$get('writing_guidelines', ''),
        'default_tone' => (string)$get('default_tone', 'concise'),
        'message_length' => (int)$get('message_length', 120),
    ];
}

/** Apollo credits already spent by this client in the current month. */
function obe_apollo_credits_used(PDO $pdo, int $clientId): float {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(credits), 0) AS c FROM obe_provider_usage '
        . 'WHERE client_id = ? AND provider = ? '
        . 'AND created_at >= DATE_FORMAT(NOW(), \'%Y-%m-01\')');
    $stmt->execute([$clientId, 'apollo']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? 0.0 : (float)$row['c'];
}

/** True when the email is suppressed globally or for this client. */
function obe_suppressed(PDO $pdo, ?int $clientId, string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }
    if ($clientId === null) {
        $stmt = $pdo->prepare(
            'SELECT id FROM obe_suppression WHERE email = ? '
            . 'AND client_id IS NULL LIMIT 1');
        $stmt->execute([$email]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id FROM obe_suppression WHERE email = ? '
            . 'AND (client_id IS NULL OR client_id = ?) LIMIT 1');
        $stmt->execute([$email, $clientId]);
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

// --- HTTP ------------------------------------------------------------------------

/**
 * Small curl wrapper. Never throws; the result always carries
 * status (0 on transport failure), body, final_url, and error.
 * Options: method, headers (array of "Name: value" strings), body
 * (string), timeout (seconds, default 15), follow (bool, default off;
 * page and board fetches pass true, API posts leave it off).
 */
function obe_http(string $url, array $opts = []): array {
    $timeout = isset($opts['timeout']) ? (int)$opts['timeout'] : 15;
    $ch = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OutboundEngine/1.0)',
    ];
    if (!empty($opts['follow'])) {
        $curlOpts[CURLOPT_FOLLOWLOCATION] = true;
        $curlOpts[CURLOPT_MAXREDIRS] = 5;
    }
    if (isset($opts['method'])) {
        $curlOpts[CURLOPT_CUSTOMREQUEST] = (string)$opts['method'];
    }
    if (isset($opts['body'])) {
        $curlOpts[CURLOPT_POSTFIELDS] = (string)$opts['body'];
    }
    if (!empty($opts['headers'])) {
        $curlOpts[CURLOPT_HTTPHEADER] = $opts['headers'];
    }
    curl_setopt_array($ch, $curlOpts);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [
        'status' => $status,
        'body' => $body === false ? '' : (string)$body,
        'final_url' => $finalUrl !== '' ? $finalUrl : $url,
        'error' => $error !== '' ? $error : null,
    ];
}

// --- LLM: swappable provider interface -------------------------------------------
//
// Every billable call goes through the same two steps the panel uses:
// obe_llm_estimate() first (shown to the user, who confirms), then
// obe_llm_call(). Keys come from the panel vault via vault_key(); the
// engine never stores its own copies of LLM keys.

/** Model + USD rate table (per 1M tokens) used for estimates only. */
function obe_llm_models(): array {
    return [
        'openai' => ['model' => 'gpt-4o-mini',
                     'in' => 0.15, 'out' => 0.60],
        'anthropic' => ['model' => 'claude-haiku-4-5-20251001',
                        'in' => 1.00, 'out' => 5.00],
        // Typesafe (Jev) has no published per-token rate card here; the
        // figures below are a rough placeholder so estimates stay in
        // the right order of magnitude. Labeled an estimate in the UI.
        'typesafe' => ['model' => 'jev-latest',
                       'in' => 0.20, 'out' => 0.60],
    ];
}

/** First provider, in preference order, that has a vault key. */
function obe_llm_provider(PDO $pdo): ?string {
    foreach (['openai', 'anthropic'] as $provider) {
        if (vault_key($pdo, $provider) !== null) {
            return $provider;
        }
    }
    return null;
}

/**
 * Rough pre-call estimate: tokens at 4 characters per token, priced
 * from the table above. Always labeled an estimate, never a quote.
 */
function obe_llm_estimate(string $provider, int $promptChars,
                          int $outputChars = 1600): array {
    $models = obe_llm_models();
    $rate = $models[$provider] ?? $models['openai'];
    $inTokens = (int)ceil($promptChars / 4);
    $outTokens = (int)ceil($outputChars / 4);
    $cost = ($inTokens / 1000000) * $rate['in']
          + ($outTokens / 1000000) * $rate['out'];
    return [
        'provider' => $provider,
        'model' => $rate['model'],
        'est_input_tokens' => $inTokens,
        'est_output_tokens' => $outTokens,
        'est_cost' => round($cost, 4),
        'is_estimate' => true,
        'note' => 'Estimate only, from character counts and published '
                . 'rate cards. Actual usage is logged after the call.',
    ];
}

/** Blended cost for a finished call, from its reported token total. */
function obe_llm_cost(string $provider, int $tokens): float {
    $models = obe_llm_models();
    $rate = $models[$provider] ?? $models['openai'];
    $blended = ($rate['in'] + $rate['out']) / 2;
    return round(($tokens / 1000000) * $blended, 4);
}

/**
 * One chat completion. Supports openai and anthropic through the
 * vault. Returns [text, totalTokens]. Throws Exception on any
 * failure (missing key, HTTP error, unparseable reply).
 */
function obe_llm_call(PDO $pdo, string $provider, string $system,
                      string $user): array {
    $key = vault_key($pdo, $provider);
    if ($key === null || $key === '') {
        throw new Exception('No API key saved for provider ' . $provider);
    }
    $models = obe_llm_models();
    if (!isset($models[$provider])) {
        throw new Exception('Unsupported LLM provider ' . $provider);
    }
    $model = $models[$provider]['model'];
    if ($provider === 'openai') {
        $res = obe_http('https://api.openai.com/v1/chat/completions', [
            'method' => 'POST',
            'timeout' => 60,
            'headers' => ['Content-Type: application/json',
                          'Authorization: Bearer ' . $key],
            'body' => json_encode([
                'model' => $model,
                'temperature' => 0.4,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
        ]);
        if ($res['error'] !== null || $res['status'] < 200 || $res['status'] >= 300) {
            throw new Exception('OpenAI call failed (HTTP ' . $res['status'] . '): ' . substr($res['body'], 0, 300));
        }
        $data = json_decode($res['body'], true);
        $text = isset($data['choices'][0]['message']['content'])
            ? (string)$data['choices'][0]['message']['content'] : '';
        if ($text === '') {
            throw new Exception('OpenAI returned an empty completion');
        }
        $tokens = isset($data['usage']['total_tokens'])
            ? (int)$data['usage']['total_tokens'] : 0;
        return [$text, $tokens];
    }
    if ($provider === 'anthropic') {
        $res = obe_http('https://api.anthropic.com/v1/messages', [
            'method' => 'POST',
            'timeout' => 60,
            'headers' => ['Content-Type: application/json',
                          'x-api-key: ' . $key,
                          'anthropic-version: 2023-06-01'],
            'body' => json_encode([
                'model' => $model,
                'max_tokens' => 1500,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ]),
        ]);
        if ($res['error'] !== null || $res['status'] < 200 || $res['status'] >= 300) {
            throw new Exception('Anthropic call failed (HTTP ' . $res['status']
                . '): ' . substr($res['body'], 0, 300));
        }
        $data = json_decode($res['body'], true);
        $text = isset($data['content'][0]['text'])
            ? (string)$data['content'][0]['text'] : '';
        if ($text === '') {
            throw new Exception('Anthropic returned an empty completion');
        }
        $tokens = (isset($data['usage']['input_tokens'])
                   ? (int)$data['usage']['input_tokens'] : 0)
                + (isset($data['usage']['output_tokens'])
                   ? (int)$data['usage']['output_tokens'] : 0);
        return [$text, $tokens];
    }
    throw new Exception('Unsupported LLM provider ' . $provider);
}

/** Extract a JSON object from an LLM reply (strips code fences). */
function obe_parse_json(string $text): ?array {
    $t = trim($text);
    if (substr($t, 0, 3) === '```') {
        $nl = strpos($t, "\n");
        if ($nl !== false) {
            $t = substr($t, $nl + 1);
        }
        if (substr(rtrim($t), -3) === '```') {
            $t = substr(rtrim($t), 0, -3);
        }
    }
    $start = strpos($t, '{');
    $end = strrpos($t, '}');
    if ($start === false || $end === false || $end <= $start) {
        return null;
    }
    $decoded = json_decode(substr($t, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
}

// --- website evidence + job board fetching -----------------------------------------

/**
 * Fetch a web page and reduce it to evidence: final URL, page title,
 * meta description, h1/h2 headings, and up to ~6000 characters of
 * visible text. Returns null when the fetch fails.
 */
function obe_fetch_page_evidence(string $url): ?array {
    $res = obe_http($url, ['timeout' => 12, 'follow' => true]);
    if ($res['error'] !== null || $res['status'] < 200 || $res['status'] >= 400
        || $res['body'] === '') {
        return null;
    }
    $html = $res['body'];
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]),
                                         ENT_QUOTES, 'UTF-8'));
    }
    $metaDesc = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]*>/i', $html, $tag)
        && preg_match('/content=["\'](.*?)["\']/i', $tag[0], $c)) {
        $metaDesc = trim(html_entity_decode($c[1], ENT_QUOTES, 'UTF-8'));
    }
    $headings = [];
    if (preg_match_all('/<h[12][^>]*>(.*?)<\/h[12]>/is', $html, $mm)) {
        foreach ($mm[1] as $h) {
            $h = trim(html_entity_decode(strip_tags($h),
                                         ENT_QUOTES, 'UTF-8'));
            if ($h !== '') {
                $headings[] = $h;
            }
        }
    }
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
    $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', (string)$text);
    $text = strip_tags((string)$text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = trim((string)preg_replace('/\s+/', ' ', $text));
    return [
        'url' => $res['final_url'],
        'title' => $title,
        'meta_description' => $metaDesc,
        'headings' => array_slice($headings, 0, 30),
        'text' => substr($text, 0, 6000),
    ];
}

/**
 * Detect a public job board in a URL. Returns
 * ['type' => 'greenhouse'|'lever', 'token' => string] or null.
 */
function obe_board_from_url(string $url): ?array {
    if (preg_match('#(?:boards|job-boards)\.greenhouse\.io/([A-Za-z0-9_\-]+)#',
                   $url, $m)) {
        return ['type' => 'greenhouse', 'token' => $m[1]];
    }
    if (preg_match('#jobs\.lever\.co/([A-Za-z0-9_\-]+)#', $url, $m)) {
        return ['type' => 'lever', 'token' => $m[1]];
    }
    return null;
}

/**
 * Enrich one contact through Apollo People Match (about 1 credit).
 * The API call itself is the charge event, so usage is logged here,
 * exactly once per call, for both the single-prospect endpoint and
 * the bulk pipeline. Returns ['ok' => bool, ...] and updates the
 * contact row plus the match status on success.
 */
function obe_apollo_enrich_match(PDO $pdo, int $clientId, array $match,
                                 string $key): array {
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
        return ['ok' => false,
                'error' => 'Apollo enrichment failed (HTTP '
                    . $res['status'] . '). '
                    . substr($res['body'], 0, 300)];
    }
    $decoded = json_decode($res['body'], true);
    $person = isset($decoded['person']) && is_array($decoded['person'])
        ? $decoded['person'] : [];
    $email = isset($person['email']) && (string)$person['email'] !== ''
        ? (string)$person['email'] : ($match['email'] ?? null);
    $linkedin = isset($person['linkedin_url'])
        && (string)$person['linkedin_url'] !== ''
        ? (string)$person['linkedin_url']
        : ($match['linkedin_url'] ?? null);
    $upd = $pdo->prepare(
        'UPDATE obe_contacts SET email = ?, linkedin_url = ? '
        . 'WHERE id = ?');
    $upd->execute([$email, $linkedin, (int)$match['ct_id']]);
    $upd = $pdo->prepare(
        'UPDATE obe_prospect_matches SET status = ? WHERE id = ?');
    $upd->execute(['enriched', (int)$match['id']]);
    obe_usage($pdo, $clientId, 'apollo', 1, 1.0, 0.0,
              'People match enrichment for match ' . (int)$match['id']);
    return ['ok' => true, 'email' => $email, 'linkedin_url' => $linkedin];
}

/**
 * Run a one-column aggregate query and return the raw value.
 * (reports.php defines the same helper for its own requests; this
 * copy serves the other engine endpoints.)
 */
function obe_scalar(PDO $pdo, string $sql, array $params) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val === false ? null : $val;
}

/**
 * Try to find a company's public Greenhouse or Lever board from its
 * own URLs. Checks, in order: a board URL used directly as the
 * careers or website URL, the careers page itself (including its
 * links), then the website homepage (a board link, or a careers
 * link followed one level deep). When a board is found it is saved
 * on the company row and returned as ['type' => ..., 'token' => ...].
 * Returns null when nothing is found.
 */
function obe_detect_board_for_company(PDO $pdo, array $company): ?array {
    foreach ([$company['careers_url'] ?? '', $company['website'] ?? '']
             as $candidate) {
        if (!empty($candidate)) {
            $found = obe_board_from_url((string)$candidate);
            if ($found !== null) {
                $stmt = $pdo->prepare(
                    'UPDATE obe_companies SET board_type = ?, '
                    . 'board_token = ? WHERE id = ?');
                $stmt->execute([$found['type'], $found['token'],
                                (int)$company['id']]);
                return $found;
            }
        }
    }
    $pages = [];
    if (!empty($company['careers_url'])) {
        $pages[] = (string)$company['careers_url'];
    }
    if (!empty($company['website'])) {
        $pages[] = (string)$company['website'];
    }
    $toFetch = [];
    foreach ($pages as $pageUrl) {
        $res = obe_http($pageUrl, ['timeout' => 12, 'follow' => true]);
        if ($res['error'] !== null || $res['body'] === '') {
            continue;
        }
        $found = obe_board_from_url($res['final_url']);
        if ($found !== null) {
            $stmt = $pdo->prepare(
                'UPDATE obe_companies SET board_type = ?, '
                . 'board_token = ? WHERE id = ?');
            $stmt->execute([$found['type'], $found['token'],
                            (int)$company['id']]);
            return $found;
        }
        if (!preg_match_all('/href=["\']([^"\']+)["\']/i',
                            $res['body'], $mm)) {
            continue;
        }
        foreach ($mm[1] as $href) {
            $href = html_entity_decode((string)$href, ENT_QUOTES,
                                       'UTF-8');
            $found = obe_board_from_url($href);
            if ($found !== null) {
                $stmt = $pdo->prepare(
                    'UPDATE obe_companies SET board_type = ?, '
                    . 'board_token = ? WHERE id = ?');
                $stmt->execute([$found['type'], $found['token'],
                                (int)$company['id']]);
                return $found;
            }
            $lh = strtolower($href);
            if ((strpos($lh, 'career') !== false
                 || strpos($lh, '/jobs') !== false)
                && strpos($href, '#') !== 0) {
                $toFetch[] = $href;
            }
        }
    }
    foreach (array_slice(array_unique($toFetch), 0, 3) as $careersLink) {
        if (strpos($careersLink, 'http') !== 0) {
            $base = !empty($company['website'])
                ? (string)$company['website'] : '';
            if ($base === '') {
                continue;
            }
            $parts = parse_url($base);
            if (!isset($parts['scheme']) || !isset($parts['host'])) {
                continue;
            }
            $careersLink = $parts['scheme'] . '://' . $parts['host']
                . '/' . ltrim($careersLink, '/');
        }
        $res = obe_http($careersLink, ['timeout' => 12, 'follow' => true]);
        if ($res['error'] !== null || $res['body'] === '') {
            continue;
        }
        $found = obe_board_from_url($res['final_url']);
        if ($found === null
            && preg_match_all('/href=["\']([^"\']+)["\']/i',
                              $res['body'], $mm2)) {
            foreach ($mm2[1] as $href2) {
                $found = obe_board_from_url(html_entity_decode(
                    (string)$href2, ENT_QUOTES, 'UTF-8'));
                if ($found !== null) {
                    break;
                }
            }
        }
        if ($found !== null) {
            $stmt = $pdo->prepare(
                'UPDATE obe_companies SET board_type = ?, '
                . 'board_token = ? WHERE id = ?');
            $stmt->execute([$found['type'], $found['token'],
                            (int)$company['id']]);
            return $found;
        }
    }
    return null;
}

/**
 * Recent Google News items about a company that mention at least one
 * of the given signals' keywords. One RSS fetch per call (free, no
 * key). Returns up to 10 normalized items (title, summary, link,
 * ts), or null when the feed could not be fetched or parsed.
 */
function obe_news_items(string $companyName, array $newsSignals): ?array {
    $kws = [];
    foreach ($newsSignals as $signal) {
        $keywords = obe_json_field($signal['keywords_json'] ?? null);
        foreach ($keywords as $kw) {
            $kw = trim((string)$kw);
            if ($kw !== '') {
                $kws[] = $kw;
            }
        }
    }
    $kws = array_slice(array_unique($kws), 0, 3);
    if (count($kws) === 0) {
        return null;
    }
    $query = '"' . $companyName . '" (' . implode(' OR ', $kws) . ')';
    $url = 'https://news.google.com/rss/search?q='
        . urlencode($query) . '&hl=en-US&gl=US&ceid=US:en';
    $res = obe_http($url, ['timeout' => 15, 'follow' => true]);
    if ($res['error'] !== null || $res['body'] === '') {
        return null;
    }
    $nameLc = strtolower($companyName);
    $items = [];
    if (function_exists('simplexml_load_string')) {
        $xml = @simplexml_load_string($res['body']);
        if ($xml !== false && isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $title = (string)$item->title;
                $link = (string)$item->link;
                $desc = strip_tags((string)$item->description);
                if ($title === '' || $link === '') {
                    continue;
                }
                if (strpos(strtolower($title . ' ' . $desc),
                           $nameLc) === false) {
                    continue;
                }
                $ts = isset($item->pubDate)
                    ? strtotime((string)$item->pubDate) : false;
                $items[] = [
                    'title' => $title,
                    'summary' => $desc,
                    'link' => $link,
                    'ts' => $ts !== false ? $ts : time(),
                ];
                if (count($items) >= 10) {
                    break;
                }
            }
        }
        return $items;
    }
    if (preg_match_all('/<item>([\s\S]*?)<\/item>/',
                       $res['body'], $blocks)) {
        foreach ($blocks[1] as $block) {
            if (!preg_match('/<title>(?:<!\[CDATA\[)?([\s\S]*?)(?:\]\]>)?<\/title>/',
                            $block, $mt)
                || !preg_match('/<link>(?:<!\[CDATA\[)?([\s\S]*?)(?:\]\]>)?<\/link>/',
                               $block, $ml)) {
                continue;
            }
            $title = html_entity_decode(trim($mt[1]), ENT_QUOTES,
                                        'UTF-8');
            $link = html_entity_decode(trim($ml[1]), ENT_QUOTES,
                                       'UTF-8');
            $desc = '';
            if (preg_match('/<description>(?:<!\[CDATA\[)?([\s\S]*?)(?:\]\]>)?<\/description>/',
                           $block, $md)) {
                $desc = strip_tags(html_entity_decode(
                    trim($md[1]), ENT_QUOTES, 'UTF-8'));
            }
            if ($title === '' || $link === '') {
                continue;
            }
            if (strpos(strtolower($title . ' ' . $desc),
                       $nameLc) === false) {
                continue;
            }
            $ts = time();
            if (preg_match('/<pubDate>([\s\S]*?)<\/pubDate>/',
                           $block, $mp)) {
                $parsed = strtotime(trim($mp[1]));
                if ($parsed !== false) {
                    $ts = $parsed;
                }
            }
            $items[] = ['title' => $title, 'summary' => $desc,
                        'link' => $link, 'ts' => $ts];
            if (count($items) >= 10) {
                break;
            }
        }
    }
    return $items;
}

/**
 * Fetch open jobs from a public Greenhouse or Lever board (both are
 * free, unauthenticated APIs). Normalized to title, department,
 * location, url, posted date. Throws Exception on failure.
 */
function obe_fetch_board_jobs(string $type, string $token): array {
    $jobs = [];
    if ($type === 'greenhouse') {
        $res = obe_http('https://boards-api.greenhouse.io/v1/boards/'
                        . urlencode($token) . '/jobs',
                        ['timeout' => 20, 'follow' => true]);
        if ($res['error'] !== null || $res['status'] !== 200) {
            throw new Exception('Greenhouse board fetch failed (HTTP '
                                . $res['status'] . ')');
        }
        $data = json_decode($res['body'], true);
        foreach ((isset($data['jobs']) && is_array($data['jobs']))
                 ? $data['jobs'] : [] as $j) {
            $jobs[] = [
                'title' => (string)($j['title'] ?? ''),
                'department' => isset($j['departments'][0]['name'])
                    ? (string)$j['departments'][0]['name'] : '',
                'location' => isset($j['location']['name'])
                    ? (string)$j['location']['name'] : '',
                'url' => (string)($j['absolute_url'] ?? ''),
                'posted' => (string)($j['updated_at'] ?? ''),
            ];
        }
        return $jobs;
    }
    if ($type === 'lever') {
        $res = obe_http('https://api.lever.co/v0/postings/'
                        . urlencode($token) . '?mode=json',
                        ['timeout' => 20, 'follow' => true]);
        if ($res['error'] !== null || $res['status'] !== 200) {
            throw new Exception('Lever board fetch failed (HTTP '
                                . $res['status'] . ')');
        }
        $data = json_decode($res['body'], true);
        foreach (is_array($data) ? $data : [] as $j) {
            $cat = isset($j['categories']) && is_array($j['categories'])
                ? $j['categories'] : [];
            $posted = '';
            if (!empty($j['createdAt'])) {
                $posted = date('Y-m-d H:i:s',
                               (int)floor(((int)$j['createdAt']) / 1000));
            }
            $jobs[] = [
                'title' => (string)($j['text'] ?? ''),
                'department' => (string)($cat['department']
                                         ?? ($cat['team'] ?? '')),
                'location' => (string)($cat['location'] ?? ''),
                'url' => (string)($j['hostedUrl'] ?? ''),
                'posted' => $posted,
            ];
        }
        return $jobs;
    }
    throw new Exception('Unknown board type ' . $type);
}

// --- scoring -------------------------------------------------------------------------
//
// Score = fit * Wfit + signal * Wsignal + evidence * Wevidence, where
// each component is 0..1 and the weights come from the ICP (default
// 40 / 40 / 20, normalized to sum 100 when the match is scored).
//
//   fit:      70% title match (contact title against ICP titles, with
//             a seniority keyword fallback at 0.6 and a neutral 0.25
//             when nothing matches; 0.5 neutral when the ICP lists no
//             titles), 30% company match (industry overlap and size
//             band overlap, each half; unknowns score 0.5). Company
//             level matches with no contact use the company part only,
//             scaled by 0.6 because no person has been identified.
//   signal:   per distinct matched signal, (importance / 5) scaled by
//             a freshness factor (1.0 inside the signal's freshness
//             window, decaying to 0.25 at twice the window), summed
//             and capped at 1. One fresh, top importance signal can
//             therefore carry the full signal component.
//   evidence: average over the best observation per signal of
//             confidence * freshness factor. No observations means 0:
//             evidence is never invented.

function obe_freshness_factor(string $observedAt, int $freshnessDays): float {
    $ts = strtotime($observedAt);
    if ($ts === false) {
        return 0.25;
    }
    $ageDays = (time() - $ts) / 86400;
    if ($ageDays < 0) {
        $ageDays = 0;
    }
    $window = max(1, $freshnessDays);
    if ($ageDays <= $window) {
        return 1.0;
    }
    if ($ageDays >= 2 * $window) {
        return 0.25;
    }
    return 1.0 - (0.75 * ($ageDays - $window) / $window);
}

/** Title aliases so "CMO" also matches "Chief Marketing Officer". */
function obe_title_variants(string $title): array {
    $t = strtolower(trim($title));
    $variants = [$t];
    $aliases = [
        'cmo' => ['chief marketing officer'],
        'chief marketing officer' => ['cmo'],
        'cto' => ['chief technology officer'],
        'chief technology officer' => ['cto'],
        'vp' => ['vice president'],
        'vice president' => ['vp'],
    ];
    if (isset($aliases[$t])) {
        foreach ($aliases[$t] as $a) {
            $variants[] = $a;
        }
    }
    return $variants;
}

function obe_score_match(array $company, ?array $contact, array $filters,
                         array $weights, array $observations): array {
    $fitW = isset($weights['fit']) ? (float)$weights['fit'] : 40.0;
    $sigW = isset($weights['signal']) ? (float)$weights['signal'] : 40.0;
    $evW = isset($weights['evidence']) ? (float)$weights['evidence'] : 20.0;
    $sum = $fitW + $sigW + $evW;
    if ($sum <= 0) {
        $fitW = 40.0; $sigW = 40.0; $evW = 20.0; $sum = 100.0;
    }
    $fitW = $fitW / $sum * 100;
    $sigW = $sigW / $sum * 100;
    $evW = $evW / $sum * 100;

    // Company component.
    $industryScore = 0.5;
    $icpIndustries = isset($filters['industries'])
        ? $filters['industries'] : [];
    $companyIndustry = trim((string)($company['industry'] ?? ''));
    if ($companyIndustry !== '' && count($icpIndustries) > 0) {
        $industryScore = 0.2;
        foreach ($icpIndustries as $ind) {
            $ind = strtolower(trim((string)$ind));
            if ($ind !== '' && (strpos(strtolower($companyIndustry), $ind) !== false
                || strpos($ind, strtolower($companyIndustry)) !== false)) {
                $industryScore = 1.0;
                break;
            }
        }
    }
    $sizeScore = 0.5;
    $sizeMin = isset($filters['size_min']) && $filters['size_min'] !== null
        ? (int)$filters['size_min'] : null;
    $sizeMax = isset($filters['size_max']) && $filters['size_max'] !== null
        ? (int)$filters['size_max'] : null;
    $band = (string)($company['size_band'] ?? '');
    if (($sizeMin !== null || $sizeMax !== null)
        && preg_match('/(\d+)\D+(\d+)/', $band, $bm)) {
        $lo = (int)$bm[1];
        $hi = (int)$bm[2];
        $wantLo = $sizeMin === null ? 0 : $sizeMin;
        $wantHi = $sizeMax === null ? PHP_INT_MAX : $sizeMax;
        if ($lo >= $wantLo && $hi <= $wantHi) {
            $sizeScore = 1.0;
        } elseif ($hi < $wantLo || $lo > $wantHi) {
            $sizeScore = 0.2;
        } else {
            $sizeScore = 0.6;
        }
    }
    $companyScore = ($industryScore + $sizeScore) / 2;

    // Title component.
    if ($contact === null) {
        $fit = $companyScore * 0.6;
    } else {
        $contactTitle = strtolower(trim((string)($contact['title'] ?? '')));
        $icpTitles = isset($filters['titles']) ? $filters['titles'] : [];
        if (count($icpTitles) === 0 || $contactTitle === '') {
            $titleScore = 0.5;
        } else {
            $titleScore = 0.25;
            foreach ($icpTitles as $t) {
                foreach (obe_title_variants((string)$t) as $variant) {
                    if ($variant !== ''
                        && strpos($contactTitle, $variant) !== false) {
                        $titleScore = 1.0;
                        break 2;
                    }
                }
            }
            if ($titleScore < 1.0) {
                $seniorities = isset($filters['seniorities'])
                    ? $filters['seniorities'] : [];
                foreach ($seniorities as $s) {
                    $s = strtolower(trim((string)$s));
                    if ($s !== '' && strpos($contactTitle, $s) !== false) {
                        $titleScore = 0.6;
                        break;
                    }
                }
            }
        }
        $fit = 0.7 * $titleScore + 0.3 * $companyScore;
    }

    // Signal + evidence components, best observation per signal.
    $bestBySignal = [];
    foreach ($observations as $obs) {
        $sid = (int)$obs['signal_id'];
        $factor = obe_freshness_factor(
            (string)$obs['observed_at'],
            isset($obs['freshness_days']) ? (int)$obs['freshness_days'] : 30);
        $quality = (float)$obs['confidence'] * $factor;
        if (!isset($bestBySignal[$sid])
            || $quality > $bestBySignal[$sid]['quality']) {
            $bestBySignal[$sid] = [
                'importance' => isset($obs['importance'])
                    ? (int)$obs['importance'] : 3,
                'factor' => $factor,
                'quality' => $quality,
            ];
        }
    }
    $signal = 0.0;
    $evidenceSum = 0.0;
    foreach ($bestBySignal as $b) {
        $signal += ($b['importance'] / 5) * $b['factor'];
        $evidenceSum += $b['quality'];
    }
    $signal = min(1.0, $signal);
    $evidence = count($bestBySignal) > 0
        ? $evidenceSum / count($bestBySignal) : 0.0;

    $fitPoints = $fit * $fitW;
    $signalPoints = $signal * $sigW;
    $evidencePoints = $evidence * $evW;
    $score = (int)round($fitPoints + $signalPoints + $evidencePoints);
    return [
        'score' => max(0, min(100, $score)),
        'breakdown' => [
            'fit' => round($fit, 3),
            'signal' => round($signal, 3),
            'evidence' => round($evidence, 3),
            'fit_points' => round($fitPoints, 1),
            'signal_points' => round($signalPoints, 1),
            'evidence_points' => round($evidencePoints, 1),
            'weights' => ['fit' => round($fitW, 1),
                          'signal' => round($sigW, 1),
                          'evidence' => round($evW, 1)],
            'signal_ids' => array_map('intval', array_keys($bestBySignal)),
        ],
    ];
}

/**
 * Insert or update one prospect match. Statuses that represent real
 * progress (enriched, queued_outreach, archived) are never downgraded
 * by a rescore.
 */
function obe_upsert_match(PDO $pdo, int $clientId, int $companyId,
                          ?int $contactId, int $icpId, int $score,
                          array $breakdown): string {
    if ($contactId === null) {
        $stmt = $pdo->prepare(
            'SELECT id, status FROM obe_prospect_matches WHERE client_id = ? '
            . 'AND company_id = ? AND icp_id = ? AND contact_id IS NULL');
        $stmt->execute([$clientId, $companyId, $icpId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, status FROM obe_prospect_matches WHERE client_id = ? '
            . 'AND company_id = ? AND icp_id = ? AND contact_id = ?');
        $stmt->execute([$clientId, $companyId, $icpId, $contactId]);
    }
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $scoredStatus = $score >= 75 ? 'queued_enrich' : 'watchlist';
    if ($existing !== false) {
        $status = (string)$existing['status'];
        if (!in_array($status, ['enriched', 'queued_outreach', 'archived'], true)) {
            $status = $scoredStatus;
        }
        $upd = $pdo->prepare(
            'UPDATE obe_prospect_matches SET score = ?, breakdown_json = ?, '
            . 'status = ? WHERE id = ?');
        $upd->execute([$score, json_encode($breakdown), $status,
                       (int)$existing['id']]);
        return $status;
    }
    $ins = $pdo->prepare(
        'INSERT INTO obe_prospect_matches (client_id, company_id, '
        . 'contact_id, icp_id, score, breakdown_json, status) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$clientId, $companyId, $contactId, $icpId, $score,
                   json_encode($breakdown), $scoredStatus]);
    return $scoredStatus;
}

/**
 * Find a company by (client, domain) or create it. Returns the row.
 * Used by CSV import and Apollo search.
 */
function obe_find_or_create_company(PDO $pdo, int $clientId, string $name,
                                    string $domain, ?string $website = null,
                                    ?string $careersUrl = null): array {
    if ($domain === '') {
        $domain = obe_company_fallback_key($name);
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_companies WHERE client_id = ? AND domain = ?');
    $stmt->execute([$clientId, $domain]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        $row['id'] = (int)$row['id'];
        $row['_created'] = false;
        return $row;
    }
    $ins = $pdo->prepare(
        'INSERT INTO obe_companies (client_id, name, domain, website, '
        . 'careers_url) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$clientId, $name, $domain, $website, $careersUrl]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('SELECT * FROM obe_companies WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $row['id'] = (int)$row['id'];
    $row['_created'] = true;
    return $row;
}

// --- discovery routine (shared by discovery.php and cron.php) -----------------------

/**
 * Run one discovery pass for a client against one ICP:
 *   1. For every company with a detected job board, fetch its open
 *      jobs from the free Greenhouse / Lever APIs.
 *   2. Match jobs against the client's enabled signals (a signal
 *      matches when one of its keywords appears in the job title or
 *      department) and record deduplicated signal observations with
 *      the job URL as the verifiable source.
 *   3. (Re)score every contact of every company that has at least one
 *      observation, plus a company level match for signal companies
 *      with no known contacts.
 *
 * Returns a summary array; also writes a job_runs row.
 */
function obe_run_discovery(PDO $pdo, array $client, ?array $icp): array {
    $clientId = (int)$client['id'];
    $summary = [
        'companies_checked' => 0,
        'boards_detected' => 0,
        'jobs_seen' => 0,
        'news_checked' => 0,
        'news_observations' => 0,
        'new_observations' => 0,
        'matches_scored' => 0,
        'queued' => 0,
        'errors' => [],
    ];
    if ($icp === null) {
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_icp_profiles WHERE client_id = ? '
            . 'ORDER BY id LIMIT 1');
        $stmt->execute([$clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $summary['note'] = 'No ICP configured for this client yet.';
            obe_log_run($pdo, $clientId, 'discovery',
                        'Discovery run skipped: no ICP configured.',
                        $summary);
            return $summary;
        }
        $icp = $row;
    }
    $icpId = (int)$icp['id'];
    $filters = obe_json_field($icp['filters_json'] ?? null);
    $weights = obe_json_field($icp['weights_json'] ?? null,
                              ['fit' => 40, 'signal' => 40, 'evidence' => 20]);

    $stmt = $pdo->prepare(
        'SELECT * FROM obe_intent_signals WHERE client_id = ? '
        . 'AND enabled = 1 AND (icp_id IS NULL OR icp_id = ?)');
    $stmt->execute([$clientId, $icpId]);
    $signals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Companies that arrive from Apollo search or CSV import usually
    // have no job board attached yet. Try to detect one automatically
    // (bounded per run) so a single Run covers the full flow: find
    // companies, locate their boards, then read them.
    $stmt = $pdo->prepare(
        'SELECT * FROM obe_companies WHERE client_id = ? '
        . 'AND (board_token IS NULL OR board_token = \'\') '
        . 'AND ((careers_url IS NOT NULL AND careers_url <> \'\') '
        . 'OR (website IS NOT NULL AND website <> \'\')) '
        . 'ORDER BY id LIMIT 25');
    $stmt->execute([$clientId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cand) {
        if (obe_detect_board_for_company($pdo, $cand) !== null) {
            $summary['boards_detected']++;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM obe_companies WHERE client_id = ? '
        . 'AND board_token IS NOT NULL AND board_token <> \'\'');
    $stmt->execute([$clientId]);
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $summary['companies_checked'] = count($companies);

    $obsExists = $pdo->prepare(
        'SELECT id FROM obe_signal_observations WHERE company_id = ? '
        . 'AND signal_id = ? AND source_url = ? LIMIT 1');
    $obsInsert = $pdo->prepare(
        'INSERT INTO obe_signal_observations (client_id, company_id, '
        . 'signal_id, source_type, source_url, evidence, confidence, '
        . 'observed_at) VALUES (?, ?, ?, ?, ?, ?, 0.90, ?)');

    foreach ($companies as $company) {
        $companyId = (int)$company['id'];
        try {
            $jobs = obe_fetch_board_jobs((string)$company['board_type'],
                                         (string)$company['board_token']);
        } catch (Exception $e) {
            $summary['errors'][] = (string)$company['name'] . ': '
                                 . $e->getMessage();
            continue;
        }
        $summary['jobs_seen'] += count($jobs);
        foreach ($jobs as $job) {
            if ($job['url'] === '' || $job['title'] === '') {
                continue;
            }
            $haystack = strtolower($job['title'] . ' ' . $job['department']);
            foreach ($signals as $signal) {
                $types = obe_json_field($signal['source_types_json'] ?? null);
                if (!in_array((string)$company['board_type'], $types, true)) {
                    continue;
                }
                $keywords = obe_json_field($signal['keywords_json'] ?? null);
                $matched = false;
                foreach ($keywords as $kw) {
                    $kw = strtolower(trim((string)$kw));
                    if ($kw !== '' && strpos($haystack, $kw) !== false) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
                $obsExists->execute([$companyId, (int)$signal['id'],
                                     $job['url']]);
                if ($obsExists->fetch(PDO::FETCH_ASSOC) !== false) {
                    continue;
                }
                $evidence = $job['title'];
                if ($job['department'] !== '') {
                    $evidence .= ' · ' . $job['department'];
                }
                if ($job['location'] !== '') {
                    $evidence .= ' · ' . $job['location'];
                }
                $postedTs = $job['posted'] !== ''
                    ? strtotime($job['posted']) : false;
                $observedAt = $postedTs !== false
                    ? date('Y-m-d H:i:s', $postedTs)
                    : date('Y-m-d H:i:s');
                $obsInsert->execute([$clientId, $companyId,
                                     (int)$signal['id'],
                                     (string)$company['board_type'],
                                     $job['url'], $evidence, $observedAt]);
                $summary['new_observations']++;
            }
        }
    }

    // News pass: enabled signals that list the news source are checked
    // against Google News RSS (free, no key) once per company. An item
    // becomes an observation only when the headline or summary names
    // the company AND matches one of the signal's own keywords, and it
    // was published inside the signal's freshness window.
    $newsSignals = [];
    foreach ($signals as $signal) {
        $types = obe_json_field($signal['source_types_json'] ?? null);
        if (in_array('news', $types, true)) {
            $newsSignals[] = $signal;
        }
    }
    if (count($newsSignals) > 0) {
        $stmt = $pdo->prepare(
            'SELECT * FROM obe_companies WHERE client_id = ? '
            . 'ORDER BY id LIMIT 25');
        $stmt->execute([$clientId]);
        $newsCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $newsObsInsert = $pdo->prepare(
            'INSERT INTO obe_signal_observations (client_id, company_id, '
            . 'signal_id, source_type, source_url, evidence, confidence, '
            . 'observed_at) VALUES (?, ?, ?, \'news\', ?, ?, 0.60, ?)');
        foreach ($newsCompanies as $nc) {
            $items = obe_news_items((string)$nc['name'], $newsSignals);
            if ($items === null) {
                continue;
            }
            $summary['news_checked']++;
            foreach ($items as $item) {
                foreach ($newsSignals as $signal) {
                    $freshDays = (int)($signal['freshness_days'] ?? 30);
                    if ($item['ts'] < time() - $freshDays * 86400) {
                        continue;
                    }
                    $text = strtolower($item['title'] . ' '
                                       . $item['summary']);
                    $keywords = obe_json_field(
                        $signal['keywords_json'] ?? null);
                    $hit = false;
                    foreach ($keywords as $kw) {
                        $kw = strtolower(trim((string)$kw));
                        if ($kw !== ''
                            && strpos($text, $kw) !== false) {
                            $hit = true;
                            break;
                        }
                    }
                    if (!$hit) {
                        continue;
                    }
                    $obsExists->execute([(int)$nc['id'],
                                         (int)$signal['id'],
                                         $item['link']]);
                    if ($obsExists->fetch(PDO::FETCH_ASSOC) !== false) {
                        continue;
                    }
                    $newsObsInsert->execute([
                        $clientId, (int)$nc['id'], (int)$signal['id'],
                        $item['link'], $item['title'],
                        date('Y-m-d H:i:s', $item['ts'])]);
                    $summary['new_observations']++;
                    $summary['news_observations']++;
                }
            }
        }
    }

    // Every company with at least one observation gets (re)scored.
    $stmt = $pdo->prepare(
        'SELECT DISTINCT company_id FROM obe_signal_observations '
        . 'WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $signalCompanyIds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $signalCompanyIds[] = (int)$r['company_id'];
    }
    $obsStmt = $pdo->prepare(
        'SELECT o.*, s.importance, s.freshness_days '
        . 'FROM obe_signal_observations o '
        . 'JOIN obe_intent_signals s ON s.id = o.signal_id '
        . 'WHERE o.company_id = ?');
    $companyStmt = $pdo->prepare(
        'SELECT * FROM obe_companies WHERE id = ?');
    $contactStmt = $pdo->prepare(
        'SELECT * FROM obe_contacts WHERE company_id = ? ORDER BY id');

    foreach ($signalCompanyIds as $companyId) {
        $companyStmt->execute([$companyId]);
        $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
        if ($company === false) {
            continue;
        }
        $obsStmt->execute([$companyId]);
        $observations = $obsStmt->fetchAll(PDO::FETCH_ASSOC);
        $contactStmt->execute([$companyId]);
        $contacts = $contactStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($contacts) === 0) {
            $scored = obe_score_match($company, null, $filters, $weights,
                                      $observations);
            $status = obe_upsert_match($pdo, $clientId, $companyId, null,
                                       $icpId, $scored['score'],
                                       $scored['breakdown']);
            $summary['matches_scored']++;
            if ($status === 'queued_enrich') {
                $summary['queued']++;
            }
            continue;
        }
        foreach ($contacts as $contact) {
            $scored = obe_score_match($company, $contact, $filters,
                                      $weights, $observations);
            $status = obe_upsert_match($pdo, $clientId, $companyId,
                                       (int)$contact['id'], $icpId,
                                       $scored['score'],
                                       $scored['breakdown']);
            $summary['matches_scored']++;
            if ($status === 'queued_enrich') {
                $summary['queued']++;
            }
        }
    }

    obe_log_run($pdo, $clientId, 'discovery',
                'Discovery run: ' . $summary['companies_checked']
                . ' companies checked, ' . $summary['jobs_seen']
                . ' jobs seen, ' . $summary['news_observations']
                . ' news observations, ' . $summary['new_observations']
                . ' new observations, ' . $summary['matches_scored']
                . ' matches scored, ' . $summary['queued'] . ' queued.',
                $summary);
    return $summary;
}

// --- gate ---------------------------------------------------------------------------

if (!defined('OBE_NO_AUTH')) {
    require_api_auth($pdo);
}
obe_install($pdo);
