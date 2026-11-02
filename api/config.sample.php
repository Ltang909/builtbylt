<?php
/**
 * Rename this file to config.php and fill in your real values.
 * config.php should NOT be committed anywhere public — it's already
 * covered by the .htaccess in this folder blocking direct access to
 * config files and the db.php bootstrap, but keep it private regardless.
 *
 * Get DB_HOST / DB_NAME / DB_USER / DB_PASS from:
 * Hostinger hPanel → Databases → MySQL Databases
 * (DB_HOST is almost always "localhost" on Hostinger shared hosting)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// The URL path this panel is served from. The deploy puts the panel at
// leontang.ca/panel/, so '/panel' is right. No trailing slash. If you
// ever move it to a subdomain root, set this to ''.
define('APP_BASE_PATH', '/panel');
