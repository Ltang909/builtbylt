<?php
/**
 * Google Ads connect + push endpoint (Update 11.1).
 *   GET  google_ads.php?action=status&workspace_id=N
 *   GET  google_ads.php?action=connect_url&workspace_id=N
 *   GET  google_ads.php?action=customers&workspace_id=N
 *   POST google_ads.php {action: "save_customer", workspace_id,
 *        customer_id, login_customer_id, default_final_url}
 *   POST google_ads.php {action: "disconnect", workspace_id}
 *   POST google_ads.php {action: "preview", creative_id}
 *   POST google_ads.php {action: "push", creative_id, final_url,
 *        confirm: true}
 *
 * The refresh token never appears in any response: status reports
 * only that a connection exists, plus the non-secret choices made
 * around it (customer id, manager id, default landing URL).
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/google_ads.php';
require_api_auth($pdo);

function gads_public_conn(?array $conn): ?array {
    if ($conn === null) {
        return null;
    }
    return [
        'connected' => true,
        'customer_id' => $conn['customer_id'],
        'login_customer_id' => $conn['login_customer_id'],
        'default_final_url' => $conn['default_final_url'],
        'connected_at' => $conn['connected_at'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? 'status');
    $wsid = (int)($_GET['workspace_id'] ?? 0);
    if ($action === 'status') {
        if ($wsid <= 0) {
            json_out(['error' => 'workspace_id required'], 400);
        }
        $cfg = gads_config($pdo);
        json_out([
            'configured' => [
                'dev_token' => $cfg['dev_token_set'],
                'client_id' => $cfg['client_id_set'],
                'client_secret' => $cfg['client_secret_set'],
                'ready' => $cfg['ready'],
            ],
            'redirect_uri' => gads_redirect_uri(),
            'connection' => gads_public_conn(
                gads_connection($pdo, $wsid)),
            'guardrails_status' => (function () use ($pdo, $wsid) {
                $conn = gads_connection($pdo, $wsid);
                if ($conn === null
                    || empty($conn['customer_id'])) {
                    return null;
                }
                try {
                    return gads_guardrails_status($pdo, $conn,
                                                  $wsid)['text'];
                } catch (Throwable $e) {
                    return null;
                }
            })(),
        ]);
    }
    if ($action === 'connect_url') {
        if ($wsid <= 0) {
            json_out(['error' => 'workspace_id required'], 400);
        }
        $url = gads_connect_url($pdo, $wsid);
        if ($url === null) {
            json_out(['error' => 'Google credentials are incomplete '
                . 'in the vault: OAuth client id and OAuth client '
                . 'secret must both be set first.'], 400);
        }
        json_out(['url' => $url]);
    }
    if ($action === 'customers') {
        if ($wsid <= 0) {
            json_out(['error' => 'workspace_id required'], 400);
        }
        $conn = gads_connection($pdo, $wsid);
        if ($conn === null) {
            json_out(['error' => 'not connected'], 400);
        }
        try {
            json_out(['customers' => gads_list_customers($pdo, $conn)]);
        } catch (Throwable $e) {
            json_out(['error' => $e->getMessage()], 502);
        }
    }
    json_out(['error' => 'unknown action'], 400);
}

if ($method !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = read_json_body();
$action = (string)($data['action'] ?? '');

if ($action === 'save_customer') {
    $wsid = (int)($data['workspace_id'] ?? 0);
    if ($wsid <= 0 || gads_connection($pdo, $wsid) === null) {
        json_out(['error' => 'workspace is not connected'], 400);
    }
    $customerId = preg_replace('/\D/', '',
        (string)($data['customer_id'] ?? ''));
    $loginId = preg_replace('/\D/', '',
        (string)($data['login_customer_id'] ?? ''));
    $url = trim((string)($data['default_final_url'] ?? ''));
    $pdo->prepare(
        'UPDATE workspace_google_ads SET customer_id = ?, '
        . 'login_customer_id = ?, default_final_url = ? '
        . 'WHERE workspace_id = ?')
        ->execute([$customerId !== '' ? $customerId : null,
                   $loginId !== '' ? $loginId : null,
                   $url !== '' ? $url : null, $wsid]);
    json_out(['ok' => true,
              'connection' => gads_public_conn(
                  gads_connection($pdo, $wsid))]);
}

if ($action === 'disconnect') {
    $wsid = (int)($data['workspace_id'] ?? 0);
    if ($wsid <= 0) {
        json_out(['error' => 'workspace_id required'], 400);
    }
    gads_install($pdo);
    $pdo->prepare('DELETE FROM workspace_google_ads '
                  . 'WHERE workspace_id = ?')->execute([$wsid]);
    json_out(['ok' => true, 'connection' => null]);
}

if ($action === 'apply_guardrails') {
    $wsid = (int)($data['workspace_id'] ?? 0);
    $conn = $wsid > 0 ? gads_connection($pdo, $wsid) : null;
    if ($conn === null || empty($conn['customer_id'])) {
        json_out(['error' => 'This workspace is not connected to '
            . 'a Google Ads account yet.'], 400);
    }
    try {
        $made = gads_apply_guardrails($pdo, $conn, $wsid);
        $status = gads_guardrails_status($pdo, $conn, $wsid);
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 502);
    }
    json_out(['ok' => true, 'created' => $made,
              'guardrails_status' => $status['text'],
              'complete' => $status['complete']]);
}

if ($action === 'preview' || $action === 'push') {
    $cid = (int)($data['creative_id'] ?? 0);
    $creative = $cid > 0 ? fetch_creative($pdo, $cid) : null;
    if ($creative === null) {
        json_out(['error' => 'creative not found'], 404);
    }
    $wsid = (int)$creative['workspace_id'];
    $conn = gads_connection($pdo, $wsid);
    $spec = display_spec($pdo, $creative);
    $problems = [];
    $cfg = gads_config($pdo);
    if (!$cfg['ready']) {
        $problems[] = 'Google credentials are incomplete in the '
            . 'vault (OAuth client id and secret).';
    }
    if ($conn === null) {
        $problems[] = 'This workspace is not connected to Google '
            . 'Ads yet (Settings → Google Ads).';
    } elseif (empty($conn['customer_id'])) {
        $problems[] = 'No Google Ads account is selected for this '
            . 'workspace yet (Settings → Google Ads).';
    }
    if (!$spec['copy_ok']) {
        foreach ($spec['checks'] as $c) {
            if (!$c['ok']) {
                $problems[] = $c['field'] . ' is ' . $c['chars']
                    . ' chars; Google caps it at ' . $c['max'] . '.';
            }
        }
    }
    if (empty($creative['image_path'])) {
        $problems[] = 'The creative has no image yet.';
    }
    if ($action === 'preview') {
        json_out([
            'ready' => $problems === [],
            'problems' => $problems,
            'spec' => $spec,
            'default_final_url' => $conn !== null
                ? $conn['default_final_url'] : null,
            'customer_id' => $conn !== null
                ? $conn['customer_id'] : null,
            'guardrails' => (function () use ($pdo, $conn, $wsid) {
                $want = gads_guardrails_summary(
                    gads_display_guardrails($pdo, $wsid));
                if ($conn === null
                    || empty($conn['customer_id'])) {
                    return $want . ' wanted; account not readable';
                }
                try {
                    $st = gads_guardrails_status($pdo, $conn, $wsid);
                    return ($st['complete']
                        ? 'on account: ' : 'PARTLY on account: ')
                        . $st['text'] . ' (setting wants: ' . $want
                        . ')';
                } catch (Throwable $e) {
                    return $want . ' wanted; account status '
                        . 'unreadable';
                }
            })(),
        ]);
    }
    // push
    if (empty($data['confirm'])) {
        json_out(['error' => 'confirmation required',
                  'hint' => 'Pushing creates a real (paused) '
                      . 'campaign in the connected Google Ads '
                      . 'account. Preview first, then confirm.'], 400);
    }
    if ($problems !== []) {
        json_out(['error' => 'not ready', 'problems' => $problems], 400);
    }
    $finalUrl = trim((string)($data['final_url'] ?? ''))
        ?: (string)($conn['default_final_url'] ?? '');
    try {
        $push = gads_push_creative($pdo, $creative, $conn, $finalUrl);
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 502);
    }
    json_out(['ok' => true, 'push' => [
        'id' => (int)$push['id'],
        'campaign_resource' => $push['campaign_resource'],
        'ad_resource' => $push['ad_resource'],
        'status' => $push['status'],
        'customer_id' => $push['customer_id'],
    ]]);
}

json_out(['error' => 'unknown action'], 400);
