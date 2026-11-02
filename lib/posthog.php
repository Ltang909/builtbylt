<?php
/**
 * PostHog native-source import helpers (Update 9).
 *
 * The client connects their own ad account inside their own
 * PostHog (Data pipelines → Meta Ads) and enables the creative
 * level tables. This library discovers those warehouse tables,
 * builds the HogQL report that joins them, and normalizes the
 * rows into the panel's creative + stats shape.
 *
 * Pure functions (find_meta_tables, build_report_query,
 * normalize_report_row) carry no I/O so the CLI tests can pin
 * them. The HTTP + config functions sit below them.
 */

const POSTHOG_HOSTS = ['us.posthog.com', 'eu.posthog.com', 'app.posthog.com'];

/** Pick the best table for a role by name, suffix match first. */
function ph_pick_table(array $tables, array $candidates): ?array {
    foreach ($candidates as $cand) {
        foreach ($tables as $t) {
            if (strtolower($t['name']) === $cand) {
                return $t;
            }
        }
        foreach ($tables as $t) {
            $suffix = '_' . $cand;
            if (substr(strtolower($t['name']), -strlen($suffix)) === $suffix) {
                return $t;
            }
        }
    }
    foreach ($candidates as $cand) {
        foreach ($tables as $t) {
            if (strpos(strtolower($t['name']), $cand) !== false) {
                return $t;
            }
        }
    }
    return null;
}

/**
 * Find the Meta Ads source tables among discovered warehouse
 * tables. $tables: list of ['name' => string, 'columns' => [names]].
 * Returns role => table (or null): stats, ads, creatives, images,
 * campaigns.
 */
function ph_find_meta_tables(array $tables): array {
    $tables = ph_filter_tables_for($tables, 'meta');
    return [
        'stats' => ph_pick_table($tables, ['ad_stats', 'adstats']),
        'ads' => ph_pick_table($tables, ['ads']),
        'creatives' => ph_pick_table($tables, ['ad_creatives', 'adcreatives']),
        'images' => ph_pick_table($tables, ['ad_images', 'adimages']),
        'campaigns' => ph_pick_table($tables, ['campaigns']),
    ];
}

function ph_has_col(?array $table, string $col): bool {
    return $table !== null
        && in_array($col, $table['columns'] ?? [], true);
}

function ph_first_col(?array $table, array $cols): ?string {
    foreach ($cols as $c) {
        if (ph_has_col($table, $c)) {
            return $c;
        }
    }
    return null;
}

/**
 * Build the HogQL report over the discovered tables. Only joins
 * what exists; only selects columns that are actually there.
 * Returns [sql, notes] — notes names anything the sync does not
 * carry (conversions, creative copy) so the UI can say so.
 */
function ph_build_report_query(array $found, ?string $from = null,
                                 ?string $to = null): array {
    $stats = $found['stats'];
    $ads = $found['ads'];
    $cre = $found['creatives'] ?? null;
    $camp = $found['campaigns'] ?? null;
    if ($stats === null || $ads === null) {
        return ['', ['The ad_stats and ads tables are both required.']];
    }
    $notes = [];
    $s = '`' . $stats['name'] . '`';
    $a = '`' . $ads['name'] . '`';

    $adId = ph_first_col($stats, ['ad_id', 'id']);
    $adsId = ph_first_col($ads, ['id', 'ad_id']);
    if ($adId === null || $adsId === null) {
        return ['', ['Could not find the ad id columns to join on.']];
    }

    $sel = [];
    $grp = [];
    $sel[] = "s.$adId AS ad_id";
    $grp[] = "s.$adId";

    $adName = ph_first_col($ads, ['name', 'ad_name']);
    if ($adName !== null) {
        $sel[] = "a.$adName AS ad_name";
        $grp[] = "a.$adName";
    }
    $account = ph_first_col($stats, ['account_id'])
        ?: ph_first_col($ads, ['account_id']);
    if ($account !== null) {
        $owner = ph_has_col($stats, 'account_id') ? 's' : 'a';
        $sel[] = "toString($owner.$account) AS account";
        $grp[] = "toString($owner.$account)";
    }

    $joinCre = '';
    $creId = ph_first_col($ads, ['creative_id']);
    if ($cre !== null && $creId !== null
        && ph_first_col($cre, ['id', 'creative_id']) !== null) {
        $cId = ph_first_col($cre, ['id', 'creative_id']);
        $c = '`' . $cre['name'] . '`';
        $joinCre = " JOIN $c c ON a.$creId = c.$cId";
        $headline = ph_first_col($cre, ['title', 'headline', 'name']);
        if ($headline !== null) {
            $sel[] = "c.$headline AS headline";
            $grp[] = "c.$headline";
        }
        $body = ph_first_col($cre, ['body', 'message', 'primary_text', 'text']);
        if ($body !== null) {
            $sel[] = "c.$body AS primary_text";
            $grp[] = "c.$body";
        }
        $cta = ph_first_col($cre, ['call_to_action_type', 'cta', 'cta_type']);
        if ($cta !== null) {
            $sel[] = "c.$cta AS cta_raw";
            $grp[] = "c.$cta";
        }
        $img = ph_first_col($cre, ['image_url', 'thumbnail_url', 'image_link']);
        if ($img !== null) {
            $sel[] = "c.$img AS image_url";
            $grp[] = "c.$img";
        }
        $cname = ph_first_col($cre, ['name']);
        if ($cname !== null) {
            $sel[] = "c.$cname AS creative_name";
            $grp[] = "c.$cname";
        }
    } else {
        $notes[] = 'No ad_creatives table found, so the import carries '
            . 'ad names and numbers only, no copy.';
    }

    $joinCamp = '';
    $campId = ph_first_col($ads, ['campaign_id']);
    if ($camp !== null && $campId !== null
        && ph_first_col($camp, ['id', 'campaign_id']) !== null
        && ph_first_col($camp, ['name', 'campaign_name']) !== null) {
        $cpId = ph_first_col($camp, ['id', 'campaign_id']);
        $cpName = ph_first_col($camp, ['name', 'campaign_name']);
        $cp = '`' . $camp['name'] . '`';
        $joinCamp = " JOIN $cp cp ON a.$campId = cp.$cpId";
        $sel[] = "cp.$cpName AS campaign";
        $grp[] = "cp.$cpName";
    }

    foreach (['spend', 'impressions', 'clicks'] as $m) {
        $col = ph_first_col($stats, [$m]);
        $sel[] = $col !== null
            ? "round(sum(toFloat(s.$col)), 2) AS $m"
            : "0 AS $m";
    }
    $conv = ph_first_col($stats, ['conversions', 'conversion', 'results']);
    if ($conv !== null) {
        $sel[] = "sum(toInt(s.$conv)) AS conversions";
    } else {
        $notes[] = 'The sync carries no conversions column the panel '
            . 'can sum, so purchasers import empty.';
    }
    $notes[] = 'Signups never come from the ad sync. They join '
        . 'from a sheet import or manual entry, or stay empty.';

    $where = ph_date_where($stats, ['date_start', 'date'], $from, $to);
    if ($where !== '') {
        // qualify the stats column with the s alias (both bounds)
        $dcol = ph_first_col($stats, ['date_start', 'date']);
        $where = str_replace("$dcol ", "s.$dcol ", $where);
        $notes[] = 'Date range applied: only activity inside '
            . ($from ?: '…') . ' to ' . ($to ?: '…')
            . ' is totalled.';
    }
    $sql = 'SELECT ' . implode(', ', $sel)
        . " FROM $s s JOIN $a a ON s.$adId = a.$adsId"
        . $joinCre . $joinCamp
        . $where
        . ' GROUP BY ' . implode(', ', $grp)
        . ' ORDER BY spend DESC LIMIT 500';
    return [$sql, $notes];
}

/** Normalize one report row into the panel's import shape. */
function ph_normalize_report_row(array $cols, array $row): array {
    $get = function ($name) use ($cols, $row) {
        $i = array_search($name, $cols, true);
        return $i === false ? null : ($row[$i] ?? null);
    };
    $ctaRaw = (string)($get('cta_raw') ?? '');
    $cta = $ctaRaw !== ''
        ? ucwords(strtolower(str_replace('_', ' ', $ctaRaw))) : null;
    $adId = $get('ad_id');
    return [
        'external_ref' => $adId !== null && $adId !== ''
            ? 'meta:' . $adId : null,
        'creative_name' => $get('creative_name') ?: $get('ad_name'),
        'headline' => $get('headline') ?: $get('ad_name'),
        'primary_text' => $get('primary_text'),
        'cta' => $cta,
        'image_url' => $get('image_url'),
        'account' => $get('account'),
        'campaign' => $get('campaign'),
        'spend' => $get('spend') !== null ? (float)$get('spend') : 0.0,
        'impressions' => $get('impressions') !== null
            ? (int)$get('impressions') : 0,
        'clicks' => $get('clicks') !== null ? (int)$get('clicks') : 0,
        'purchasers' => $get('conversions') !== null
            ? (int)$get('conversions') : null,
    ];
}

// ---------- I/O below (config + HTTP) ----------

require_once __DIR__ . '/settings.php';

/** Workspace PostHog config. Never returns the key itself by value
 *  to callers that only need status: see ph_config_status(). */
function ph_config(PDO $pdo, int $wsid): array {
    $host = (string)(get_setting($pdo, $wsid, 'posthog_host')
        ?: 'us.posthog.com');
    if (!in_array($host, POSTHOG_HOSTS, true)) {
        $host = 'us.posthog.com';
    }
    $project = (string)(get_setting($pdo, $wsid, 'posthog_project_id')
        ?: '');
    return [
        'host' => $host,
        'project_id' => $project,
        'key' => vault_key($pdo, 'posthog'),
    ];
}

function ph_config_status(PDO $pdo, int $wsid): array {
    $cfg = ph_config($pdo, $wsid);
    return [
        'host' => $cfg['host'],
        'project_id' => $cfg['project_id'],
        'has_key' => !empty($cfg['key']),
        'configured' => !empty($cfg['key']) && $cfg['project_id'] !== '',
    ];
}

/** Authenticated PostHog API call. Throws with the provider's own
 *  message on failure, like the OpenAI helper does. */
function ph_http(array $cfg, string $method, string $path,
                 ?array $payload = null): array {
    $ch = curl_init('https://' . $cfg['host'] . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('PostHog transport error: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
        $msg = is_array($data) && isset($data['detail'])
            ? (string)$data['detail']
            : ('HTTP ' . $status . ': ' . substr((string)$raw, 0, 300));
        throw new RuntimeException('PostHog error: ' . $msg);
    }
    if (!is_array($data)) {
        throw new RuntimeException('PostHog returned a non-JSON response.');
    }
    return $data;
}

/** List the project's warehouse tables, normalized. */
function ph_discover_tables(array $cfg): array {
    $data = ph_http($cfg, 'GET',
        '/api/projects/' . $cfg['project_id'] . '/warehouse_tables/');
    $tables = [];
    foreach (($data['results'] ?? []) as $t) {
        $cols = [];
        foreach (($t['columns'] ?? []) as $k => $v) {
            // Columns arrive either as a list of {name,...} or as a
            // name => type map, depending on the PostHog version.
            $cols[] = is_array($v) ? (string)($v['name'] ?? $k)
                                   : (string)$k;
        }
        $tables[] = [
            'name' => (string)($t['name'] ?? ''),
            'columns' => $cols,
        ];
    }
    return $tables;
}

/** Run the report HogQL through the query endpoint. The trailing
 *  comment busts PostHog's per-text result cache, which otherwise
 *  serves the empty result from before the sync landed. */
function ph_run_query(array $cfg, string $sql,
                      ?callable $normalizer = null): array {
    $sql .= ' -- panel-import ' . date('Y-m-d H:i');
    $data = ph_http($cfg, 'POST',
        '/api/projects/' . $cfg['project_id'] . '/query/',
        ['query' => ['kind' => 'HogQLQuery', 'query' => $sql]]);
    $cols = $data['columns'] ?? [];
    if ($normalizer === null) {
        $normalizer = fn($c, $r) => ph_normalize_report_row($c, $r);
    }
    $rows = [];
    foreach (($data['results'] ?? []) as $r) {
        $rows[] = $normalizer($cols, (array)$r);
    }
    return $rows;
}

// ---------- Multi-platform profiles (Update 10) ----------
//
// Update 9 was Meta-shaped. PostHog also has native sources for
// Google Ads, LinkedIn Ads and Reddit Ads, each with creative
// level tables, plus a Google Sheets source that turns any
// worksheet into a table. A profile describes one platform's
// table names and column candidates; the generic builder below
// does the rest. Every candidate list is defensive: the builder
// only joins tables and selects columns that discovery actually
// found, and the preview step exists so an operator can see real
// numbers before anything imports.

function ph_profiles(): array {
    return [
        'google' => [
            'label' => 'Google Ads',
            'prefix' => 'google',
            'stats' => ['ad_stats', 'adstats'],
            'entity' => ['ad', 'ads'],
            'copy' => null,
            'campaigns' => ['campaign', 'campaigns'],
            'stats_id' => ['ad_id', 'id'],
            'entity_id' => ['id', 'ad_id'],
            'date' => ['date', 'date_start'],
            'entity_ref' => ['creative_id'],
            'spend' => [['col' => 'cost'], ['col' => 'spend'],
                        ['col' => 'cost_micros', 'div' => 1000000],
                        ['col' => 'metrics_cost_micros', 'div' => 1000000]],
            'impressions' => ['impressions', 'metrics_impressions'],
            'clicks' => ['clicks', 'metrics_clicks'],
            'conversions' => ['conversions', 'metrics_conversions'],
            'headline' => ['name', 'headline', 'ad_name', 'title'],
            'primary' => ['description', 'text', 'body'],
            'cta' => ['call_to_action_type', 'cta'],
            'image' => ['image_url', 'thumbnail_url'],
            'account' => ['account_id', 'customer_id'],
            'note' => 'Google syncs stats per ad, but responsive '
                . 'search ad copy lives in headline and description '
                . 'lists the sync may flatten or omit — check the '
                . 'preview; copy can import empty while the numbers '
                . 'are complete.',
        ],
        'linkedin' => [
            'label' => 'LinkedIn Ads',
            'prefix' => 'linkedin',
            'stats' => ['creative_stats', 'creativestats'],
            'entity' => ['creatives'],
            'copy' => null,
            'campaigns' => ['campaigns'],
            'stats_id' => ['creative_id', 'creative', 'id'],
            'entity_id' => ['id', 'creative_id'],
            'date' => ['date_start', 'date'],
            'entity_ref' => [],
            'spend' => [['col' => 'cost_in_usd'],
                        ['col' => 'cost_in_local_currency'],
                        ['col' => 'cost'], ['col' => 'spend']],
            'impressions' => ['impressions'],
            'clicks' => ['clicks'],
            'conversions' => ['external_website_conversions',
                              'conversions', 'website_conversions'],
            'headline' => ['name', 'title', 'headline'],
            'primary' => ['description', 'body', 'text', 'commentary'],
            'cta' => ['call_to_action', 'cta'],
            'image' => ['image_url', 'thumbnail_url'],
            'account' => ['account_id'],
            'note' => 'LinkedIn creative copy is nested in the '
                . 'platform; the sync may carry only ids and stats '
                . 'for some creative types. The preview shows what '
                . 'actually arrived.',
        ],
        'reddit' => [
            'label' => 'Reddit Ads',
            'prefix' => 'reddit',
            'stats' => ['ad_report', 'adreport'],
            'entity' => ['ads'],
            'copy' => ['structured_posts', 'structuredposts'],
            'copy_join' => ['entity_col' => 'post_id',
                            'copy_col' => 'id'],
            'campaigns' => ['campaigns'],
            'stats_id' => ['ad_id', 'id'],
            'entity_id' => ['id', 'ad_id'],
            'date' => ['date', 'date_start'],
            'entity_ref' => [],
            'spend' => [['col' => 'spend'], ['col' => 'spend_usd']],
            'impressions' => ['impressions'],
            'clicks' => ['clicks'],
            'conversions' => ['conversion_total', 'conversions',
                              'total_conversions'],
            'headline' => ['title', 'headline', 'name'],
            'primary' => ['body', 'text', 'selftext'],
            'cta' => ['call_to_action', 'cta'],
            'image' => ['image_url', 'thumbnail_url', 'thumbnail'],
            'account' => ['account_id', 'ad_account_id'],
            'note' => 'Reddit copy lives on the post behind the ad '
                . '(structured_posts). Check the preview spend '
                . 'figures before importing: if they look 1,000,000x '
                . 'too large, the account reports spend in micros '
                . 'and the numbers need a unit fix first.',
        ],
    ];
}

/** Find one platform's tables. Roles: stats, entity, copy, campaigns. */
function ph_find_platform_tables(array $tables, array $profile): array {
    $tables = ph_filter_tables_for($tables, $profile['prefix']);
    return [
        'stats' => ph_pick_table($tables, $profile['stats']),
        'entity' => ph_pick_table($tables, $profile['entity']),
        'copy' => !empty($profile['copy'])
            ? ph_pick_table($tables, $profile['copy']) : null,
        'campaigns' => ph_pick_table($tables, $profile['campaigns']),
    ];
}


/** Build a WHERE fragment restricting a stats table to a date
 *  range. Dates arrive as YYYY-MM-DD (validated here by shape and
 *  by checkdate, so interpolating them is safe). Returns '' when
 *  no bound is set or the table has no usable date column. */
function ph_date_where(?array $table, array $candidates,
                       ?string $from, ?string $to): string {
    $ok = function (?string $d): ?string {
        if ($d === null || $d === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return $d;
    };
    $from = $ok($from);
    $to = $ok($to);
    if ($from === null && $to === null) {
        return '';
    }
    $col = ph_first_col($table, $candidates);
    if ($col === null) {
        return '';
    }
    $parts = [];
    if ($from !== null) {
        $parts[] = "$col >= '$from'";
    }
    if ($to !== null) {
        $parts[] = "$col <= '$to'";
    }
    return ' WHERE ' . implode(' AND ', $parts);
}

/** Generic report builder, driven by a platform profile.
 *
 *  The stats table is aggregated down to one row per creative in
 *  a subquery BEFORE anything joins it. The first version joined
 *  first and grouped after, and against a real warehouse that
 *  timed out (LinkedIn creative_stats at 55k daily rows: the join
 *  fan-out made the group-by crawl past PostHog's query limit).
 *  Aggregate-first keeps every join small: stats collapse to at
 *  most one row per creative, and the entity/campaign/copy tables
 *  are one row per id by construction. */
function ph_build_platform_query(array $found, array $profile,
                                   ?string $from = null,
                                   ?string $to = null): array {
    $stats = $found['stats'];
    $entity = $found['entity'];
    $label = $profile['label'];
    if ($stats === null || $entity === null) {
        return ['', ["The $label stats and entity tables are both "
            . 'required.']];
    }
    $notes = [$profile['note']];
    $statsId = ph_first_col($stats, $profile['stats_id']);
    $entityId = ph_first_col($entity, $profile['entity_id']);
    if ($statsId === null || $entityId === null) {
        return ['', ["Could not find the $label id columns to join on."]];
    }
    $sName = '`' . $stats['name'] . '`';
    $aName = '`' . $entity['name'] . '`';

    // ---- inner: aggregate stats to one row per creative ----
    $agg = ["$statsId AS ad_id"];
    $aggGrp = ["$statsId"];
    $account = ph_first_col($stats, $profile['account']);
    if ($account !== null) {
        $agg[] = "toString($account) AS account";
        $aggGrp[] = "toString($account)";
    }
    $spendExpr = '0 AS spend';
    foreach ($profile['spend'] as $spec) {
        if (ph_has_col($stats, $spec['col'])) {
            $div = isset($spec['div']) ? ' / ' . (int)$spec['div'] : '';
            $spendExpr = "round(sum(toFloat({$spec['col']}))$div, 2)"
                . ' AS spend';
            break;
        }
    }
    $agg[] = $spendExpr;
    foreach (['impressions', 'clicks'] as $m) {
        $col = ph_first_col($stats, $profile[$m]);
        $agg[] = $col !== null ? "sum(toInt($col)) AS $m" : "0 AS $m";
    }
    $conv = ph_first_col($stats, $profile['conversions']);
    if ($conv !== null) {
        $agg[] = "sum(toInt($conv)) AS conversions";
    } else {
        $notes[] = "The $label sync carries no conversions column "
            . 'the panel can sum, so purchasers import empty.';
    }
    $notes[] = 'Signups never come from the ad sync. They join '
        . 'from a sheet import or manual entry, or stay empty.';
    $where = ph_date_where($stats, $profile['date'] ?? [], $from, $to);
    if ($where !== '') {
        $notes[] = 'Date range applied: only activity inside '
            . ($from ?: '…') . ' to ' . ($to ?: '…')
            . ' is totalled.';
    }
    $inner = 'SELECT ' . implode(', ', $agg)
        . " FROM $sName$where GROUP BY " . implode(', ', $aggGrp);

    // ---- outer: join the small aggregates to entity/copy ----
    $sel = ['s.ad_id AS ad_id', 's.spend AS spend',
            's.impressions AS impressions', 's.clicks AS clicks'];
    if ($conv !== null) {
        $sel[] = 's.conversions AS conversions';
    }
    if ($account !== null) {
        $sel[] = 's.account AS account';
    } else {
        $acc2 = ph_first_col($entity, $profile['account']);
        if ($acc2 !== null) {
            $sel[] = "toString(a.$acc2) AS account";
        }
    }
    $adName = ph_first_col($entity, $profile['headline']);
    if ($adName !== null) {
        $sel[] = "a.$adName AS ad_name";
    }

    // Copy: a separate table when the profile has one and the
    // join columns exist, otherwise fields on the entity itself.
    $copyAlias = null;
    $joinCopy = '';
    if (!empty($found['copy']) && !empty($profile['copy_join'])) {
        $eCol = $profile['copy_join']['entity_col'];
        $cCol = $profile['copy_join']['copy_col'];
        if (ph_has_col($entity, $eCol)
            && ph_has_col($found['copy'], $cCol)) {
            $c = '`' . $found['copy']['name'] . '`';
            $joinCopy = " JOIN $c c ON a.$eCol = c.$cCol";
            $copyAlias = 'c';
        }
    }
    $copyTable = $copyAlias !== null ? $found['copy'] : $entity;
    $copyOwner = $copyAlias !== null ? 'c' : 'a';
    $fieldMap = [
        'headline' => $profile['headline'],
        'primary_text' => $profile['primary'],
        'cta_raw' => $profile['cta'],
        'image_url' => $profile['image'],
    ];
    foreach ($fieldMap as $alias => $candidates) {
        $col = ph_first_col($copyTable, $candidates);
        if ($col !== null) {
            $sel[] = "$copyOwner.$col AS $alias";
        }
    }
    if ($copyAlias === null && !empty($profile['copy'])) {
        $notes[] = "No separate copy table joined for $label, so "
            . 'copy fields come from the ad row itself.';
    }

    // Campaign: prefer the name the entity row already carries
    // (LinkedIn creatives ship one) over joining a third table.
    $joinCamp = '';
    if (ph_has_col($entity, 'campaign')) {
        $sel[] = 'a.campaign AS campaign';
    } else {
        $camp = $found['campaigns'] ?? null;
        $campLink = ph_first_col($entity, ['campaign_id']);
        if ($camp !== null && $campLink !== null
            && ph_first_col($camp, ['id', 'campaign_id']) !== null
            && ph_first_col($camp, ['name', 'campaign_name']) !== null) {
            $cpId = ph_first_col($camp, ['id', 'campaign_id']);
            $cpName = ph_first_col($camp, ['name', 'campaign_name']);
            $cp = '`' . $camp['name'] . '`';
            $joinCamp = " JOIN $cp cp ON a.$campLink = cp.$cpId";
            $sel[] = "cp.$cpName AS campaign";
        }
    }

    $sql = 'SELECT ' . implode(', ', $sel)
        . " FROM ($inner) s JOIN $aName a"
        . " ON toString(s.ad_id) = toString(a.$entityId)"
        . $joinCopy . $joinCamp
        . ' ORDER BY spend DESC LIMIT 500';
    return [$sql, $notes];
}

function ph_normalize_platform_row(array $cols, array $row,
                                   array $profile): array {
    $get = function ($name) use ($cols, $row) {
        $i = array_search($name, $cols, true);
        return $i === false ? null : ($row[$i] ?? null);
    };
    $ctaRaw = (string)($get('cta_raw') ?? '');
    $cta = $ctaRaw !== ''
        ? ucwords(strtolower(str_replace('_', ' ', $ctaRaw))) : null;
    $adId = $get('ad_id');
    return [
        'external_ref' => $adId !== null && $adId !== ''
            ? $profile['prefix'] . ':' . $adId : null,
        'creative_name' => $get('ad_name'),
        'headline' => $get('headline') ?: $get('ad_name'),
        'primary_text' => $get('primary_text'),
        'cta' => $cta,
        'image_url' => $get('image_url'),
        'account' => $get('account'),
        'campaign' => $get('campaign'),
        'spend' => $get('spend') !== null ? (float)$get('spend') : 0.0,
        'impressions' => $get('impressions') !== null
            ? (int)$get('impressions') : 0,
        'clicks' => $get('clicks') !== null ? (int)$get('clicks') : 0,
        'purchasers' => $get('conversions') !== null
            ? (int)$get('conversions') : null,
        'signups' => null,
    ];
}

// ---------- Google Sheets mode (Update 10) ----------
//
// A Sheets source turns each worksheet into a warehouse table:
// first row is the headers, normalized by PostHog (casing,
// spaces and punctuation ignored). The panel maps those headers
// onto its own CSV intake columns, so a maintained sheet behaves
// like a living CSV — including a signups column the ad
// syncs cannot carry.

function ph_sheet_norm_header(string $h): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($h)) ?? '';
}

/**
 * Keep one platform's discovery from claiming another platform's
 * tables: in a mixed project, google_ads_ad_stats also suffix
 * matches a bare ad_stats candidate. Tables naming a different
 * known platform are excluded unless they name this one too.
 */
function ph_filter_tables_for(array $tables, string $own): array {
    // The last three are platforms the panel does not import yet;
    // their tables are excluded so they can never be claimed by a
    // loose candidate match.
    $tokens = ['meta', 'google', 'linkedin', 'reddit',
               'pinterest', 'tiktok', 'snapchat'];
    $out = [];
    foreach ($tables as $t) {
        $n = strtolower($t['name']);
        $hasOwn = strpos($n, $own) !== false;
        $hasOther = false;
        foreach ($tokens as $tok) {
            if ($tok !== $own && strpos($n, $tok) !== false) {
                $hasOther = true;
                break;
            }
        }
        if ($hasOwn || !$hasOther) {
            $out[] = $t;
        }
    }
    return $out;
}

/** Map a sheet table's columns onto panel fields. */
function ph_sheet_column_map(array $columns): array {
    $byNorm = [];
    foreach ($columns as $c) {
        $byNorm[ph_sheet_norm_header($c)] = $c;
    }
    $want = [
        'ref' => ['id', 'templateid', 'template', 'adid', 'externalid'],
        'headline' => ['headline', 'title'],
        'primary_text' => ['primarytext', 'primary', 'body', 'copy',
                           'text', 'description'],
        'description' => ['description', 'desc'],
        'cta' => ['cta', 'calltoaction'],
        'offer_text' => ['offertext', 'offer'],
        'entity' => ['entity', 'gamename', 'account', 'campaign',
                     'brand'],
        'format' => ['format'],
        'image_url' => ['imageurl', 'imagefile', 'image', 'imagelink'],
        'spend' => ['spend', 'cost'],
        'impressions' => ['impressions', 'impr'],
        'clicks' => ['clicks'],
        'signups' => ['signups', 'leads'],
        'purchasers' => ['purchasers', 'purchases', 'conversions',
                         'buyers'],
    ];
    $map = [];
    foreach ($want as $field => $candidates) {
        foreach ($candidates as $cand) {
            if (isset($byNorm[$cand])) {
                // primary_text wins the description column; the
                // dedicated description field only takes it when
                // primary_text already claimed something else.
                if ($field === 'description'
                    && ($map['primary_text'] ?? null) === $byNorm[$cand]) {
                    continue;
                }
                $map[$field] = $byNorm[$cand];
                break;
            }
        }
    }
    return $map;
}

/** A table counts as an importable sheet when it carries at least
 *  a headline and one stat column. */
function ph_sheet_is_importable(array $map): bool {
    return isset($map['headline'])
        && (isset($map['spend']) || isset($map['impressions'])
            || isset($map['primary_text']));
}

function ph_build_sheet_query(string $table, array $map): string {
    $sel = [];
    foreach ($map as $field => $col) {
        $sel[] = '`' . $col . '` AS ' . $field;
    }
    if (empty($sel)) {
        return '';
    }
    return 'SELECT ' . implode(', ', $sel)
        . ' FROM `' . $table . '` LIMIT 500';
}

function ph_sheet_num($v): ?float {
    if ($v === null || $v === '') {
        return null;
    }
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$v);
    return $clean === '' || $clean === '-' ? null
        : (float)$clean;
}

function ph_normalize_sheet_row(array $cols, array $row,
                                string $table): array {
    $get = function ($name) use ($cols, $row) {
        $i = array_search($name, $cols, true);
        return $i === false ? null : ($row[$i] ?? null);
    };
    $ref = $get('ref');
    if ($ref === null || $ref === '') {
        $ref = substr(md5(($get('headline') ?? '') . '|'
            . ($get('primary_text') ?? '') . '|'
            . ($get('entity') ?? '')), 0, 12);
    }
    $prefix = 'sheet:' . substr(md5($table), 0, 6);
    return [
        'external_ref' => $prefix . ':' . $ref,
        'creative_name' => $get('headline'),
        'headline' => $get('headline'),
        'primary_text' => $get('primary_text'),
        'description' => $get('description'),
        'cta' => $get('cta'),
        'offer_text' => $get('offer_text'),
        'format' => $get('format'),
        'image_url' => $get('image_url'),
        'account' => null,
        'campaign' => $get('entity'),
        'spend' => ph_sheet_num($get('spend')) ?? 0.0,
        'impressions' => (int)(ph_sheet_num($get('impressions')) ?? 0),
        'clicks' => (int)(ph_sheet_num($get('clicks')) ?? 0),
        'signups' => ph_sheet_num($get('signups')) !== null
            ? (int)ph_sheet_num($get('signups')) : null,
        'purchasers' => ph_sheet_num($get('purchasers')) !== null
            ? (int)ph_sheet_num($get('purchasers')) : null,
    ];
}
