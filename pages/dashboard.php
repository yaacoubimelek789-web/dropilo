<?php
$currentPage = 'dashboard';
$pageTitle = 'Dashboard';
$uid = (int) $_SESSION['user_id'];

// --- REAL-TIME DELIVERY RESET LOGIC ---
// Fetch integration info for reset tracking
$stInt = $app->pdo->prepare("SELECT tracking_token_encrypted, last_delivered_reset FROM user_integrations WHERE user_id = ? AND provider = 'fiabilo'");
$stInt->execute([$uid]);
$integration = $stInt->fetch();

$lastReset = $integration['last_delivered_reset'] ?? null;
$now = new DateTime('now', new DateTimeZone('Africa/Tunis')); // Tunise time
$today7am = clone $now;
$today7am->setTime(7, 0, 0);

// 1. Manual Reset Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_delivered') {
    $app->pdo->prepare("UPDATE user_integrations SET last_delivered_reset = NOW() WHERE user_id = ? AND provider = 'fiabilo'")->execute([$uid]);
    header("Location: index.php?page=dashboard&reset_success=1");
    exit;
}

// 2. Automatic Reset Logic (7:00 AM every day)
if ($now >= $today7am) {
    $lastResetDt = $lastReset ? new DateTime($lastReset, new DateTimeZone('Africa/Tunis')) : null;
    if (!$lastResetDt || $lastResetDt < $today7am) {
        $app->pdo->prepare("UPDATE user_integrations SET last_delivered_reset = NOW() WHERE user_id = ? AND provider = 'fiabilo'")->execute([$uid]);
        $lastReset = date('Y-m-d H:i:s'); 
    }
}

// Keep Fiabilo statuses exact (fixes stuck En attente / Enlever while already Livrer/Retour)
FiabiloHelper::syncUserShipments($app->pdo, $uid, $app->app['encryption_key'] ?? '', 60);

// Analytics Filter Handling
$dateRange = $_GET['range'] ?? 'today';
$customFrom = $_GET['from'] ?? '';
$customTo = $_GET['to'] ?? '';
$selectedStatus = $_GET['status'] ?? 'all'; // all, confirmed, followup, shipped, delivered, returned

$startDate = date('Y-m-d');
$endDate = date('Y-m-d');

if ($dateRange === 'custom' && !empty($customFrom) && !empty($customTo)) {
    $startDate = date('Y-m-d', strtotime($customFrom));
    $endDate = date('Y-m-d', strtotime($customTo));
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
} elseif ($dateRange === 'last_month') {
    $startDate = date('Y-m-d', strtotime('first day of last month'));
    $endDate = date('Y-m-d', strtotime('last day of last month'));
} elseif ($dateRange === '90') {
    $startDate = date('Y-m-d', strtotime('-89 days'));
    $endDate = date('Y-m-d');
} else {
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d');
    $dateRange = 'today';
}

// Reference column mapping based on selected status
$refColMap = [
    'all'       => 'o.created_at',
    'confirmed' => 'o.confirmed_at',
    'followup'  => 'o.followup_at',
    'shipped'   => 'o.shipped_at',
    'delivered' => 'o.delivered_at',
    'returned'  => 'o.returned_at'
];
$refCol = $refColMap[$selectedStatus] ?? 'o.created_at';

// Base query for user's data
$userOrdersQuery = "FROM orders o INNER JOIN shops s ON o.shop_id = s.id WHERE s.user_id = $uid";

ensurePdoAlive($app);

// Metrics Calculation (Action-Based Filtering)
// We use the $refCol for the primary context (Total Orders in this context)
$st = $app->pdo->prepare("
    SELECT 
        -- Target focus count & value based on the selected reference field
        COUNT(CASE WHEN DATE($refCol) >= :start AND DATE($refCol) <= :end THEN 1 END) as focus_count,
        SUM(CASE WHEN DATE($refCol) >= :start AND DATE($refCol) <= :end THEN COALESCE(o.total, 0) ELSE 0 END) as focus_rev,
        
        -- Secondary Metrics (always useful)
        SUM(CASE WHEN DATE(o.confirmed_at) >= :start AND DATE(o.confirmed_at) <= :end THEN 1 ELSE 0 END) as confirmed_count,
        SUM(CASE WHEN DATE(o.confirmed_at) >= :start AND DATE(o.confirmed_at) <= :end THEN COALESCE(o.total, 0) ELSE 0 END) as confirmed_rev,
        SUM(CASE WHEN o.status = 'followup' AND DATE(o.followup_at) >= :start AND DATE(o.followup_at) <= :end THEN 1 ELSE 0 END) as followup_count,
        SUM(CASE WHEN (LOWER(o.fiabilo_status) IN ('livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree')) AND DATE(o.delivered_at) >= :start AND DATE(o.delivered_at) <= :end THEN COALESCE(o.total, 0) ELSE 0 END) as delivered_api_rev,
        
        -- Pipeline Snapshot (Transient states: ready & out for delivery)
        SUM(CASE WHEN o.fiabilo_tracking_code IS NOT NULL AND (
            o.fiabilo_status IS NULL OR o.fiabilo_status = '' OR
            LOWER(o.fiabilo_status) LIKE '%attente%' OR LOWER(o.fiabilo_status) LIKE '%enlev%' OR LOWER(o.fiabilo_status) LIKE '%assign%'
        ) THEN 1 ELSE 0 END) as ready_pickup,
        SUM(CASE WHEN (
            LOWER(o.fiabilo_status) LIKE '%cours%' OR LOWER(o.fiabilo_status) LIKE '%livraison%' OR
            (LOWER(o.fiabilo_status) LIKE '%expedi%' AND LOWER(o.fiabilo_status) NOT LIKE '%expediteur%') OR
            LOWER(o.fiabilo_status) IN ('shipping', 'shipped')
        ) THEN 1 ELSE 0 END) as out_for_delivery,
        
        -- Terminal Snapshot (Terminal states: delivered & returned & warehouse)
        SUM(CASE WHEN (
            (LOWER(o.fiabilo_status) LIKE '%livr%' AND LOWER(o.fiabilo_status) NOT LIKE '%non livr%')
            OR LOWER(o.fiabilo_status) IN ('delivered', 'reçu', 'recu', 'livree')
        ) THEN 1 ELSE 0 END) as delivered_count,
        SUM(CASE WHEN (
            LOWER(o.fiabilo_status) LIKE '%retour%'
            OR LOWER(o.fiabilo_status) LIKE '%rtn%'
            OR LOWER(o.fiabilo_status) LIKE '%refus%'
            OR LOWER(o.fiabilo_status) LIKE '%annul%'
            OR LOWER(o.fiabilo_status) LIKE '%echou%'
            OR LOWER(o.fiabilo_status) LIKE '%supprim%'
            OR LOWER(o.fiabilo_status) LIKE '%verifier%'
        ) THEN 1 ELSE 0 END) as returned_count,
        SUM(CASE WHEN (
            LOWER(o.fiabilo_status) LIKE '%magasin%' OR LOWER(o.fiabilo_status) LIKE '%entrepot%' OR
            LOWER(o.fiabilo_status) LIKE '%depot%' OR LOWER(o.fiabilo_status) LIKE '%warehouse%' OR
            LOWER(o.fiabilo_status) LIKE '%centre%' OR LOWER(o.fiabilo_status) LIKE '%transfert%'
        ) THEN 1 ELSE 0 END) as warehouse_count,
        
        -- Real-time counter for delivered today (respecting reset)
        SUM(CASE WHEN (
            (LOWER(o.fiabilo_status) LIKE '%livr%' AND LOWER(o.fiabilo_status) NOT LIKE '%non livr%')
            OR LOWER(o.fiabilo_status) IN ('delivered', 'reçu', 'recu', 'livree')
        ) AND (:lastReset IS NULL OR o.delivered_at >= :lastReset) THEN 1 ELSE 0 END) as delivered_count_realtime
    FROM orders o JOIN shops s ON o.shop_id = s.id 
    WHERE s.user_id = :uid
");
$st->execute(['uid' => $uid, 'start' => $startDate, 'end' => $endDate, 'lastReset' => $lastReset]);
$m = $st->fetch();

// Calculate Delivery Rate
$acceptedCount = (int)$m['delivered_count'] + (int)$m['returned_count'] + (int)$m['warehouse_count'] + (int)$m['out_for_delivery'];
$deliveryRate = $acceptedCount > 0 ? ((int)$m['delivered_count'] / $acceptedCount) * 100 : 0;

// PREPARE GRAPH DATA
// Generate labels (dates) and datasets based on the range
$graphLabels = [];
$graphData = [];
$period = new DatePeriod(new DateTime($startDate), new DateInterval('P1D'), (new DateTime($endDate))->modify('+1 day'));
foreach ($period as $date) {
    $graphLabels[] = $date->format('M d');
}

// Fetch counts per day for the graph based on the $refCol
$stGraph = $app->pdo->prepare("
    SELECT DATE($refCol) as d, COUNT(*) as cnt 
    FROM orders o JOIN shops s ON o.shop_id = s.id 
    WHERE s.user_id = :uid AND DATE($refCol) >= :start AND DATE($refCol) <= :end
    GROUP BY DATE($refCol)
");
$stGraph->execute(['uid' => $uid, 'start' => $startDate, 'end' => $endDate]);
$graphResults = $stGraph->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($period as $date) {
    $dStr = $date->format('Y-m-d');
    $graphData[] = (int)($graphResults[$dStr] ?? 0);
}

// Lifetime stats (for footer or specific comparison)
$stShops = $app->pdo->query("SELECT COUNT(*) FROM shops WHERE user_id = $uid");
$shopCount = (int) $stShops->fetchColumn();

$stLifetime = $app->pdo->query("SELECT COUNT(*) $userOrdersQuery");
$totalOrdersLifetime = (int) $stLifetime->fetchColumn();

// Fetch Recent Orders (Last 30)
$stRecent = $app->pdo->prepare("
    SELECT o.id, o.name, o.billing_name, o.billing_phone, o.total, o.currency, o.status, o.created_at, s.name as shop_name
    FROM orders o 
    JOIN shops s ON o.shop_id = s.id 
    WHERE s.user_id = ? 
    ORDER BY o.created_at DESC 
    LIMIT 30
");
$stRecent->execute([$uid]);
$recentOrders = $stRecent->fetchAll();

$content = '
<!-- Loading Overlay -->
<div id="dashboard-loader" class="loader-overlay">
  <div class="loader-content">
    <div class="loader-title">Updating Analytics</div>
    <div class="loader-sub">Syncing with Fiabilo API...</div>
    <div class="progress-wrap">
      <div class="progress-bar-fill"></div>
    </div>
  </div>
</div>

<div class="dashboard-wrapper">
<div class="dashboard-header">
  <div class="header-main-row">
    <div class="header-left">
        <h1>Analytics Overview</h1>
        <p>Performance tracking for ' . $shopCount . ' active shop' . ($shopCount != 1 ? 's' : '') . '</p>
    </div>
    <div class="header-right">
        <!-- Desktop Filters (Hidden on Mobile) -->
        <div class="advanced-filter-bar desktop-only">
        <form method="get" id="filterFormDesktop">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="status" id="statusInputDesktop" value="' . htmlspecialchars($selectedStatus) . '">
            <div class="filter-pills">
                <button type="button" class="pill ' . ($dateRange == 'today' ? 'active' : '') . '" onclick="applyRange(\'today\')">Today</button>
                <button type="button" class="pill ' . ($dateRange == 'yesterday' ? 'active' : '') . '" onclick="applyRange(\'yesterday\')">Yesterday</button>
                <button type="button" class="pill ' . ($dateRange == '7' ? 'active' : '') . '" onclick="applyRange(\'7\')">7D</button>
                <button type="button" class="pill ' . ($dateRange == '30' ? 'active' : '') . '" onclick="applyRange(\'30\')">30D</button>
                <button type="button" class="pill ' . ($dateRange == 'this_month' ? 'active' : '') . '" onclick="applyRange(\'this_month\')">Month</button>
                <button type="button" class="pill ' . ($dateRange == 'custom' ? 'active' : '') . '" onclick="toggleCustom()">Custom</button>
            </div>
            <input type="hidden" name="range" id="rangeInputDesktop" value="' . htmlspecialchars($dateRange) . '">
            <div class="custom-inputs" id="customInputsDesktop" style="' . ($dateRange != 'custom' ? 'display:none;' : 'display:flex;') . '">
            <input type="date" name="from" value="' . htmlspecialchars($customFrom) . '">
            <span class="sep">to</span>
            <input type="date" name="to" value="' . htmlspecialchars($customTo) . '">
            <button type="submit" class="apply-btn-desktop" onclick="document.getElementById(\'rangeInputDesktop\').value = \'custom\'" title="Apply Date Range">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </button>
            </div>
        </form>
        </div>

        <!-- Mobile Filter Toggle -->
        <button class="mobile-filter-btn" onclick="openFilterSheet()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>
            Filters
        </button>
    </div>
  </div>

  <div class="status-selector-scroll desktop-only">
    <div class="status-selector-bar">
      <button type="button" class="status-pill ' . ($selectedStatus == 'all' ? 'active' : '') . '" onclick="applyStatus(\'status\', \'all\')">All Orders</button>
      <button type="button" class="status-pill ' . ($selectedStatus == 'confirmed' ? 'active' : '') . '" onclick="applyStatus(\'status\', \'confirmed\')">Confirmed</button>
      <button type="button" class="status-pill ' . ($selectedStatus == 'followup' ? 'active' : '') . '" onclick="applyStatus(\'status\', \'followup\')">Follow Up</button>
      <button type="button" class="status-pill ' . ($selectedStatus == 'shipped' ? 'active' : '') . '" onclick="applyStatus(\'status\', \'shipped\')">Shipped</button>
      <button type="button" class="status-pill ' . ($selectedStatus == 'delivered' ? 'active' : '') . '" onclick="applyStatus(\'status\', \'delivered\')">Delivered</button>
      <button type="button" class="status-pill ' . ($selectedStatus == 'returned' ? 'active' : '') . '" onclick="applyStatus(\'status\', \'returned\')">Returned</button>
    </div>
  </div>
</div>

<!-- KPI Grid -->
<div class="kpi-grid">
  <div class="kpi-card kpi-green">
    <div class="kpi-label">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/></svg>
      ' . ucfirst($selectedStatus) . ' Orders
    </div>
    <div class="kpi-value kpi-val-green">' . number_format((int)$m['focus_count']) . '</div>
    <div class="kpi-sub"><span class="kpi-badge">Filtered by event date</span></div>
    <svg class="kpi-card-icon" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
  </div>
  <div class="kpi-card kpi-blue">
    <div class="kpi-label">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      ' . ucfirst($selectedStatus) . ' Value
    </div>
    <div class="kpi-value kpi-val-blue">' . number_format((float)$m['focus_rev'], 2) . '<span style="font-size:14px;font-weight:500;opacity:.6"> TND</span></div>
    <div class="kpi-sub">Revenue in selected range</div>
    <svg class="kpi-card-icon" viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"></path><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
  </div>
  <div class="kpi-card kpi-yellow">
    <div class="kpi-label">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      Confirmed Revenue
    </div>
    <div class="kpi-value kpi-val-yellow">' . number_format((float)$m['confirmed_rev'], 2) . '<span style="font-size:14px;font-weight:500;opacity:.6"> TND</span></div>
    <div class="kpi-sub">Tied to confirmed_at date</div>
    <svg class="kpi-card-icon" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
  </div>
  <div class="kpi-card kpi-emerald">
    <div class="kpi-label">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Delivered Revenue
    </div>
    <div class="kpi-value kpi-val-emerald">' . number_format((float)$m['delivered_api_rev'], 2) . '<span style="font-size:14px;font-weight:500;opacity:.6"> TND</span></div>
    <div class="kpi-sub">Actual cash received</div>
    <svg class="kpi-card-icon" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
  </div>
</div>

<!-- Chart + Order Breakdown -->
<div class="analytics-layout" style="margin-bottom:16px;">
  <!-- Chart card -->
  <div class="dash-card">
    <div class="dash-card-header">
      <div>
        <div class="dash-card-title">' . ucfirst($selectedStatus) . ' Orders Over Time</div>
        <div class="dash-card-sub">Based on your selected date range &amp; status</div>
      </div>
    </div>
    <div class="dash-card-body" style="min-height:200px; padding:1rem 1.25rem;">
      <canvas id="analyticsChart"></canvas>
    </div>
  </div>

  <!-- Order Breakdown -->
  <div class="dash-card">
    <div class="dash-card-header">
      <div>
        <div class="dash-card-title">Order Breakdown</div>
        <div class="dash-card-sub">By status · ' . ($dateRange == 'today' ? 'Today' : htmlspecialchars($dateRange)) . '</div>
      </div>
    </div>
    <div class="mini-stat-list">
      <div class="mini-stat-row">
        <div class="mini-stat-label"><span class="mini-dot mini-dot-green"></span> Confirmed</div>
        <div>
          <div class="mini-stat-val">' . number_format((int)$m['confirmed_count']) . '</div>
          <div class="mini-progress"><div class="mini-progress-fill fill-green" style="width:' . ($m['focus_count'] > 0 ? min(100, round(($m['confirmed_count'] / max(1, $m['focus_count'])) * 100)) : 0) . '%"></div></div>
        </div>
      </div>
      <div class="mini-stat-row">
        <div class="mini-stat-label"><span class="mini-dot mini-dot-yellow"></span> Follow Up</div>
        <div>
          <div class="mini-stat-val">' . number_format((int)$m['followup_count']) . '</div>
          <div class="mini-progress"><div class="mini-progress-fill fill-yellow" style="width:' . ($m['focus_count'] > 0 ? min(100, round(($m['followup_count'] / max(1, $m['focus_count'])) * 100)) : 0) . '%"></div></div>
        </div>
      </div>
      <div class="mini-stat-row">
        <div class="mini-stat-label"><span class="mini-dot mini-dot-blue"></span> Delivered</div>
        <div>
          <div class="mini-stat-val">' . number_format((int)$m['delivered_count']) . '</div>
          <div class="mini-progress"><div class="mini-progress-fill fill-blue" style="width:' . ($m['focus_count'] > 0 ? min(100, round(($m['delivered_count'] / max(1, $m['focus_count'])) * 100)) : 0) . '%"></div></div>
        </div>
      </div>
      <div class="mini-stat-row">
        <div class="mini-stat-label"><span class="mini-dot mini-dot-red"></span> Returned</div>
        <div>
          <div class="mini-stat-val">' . number_format((int)$m['returned_count']) . '</div>
          <div class="mini-progress"><div class="mini-progress-fill fill-red" style="width:' . ($m['focus_count'] > 0 ? min(100, round(($m['returned_count'] / max(1, $m['focus_count'])) * 100)) : 0) . '%"></div></div>
        </div>
      </div>
    </div>
    ' . ($m['followup_count'] > 0 ? '
    <div style="padding:0 1.25rem 1rem;">
      <div style="font-size:11px;color:var(--text-muted);background:var(--yellow-light);padding:8px 10px;border-radius:6px;border-left:3px solid var(--yellow);">
        ⚡ ' . number_format((int)$m['followup_count']) . ' orders need confirmation calls
      </div>
    </div>' : '') . '
  </div>
</div>

<!-- Shipping Pipeline -->
<div class="dash-card" style="margin-bottom:16px;">
  <div class="dash-card-header">
    <div>
      <div class="dash-card-title">Shipping Pipeline</div>
      <div class="dash-card-sub">Real-time · Lifetime: ' . number_format($totalOrdersLifetime) . ' orders</div>
    </div>
    ' . ($dateRange == 'today' ? '
    <form method="POST" style="margin:0;">
      <input type="hidden" name="action" value="reset_delivered">
      <button type="submit" class="dash-btn dash-btn-default" onclick="return confirm(\'Reset the delivered count for today?\')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
        Reset Count
      </button>
    </form>' : '') . '
  </div>
  <div class="pipeline-grid">
    <a href="index.php?page=shipping-status&status=pending" class="pipeline-step" style="text-decoration:none; color:inherit;">
      <div class="pipeline-icon">📦</div>
      <div class="pipeline-label">Assigned</div>
      <div class="pipeline-value">' . number_format((int)$m['ready_pickup']) . '</div>
      <div class="pipeline-sub">Waiting for courier</div>
    </a>
    <a href="index.php?page=shipping-status&status=warehouse" class="pipeline-step" style="text-decoration:none; color:inherit;">
      <div class="pipeline-icon">🏭</div>
      <div class="pipeline-label">In Warehouse</div>
      <div class="pipeline-value" style="color:var(--blue);">' . number_format((int)$m['warehouse_count']) . '</div>
      <div class="pipeline-sub">At depot</div>
    </a>
    <a href="index.php?page=shipping-status&status=shipping" class="pipeline-step" style="text-decoration:none; color:inherit;">
      <div class="pipeline-icon">🚚</div>
      <div class="pipeline-label">Out for Delivery</div>
      <div class="pipeline-value" style="color:var(--yellow);">' . number_format((int)$m['out_for_delivery']) . '</div>
      <div class="pipeline-sub">In transit</div>
    </a>
    <a href="index.php?page=shipping-status&status=delivered" class="pipeline-step pipeline-step-highlight" style="text-decoration:none; color:inherit;">
      <div class="pipeline-icon">✅</div>
      <div class="pipeline-label" style="color:var(--accent);">Delivered</div>
      <div class="pipeline-value" style="color:var(--accent-dark);">' . number_format((int)$m['delivered_count_realtime']) . '</div>
      <div class="pipeline-sub">Successfully received</div>
    </a>
  </div>
  <div class="pipeline-footer">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Lifetime Volume: <strong>' . number_format($totalOrdersLifetime) . ' orders</strong> processed since account creation
  </div>
</div>

<!-- Mobile Filter Bottom Sheet -->
<div class="sheet-backdrop" id="filterSheetBackdrop" onclick="closeFilterSheet()">
    <div class="bottom-sheet" id="filterSheet" onclick="event.stopPropagation()">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2>Dashboard Filters</h2>
            <button class="sheet-close" onclick="closeFilterSheet()">&times;</button>
        </div>
        <div class="sheet-body">
            <form method="get" id="mobileFilterForm">
                <input type="hidden" name="page" value="dashboard">
                <input type="hidden" name="range" id="mobileRangeInput" value="' . htmlspecialchars($dateRange) . '">
                <input type="hidden" name="status" id="mobileStatusInput" value="' . htmlspecialchars($selectedStatus) . '">

                <!-- Date Range Section -->
                <div class="sheet-section">
                    <label class="sheet-label">Date Range</label>
                    <div class="sheet-pill-grid">
                        <button type="button" class="sheet-pill ' . ($dateRange == 'today' ? 'active' : '') . '" onclick="setMobileRange(\'today\')">Today</button>
                        <button type="button" class="sheet-pill ' . ($dateRange == 'yesterday' ? 'active' : '') . '" onclick="setMobileRange(\'yesterday\')">Yesterday</button>
                        <button type="button" class="sheet-pill ' . ($dateRange == '7' ? 'active' : '') . '" onclick="setMobileRange(\'7\')">7 Days</button>
                        <button type="button" class="sheet-pill ' . ($dateRange == '30' ? 'active' : '') . '" onclick="setMobileRange(\'30\')">30 Days</button>
                        <button type="button" class="sheet-pill ' . ($dateRange == 'this_month' ? 'active' : '') . '" onclick="setMobileRange(\'this_month\')">Month</button>
                        <button type="button" class="sheet-pill ' . ($dateRange == 'custom' ? 'active' : '') . '" onclick="setMobileRange(\'custom\')">Custom</button>
                    </div>

                    <div id="mobileCustomDates" class="sheet-custom-dates" style="' . ($dateRange != 'custom' ? 'display:none;' : 'display:grid;') . '">
                        <div class="input-field">
                            <span>From</span>
                            <input type="date" name="from" value="' . htmlspecialchars($customFrom) . '">
                        </div>
                        <div class="input-field">
                            <span>To</span>
                            <input type="date" name="to" value="' . htmlspecialchars($customTo) . '">
                        </div>
                    </div>
                </div>

                <!-- Status Section -->
                <div class="sheet-section">
                    <label class="sheet-label">Order Status</label>
                    <div class="sheet-pill-grid">
                        <button type="button" class="sheet-pill ' . ($selectedStatus == 'all' ? 'active' : '') . '" onclick="setMobileStatus(\'all\')">All Orders</button>
                        <button type="button" class="sheet-pill ' . ($selectedStatus == 'confirmed' ? 'active' : '') . '" onclick="setMobileStatus(\'confirmed\')">Confirmed</button>
                        <button type="button" class="sheet-pill ' . ($selectedStatus == 'followup' ? 'active' : '') . '" onclick="setMobileStatus(\'followup\')">Follow Up</button>
                        <button type="button" class="sheet-pill ' . ($selectedStatus == 'shipped' ? 'active' : '') . '" onclick="setMobileStatus(\'shipped\')">Shipped</button>
                        <button type="button" class="sheet-pill ' . ($selectedStatus == 'delivered' ? 'active' : '') . '" onclick="setMobileStatus(\'delivered\')">Delivered</button>
                        <button type="button" class="sheet-pill ' . ($selectedStatus == 'returned' ? 'active' : '') . '" onclick="setMobileStatus(\'returned\')">Returned</button>
                    </div>
                </div>

                <div class="sheet-footer">
                    <button type="submit" class="apply-btn">
                        Apply Filters
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Recent Orders Section -->
<div class="dash-card" style="margin-bottom:1.5rem;">
  <div class="dash-card-header">
    <div>
      <div class="dash-card-title">Recent Activity</div>
      <div class="dash-card-sub">Last 30 processed orders</div>
    </div>
  </div>
  <div style="overflow-x:auto;">
    <table class="recent-orders-table">
      <thead>
        <tr>
          <th>Order</th>
          <th>Customer</th>
          <th>Shop</th>
          <th>Status</th>
          <th>Total</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>';
      
      foreach ($recentOrders as $ro) {
          $isNew = ($ro['status'] === 'new' || (empty($ro['status']) && empty($ro['fiabilo_tracking_code'])));
          $statusLabel = $ro['status'] ?: 'new';
          
          $content .= '
          <tr>
            <td style="font-weight:600; color:var(--text-primary);">' . htmlspecialchars($ro['name']) . '</td>
            <td>' . htmlspecialchars($ro['billing_name'] ?: '—') . '</td>
            <td style="font-size:11px; color:var(--text-muted);">' . htmlspecialchars($ro['shop_name']) . '</td>
            <td><span class="status-badge-mini ' . htmlspecialchars($statusLabel) . '">' . ucfirst($statusLabel) . '</span></td>
            <td style="font-weight:600;">' . number_format((float)$ro['total'], 2) . ' <span style="font-size:10px; opacity:0.6;">' . htmlspecialchars($ro['currency']) . '</span></td>
            <td>
              <div style="display:flex; gap:0.5rem;">
                <a href="index.php?page=order-view&id=' . $ro['id'] . '" class="dash-btn dash-btn-default" style="padding:0.25rem 0.5rem; font-size:10px;">View</a>
                ' . ($isNew ? '<button type="button" class="dash-btn" style="background:var(--blue); color:#fff; padding:0.25rem 0.5rem; font-size:10px;" onclick="showCallSnackbar(\'' . addslashes($ro['billing_name']) . '\', \'' . addslashes($ro['billing_phone']) . '\')">Call</button>' : '') . '
              </div>
            </td>
          </tr>';
      }
      
      $content .= '
      </tbody>
    </table>
  </div>
  <div style="padding:1rem; border-top:1px solid var(--border); text-align:center;">
    <a href="index.php?page=orders" class="dash-btn dash-btn-default" style="width:100%; justify-content:center; padding:0.6rem;">
      View All Orders
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left:4px;"><polyline points="9 18 15 12 9 6"></polyline></svg>
    </a>
  </div>
</div>

<!-- Call Snackbar UI -->
<div id="call-snackbar" class="snackbar">
  <div class="snackbar-content">
    <div class="snackbar-icon">📞</div>
    <div class="snackbar-details">
      <div class="snackbar-title" id="snack-cust-name">Customer Name</div>
      <div class="snackbar-phone" id="snack-cust-phone">Phone Number</div>
    </div>
    <a href="#" id="snack-call-link" class="snackbar-btn">
      Direct Call
    </a>
    <button class="snackbar-close" onclick="closeSnackbar()">&times;</button>
  </div>
</div>

<script>
function showLoader() {
  const loader = document.getElementById(\'dashboard-loader\');
  const wrapper = document.querySelector(\'.dashboard-wrapper\');
  const statusEl = loader.querySelector(\'.loader-sub\');
  
  const messages = [
    \'Initializing secure session...\',
    \'Gathering shop performance data...\',
    \'Analyzing selected data range...\',
    \'Filtering by order status...\',
    \'Contacting Fiabilo tracking API...\',
    \'Syncing latest shipment states...\',
    \'Computing updated revenue KPIs...\',
    \'Generating analytics chart datasets...\',
    \'Preparing your performance overview...\'
  ];
  
  let msgIdx = 0;
  loader.classList.add(\'active\');
  wrapper.classList.add(\'blurred\');
  
  // Start message rotation
  const msgInterval = setInterval(() => {
    msgIdx = (msgIdx + 1) % messages.length;
    statusEl.style.opacity = 0;
    setTimeout(() => {
      statusEl.textContent = messages[msgIdx];
      statusEl.style.opacity = 1;
    }, 200);
  }, 1600);
}

function applyRange(val) {
  showLoader();
  document.getElementById(\'rangeInputDesktop\').value = val;
  document.getElementById(\'filterFormDesktop\').submit();
}
function applyStatus(type, val) {
  if (type === \'status\') {
    showLoader();
    document.getElementById(\'statusInputDesktop\').value = val;
    document.getElementById(\'filterFormDesktop\').submit();
  }
}
function toggleCustom() {
  const el = document.getElementById(\'customInputsDesktop\');
  el.style.display = (el.style.display === \'none\') ? \'flex\' : \'none\';
}

// Add loader trigger to all forms on submit
document.addEventListener(\'DOMContentLoaded\', function() {
    const forms = document.querySelectorAll(\'form\');
    forms.forEach(form => {
        form.addEventListener(\'submit\', function() {
            // Only show loader for filter/action forms, not search if it exists
            if (this.id === \'filterFormDesktop\' || this.id === \'mobileFilterForm\' || this.querySelector(\'[name=\"action\"]\')) {
                showLoader();
            }
        });
    });
});

// Bottom Sheet Functions
function openFilterSheet() {
    document.getElementById(\'filterSheetBackdrop\').classList.add(\'active\');
    document.getElementById(\'filterSheet\').classList.add(\'active\');
    document.body.style.overflow = \'hidden\';
}
function closeFilterSheet() {
    document.getElementById(\'filterSheetBackdrop\').classList.remove(\'active\');
    document.getElementById(\'filterSheet\').classList.remove(\'active\');
    document.body.style.overflow = \'\';
}
function setMobileRange(val) {
    document.getElementById(\'mobileRangeInput\').value = val;
    document.querySelectorAll(\'#mobileFilterForm .sheet-pill\').forEach(btn => {
        if(btn.onclick.toString().includes(\'setMobileRange\')) btn.classList.remove(\'active\');
    });
    event.currentTarget.classList.add(\'active\');
    
    const custom = document.getElementById(\'mobileCustomDates\');
    custom.style.display = (val === \'custom\') ? \'grid\' : \'none\';
}
function setMobileStatus(val) {
    document.getElementById(\'mobileStatusInput\').value = val;
    document.querySelectorAll(\'#mobileFilterForm .sheet-pill\').forEach(btn => {
        if(btn.onclick.toString().includes(\'setMobileStatus\')) btn.classList.remove(\'active\');
    });
    event.currentTarget.classList.add(\'active\');
}

const ctx = document.getElementById(\'analyticsChart\').getContext(\'2d\');
new Chart(ctx, {
    type: \'line\',
    data: {
        labels: ' . json_encode($graphLabels) . ',
        datasets: [{
            label: \'' . ucfirst($selectedStatus) . ' Orders\',
            data: ' . json_encode($graphData) . ',
            borderColor: \'#00b37e\',
            backgroundColor: \'rgba(0, 179, 126, 0.08)\',
            borderWidth: 2.5,
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointBackgroundColor: \'#fff\',
            pointBorderColor: \'#00b37e\',
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: \'#f1f5f9\' }, ticks: { stepSize: 1, precision: 0, color: \'#9ca3af\', font: { size: 11 } }, border: { display: false } },
            x: { grid: { display: false }, ticks: { color: \'#9ca3af\', font: { size: 11 } }, border: { display: false } }
        }
    }
});

function showCallSnackbar(name, phone) {
  document.getElementById(\'snack-cust-name\').textContent = name || \'Customer\';
  document.getElementById(\'snack-cust-phone\').textContent = phone;
  document.getElementById(\'snack-call-link\').href = \'tel:\' + phone;
  
  const snack = document.getElementById(\'call-snackbar\');
  snack.classList.add(\'active\');
  
  // Auto hide after 10 seconds
  setTimeout(() => {
    // snack.classList.remove(\'active\');
  }, 10000);
}

function closeSnackbar() {
  document.getElementById(\'call-snackbar\').classList.remove(\'active\');
}
</script>

<style>
:root {
  --radius: 10px;
  --radius-sm: 8px;
  --text-primary: #020617;
  --text-secondary: #334155;
  --text-muted: #64748b;
  --surface: #ffffff;
  --bg-main: #f8fafc;
  --border: #e2e8f0;
  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05), 0 2px 4px -2px rgb(0 0 0 / 0.05);

  --accent: #00b37e;
  --accent-dark: #008a62;
  --accent-light: #e6f7f3;
  --blue: #3b82f6;
  --blue-light: #eff6ff;
  --yellow: #f59e0b;
  --yellow-light: #fffbeb;
  --red: #ef4444;
  --red-light: #fef2f2;
}

.dashboard-wrapper { max-width: 1400px; margin: 0 auto; }
.dashboard-header { display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem; }
.header-main-row { display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 1rem; }
.header-left h1 { font-size: 1.4rem; font-weight: 700; color: var(--text-primary); margin: 0; letter-spacing: -0.03em; font-family: "DM Sans", system-ui, sans-serif; }
.header-left p { font-size: 0.82rem; color: var(--text-muted); margin-top: 0.2rem; font-weight: 500; }

.status-selector-scroll {
    width: 100%;
    overflow-x: auto;
    padding-bottom: 4px;
    scrollbar-width: none;
}
.status-selector-scroll::-webkit-scrollbar { display: none; }

.status-selector-bar { display: flex; gap: 0.4rem; flex-wrap: nowrap; min-width: max-content; }
.status-pill { border: 1.5px solid var(--border); background: var(--surface); padding: 0.4rem 1rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; color: var(--text-secondary); cursor: pointer; transition: all 0.15s; font-family: inherit; white-space: nowrap; }
.status-pill:hover { border-color: var(--text-primary); color: var(--text-primary); }
.status-pill.active { background: var(--text-primary); color: #fff; border-color: var(--text-primary); }

.advanced-filter-bar { display: flex; align-items: center; gap: 0.75rem; }
.filter-pills { 
    display: flex; 
    background: var(--bg-main); 
    padding: 3px; 
    border-radius: var(--radius-sm); 
    gap: 2px; 
    border: 1px solid var(--border);
    overflow-x: auto;
    scrollbar-width: none;
}
.filter-pills::-webkit-scrollbar { display: none; }
.pill { border: none; background: transparent; padding: 6px 14px; font-size: 12px; font-weight: 500; color: var(--text-secondary); border-radius: 6px; cursor: pointer; transition: all 0.15s; font-family: inherit; white-space: nowrap; }
.pill:hover { color: var(--text-primary); background: var(--border); }
.pill.active { background: var(--text-primary); color: #fff; box-shadow: var(--shadow-sm); }
.custom-inputs { display: flex; align-items: center; gap: 0.5rem; background: var(--bg-main); padding: 4px 8px; border-radius: var(--radius-sm); border: 1px solid var(--border); }
.custom-inputs input { height: 1.8rem; padding: 0 0.5rem; border: 1px solid var(--border); border-radius: 6px; font-size: 0.75rem; background: var(--surface); color: var(--text-primary); outline: none; transition: border-color 0.15s; }
.custom-inputs input:focus { border-color: var(--accent); }
.apply-btn-desktop { background: var(--accent); color: #fff; border: none; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.15s; flex-shrink: 0; }
.apply-btn-desktop:hover { background: var(--accent-dark); transform: translateY(-1px); }
.apply-btn-desktop:active { transform: translateY(0); }
.sep { font-size: 0.7rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; }

/* Mobile Bottom Sheet */
.mobile-filter-btn {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 0.6rem 1.25rem;
    background: #0f172a;
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.sheet-backdrop {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.4);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: none;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.sheet-backdrop.active { display: block; opacity: 1; }

.bottom-sheet {
    position: fixed;
    bottom: -100%;
    left: 0; right: 0;
    background: #fff;
    border-radius: 24px 24px 0 0;
    z-index: 10000;
    padding: 1.5rem;
    transition: bottom 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.15);
}
.bottom-sheet.active { bottom: 0; }

.sheet-handle {
    width: 40px; height: 4px;
    background: #e2e8f0;
    border-radius: 99px;
    margin: -0.5rem auto 1.5rem;
}
.sheet-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 1.5rem;
}
.sheet-header h2 { font-size: 1.2rem; font-weight: 800; margin: 0; }
.sheet-close {
    background: #f1f5f9; border: none; font-size: 1.4rem;
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #64748b;
}

.sheet-section { margin-bottom: 1.5rem; }
.sheet-label {
    display: block; font-size: 0.75rem; font-weight: 700;
    text-transform: uppercase; color: #94a3b8;
    margin-bottom: 0.75rem; letter-spacing: 0.5px;
}
.sheet-pill-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.sheet-pill {
    background: #f8fafc; border: 1.5px solid #f1f5f9;
    padding: 0.6rem 0.5rem; border-radius: 12px;
    font-size: 0.8rem; font-weight: 600; color: #475569;
    cursor: pointer; transition: all 0.2s;
}
.sheet-pill.active {
    background: #0f172a; border-color: #0f172a; color: #fff;
}

.sheet-custom-dates { grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
.input-field { display: flex; flex-direction: column; gap: 4px; }
.input-field span { font-size: 11px; font-weight: 600; color: #94a3b8; }
.input-field input {
    height: 40px; padding: 0 10px;
    border: 1px solid #e2e8f0; border-radius: 10px;
    font-size: 13px; font-family: inherit;
}

.sheet-footer { margin-top: 2rem; }
.apply-btn {
    width: 100%; height: 52px;
    background: #00b37e; color: #fff;
    border: none; border-radius: 16px;
    font-size: 1rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center; gap: 10px;
    cursor: pointer; box-shadow: 0 10px 20px rgba(0, 179, 126, 0.2);
    transition: all 0.2s;
}
.apply-btn:active { transform: scale(0.98); }

@media(max-width:768px){
    .desktop-only { display: none !important; }
    .mobile-filter-btn { display: flex; }
    .header-main-row { justify-content: space-between; }
    .kpi-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
}

.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 16px; }
.kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1rem 1.2rem; box-shadow: var(--shadow-sm); position: relative; overflow: hidden; transition: box-shadow 0.2s; animation: kpiFadeIn 0.3s ease both; }
.kpi-card:hover { box-shadow: var(--shadow); }
.kpi-card::before { content: \'\'; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.kpi-green::before { background: var(--accent); } .kpi-blue::before { background: var(--blue); } .kpi-yellow::before { background: var(--yellow); } .kpi-emerald::before { background: #10b981; }
.kpi-card:nth-child(1){animation-delay:.05s} .kpi-card:nth-child(2){animation-delay:.1s} .kpi-card:nth-child(3){animation-delay:.15s} .kpi-card:nth-child(4){animation-delay:.2s}
@keyframes kpiFadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:translateY(0)} }
.kpi-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
.kpi-value { font-size: 1.6rem; font-weight: 700; letter-spacing: -0.04em; line-height: 1; margin-bottom: 6px; }
.kpi-val-green{color:var(--accent-dark)} .kpi-val-blue{color:var(--blue)} .kpi-val-yellow{color:var(--yellow)} .kpi-val-emerald{color:#065f46}
.kpi-sub { font-size: 11px; color: var(--text-muted); position: relative; z-index: 2; }
.kpi-card-icon {
    position: absolute;
    right: 1.2rem;
    bottom: 1.2rem;
    width: 48px;
    height: 48px;
    opacity: 0.8;
    object-fit: contain;
    pointer-events: none;
    transition: transform 0.3s ease;
}
.kpi-card:hover .kpi-card-icon { transform: scale(1.1) rotate(-5deg); opacity: 1; }
.kpi-badge { display: inline-flex; align-items: center; padding: 1px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; background: var(--accent-light); color: var(--accent-dark); }

.analytics-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; }

.dash-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; transition: box-shadow 0.2s; }
.dash-card:hover { box-shadow: var(--shadow); }
.dash-card-header { padding: 0.85rem 1.25rem 0.65rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
.dash-card-title { font-size: 13px; font-weight: 600; color: var(--text-primary); }
.dash-card-sub { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.dash-card-body { padding: 1rem 1.25rem; }

.mini-stat-list { display: flex; flex-direction: column; gap: 0.85rem; padding: 1rem 1.25rem; }
.mini-stat-row { display: flex; align-items: center; justify-content: space-between; }
.mini-stat-label { font-size: 12.5px; color: var(--text-secondary); display: flex; align-items: center; gap: 0.4rem; }
.mini-stat-val { font-size: 13px; font-weight: 600; text-align: right; margin-bottom: 4px; }
.mini-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.mini-dot-green{background:var(--accent)} .mini-dot-yellow{background:var(--yellow)} .mini-dot-blue{background:var(--blue)} .mini-dot-red{background:var(--red)}
.mini-progress { height: 5px; background: var(--border); border-radius: 99px; overflow: hidden; width: 110px; }
.mini-progress-fill { height: 100%; border-radius: 99px; }
.fill-green{background:var(--accent)} .fill-yellow{background:var(--yellow)} .fill-blue{background:var(--blue)} .fill-red{background:var(--red)}

.pipeline-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; background: var(--border); margin: 1rem 1.25rem; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border); }
.pipeline-step { background: var(--surface); padding: 1rem; display: flex; flex-direction: column; gap: 3px; transition: background 0.15s; }
.pipeline-step:hover { background: var(--bg-main) !important; }
.pipeline-step-highlight { background: var(--accent-light) !important; }
.pipeline-icon { font-size: 1.25rem; margin-bottom: 4px; }
.pipeline-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--text-muted); }
.pipeline-value { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.04em; color: var(--text-primary); }
.pipeline-sub { font-size: 11px; color: var(--text-muted); }
.pipeline-footer { padding: 0.7rem 1.25rem; background: var(--bg-main); border-top: 1px solid var(--border); display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--text-muted); }
.pipeline-footer strong { color: var(--text-primary); font-weight: 600; }

.dash-btn { display: inline-flex; align-items: center; gap: 5px; padding: 0.4rem 0.85rem; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; cursor: pointer; border: none; transition: all .15s; font-family: inherit; }
.dash-btn-default { background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary); }
.dash-btn-default:hover { background: var(--bg-main); color: var(--text-primary); }

@media(max-width:1100px){
    .analytics-layout{grid-template-columns:1fr} 
    .kpi-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:768px){
    .header-main-row { flex-direction: column; align-items: flex-start; }
    .header-right { width: 100%; }
    .advanced-filter-bar { width: 100%; justify-content: space-between; }
    .filter-pills { flex: 1; }
    .kpi-grid{grid-template-columns:1fr 1fr; gap:10px} 
    .pipeline-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
    .kpi-grid{grid-template-columns:1fr}
}

/* Recent Orders Table */
.recent-orders-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.recent-orders-table th { text-align: left; padding: 10px 1.25rem; font-size: 10px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; border-bottom: 1px solid var(--border); background: var(--bg-main); }
.recent-orders-table td { padding: 12px 1.25rem; border-bottom: 1px solid #f1f5f9; }
.recent-orders-table tr:last-child td { border-bottom: none; }

.status-badge-mini { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
.status-badge-mini.new { background: #e0f2fe; color: #0369a1; }
.status-badge-mini.confirmed { background: #dcfce7; color: #166534; }
.status-badge-mini.followup { background: #fffbe6; color: #854d0e; }
.status-badge-mini.shipped { background: #eff6ff; color: #1e40af; }
.status-badge-mini.delivered { background: var(--accent-light); color: var(--accent-dark); }
.status-badge-mini.returned { background: #fef2f2; color: #991b1b; }

/* Snackbar */
.snackbar {
  position: fixed;
  bottom: -100px;
  left: 50%;
  transform: translateX(-50%);
  background: #1e293b;
  color: #ffffff;
  padding: 12px 16px;
  border-radius: 12px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.3);
  z-index: 10000;
  transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  width: 90%;
  max-width: 400px;
}
.snackbar.active {
  bottom: 30px;
}
.snackbar-content {
  display: flex;
  align-items: center;
  gap: 12px;
}
.snackbar-icon {
  font-size: 1.25rem;
  background: rgba(255,255,255,0.1);
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
}
.snackbar-details {
  flex: 1;
}
.snackbar-title {
  font-size: 13px;
  font-weight: 700;
}
.snackbar-phone {
  font-size: 11px;
  opacity: 0.7;
}
.snackbar-btn {
  background: var(--blue);
  color: #fff;
  border: none;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
  transition: transform 0.2s;
}
.snackbar-btn:active {
  transform: scale(0.95);
}
.snackbar-close {
  background: none;
  border: none;
  color: #fff;
  font-size: 1.5rem;
  cursor: pointer;
  opacity: 0.5;
}
/* Loader & Progress Bar */
.loader-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 10001;
  display: none;
  align-items: center;
  justify-content: center;
  transition: opacity 0.3s ease;
  opacity: 0;
}
.loader-overlay.active { display: flex; opacity: 1; }
.dashboard-wrapper.blurred { pointer-events: none; }

.loader-content { text-align: center; max-width: 280px; width: 90%; }
.loader-title { font-size: 1.1rem; font-weight: 800; color: var(--text-primary); margin-bottom: 4px; letter-spacing: -0.02em; }
.loader-sub { 
  font-size: 0.8rem; 
  color: var(--text-muted); 
  font-weight: 500; 
  margin-bottom: 20px; 
  transition: opacity 0.2s ease; 
}

.progress-wrap { height: 6px; background: #e2e8f0; border-radius: 99px; overflow: hidden; position: relative; }
.progress-bar-fill { height: 100%; background: linear-gradient(90deg, var(--accent) 0%, var(--blue) 100%); width: 0%; border-radius: 99px; animation: progressAnim 8s cubic-bezier(0.1, 0, 0.3, 1) forwards; }

@keyframes progressAnim {
  0% { width: 0%; }
  20% { width: 30%; }
  50% { width: 65%; }
  80% { width: 85%; }
  100% { width: 92%; }
}
</style>
</div>';

require $base . '/layouts/layout.php';
