<?php
require 'bootstrap.php';

$uid = (int)$_SESSION['user_id'] ?? 1;
$today = date('Y-m-d');

$deliveredList = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];
$placeholders = implode(',', array_fill(0, count($deliveredList), '?'));

$st = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND LOWER(o.fiabilo_status) IN ($placeholders) AND DATE(o.delivered_at) = ?");
$st->execute(array_merge([$uid], $deliveredList, [$today]));
echo "Delivered orders Today: " . $st->fetchColumn() . "\n";

$st2 = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND LOWER(o.fiabilo_status) IN ($placeholders)");
$st2->execute(array_merge([$uid], $deliveredList));
echo "Delivered orders Lifetime: " . $st2->fetchColumn() . "\n";
