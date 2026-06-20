<?php
require_once __DIR__ . '/src/App.php';
$app = new App();
$st = $app->pdo->query("DESCRIBE orders");
$cols = $st->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $cols);
