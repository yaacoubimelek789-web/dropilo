<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    $app = require __DIR__ . '/bootstrap.php';
    echo "<h1>Database Migration Status</h1>";
    
    $pdo = $app->pdo;
    $colsRes = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p>Current Columns: " . implode(', ', $colsRes) . "</p>";
    
    $needed = [
        'confirmed_at' => 'DATETIME DEFAULT NULL AFTER `confirmed`',
        'followup_at'  => 'DATETIME DEFAULT NULL AFTER `follow_up`',
        'shipped_at'   => 'DATETIME DEFAULT NULL',
        'delivered_at' => 'DATETIME DEFAULT NULL',
        'returned_at'  => 'DATETIME DEFAULT NULL',
        'fiabilo_status' => 'VARCHAR(100) DEFAULT NULL',
        'fiabilo_last_sync' => 'DATETIME DEFAULT NULL',
        'fiabilo_tracking_code' => 'VARCHAR(100) DEFAULT NULL',
        'intigo_tracking_code' => 'VARCHAR(100) DEFAULT NULL',
        'intigo_status' => 'VARCHAR(50) DEFAULT NULL AFTER intigo_tracking_code',
        'intigo_sent_at' => 'DATETIME DEFAULT NULL',
        'intigo_last_sync' => 'DATETIME DEFAULT NULL'
    ];
    
    $toAdd = [];
    foreach ($needed as $col => $definition) {
        if (!in_array($col, $colsRes)) {
            $toAdd[] = "ADD COLUMN `$col` $definition";
        }
    }
    
    if (!empty($toAdd)) {
        $sql = "ALTER TABLE `orders` " . implode(', ', $toAdd);
        echo "<p>Running SQL: <code>$sql</code></p>";
        $pdo->exec($sql);
        echo "<p style='color:green; font-weight:bold;'>Migration completed successfully!</p>";
    } else {
        echo "<p style='color:blue;'>All columns already exist. No migration needed.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red; font-weight:bold;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
