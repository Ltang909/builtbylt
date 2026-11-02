<?php
/**
 * PHP 7.4 compatibility polyfills (Update 4.1 hotfix).
 *
 * The production host runs PHP < 8.0 for this site, where the PHP 8.0
 * string helpers below do not exist and calling one is a fatal
 * ("Call to undefined function str_starts_with()") — that is what
 * broke all three clear actions live. These are the canonical
 * implementations, each defined ONLY when the runtime does not
 * provide it (function_exists guard), so on PHP 8+ the natives win
 * and this file is a no-op.
 *
 * Load order: lib/common.php requires this file as its first
 * statement. Every API endpoint loads common.php first (via
 * api/db.php) and every page (index/login/setup) requires common.php
 * directly, so these definitions exist before any call site can run.
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $needle = (string)$needle;
        return $needle === ''
            || substr((string)$haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $needle = (string)$needle;
        if ($needle === '') {
            return true;
        }
        return substr((string)$haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        $needle = (string)$needle;
        return $needle === ''
            || strpos((string)$haystack, $needle) !== false;
    }
}
