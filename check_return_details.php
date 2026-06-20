<?php
require 'bootstrap.php';
$st = $pdo->query("SELECT name, fiabilo_status FROM orders WHERE returned_at IS NOT NULL");
print_r($st->fetchAll(PDO::FETCH_ASSOC));
