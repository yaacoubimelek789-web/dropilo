<?php
require 'bootstrap.php';
$st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE fiabilo_status = ?");
$st->execute(['Rtn definitif']);
echo "Strict count for 'Rtn definitif': " . $st->fetchColumn() . "\n";
