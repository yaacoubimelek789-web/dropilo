<?php
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($email === '' || $pass === '') {
        $error = 'Email and password are required.';
    } else {
        if (!isset($app) || !is_object($app)) {
            $app = require dirname(__DIR__) . '/bootstrap.php';
        }
        $st = $app->pdo->prepare('SELECT id, email, password_hash, name FROM users WHERE LOWER(email) = LOWER(?)');
        $st->execute([$email]);
        $user = $st->fetch();
        if ($user && password_verify($pass, $user['password_hash'])) {
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = $user['name'] ?: $user['email'];
            header('Location: index.php');
            exit;
        }
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login – DROPILOU</title>
  <?php
  $assetPrefix = $appPublicPrefix ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  if ($assetPrefix === '' || $assetPrefix === '.') $assetPrefix = '';
  $cssFile = dirname(__DIR__) . '/public/assets/style.css';
  $cssVer = is_file($cssFile) ? (string) filemtime($cssFile) : (string) time();
  ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix) ?>/assets/style.css?v=<?= htmlspecialchars($cssVer) ?>">
</head>
<body>
<div class="auth-split-container">
  <!-- Left Side: Introduction -->
  <div class="auth-sidebar">
    <div class="auth-sidebar-content">
      <h1>DROPILOU.</h1>
      <p>Streamline your Shopify order management with DROPILOU. Professional tools for professional sellers.</p>
      
      <ul class="auth-features">
        <li>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Real-time Order Syncing
        </li>
        <li>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Smart Tracking Updates
        </li>
        <li>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Advanced Sales Dashboard
        </li>
      </ul>
    </div>
  </div>

  <!-- Right Side: Login Form -->
  <div class="auth-form-container">
    <div class="auth-form-card">
      <h2>Welcome Back</h2>
      <p class="subtitle">Please enter your details to sign in</p>

      <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      
      <form method="post">
        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required placeholder="name@company.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn">Sign In</button>
      </form>

      <div class="auth-form-footer">
        <p>Don't have an account? <a href="index.php?page=register">Create an account</a></p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
