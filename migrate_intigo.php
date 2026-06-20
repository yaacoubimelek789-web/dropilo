<?php
$app = require_once __DIR__ . '/bootstrap.php';
$pdo = $app->pdo;

try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN intigo_tracking_code VARCHAR(100) DEFAULT NULL");
    echo "Added intigo_tracking_code<br>";
} catch (Exception $e) {
    echo "intigo_tracking_code error: " . $e->getMessage() . "<br>";
}

try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN intigo_status VARCHAR(50) DEFAULT NULL AFTER intigo_tracking_code");
    echo "Added intigo_status<br>";
} catch (Exception $e) {
    echo "intigo_status error: " . $e->getMessage() . "<br>";
}
