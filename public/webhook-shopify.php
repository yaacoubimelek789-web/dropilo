<?php
declare(strict_types=1);

/**
 * Shopify Webhook Handler
 * Receives 'orders/create' webhooks from Shopify.
 * URL: https://[domain]/public/webhook-shopify.php?sid=[shop_id]&token=[webhook_token]
 */

$base = dirname(__DIR__);
$app = require $base . '/bootstrap.php';

// 1. Validation
$shopId = (int) ($_GET['sid'] ?? 0);
$token = $_GET['token'] ?? '';

if ($shopId <= 0 || $token === '') {
    http_response_code(400);
    die('Bad Request: Missing parameters');
}

// Verify shop exists and token matches
$st = $app->pdo->prepare('SELECT id, user_id FROM shops WHERE id = ? AND webhook_token = ?');
$st->execute([$shopId, $token]);
$shop = $st->fetch();

if (!$shop) {
    http_response_code(403);
    die('Unauthorized: Invalid shop or token');
}

// 2. Receive Payload
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || (!isset($data['name']) && !isset($data['order_number']))) {
    // Possibly a test request or empty body
    http_response_code(200); 
    die('OK: Ready for orders');
}

// 3. Map Shopify Data to internal structure
$orderName = (string) ($data['name'] ?? $data['order_number'] ?? '');
if ($orderName === '') {
    http_response_code(200);
    die('OK: No order name');
}

// Prevent duplicate imports (per shop)
$checkDup = $app->pdo->prepare('SELECT id FROM orders WHERE shop_id = ? AND name = ?');
$checkDup->execute([$shopId, $orderName]);
if ($checkDup->fetch()) {
    http_response_code(200);
    die('OK: Already imported');
}

$o = [
    'name' => $orderName,
    'order_created_at' => date('Y-m-d H:i:s', strtotime($data['created_at'] ?? 'now')),
    'financial_status' => $data['financial_status'] ?? null,
    'fulfillment_status' => $data['fulfillment_status'] ?? null,
    'total' => (float) ($data['total_price'] ?? 0),
    'currency' => $data['currency'] ?? null,
    'billing_name' => $data['billing_address']['name'] ?? null,
    'billing_phone' => $data['billing_address']['phone'] ?? null,
    'billing_address' => $data['billing_address']['address1'] ?? null,
    'billing_city' => $data['billing_address']['city'] ?? null,
    'billing_zip' => $data['billing_address']['zip'] ?? null,
    'billing_country' => $data['billing_address']['country'] ?? null,
    'shipping_name' => $data['shipping_address']['name'] ?? null,
    'shipping_address' => $data['shipping_address']['address1'] ?? null,
    'shipping_city' => $data['shipping_address']['city'] ?? null,
    'shipping_zip' => $data['shipping_address']['zip'] ?? null,
    'notes' => $data['note'] ?? null,
    'phone' => $data['phone'] ?? $data['billing_address']['phone'] ?? $data['shipping_address']['phone'] ?? null,
];

// 4. Insert Order
try {
    $orderIns = $app->pdo->prepare('
        INSERT INTO orders (shop_id, name, order_created_at, financial_status, fulfillment_status, total, currency, shipping_method,
        billing_name, billing_phone, billing_address, billing_city, billing_zip, billing_country,
        shipping_name, shipping_address, shipping_city, shipping_zip, notes, phone)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    $orderIns->execute([
        $shopId, $o['name'], $o['order_created_at'], $o['financial_status'], $o['fulfillment_status'],
        $o['total'], $o['currency'], $data['shipping_lines'][0]['title'] ?? null,
        $o['billing_name'], $o['billing_phone'], $o['billing_address'], $o['billing_city'], $o['billing_zip'], $o['billing_country'],
        $o['shipping_name'], $o['shipping_address'], $o['shipping_city'], $o['shipping_zip'], $o['notes'], $o['phone'],
    ]);
    
    $orderId = (int) $app->pdo->lastInsertId();
    
    // 5. Build Product Index for Matching
    $index = OrderProductMatch::buildProductIndex($app->pdo, $shopId);
    
    // 6. Insert Line Items
    $lineIns = $app->pdo->prepare('
        INSERT INTO order_line_items (order_id, lineitem_name, lineitem_sku, lineitem_price, lineitem_quantity, vendor, fulfillment_status, product_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    foreach (($data['line_items'] ?? []) as $li) {
        $itemName = $li['name'] ?? 'Product';
        $itemSku = $li['sku'] ?? null;
        $productId = OrderProductMatch::findProductId($index, $itemName, $itemSku);
        
        $lineIns->execute([
            $orderId, $itemName, $itemSku, (float)($li['price'] ?? 0), (int)($li['quantity'] ?? 1),
            $li['vendor'] ?? null, $li['fulfillment_status'] ?? null, $productId
        ]);
    }
    
    http_response_code(201);
    echo "Order $orderName imported successfully";
} catch (Throwable $e) {
    // Log error internally if needed
    http_response_code(500);
    die('Internal Error');
}
