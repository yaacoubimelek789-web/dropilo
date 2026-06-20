<?php
/**
 * Database configuration with profile switching.
 *
 * Set credentials in .env (never commit .env).
 *
 * Profiles:
 * - local  : Docker / WAMP
 * - remote : Hostinger MySQL
 *
 * DB_PROFILE=local|remote (auto: localhost -> local, else remote)
 */
$profiles = [
    'local' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'dropilo_db',
        'username' => 'dropilo_user',
        'password' => 'dropilo_pass',
        'charset'  => 'utf8mb4',
    ],
    'remote' => [
        'host'     => 'srv2038.hstgr.io',
        'port'     => 3306,
        'dbname'   => 'u631627980_dropilo',
        'username' => 'u631627980_dropilo',
        'password' => '',
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

$selected['host'] = getenv('DB_HOST') ?: $selected['host'];
$selected['port'] = (int) (getenv('DB_PORT') ?: $selected['port']);
$selected['dbname'] = getenv('DB_NAME') ?: $selected['dbname'];
$selected['username'] = getenv('DB_USER') ?: $selected['username'];
$selected['password'] = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : $selected['password'];
$selected['charset'] = getenv('DB_CHARSET') ?: $selected['charset'];

return $selected;
