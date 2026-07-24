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
