<?php
/**
 * Google Ads integration (Update 11.1): connect + push for
 * Responsive Display Ads.
 *
 * Credential model, matching the panel's BYOK posture:
 *   - API ACCESS lives on the operator's Google Cloud project:
 *     Google retired the developer token on 2026-09-09; access
 *     levels attach to the project behind the OAuth client and
 *     the old token header is ignored by the API. A legacy token
 *     may still sit in the vault as 'google_ads_dev_token'; it is
 *     sent only when present and is never required.
 *   - The OAUTH CLIENT (id + secret) is the operator's Google
 *     Cloud project, vault providers 'google_oauth_client_id' and
 *     'google_oauth_client_secret'.
 *   - Each WORKSPACE connects its own Google Ads account through
 *     Google sign-in; the resulting refresh token is stored per
 *     workspace in workspace_google_ads and never leaves the
 *     server except to Google's token endpoint.
 *
 * Push posture: a push creates a PAUSED campaign (budget, campaign,
 * ad group, ad — everything paused) from the creative's display
 * packet (lib/display.php). Zero spend is possible until a human
 * enables something inside Google Ads. Pushes are recorded in
 * creative_google_ads_pushes and ride the creative on read.
 *
 * OAuth state is an HMAC over the workspace id keyed by the OAuth
 * client secret, so the callback needs no server-side session
 * state (the panel gateway can rotate session ids between the
 * connect click and Google's redirect back).
 *
 * API version is one constant: Google retires versions roughly
 * yearly; bump GADS_API_VERSION when the API starts refusing it.
 * v21 stopped working in fall 2026; v26 is current as of
 * November 2026.
 */

const GADS_API_VERSION = 'v26';
const GADS_API_BASE = 'https://googleads.googleapis.com';
const GADS_SCOPE = 'https://www.googleapis.com/auth/adwords';

require_once __DIR__ . '/settings.php';

function gads_install(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workspace_google_ads ('
        . 'workspace_id INT PRIMARY KEY, '
        . 'refresh_token TEXT NOT NULL, '
        . 'customer_id VARCHAR(20) NULL, '
        . 'login_customer_id VARCHAR(20) NULL, '
        . 'default_final_url VARCHAR(1024) NULL, '
        . 'connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, '
        . 'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP '
        . 'ON UPDATE CURRENT_TIMESTAMP) '
        . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS creative_google_ads_pushes ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, '
        . 'creative_id INT NOT NULL, '
        . 'workspace_id INT NOT NULL, '
        . 'customer_id VARCHAR(20) NOT NULL, '
        . 'campaign_resource VARCHAR(255) NULL, '
        . 'ad_resource VARCHAR(255) NULL, '
        . 'status VARCHAR(40) NOT NULL, '
        . 'final_url VARCHAR(1024) NULL, '
        . 'response_json MEDIUMTEXT NULL, '
        . 'pushed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) '
        . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Operator-level Google credentials from the vault. */
function gads_config(PDO $pdo): array {
    $dev = vault_key($pdo, 'google_ads_dev_token');
    $cid = vault_key($pdo, 'google_oauth_client_id');
    $secret = vault_key($pdo, 'google_oauth_client_secret');
    return [
        'dev_token' => $dev,
        'client_id' => $cid,
        'client_secret' => $secret,
        'dev_token_set' => $dev !== null && $dev !== '',
        'client_id_set' => $cid !== null && $cid !== '',
        'client_secret_set' => $secret !== null && $secret !== '',
        // Since 2026-09-09 the OAuth pair is all that is
        // required; the developer token is legacy, ignored.
        'ready' => $cid !== null && $cid !== ''
            && $secret !== null && $secret !== '',
    ];
}

/** The exact redirect URI to register in Google Cloud Console. */
function gads_redirect_uri(): string {
    $scheme = (!empty($_SERVER['HTTPS'])
               && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'builtbylt.com');
    $dir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api/x')),
                 '/');
    return $scheme . '://' . $host . $dir
        . '/google_ads_callback.php';
}

function gads_state(PDO $pdo, int $wsid): ?string {
    $cfg = gads_config($pdo);
    if (!$cfg['ready']) {
        return null;
    }
    $sig = hash_hmac('sha256', 'gads-ws-' . $wsid,
                     (string)$cfg['client_secret']);
    return $wsid . '.' . $sig;
}

function gads_state_workspace(PDO $pdo, string $state): ?int {
    $parts = explode('.', $state, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) {
        return null;
    }
    $wsid = (int)$parts[0];
    $expect = gads_state($pdo, $wsid);
    if ($expect === null || !hash_equals($expect, $state)) {
        return null;
    }
    return $wsid;
}

function gads_connect_url(PDO $pdo, int $wsid): ?string {
    $cfg = gads_config($pdo);
    $state = gads_state($pdo, $wsid);
    if (!$cfg['ready'] || $state === null) {
        return null;
    }
    return 'https://accounts.google.com/o/oauth2/v2/auth?'
        . http_build_query([
            'client_id' => $cfg['client_id'],
            'redirect_uri' => gads_redirect_uri(),
            'response_type' => 'code',
            'scope' => GADS_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
}

/** Raw HTTP helper: returns [status, decoded array|null, raw body]. */
function gads_http(string $url, string $method, array $headers,
                   ?string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Google transport error: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    return [$status, is_array($data) ? $data : null, (string)$raw];
}

/** Google's own error message from an error payload, verbatim. */
function gads_error_text(?array $data, string $raw, int $status): string {
    if (is_array($data) && isset($data['error']['message'])) {
        $msg = (string)$data['error']['message'];
        $details = $data['error']['details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $d) {
                $errs = $d['errors'] ?? null;
                if (is_array($errs)) {
                    foreach ($errs as $e) {
                        if (isset($e['message'])) {
                            $msg .= ' [' . (string)$e['message'] . ']';
                        }
                        if (isset($e['location']['fieldPathElements'])
                            && is_array($e['location']['fieldPathElements'])) {
                            $path = [];
                            foreach ($e['location']['fieldPathElements'] as $el) {
                                $path[] = (string)($el['fieldName'] ?? '?');
                            }
                            $msg .= ' (at ' . implode('.', $path) . ')';
                        }
                    }
                }
            }
        }
        return $msg;
    }
    return 'HTTP ' . $status . ': ' . substr($raw, 0, 300);
}

function gads_exchange_code(PDO $pdo, string $code): string {
    $cfg = gads_config($pdo);
    [$status, $data, $raw] = gads_http(
        'https://oauth2.googleapis.com/token', 'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'code' => $code,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => gads_redirect_uri(),
            'grant_type' => 'authorization_code',
        ]));
    if ($status < 200 || $status >= 300 || $data === null) {
        throw new RuntimeException('Google sign-in exchange failed: '
            . gads_error_text($data, $raw, $status));
    }
    $refresh = (string)($data['refresh_token'] ?? '');
    if ($refresh === '') {
        throw new RuntimeException('Google did not return a refresh '
            . 'token. The consent screen must grant offline access; '
            . 'try connecting again.');
    }
    return $refresh;
}

function gads_access_token(PDO $pdo, string $refreshToken): string {
    $cfg = gads_config($pdo);
    [$status, $data, $raw] = gads_http(
        'https://oauth2.googleapis.com/token', 'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'refresh_token' => $refreshToken,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'grant_type' => 'refresh_token',
        ]));
    if ($status < 200 || $status >= 300 || $data === null
        || empty($data['access_token'])) {
        throw new RuntimeException('Google token refresh failed: '
            . gads_error_text($data, $raw, $status));
    }
    return (string)$data['access_token'];
}

/** Connection row for a workspace (includes the refresh token —
 *  server-side use only; API responses must mask it). */
function gads_connection(PDO $pdo, int $wsid): ?array {
    gads_install($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM workspace_google_ads WHERE workspace_id = ?');
    $stmt->execute([$wsid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * One Google Ads API call for a connection. Throws RuntimeException
 * with Google's message on any failure.
 */
function gads_api(PDO $pdo, array $conn, string $path,
                  ?array $payload): array {
    $cfg = gads_config($pdo);
    if (!$cfg['ready']) {
        throw new RuntimeException('Google credentials are '
            . 'incomplete in the vault (OAuth client id and '
            . 'secret are required).');
    }
    $token = gads_access_token($pdo, (string)$conn['refresh_token']);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    if ($cfg['dev_token_set']) {
        // Legacy header: ignored by the API since 2026-09-09,
        // sent only when an old token still sits in the vault.
        $headers[] = 'developer-token: ' . $cfg['dev_token'];
    }
    if (!empty($conn['login_customer_id'])) {
        $headers[] = 'login-customer-id: '
            . preg_replace('/\D/', '', (string)$conn['login_customer_id']);
    }
    [$status, $data, $raw] = gads_http(
        GADS_API_BASE . '/' . GADS_API_VERSION . '/' . $path,
        $payload === null ? 'GET' : 'POST',
        $headers,
        $payload === null ? null : json_encode($payload));
    if ($status < 200 || $status >= 300 || $data === null) {
        throw new RuntimeException('Google Ads API error: '
            . gads_error_text($data, $raw, $status));
    }
    return $data;
}

/** Accounts the connected Google user can reach, with names. */
function gads_list_customers(PDO $pdo, array $conn): array {
    $data = gads_api($pdo, $conn, 'customers:listAccessibleCustomers',
                     null);
    $out = [];
    foreach ((array)($data['resourceNames'] ?? []) as $rn) {
        if (!preg_match('#customers/(\d+)#', (string)$rn, $m)) {
            continue;
        }
        $id = $m[1];
        $entry = ['id' => $id, 'name' => '', 'manager' => false,
                  'test' => false];
        try {
            $q = gads_api($pdo, $conn,
                'customers/' . $id . '/googleAds:search',
                ['query' => 'SELECT customer.id, '
                    . 'customer.descriptive_name, customer.manager, '
                    . 'customer.test_account FROM customer LIMIT 1']);
            $row = $q['results'][0]['customer'] ?? null;
            if (is_array($row)) {
                $entry['name'] = (string)($row['descriptiveName'] ?? '');
                $entry['manager'] = !empty($row['manager']);
                $entry['test'] = !empty($row['testAccount']);
            }
        } catch (Throwable $e) {
            // The id alone is still useful in the picker.
        }
        $out[] = $entry;
    }
    return $out;
}

/** Map the panel's CTA text to the button text Google accepts
 * in a Responsive Display Ad's call_to_action_text field, or null
 * (field omitted; Google picks). Despite the enum-sounding name,
 * that field is a plain display string in the API — the official
 * v26 RDA guide sets it with .setCallToActionText("Apply Now")
 * and points at the Help Center list of valid button texts.
 * Sending the enum token (SIGN_UP) is rejected with AdError
 * INVALID_CALL_TO_ACTION_TEXT, as the first headed push proved
 * live (creative #165, 2026-11-05). Only texts attested on
 * Google's button list are mapped; anything else is omitted. */
function gads_cta_text(string $cta): ?string {
    $map = [
        'learn more' => 'Learn More', 'sign up' => 'Sign Up',
        'shop now' => 'Shop Now', 'get quote' => 'Get Quote',
        'apply now' => 'Apply Now', 'contact us' => 'Contact Us',
        'subscribe' => 'Subscribe', 'download' => 'Download',
        'book now' => 'Book Now', 'see more' => 'See More',
    ];
    $k = strtolower(trim($cta));
    return $map[$k] ?? null;
}

/**
 * Create the paused Responsive Display campaign for a creative.
 * Returns the recorded push row. Throws RuntimeException with an
 * operator-readable message (Google's own words where Google is
 * the source) on any failure.
 */

/* ------------------------------------------------------------------
 * Display guardrails (Update 11.6): per-workspace placement safety,
 * applied to the connected Google Ads ACCOUNT as customer negative
 * criteria (content labels, placements, mobile app categories),
 * where they cover every campaign in the account. Campaign-level
 * content-label criteria are rejected by the API for the
 * suitability types (verified live 2026-11-06: CANNOT_EXCLUDE on
 * campaign_criterion), so pushes carry no criteria of their own;
 * the operator applies the guardrails to the account once (and
 * re-applies after changing them) from Settings.
 *
 * Stored as one JSON setting ('display_guardrails') per workspace:
 *   {labels: [ContentLabelType, ...], exclude_apps: bool,
 *    placements: [url, ...]}
 * Unset means the defaults below. The account-wide inventory type
 * (Maximum / Moderate / Limited) is NOT touched here: it lives in
 * Google Ads under Tools - Content suitability.
 * ---------------------------------------------------------------- */

/** Content label catalog: type => [operator label, on by default]. */
function gads_content_label_catalog(): array {
    return [
        'VIDEO_RATING_DV_MA' => ['Mature-audience rating (DV-MA)', true],
        'VIDEO_RATING_DV_T' => ['Teen rating (DV-T)', true],
        'VIDEO_NOT_YET_RATED' => ['Videos not yet rated', true],
        'SEXUALLY_SUGGESTIVE' => ['Sexually suggestive', true],
        'PROFANITY' => ['Profanity and rough language', true],
        'TRAGEDY' => ['Tragedy and conflict', true],
        'SOCIAL_ISSUES' => ['Sensitive social issues', true],
        'BRAND_SUITABILITY_POLITICS' => ['Politics (content theme)', true],
        'BRAND_SUITABILITY_RELIGION' => ['Religion (content theme)', true],
        'PARKED_DOMAIN' => ['Parked domains', true],
        'BELOW_THE_FOLD' => ['Below-the-fold placements', true],
        'LIVE_STREAMING_VIDEO' => ['Live-streaming video', true],
        'EMBEDDED_VIDEO' => ['Embedded videos', false],
        'VIDEO_RATING_DV_PG' => ['Parental-guidance rating (DV-PG)', false],
        'JUVENILE' => ['Juvenile content', false],
        'BRAND_SUITABILITY_GAMES_MATURE' =>
            ['Mature games (content theme)', false],
        'BRAND_SUITABILITY_GAMES_FIGHTING' =>
            ['Fighting games (content theme)', false],
        'BRAND_SUITABILITY_HEALTH_SENSITIVE' =>
            ['Sensitive health (content theme)', false],
        'BRAND_SUITABILITY_NEWS_SENSITIVE' =>
            ['Sensitive news (content theme)', false],
    ];
}

/**
 * Normalize a raw guardrails payload (decoded JSON setting or a
 * save request) against the catalog. Null / non-array input yields
 * the defaults; an explicit empty labels array is respected.
 */
function gads_guardrails_sanitize($raw): array {
    $cat = gads_content_label_catalog();
    $labels = [];
    $in = is_array($raw) ? ($raw['labels'] ?? null) : null;
    if (is_array($in)) {
        foreach ($in as $type) {
            $type = (string)$type;
            if (isset($cat[$type]) && !in_array($type, $labels, true)) {
                $labels[] = $type;
            }
        }
    } else {
        foreach ($cat as $type => $meta) {
            if ($meta[1]) {
                $labels[] = $type;
            }
        }
    }
    $apps = is_array($raw) && array_key_exists('exclude_apps', $raw)
        ? !empty($raw['exclude_apps']) : true;
    $placements = [];
    $pin = is_array($raw) ? ($raw['placements'] ?? []) : [];
    if (is_string($pin)) {
        $pin = preg_split('/\r?\n/', $pin);
    }
    if (is_array($pin)) {
        foreach ($pin as $pl) {
            $pl = trim((string)$pl);
            $pl = preg_replace('#^https?://#i', '', $pl);
            $pl = rtrim((string)$pl, '/');
            if ($pl === '' || preg_match('/\s/', $pl)
                || strlen($pl) > 190) {
                continue;
            }
            if (!in_array($pl, $placements, true)) {
                $placements[] = $pl;
            }
            if (count($placements) >= 200) {
                break;
            }
        }
    }
    return ['labels' => $labels, 'exclude_apps' => $apps,
            'placements' => $placements];
}

/** Effective guardrails for a workspace (defaults when unset). */
function gads_display_guardrails(PDO $pdo, int $wsid): array {
    $raw = get_setting($pdo, $wsid, 'display_guardrails');
    if ($raw === null || trim((string)$raw) === '') {
        return gads_guardrails_sanitize(null);
    }
    $dec = json_decode((string)$raw, true);
    return gads_guardrails_sanitize(is_array($dec) ? $dec : null);
}

/** One-line summary for the push preview and the ledger note. */
function gads_guardrails_summary(array $g): string {
    $n = count($g['labels']);
    $s = $n . ' content label' . ($n === 1 ? '' : 's') . ' excluded';
    if (!empty($g['exclude_apps'])) {
        $s .= ', mobile apps excluded';
    }
    $m = count($g['placements']);
    $s .= ', ' . $m . ' placement' . ($m === 1 ? '' : 's') . ' excluded';
    return $s;
}

/**
 * Every mobile app category constant in the account, as resource
 * names — excluding the categories excludes app inventory as a
 * class. Category constants are account-independent reference
 * data; the search runs against the connected customer.
 */
function gads_app_category_resources(PDO $pdo, array $conn,
                                     string $cust): array {
    $data = gads_api($pdo, $conn, $cust . '/googleAds:search', [
        'query' => 'SELECT mobile_app_category_constant.resource_name '
            . 'FROM mobile_app_category_constant',
    ]);
    $out = [];
    foreach ((array)($data['results'] ?? []) as $r) {
        $rn = $r['mobileAppCategoryConstant']['resourceName'] ?? null;
        if (is_string($rn) && $rn !== '') {
            $out[] = $rn;
        }
    }
    return $out;
}


/**
 * What already sits on the account as customer negative criteria:
 * label types, placement urls and app-category resource names.
 */
function gads_customer_negative_existing(PDO $pdo, array $conn,
                                         string $cust): array {
    $data = gads_api($pdo, $conn, $cust . '/googleAds:search', [
        'query' => 'SELECT customer_negative_criterion.type, '
            . 'customer_negative_criterion.content_label.type, '
            . 'customer_negative_criterion.placement.url, '
            . 'customer_negative_criterion.mobile_app_category'
            . '.mobile_app_category_constant '
            . 'FROM customer_negative_criterion',
    ]);
    $labels = [];
    $placements = [];
    $apps = [];
    foreach ((array)($data['results'] ?? []) as $r) {
        $c = $r['customerNegativeCriterion'] ?? [];
        if (!empty($c['contentLabel']['type'])) {
            $labels[] = (string)$c['contentLabel']['type'];
        }
        if (!empty($c['placement']['url'])) {
            $placements[] = (string)$c['placement']['url'];
        }
        if (!empty($c['mobileAppCategory']
                   ['mobileAppCategoryConstant'])) {
            $apps[] = (string)$c['mobileAppCategory']
                ['mobileAppCategoryConstant'];
        }
    }
    return ['labels' => $labels, 'placements' => $placements,
            'app_categories' => $apps];
}

/**
 * Coverage of the workspace's guardrail setting on its account:
 * how many of the wanted labels / placements / app categories are
 * already excluded at account level. Throws on API failure; the
 * callers decide how to present an unreadable account.
 */
function gads_guardrails_status(PDO $pdo, array $conn,
                                int $wsid): array {
    $customerId = preg_replace('/\D/', '',
        (string)($conn['customer_id'] ?? ''));
    $cust = 'customers/' . $customerId;
    $want = gads_display_guardrails($pdo, $wsid);
    $have = gads_customer_negative_existing($pdo, $conn, $cust);
    $labelsOn = count(array_intersect($want['labels'],
                                      $have['labels']));
    $placesOn = count(array_intersect($want['placements'],
                                      $have['placements']));
    $appsOn = !$want['exclude_apps'] ? null
        : count($have['app_categories']);
    $text = $labelsOn . '/' . count($want['labels'])
        . ' content labels, ' . $placesOn . '/'
        . count($want['placements']) . ' placements';
    if ($appsOn !== null) {
        $text .= ', ' . $appsOn . ' app categories';
    }
    $text .= ' excluded on this account';
    $complete = $labelsOn === count($want['labels'])
        && $placesOn === count($want['placements'])
        && ($appsOn === null || $appsOn > 0);
    return ['labels_on' => $labelsOn,
            'labels_wanted' => count($want['labels']),
            'placements_on' => $placesOn,
            'placements_wanted' => count($want['placements']),
            'app_categories_on' => $appsOn,
            'complete' => $complete, 'text' => $text];
}

/**
 * Apply the workspace's guardrails to its connected account:
 * creates only the customer negative criteria that are missing
 * (criteria are immutable; nothing is removed). Ledgered as
 * google_ads_guardrails. Returns the created counts.
 */
function gads_apply_guardrails(PDO $pdo, array $conn,
                               int $wsid): array {
    $customerId = preg_replace('/\D/', '',
        (string)($conn['customer_id'] ?? ''));
    if ($customerId === '') {
        throw new RuntimeException('No Google Ads account is '
            . 'selected for this workspace yet.');
    }
    $cust = 'customers/' . $customerId;
    $want = gads_display_guardrails($pdo, $wsid);
    $have = gads_customer_negative_existing($pdo, $conn, $cust);
    $ops = [];
    $made = ['labels' => 0, 'placements' => 0, 'app_categories' => 0];
    foreach ($want['labels'] as $type) {
        if (!in_array($type, $have['labels'], true)) {
            $ops[] = ['create' => ['contentLabel' => ['type' => $type]]];
            $made['labels']++;
        }
    }
    foreach ($want['placements'] as $url) {
        if (!in_array($url, $have['placements'], true)) {
            $ops[] = ['create' => ['placement' => ['url' => $url]]];
            $made['placements']++;
        }
    }
    if ($want['exclude_apps'] && $have['app_categories'] === []) {
        foreach (gads_app_category_resources($pdo, $conn, $cust)
                 as $rn) {
            $ops[] = ['create' => ['mobileAppCategory' => [
                'mobileAppCategoryConstant' => $rn]]];
            $made['app_categories']++;
        }
    }
    if ($ops !== []) {
        try {
            gads_api($pdo, $conn,
                     $cust . '/customerNegativeCriteria:mutate',
                     ['operations' => $ops]);
        } catch (Throwable $e) {
            log_ledger($pdo, $wsid, 'google_ads_guardrails', 'google',
                       GADS_API_VERSION, 'error',
                       substr('apply failed: ' . $e->getMessage(),
                              0, 240));
            throw $e;
        }
    }
    log_ledger($pdo, $wsid, 'google_ads_guardrails', 'google',
               GADS_API_VERSION, 'ok',
               substr('account ' . $customerId . ': created '
                   . $made['labels'] . ' labels, '
                   . $made['placements'] . ' placements, '
                   . $made['app_categories'] . ' app categories '
                   . '(already on file: '
                   . count($have['labels']) . ' labels, '
                   . count($have['placements']) . ' placements, '
                   . count($have['app_categories'])
                   . ' app categories)', 0, 240));
    return $made;
}

function gads_push_creative(PDO $pdo, array $creative, array $conn,
                            string $finalUrl): array {
    $wsid = (int)$creative['workspace_id'];
    $cid = (int)$creative['id'];
    $customerId = preg_replace('/\D/', '',
                               (string)($conn['customer_id'] ?? ''));
    if ($customerId === '') {
        throw new RuntimeException('No Google Ads account is '
            . 'selected for this workspace yet. Pick one in '
            . 'Settings first.');
    }
    if (!preg_match('#^https?://#i', $finalUrl)) {
        throw new RuntimeException('A landing page URL starting '
            . 'with http:// or https:// is required.');
    }
    $spec = display_spec($pdo, $creative);
    if (!$spec['copy_ok']) {
        $bad = [];
        foreach ($spec['checks'] as $c) {
            if (!$c['ok']) {
                $bad[] = $c['field'] . ' (' . $c['chars'] . '/'
                    . $c['max'] . ' chars)';
            }
        }
        throw new RuntimeException('Copy is over Google limits: '
            . implode(', ', $bad) . '. Shorten those fields first; '
            . 'nothing was pushed.');
    }
    if (empty($creative['image_path'])) {
        throw new RuntimeException('This creative has no image yet; '
            . 'a display ad needs at least the square asset.');
    }
    // The square slot must be a true 1:1 file: a square master
    // goes up untouched; any other aspect (portrait masters)
    // goes up as the derived 1024x1024 rendition. Uploading the
    // raw portrait here is what Google rejected on 2026-11-06.
    $sq = display_square_bytes($pdo, $creative);
    if (!$sq['ok']) {
        throw new RuntimeException((string)$sq['error']);
    }
    $squareBytes = $sq['bytes'];
    $land = display_landscape_bytes($pdo, $creative);
    if (!$land['ok']) {
        throw new RuntimeException((string)$land['error']);
    }

    $cust = 'customers/' . $customerId;
    $budgetRes = $cust . '/campaignBudgets/-1';
    $campaignRes = $cust . '/campaigns/-2';
    $adGroupRes = $cust . '/adGroups/-3';
    $landRes = $cust . '/assets/-4';
    $squareRes = $cust . '/assets/-5';
    $stamp = date('Y-m-d H:i');

    $rda = [
        'businessName' => $spec['business_name'],
        'headlines' => array_map(function ($h) {
            return ['text' => $h];
        }, $spec['headlines']),
        'longHeadline' => ['text' => $spec['long_headline']],
        'descriptions' => array_map(function ($d) {
            return ['text' => $d];
        }, $spec['descriptions']),
        'marketingImages' => [['asset' => $landRes]],
        'squareMarketingImages' => [['asset' => $squareRes]],
    ];
    $ctaText = gads_cta_text((string)$spec['cta']);
    if ($ctaText !== null) {
        $rda['callToActionText'] = $ctaText;
    }

    $ops = [
        ['campaignBudgetOperation' => ['create' => [
            'resourceName' => $budgetRes,
            'name' => 'Panel RDA budget — creative #' . $cid
                . ' — ' . $stamp,
            'amountMicros' => '1000000',
            'deliveryMethod' => 'STANDARD',
            'explicitlyShared' => false,
        ]]],
        ['campaignOperation' => ['create' => [
            'resourceName' => $campaignRes,
            'name' => 'Panel RDA — ' . $spec['business_name']
                . ' — creative #' . $cid . ' — ' . $stamp,
            'status' => 'PAUSED',
            'advertisingChannelType' => 'DISPLAY',
            'campaignBudget' => $budgetRes,
            'containsEuPoliticalAdvertising' =>
                'DOES_NOT_CONTAIN_EU_POLITICAL_ADVERTISING',
            'networkSettings' => [
                'targetGoogleSearch' => false,
                'targetSearchNetwork' => false,
                'targetContentNetwork' => true,
                'targetPartnerSearchNetwork' => false,
            ],
            'maximizeConversions' => new stdClass(),
        ]]],
        ['adGroupOperation' => ['create' => [
            'resourceName' => $adGroupRes,
            'name' => 'Panel RDA — creative #' . $cid,
            'campaign' => $campaignRes,
            'status' => 'PAUSED',
            'type' => 'DISPLAY_STANDARD',
        ]]],
        ['assetOperation' => ['create' => [
            'resourceName' => $landRes,
            'name' => 'creative-' . $cid . '-landscape-1200x628.jpg',
            'type' => 'IMAGE',
            'imageAsset' => ['data' => base64_encode($land['bytes'])],
        ]]],
        ['assetOperation' => ['create' => [
            'resourceName' => $squareRes,
            'name' => 'creative-' . $cid . '-square',
            'type' => 'IMAGE',
            'imageAsset' => ['data' => base64_encode($squareBytes)],
        ]]],
        ['adGroupAdOperation' => ['create' => [
            'adGroup' => $adGroupRes,
            'status' => 'PAUSED',
            'ad' => [
                'finalUrls' => [$finalUrl],
                'responsiveDisplayAd' => $rda,
            ],
        ]]],
    ];
    try {
        $resp = gads_api($pdo, $conn, $cust . '/googleAds:mutate',
                         ['mutateOperations' => $ops,
                          'partialFailure' => false]);
    } catch (Throwable $e) {
        log_ledger($pdo, $wsid, 'google_ads_push', 'google',
                   GADS_API_VERSION, 'error',
                   'creative #' . $cid . ': '
                   . substr($e->getMessage(), 0, 240));
        throw $e;
    }
    $campaignResource = null;
    $adResource = null;
    foreach ((array)($resp['mutateOperationResponses'] ?? []) as $r) {
        if (isset($r['campaignResult']['resourceName'])) {
            $campaignResource = (string)$r['campaignResult']['resourceName'];
        }
        if (isset($r['adGroupAdResult']['resourceName'])) {
            $adResource = (string)$r['adGroupAdResult']['resourceName'];
        }
    }
    $ins = $pdo->prepare(
        'INSERT INTO creative_google_ads_pushes (creative_id, '
        . 'workspace_id, customer_id, campaign_resource, '
        . 'ad_resource, status, final_url, response_json) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([$cid, $wsid, $customerId, $campaignResource,
                   $adResource, 'PAUSED', $finalUrl,
                   json_encode($resp)]);
    $pushId = (int)$pdo->lastInsertId();
    log_ledger($pdo, $wsid, 'google_ads_push', 'google',
               GADS_API_VERSION, 'ok',
               'creative #' . $cid . ': paused campaign '
               . (string)$campaignResource . ' in customer '
               . $customerId);
    $stmt = $pdo->prepare(
        'SELECT * FROM creative_google_ads_pushes WHERE id = ?');
    $stmt->execute([$pushId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? [] : $row;
}
