<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'fiabilo';
$pageTitle = 'FIABILO';

$message = '';
$st = $app->pdo->prepare('SELECT add_token_encrypted, tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
$st->execute([$uid, 'fiabilo']);
$row = $st->fetch();
$hasSavedTokens = $row && !empty($row['add_token_encrypted']) && !empty($row['tracking_token_encrypted']);
$encryptionKey = $app->app['encryption_key'] ?? '';

// Save tokens
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tokens'])) {
    $addToken = trim($_POST['add_token'] ?? '');
    $trackingToken = trim($_POST['tracking_token'] ?? '');
    if ($addToken === '' || $trackingToken === '') {
        $message = '<div class="alert alert-error">Please enter both Add Token and Tracking Token.</div>';
    } else {
        try {
            $addEnc = FiabiloHelper::encrypt($addToken, $encryptionKey);
            $trackEnc = FiabiloHelper::encrypt($trackingToken, $encryptionKey);
            $app->pdo->prepare('INSERT INTO user_integrations (user_id, provider, add_token_encrypted, tracking_token_encrypted) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE add_token_encrypted = VALUES(add_token_encrypted), tracking_token_encrypted = VALUES(tracking_token_encrypted)')
                ->execute([$uid, 'fiabilo', $addEnc, $trackEnc]);
            $message = '<div class="alert alert-success">Tokens saved securely.</div>';
            $st = $app->pdo->prepare('SELECT add_token_encrypted, tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
            $st->execute([$uid, 'fiabilo']);
            $row = $st->fetch();
            $hasSavedTokens = $row && !empty($row['add_token_encrypted']) && !empty($row['tracking_token_encrypted']);
            $addToken = '';
            $trackingToken = '';
        } catch (Throwable $e) {
            $message = '<div class="alert alert-error">' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Test connection (use form values or saved)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_connection'])) {
    $addToken = trim($_POST['add_token'] ?? '');
    $trackingToken = trim($_POST['tracking_token'] ?? '');
    if ($addToken === '' || $trackingToken === '') {
        if ($hasSavedTokens) {
            try {
                $addToken = FiabiloHelper::decrypt($row['add_token_encrypted'], $encryptionKey);
                $trackingToken = FiabiloHelper::decrypt($row['tracking_token_encrypted'], $encryptionKey);
            } catch (Throwable $e) {
                $message = '<div class="alert alert-error">Could not read saved tokens.</div>';
                $addToken = '';
                $trackingToken = '';
            }
        }
    }
    if ($addToken !== '' && $trackingToken !== '') {
        $result = FiabiloHelper::testConnection($addToken, $trackingToken);
        if (!empty($result['ok'])) {
            $message = '<div class="alert alert-success">' . htmlspecialchars($result['message'] ?? 'Connection OK.') . '</div>';
        } else {
            $message = '<div class="alert alert-error">' . htmlspecialchars($result['error'] ?? 'Connection failed.') . '</div>';
        }
    } else {
        $message = '<div class="alert alert-error">Enter both tokens above and try again, or save tokens first.</div>';
    }
}

$content = '<h1>FIABILO</h1>';
$content .= '<p>Connect your Fiabilo account with <strong>Add Token</strong> (for creating shipments) and <strong>Tracking Token</strong> (for checking status). Get these from your Fiabilo Expéditeur account.</p>';
$content .= $message;
$content .= '<div class="card"><form method="post">';
$content .= '<div class="form-group"><label for="add_token">Add Token</label><input type="password" id="add_token" name="add_token" value="" placeholder="' . ($hasSavedTokens ? 'Saved — enter new to update' : '') . '" autocomplete="off"></div>';
$content .= '<div class="form-group"><label for="tracking_token">Tracking Token</label><input type="password" id="tracking_token" name="tracking_token" value="" placeholder="' . ($hasSavedTokens ? 'Saved — enter new to update' : '') . '" autocomplete="off"></div>';
$content .= '<div class="flex"><button type="submit" name="save_tokens" value="1" class="btn">Save tokens</button> <button type="submit" name="test_connection" value="1" class="btn btn-secondary">Test connection</button></div>';
$content .= '</form></div>';
if ($hasSavedTokens) {
    $content .= '<p style="font-size:0.9rem;">Tokens are stored encrypted. Use <strong>Test connection</strong> to verify (uses saved tokens if fields are empty).</p>';
}

require $base . '/layouts/layout.php';
