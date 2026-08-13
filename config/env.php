<?php
declare(strict_types=1);

/**
 * Minimal .env loader (no Composer dependency).
 * Values already set in the environment are not overwritten.
 */
function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = trim($value, "\"'");
        }

        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

function requestIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_CF_VISITOR']) && str_contains((string) $_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"')) {
        return true;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_starts_with($host, 'localhost:')
        || str_starts_with($host, '127.0.0.1:');

    return $host !== '' && !$isLocal;
}

function requestBaseUrl(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return (requestIsHttps() ? 'https://' : 'http://') . $host;
}
