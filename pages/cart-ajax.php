<?php
/**
 * Cart AJAX endpoint - returns HTML fragments for cart panel views
 * Called by cart.php JavaScript for live updates
 */
$uid = (int) $_SESSION['user_id'];

// Get order IDs from request
$orderIds = isset($_GET['order_ids']) && is_array($_GET['order_ids']) ? array_map('intval', $_GET['order_ids']) : [];
$orderIds = array_filter($orderIds);

// Validate orders belong to user
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $st = $app->pdo->prepare("SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND o.id IN ($placeholders)");
    $st->execute(array_merge([$uid], $orderIds));
    $orderIds = array_column($st->fetchAll(), 'id');
}

$view = $_GET['view'] ?? 'products_needed';
if (!in_array($view, ['products_needed', 'total_sales', 'cost', 'confirmed_orders'], true)) {
    $view = 'products_needed';
}

header('Content-Type: text/html; charset=utf-8');

if ($view === 'confirmed_orders') {
    // List of last 50 confirmed orders
    $q = trim($_GET['q'] ?? '');
    
    // Select orders that are confirmed OR have confirmed flag (for old data)
    // but not yet sent to shipping
    $sql = "SELECT o.id, o.name, o.billing_name, o.total, o.currency 
            FROM orders o 
            JOIN shops s ON o.shop_id = s.id 
            WHERE s.user_id = ? 
            AND (o.status = 'confirmed' OR (o.confirmed = 1 AND (o.fiabilo_tracking_code IS NULL OR o.fiabilo_tracking_code = '')))";
    $params = [$uid];
    
    if ($q !== '') {
        $sql .= " AND (o.name LIKE ? OR o.billing_name LIKE ?)";
        $params[] = "%$q%";
        $params[] = "%$q%";
    }
    
    $sql .= " ORDER BY o.order_created_at DESC, o.id DESC LIMIT 50";
    
    $st = $app->pdo->prepare($sql);
    $st->execute($params);
    $orders = $st->fetchAll();
    
    if (empty($orders)) {
        echo '<p class="cart-message">No confirmed orders found' . ($q ? ' matching search' : '') . '.</p>';
    } else {
        foreach ($orders as $o) {
            echo '<div class="confirmed-order-card" data-order-id="' . (int)$o['id'] . '">
                <div class="confirmed-order-info">
                    <span class="order-name">' . htmlspecialchars($o['name']) . '</span>
                    <span class="order-customer">' . htmlspecialchars($o['billing_name'] ?? '—') . '</span>
                    <span class="order-total">' . number_format((float)$o['total'], 2) . ' ' . htmlspecialchars($o['currency'] ?? 'TND') . '</span>
                </div>
                <button type="button" class="btn-select-confirmed" onclick="selectFromConfirmed(' . (int)$o['id'] . ', this)">Select</button>
            </div>';
        }
    }
    exit;
}

if (empty($orderIds)) {
    echo '<p class="cart-message">No valid orders selected.</p>';
    exit;
}

$placeholders = implode(',', array_fill(0, count($orderIds), '?'));

if ($view === 'products_needed') {
    // Aggregated products with quantities
    $st = $app->pdo->prepare("
        SELECT li.product_id, COALESCE(p.title, li.lineitem_name) AS title, 
               MAX(p.image_src) AS image_src, SUM(li.lineitem_quantity) AS total_qty
        FROM order_line_items li
        LEFT JOIN products p ON li.product_id = p.id
        WHERE li.order_id IN ($placeholders)
        GROUP BY li.product_id, COALESCE(p.title, li.lineitem_name)
        ORDER BY total_qty DESC, title
    ");
    $st->execute($orderIds);
    $rows = $st->fetchAll();
    
    echo '<div class="cart-result-header">
        <h3>Products needed</h3>
        <span class="cart-result-count">' . count($rows) . ' product(s) from ' . count($orderIds) . ' order(s)</span>
    </div>';
    
    if (count($rows) === 0) {
        echo '<p class="cart-message">No line items in selected orders.</p>';
    } else {
        echo '<div class="products-needed-list">';
        foreach ($rows as $r) {
            $img = $r['image_src'] 
                ? '<img src="' . htmlspecialchars($r['image_src']) . '" alt="" class="product-thumb">' 
                : '<div class="product-thumb-placeholder"></div>';
            echo '<div class="product-needed-item">
                ' . $img . '
                <div class="product-info">
                    <span class="product-title">' . htmlspecialchars($r['title']) . '</span>
                </div>
                <div class="product-qty">
                    <span class="qty-number">' . (int)$r['total_qty'] . '</span>
                    <span class="qty-label">needed</span>
                </div>
            </div>';
        }
        echo '</div>';
    }
    
} elseif ($view === 'total_sales') {
    // Sum of order totals
    $st = $app->pdo->prepare("SELECT COALESCE(SUM(total), 0) AS total, MAX(currency) AS currency FROM orders WHERE id IN ($placeholders)");
    $st->execute($orderIds);
    $row = $st->fetch();
    
    echo '<div class="cart-result-header">
        <h3>Total sales</h3>
        <span class="cart-result-count">' . count($orderIds) . ' order(s) selected</span>
    </div>';
    
    echo '<div class="total-sales-display">
        <div class="sales-amount">' . number_format((float)$row['total'], 2) . '</div>
        <div class="sales-currency">' . htmlspecialchars($row['currency'] ?? 'TND') . '</div>
    </div>';
    
    // Calculate average
    $avg = count($orderIds) > 0 ? (float)$row['total'] / count($orderIds) : 0;
    echo '<div class="sales-stats">
        <div class="stat-item">
            <span class="stat-value">' . number_format($avg, 2) . '</span>
            <span class="stat-label">Average per order</span>
        </div>
    </div>';
    
} else {
    // Cost breakdown (facture)
    $st = $app->pdo->prepare("
        SELECT li.product_id, COALESCE(p.title, li.lineitem_name) AS title, 
               p.cost, SUM(li.lineitem_quantity) AS total_qty
        FROM order_line_items li
        LEFT JOIN products p ON li.product_id = p.id
        WHERE li.order_id IN ($placeholders)
        GROUP BY li.product_id, COALESCE(p.title, li.lineitem_name), p.cost
        ORDER BY title
    ");
    $st->execute($orderIds);
    $rows = $st->fetchAll();
    
    $grandTotal = 0.0;
    $missingCost = 0;
    foreach ($rows as $r) {
        if ($r['cost'] === null) $missingCost++;
        $grandTotal += ($r['cost'] !== null ? (float)$r['cost'] : 0) * (int)$r['total_qty'];
    }
    
    echo '<div class="cart-result-header">
        <h3>Cost breakdown</h3>
        <span class="cart-result-count">' . count($rows) . ' product(s) from ' . count($orderIds) . ' order(s)</span>
    </div>';
    
    if ($missingCost > 0) {
        echo '<div class="cost-warning">
            <span class="warning-icon">⚠️</span>
            <span>' . $missingCost . ' product(s) missing cost. <a href="index.php?page=products">Set in Products</a></span>
        </div>';
    }
    
    if (count($rows) === 0) {
        echo '<p class="cart-message">No line items in selected orders.</p>';
    } else {
        echo '<table class="cost-table">
            <thead><tr><th>Product</th><th>Qty</th><th>Unit cost</th><th>Total</th></tr></thead>
            <tbody>';
        foreach ($rows as $r) {
            $cost = $r['cost'] !== null ? (float)$r['cost'] : 0.0;
            $qty = (int)$r['total_qty'];
            $lineTotal = $cost * $qty;
            $costStr = $r['cost'] !== null ? number_format($cost, 2) : '<span class="missing">—</span>';
            echo '<tr>
                <td>' . htmlspecialchars($r['title']) . '</td>
                <td>' . $qty . '</td>
                <td>' . $costStr . '</td>
                <td>' . number_format($lineTotal, 2) . '</td>
            </tr>';
        }
        echo '</tbody></table>';
        
        echo '<div class="cost-total">
            <span class="total-label">Total cost:</span>
            <span class="total-amount">' . number_format($grandTotal, 2) . ' TND</span>
        </div>';
    }
}

// IMPORTANT: Exit to prevent index.php from loading layout/dashboard
exit;
