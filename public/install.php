<?php
/**
 * One-time setup: checks DB connection and whether tables exist.
 * If tables are missing, shows instructions and a link to run schema in Hostinger.
 */
$base = dirname(__DIR__);
$cfg = require $base . '/config/database.php';
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'] ?? 3306, $cfg['dbname'], $cfg['charset'] ?? 'utf8mb4');

$error = null;
$tablesOk = false;
try {
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r = $pdo->query("SHOW TABLES LIKE 'users'");
    $tablesOk = $r && $r->fetch();
} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Setup – DROPILOU</title>
  <style>
    body { font-family: system-ui; max-width: 600px; margin: 2rem auto; padding: 0 1rem; background: #EEEEEE; color: #000; }
    .ok { color: #253900; }
    .err { color: #b71c1c; }
    pre { background: #fff; padding: 1rem; overflow: auto; border: 1px solid #ddd; }
    a { color: #253900; }
  </style>
</head>
<body>
  <h1>Database setup</h1>
  <?php if ($error): ?>
    <p class="err">Could not connect: <?= htmlspecialchars($error) ?></p>
    <p>Check <code>config/database.php</code> (host, dbname, username, password).</p>
  <?php elseif ($tablesOk): ?>
    <p class="ok">Database connected and tables exist.</p>
    <p><a href="index.php">Go to login</a></p>
    <p><small>You can delete or rename this file (install.php) for security.</small></p>
  <?php else: ?>
    <p>Connected to the database, but tables are missing.</p>
    <p>In Hostinger, open <strong>phpMyAdmin</strong> (or MySQL Remote), select database <code><?= htmlspecialchars($cfg['dbname']) ?></code>, then run the script in:</p>
    <p><strong>sql/schema.sql</strong></p>
    <p>Or copy the contents of <code>sql/schema.sql</code> from your project and execute it in the SQL tab.</p>
    <p><a href="index.php">Retry</a> after running the schema.</p>
  <?php endif; ?>
</body>
</html>
