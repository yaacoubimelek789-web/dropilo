<?php
require 'bootstrap.php';

// Backfill Returned
$returnedList = ['retourné', 'annulé', 'retour', 'refusé', 'returned', 'cancelled', 'rtn definit', 'rtn', 'echouée', 'annulée', 'refusée', 'retourne'];
foreach ($returnedList as $status) {
    $st = $pdo->prepare("UPDATE orders SET returned_at = COALESCE(fiabilo_returned_at, created_at) WHERE returned_at IS NULL AND LOWER(fiabilo_status) = ?");
    $st->execute([mb_strtolower($status)]);
    echo "Updated " . $status . ": " . $st->rowCount() . " rows\n";
}

// Backfill Delivered
$deliveredList = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];
foreach ($deliveredList as $status) {
    $st = $pdo->prepare("UPDATE orders SET delivered_at = COALESCE(fiabilo_delivered_at, created_at) WHERE delivered_at IS NULL AND LOWER(fiabilo_status) = ?");
    $st->execute([mb_strtolower($status)]);
    echo "Updated " . $status . ": " . $st->rowCount() . " rows\n";
}

// Backfill Shipped
$shippedList = ['en cours', 'en cours de livraison', 'expédié', 'shipping', 'shipped', 'en cours transit'];
foreach ($shippedList as $status) {
    $st = $pdo->prepare("UPDATE orders SET shipped_at = created_at WHERE shipped_at IS NULL AND LOWER(fiabilo_status) = ?");
    $st->execute([mb_strtolower($status)]);
    echo "Updated " . $status . ": " . $st->rowCount() . " rows\n";
}
