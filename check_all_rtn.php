<?php
require 'bootstrap.php';
$st = $pdo->query("SELECT name, fiabilo_status FROM orders WHERE fiabilo_status LIKE 'Rtn%'");
print_r($st->fetchAll(PDO::FETCH_ASSOC));
