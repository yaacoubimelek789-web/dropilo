<?php
$uid = (int) $_SESSION['user_id'];
DropforHelper::ensureSchema($app->pdo);
$currentPage = 'returned-orders';
$pageTitle = 'Returned Orders';

// Search logic
$search = trim($_GET['search'] ?? '');

// Pagination
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15;
$offset = ($pageNum - 1) * $perPage;

// Base condition: all returned statuses for both providers
$returnedStatuses = ['Rtn definitif', 'Rtn client/agence', 'Retour Expediteur', 'Retour', 'Returned', 'Cancelled', 'Refusé', 'Annulé', 'REFUSE', 'ANNULE', 'RETOUR_AU_MAGASIN', 'echouée', 'annulée', 'refusée', 'retourne', 'A verifier', 'Rtn depot', 'Retour recu'];
$placeholders = implode(',', array_fill(0, count($returnedStatuses), '?'));
$statusCondition = "(o.fiabilo_status IN ($placeholders) OR o.intigo_status IN ($placeholders) OR o.dropfor_status IN ($placeholders))";

// 1. Fetch Stats (Lifetime)
$statsSql = "
    SELECT 
        COUNT(*) as total_count,
        SUM(COALESCE(o.total, 0)) as total_value
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE s.user_id = ? AND $statusCondition
";
$stStats = $app->pdo->prepare($statsSql);
$params = array_merge([$uid], $returnedStatuses, $returnedStatuses, $returnedStatuses);
$stStats->execute($params);
$stats = $stStats->fetch();

$lifetimeCount = (int)$stats['total_count'];
$lifetimeValue = (float)$stats['total_value'];
$totalLoss = $lifetimeCount * 3; // 3 TND per returned order

// 2. Fetch Orders (Paginated)
$whereClauses = ["s.user_id = ?", $statusCondition];
$whereParams = [$uid];

if ($search !== '') {
    $searchTerm = "%$search%";
    $whereClauses[] = "(o.name LIKE ? OR o.billing_name LIKE ? OR o.billing_phone LIKE ? OR o.fiabilo_tracking_code LIKE ? OR o.intigo_tracking_code LIKE ? OR o.dropfor_tracking_code LIKE ?)";
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
}

$whereSql = implode(' AND ', $whereClauses);

// Count for pagination
$countSql = "SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE $whereSql";
$stCount = $app->pdo->prepare($countSql);
$allParams = array_merge($whereParams, $returnedStatuses, $returnedStatuses, $returnedStatuses);
$stCount->execute($allParams);
$totalFilteredCount = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalFilteredCount / $perPage));

// Main Query
$sql = "
    SELECT o.*, s.name as shop_name 
    FROM orders o 
    JOIN shops s ON o.shop_id = s.id 
    WHERE $whereSql 
    ORDER BY o.fiabilo_returned_at DESC, o.id DESC 
    LIMIT $perPage OFFSET $offset
";
$st = $app->pdo->prepare($sql);
$allParams = array_merge($whereParams, $returnedStatuses, $returnedStatuses, $returnedStatuses);
$st->execute($allParams);
$orders = $st->fetchAll();

$content = '
<div class="dashboard-wrapper">
    <!-- Sales Protection Banner -->
    <div class="protection-banner">
      <div class="banner-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
      </div>
      <div class="banner-content">
        <h4 class="banner-title">Protect Your Sales</h4>
        <p class="banner-message">Your business is shielded by our Elite Protection Wall. Definitive returns are automatically cataloged to safeguard your future revenue. Future orders from high-risk numbers will be instantly flagged, empowering you to stop losses before they happen.</p>
      </div>
    </div>

    <div class="dashboard-header">
        <div class="header-left">
            <h1>Returned Orders</h1>
            <p>Orders tracking status "Returned"</p>
        </div>
        <div class="header-right">
            <form method="get" class="search-form-inline" style="margin:0;">
                <input type="hidden" name="page" value="returned-orders">
                <div style="position:relative;">
                    <input type="text" name="search" value="' . htmlspecialchars($search) . '" placeholder="Search returned orders..." style="padding: 0.75rem 1rem 0.75rem 2.5rem; border-radius:12px; border:1px solid #e2e8f0; width:280px; font-weight:600;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-bottom: 2rem;">
        <div class="stats-card" style="background:#fef2f2; border:1px solid #fecaca;">
            <span class="label" style="color:#991b1b;">Lifetime Returned</span>
            <span class="value" style="color:#991b1b;">' . number_format($lifetimeCount) . '</span>
            <span class="sub-label" style="color:#991b1b;">Total returned orders</span>
            <div class="icon-bg" style="color:#ef444415;">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
            </div>
        </div>
        <div class="stats-card" style="background:#fff7ed; border:1px solid #ffedd5;">
            <span class="label" style="color:#9a3412;">Value of Returned</span>
            <span class="value" style="color:#9a3412;">' . number_format($lifetimeValue, 2) . ' <small>TND</small></span>
            <span class="sub-label" style="color:#9a3412;">Gross value lost</span>
            <div class="icon-bg" style="color:#f9731615;">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#f97316" stroke-width="2"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
        </div>
        <div class="stats-card" style="background:#fff1f2; border:1px solid #ffe4e6;">
            <span class="label" style="color:#be123c;">Total Loss</span>
            <span class="value" style="color:#be123c;">' . number_format($totalLoss, 2) . ' <small>TND</small></span>
            <span class="sub-label" style="color:#be123c;">3 TND per returned order</span>
            <div class="icon-bg" style="color:#e11d4815;">
                <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
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
                    <th>Return Date</th>
                    <th>Tracking Code</th>
                    <th>Current Status</th>
                    <th>Total</th>
                    <th style="width:150px;"></th>
                </tr>
            </thead>
            <tbody>';

if (empty($orders)) {
    $content .= '<tr><td colspan="8" style="text-align:center; padding:4rem; color:#64748b;">No returned orders found.</td></tr>';
} else {
    foreach ($orders as $o) {
        $content .= '
        <tr>
            <td style="font-weight:600;">' . htmlspecialchars($o['name']) . '</td>
            <td>' . htmlspecialchars($o['billing_name'] ?? '—') . '</td>
            <td>' . htmlspecialchars($o['shop_name']) . '</td>
            <td style="font-size:0.8125rem; color:#64748b;">' . ($o['fiabilo_returned_at'] ? date('M d, Y', strtotime($o['fiabilo_returned_at'])) : ($o['returned_at'] ? date('M d, Y', strtotime($o['returned_at'])) : '—')) . '</td>
            <td>
                ' . (!empty($o['fiabilo_tracking_code']) ? '<div style="margin-bottom:4px;"><small style="color:#94a3b8; font-size:0.65rem;">FIABILO:</small> <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:0.875rem; color:#475569;">' . htmlspecialchars($o['fiabilo_tracking_code']) . '</code></div>' : '') . '
                ' . (!empty($o['intigo_tracking_code']) ? '<div><small style="color:#94a3b8; font-size:0.65rem;">INTIGO:</small> <code style="background:#e0f2fe; padding:2px 6px; border-radius:4px; font-size:0.875rem; color:#0369a1;">' . htmlspecialchars($o['intigo_tracking_code']) . '</code></div>' : '') . '
                ' . (!empty($o['dropfor_tracking_code']) ? '<div><small style="color:#94a3b8; font-size:0.65rem;">DROPFOR:</small> <code style="background:#dbeafe; padding:2px 6px; border-radius:4px; font-size:0.875rem; color:#1d4ed8;">' . htmlspecialchars($o['dropfor_tracking_code']) . '</code></div>' : '') . '
            </td>
            <td>
                ' . (!empty($o['fiabilo_status']) ? '<span class="status-pill danger" style="padding:4px 10px; border-radius:20px; font-weight:700; font-size:0.75rem; margin-right:4px;">' . htmlspecialchars($o['fiabilo_status']) . '</span>' : '') . '
                ' . (!empty($o['intigo_status']) ? '<span class="status-pill warning" style="padding:4px 10px; border-radius:20px; font-weight:700; font-size:0.75rem; background:#fff7ed; color:#c2410c; border:1px solid #ffedd5;">' . htmlspecialchars($o['intigo_status']) . '</span>' : '') . '
                ' . (!empty($o['dropfor_status']) ? '<span class="status-pill" style="padding:4px 10px; border-radius:20px; font-weight:700; font-size:0.75rem; background:#dbeafe; color:#1d4ed8; border:1px solid #bfdbfe;">' . htmlspecialchars($o['dropfor_status']) . '</span>' : '') . '
            </td>
            <td style="font-weight:700;">' . number_format((float)$o['total'], 2) . ' ' . htmlspecialchars($o['currency'] ?? 'TND') . '</td>
            <td>
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" class="btn btn-sm" style="background:#f1f5f9; color:#475569;" onclick="quickTrack(' . (int)$o['id'] . ')">Track</button>
                    <a href="index.php?page=order-view&id=' . (int)$o['id'] . '" class="btn btn-sm btn-view-eye" title="View Order"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> View</a>
                </div>
            </td>
        </tr>';
    }
}

$content .= '
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    ' . ($totalPages > 1 ? '<div class="pagination-modern" style="margin-top:2rem; display:flex; justify-content:center; gap:0.5rem;">' . ($pageNum > 1 ? '<a href="index.php?page=returned-orders&p=' . ($pageNum-1) . ($search ? '&search='.urlencode($search) : '') . '" class="page-link">‹</a>' : '') . (function() use ($pageNum, $totalPages, $search) {
        $html = '';
        for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++) {
            $html .= '<a href="index.php?page=returned-orders&p=' . $i . ($search ? '&search='.urlencode($search) : '') . '" class="page-link ' . ($i == $pageNum ? 'active' : '') . '">' . $i . '</a>';
        }
        return $html;
    })() . ($pageNum < $totalPages ? '<a href="index.php?page=returned-orders&p=' . ($pageNum+1) . ($search ? '&search='.urlencode($search) : '') . '" class="page-link">›</a>' : '') . '</div>' : '') . '
</div>

<!-- Quick Track Modal -->
<div id="tracking-modal" class="modal-overlay" onclick="closeTrackingModal(event)">
    <div class="modal-container" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeTrackingModal()">&times;</button>
        <div id="tracking-content"></div>
    </div>
</div>

<script>
function quickTrack(orderId) {
    const modal = document.getElementById(\'tracking-modal\');
    const content = document.getElementById(\'tracking-content\');
    modal.classList.add(\'active\');
    content.innerHTML = \'<div style="padding:3rem; text-align:center;"><div class="spinner"></div></div>\';
    fetch(\'index.php?page=tracking-ajax&id=\' + orderId)
        .then(res => res.text())
        .then(html => content.innerHTML = html);
}
function closeTrackingModal() { document.getElementById(\'tracking-modal\').classList.remove(\'active\'); }
</script>

<style>
.pagination-modern .page-link { padding: 0.5rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #64748b; font-weight: 600; }
.pagination-modern .page-link.active { background: #1e293b; color: #fff; border-color: #1e293b; }
.modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
.modal-overlay.active { display: flex; }
.modal-container { background: white; width: 90%; max-width: 500px; border-radius: 12px; position: relative; }
.modal-close { position: absolute; top: 1rem; right: 1rem; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
.spinner { border: 3px solid #f3f3f3; border-top: 3px solid #3498db; border-radius: 50%; width: 24px; height: 24px; animation: spin 2s linear infinite; margin: auto; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.status-pill.danger { background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; }
</style>
';

require $base . '/layouts/layout.php';
