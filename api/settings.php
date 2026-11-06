<?php
/**
 * Settings endpoint (Update 4) — key-value settings from
 * lib/settings.php (migration-003), plus the workspace data wipe.
 *
 *   GET  api/settings.php?workspace_id=N
 *        -> {settings_ready, notify_email, notify_on_fail,
 *            notify_on_kill, learnings_page_id,
 *            use_learnings_in_prompt, cron_token,
 *            notion_configured}
 *        The cron_token is returned in full on purpose: it is the
 *        operator's own secret, shown in his own Settings so he can
 *        build the hPanel cron URL. API keys are never handled here.
 *   POST api/settings.php {action: "save", workspace_id, notify_email,
 *        notify_on_fail, notify_on_kill, learnings_page_id,
 *        use_learnings_in_prompt} -> saves the workspace settings.
 *   POST api/settings.php {action: "test_email", workspace_id}
 *        -> sends a test notification through the panel mailer and
 *           logs the attempt to the ledger (provider 'mail').
 *   POST api/settings.php {action: "push_learnings", workspace_id}
 *        -> retries every unsynced learnings row against Notion.
 *   POST api/settings.php {action: "regenerate_cron_token"}
 *        -> replaces the panel-global evaluation cron token.
 *   POST api/settings.php {action: "clear_data", workspace_id,
 *        confirm: true}
 *        -> THE WIPE. Deletes, for this workspace only: learnings,
 *           verdicts, performance, creatives (+ uploads/ image files,
 *           best-effort), generation_batches, experiments. Never
 *           touches: the workspace row, brand tokens, rules, the
 *           guideline doc, settings, API keys, routing, the ledger,
 *           or the admin. Refuses without confirm:true. This is also
 *           how pre-Update-4 installs purge their seeded demo rows.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/settings.php';
require_once __DIR__ . '/../lib/google_ads.php';
require_once __DIR__ . '/../lib/evaluate.php';
require_api_auth($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $wsid = resolve_workspace($pdo);
    if ($wsid === null) {
        json_out(['error' => 'workspace not found'], 404);
    }
    json_out([
        'settings_ready' => settings_ready($pdo),
        'workspace_id' => $wsid,
        'notify_email' => (string)get_setting(
            $pdo, $wsid, 'notify_email', ''),
        'notify_on_fail' => get_setting_bool(
            $pdo, $wsid, 'notify_on_fail', true),
        'notify_on_kill' => get_setting_bool(
            $pdo, $wsid, 'notify_on_kill', true),
        'learnings_page_id' => (string)get_setting(
            $pdo, $wsid, 'learnings_page_id', ''),
        'use_learnings_in_prompt' => learnings_in_prompt_enabled(
            $pdo, $wsid),
        'cron_token' => (string)get_setting($pdo, 0, 'cron_token', ''),
        'notion_configured' => vault_key($pdo, 'notion') !== null,
        'display_guardrails' => gads_display_guardrails($pdo, $wsid),
        'display_guardrail_labels' => array_map(
            function ($type, $meta) {
                return ['type' => $type, 'label' => $meta[0],
                        'default' => $meta[1]];
            },
            array_keys(gads_content_label_catalog()),
            array_values(gads_content_label_catalog())),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method not allowed'], 405);
}

$data = read_json_body();
$action = (string)($data['action'] ?? '');

if ($action === 'regenerate_cron_token') {
    if (!settings_ready($pdo)) {
        migration_required('Settings');
    }
    $token = bin2hex(random_bytes(16));
    set_setting($pdo, 0, 'cron_token', $token);
    json_out(['ok' => true, 'cron_token' => $token]);
}

$wsid = resolve_workspace($pdo, $data);
if ($wsid === null) {
    json_out(['error' => 'workspace not found'], 404);
}

if ($action === 'save') {
    if (!settings_ready($pdo)) {
        migration_required('Settings');
    }
    $email = trim((string)($data['notify_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['error' => 'notify_email is not a valid email '
                          . 'address'], 400);
    }
    set_setting($pdo, $wsid, 'notify_email', $email);
    set_setting($pdo, $wsid, 'notify_on_fail',
                !empty($data['notify_on_fail']) ? '1' : '0');
    set_setting($pdo, $wsid, 'notify_on_kill',
                !empty($data['notify_on_kill']) ? '1' : '0');
    set_setting($pdo, $wsid, 'learnings_page_id',
                trim((string)($data['learnings_page_id'] ?? '')));
    set_setting($pdo, $wsid, 'use_learnings_in_prompt',
                !empty($data['use_learnings_in_prompt']) ? '1' : '0');
    json_out(['ok' => true]);
}

if ($action === 'save_display_guardrails') {
    if (!settings_ready($pdo)) {
        migration_required('Settings');
    }
    $clean = gads_guardrails_sanitize([
        'labels' => $data['labels'] ?? null,
        'exclude_apps' => $data['exclude_apps'] ?? null,
        'placements' => $data['placements'] ?? [],
    ]);
    set_setting($pdo, $wsid, 'display_guardrails',
                json_encode($clean));
    json_out(['ok' => true, 'display_guardrails' => $clean]);
}

if ($action === 'test_email') {
    $notify = notify_settings($pdo, $wsid);
    if ($notify['email'] === '') {
        json_out(['error' => 'Set a notification email first, then '
                          . 'send the test.'], 400);
    }
    $msg = [
        'to' => $notify['email'],
        'subject' => '[Creative Panel] Test notification',
        'body' => "This is a test from your Creative Testing control "
            . "panel.\nIf you received it, fail/kill notifications "
            . "for this workspace will reach you at this address.\n\n"
            . 'Panel: ' . APP_BASE_PATH . '/index.php',
    ];
    $send = panel_mailer(null);
    $okSend = false;
    try {
        $okSend = (bool)$send($msg);
    } catch (Throwable $e) {
        $okSend = false;
    }
    log_ledger($pdo, $wsid, 'notify', 'mail', null,
               $okSend ? 'ok' : 'error',
               'test email to ' . $notify['email']);
    json_out(['ok' => $okSend,
              'error' => $okSend ? null
                  : 'mail() reported failure — the host may not have '
                  . 'outbound mail configured. The attempt was logged '
                  . 'in the ledger.']);
}

if ($action === 'push_learnings') {
    if (!panel_try_column($pdo, 'learnings', 'notion_synced')) {
        migration_required('Learnings sync');
    }
    $res = notion_sync_pending_learnings($pdo, $wsid);
    $res['ok'] = true;
    $res['notion_configured'] = vault_key($pdo, 'notion') !== null
        && trim((string)get_setting(
            $pdo, $wsid, 'learnings_page_id', '')) !== '';
    json_out($res);
}

if ($action === 'clear_data') {
    if (empty($data['confirm'])) {
        json_out(['error' => 'confirmation required',
                  'hint' => 'Clear all data is destructive. Re-send '
                          . 'with confirm:true only after the '
                          . 'operator confirms. Nothing was deleted.'],
                 400);
    }
    // Image paths first (the rows are about to go), unlinked after
    // the transaction commits — best-effort, uploads/-confined.
    $imgStmt = $pdo->prepare(
        'SELECT image_path FROM creatives WHERE workspace_id = ? '
        . 'AND image_path IS NOT NULL AND image_path <> \'\'');
    $imgStmt->execute([$wsid]);
    $imagePaths = $imgStmt->fetchAll(PDO::FETCH_COLUMN);

    $counts = [];
    $pdo->beginTransaction();
    try {
        $del = function (string $sql) use ($pdo, $wsid, &$counts) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$wsid]);
            return $stmt->rowCount();
        };
        $counts['learnings'] = $del(
            'DELETE FROM learnings WHERE workspace_id = ?');
        $counts['verdicts'] = $del(
            'DELETE FROM verdicts WHERE workspace_id = ?');
        $counts['performance'] = $del(
            'DELETE FROM performance WHERE workspace_id = ?');
        $counts['creatives'] = $del(
            'DELETE FROM creatives WHERE workspace_id = ?');
        $counts['generation_batches'] = $del(
            'DELETE FROM generation_batches WHERE workspace_id = ?');
        $counts['experiments'] = $del(
            'DELETE FROM experiments WHERE workspace_id = ?');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_out(['error' => 'clear failed: ' . $e->getMessage()], 500);
    }
    $unlinked = 0;
    foreach ($imagePaths as $p) {
        if (panel_unlink_upload(is_string($p) ? $p : null)) {
            $unlinked++;
        }
    }
    $counts['images_unlinked'] = $unlinked;
    json_out(['ok' => true, 'action' => 'clear_data',
              'workspace_id' => $wsid, 'deleted' => $counts,
              'preserved' => 'workspace, brand tokens, rules, '
                  . 'guideline doc, settings, API keys, routing, '
                  . 'call ledger, admin']);
}

json_out(['error' => 'unknown action'], 400);
