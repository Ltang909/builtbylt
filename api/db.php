<?php
// Shared bootstrap: loads config, opens the DB connection. Every endpoint
// in this folder requires this file first. The heavy lifting (PDO connect,
// helpers, auth) lives in ../lib/ — this file just wires it up and turns
// setup problems into JSON errors, like the rest of leontang.ca's API.

require_once __DIR__ . '/../lib/common.php';
require_once __DIR__ . '/../lib/auth.php';

if (!empty($GLOBALS['CONFIG_MISSING'])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'config_missing',
                      'hint' => 'Rename api/config.sample.php to api/config.php and fill it in.']);
    exit;
}
if (!isset($pdo) || $pdo === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'db_connection_failed']);
    exit;
}
