<?php
declare(strict_types=1);

$base = __DIR__;
require_once $base . '/config/env.php';
loadEnvFile($base . '/.env');

$debug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

if (file_exists($base . '/vendor/autoload.php')) {
    require_once $base . '/vendor/autoload.php';
}
require_once $base . '/src/CsvParser.php';
require_once $base . '/src/ProductImport.php';
require_once $base . '/src/OrderImport.php';
require_once $base . '/src/OrderProductMatch.php';
require_once $base . '/src/FiabiloHelper.php';
require_once $base . '/src/IntigoHelper.php';
require_once $base . '/src/GeminiHelper.php';
require_once $base . '/src/UserDataContext.php';
require_once $base . '/src/DropiloExport.php';
require_once $base . '/src/DropiloImport.php';

$cfg = require $base . '/config/database.php';
$appConfig = require $base . '/config/app.php';
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $cfg['host'],
    $cfg['port'] ?? 3306,
    $cfg['dbname'],
    $cfg['charset'] ?? 'utf8mb4'
);
try {
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'getaddrinfo failed') !== false || strpos($e->getMessage(), 'php_network_getaddresses') !== false) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $currentUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        
        die("<!DOCTYPE html>
        <html lang='en'>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
          <title>Connection Lost - Dropilo</title>
          <style>
            :root { --accent: #00b37e; --text: #1a1b1e; --muted: #6b7280; --bg: #f8fafc; }
            body { margin: 0; font-family: 'Inter', -apple-system, sans-serif; background: var(--bg); display: flex; align-items: center; justify-content: center; height: 100vh; color: var(--text); }
            .error-card { background: white; padding: 2.5rem; border-radius: 20px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 90%; }
            .icon-wrap { width: 64px; height: 64px; background: #fee2e2; color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; }
            h1 { font-size: 1.5rem; font-weight: 800; margin: 0 0 0.5rem; letter-spacing: -0.02em; }
            p { color: var(--muted); font-size: 0.95rem; line-height: 1.5; margin-bottom: 2rem; }
            .reload-btn { background: var(--accent); color: white; border: none; padding: 0.8rem 1.5rem; border-radius: 12px; font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
            .reload-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,179,126,0.3); }
            .reload-btn:active { transform: translateY(0); }
          </style>
        </head>
        <body>
          <div class='error-card'>
            <div class='icon-wrap'>
              <svg width='32' height='32' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><path d='M17.5 19c.6 0 1.1-.4 1.1-1 0-.6-.5-1.1-1.1-1.1s-1.1.5-1.1 1.1c0 .6.5 1 1.1 1zM20.9 9.9c-1.8-1.5-4.1-2.4-6.6-2.4-2.5 0-4.8.9-6.6 2.4l1.4 1.4c1.4-1.2 3.2-1.9 5.2-1.9 2 0 3.8.7 5.2 1.9l1.4-1.4zM23.7 7.1C21 4.5 17.5 3 13.5 3S6 4.6 3.3 7.1L4.7 8.5c2.4-2.3 5.5-3.6 9-3.6s6.6 1.3 9 3.6l1.4-1.4z'/><path d='M1 1l22 22'/></svg>
            </div>
            <h1>Connection Unstable</h1>
            <p>Your internet or DNS settings are preventing a secure link to the remote Hostinger database. It's better to reload your page to try establishing the connection again.</p>
            <a href='" . htmlspecialchars($currentUrl) . "' class='reload-btn'>
              <svg width='18' height='18' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='23 4 23 10 17 10'></polyline><path d='M20.49 15a9 9 0 1 1-2.12-9.36L23 10'></path></svg>
              Reload Dashboard
            </a>
          </div>
        </body>
        </html>");
    }
    throw $e;
}

// --- LAZY MIGRATION: Event Timestamps ---
try {
    $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    $needed = [
        'confirmed_at' => 'DATETIME DEFAULT NULL AFTER `confirmed`',
        'followup_at'  => 'DATETIME DEFAULT NULL AFTER `follow_up`',
        'shipped_at'   => 'DATETIME DEFAULT NULL',
        'delivered_at' => 'DATETIME DEFAULT NULL',
        'returned_at'  => 'DATETIME DEFAULT NULL',
        'fiabilo_status' => 'VARCHAR(100) DEFAULT NULL',
        'fiabilo_last_sync' => 'DATETIME DEFAULT NULL',
        'fiabilo_tracking_code' => 'VARCHAR(100) DEFAULT NULL'
    ];
    
    $toAdd = [];
    foreach ($needed as $col => $definition) {
        if (!in_array($col, $cols)) {
            $toAdd[] = "ADD COLUMN `$col` $definition";
        }
    }
    
    if (!empty($toAdd)) {
        $pdo->exec("ALTER TABLE `orders` " . implode(', ', $toAdd));
        
        // Initial Backfill for the newly added columns
        if (in_array('confirmed_at', $cols) === false) {
             $pdo->exec("UPDATE orders SET confirmed_at = COALESCE(order_created_at, created_at) WHERE confirmed = 1");
        }
        if (in_array('followup_at', $cols) === false) {
            $pdo->exec("UPDATE orders SET followup_at = created_at WHERE follow_up = 1");
        }
        if (in_array('shipped_at', $cols) === false) {
            $pdo->exec("UPDATE orders SET shipped_at = created_at WHERE fiabilo_tracking_code IS NOT NULL");
        }
        // Sync existing fiabilo timestamps to new columns if they exist
        if (in_array('fiabilo_delivered_at', $cols)) {
            $pdo->exec("UPDATE orders SET delivered_at = fiabilo_delivered_at WHERE delivered_at IS NULL");
        }
        if (in_array('fiabilo_returned_at', $cols)) {
            $pdo->exec("UPDATE orders SET returned_at = fiabilo_returned_at WHERE returned_at IS NULL");
        }
    }
} catch (Exception $e) {
    // Silently continue if migration fails, to avoid breaking the whole app
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('ensurePdoAlive')) {
    /**
     * Helper to ensure PDO connection is still alive.
     * Remote MySQL (Hostinger) can "go away" during long loops (e.g. status sync).
     */
    function ensurePdoAlive($app): void {
        try {
            @$app->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            if ($e->getCode() == 'HY000' || strpos($e->getMessage(), 'gone away') !== false) {
                $cfg = $app->config;
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $cfg['host'],
                    $cfg['port'] ?? 3306,
                    $cfg['dbname'],
                    $cfg['charset'] ?? 'utf8mb4'
                );
                $app->pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            }
        }
    }
}

return (object) [
    'pdo' => $pdo,
    'config' => $cfg,
    'app' => $appConfig,
    'ensurePdoAlive' => 'ensurePdoAlive' // Export the function name or use a closure
];
