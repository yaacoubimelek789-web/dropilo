<?php
/**
 * Database configuration with profile switching.
 *
 * Supported profiles:
 * - local  : WAMP localhost
 * - remote : Hostinger MySQL
 *
 * Switch profile by setting DB_PROFILE:
 * - DB_PROFILE=local
 * - DB_PROFILE=remote
 *
 * If DB_PROFILE is not set, localhost/127.0.0.1 will use "local",
 * all other hosts will use "remote".
 */
$profiles = [
    'local' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'u755103422_gloras',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
    'remote' => [
        'host'     => 'srv2038.hstgr.io',
        'port'     => 3306,
        'dbname'   => 'u631627980_dropilo',
        'username' => 'u631627980_dropilo',
        'password' => 'Morino1234@@',
        'charset'  => 'utf8mb4',
    ],
];

$requestedProfile = getenv('DB_PROFILE') ?: '';
$requestedProfile = strtolower(trim($requestedProfile));

if (!isset($profiles[$requestedProfile])) {
    $serverHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLocalHost = in_array($serverHost, ['localhost', '127.0.0.1', '::1'], true)
        || str_starts_with($serverHost, 'localhost:')
        || str_starts_with($serverHost, '127.0.0.1:');

    $requestedProfile = $isLocalHost ? 'local' : 'remote';
}

$selected = $profiles[$requestedProfile];

// Optional env overrides (useful for deployment without editing this file).
$selected['host'] = getenv('DB_HOST') ?: $selected['host'];
$selected['port'] = (int) (getenv('DB_PORT') ?: $selected['port']);
$selected['dbname'] = getenv('DB_NAME') ?: $selected['dbname'];
$selected['username'] = getenv('DB_USER') ?: $selected['username'];
$selected['password'] = getenv('DB_PASS') ?: $selected['password'];
$selected['charset'] = getenv('DB_CHARSET') ?: $selected['charset'];

return $selected;
