<?php
/**
 * Database defaults (XAMPP local). Safe to commit.
 * Production credentials go in config/db-config.local.php, which overrides
 * these and is never committed or included in a deploy zip.
 */
declare(strict_types=1);

$DB = [
    'host'    => '127.0.0.1',
    'user'    => 'root',
    'pass'    => '',
    'name'    => 'yarn_store',
    'port'    => 3306,
    'charset' => 'utf8mb4',
];

$localDb = __DIR__ . '/db-config.local.php';
if (is_file($localDb)) {
    $override = require $localDb;
    if (is_array($override)) {
        $DB = array_merge($DB, $override);
    }
}
