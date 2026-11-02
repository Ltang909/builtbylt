<?php
/**
 * Shared bootstrap + helpers for the control panel.
 *
 * Loads api/config.php, opens the PDO MySQL connection, and provides the
 * data helpers every endpoint and page uses (tokens, rules, key vault,
 * ledger, workspace resolution, performance math). api/db.php is a thin
 * wrapper over this file, in the style of the leontang.ca site.
 *
 * If config.php is missing or the DB connection fails, this file does NOT
 * emit output itself; it sets CONFIG_MISSING / leaves $pdo null, and the
 * caller (api/db.php for endpoints, the page files for HTML) decides how
 * to present the error.
 */

// PHP 7.4 polyfills FIRST (Update 4.1): the production host runs
// PHP < 8.0, and every endpoint/page funnels through this file, so
// the definitions must land before anything else can call them.
require_once __DIR__ . '/polyfills.php';
require_once __DIR__ . '/lint.php';
require_once __DIR__ . '/ad_templates.php';
require_once __DIR__ . '/guardrails.php';

define('PANEL_ROOT', dirname(__DIR__));

$GLOBALS['CONFIG_MISSING'] = false;
$GLOBALS['DB_ERROR'] = false;
$pdo = null;

$configPath = PANEL_ROOT . '/api/config.php';
if (!file_exists($configPath)) {
    $GLOBALS['CONFIG_MISSING'] = true;
} else {
    require $configPath;
    if (!defined('APP_BASE_PATH')) {
        define('APP_BASE_PATH', '/panel');
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (PDOException $e) {
        $GLOBALS['DB_ERROR'] = true;
        $pdo = null;
    }
}
if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', '/panel');
}

require_once __DIR__ . '/templates.php';

// --- JSON helpers ------------------------------------------------------------

function json_out($obj, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($obj);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// --- data helpers --------------------------------------------------------------

function log_ledger(PDO $pdo, ?int $workspaceId, ?string $task,
                    ?string $provider, ?string $model, ?string $status,
                    ?string $note): void {
    $stmt = $pdo->prepare(
        'INSERT INTO call_ledger (workspace_id, task, provider, model, '
        . 'status, note) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$workspaceId, $task, $provider, $model, $status, $note]);
}

function get_tokens(PDO $pdo, int $wsid): array {
    $stmt = $pdo->prepare('SELECT * FROM brand_tokens WHERE workspace_id = ?');
    $stmt->execute([$wsid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['colors' => [], 'fonts' => [], 'cta_allowlist' => [],
                'banned_words' => [], 'compliance_strings' => [],
                'offer_table' => [], 'tone_text' => ''];
    }
    $dec = fn($v) => json_decode((string)($v ?? '[]'), true) ?: [];
    return [
        'colors' => $dec($row['colors_json']),
        'fonts' => $dec($row['fonts_json']),
        'cta_allowlist' => $dec($row['cta_allowlist_json']),
        'banned_words' => $dec($row['banned_words_json']),
        'compliance_strings' => $dec($row['compliance_strings_json']),
        'offer_table' => $dec($row['offer_table_json']),
        'tone_text' => (string)($row['tone_text'] ?? ''),
    ];
}

function get_rules(PDO $pdo, int $wsid): array {
    $stmt = $pdo->prepare(
        'SELECT * FROM rules WHERE workspace_id = ? '
        . 'ORDER BY CAST(SUBSTRING(rule_id, 2) AS UNSIGNED)');
    $stmt->execute([$wsid]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['config'] = json_decode((string)($r['config_json'] ?? '{}'), true) ?: [];
        unset($r['config_json']);
        $r['enabled'] = (bool)$r['enabled'];
        $r['workspace_id'] = (int)$r['workspace_id'];
        $out[] = $r;
    }
    return $out;
}

const PROVIDER_ENV = [
    'typesafe' => 'TYPESAFE_API_KEY',
    'openai' => 'OPENAI_API_KEY',
    'anthropic' => 'ANTHROPIC_API_KEY',
    'notion' => 'NOTION_API_KEY',
];
const PANEL_PROVIDERS = ['typesafe', 'openai', 'anthropic', 'notion', 'posthog'];

/**
 * Key for a provider: vault first, environment second. Never logged,
 * never returned to any caller of this function besides the integration
 * code that needs it to make a call.
 */
function vault_key(PDO $pdo, string $provider): ?string {
    $stmt = $pdo->prepare('SELECT key_value FROM api_keys WHERE provider = ?');
    $stmt->execute([$provider]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row !== false && !empty($row['key_value'])) {
        return (string)$row['key_value'];
    }
    $env = PROVIDER_ENV[$provider] ?? null;
    if ($env) {
        $val = getenv($env);
        if ($val !== false && $val !== '') {
            return $val;
        }
    }
    return null;
}

function create_workspace(PDO $pdo, string $name, string $template): int {
    $stmt = $pdo->prepare('INSERT INTO workspaces (name) VALUES (?)');
    $stmt->execute([$name]);
    $wsid = (int)$pdo->lastInsertId();
    $tokens = template_tokens($template);
    $stmt = $pdo->prepare(
        'INSERT INTO brand_tokens (workspace_id, colors_json, fonts_json, '
        . 'cta_allowlist_json, banned_words_json, compliance_strings_json, '
        . 'offer_table_json, tone_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $wsid,
        json_encode($tokens['colors']), json_encode($tokens['fonts']),
        json_encode($tokens['cta_allowlist']),
        json_encode($tokens['banned_words']),
        json_encode($tokens['compliance_strings']),
        json_encode($tokens['offer_table']), $tokens['tone_text'],
    ]);
    $stmt = $pdo->prepare(
        'INSERT INTO rules (workspace_id, rule_id, name, type, enabled, '
        . 'config_json) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (template_rules($template) as [$ruleId, $rname, $rtype, $enabled, $config]) {
        $stmt->execute([$wsid, $ruleId, $rname, $rtype, $enabled,
                        json_encode($config)]);
    }
    return $wsid;
}

/** Workspace id from ?workspace_id= or the JSON body; default: first workspace. */
function resolve_workspace(PDO $pdo, ?array $data = null): ?int {
    $wid = $_GET['workspace_id'] ?? ($data['workspace_id'] ?? null);
    if ($wid === null) {
        $row = $pdo->query('SELECT id FROM workspaces ORDER BY id LIMIT 1')
                   ->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int)$row['id'] : null;
    }
    if (!is_numeric($wid)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM workspaces WHERE id = ?');
    $stmt->execute([(int)$wid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? (int)$row['id'] : null;
}

/**
 * Attach failure detail to verdict rows (Update 10.10): the rule's
 * display name, plus the panel's own deterministic verdict for the
 * same rule and its note. Jev returns only a probability per rule —
 * no prose — so the "why" behind a failure is assembled from these
 * two sources and labeled as such, never presented as Jev's words.
 */
function attach_verdict_details(array $verdicts, array $rules,
                                array $localVerdicts): array {
    $ruleById = [];
    foreach ($rules as $r) {
        $ruleById[(string)$r['rule_id']] = $r;
    }
    $localById = [];
    foreach ($localVerdicts as $v) {
        $localById[(string)$v['rule_id']] = $v;
    }
    foreach ($verdicts as &$v) {
        $rid = (string)$v['rule_id'];
        if (isset($ruleById[$rid])) {
            $v['rule_name'] = (string)($ruleById[$rid]['name'] ?? '');
        }
        if (isset($localById[$rid])) {
            $v['local_verdict'] = (string)$localById[$rid]['verdict'];
            $v['local_note'] = (string)$localById[$rid]['note'];
        }
    }
    unset($v);
    return $verdicts;
}

function creative_with_verdicts(PDO $pdo, array $creative): array {
    $stmt = $pdo->prepare(
        'SELECT * FROM verdicts WHERE creative_id = ? '
        . 'ORDER BY CAST(SUBSTRING(rule_id, 2) AS UNSIGNED)');
    $stmt->execute([(int)$creative['id']]);
    $creative['verdicts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Update 10.10: enrich verdicts with failure details (rule name
    // + the local engine's diagnosis for the same rule). Additive
    // only — a failure here must never break the creative read.
    if (!empty($creative['verdicts'])
        && (int)($creative['workspace_id'] ?? 0) > 0) {
        try {
            $rulesV = get_rules($pdo, (int)$creative['workspace_id']);
            $localV = run_checks(
                $creative, get_tokens($pdo, (int)$creative['workspace_id']),
                $rulesV);
            $creative['verdicts'] = attach_verdict_details(
                $creative['verdicts'], $rulesV, $localV);
        } catch (Throwable $e) {
            // Details are a nice-to-have; the verdicts stand alone.
        }
    }
    // Additive fields for the Queue review stage: explicit image
    // presence, and grouping ids normalized to int-or-null (the list
    // endpoint's SELECT * otherwise leaks PDO's string typing, which
    // breaks strict comparisons against the int-cast experiment ids).
    $creative['has_image'] = !empty($creative['image_path']);
    $creative['batch_id'] = isset($creative['batch_id'])
        && $creative['batch_id'] !== null ? (int)$creative['batch_id'] : null;
    $creative['experiment_id'] = isset($creative['experiment_id'])
        && $creative['experiment_id'] !== null
        ? (int)$creative['experiment_id'] : null;
    if (!empty($creative['image_path'])) {
        $creative['image_url'] = 'api/image.php?creative_id=' . (int)$creative['id'];
    }
    // Update 10.7: deterministic lint flags (ungrounded numbers,
    // off-allowlist CTA) ride every creative on read — see
    // lib/lint.php for why these are code, not prompt prose.
    $creative['lint_flags'] = lint_flags_for($pdo, $creative);
    return $creative;
}

function fetch_creative(PDO $pdo, int $cid): ?array {
    $stmt = $pdo->prepare('SELECT * FROM creatives WHERE id = ?');
    $stmt->execute([$cid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Performance state logic (same documented logic as the Python build):
 *   kill: spend >= spend_floor AND CAC > kill_cac
 *   pass: CAC <= pass_value
 *   fail: CAC > fail_value
 *   otherwise: on_track
 */
function compute_perf_state(array $totals, array $exp): array {
    $purchasers = (int)($totals['purchasers'] ?? 0);
    $spend = (float)($totals['spend'] ?? 0.0);
    $cac = $purchasers ? $spend / $purchasers : null;
    if (isset($exp['spend_floor'], $exp['kill_cac'])
        && $spend >= (float)$exp['spend_floor']
        && $cac !== null && $cac > (float)$exp['kill_cac']) {
        $state = 'kill';
    } elseif ($cac !== null && isset($exp['pass_value'])
        && $exp['pass_value'] !== null
        && $cac <= (float)$exp['pass_value']) {
        $state = 'pass';
    } elseif ($cac !== null && isset($exp['fail_value'])
        && $exp['fail_value'] !== null
        && $cac > (float)$exp['fail_value']) {
        $state = 'fail';
    } else {
        $state = 'on_track';
    }
    return [
        'total_spend' => $spend,
        'total_purchasers' => $purchasers,
        'cac' => $cac,
        'state' => $state,
    ];
}

// --- routing + migration preflight (MVP generation build) --------------------

/** Routing row for a task: ['provider' => ?, 'model' => ?] or nulls. */
function get_route(PDO $pdo, string $task): array {
    $stmt = $pdo->prepare('SELECT provider, model FROM routing WHERE task = ?');
    $stmt->execute([$task]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['provider' => null, 'model' => null];
    }
    return ['provider' => $row['provider'], 'model' => $row['model']];
}

/** True when a table exists in the current database (migration checks). */
function panel_table_exists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false && (int)$row['n'] > 0;
}

/** True when a column exists on a table in the current database. */
function panel_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
        . 'AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false && (int)$row['n'] > 0;
}

/**
 * Column probe that works on any PDO driver (Update 4): runs a
 * zero-row SELECT inside try/catch instead of consulting
 * information_schema, so feature detection behaves identically on
 * MySQL in production and SQLite in the test harness. Never throws.
 */
function panel_try_column(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ":$table.$column";
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $pdo->query("SELECT `$column` FROM `$table` LIMIT 0");
        $cache[$key] = true;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Best-effort unlink of a stored creative image, confined to the
 * panel's uploads/ tree via realpath (the same pattern api/image.php
 * and the Update 3 clear actions use). Returns true only when a file
 * was actually removed.
 */
function panel_unlink_upload(?string $imagePath): bool {
    if ($imagePath === null || trim($imagePath) === '') {
        return false;
    }
    $uploadsRoot = realpath(PANEL_ROOT . '/uploads');
    if ($uploadsRoot === false) {
        return false;
    }
    $full = realpath(PANEL_ROOT . '/' . ltrim($imagePath, '/'));
    $uploadsPrefix = $uploadsRoot . DIRECTORY_SEPARATOR;
    if ($full === false
        || substr($full, 0, strlen($uploadsPrefix)) !== $uploadsPrefix) {
        return false;
    }
    return @unlink($full);
}

/** Standard JSON error when an MVP feature's migration has not run. */
function migration_required(string $what): void {
    json_out(['error' => 'migration_required',
              'hint' => "$what needs sql/migration-002.sql — run it once "
                      . 'in phpMyAdmin (see UPDATE.md), then retry.'], 500);
}

// --- Batch identity (Update 3) ---------------------------------------------
// Generated batches get a deterministic accent color so work from
// different hypotheses is unmistakable in the Creative Queue. The
// palette is a pure function of the batch id — no column, no setting.
// This PHP list is the canonical copy; panel/app.js carries an
// identical BATCH_PALETTE (tests/run_tests.php cross-checks them).

const BATCH_PALETTE = [
    '#5B62F9', '#2fbf71', '#e8b84b', '#ef476f',
    '#38b6ff', '#b388ff', '#ff8a5c', '#3fd0c9',
];

/** Accent color for a generation batch id (deterministic). */
function batch_palette_color(int $batchId): string {
    return BATCH_PALETTE[abs($batchId) % count(BATCH_PALETTE)];
}
