<?php
/**
 * Build user data context for Gemini AI
 * Fetches all relevant user data (shops, orders, products, analytics) and formats it for AI context
 */
class UserDataContext
{
    private $pdo;
    private $userId;

    public function __construct($pdo, $userId)
    {
        $this->pdo = $pdo;
        $this->userId = (int) $userId;
    }

    /**
     * Build comprehensive user data context string
     */
    public function build()
    {
        $context = [];
        
        // User info
        $userStmt = $this->pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
        $userStmt->execute([$this->userId]);
        $user = $userStmt->fetch();
        if ($user) {
            $context[] = "USER: " . ($user['name'] ?? 'User') . " (ID: {$user['id']})";
        }

        // Shops
        $shopsStmt = $this->pdo->prepare("SELECT id, name, created_at FROM shops WHERE user_id = ? ORDER BY created_at DESC");
        $shopsStmt->execute([$this->userId]);
        $shops = $shopsStmt->fetchAll();
        if (!empty($shops)) {
            $context[] = "\nSHOPS (" . count($shops) . " total):";
            foreach ($shops as $shop) {
                $context[] = "  - Shop ID {$shop['id']}: {$shop['name']} (created: {$shop['created_at']})";
            }
        } else {
            $context[] = "\nSHOPS: None";
        }

        // Orders summary
        $ordersStmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_orders,
                COUNT(CASE WHEN confirmed = 1 THEN 1 END) as confirmed_count,
                COUNT(CASE WHEN follow_up = 1 THEN 1 END) as followup_count,
                COUNT(CASE WHEN fiabilo_status IN ('livré', 'livrés', 'livrer', 'delivered') THEN 1 END) as delivered_count,
                COUNT(CASE WHEN fiabilo_status IN ('retourné', 'retour', 'returned') THEN 1 END) as returned_count,
                SUM(CASE WHEN confirmed = 1 THEN total ELSE 0 END) as confirmed_revenue,
                SUM(total) as total_revenue,
                MIN(order_created_at) as first_order_date,
                MAX(order_created_at) as last_order_date
            FROM orders o 
            INNER JOIN shops s ON o.shop_id = s.id 
            WHERE s.user_id = ?
        ");
        $ordersStmt->execute([$this->userId]);
        $orderStats = $ordersStmt->fetch();
        
        if ($orderStats && $orderStats['total_orders'] > 0) {
            $context[] = "\nORDERS SUMMARY:";
            $context[] = "  - Total orders: {$orderStats['total_orders']}";
            $context[] = "  - Confirmed: {$orderStats['confirmed_count']}";
            $context[] = "  - Needs follow-up: {$orderStats['followup_count']}";
            $context[] = "  - Delivered: {$orderStats['delivered_count']}";
            $context[] = "  - Returned: {$orderStats['returned_count']}";
            $context[] = "  - Confirmed revenue: " . number_format($orderStats['confirmed_revenue'] ?? 0, 2) . " " . ($orderStats['currency'] ?? 'TND');
            $context[] = "  - Total revenue: " . number_format($orderStats['total_revenue'] ?? 0, 2);
            $context[] = "  - First order: {$orderStats['first_order_date']}";
            $context[] = "  - Last order: {$orderStats['last_order_date']}";
        } else {
            $context[] = "\nORDERS: None";
        }

        // Recent orders (last 20)
        $recentOrdersStmt = $this->pdo->prepare("
            SELECT 
                o.id, o.name, o.total, o.currency, o.financial_status, o.fulfillment_status,
                o.confirmed, o.follow_up, o.fiabilo_status, o.fiabilo_tracking_code,
                o.order_created_at, o.confirmed_at, o.delivered_at, o.returned_at,
                s.name as shop_name
            FROM orders o 
            INNER JOIN shops s ON o.shop_id = s.id 
            WHERE s.user_id = ? 
            ORDER BY o.order_created_at DESC 
            LIMIT 20
        ");
        $recentOrdersStmt->execute([$this->userId]);
        $recentOrders = $recentOrdersStmt->fetchAll();
        
        if (!empty($recentOrders)) {
            $context[] = "\nRECENT ORDERS (last 20):";
            foreach ($recentOrders as $order) {
                $status = [];
                if ($order['confirmed']) $status[] = 'confirmed';
                if ($order['follow_up']) $status[] = 'needs-followup';
                if ($order['fiabilo_status']) $status[] = "shipping: {$order['fiabilo_status']}";
                $statusStr = !empty($status) ? ' [' . implode(', ', $status) . ']' : '';
                $context[] = "  - {$order['name']} ({$order['shop_name']}): " . number_format($order['total'], 2) . " {$order['currency']}{$statusStr}";
            }
        }

        // Products summary
        $productsStmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_products,
                COUNT(DISTINCT p.shop_id) as shops_with_products,
                SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) as active_products
            FROM products p 
            INNER JOIN shops s ON p.shop_id = s.id 
            WHERE s.user_id = ?
        ");
        $productsStmt->execute([$this->userId]);
        $productStats = $productsStmt->fetch();
        
        if ($productStats && $productStats['total_products'] > 0) {
            $context[] = "\nPRODUCTS SUMMARY:";
            $context[] = "  - Total products: {$productStats['total_products']}";
            $context[] = "  - Active products: {$productStats['active_products']}";
            $context[] = "  - Shops with products: {$productStats['shops_with_products']}";
        } else {
            $context[] = "\nPRODUCTS: None";
        }

        // Top products by revenue (if order line items exist)
        $topProductsStmt = $this->pdo->prepare("
            SELECT 
                p.title, p.variant_sku, SUM(oli.lineitem_price * oli.lineitem_quantity) as revenue,
                COUNT(DISTINCT oli.order_id) as order_count
            FROM products p
            INNER JOIN shops s ON p.shop_id = s.id
            LEFT JOIN order_line_items oli ON oli.product_id = p.id
            WHERE s.user_id = ?
            GROUP BY p.id, p.title, p.variant_sku
            HAVING revenue > 0
            ORDER BY revenue DESC
            LIMIT 10
        ");
        $topProductsStmt->execute([$this->userId]);
        $topProducts = $topProductsStmt->fetchAll();
        
        if (!empty($topProducts)) {
            $context[] = "\nTOP PRODUCTS BY REVENUE:";
            foreach ($topProducts as $product) {
                $context[] = "  - {$product['title']} (SKU: {$product['variant_sku']}): " . number_format($product['revenue'], 2) . " from {$product['order_count']} orders";
            }
        }

        // Analytics by shop
        $shopAnalyticsStmt = $this->pdo->prepare("
            SELECT 
                s.id, s.name,
                COUNT(o.id) as order_count,
                SUM(CASE WHEN o.confirmed = 1 THEN o.total ELSE 0 END) as confirmed_revenue,
                COUNT(CASE WHEN o.fiabilo_status IN ('livré', 'livrés', 'livrer', 'delivered') THEN 1 END) as delivered_count,
                COUNT(CASE WHEN o.fiabilo_status IN ('retourné', 'retour', 'returned') THEN 1 END) as returned_count
            FROM shops s
            LEFT JOIN orders o ON o.shop_id = s.id
            WHERE s.user_id = ?
            GROUP BY s.id, s.name
            ORDER BY confirmed_revenue DESC
        ");
        $shopAnalyticsStmt->execute([$this->userId]);
        $shopAnalytics = $shopAnalyticsStmt->fetchAll();
        
        if (!empty($shopAnalytics)) {
            $context[] = "\nSHOP ANALYTICS:";
            foreach ($shopAnalytics as $shop) {
                $context[] = "  - {$shop['name']}: {$shop['order_count']} orders, " . number_format($shop['confirmed_revenue'] ?? 0, 2) . " revenue, {$shop['delivered_count']} delivered, {$shop['returned_count']} returned";
            }
        }

        // Today's stats
        $todayStmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as today_orders,
                SUM(CASE WHEN confirmed = 1 THEN total ELSE 0 END) as today_revenue
            FROM orders o 
            INNER JOIN shops s ON o.shop_id = s.id 
            WHERE s.user_id = ? AND DATE(o.order_created_at) = CURDATE()
        ");
        $todayStmt->execute([$this->userId]);
        $todayStats = $todayStmt->fetch();
        
        if ($todayStats && $todayStats['today_orders'] > 0) {
            $context[] = "\nTODAY'S STATS:";
            $context[] = "  - Orders: {$todayStats['today_orders']}";
            $context[] = "  - Revenue: " . number_format($todayStats['today_revenue'] ?? 0, 2);
        }

        return implode("\n", $context);
    }
}
