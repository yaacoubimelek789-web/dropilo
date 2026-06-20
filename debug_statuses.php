<?php
require 'bootstrap.php';
$st = $pdo->query("SELECT DISTINCT fiabilo_status FROM orders WHERE fiabilo_status IS NOT NULL");
print_r($st->fetchAll(PDO::FETCH_COLUMN));
