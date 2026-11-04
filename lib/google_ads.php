<?php
/**
 * Google Ads integration (Update 11.1): connect + push for
 * Responsive Display Ads.
 *
 * Credential model, matching the panel's BYOK posture:
 *   - The DEVELOPER TOKEN is the operator's own, one per panel,
 *     from their Google Ads manager account's API Center. Stored
 *     in the vault as provider 'google_ads_dev_token'.
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
 */

const GADS_API_VERSION = 'v21';
const GADS_API_BASE = 'https://googleads.googleapis.com';
const GADS_SCOPE = 'https://www.googleapis.com/auth/adwords';

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
        'ready' => $dev !== null && $dev !== ''
            && $cid !== null && $cid !== ''
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
            . 'incomplete in the vault (developer token, OAuth '
            . 'client id and secret are all required).');
    }
    $token = gads_access_token($pdo, (string)$conn['refresh_token']);
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'developer-token: ' . $cfg['dev_token'],
    ];
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

/** Map the panel's CTA text to Google's RDA button enum, or null. */
function gads_cta_enum(string $cta): ?string {
    $map = [
        'learn more' => 'LEARN_MORE', 'sign up' => 'SIGN_UP',
        'shop now' => 'SHOP_NOW', 'get quote' => 'GET_QUOTE',
        'apply now' => 'APPLY_NOW', 'contact us' => 'CONTACT_US',
        'subscribe' => 'SUBSCRIBE', 'download' => 'DOWNLOAD',
        'book now' => 'BOOK_NOW', 'see more' => 'SEE_MORE',
        'start now' => 'START_NOW', 'visit site' => 'VISIT_SITE',
        'get started' => 'START_NOW',
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
    $srcAbs = PANEL_ROOT . '/' . (string)$creative['image_path'];
    $squareBytes = is_file($srcAbs) ? @file_get_contents($srcAbs) : false;
    if ($squareBytes === false) {
        throw new RuntimeException('The creative image file could '
            . 'not be read from uploads/.');
    }
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
    $ctaEnum = gads_cta_enum((string)$spec['cta']);
    if ($ctaEnum !== null) {
        $rda['callToActionText'] = $ctaEnum;
    }

    $ops = [
        ['campaignBudgetOperation' => ['create' => [
            'name' => 'Panel RDA budget — creative #' . $cid
                . ' — ' . $stamp,
            'amountMicros' => '1000000',
            'deliveryMethod' => 'STANDARD',
            'explicitlyShared' => false,
        ]]],
        ['campaignOperation' => ['create' => [
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
            'name' => 'Panel RDA — creative #' . $cid,
            'campaign' => $campaignRes,
            'status' => 'PAUSED',
            'type' => 'DISPLAY_STANDARD',
        ]]],
        ['assetOperation' => ['create' => [
            'name' => 'creative-' . $cid . '-landscape-1200x628.jpg',
            'type' => 'IMAGE',
            'imageAsset' => ['data' => base64_encode($land['bytes'])],
        ]]],
        ['assetOperation' => ['create' => [
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
