<?php
/**
 * Learnings loop — shared writers and readers (Update 4).
 *
 * record_learning() is the single creation point for learnings rows
 * written by the system itself (performance threshold evaluation and
 * guardrail verify outcomes). Every created row immediately attempts
 * the Notion-out sync (lib/notion.php) when the workspace has it
 * configured; a sync failure never blocks or breaks the caller.
 *
 * The readers power the prompt-in half of the loop: the most recent
 * workspace learnings, compressed to one line each, are fed into the
 * generation prompt (lib/openai.php) so new creative starts from what
 * previous experiments already taught.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/notion.php';

/**
 * Insert a learnings row unless a duplicate already exists.
 *
 * Dedupe modes:
 *   - default: an identical (workspace, creative, verdict, reason)
 *     row already exists -> no insert. Used for verify outcomes,
 *     where the reason carries the exact failed-rule set.
 *   - $dedupeByOutcome = true: ANY row for (creative, verdict)
 *     already exists -> no insert. Used by performance evaluation,
 *     whose contract is one learnings row per creative per outcome
 *     (pass / fail / kill), even as the metrics in the reason move.
 *
 * Returns the new learnings id, or null when deduped. On creation the
 * row attempts its Notion sync before this returns.
 */
function record_learning(PDO $pdo, int $wsid, ?int $creativeId,
                         string $verdict, string $reason,
                         bool $dedupeByOutcome = false): ?int {
    if ($dedupeByOutcome) {
        $chk = $pdo->prepare(
            'SELECT id FROM learnings WHERE creative_id = ? '
            . 'AND LOWER(final_verdict) = LOWER(?) LIMIT 1');
        $chk->execute([$creativeId, $verdict]);
    } else {
        $chk = $pdo->prepare(
            'SELECT id FROM learnings WHERE workspace_id = ? '
            . 'AND creative_id = ? AND final_verdict = ? '
            . 'AND reason = ? LIMIT 1');
        $chk->execute([$wsid, $creativeId, $verdict, $reason]);
    }
    if ($chk->fetch(PDO::FETCH_ASSOC) !== false) {
        return null;
    }
    if (panel_try_column($pdo, 'learnings', 'notion_synced')) {
        $ins = $pdo->prepare(
            'INSERT INTO learnings (workspace_id, creative_id, '
            . 'final_verdict, reason, notion_synced) '
            . 'VALUES (?, ?, ?, ?, 0)');
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO learnings (workspace_id, creative_id, '
            . 'final_verdict, reason) VALUES (?, ?, ?, ?)');
    }
    $ins->execute([$wsid, $creativeId, $verdict, $reason]);
    $id = (int)$pdo->lastInsertId();
    // Notion-out: best-effort, configured per workspace. Never throws
    // out of notion_sync_learning; the extra guard keeps a surprise
    // there from breaking the flow that created the learning.
    try {
        notion_sync_learning($pdo, $id);
    } catch (Throwable $e) {
        // already logged inside the sync; nothing further to do
    }
    return $id;
}

/** Compress a stored reason to its first sentence, length-capped. */
function learning_reason_head(string $reason, int $max = 160): string {
    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
    $dot = strpos($reason, '. ');
    if ($dot !== false && $dot >= 20) {
        $reason = substr($reason, 0, $dot + 1);
    }
    if (strlen($reason) > $max) {
        $reason = rtrim(substr($reason, 0, $max - 1)) . '…';
    }
    return $reason;
}

/**
 * One prompt line for a learnings row:
 *   "<VERDICT> — <headline> — <top reason>"
 * Verify learnings lead their reason with the failed rule ids;
 * performance learnings carry the metric-vs-threshold line — both
 * compress through the same first-sentence rule.
 */
function learning_one_line(array $learning, ?array $creative): string {
    $headline = $creative !== null
        ? trim((string)($creative['headline'] ?? '')) : '';
    if ($headline === '') {
        $headline = 'creative #' . (string)($learning['creative_id'] ?? '?');
    }
    if (strlen($headline) > 60) {
        $headline = rtrim(substr($headline, 0, 59)) . '…';
    }
    return strtoupper((string)($learning['final_verdict'] ?? ''))
        . ' — ' . $headline . ' — '
        . learning_reason_head((string)($learning['reason'] ?? ''));
}

/**
 * The workspace's most recent learnings as prompt lines, newest
 * first, capped at $limit (the loop contract is 12).
 *
 * @return string[]
 */
function workspace_learning_lines(PDO $pdo, int $wsid,
                                  int $limit = 12): array {
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare(
        "SELECT * FROM learnings WHERE workspace_id = ? "
        . "ORDER BY id DESC LIMIT $limit");
    $stmt->execute([$wsid]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $creative = !empty($row['creative_id'])
            ? fetch_creative($pdo, (int)$row['creative_id']) : null;
        $lines[] = learning_one_line($row, $creative);
    }
    return $lines;
}

/** Total learnings rows recorded for a workspace. */
function workspace_learnings_count(PDO $pdo, int $wsid): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM learnings WHERE workspace_id = ?');
    $stmt->execute([$wsid]);
    return (int)$stmt->fetchColumn();
}
