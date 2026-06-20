<?php
/**
 * IMO Bot — Slash Command Endpoint
 * Handles structured data commands (/orders, /shipping, etc.)
 * Returns JSON with stats + summary for rendering rich cards in the chat.
 */
header('Content-Type: application/json');

$base = dirname(__DIR__);
$app  = require $base . '/bootstrap.php';

$uid = (int) ($_SESSION['user_id'] ?? 0);
if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input   = json_decode(file_get_contents('php://input'), true);
$command = trim($input['command'] ?? '');
$from    = trim($input['date_from'] ?? '');
$to      = trim($input['date_to'] ?? '');

// Date validation helper
function validateDates($from, $to) {
    if (!$from || !$to || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        echo json_encode(['success' => false, 'error' => 'Invalid date range']);
        exit;
    }
}

$pdo = $app->pdo;

// ── /orders command ──────────────────────────────────────────────────────────
if ($command === 'orders') {
    validateDates($from, $to);
    if ($from > $to) [$from, $to] = [$to, $from];

    // Global stats for the date range
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(o.id)                                                                                   AS total_orders,
            COUNT(CASE WHEN o.confirmed = 1 THEN 1 END)                                                   AS confirmed,
            COUNT(CASE WHEN o.follow_up = 1 AND o.confirmed = 0 THEN 1 END)                               AS followup,
            COUNT(CASE WHEN
                LOWER(o.fiabilo_status) IN ('livré','livrés','livrer','delivered','reçu')
                OR LOWER(o.fiabilo_status) LIKE 'livr%'
            THEN 1 END)                                                                                   AS delivered,
            COUNT(CASE WHEN
                LOWER(o.fiabilo_status) IN ('retourné','retour','returned','annulé','refusé','cancelled','rtn definitif')
                OR LOWER(o.fiabilo_status) LIKE 'retour%'
                OR LOWER(o.fiabilo_status) LIKE 'rtn%'
            THEN 1 END)                                                                                   AS returned,
            COUNT(CASE WHEN o.fiabilo_tracking_code IS NOT NULL AND o.fiabilo_tracking_code != '' THEN 1 END) AS shipped,
            COALESCE(SUM(o.total), 0)                                                                     AS total_revenue,
            COALESCE(SUM(CASE WHEN o.confirmed = 1 THEN o.total ELSE 0 END), 0)                          AS confirmed_revenue,
            MAX(o.currency)                                                                               AS currency
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ?
          AND DATE(o.order_created_at) BETWEEN ? AND ?
    ");
    $statsStmt->execute([$uid, $from, $to]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Per-shop breakdown
    $shopStmt = $pdo->prepare("
        SELECT
            s.name                                                                                        AS shop_name,
            COUNT(o.id)                                                                                   AS total_orders,
            COUNT(CASE WHEN o.confirmed = 1 THEN 1 END)                                                   AS confirmed,
            COUNT(CASE WHEN
                LOWER(o.fiabilo_status) IN ('livré','livrés','livrer','delivered','reçu')
                OR LOWER(o.fiabilo_status) LIKE 'livr%'
            THEN 1 END)                                                                                   AS delivered,
            COUNT(CASE WHEN
                LOWER(o.fiabilo_status) IN ('retourné','retour','returned','annulé','refusé','cancelled','rtn definitif')
                OR LOWER(o.fiabilo_status) LIKE 'retour%'
                OR LOWER(o.fiabilo_status) LIKE 'rtn%'
            THEN 1 END)                                                                                   AS returned,
            COALESCE(SUM(o.total), 0)                                                                     AS revenue
        FROM shops s
        LEFT JOIN orders o ON o.shop_id = s.id
            AND DATE(o.order_created_at) BETWEEN ? AND ?
        WHERE s.user_id = ?
        GROUP BY s.id, s.name
        ORDER BY revenue DESC
    ");
    $shopStmt->execute([$from, $to, $uid]);
    $shops = $shopStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build human-readable date labels
    $fromLabel = date('M j, Y', strtotime($from));
    $toLabel   = date('M j, Y', strtotime($to));
    $isSameDay = ($from === $to);
    $period    = $isSameDay ? "on {$fromLabel}" : "between {$fromLabel} and {$toLabel}";

    $total     = (int)   $stats['total_orders'];
    $confirmed = (int)   $stats['confirmed'];
    $delivered = (int)   $stats['delivered'];
    $returned  = (int)   $stats['returned'];
    $followup  = (int)   $stats['followup'];
    $shipped   = (int)   $stats['shipped'];
    $revenue   = (float) $stats['total_revenue'];
    $confRev   = (float) $stats['confirmed_revenue'];
    $currency  = $stats['currency'] ?: 'MAD';

    // Rates based on CONFIRMED orders (how many confirmed ended up delivered/returned)
    $deliveryRate = ($confirmed > 0) ? round(($delivered / $confirmed) * 100, 1) : 0;
    $returnRate   = ($confirmed > 0) ? round(($returned  / $confirmed) * 100, 1) : 0;

    // Generate summary paragraph
    if ($total === 0) {
        $summary = "No orders were found {$period}. This could mean no new orders came in during this period, or orders were created on different dates. Try expanding your date range.";
    } else {
        $summary = "Your store processed <strong>{$total} orders</strong> {$period}. ";
        $summary .= "{$confirmed} were confirmed by phone";
        if ($followup > 0) $summary .= ", {$followup} still need follow-up";
        $summary .= ". ";

        if ($shipped > 0) {
            $summary .= "Of those confirmed, <strong>{$delivered} were delivered</strong> (delivery rate from confirmed: {$deliveryRate}%) ";
            if ($returned > 0) $summary .= "and <strong>{$returned} came back</strong> (return rate: {$returnRate}%). ";
        }

        $summary .= "Total revenue was <strong>" . number_format($revenue, 2) . " {$currency}</strong>";
        if ($confRev > 0 && $confRev !== $revenue) {
            $summary .= " with <strong>" . number_format($confRev, 2) . " {$currency}</strong> confirmed.";
        } else {
            $summary .= ".";
        }

        if (count($shops) === 1) {
            $summary .= " All orders came from <strong>{$shops[0]['shop_name']}</strong>.";
        } elseif (count($shops) > 1) {
            $topShop = $shops[0];
            $summary .= " Your top-performing shop was <strong>{$topShop['shop_name']}</strong> with {$topShop['total_orders']} orders.";
        }
    }

    echo json_encode([
        'success'  => true,
        'command'  => 'orders',
        'period'   => $period,
        'from'     => $from,
        'to'       => $to,
        'stats'    => [
            'total'            => $total,
            'confirmed'        => $confirmed,
            'followup'         => $followup,
            'shipped'          => $shipped,
            'delivered'        => $delivered,
            'returned'         => $returned,
            'revenue'          => $revenue,
            'confirmed_revenue'=> $confRev,
            'currency'         => $currency,
            'delivery_rate'    => $deliveryRate,
            'return_rate'      => $returnRate,
        ],
        'shops'   => $shops,
        'summary' => $summary,
    ]);
    exit;
}

// ── /shipping command ────────────────────────────────────────────────────────
if ($command === 'shipping') {
    validateDates($from, $to);
    if ($from > $to) [$from, $to] = [$to, $from];
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(o.id) AS total,
            COUNT(CASE WHEN o.fiabilo_tracking_code IS NOT NULL AND o.fiabilo_tracking_code != '' THEN 1 END) AS shipped,
            COUNT(CASE WHEN LOWER(o.fiabilo_status) IN ('livré','livrés','livrer','delivered','reçu') OR LOWER(o.fiabilo_status) LIKE 'livr%' THEN 1 END) AS delivered,
            COUNT(CASE WHEN LOWER(o.fiabilo_status) IN ('retourné','retour','returned','annulé','refusé','cancelled','rtn definitif') OR LOWER(o.fiabilo_status) LIKE 'retour%' OR LOWER(o.fiabilo_status) LIKE 'rtn%' THEN 1 END) AS returned,
            COUNT(CASE WHEN LOWER(o.fiabilo_status) IN ('en cours', 'en cours de livraison', 'expédié', 'shipping', 'shipped', 'en cours transit') THEN 1 END) AS in_transit,
            COUNT(CASE WHEN (o.fiabilo_status IS NULL OR o.fiabilo_status = 'En attente' OR o.fiabilo_status = '') AND o.fiabilo_tracking_code IS NOT NULL THEN 1 END) AS ready_pickup
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ?
          AND DATE(o.order_created_at) BETWEEN ? AND ?
    ");
    $statsStmt->execute([$uid, $from, $to]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'command' => 'shipping', 'period' => date('M j, Y', strtotime($from)) . ' - ' . date('M j, Y', strtotime($to)), 'stats' => $stats]);
    exit;
}

// ── /revenue command ─────────────────────────────────────────────────────────
if ($command === 'revenue') {
    validateDates($from, $to);
    if ($from > $to) [$from, $to] = [$to, $from];
    $statsStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(o.total), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN o.confirmed = 1 THEN o.total ELSE 0 END), 0) AS confirmed_revenue,
            COALESCE(SUM(CASE WHEN LOWER(o.fiabilo_status) IN ('livré','livrés','livrer','delivered','reçu') OR LOWER(o.fiabilo_status) LIKE 'livr%' THEN o.total ELSE 0 END), 0) AS delivered_revenue,
            COUNT(o.id) AS total_orders,
            MAX(o.currency) AS currency
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ?
          AND DATE(o.order_created_at) BETWEEN ? AND ?
    ");
    $statsStmt->execute([$uid, $from, $to]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $dailyStmt = $pdo->prepare("
        SELECT DATE(o.order_created_at) as d, SUM(o.total) as rev
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ? AND DATE(o.order_created_at) BETWEEN ? AND ?
        GROUP BY DATE(o.order_created_at)
        ORDER BY d ASC
    ");
    $dailyStmt->execute([$uid, $from, $to]);
    $daily = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'command' => 'revenue', 'stats' => $stats, 'daily' => $daily]);
    exit;
}

// ── /summary command ─────────────────────────────────────────────────────────
if ($command === 'summary') {
    validateDates($from, $to);
    if ($from > $to) [$from, $to] = [$to, $from];
    // Basic stats
    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(o.id) AS total_orders,
            COALESCE(SUM(o.total), 0) AS total_revenue,
            COUNT(CASE WHEN o.confirmed = 1 THEN 1 END) AS confirmed,
            COUNT(CASE WHEN LOWER(o.fiabilo_status) IN ('livré','livrés','livrer','delivered','reçu') OR LOWER(o.fiabilo_status) LIKE 'livr%' THEN 1 END) AS delivered,
            MAX(o.currency) AS currency
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ? AND DATE(o.order_created_at) BETWEEN ? AND ?
    ");
    $statsStmt->execute([$uid, $from, $to]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Top Product
    $prodStmt = $pdo->prepare("
        SELECT p.name, COUNT(li.id) as qty
        FROM order_line_items li
        INNER JOIN products p ON li.product_id = p.id
        INNER JOIN orders o ON li.order_id = o.id
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ? AND DATE(o.order_created_at) BETWEEN ? AND ?
        GROUP BY p.id
        ORDER BY qty DESC
        LIMIT 1
    ");
    $prodStmt->execute([$uid, $from, $to]);
    $topProduct = $prodStmt->fetch(PDO::FETCH_ASSOC);

    // Top Shop
    $shopStmt = $pdo->prepare("
        SELECT s.name, COUNT(o.id) as total
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ? AND DATE(o.order_created_at) BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY total DESC
        LIMIT 1
    ");
    $shopStmt->execute([$uid, $from, $to]);
    $topShop = $shopStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'command' => 'summary',
        'stats' => $stats,
        'top_product' => $topProduct,
        'top_shop' => $topShop
    ]);
    exit;
}

// ── /customers commands ──────────────────────────────────────────────────────
if ($command === 'customer-search') {
    $query = trim($input['query'] ?? '');
    if (strlen($query) < 2) {
        echo json_encode(['success' => true, 'results' => []]);
        exit;
    }

    $q = "%{$query}%";
    $st = $pdo->prepare("
        SELECT DISTINCT 
            COALESCE(NULLIF(o.billing_name, ''), NULLIF(o.shipping_name, ''), 'Guest') as billing_name, 
            COALESCE(NULLIF(o.billing_phone, ''), NULLIF(o.phone, ''), 'No Phone') as billing_phone
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE (o.billing_name LIKE ? OR o.shipping_name LIKE ? OR o.billing_phone LIKE ? OR o.phone LIKE ?)
          AND s.user_id = ?
        ORDER BY billing_name ASC
        LIMIT 10
    ");
    $st->execute([$q, $q, $q, $q, $uid]);
    $results = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);
    exit;
}

if ($command === 'customer-lookup') {
    $phone = trim($input['phone'] ?? '');

    // 1. Aggregated Lifetime Stats
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(o.id) as total_orders,
            SUM(o.total) as lifetime_value,
            MAX(o.currency) as currency
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE (o.billing_phone = ? OR o.phone = ?) AND s.user_id = ?
    ");
    $statsStmt->execute([$phone, $phone, $uid]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // 2. Latest Order Details
    $orderStmt = $pdo->prepare("
        SELECT 
            o.id, o.name, o.order_created_at, o.fiabilo_status, 
            o.shipping_address, o.shipping_city, o.total, o.currency,
            o.billing_name, o.billing_phone
        FROM orders o
        INNER JOIN shops s ON o.shop_id = s.id
        WHERE (o.billing_phone = ? OR o.phone = ?) AND s.user_id = ?
        ORDER BY o.order_created_at DESC
        LIMIT 1
    ");
    $orderStmt->execute([$phone, $phone, $uid]);
    $latest = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$latest) {
        echo json_encode(['success' => false, 'error' => 'Customer not found.']);
        exit;
    }

    // 3. Last Order Items
    $itemStmt = $pdo->prepare("
        SELECT li.lineitem_name, li.lineitem_quantity, p.image_src
        FROM order_line_items li
        LEFT JOIN products p ON li.product_id = p.id
        WHERE li.order_id = ?
    ");
    $itemStmt->execute([$latest['id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'customer' => [
            'name' => $latest['billing_name'] ?: ($latest['shipping_name'] ?: 'Guest'),
            'phone' => $latest['billing_phone'] ?: ($latest['phone'] ?: 'No Phone'),
            'stats' => [
                'count' => (int)$stats['total_orders'],
                'ltv'   => (float)$stats['lifetime_value'],
                'curr'  => $stats['currency'] ?: 'TND'
            ],
            'last_order' => [
                'name'    => $latest['name'],
                'date'    => date('M j, Y', strtotime($latest['order_created_at'])),
                'status'  => $latest['fiabilo_status'] ?: 'Confirmed',
                'address' => $latest['shipping_address'] . ($latest['shipping_city'] ? ", {$latest['shipping_city']}" : ""),
                'items'   => array_map(function($i) {
                    return [
                        'name' => $i['lineitem_name'],
                        'qty'  => (int)$i['lineitem_quantity'],
                        'image'=> $i['image_src']
                    ];
                }, $items)
            ]
        ]
    ]);
    exit;
}

// Unknown command
echo json_encode(['success' => false, 'error' => "Unknown command: {$command}"]);
