<?php
/**
 * Key-value settings store (Update 4) — panel/lib/settings.php.
 *
 * Backed by the `settings` table (migration-003 / schema.sql):
 *   workspace_id = 0  -> panel-global scope (the evaluation cron token)
 *   workspace_id = N  -> that workspace's settings
 *
 * Keys in use:
 *   per workspace: notify_email, notify_on_fail, notify_on_kill,
 *                  learnings_page_id, use_learnings_in_prompt
 *   global:        cron_token
 *
 * Every accessor is migration-safe: when the table does not exist yet
 * (pre-migration-003 database), reads return the caller's default and
 * writes are silent no-ops — features degrade, nothing fatals. The
 * readiness probe uses a plain SELECT inside try/catch rather than
 * information_schema, so it behaves identically on MySQL and SQLite
 * (the test harness runs on SQLite).
 */

/** True when the settings table exists and answers queries. */
function settings_ready(PDO $pdo): bool {
    static $cache = [];
    $oid = spl_object_id($pdo);
    if (isset($cache[$oid])) {
        return $cache[$oid];
    }
    try {
        $pdo->query('SELECT 1 FROM settings LIMIT 0');
        $cache[$oid] = true;
    } catch (Throwable $e) {
        $cache[$oid] = false;
    }
    return $cache[$oid];
}

/** Read one setting. $scope is a workspace id, or 0 for panel-global. */
function get_setting(PDO $pdo, int $scope, string $key,
                     ?string $default = null): ?string {
    if (!settings_ready($pdo)) {
        return $default;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT setting_value FROM settings WHERE workspace_id = ? '
            . 'AND setting_key = ?');
        $stmt->execute([$scope, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['setting_value'] === null) {
            return $default;
        }
        return (string)$row['setting_value'];
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Write one setting (portable upsert: UPDATE first, INSERT when no
 * row matched). Silent no-op on a pre-migration database.
 */
function set_setting(PDO $pdo, int $scope, string $key,
                     ?string $value): void {
    if (!settings_ready($pdo)) {
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'UPDATE settings SET setting_value = ? '
            . 'WHERE workspace_id = ? AND setting_key = ?');
        $stmt->execute([$value, $scope, $key]);
        if ($stmt->rowCount() === 0) {
            $ins = $pdo->prepare(
                'INSERT INTO settings (workspace_id, setting_key, '
                . 'setting_value) VALUES (?, ?, ?)');
            $ins->execute([$scope, $key, $value]);
        }
    } catch (Throwable $e) {
        // A concurrent insert can race the UPDATE probe; the value is
        // a setting, not money — losing one write is acceptable and
        // must never break the caller's flow.
    }
}

/** Boolean read: anything stored other than '1' counts as false. */
function get_setting_bool(PDO $pdo, int $scope, string $key,
                          bool $default = false): bool {
    $v = get_setting($pdo, $scope, $key, null);
    if ($v === null) {
        return $default;
    }
    return $v === '1';
}

/** The workspace's notification settings, with panel defaults. */
function notify_settings(PDO $pdo, int $wsid): array {
    return [
        'email' => trim((string)get_setting(
            $pdo, $wsid, 'notify_email', '')),
        'on_fail' => get_setting_bool($pdo, $wsid, 'notify_on_fail', true),
        'on_kill' => get_setting_bool($pdo, $wsid, 'notify_on_kill', true),
    ];
}

/** Whether generation prompts include the workspace learnings loop. */
function learnings_in_prompt_enabled(PDO $pdo, int $wsid): bool {
    return get_setting_bool($pdo, $wsid, 'use_learnings_in_prompt', true);
}
