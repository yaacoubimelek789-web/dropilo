<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'delivered-orders';
$pageTitle = 'Delivered Orders';

$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset = ($pageNum - 1) * $perPage;
$search = trim($_GET['search'] ?? '');

// --- REAL-TIME SYNC (Fresh data for "Today" analytics) ---
$stInt = $app->pdo->prepare("SELECT tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = 'fiabilo'");
$stInt->execute([$uid]);
$integration = $stInt->fetch();

if ($integration && !empty($integration['tracking_token_encrypted'])) {
    $trackingToken = FiabiloHelper::decrypt($integration['tracking_token_encrypted'], $app->app['encryption_key'] ?? '');
    if ($trackingToken) {
        // Sync orders that were recently shipped or pending
        $syncSt = $app->pdo->prepare("SELECT o.id, o.fiabilo_tracking_code, o.fiabilo_status FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND o.fiabilo_tracking_code IS NOT NULL AND (LOWER(o.fiabilo_status) NOT IN ('livré', 'livrés', 'livrer', 'retourné', 'annulé', 'retour', 'refusé', 'delivered', 'returned', 'rtn definit', 'rtn depot') OR o.fiabilo_status IS NULL) ORDER BY o.created_at DESC LIMIT 50");
        $syncSt->execute([$uid]);
        $ordersToSync = $syncSt->fetchAll();
        foreach ($ordersToSync as $order) {
            $statusRes = FiabiloHelper::getStatus($trackingToken, $order['fiabilo_tracking_code']);
            if (isset($statusRes['etat']) && $statusRes['etat'] !== $order['fiabilo_status']) {
                $status = $statusRes['etat'];
                $statusLower = mb_strtolower($status);
                $deliveredStatuses = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];
                $returnedStatuses = ['retourné', 'annulé', 'retour', 'refusé', 'returned', 'cancelled', 'rtn definit', 'rtn', 'echouée', 'annulée', 'refusée', 'retourne', 'rtn depot', 'a verifier', 'rtn definitif', 'rtn client/agence', 'retour expediteur', 'retour recu'];
                
                $sql = "UPDATE orders SET fiabilo_status = ?, fiabilo_last_sync = NOW()";
                if (in_array($statusLower, $deliveredStatuses)) $sql .= ", delivered_at = NOW()";
                if (in_array($statusLower, $returnedStatuses)) $sql .= ", returned_at = NOW()";
                $sql .= " WHERE id = ?";
                $app->pdo->prepare($sql)->execute([$status, $order['id']]);
            }
        }
    }
}

// --- REAL-TIME INTIGO SYNC ---
$stIntigo = $app->pdo->prepare("SELECT add_token_encrypted, tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = 'intigo'");
$stIntigo->execute([$uid]);
$integrationIntigo = $stIntigo->fetch();

if ($integrationIntigo && !empty($integrationIntigo['add_token_encrypted']) && !empty($integrationIntigo['tracking_token_encrypted'])) {
    $apiKey = IntigoHelper::decrypt($integrationIntigo['add_token_encrypted'], $app->app['encryption_key'] ?? '');
    $merchantId = IntigoHelper::decrypt($integrationIntigo['tracking_token_encrypted'], $app->app['encryption_key'] ?? '');
    if ($apiKey && $merchantId) {
        $syncSt = $app->pdo->prepare("SELECT o.id, o.intigo_tracking_code, o.intigo_status FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND o.intigo_tracking_code IS NOT NULL AND (LOWER(o.intigo_status) NOT IN ('livré', 'livrer', 'retourné', 'annulé', 'retour', 'refusé', 'delivered', 'returned', 'rtn definit', 'livree', 'retourne') OR o.intigo_status IS NULL) ORDER BY o.intigo_sent_at DESC LIMIT 30");
        $syncSt->execute([$uid]);
        $ordersToSync = $syncSt->fetchAll();
        foreach ($ordersToSync as $order) {
            $isSandbox = ($integrationIntigo['api_mode'] ?? 'prod') === 'sandbox';
            $statusRes = IntigoHelper::getTrackingStatus($order['intigo_tracking_code'], $apiKey, $merchantId, $isSandbox);
            if (isset($statusRes['status'])) {
                $statusNum = (int) $statusRes['status'];
                $statusLabel = IntigoHelper::getStatusLabel($statusNum);
                
                if ($statusLabel !== $order['intigo_status']) {
                    $sql = "UPDATE orders SET intigo_status = ?, intigo_last_sync = NOW()";
                    if ($statusNum === IntigoHelper::STATUS_DELIVERED) $sql .= ", delivered_at = NOW()";
                    if (in_array($statusNum, [IntigoHelper::STATUS_RETURN_DEFINITIVE, IntigoHelper::STATUS_LOST, IntigoHelper::STATUS_CANCELLED_ADMIN])) $sql .= ", returned_at = NOW()";
                    $sql .= " WHERE id = ?";
                    $app->pdo->prepare($sql)->execute([$statusLabel, $order['id']]);
                }
            }
        }
    }
}

// Ensure database connection is still alive after potentially long status sync loop
ensurePdoAlive($app);

// Analytics Filter Handling
$dateRange = $_GET['range'] ?? 'all';
$customFrom = $_GET['from'] ?? '';
$customTo = $_GET['to'] ?? '';

$startDate = null;
$endDate = null;

if ($dateRange === 'custom' && !empty($customFrom) && !empty($customTo)) {
    $startDate = date('Y-m-d', strtotime($customFrom));
    $endDate = date('Y-m-d', strtotime($customTo));
} elseif ($dateRange === 'today') {
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d');
} elseif ($dateRange === 'yesterday') {
    $startDate = date('Y-m-d', strtotime('-1 day'));
    $endDate = date('Y-m-d', strtotime('-1 day'));
} elseif ($dateRange === '7') {
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $endDate = date('Y-m-d');
} elseif ($dateRange === '30') {
    $startDate = date('Y-m-d', strtotime('-29 days'));
    $endDate = date('Y-m-d');
} elseif ($dateRange === 'this_month') {
    $startDate = date('Y-m-01');
    $endDate = date('Y-m-d');
}

// Statuses considered "Delivered"
$deliveredList = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];
$placeholders = implode(',', array_fill(0, count($deliveredList), '?'));

// Build filter clause
$filterSql = " AND LOWER(o.fiabilo_status) IN ($placeholders)";
$params = array_merge([$uid], $deliveredList);

if ($startDate && $endDate) {
    $filterSql .= " AND DATE(o.delivered_at) >= ? AND DATE(o.delivered_at) <= ?";
    $params[] = $startDate;
    $params[] = $endDate;
}

if ($search !== '') {
    $filterSql .= " AND o.billing_name LIKE ?";
    $params[] = '%' . $search . '%';
}

// 1. Calculate KPIs (Respecting Search)
$stKpi = $app->pdo->prepare("
    SELECT 
        COUNT(*) as order_count,
        SUM(COALESCE(o.total, 0)) as total_gross
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE s.user_id = ? $filterSql
");
$stKpi->execute($params);
$kpi = $stKpi->fetch();

$orderCount = (int)($kpi['order_count'] ?? 0);
$totalGross = (float)($kpi['total_gross'] ?? 0);

// 1.1 Calculate Total Product Cost (for Delivered Orders in current view)
$stCost = $app->pdo->prepare("
    SELECT SUM(li.lineitem_quantity * COALESCE(p.cost, 0)) as total_cost
    FROM order_line_items li
    JOIN orders o ON li.order_id = o.id
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN products p ON li.product_id = p.id
    WHERE s.user_id = ? $filterSql
");
$stCost->execute($params);
$totalProductCost = (float)($stCost->fetchColumn() ?? 0);

// 1.2 Calculate Accepted Count for Delivery Rate (Respecting Search)
$returnedList = ['Retourné', 'Annulé', 'Retour', 'Refusé', 'Returned', 'Cancelled'];
$shippingList = ['En cours', 'En cours de livraison', 'Expédié', 'Shipping', 'Shipped'];
$warehouseList = ['Au magasin', 'Magasin', 'Entrepôt', 'Depot', 'Aramé'];
$allAccepted = array_merge($deliveredList, $returnedList, $shippingList, $warehouseList);
$placeholdersAcc = implode(',', array_fill(0, count($allAccepted), '?'));

$paramsAcc = array_merge([$uid], $allAccepted);
$filterSqlAcc = " AND o.fiabilo_status IN ($placeholdersAcc)";
if ($search !== '') {
    $filterSqlAcc .= " AND o.billing_name LIKE ?";
    $paramsAcc[] = '%' . $search . '%';
}

$stAcc = $app->pdo->prepare("
    SELECT COUNT(*) 
    FROM orders o 
    JOIN shops s ON o.shop_id = s.id
    WHERE s.user_id = ? $filterSqlAcc
");
$stAcc->execute($paramsAcc);
$acceptedCount = (int)$stAcc->fetchColumn();

// 1.3 Final Financial Calculations
$deliveryRate = $acceptedCount > 0 ? ($orderCount / $acceptedCount) * 100 : 0;
// Estimated Payout = what courier sends you: (Total Gross - 8 TND shipping) * 0.97 (handling fee)
$estimatedPayout = ($totalGross - ($orderCount * 8)) * 0.97;
// Net Profit = (Total Gross - Product Cost) * 0.97
$totalNet = ($totalGross - $totalProductCost) * 0.97;
$avgProfit = $orderCount > 0 ? $totalNet / $orderCount : 0;
$currency = 'TND';

// 2. Rank Top 5 Most Sold Products (Respecting Search)
$stRank = $app->pdo->prepare("
    SELECT 
        li.lineitem_name,
        li.lineitem_sku,
        SUM(li.lineitem_quantity) as total_qty,
        p.image_src
    FROM order_line_items li
    JOIN orders o ON li.order_id = o.id
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN products p ON li.product_id = p.id
    WHERE s.user_id = ? $filterSql
    GROUP BY li.lineitem_name, li.lineitem_sku, p.image_src
    ORDER BY total_qty DESC
    LIMIT 5
");
$stRank->execute($params);
$topProducts = $stRank->fetchAll();

// 3. Fetch Delivered Orders for the table
$stOrders = $app->pdo->prepare("
    SELECT o.*, s.name as shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE s.user_id = ? $filterSql
    ORDER BY o.delivered_at DESC, o.order_created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stOrders->execute($params);
$orders = $stOrders->fetchAll();

// 3. --- GRAPH DATA COLLECTION (Stacked by Shop) ---
$graphLabels = [];
$graphDatasets = [];
$gStartDate = $startDate;
$gEndDate = $endDate;

if (!$gStartDate) {
    // For Lifetime, show last 14 days by default for readability
    $gStartDate = date('Y-m-d', strtotime('-13 days'));
    $gEndDate = date('Y-m-d');
}

try {
    $current = new DateTime($gStartDate);
    $last = new DateTime($gEndDate);
    $interval = new DateInterval('P1D');
    $datePeriod = new DatePeriod($current, $interval, (clone $last)->modify('+1 day'));

    foreach ($datePeriod as $date) {
        $graphLabels[] = $date->format('M d');
    }

    $graphFilterSql = " AND LOWER(o.fiabilo_status) IN ($placeholders)";
    $graphParams = array_merge([$uid], $deliveredList);
    $graphFilterSql .= " AND DATE(o.delivered_at) >= ? AND DATE(o.delivered_at) <= ?";
    $graphParams[] = $gStartDate;
    $graphParams[] = $gEndDate;
    if ($search !== '') {
        $graphFilterSql .= " AND o.billing_name LIKE ?";
        $graphParams[] = '%' . $search . '%';
    }

    $stGraph = $app->pdo->prepare("
        SELECT DATE(o.delivered_at) as d, s.name as shop_name, COUNT(*) as c
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        WHERE s.user_id = ? $graphFilterSql
        GROUP BY d, shop_name
        ORDER BY d ASC
    ");
    $stGraph->execute($graphParams);
    $graphRaw = $stGraph->fetchAll();

    $shopDataRaw = [];
    $allShopsRaw = [];
    foreach ($graphRaw as $row) {
        $shopDataRaw[$row['shop_name']][$row['d']] = (int)$row['c'];
        $allShopsRaw[$row['shop_name']] = true;
    }

    $colors = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ef4444', '#ec4899', '#06b6d4'];
    $cIdxRaw = 0;
    foreach (array_keys($allShopsRaw) as $shopName) {
        $data = [];
        foreach ($datePeriod as $date) {
            $data[] = $shopDataRaw[$shopName][$date->format('Y-m-d')] ?? 0;
        }
        $graphDatasets[] = [
            'label' => $shopName,
            'data' => $data,
            'backgroundColor' => $colors[$cIdxRaw % count($colors)],
            'borderRadius' => 4,
            'maxBarThickness' => 40
        ];
        $cIdxRaw++;
    }
} catch (Exception $e) {
    // Silent fail for graph if date logic fails
}

// Status Badge for mobile header
$checkIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 2px;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
$pageHeaderBadge = '<div class="status-confirmed-new" style="background: #f0fdf4; padding: 0.35rem 0.75rem; border-radius: 8px; border: 1px solid #dcfce7;">' . $checkIcon . ' Delivered</div>';

$content = '
<div class="settings-header">
    <div class="header-main">
        <h1>' . $pageTitle . '</h1>
        <div class="header-line"></div>
    </div>
    <p class="header-subtitle">Analyze your successful deliveries and financial performance.</p>
</div>

<div class="dashboard-wrapper">
    <div class="dashboard-header" style="flex-wrap: wrap; gap: 1rem; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <div class="header-left">
            <div class="advanced-filter-bar" style="margin:0;">
                <form method="get" id="rangeForm" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                    <input type="hidden" name="page" value="delivered-orders">
                    <input type="hidden" name="search" value="' . htmlspecialchars($search) . '">
                    <div class="filter-pills" style="display: flex; background: #f1f5f9; padding: 0.35rem; border-radius: 12px; gap: 0.25rem; position: relative; flex-wrap: wrap;">
                        <button type="button" class="pill ' . ($dateRange == 'all' ? 'active' : '') . '" onclick="applyRange(\'all\', this)">Lifetime</button>
                        <button type="button" class="pill ' . ($dateRange == 'today' ? 'active' : '') . '" onclick="applyRange(\'today\', this)">Today</button>
                        <button type="button" class="pill ' . ($dateRange == 'yesterday' ? 'active' : '') . '" onclick="applyRange(\'yesterday\', this)">Yesterday</button>
                        <button type="button" class="pill ' . ($dateRange == '7' ? 'active' : '') . '" onclick="applyRange(\'7\', this)">7D</button>
                        <button type="button" class="pill ' . ($dateRange == '30' ? 'active' : '') . '" onclick="applyRange(\'30\', this)">30D</button>
                        <button type="button" class="pill ' . ($dateRange == 'this_month' ? 'active' : '') . '" onclick="applyRange(\'this_month\', this)">Month</button>
                        <button type="button" class="pill ' . ($dateRange == 'custom' ? 'active' : '') . '" onclick="toggleCustom(this)">Custom</button>
                        <div id="filterProgress" style="position: absolute; bottom: -2px; left: 0; height: 3px; background: #08CB00; width: 0; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); opacity: 0; border-radius: 10px; z-index: 10;"></div>
                    </div>
                    <input type="hidden" name="range" id="rangeInput" value="' . htmlspecialchars($dateRange) . '">
                    <div class="custom-inputs" id="customInputs" style="' . ($dateRange != 'custom' ? 'display:none;' : 'display:flex;') . ' align-items: center; gap: 0.5rem;">
                        <input type="date" name="from" value="' . htmlspecialchars($customFrom) . '" onchange="this.form.submit()" style="padding: 0.4rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.8rem;">
                        <span style="font-size: 0.8rem; color: #64748b;">to</span>
                        <input type="date" name="to" value="' . htmlspecialchars($customTo) . '" onchange="this.form.submit()" style="padding: 0.4rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.8rem;">
                    </div>
                </form>
            </div>
        </div>
        
        <div class="header-right" style="width: 100%; max-width: 300px;">
            <form method="get" style="position: relative; margin:0;">
                <input type="hidden" name="page" value="delivered-orders">
                <input type="hidden" name="range" value="' . htmlspecialchars($dateRange) . '">
                <input type="hidden" name="from" value="' . htmlspecialchars($customFrom) . '">
                <input type="hidden" name="to" value="' . htmlspecialchars($customTo) . '">
                <input type="text" name="search" value="' . htmlspecialchars($search) . '" 
                    placeholder="Search customer..." 
                    style="width: 100%; padding: 0.6rem 1rem 0.6rem 2.5rem; border-radius: 12px; border: 1px solid #eee; font-weight: 600; outline: none; font-size: 0.9rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%);">
                    <circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
            </form>
        </div>
    </div>
';

$content .= '
    <!-- Analytics Area: Chart + KPIs -->
    <div class="analytics-layout-delivered">
        <!-- Left: Chart -->
        <div class="card graph-card-modern hide-mobile">
            <div class="card-header-modern" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3 style="margin:0; font-size:1.1rem; font-weight: 850; letter-spacing: -0.01em;">Delivery Performance <small style="color:#64748b; font-weight:500;">(Stacked Bar)</small></h3>
            </div>
            <div style="height: 350px; position: relative;">
                <canvas id="deliveredChart"></canvas>
            </div>
        </div>

        <!-- Right: Vertical KPI Stack -->
        <div class="calypso-kpi-stack" style="display: flex; flex-direction: column; gap: 1rem;">
            <div class="stats-card primary" style="flex:1;">
                <span class="label">Gross Sales</span>
                <span class="value">' . number_format($totalGross, 2) . ' <small>' . $currency . '</small></span>
                <div class="icon-bg" style="opacity: 0.15; right: -5px; bottom: -5px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                </div>
            </div>
            <div class="stats-card highlight" style="flex:1; border-left: 4px solid var(--cart-green);">
                <span class="label">Delivered</span>
                <span class="value">' . $orderCount . ' <small>Orders</small></span>
                <div class="icon-bg" style="color:var(--cart-green); opacity: 0.2; right: -5px; bottom: -5px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </div>
            </div>
            <div class="stats-card highlight" style="flex:1;">
                <span class="label">Net Profit</span>
                <span class="value" style="color:#337418;">' . number_format($totalNet, 2) . ' <small>' . $currency . '</small></span>
                <div class="icon-bg" style="color:#5DD62C30; right: -5px; bottom: -5px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#5DD62C" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                </div>
            </div>
            <div class="stats-card" style="flex:1;">
                <span class="label">Delivery Rate</span>
                <span class="value">' . number_format($deliveryRate, 1) . '%</span>
                <div class="icon-bg" style="opacity: 0.15; right: -5px; bottom: -5px;">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Ranking Section -->
    <div class="card modern-card" style="margin-top: 1.5rem;">
        <div class="card-header-modern" style="padding: 1.5rem; border-bottom: 1px solid #eee;">
            <h3 style="margin:0; font-size:1.1rem; display:flex; align-items:center; gap:0.5rem;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                Top 5 Most Sold Products (Delivered)
            </h3>
        </div>
        <div class="ranking-list" style="padding:1rem;">';
        if (empty($topProducts)) {
            $content .= '<p style="text-align:center; color:#999; padding:1rem;">No delivered products data yet.</p>';
        } else {
            foreach ($topProducts as $idx => $tp) {
                $img = $tp['image_src'] ? $tp['image_src'] : 'https://placehold.co/40x40?text=P';
                $content .= '
                <div class="ranking-item" style="display:flex; align-items:center; gap:1rem; padding:0.75rem; border-bottom:1px solid #f8fafc;">
                    <span class="rank-num" style="font-weight:900; color:#ccc; min-width:24px;">#' . ($idx + 1) . '</span>
                    <img src="' . $img . '" style="width:40px; height:40px; border-radius:8px; object-fit:cover;">
                    <div style="flex:1;">
                        <div style="font-weight:700; color:#334155;">' . htmlspecialchars($tp['lineitem_name']) . '</div>
                        <div style="font-size:0.8rem; color:#94a3b8;">SKU: ' . htmlspecialchars($tp['lineitem_sku'] ?: '—') . '</div>
                    </div>
                    <div style="text-align:right;">
                        <span style="background:#5DD62C20; color:#337418; padding:4px 12px; border-radius:20px; font-weight:700; font-size:0.9rem;">' . (int)$tp['total_qty'] . ' sold</span>
                    </div>
                </div>';
            }
        }
$content .= '
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card modern-card" style="margin-top: 1.5rem; padding:0;">
        <div class="table-container">
            <table class="modern-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Shop</th>
                        <th>Delivered At</th>
                        <th>Tracking Code</th>
                        <th>Current Status</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>';
                foreach ($orders as $o) {
                    $status = $o['fiabilo_status'] ?: 'Delivered';
                    $content .= '
                    <tr>
                        <td class="order-id" data-label="Order">
                            <span class="mobile-order-name">' . (strpos($o['name'], '#') === 0 ? '' : '#') . htmlspecialchars($o['name']) . '</span>
                        </td>
                        <td class="customer-name" data-label="Customer">' . htmlspecialchars($o['billing_name'] ?? '—') . '</td>
                        <td data-label="Shop"><span class="shop-badge">' . htmlspecialchars($o['shop_name']) . '</span></td>
                        <td data-label="Date">' . ($o['delivered_at'] ? date('Y-m-d H:i', strtotime($o['delivered_at'])) : '—') . '</td>
                        <td data-label="Tracking"><code>' . htmlspecialchars($o['fiabilo_tracking_code'] ?: '—') . '</code></td>
                        <td data-label="Status"><div class="status-confirmed-new">' . $checkIcon . ' ' . htmlspecialchars($status) . '</div></td>
                        <td class="total-cell" data-label="Total">' . number_format($o['total'], 2) . ' <small>' . $currency . '</small></td>
                    </tr>';
                }
                if (empty($orders)) {
                    $content .= '<tr><td colspan="7" class="empty-state">No delivered orders found.</td></tr>';
                }
$content .= '
                </tbody>
            </table>
        </div>
    </div>';

if ($orderCount > $perPage) {
    $paginationParams = ['page' => $currentPage];
    if ($search !== '') $paginationParams['search'] = $search;
    if ($dateRange !== 'all') {
        $paginationParams['range'] = $dateRange;
        if ($dateRange === 'custom') {
            $paginationParams['from'] = $customFrom;
            $paginationParams['to'] = $customTo;
        }
    }
    
    $content .= '<div class="pagination">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = ($i == $pageNum) ? 'active' : '';
        $paginationParams['p'] = $i;
        $content .= '<a href="index.php?' . http_build_query($paginationParams) . '" class="pg-item ' . $active . '">' . $i . '</a>';
    }
    $content .= '</div>';
}

$content .= '
<script>
// Chart.js Stacked Bar implementation
const ctx = document.getElementById(\'deliveredChart\').getContext(\'2d\');
new Chart(ctx, {
    type: \'bar\',
    data: {
        labels: ' . json_encode($graphLabels) . ',
        datasets: ' . json_encode($graphDatasets) . '
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: \'top\',
                align: \'end\',
                labels: {
                    usePointStyle: true,
                    padding: 20,
                    font: { weight: \'700\', size: 12 }
                }
            }
        },
        scales: {
            x: {
                stacked: true,
                grid: { display: false },
                ticks: { font: { weight: \'600\' } }
            },
            y: {
                stacked: true,
                beginAtZero: true,
                grid: { color: \'#f1f5f9\' },
                ticks: { stepSize: 1, font: { weight: \'600\' } }
            }
        }
    }
});

function applyRange(range, btn) {
    const bar = document.getElementById(\'filterProgress\');
    bar.style.opacity = \'1\';
    bar.style.width = \'100%\';
    
    document.getElementById(\'rangeInput\').value = range;
    if (range !== \'custom\') {
        setTimeout(() => document.getElementById(\'rangeForm\').submit(), 400);
    } else {
        toggleCustom(btn);
    }
}

function toggleCustom(btn) {
    if (btn) {
        const bar = document.getElementById(\'filterProgress\');
        bar.style.opacity = \'1\';
        bar.style.width = \'100%\';
        setTimeout(() => { bar.style.opacity = \'0\'; bar.style.width = \'0\'; }, 600);
    }
    const el = document.getElementById(\'customInputs\');
    el.style.display = (el.style.display === \'none\') ? \'flex\' : \'none\';
}
</script>


<style>
.dashboard-wrapper { padding-right: 3%; }
.analytics-layout-delivered { display: grid; grid-template-columns: 60% 40%; gap: 1.5rem; margin-bottom: 2rem; }
.graph-card-modern { padding: 1.5rem; border-radius: 16px; border: 1px solid #eee; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }

@media (max-width: 900px) {
    .analytics-layout-delivered { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    .hide-mobile { display: none !important; }
}

.pill { border: none; background: transparent; padding: 0.5rem 0.8rem; font-size: 0.75rem; font-weight: 700; color: #64748b; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
.pill:hover { color: #020617; }
.pill.active { background: white; color: #000; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
</style>
</div>';

require $base . '/layouts/layout.php';
