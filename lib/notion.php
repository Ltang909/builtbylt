<?php
/**
 * Notion guideline intake. PHP port of notion.py.
 *
 * Fetches a Notion page's blocks via the public API (api.notion.com, v1)
 * and converts them to markdown, best-effort. Requires an integration
 * token (the panel key vault, provider "notion"); the page must be shared
 * with that integration in Notion or the API returns an error — which is
 * surfaced to the caller, never replaced with fabricated content.
 */

const NOTION_BASE = 'https://api.notion.com';
const NOTION_VERSION = '2022-06-28';
const NOTION_TIMEOUT_S = 15;
const NOTION_MAX_PAGES = 10;

class NotionError extends Exception {}

/** Extract a Notion page id from a URL (dashed UUID or trailing 32 hex). */
function notion_parse_page_id(?string $pageUrl): ?string {
    if (!$pageUrl) {
        return null;
    }
    if (preg_match_all(
            '/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/',
            $pageUrl, $m) && $m[0]) {
        return $m[0][count($m[0]) - 1];
    }
    $stripped = str_replace('-', '', $pageUrl);
    if (preg_match_all('/[0-9a-fA-F]{32}/', $stripped, $m2) && $m2[0]) {
        return $m2[0][count($m2[0]) - 1];
    }
    // Slug-style URL: the id is the last dash-separated chunk of hex.
    $tail = rtrim($pageUrl, '/');
    $parts = explode('/', $tail);
    $tail = end($parts);
    $tail = explode('?', $tail)[0];
    $chunks = explode('-', $tail);
    $chunk = end($chunks);
    if (preg_match('/^[0-9a-fA-F]{32}$/', (string)$chunk)) {
        return $chunk;
    }
    return null;
}

function notion_rich_text(array $body, string $key = 'rich_text'): string {
    $out = '';
    foreach ((array)($body[$key] ?? []) as $p) {
        $out .= $p['plain_text'] ?? '';
    }
    return $out;
}

function notion_block_to_markdown(array $block): string {
    $btype = (string)($block['type'] ?? '');
    $body = (array)($block[$btype] ?? []);
    $text = notion_rich_text($body);
    switch ($btype) {
        case 'paragraph':
            return $text;
        case 'heading_1':
            return '# ' . $text;
        case 'heading_2':
            return '## ' . $text;
        case 'heading_3':
            return '### ' . $text;
        case 'bulleted_list_item':
            return '- ' . $text;
        case 'numbered_list_item':
            return '1. ' . $text;
        case 'quote':
        case 'callout':
            return '> ' . $text;
        case 'code':
            $lang = $body['language'] ?? '';
            return "```$lang\n$text\n```";
        case 'divider':
            return '---';
        case 'child_page':
            return '## ' . ($body['title'] ?? 'Untitled page');
        case 'toggle':
            return $text;
        case 'image':
            $caption = notion_rich_text($body, 'caption');
            return '[image' . ($caption !== '' ? ": $caption" : '') . ']';
        default:
            return $text; // any other block: keep whatever plain text it carries
    }
}

/** @throws NotionError */
function notion_get_json(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => NOTION_TIMEOUT_S,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Notion-Version: ' . NOTION_VERSION,
            'Content-Type: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new NotionError('Notion API unreachable: ' . curl_error($ch));
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        $detail = '';
        $payload = json_decode((string)$raw, true);
        if (is_array($payload)) {
            $detail = $payload['message'] ?? $payload['code'] ?? '';
        }
        throw new NotionError("Notion API HTTP $status"
            . ($detail !== '' ? ": $detail" : ''));
    }
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/** POST JSON to Notion. Returns the decoded response array.
 *  @throws NotionError */
function notion_post_json(string $url, string $token, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => NOTION_TIMEOUT_S,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Notion-Version: ' . NOTION_VERSION,
            'Content-Type: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new NotionError('Notion API unreachable: ' . curl_error($ch));
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        $detail = '';
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $detail = $decoded['message'] ?? $decoded['code'] ?? '';
        }
        throw new NotionError("Notion API HTTP $status"
            . ($detail !== '' ? ": $detail" : ''));
    }
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/** One rich_text run array for building blocks to append. */
function notion_rich_run(string $text): array {
    return [['type' => 'text', 'text' => ['content' => $text]]];
}

/**
 * The Notion blocks for one learnings row (Update 4 learnings loop):
 * a heading "YYYY-MM-DD — <experiment> — <headline> — <VERDICT>" and a
 * paragraph carrying the reason / metrics line. The verdict is shown
 * uppercased; the stored value stays as-is.
 */
function notion_learning_blocks(array $learning, ?array $creative,
                                ?array $experiment): array {
    $date = substr((string)($learning['created_at'] ?? ''), 0, 10);
    if ($date === '') {
        $date = date('Y-m-d');
    }
    $expName = $experiment !== null
        ? (string)($experiment['name'] ?? 'experiment') : 'no experiment';
    $headline = $creative !== null
        ? trim((string)($creative['headline'] ?? '')) : '';
    if ($headline === '') {
        $headline = 'creative #'
            . (string)($learning['creative_id'] ?? '?');
    }
    $verdict = strtoupper((string)($learning['final_verdict'] ?? ''));
    $title = $date . ' — ' . $expName . ' — ' . $headline . ' — '
        . $verdict;
    return [
        ['object' => 'block', 'type' => 'heading_3',
         'heading_3' => ['rich_text' => notion_rich_run($title)]],
        ['object' => 'block', 'type' => 'paragraph',
         'paragraph' => ['rich_text' => notion_rich_run(
             (string)($learning['reason'] ?? ''))]],
    ];
}

/**
 * Append blocks as children of a Notion page.
 * @throws NotionError
 */
function notion_append_blocks(string $pageId, string $token,
                              array $blocks): void {
    notion_post_json(
        NOTION_BASE . "/v1/blocks/$pageId/children", $token,
        ['children' => $blocks]);
}

/**
 * Push ONE learnings row to the workspace's Notion experiments doc
 * (Update 4). Best-effort by design: returns false and leaves
 * learnings.notion_synced at 0 when Notion is not configured (no
 * vault token, no learnings_page_id setting) or the append fails;
 * failures are logged to the call ledger (provider 'notion') and
 * NEVER thrown into the caller's flow. Returns true when the row is
 * synced (or already was). Requires lib/settings.php to be loaded.
 */
function notion_sync_learning(PDO $pdo, int $learningId): bool {
    if (!panel_try_column($pdo, 'learnings', 'notion_synced')) {
        return false; // pre-migration-003: the loop is simply off
    }
    $stmt = $pdo->prepare('SELECT * FROM learnings WHERE id = ?');
    $stmt->execute([$learningId]);
    $learning = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($learning === false) {
        return false;
    }
    if ((int)($learning['notion_synced'] ?? 0) === 1) {
        return true;
    }
    $wsid = isset($learning['workspace_id'])
        ? (int)$learning['workspace_id'] : 0;
    $pageRef = trim((string)get_setting(
        $pdo, $wsid, 'learnings_page_id', ''));
    if ($pageRef === '') {
        return false; // not configured for this workspace — not an error
    }
    $token = vault_key($pdo, 'notion');
    if ($token === null) {
        return false; // no integration token in the vault — not an error
    }
    $pageId = notion_parse_page_id($pageRef) ?? $pageRef;

    $creative = null;
    $experiment = null;
    if (!empty($learning['creative_id'])) {
        $creative = fetch_creative($pdo, (int)$learning['creative_id']);
        if ($creative !== null && !empty($creative['experiment_id'])) {
            $es = $pdo->prepare('SELECT * FROM experiments WHERE id = ?');
            $es->execute([(int)$creative['experiment_id']]);
            $experiment = $es->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    try {
        notion_append_blocks(
            $pageId, $token,
            notion_learning_blocks($learning, $creative, $experiment));
    } catch (Throwable $e) {
        log_ledger($pdo, $wsid ?: null, 'learnings_sync', 'notion', null,
                   'error', 'learning #' . $learningId . ': '
                   . substr($e->getMessage(), 0, 280));
        return false;
    }
    $pdo->prepare('UPDATE learnings SET notion_synced = 1 WHERE id = ?')
        ->execute([$learningId]);
    log_ledger($pdo, $wsid ?: null, 'learnings_sync', 'notion', null,
               'ok', 'learning #' . $learningId . ' appended to the '
               . 'workspace experiments doc');
    return true;
}

/**
 * Retry every unsynced learnings row in a workspace (the Settings
 * "Push pending learnings to Notion" button). Returns counts:
 * ['synced' => n, 'failed' => n, 'pending' => n] — pending counts
 * rows still unsynced at the end (not configured, or append failed).
 */
function notion_sync_pending_learnings(PDO $pdo, int $wsid): array {
    $out = ['synced' => 0, 'failed' => 0, 'pending' => 0];
    if (!panel_try_column($pdo, 'learnings', 'notion_synced')) {
        return $out;
    }
    $stmt = $pdo->prepare(
        'SELECT id FROM learnings WHERE workspace_id = ? '
        . 'AND notion_synced = 0 ORDER BY id');
    $stmt->execute([$wsid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        if (notion_sync_learning($pdo, (int)$lid)) {
            $out['synced']++;
        } else {
            $out['failed']++;
        }
    }
    $left = $pdo->prepare(
        'SELECT COUNT(*) FROM learnings WHERE workspace_id = ? '
        . 'AND notion_synced = 0');
    $left->execute([$wsid]);
    $out['pending'] = (int)$left->fetchColumn();
    return $out;
}

/**
 * Return the page converted to markdown.
 * @throws NotionError on any failure (bad URL, missing page, permission,
 *                     network).
 */
function notion_fetch_page_markdown(string $pageUrl, string $token): string {
    $pageId = notion_parse_page_id($pageUrl);
    if ($pageId === null) {
        throw new NotionError('Could not find a Notion page id in that URL.');
    }
    $lines = [];
    $cursor = null;
    for ($i = 0; $i < NOTION_MAX_PAGES; $i++) {
        $url = NOTION_BASE . "/v1/blocks/$pageId/children?page_size=100";
        if ($cursor) {
            $url .= '&start_cursor=' . urlencode($cursor);
        }
        $data = notion_get_json($url, $token);
        foreach ((array)($data['results'] ?? []) as $block) {
            $md = notion_block_to_markdown((array)$block);
            if ($md !== '') {
                $lines[] = $md;
            }
        }
        if (empty($data['has_more'])) {
            break;
        }
        $cursor = $data['next_cursor'] ?? null;
        if (!$cursor) {
            break;
        }
    }
    if (!$lines) {
        throw new NotionError(
            'Page fetched but no readable blocks were returned. The page '
            . 'may be empty, or not shared with the integration.');
    }
    return implode("\n\n", $lines);
}
