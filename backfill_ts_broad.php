<?php
require 'bootstrap.php';

// Broad backfill for Returned
$st = $pdo->prepare("UPDATE orders SET returned_at = created_at WHERE returned_at IS NULL AND (LOWER(fiabilo_status) LIKE 'rtn%' OR LOWER(fiabilo_status) LIKE '%retour%' OR LOWER(fiabilo_status) LIKE '%annul%' OR LOWER(fiabilo_status) LIKE '%refus%')");
$st->execute();
echo "Updated returned: " . $st->rowCount() . " rows\n";

// Broad backfill for Delivered
$st = $pdo->prepare("UPDATE orders SET delivered_at = created_at WHERE delivered_at IS NULL AND (LOWER(fiabilo_status) LIKE 'livr%' OR LOWER(fiabilo_status) LIKE 'deliv%' OR LOWER(fiabilo_status) LIKE 'reçu%')");
$st->execute();
echo "Updated delivered: " . $st->rowCount() . " rows\n";
