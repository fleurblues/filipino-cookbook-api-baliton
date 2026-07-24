<?php
/**
 * Example application configuration.
 * Copy this file to config.php and replace the placeholder values,
 * or set the matching environment variables.
 */

$host    = getenv('DB_HOST') ?: 'localhost';
$db      = getenv('DB_NAME') ?: 'YOUR_DATABASE_NAME';
$user    = getenv('DB_USER') ?: 'YOUR_DATABASE_USERNAME';
$pass    = getenv('DB_PASS') ?: 'YOUR_DATABASE_PASSWORD';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

define('API_TOKEN', getenv('API_TOKEN') ?: 'YOUR_API_TOKEN_HERE');

// Rate limiting: max requests per client IP within the window (seconds).
// Set RATE_LIMIT_MAX to 0 to disable.
define('RATE_LIMIT_MAX', (int) (getenv('RATE_LIMIT_MAX') !== false && getenv('RATE_LIMIT_MAX') !== ''
    ? getenv('RATE_LIMIT_MAX')
    : 120));
define('RATE_LIMIT_WINDOW', (int) (getenv('RATE_LIMIT_WINDOW') !== false && getenv('RATE_LIMIT_WINDOW') !== ''
    ? getenv('RATE_LIMIT_WINDOW')
    : 60));
