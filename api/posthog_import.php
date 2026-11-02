<?php
/**
 * PostHog native-source import endpoint (Update 9).
 *
 *   POST api/posthog_import.php {action: "status", workspace_id}
 *     -> {host, project_id, has_key, configured}
 *   POST ... {action: "save_config", workspace_id, host, project_id}
 *     -> stores host + project in workspace settings. The personal
 *        API key itself lives in the vault (Settings → API keys,
 *        provider "posthog") and is never accepted here.
 *   POST ... {action: "discover", workspace_id}
 *     -> warehouse tables + which Meta Ads roles were found.
 *   POST ... {action: "preview", workspace_id}
 *     -> the report rows the import would write. No writes.
 *   POST ... {action: "import", workspace_id, experiment_id}
 *     -> re-runs the report server-side (client rows are never
 *        trusted), then creates or refreshes one creative per ad
 *        and replaces that creative's posthog-sourced performance
 *        rows with the current totals. Re-importing is idempotent:
 *        dedupe is by the Meta ad id, stored in creatives
 *        .external_ref when migration 004 has run, otherwise in
 *        template_id. Images are fetched once, when the creative
 *        has none yet.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/../lib/posthog.php';
require_api_auth($pdo);

$data = read_json_body();
$action = (string)($data['action'] ?? 'status');
$wsid = resolve_workspace($pdo, $data);
if ($wsid === null) {
    json_out(['error' => 'workspace not found'], 404);
}

function ph_require_config(PDO $pdo, int $wsid): array {
    $cfg = ph_config($pdo, $wsid);
    if (empty($cfg['key']) || $cfg['project_id'] === '') {
        json_out(['error' => 'PostHog is not configured for this '
            . 'workspace. Add the personal API key in Settings → API '
            . 'keys (provider: posthog), then save the host and '
            . 'project ID in the Intake card.'], 400);
    }
    return $cfg;
}

function ph_report(PDO $pdo, int $wsid, string $platform,
                   ?string $sheetTable, ?string $dateFrom = null,
                   ?string $dateTo = null): array {
    $cfg = ph_require_config($pdo, $wsid);
    $tables = ph_discover_tables($cfg);

    if ($platform === 'sheet') {
        $target = null;
        foreach ($tables as $t) {
            if ($t['name'] === $sheetTable) {
                $target = $t;
                break;
            }
        }
        if ($target === null) {
            json_out(['error' => 'That sheet table is not in the '
                . 'project. Run Discover tables again.'], 400);
        }
        $map = ph_sheet_column_map($target['columns']);
        if (!ph_sheet_is_importable($map)) {
            json_out(['error' => 'That table does not look like a '
                . 'creative report: it needs a headline column and '
                . 'at least one of spend, impressions or primary '
                . 'text, using the same headers as the CSV '
                . 'template.'], 400);
        }
        $sql = ph_build_sheet_query($target['name'], $map);
        $rows = ph_run_query($cfg, $sql,
            fn($c, $r) => ph_normalize_sheet_row($c, $r, $target['name']));
        $notes = ['Rows come from a sheet table in PostHog. '
            . 'Whatever the sheet carries imports, including '
            . 'a signups column when it is present.'];
        return [$rows, $notes, [], $tables];
    }

    if ($platform !== 'meta') {
        $profiles = ph_profiles();
        if (!isset($profiles[$platform])) {
            json_out(['error' => 'Unknown channel.'], 400);
        }
        $profile = $profiles[$platform];
        $foundP = ph_find_platform_tables($tables, $profile);
        [$sqlP, $notesP] = ph_build_platform_query($foundP, $profile,
                                                   $dateFrom, $dateTo);
        if ($sqlP === '') {
            json_out(['ok' => false, 'status' => 'no_tables',
                      'message' => implode(' ', $notesP)
                          . ' Connect ' . $profile['label'] . ' under '
                          . 'Data pipelines in PostHog and enable its '
                          . 'stats and creative tables.',
                      'tables' => array_map(fn($t) => $t['name'],
                                            $tables)],
                     400);
        }
        $rowsP = ph_run_query($cfg, $sqlP,
            fn($c, $r) => ph_normalize_platform_row($c, $r, $profile));
        return [$rowsP, $notesP, $foundP, $tables];
    }

    $found = ph_find_meta_tables($tables);
    [$sql, $notes] = ph_build_report_query($found, $dateFrom, $dateTo);
    if ($sql === '') {
        json_out(['ok' => false, 'status' => 'no_tables',
                  'message' => implode(' ', $notes)
                      . ' Connect Meta Ads under Data pipelines in '
                      . 'PostHog and enable the ads and ad_stats '
                      . 'tables (plus ad_creatives for copy).',
                  'tables' => array_map(fn($t) => $t['name'], $tables)],
                 400);
    }
    $rows = ph_run_query($cfg, $sql);
    return [$rows, $notes, $found, $tables];
}

if ($action === 'status') {
    json_out(ph_config_status($pdo, $wsid));
}

if ($action === 'save_config') {
    $host = (string)($data['host'] ?? 'us.posthog.com');
    if (!in_array($host, POSTHOG_HOSTS, true)) {
        json_out(['error' => 'Unknown PostHog host.'], 400);
    }
    $project = preg_replace('/\D/', '', (string)($data['project_id'] ?? ''));
    set_setting($pdo, $wsid, 'posthog_host', $host);
    set_setting($pdo, $wsid, 'posthog_project_id', $project);
    json_out(ph_config_status($pdo, $wsid));
}

if ($action === 'discover') {
    $cfg = ph_require_config($pdo, $wsid);
    try {
        $tables = ph_discover_tables($cfg);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 502);
    }
    $found = ph_find_meta_tables($tables);
    $roles = [];
    foreach ($found as $role => $t) {
        $roles[$role] = $t !== null
            ? ['table' => $t['name'], 'columns' => count($t['columns'])]
            : null;
    }
    $platforms = ['meta' => ($found['stats'] !== null
                             && $found['ads'] !== null)];
    foreach (ph_profiles() as $key => $profile) {
        $f = ph_find_platform_tables($tables, $profile);
        $platforms[$key] = ($f['stats'] !== null
                            && $f['entity'] !== null);
    }
    $sheets = [];
    foreach ($tables as $t) {
        $map = ph_sheet_column_map($t['columns']);
        if (ph_sheet_is_importable($map)) {
            $sheets[] = ['table' => $t['name'],
                         'fields' => array_keys($map)];
        }
    }
    json_out(['tables' => array_map(
        fn($t) => ['name' => $t['name'],
                    'columns' => count($t['columns'])], $tables),
        'found' => $roles, 'platforms' => $platforms,
        'sheets' => $sheets]);
}

if ($action === 'preview') {
    $platform = (string)($data['platform'] ?? 'meta');
    $sheetTable = isset($data['table']) ? (string)$data['table'] : null;
    try {
        [$rows, $notes] = ph_report($pdo, $wsid, $platform, $sheetTable,
            isset($data['date_from']) ? (string)$data['date_from'] : null,
            isset($data['date_to']) ? (string)$data['date_to'] : null);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 502);
    }
    json_out(['rows' => $rows, 'notes' => $notes,
              'count' => count($rows)]);
}

// ---------- import ----------

if ($action === 'import') {
    $expId = isset($data['experiment_id']) && $data['experiment_id'] !== ''
        ? (int)$data['experiment_id'] : null;
    $platform = (string)($data['platform'] ?? 'meta');
    $sheetTable = isset($data['table']) ? (string)$data['table'] : null;
    try {
        [$rows, $notes] = ph_report($pdo, $wsid, $platform, $sheetTable,
            isset($data['date_from']) ? (string)$data['date_from'] : null,
            isset($data['date_to']) ? (string)$data['date_to'] : null);
    } catch (RuntimeException $e) {
        json_out(['error' => $e->getMessage()], 502);
    }

    $hasRef = $pdo->query("SHOW COLUMNS FROM creatives LIKE 'external_ref'")
        ->fetch() !== false;

    $findSql = $hasRef
        ? 'SELECT * FROM creatives WHERE workspace_id = ? AND external_ref = ?'
        : 'SELECT * FROM creatives WHERE workspace_id = ? AND template_id = ?';
    $find = $pdo->prepare($findSql);

    $channelLabels = ['meta' => 'Meta Ads', 'google' => 'Google Ads',
        'linkedin' => 'LinkedIn Ads', 'reddit' => 'Reddit Ads',
        'sheet' => 'Sheet import'];
    $fallbackEntity = $channelLabels[$platform] ?? 'PostHog import';
    $created = 0; $updated = 0; $imaged = 0; $skipped = 0;
    $ids = []; $imageNotes = [];
    foreach ($rows as $r) {
        if (empty($r['external_ref'])) {
            $skipped++;
            continue;
        }
        $entity = $r['campaign'] ?: ($r['account']
            ? 'Account ' . $r['account'] : $fallbackEntity);
        $find->execute([$wsid, $r['external_ref']]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $cid = (int)$existing['id'];
            $upd = $pdo->prepare(
                'UPDATE creatives SET headline = ?, primary_text = ?, '
                . 'description = ?, cta = ?, offer_text = ?, '
                . 'format = ?, game_name = ? WHERE id = ?');
            $upd->execute([
                $r['headline'] ?: $existing['headline'],
                $r['primary_text'] ?: $existing['primary_text'],
                ($r['description'] ?? null) ?: $existing['description'],
                $r['cta'] ?: $existing['cta'],
                ($r['offer_text'] ?? null) ?: $existing['offer_text'],
                ($r['format'] ?? null) ?: $existing['format'],
                $entity, $cid]);
            $updated++;
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO creatives (workspace_id, experiment_id, '
                . 'kind, game_name, headline, primary_text, '
                . 'description, cta, offer_text, format, '
                . ($hasRef ? 'external_ref' : 'template_id')
                . ', status) VALUES (?, ?, \'static\', ?, ?, ?, ?, ?, '
                . '?, ?, ?, \'generated\')');
            $ins->execute([$wsid, $expId, $entity, $r['headline'],
                           $r['primary_text'], $r['description'] ?? null,
                           $r['cta'], $r['offer_text'] ?? null,
                           $r['format'] ?? null, $r['external_ref']]);
            $cid = (int)$pdo->lastInsertId();
            $created++;
        }
        $ids[] = $cid;

        // Stats: replace this creative's posthog rows with the
        // current totals, so a re-import refreshes instead of
        // double-counting.
        $del = $pdo->prepare(
            'DELETE FROM performance WHERE creative_id = ? '
            . 'AND source = \'posthog\'');
        $del->execute([$cid]);
        $pins = $pdo->prepare(
            'INSERT INTO performance (workspace_id, creative_id, date, '
            . 'spend, impressions, clicks, signups, purchasers, '
            . 'source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '
            . '\'posthog\')');
        $pins->execute([$wsid, $cid, date('Y-m-d'), $r['spend'],
                        $r['impressions'], $r['clicks'],
                        $r['signups'] ?? null, $r['purchasers']]);

        // Image: fetch once, only when the creative has none.
        $cur = $pdo->prepare(
            'SELECT image_path FROM creatives WHERE id = ?');
        $cur->execute([$cid]);
        $haveImage = $cur->fetchColumn();
        if (!$haveImage && !empty($r['image_url'])) {
            $rel = ph_store_remote_image($pdo, $wsid, $cid,
                                         (string)$r['image_url']);
            if ($rel !== null) {
                $set = $pdo->prepare(
                    'UPDATE creatives SET image_path = ? WHERE id = ?');
                $set->execute([$rel, $cid]);
                $imaged++;
            } else {
                $imageNotes[] = "Creative #$cid: image fetch failed.";
            }
        }
    }
    json_out(['ok' => true, 'created' => $created,
              'updated' => $updated, 'imaged' => $imaged,
              'skipped' => $skipped, 'ids' => $ids,
              'notes' => array_merge($notes, $imageNotes)]);
}

json_out(['error' => 'unknown action'], 400);

/** Fetch a creative image from the ad platform CDN and store it
 *  under the panel uploads, mirroring the manual attach rules:
 *  sniffed bytes, png/jpg/webp only, 8 MB cap. Null on any failure. */
function ph_store_remote_image(PDO $pdo, int $wsid, int $cid,
                               string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 15,
    ]);
    $bytes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($bytes === false || $status < 200 || $status >= 300
        || strlen($bytes) > 8388608 || $bytes === '') {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($bytes);
    $extByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg',
                  'image/webp' => 'webp'];
    if (!isset($extByMime[$mime])) {
        return null;
    }
    $wsDir = PANEL_ROOT . '/uploads/ws' . $wsid;
    if (!is_dir($wsDir)) {
        mkdir($wsDir, 0775, true);
    }
    $name = $cid . '_meta.' . $extByMime[$mime];
    if (file_put_contents($wsDir . '/' . $name, $bytes) === false) {
        return null;
    }
    return 'uploads/ws' . $wsid . '/' . $name;
}
