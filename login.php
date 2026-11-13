<?php
// The three-tools landing moved to /login/ on Nov 13, 2026 (HQ took the
// homepage). Keep old bookmarks and links working.
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== ''
    ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: /login/' . $qs, true, 301);
exit;
