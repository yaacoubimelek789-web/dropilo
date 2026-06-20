<?php
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    if ($email === '' || $pass === '') {
        $error = 'Email and password are required.';
    } elseif (strlen($pass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'Passwords do not match.';
    } else {
        $app = require dirname(__DIR__) . '/bootstrap.php';
        $st = $app->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) {
            $error = 'Email already registered.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $app->pdo->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)')->execute([$email, $hash, $name ?: null]);
            $_SESSION['user_id'] = (int) $app->pdo->lastInsertId();
            $_SESSION['user_name'] = $name ?: $email;
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register – DROPILOU</title>
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
      <h1>Join DROPILOU.</h1>
      <p>Start managing your Shopify store like a pro. Set up your account in minutes.</p>
      
      <ul class="auth-features">
        <li>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Quick Setup Process
        </li>
        <li>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Unlimited Order Tracking
        </li>
        <li>
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          Dedicated Support
        </li>
      </ul>
    </div>
  </div>

  <!-- Right Side: Register Form -->
  <div class="auth-form-container">
    <div class="auth-form-card">
      <h2>Create Account</h2>
      <p class="subtitle">Fill in the form to get started</p>

      <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      
      <form method="post">
        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" required placeholder="name@company.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="name">Full Name</label>
          <input type="text" id="name" name="name" placeholder="John Doe" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="6" placeholder="At least 6 characters">
        </div>
        <div class="form-group">
          <label for="password2">Confirm Password</label>
          <input type="password" id="password2" name="password2" required placeholder="Repeat password">
        </div>
        <button type="submit" class="btn">Create Account</button>
      </form>

      <div class="auth-form-footer">
        <p>Already have an account? <a href="index.php">Sign In</a></p>
      </div>
    </div>
  </div>
</div>
</body>
</html>
