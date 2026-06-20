<?php
$uid = (int) $_SESSION['user_id'];
$statusKey = $_GET['status'] ?? 'shipping';
$search = trim($_GET['search'] ?? '');
$pageTitle = 'Shipping Details';
$currentPage = 'dashboard'; // Keep dashboard highlighted in sidebar

// Map friendly status to shipping status strings (Common for both)
$statusMap = [
    'pending'   => ['En attente', 'Assigné au livreur', 'Assigné', 'Enlèvement', null, ''],
    'shipping'  => ['En cours', 'En cours de livraison', 'Expédié', 'Shipping', 'Shipped', 'En livraison'],
    'delivered' => ['Livré', 'Livrés', 'Livrer', 'Delivered', 'Reçu', 'livree'],
    'returned'  => ['Retourné', 'Annulé', 'Retour', 'Refusé', 'Returned', 'Cancelled', 'REFUSE', 'ANNULE', 'RETOUR_AU_MAGASIN', 'echouée', 'annulée', 'refusée', 'retourne', 'Annulé par admin', 'En retour définitif', 'En retour vendeur', 'Colis perdu', 'Relance', 'A verifier', 'Rtn depot', 'Rtn definitif', 'Rtn client/agence', 'Retour Expediteur', 'Retour recu'],
    'warehouse' => ['Au magasin', 'Magasin', 'Entrepôt', 'Depot', 'Aramé', 'En transfert vers centre', 'Entré au centre', 'En transfert', 'Vérification', 'En retour provisoire'],
];

$titleMap = [
    'pending' => 'Assigned',
    'shipping' => 'Out for Delivery',
    'delivered' => 'Delivered',
    'returned' => 'Returned',
    'warehouse' => 'In Warehouse',
];

$selectedStatuses = $statusMap[$statusKey] ?? $statusMap['shipping'];
$displayTitle = $titleMap[$statusKey] ?? 'Shipping Details';

// --- REAL-TIME RESET SYNC ---
// Fetch last_delivered_reset for the user
$stReset = $app->pdo->prepare("SELECT last_delivered_reset FROM user_integrations WHERE user_id = ? AND provider = 'fiabilo' OR provider = 'intigo' LIMIT 1");
$stReset->execute([$uid]);
$lastReset = $stReset->fetchColumn();

// Handle Sync Action
$syncMessage = '';
    // --- FIABILO SYNC ---
    $stF = $app->pdo->prepare('SELECT tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
    $stF->execute([$uid, 'fiabilo']);
    $intF = $stF->fetch();
    
    $updated = 0;
    $errors = 0;

    if ($intF && !empty($intF['tracking_token_encrypted'])) {
        $trackingToken = FiabiloHelper::decrypt($intF['tracking_token_encrypted'], $app->app['encryption_key'] ?? '');
        if ($trackingToken) {
            $placeholders = implode(',', array_fill(0, count($selectedStatuses), '?'));
            $whereClause = "s.user_id = ? AND o.fiabilo_tracking_code IS NOT NULL";
            if ($statusKey === 'pending') {
                $whereClause .= " AND (o.fiabilo_status IS NULL OR o.fiabilo_status = '' OR o.fiabilo_status = 'En attente')";
            } else {
                $whereClause .= " AND o.fiabilo_status IN ($placeholders)";
            }
            if ($statusKey === 'delivered' && $lastReset) $whereClause .= " AND o.fiabilo_delivered_at >= ?";
            elseif ($statusKey === 'returned' && $lastReset) $whereClause .= " AND o.fiabilo_returned_at >= ?";
            
            $sql = "SELECT o.id, o.fiabilo_tracking_code, o.fiabilo_status FROM orders o JOIN shops s ON o.shop_id = s.id WHERE $whereClause";
            $syncParams = ($statusKey === 'pending') ? [$uid] : array_merge([$uid], $selectedStatuses);
            if (($statusKey === 'delivered' || $statusKey === 'returned') && $lastReset) $syncParams[] = $lastReset;
            
            $syncSt = $app->pdo->prepare($sql);
            $syncSt->execute($syncParams);
            $ordersToSync = $syncSt->fetchAll();
            
            foreach ($ordersToSync as $order) {
                $statusRes = FiabiloHelper::getStatus($trackingToken, $order['fiabilo_tracking_code']);
                if (isset($statusRes['etat']) && $statusRes['etat'] !== $order['fiabilo_status']) {
                    $newStatus = $statusRes['etat'];
                    $newStatusLower = mb_strtolower($newStatus);
                    $tsCol = null;
                    if (in_array($newStatusLower, ['livré', 'livrés', 'livrer', 'delivered', 'reçu'])) $tsCol = 'delivered_at';
                    elseif (in_array($newStatusLower, ['retourné', 'annulé', 'retour', 'refusé', 'returned', 'cancelled'])) $tsCol = 'returned_at';
                    
                    $updSql = "UPDATE orders SET fiabilo_status = :status";
                    if ($tsCol) $updSql .= ", $tsCol = NOW()";
                    $updSql .= " WHERE id = :id";
                    $app->pdo->prepare($updSql)->execute(['status' => $newStatus, 'id' => $order['id']]);
                    $updated++;
                }
            }
        }
    }

    // --- INTIGO SYNC ---
    $stI = $app->pdo->prepare('SELECT add_token_encrypted, tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
    $stI->execute([$uid, 'intigo']);
    $intI = $stI->fetch();
    if ($intI && !empty($intI['add_token_encrypted']) && !empty($intI['tracking_token_encrypted'])) {
        $apiKey = IntigoHelper::decrypt($intI['add_token_encrypted'], $app->app['encryption_key'] ?? '');
        $merchantId = IntigoHelper::decrypt($intI['tracking_token_encrypted'], $app->app['encryption_key'] ?? '');
        if ($apiKey && $merchantId) {
            $isSandbox = ($intI['api_mode'] ?? 'prod') === 'sandbox';
            $placeholders = implode(',', array_fill(0, count($selectedStatuses), '?'));
            $whereClause = "s.user_id = ? AND o.intigo_tracking_code IS NOT NULL";
            if ($statusKey === 'pending') {
                $whereClause .= " AND (o.intigo_status IS NULL OR o.intigo_status = '' OR o.intigo_status = 'En attente')";
            } else {
                $whereClause .= " AND o.intigo_status IN ($placeholders)";
            }
            
            $sql = "SELECT o.id, o.intigo_tracking_code, o.intigo_status FROM orders o JOIN shops s ON o.shop_id = s.id WHERE $whereClause";
            $syncParams = ($statusKey === 'pending') ? [$uid] : array_merge([$uid], $selectedStatuses);
            
            $syncSt = $app->pdo->prepare($sql);
            $syncSt->execute($syncParams);
            $ordersToSync = $syncSt->fetchAll();
            
            foreach ($ordersToSync as $order) {
                $statusRes = IntigoHelper::getTrackingStatus($order['intigo_tracking_code'], $apiKey, $merchantId, $isSandbox);
                if (isset($statusRes['status'])) {
                    $statusNum = (int) $statusRes['status'];
                    $newStatus = IntigoHelper::getStatusLabel($statusNum);
                    
                    if ($newStatus !== $order['intigo_status']) {
                        $tsCol = null;
                        if ($statusNum === IntigoHelper::STATUS_DELIVERED) $tsCol = 'delivered_at';
                        elseif (in_array($statusNum, [IntigoHelper::STATUS_RETURN_DEFINITIVE, IntigoHelper::STATUS_LOST, IntigoHelper::STATUS_CANCELLED_ADMIN])) $tsCol = 'returned_at';
                        
                        $updSql = "UPDATE orders SET intigo_status = :status";
                        if ($tsCol) $updSql .= ", $tsCol = NOW()";
                        $updSql .= " WHERE id = :id";
                        $app->pdo->prepare($updSql)->execute(['status' => $newStatus, 'id' => $order['id']]);
                        $updated++;
                    }
                }
            }
        }
    }
    $syncMessage = '<div class="alert alert-success">Sync complete. Updated ' . $updated . ' orders.</div>';

// Ensure database connection is still alive after potential sync loop
ensurePdoAlive($app);

// Count stats
$placeholders = implode(',', array_fill(0, count($selectedStatuses), '?'));
$whereClause = "s.user_id = ? AND (o.fiabilo_tracking_code IS NOT NULL OR o.intigo_tracking_code IS NOT NULL)";
if ($statusKey === 'pending') {
    $whereClause .= " AND ((o.fiabilo_tracking_code IS NOT NULL AND (o.fiabilo_status IS NULL OR o.fiabilo_status = '' OR o.fiabilo_status = 'En attente')) OR (o.intigo_tracking_code IS NOT NULL AND (o.intigo_status IS NULL OR o.intigo_status = '' OR o.intigo_status = 'En attente')))";
} else {
    $whereClause .= " AND (o.fiabilo_status IN ($placeholders) OR o.intigo_status IN ($placeholders))";
}

if ($search !== '') {
    $searchTerm = "%$search%";
    $whereClause .= " AND (o.name LIKE ? OR o.billing_name LIKE ? OR o.billing_phone LIKE ? OR o.fiabilo_tracking_code LIKE ? OR o.intigo_tracking_code LIKE ?)";
}

$sql = "SELECT o.*, s.name as shop_name FROM orders o JOIN shops s ON o.shop_id = s.id WHERE $whereClause ORDER BY o.id DESC";

$finalParams = [$uid];
if ($statusKey !== 'pending') {
    $finalParams = array_merge($finalParams, $selectedStatuses, $selectedStatuses);
}
if ($search !== '') {
    $finalParams = array_merge($finalParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

$st = $app->pdo->prepare($sql);
$st->execute($finalParams);
$orders = $st->fetchAll();

// Calculate Statistics for filtered view
$totalGross = 0;
$orderCount = count($orders);
foreach ($orders as $o) {
    $totalGross += (float)$o['total'];
}
$totalNet = ($totalGross - ($orderCount * 8)) * 0.97;
$currency = $orders[0]['currency'] ?? 'TND';

// Calculate Global Delivery Rate for the user
$deliveredList = ['Livré', 'Livrés', 'Livrer', 'Delivered', 'Reçu'];
$returnedList = ['Retourné', 'Annulé', 'Retour', 'Refusé', 'Returned', 'Cancelled'];
$shippingList = ['En cours', 'En cours de livraison', 'Expédié', 'Shipping', 'Shipped'];
$warehouseList = ['Au magasin', 'Magasin', 'Entrepôt', 'Depot', 'Aramé'];

$allAccepted = array_merge($deliveredList, $returnedList, $shippingList, $warehouseList);

$placeholdersDel = implode(',', array_fill(0, count($deliveredList), '?'));
$placeholdersAcc = implode(',', array_fill(0, count($allAccepted), '?'));

$stStats = $app->pdo->prepare("
    SELECT 
        SUM(CASE WHEN fiabilo_status IN ($placeholdersDel) OR intigo_status IN ($placeholdersDel) THEN 1 ELSE 0 END) as delivered_count,
        SUM(CASE WHEN fiabilo_status IN ($placeholdersAcc) OR intigo_status IN ($placeholdersAcc) THEN 1 ELSE 0 END) as accepted_count
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE s.user_id = ? AND (o.fiabilo_tracking_code IS NOT NULL OR o.intigo_tracking_code IS NOT NULL)
");

$stStats->execute(array_merge($deliveredList, $deliveredList, $allAccepted, $allAccepted, [$uid]));
$globalStats = $stStats->fetch();

$dCount = (int)($globalStats['delivered_count'] ?? 0);
$accCount = (int)($globalStats['accepted_count'] ?? 0);
$deliveryRate = $accCount > 0 ? ($dCount / $accCount) * 100 : 0;

$content = '
<div class="dashboard-wrapper">
    <div class="dashboard-header">
        <div class="header-left">
            <div style="display:flex; align-items:center; gap:0.75rem; margin-bottom:0.5rem;">
                <a href="index.php?page=dashboard" style="text-decoration:none; color:#64748b; display:flex; align-items:center; font-size:0.875rem; font-weight:500;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><polyline points="15 18 9 12 15 6"></polyline></svg>
                    Back to Dashboard
                </a>
            </div>
            <h1>' . htmlspecialchars($displayTitle) . '</h1>
            <p>' . $orderCount . ' orders currently in this state</p>
        </div>
        <div class="header-right" style="display:flex; gap:1rem; align-items:center;">
            <form method="get" class="search-form-inline" style="margin:0;">
                <input type="hidden" name="page" value="shipping-status">
                <input type="hidden" name="status" value="' . htmlspecialchars($statusKey) . '">
                <div style="position:relative;">
                    <input type="text" name="search" value="' . htmlspecialchars($search) . '" placeholder="Search orders..." style="padding: 0.75rem 1rem 0.75rem 2.5rem; border-radius:12px; border:1px solid #e2e8f0; width:250px; font-weight:600;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
            </form>
            <form method="post" style="margin:0;">
                <button type="submit" name="sync_tracking" value="1" class="btn" style="display:flex; align-items:center; gap:0.5rem; border-radius:12px; padding:0.75rem 1.25rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                    Sync Tracking Now
                </button>
            </form>
        </div>
    </div>

    ' . $syncMessage . '

    <!-- Stats Section -->
    <div class="stats-grid">
        <div class="stats-card primary">
            <span class="label">Total Gross Sales</span>
            <span class="value">' . number_format($totalGross, 2) . ' ' . $currency . '</span>
            <span class="sub-label">Filtered view (' . $orderCount . ' orders)</span>
            <div class="icon-bg">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
        </div>
        <div class="stats-card highlight">
            <span class="label">Net Total</span>
            <span class="value">' . number_format($totalNet, 2) . ' ' . $currency . '</span>
            <span class="sub-label">Deducted 8 TND/order & 3% fee</span>
            <div class="icon-bg">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>
            </div>
        </div>
        <div class="stats-card highlight">
            <span class="label">Delivery Rate</span>
            <span class="value">' . number_format($deliveryRate, 1) . '%</span>
            <span class="sub-label">Global (Delivered / Accepted)</span>
            <div class="icon-bg">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="16 12 12 8 8 12"></polyline><line x1="12" y1="16" x2="12" y2="8"></line></svg>
            </div>
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden; border-radius:16px; border:1px solid #eee;">
        <table class="modern-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Shop</th>
                    <th>Sent At</th>
                    <th>Tracking Code</th>
                    <th>Current Status</th>
                    <th>Total</th>
                    <th style="width:150px;"></th>
                </tr>
            </thead>
            <tbody>';

if (empty($orders)) {
    $content .= '<tr><td colspan="8" style="text-align:center; padding:3rem; color:var(--color-text-muted);">No orders found in this category.</td></tr>';
} else {
    $checkIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 2px;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    foreach ($orders as $o) {
        $content .= '
        <tr>
            <td style="font-weight:600;">' . htmlspecialchars($o['name']) . '</td>
            <td>' . htmlspecialchars($o['billing_name'] ?? '—') . '</td>
            <td>' . htmlspecialchars($o['shop_name']) . '</td>
            <td style="font-size:0.8125rem; color:var(--color-text-muted);">' . ($o['shipped_at'] ? date('M d, H:i', strtotime($o['shipped_at'])) : ($o['fiabilo_sent_at'] ? date('M d, H:i', strtotime($o['fiabilo_sent_at'])) : '—')) . '</td>
            <td>
                ' . (!empty($o['fiabilo_tracking_code']) ? '<div><small style="font-size:0.6rem; color:#94a3b8;">FIABILO:</small> <code style="background:#f1f5f9; padding:2px 4px; border-radius:4px; font-size:0.8rem;">' . htmlspecialchars($o['fiabilo_tracking_code']) . '</code></div>' : '') . '
                ' . (!empty($o['intigo_tracking_code']) ? '<div><small style="font-size:0.6rem; color:#94a3b8;">INTIGO:</small> <code style="background:#e0f2fe; padding:2px 4px; border-radius:4px; font-size:0.8rem; color:#0369a1;">' . htmlspecialchars($o['intigo_tracking_code']) . '</code></div>' : '') . '
            </td>
            <td>
                ' . (!empty($o['fiabilo_status']) ? ($statusKey === 'delivered' ? '<div class="status-confirmed-new">' . $checkIcon . ' ' . htmlspecialchars($o['fiabilo_status']) . '</div>' : '<span class="status-pill ' . ($statusKey === 'returned' ? 'danger' : 'info') . '" style="margin-bottom:2px; display:inline-block;">' . htmlspecialchars($o['fiabilo_status']) . '</span>') : '') . '
                ' . (!empty($o['intigo_status']) ? ($statusKey === 'delivered' ? '<div class="status-confirmed-new">' . $checkIcon . ' ' . htmlspecialchars($o['intigo_status']) . '</div>' : '<span class="status-pill ' . ($statusKey === 'returned' ? 'danger' : 'info') . '" style="display:inline-block;">' . htmlspecialchars($o['intigo_status']) . '</span>') : '') . '
            </td>
            <td style="font-weight:600;">' . number_format((float)$o['total'], 2) . ' ' . htmlspecialchars($o['currency'] ?? 'TND') . '</td>
            <td style="display:flex; gap:0.5rem;">
                <button type="button" class="btn btn-sm btn-info" onclick="quickTrack(' . (int)$o['id'] . ')">Track</button>
                <a href="index.php?page=order-view&id=' . (int)$o['id'] . '" class="btn btn-sm btn-view-eye" title="View Order"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> View</a>
            </td>
        </tr>';
    }
}

$content .= '
            </tbody>
        </table>
    </div>
</div>

<!-- Quick Track Modal -->
<div id="tracking-modal" class="modal-overlay" onclick="closeTrackingModal(event)">
    <div class="modal-container" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeTrackingModal()">&times;</button>
        <div id="tracking-content">
            <div style="padding:2rem; text-align:center; color:#64748b;">
                <div class="spinner" style="margin: 0 auto 1rem;"></div>
                Loading tracking details...
            </div>
        </div>
    </div>
</div>

<script>
function quickTrack(orderId) {
    const modal = document.getElementById(\'tracking-modal\');
    const content = document.getElementById(\'tracking-content\');
    
    modal.classList.add(\'active\');
    content.innerHTML = \'<div style="padding:2rem; text-align:center; color:#64748b;"><div class="spinner" style="margin: 0 auto 1rem;"></div>Loading tracking details...</div>\';
    
    fetch(\'index.php?page=tracking-ajax&id=\' + orderId)
        .then(response => response.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = \'<div style="padding:2rem; color:#ef4444;">Failed to load tracking details.</div>\';
        });
}

function closeTrackingModal(e) {
    document.getElementById(\'tracking-modal\').classList.remove(\'active\');
}
</script>

<style>
/* Modal Styles */
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 1000; padding: 1rem; }
.modal-overlay.active { display: flex; }
.modal-container { background: white; width: 100%; max-width: 500px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); position: relative; overflow: hidden; animation: modalSlide 0.3s ease-out; }
.modal-close { position: absolute; top: 1rem; right: 1rem; background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; z-index: 10; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: background 0.2s; }
.modal-close:hover { background: #f1f5f9; color: #1e293b; }

@keyframes modalSlide { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.spinner { width: 24px; height: 24px; border: 3px solid #e2e8f0; border-top-color: #3b82f6; border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.btn-info { background: #eff6ff; color: #3b82f6; border: 1px solid #dbeafe; }
.btn-info:hover { background: #dbeafe; }

.status-pill.info { background: #eff6ff; color: #3b82f6; border: 1px solid #dbeafe; }
.status-pill.success { background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; }
.status-pill.danger { background: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; }
</style>';

require $base . '/layouts/layout.php';
