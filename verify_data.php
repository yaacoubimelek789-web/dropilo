<?php
require 'bootstrap.php';
$st = $pdo->query("SELECT name, fiabilo_status, returned_at FROM orders WHERE name = '#1162' OR lower(fiabilo_status) like '%rtn%'");
print_r($st->fetchAll());
