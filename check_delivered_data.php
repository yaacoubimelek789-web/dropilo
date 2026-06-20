<?php
require 'bootstrap.php';
$deliveredList = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];
$placeholders = implode(',', array_fill(0, count($deliveredList), '?'));
$st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE LOWER(fiabilo_status) IN ($placeholders) AND delivered_at IS NULL");
$st->execute($deliveredList);
echo "Delivered orders with NULL delivered_at: " . $st->fetchColumn() . "\n";

$st2 = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE LOWER(fiabilo_status) IN ($placeholders) AND delivered_at IS NOT NULL");
$st2->execute($deliveredList);
echo "Delivered orders with SET delivered_at: " . $st2->fetchColumn() . "\n";
