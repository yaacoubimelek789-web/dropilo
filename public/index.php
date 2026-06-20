<?php
/**
 * Entry point - route to login, register, or dashboard.
 */
$base = dirname(__DIR__);
$app = require $base . '/bootstrap.php';
$user = $_SESSION['user_id'] ?? null;

if ($user === null) {
    $action = $_GET['page'] ?? 'login';
    if ($action === 'register') {
        require $base . '/public/register.php';
        return;
    }
    require $base . '/public/login.php';
    return;
}

// Logged in: dashboard
$page = $_GET['page'] ?? 'dashboard';
$allowed = [
    'dashboard', 'shops', 'shop-create', 'shop-view',
    'order-upload', 'orders', 'order-view', 'orders-confirmed', 'orders-followup',
    'product-import', 'products', 'product-view',
    'cart', 'cart-ajax', 'integration', 'ready', 'shipping-status', 'tracking-ajax', 'delivered-orders', 'returned-orders', 'manual-order', 'leads-centre', 'leads-export-ajax',
    'settings', 'ask-imo', 'chat-ajax', 'bot-command', 'gemini-test', 'logout'
];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}
if ($page === 'logout') {
    unset($_SESSION['user_id'], $_SESSION['user_name']);
    header('Location: index.php');
    exit;
}
if ($page === 'dashboard') {
    require $base . '/pages/dashboard.php';
    return;
}
if (strpos($page, 'shop') === 0) {
    require $base . '/pages/shops.php';
    return;
}
if (strpos($page, 'order') === 0) {
    require $base . '/pages/orders.php';
    return;
}
if (strpos($page, 'product') === 0) {
    require $base . '/pages/products.php';
    return;
}
if ($page === 'cart-ajax') {
    require $base . '/pages/cart-ajax.php';
    return;
}
if ($page === 'cart') {
    require $base . '/pages/cart.php';
    return;
}
if ($page === 'integration') {
    require $base . '/pages/integration.php';
    return;
}
if ($page === 'ready') {
    require $base . '/pages/ready.php';
    return;
}
if ($page === 'shipping-status') {
    require $base . '/pages/shipping-status.php';
    return;
}
if ($page === 'delivered-orders') {
    require $base . '/pages/delivered-orders.php';
    return;
}
if ($page === 'returned-orders') {
    require $base . '/pages/returned-orders.php';
    return;
}
if ($page === 'tracking-ajax') {
    require $base . '/pages/tracking-ajax.php';
    return;
}
if ($page === 'settings') {
    require $base . '/pages/settings.php';
    return;
}
if ($page === 'ask-imo') {
    require $base . '/pages/ask-imo.php';
    return;
}
if ($page === 'chat-ajax') {
    require $base . '/pages/chat-ajax.php';
    return;
}
if ($page === 'bot-command') {
    require $base . '/pages/bot-command.php';
    return;
}
if ($page === 'gemini-test') {
    require $base . '/pages/gemini-test.php';
    return;
}
if ($page === 'manual-order') {
    require $base . '/pages/manual-order.php';
    return;
}
if ($page === 'leads-centre') {
    require $base . '/pages/leads-centre.php';
    return;
}
if ($page === 'leads-export-ajax') {
    require $base . '/pages/leads-export-ajax.php';
    return;
}
require $base . '/pages/dashboard.php';
