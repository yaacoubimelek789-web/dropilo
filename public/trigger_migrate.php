<?php
$base = dirname(__DIR__);
require $base . '/bootstrap.php';

try {
    // 1. Add columns
    $alterSql = "ALTER TABLE `orders` 
        ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `confirmed`,
        ADD COLUMN `followup_at` DATETIME DEFAULT NULL AFTER `follow_up`,
        ADD COLUMN `shipped_at` DATETIME DEFAULT NULL,
        ADD COLUMN `delivered_at` DATETIME DEFAULT NULL,
        ADD COLUMN `returned_at` DATETIME DEFAULT NULL";
    
    try {
        $app->pdo->exec($alterSql);
        echo "Columns added successfully.<br>";
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "Columns already exist.<br>";
        } else {
            throw $e;
        }
    }

    // 2. Backfill
    $backfillSql = "
        UPDATE orders SET confirmed_at = COALESCE(order_created_at, created_at) WHERE confirmed = 1 AND confirmed_at IS NULL;
        UPDATE orders SET followup_at = created_at WHERE follow_up = 1 AND followup_at IS NULL;
        UPDATE orders SET shipped_at = created_at WHERE fiabilo_tracking_code IS NOT NULL AND shipped_at IS NULL;
        UPDATE orders SET delivered_at = created_at WHERE (LOWER(fiabilo_status) IN ('livré', 'livrés', 'livrer', 'delivered', 'reçu')) AND delivered_at IS NULL;
        UPDATE orders SET returned_at = created_at WHERE (LOWER(fiabilo_status) IN ('retourné', 'annulé', 'retour', 'refusé', 'returned', 'cancelled')) AND returned_at IS NULL;
    ";
    
    $app->pdo->exec($backfillSql);
    echo "Backfill completed successfully.<br>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
echo "Migration finished.";
