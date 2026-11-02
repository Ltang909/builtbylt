<?php
/**
 * Pass / Fail / Kill as PURE threshold evaluation (Update 4).
 *
 * There is deliberately no way for a human to set a performance
 * verdict in this panel: a creative's perf_status is computed from
 * its cumulative performance rows against its experiment's stored
 * thresholds, and nothing else. Approval state (Approvals tab) is a
 * separate, human-owned thing and is untouched by this file.
 *
 * Status semantics (experiments table columns):
 *   kill:       CAC > kill_cac AND spend >= spend_floor
 *   fail:       CAC > fail_value
 *   pass:       CAC <= pass_value
 *   monitoring: thresholds not yet decidable — no customers yet, or
 *               CAC sits between pass_value and fail_value, or
 *               the experiment's thresholds are incomplete.
 * Precedence: KILL > FAIL > PASS > MONITORING.
 *
 * evaluate_workspace() persists the computed status + a human-readable
 * reason on the creative (creatives.perf_status / perf_status_reason,
 * migration-003), writes a deduped learnings row on pass/fail/kill,
 * moves killed creatives to the panel's 'killed' state, and emails
 * on TRANSITIONS into fail/kill (subject to the workspace's notify
 * settings). It runs when the Performance tab loads, after any
 * performance write, and from api/cron_evaluate.php.
 *
 * The mailer is injectable for tests: pass a callable, or set
 * $GLOBALS['PANEL_MAILER']; production falls back to mail().
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/learnings.php';

/** $ money formatting for reason lines: $151.50, $1,234.00. */
function eval_money(float $v): string {
    return '$' . number_format($v, 2);
}

/**
 * Pure threshold evaluation for one creative's cumulative metrics.
 *
 * @param array $totals ['spend' => float, 'purchasers' => int]
 *                       (cumulative sums; purchasers is the customer
 *                       count CAC divides by)
 * @param array $exp    experiment row (pass_value, fail_value,
 *                      kill_cac, spend_floor; any may be null).
 *                      pass_value / fail_value are CAC thresholds:
 *                      pass at or below pass_value, fail above
 *                      fail_value.
 * @return array{status: string, reason: string, spend: float,
 *               purchasers: int, cac: ?float}
 */
function evaluate_thresholds(array $totals, array $exp): array {
    $spend = (float)($totals['spend'] ?? 0.0);
    $purchasers = (int)($totals['purchasers'] ?? 0);
    $cac = $purchasers > 0 ? $spend / $purchasers : null;

    $passMax = isset($exp['pass_value']) && $exp['pass_value'] !== null
        ? (float)$exp['pass_value'] : null;
    $failAbove = isset($exp['fail_value']) && $exp['fail_value'] !== null
        ? (float)$exp['fail_value'] : null;
    $killCac = isset($exp['kill_cac']) && $exp['kill_cac'] !== null
        ? (float)$exp['kill_cac'] : null;
    $floor = isset($exp['spend_floor']) && $exp['spend_floor'] !== null
        ? (float)$exp['spend_floor'] : null;

    $base = ['spend' => $spend, 'purchasers' => $purchasers,
             'cac' => $cac];

    if ($cac === null) {
        return $base + [
            'status' => 'monitoring',
            'reason' => 'MONITORING: no customers yet, so CAC is not '
                . 'decidable (spend ' . eval_money($spend)
                . ' so far).',
        ];
    }
    // KILL first: both conditions must hold.
    if ($killCac !== null && $cac > $killCac
        && $spend >= ($floor ?? 0.0)) {
        return $base + [
            'status' => 'kill',
            'reason' => 'KILL: CAC ' . eval_money($cac) . ' > '
                . eval_money($killCac) . ' after ' . eval_money($spend)
                . ' spend.',
        ];
    }
    // FAIL before PASS (precedence), so a CAC that somehow matches
    // both bands (misconfigured thresholds) resolves to FAIL.
    if ($failAbove !== null && $cac > $failAbove) {
        return $base + [
            'status' => 'fail',
            'reason' => 'FAIL: CAC ' . eval_money($cac)
                . ' > ' . eval_money($failAbove) . ' fail threshold.',
        ];
    }
    if ($passMax !== null && $cac <= $passMax) {
        return $base + [
            'status' => 'pass',
            'reason' => 'PASS: CAC ' . eval_money($cac)
                . ' <= ' . eval_money($passMax) . ' pass threshold.',
        ];
    }
    if ($failAbove !== null && $passMax !== null) {
        $why = 'CAC ' . eval_money($cac)
            . ' is between the pass threshold '
            . eval_money($passMax) . ' and the fail threshold '
            . eval_money($failAbove) . '.';
    } elseif ($killCac !== null && $cac > $killCac) {
        $why = 'CAC ' . eval_money($cac) . ' exceeds the kill CAC '
            . eval_money($killCac) . ' but spend ' . eval_money($spend)
            . ' has not reached the ' . eval_money($floor ?? 0.0)
            . ' spend floor.';
    } else {
        $why = 'the experiment thresholds are incomplete, so no '
            . 'verdict can be computed yet.';
    }
    return $base + ['status' => 'monitoring',
                    'reason' => 'MONITORING: ' . $why];
}

/** Default production mailer: PHP mail() with a same-domain From. */
function panel_default_mailer(array $msg): bool {
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?? '';
    if ($host === '') {
        $host = 'localhost';
    }
    $headers = implode("\r\n", [
        'From: Creative Panel <panel@' . $host . '>',
        'Content-Type: text/plain; charset=utf-8',
    ]);
    return @mail($msg['to'], $msg['subject'], $msg['body'], $headers);
}

/** Resolve the active mailer: explicit > $GLOBALS stub > default. */
function panel_mailer(?callable $mailer): callable {
    if ($mailer !== null) {
        return $mailer;
    }
    if (isset($GLOBALS['PANEL_MAILER'])
        && is_callable($GLOBALS['PANEL_MAILER'])) {
        return $GLOBALS['PANEL_MAILER'];
    }
    return 'panel_default_mailer';
}

/**
 * Build the fail/kill notification for one transitioned creative.
 * Returns ['to' =>, 'subject' =>, 'body' =>].
 */
function perf_notification_message(array $creative, ?array $experiment,
                                   array $eval, string $to): array {
    $status = strtoupper($eval['status']);
    $headline = trim((string)($creative['headline'] ?? ''));
    if ($headline === '') {
        $headline = 'creative #' . (int)$creative['id'];
    }
    $expName = $experiment !== null
        ? (string)($experiment['name'] ?? '') : '';
    $subject = '[Creative Panel] ' . $status . ': ' . $headline
        . ($expName !== '' ? ' (' . $expName . ')' : '');
    $link = (defined('APP_BASE_PATH') ? APP_BASE_PATH : '/panel')
        . '/index.php';
    $lines = [
        'Creative #' . (int)$creative['id'] . ' — ' . $headline,
    ];
    if ($expName !== '') {
        $lines[] = 'Experiment: ' . $expName;
    }
    $lines[] = 'Status: ' . $status;
    $lines[] = 'Why: ' . $eval['reason'];
    $lines[] = 'Cumulative: spend ' . eval_money($eval['spend']) . ', '
        . $eval['purchasers'] . ' customer(s), CAC '
        . ($eval['cac'] !== null ? eval_money($eval['cac']) : 'n/a')
        . '.';
    $lines[] = '';
    $lines[] = 'Panel: ' . $link;
    return ['to' => $to, 'subject' => $subject,
            'body' => implode("\n", $lines)];
}

/**
 * Evaluate every creative with performance rows in one workspace.
 * Persists statuses, learnings, kills and transition notifications.
 * Degrades to a skipped summary on a pre-migration-003 database —
 * never fatals.
 *
 * @return array{evaluated: int, transitions: array, notifications: int,
 *               skipped?: string}
 */
function evaluate_workspace(PDO $pdo, int $wsid,
                            ?callable $mailer = null): array {
    $summary = ['evaluated' => 0, 'transitions' => [],
                'notifications' => 0];
    if (!panel_try_column($pdo, 'creatives', 'perf_status')
        || !panel_try_column($pdo, 'creatives', 'perf_status_reason')) {
        return $summary + ['skipped' => 'migration_required'];
    }
    $notify = notify_settings($pdo, $wsid);
    $send = panel_mailer($mailer);

    $cs = $pdo->prepare(
        'SELECT c.* FROM creatives c WHERE c.workspace_id = ? '
        . 'AND EXISTS (SELECT 1 FROM performance p '
        . 'WHERE p.creative_id = c.id) ORDER BY c.id');
    $cs->execute([$wsid]);
    $creatives = $cs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($creatives as $creative) {
        $cid = (int)$creative['id'];
        $ts = $pdo->prepare(
            'SELECT COALESCE(SUM(spend),0) AS spend, '
            . 'COALESCE(SUM(purchasers),0) AS purchasers '
            . 'FROM performance WHERE creative_id = ?');
        $ts->execute([$cid]);
        $totals = $ts->fetch(PDO::FETCH_ASSOC) ?: [];

        $exp = null;
        if (!empty($creative['experiment_id'])) {
            $es = $pdo->prepare('SELECT * FROM experiments WHERE id = ?');
            $es->execute([(int)$creative['experiment_id']]);
            $exp = $es->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $eval = evaluate_thresholds($totals, $exp ?: []);
        $summary['evaluated']++;

        $old = isset($creative['perf_status'])
            && $creative['perf_status'] !== null
            ? (string)$creative['perf_status'] : null;
        $new = $eval['status'];
        if ($old !== $new
            || (string)($creative['perf_status_reason'] ?? '')
                !== $eval['reason']) {
            $pdo->prepare(
                'UPDATE creatives SET perf_status = ?, '
                . 'perf_status_reason = ? WHERE id = ?')
                ->execute([$new, $eval['reason'], $cid]);
        }

        if (in_array($new, ['pass', 'fail', 'kill'], true)) {
            try {
                record_learning($pdo, $wsid, $cid, $new, $eval['reason'],
                                true);
            } catch (Throwable $e) {
                log_ledger($pdo, $wsid, 'learnings', null, null,
                           'error', 'creative #' . $cid . ': '
                           . substr($e->getMessage(), 0, 240));
            }
        }
        if ($new === 'kill'
            && (string)$creative['status'] !== 'killed') {
            $pdo->prepare(
                'UPDATE creatives SET status = \'killed\' WHERE id = ?')
                ->execute([$cid]);
        }

        // Notifications fire on TRANSITIONS into fail/kill only. A
        // creative that stays in the same status across evaluations
        // never re-emails.
        if ($old !== $new && ($new === 'fail' || $new === 'kill')) {
            $summary['transitions'][] = [
                'creative_id' => $cid, 'from' => $old, 'to' => $new,
            ];
            $wanted = $new === 'fail' ? $notify['on_fail']
                                      : $notify['on_kill'];
            if ($wanted && $notify['email'] !== '') {
                $msg = perf_notification_message(
                    $creative, $exp, $eval, $notify['email']);
                $okSend = false;
                try {
                    $okSend = (bool)$send($msg);
                } catch (Throwable $e) {
                    $okSend = false;
                }
                log_ledger($pdo, $wsid, 'notify', 'mail', null,
                           $okSend ? 'ok' : 'error',
                           strtoupper($new) . ' creative #' . $cid
                           . ' — subject: ' . $msg['subject']);
                if ($okSend) {
                    $summary['notifications']++;
                }
            }
        }
    }
    return $summary;
}
