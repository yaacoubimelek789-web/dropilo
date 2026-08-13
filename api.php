<?php
/**
 * Read-only data API for Claude.
 *
 * Auth: send the key from .env (CLAUDE_API_KEY) via one of:
 *   - Header:  X-API-Key: <key>
 *   - Header:  Authorization: Bearer <key>
 *   - Query:   ?api_key=<key>   (fallback for clients that cannot set headers)
 *
 * All responses are JSON. Only GET is allowed — no data is ever modified.
 * Data is scoped to the single account set in CLAUDE_API_USER_EMAIL.
 *
 * Endpoints:
 *   api.php?action=summary                     Full business overview (text context)
 *   api.php?action=stats                       Key numbers (orders, revenue, delivery rates)
 *   api.php?action=shops                       List shops
 *   api.php?action=orders                      List orders (filters below)
 *       &status=confirmed|followup|delivered|returned|shipped|pending
 *       &shop_id=N  &search=text  &since=YYYY-MM-DD  &until=YYYY-MM-DD
 *       &limit=N (max 100, default 25)  &offset=N
 *   api.php?action=order&id=N                  Single order with line items (or &name=#1081)
 *   api.php?action=products                    List products (&shop_id=N &search=text &limit &offset)
 */

$app = require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function apiRespond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function apiError($status, $message)
{
    apiRespond($status, ['success' => false, 'error' => $message]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError(405, 'Only GET requests are allowed. This API is read-only.');
}

// --- Authentication ---
$configuredKey = $app->app['claude_api_key'] ?? '';
if ($configuredKey === '') {
    apiError(503, 'API key not configured on the server. Set CLAUDE_API_KEY in .env.');
}

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($providedKey === '' && isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
    $providedKey = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
}
if ($providedKey === '') {
    $providedKey = $_GET['api_key'] ?? '';
}

if ($providedKey === '' || !hash_equals($configuredKey, (string) $providedKey)) {
    apiError(401, 'Invalid or missing API key.');
}

// --- Resolve the account this key serves ---
$apiUserEmail = $app->app['claude_api_user_email'] ?? '';
if ($apiUserEmail === '') {
    apiError(503, 'CLAUDE_API_USER_EMAIL not configured on the server.');
}
$userStmt = $app->pdo->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
$userStmt->execute([$apiUserEmail]);
$apiUser = $userStmt->fetch();
if (!$apiUser) {
    apiError(503, 'No user found for CLAUDE_API_USER_EMAIL. Check the email in .env matches your Dropilo login.');
}
$userId = (int) $apiUser['id'];

$action = $_GET['action'] ?? '';

$limit = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

switch ($action) {

    case 'summary': {
        $context = new UserDataContext($app->pdo, $userId);
        apiRespond(200, [
            'success' => true,
            'user' => ['name' => $apiUser['name'], 'email' => $apiUser['email']],
            'summary' => $context->build(),
        ]);
    }

    case 'stats': {
        $stmt = $app->pdo->prepare("
            SELECT
                COUNT(*) AS total_orders,
                COUNT(CASE WHEN o.confirmed = 1 THEN 1 END) AS confirmed_orders,
                COUNT(CASE WHEN o.follow_up = 1 THEN 1 END) AS followup_orders,
                COUNT(CASE WHEN o.fiabilo_tracking_code IS NOT NULL THEN 1 END) AS shipped_orders,
                COUNT(CASE WHEN o.fiabilo_status IN ('livré', 'livrés', 'livrer', 'delivered') THEN 1 END) AS delivered_orders,
                COUNT(CASE WHEN o.fiabilo_status IN ('retourné', 'retour', 'returned') THEN 1 END) AS returned_orders,
                SUM(o.total) AS total_revenue,
                SUM(CASE WHEN o.confirmed = 1 THEN o.total ELSE 0 END) AS confirmed_revenue,
                SUM(CASE WHEN o.fiabilo_status IN ('livré', 'livrés', 'livrer', 'delivered') THEN o.total ELSE 0 END) AS delivered_revenue,
                MIN(o.order_created_at) AS first_order_date,
                MAX(o.order_created_at) AS last_order_date
            FROM orders o
            INNER JOIN shops s ON o.shop_id = s.id
            WHERE s.user_id = ?
        ");
        $stmt->execute([$userId]);
        $stats = $stmt->fetch();

        $prodStmt = $app->pdo->prepare("
            SELECT COUNT(*) AS total_products,
                   SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) AS active_products
            FROM products p
            INNER JOIN shops s ON p.shop_id = s.id
            WHERE s.user_id = ?
        ");
        $prodStmt->execute([$userId]);
        $prodStats = $prodStmt->fetch();

        apiRespond(200, [
            'success' => true,
            'orders' => $stats,
            'products' => $prodStats,
        ]);
    }

    case 'shops': {
        $stmt = $app->pdo->prepare("
            SELECT s.id, s.name, s.created_at,
                   COUNT(o.id) AS order_count,
                   SUM(CASE WHEN o.confirmed = 1 THEN o.total ELSE 0 END) AS confirmed_revenue
            FROM shops s
            LEFT JOIN orders o ON o.shop_id = s.id
            WHERE s.user_id = ?
            GROUP BY s.id, s.name, s.created_at
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$userId]);
        apiRespond(200, ['success' => true, 'shops' => $stmt->fetchAll()]);
    }

    case 'orders': {
        $where = ['s.user_id = ?'];
        $params = [$userId];

        $status = $_GET['status'] ?? '';
        if ($status === 'confirmed') {
            $where[] = 'o.confirmed = 1';
        } elseif ($status === 'followup') {
            $where[] = 'o.follow_up = 1';
        } elseif ($status === 'delivered') {
            $where[] = "o.fiabilo_status IN ('livré', 'livrés', 'livrer', 'delivered')";
        } elseif ($status === 'returned') {
            $where[] = "o.fiabilo_status IN ('retourné', 'retour', 'returned')";
        } elseif ($status === 'shipped') {
            $where[] = 'o.fiabilo_tracking_code IS NOT NULL';
        } elseif ($status === 'pending') {
            $where[] = 'o.confirmed = 0 AND o.follow_up = 0 AND o.fiabilo_tracking_code IS NULL';
        }

        if (!empty($_GET['shop_id'])) {
            $where[] = 'o.shop_id = ?';
            $params[] = (int) $_GET['shop_id'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(o.name LIKE ? OR o.billing_name LIKE ? OR o.billing_phone LIKE ?)';
            $term = '%' . $_GET['search'] . '%';
            array_push($params, $term, $term, $term);
        }
        if (!empty($_GET['since'])) {
            $where[] = 'o.order_created_at >= ?';
            $params[] = $_GET['since'] . ' 00:00:00';
        }
        if (!empty($_GET['until'])) {
            $where[] = 'o.order_created_at <= ?';
            $params[] = $_GET['until'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $app->pdo->prepare("
            SELECT COUNT(*) FROM orders o INNER JOIN shops s ON o.shop_id = s.id WHERE $whereSql
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $app->pdo->prepare("
            SELECT o.id, o.name, o.order_created_at, o.total, o.currency,
                   o.financial_status, o.fulfillment_status,
                   o.billing_name, o.billing_phone, o.billing_city,
                   o.confirmed, o.follow_up, o.fiabilo_tracking_code, o.fiabilo_status,
                   o.confirmed_at, o.shipped_at, o.delivered_at, o.returned_at,
                   s.name AS shop_name
            FROM orders o
            INNER JOIN shops s ON o.shop_id = s.id
            WHERE $whereSql
            ORDER BY o.order_created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        apiRespond(200, [
            'success' => true,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'orders' => $stmt->fetchAll(),
        ]);
    }

    case 'order': {
        if (!empty($_GET['id'])) {
            $stmt = $app->pdo->prepare("
                SELECT o.*, s.name AS shop_name
                FROM orders o INNER JOIN shops s ON o.shop_id = s.id
                WHERE s.user_id = ? AND o.id = ?
            ");
            $stmt->execute([$userId, (int) $_GET['id']]);
        } elseif (!empty($_GET['name'])) {
            $stmt = $app->pdo->prepare("
                SELECT o.*, s.name AS shop_name
                FROM orders o INNER JOIN shops s ON o.shop_id = s.id
                WHERE s.user_id = ? AND o.name = ?
            ");
            $stmt->execute([$userId, $_GET['name']]);
        } else {
            apiError(400, 'Provide ?id=N or ?name=#1081 to fetch an order.');
        }

        $order = $stmt->fetch();
        if (!$order) {
            apiError(404, 'Order not found.');
        }

        $itemsStmt = $app->pdo->prepare("
            SELECT id, lineitem_name, lineitem_sku, lineitem_price, lineitem_quantity,
                   vendor, fulfillment_status, product_id
            FROM order_line_items WHERE order_id = ?
        ");
        $itemsStmt->execute([$order['id']]);
        $order['line_items'] = $itemsStmt->fetchAll();

        apiRespond(200, ['success' => true, 'order' => $order]);
    }

    case 'products': {
        $where = ['s.user_id = ?'];
        $params = [$userId];

        if (!empty($_GET['shop_id'])) {
            $where[] = 'p.shop_id = ?';
            $params[] = (int) $_GET['shop_id'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(p.title LIKE ? OR p.variant_sku LIKE ?)';
            $term = '%' . $_GET['search'] . '%';
            array_push($params, $term, $term);
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $app->pdo->prepare("
            SELECT COUNT(*) FROM products p INNER JOIN shops s ON p.shop_id = s.id WHERE $whereSql
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $app->pdo->prepare("
            SELECT p.id, p.shop_id, p.handle, p.title, p.vendor, p.type,
                   p.variant_sku, p.variant_price, p.cost, p.status, p.created_at,
                   s.name AS shop_name
            FROM products p
            INNER JOIN shops s ON p.shop_id = s.id
            WHERE $whereSql
            ORDER BY p.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        apiRespond(200, [
            'success' => true,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'products' => $stmt->fetchAll(),
        ]);
    }

    default:
        apiError(400, 'Unknown or missing action. Valid actions: summary, stats, shops, orders, order, products.');
}
