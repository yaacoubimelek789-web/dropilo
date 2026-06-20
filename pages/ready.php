<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'ready';
$pageTitle = 'Assigned Orders';

$filterShop = (int) ($_GET['shop_id'] ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 15; // Increased per-page default
$filterSearch = trim($_GET['search'] ?? '');
$offset = ($pageNum - 1) * $perPage;

// Base query parts
$whereClauses = ['s.user_id = ?', 'o.fiabilo_tracking_code IS NOT NULL'];
$whereParams = [$uid];

if ($filterShop > 0) {
    $whereClauses[] = 'o.shop_id = ?';
    $whereParams[] = $filterShop;
}
if ($filterDateFrom !== '') {
    $whereClauses[] = 'DATE(o.order_created_at) >= ?';
    $whereParams[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $whereClauses[] = 'DATE(o.order_created_at) <= ?';
    $whereParams[] = $filterDateTo;
}
if ($filterSearch !== '') {
    $whereClauses[] = '(o.name LIKE ? OR o.billing_name LIKE ? OR o.phone LIKE ? OR o.fiabilo_tracking_code LIKE ?)';
    $term = "%$filterSearch%";
    $whereParams[] = $term; // o.name
    $whereParams[] = $term; // o.billing_name
    $whereParams[] = $term; // o.phone
    $whereParams[] = $term; // o.fiabilo_tracking_code
}

$whereString = implode(' AND ', $whereClauses);

// Count total
$countSt = $app->pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE $whereString");
$countSt->execute($whereParams);
$totalCount = (int) $countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Get orders
$sql = "SELECT o.id, o.name, o.order_created_at, o.total, o.currency, o.fiabilo_tracking_code, o.fiabilo_status, s.name AS shop_name 
        FROM orders o JOIN shops s ON o.shop_id = s.id 
        WHERE $whereString 
        ORDER BY o.order_created_at DESC LIMIT $perPage OFFSET $offset";
$st = $app->pdo->prepare($sql);
$st->execute($whereParams);
$orders = $st->fetchAll();

$shops = $app->pdo->prepare('SELECT id, name FROM shops WHERE user_id = ? ORDER BY name');
$shops->execute([$uid]);
$shops = $shops->fetchAll();

$content = '
<div class="ready-view-container">
    <div class="view-header-modern">
        <div class="header-content">
            <h1>Assigned Orders</h1>
            <p>Orders sent to DROPILOU. These are being processed or are in transit.</p>
        </div>
    </div>';

if (isset($bulkMessage)) $content .= $bulkMessage;

$content .= '
    <div class="card card-modern glass">
        <form method="get" class="filters-form-modern">
            <input type="hidden" name="page" value="ready">
            <div class="filters-grid">
                <div class="filter-group">
                    <label>Shop</label>
                    <select name="shop_id" onchange="this.form.submit()" class="select-modern">
                        <option value="0">All shops</option>';
                        foreach ($shops as $s) {
                            $sel = $s['id'] == $filterShop ? ' selected' : '';
                            $content .= '<option value="' . $s['id'] . '"' . $sel . '>' . htmlspecialchars($s['name']) . '</option>';
                        }
$content .= '       </select>
                </div>
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="' . htmlspecialchars($filterDateFrom) . '" class="input-modern" onchange="this.form.submit()">
                </div>
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="' . htmlspecialchars($filterDateTo) . '" class="input-modern" onchange="this.form.submit()">
                </div>
                <div class="filter-group" style="flex: 2;">
                    <label>Quick Search</label>
                    <div style="position:relative;">
                        <input type="text" name="search" value="' . htmlspecialchars($filterSearch) . '" class="input-modern" placeholder="Name, Phone or Tracking..." style="padding-left: 2.5rem;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute; left:1rem; top:50%; transform:translateY(-50%);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    </div>
                </div>
                <div class="filter-group filter-actions">
                    <label>&nbsp;</label>
                    <a href="index.php?page=ready" class="btn-react btn-secondary">Clear Filters</a>
                </div>
            </div>
        </form>

        <div class="table-container-modern">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Shop</th>
                        <th>Date</th>
                        <th>Total</th>
                        <th>Tracking</th>
                        <th>Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>';
                foreach ($orders as $o) {
                    $status = $o['fiabilo_status'] ?: 'En attente';
                    $statusClass = strtolower(str_replace(' ', '-', $status));
                    $content .= '
                    <tr>
                        <td class="td-order-id">
                            <a href="index.php?page=order-view&id=' . $o['id'] . '&from=ready" class="order-link">#' . htmlspecialchars($o['name']) . '</a>
                        </td>
                        <td><span class="shop-badge">' . htmlspecialchars($o['shop_name']) . '</span></td>
                        <td class="td-date">' . htmlspecialchars($o['order_created_at'] ?? '') . '</td>
                        <td class="td-total">' . ($o['total'] !== null ? number_format((float)$o['total'], 2) . ' <small>' . htmlspecialchars($o['currency'] ?? 'TND') . '</small>' : '—') . '</td>
                        <td class="td-tracking"><code>' . htmlspecialchars($o['fiabilo_tracking_code']) . '</code></td>
                        <td><span class="badge-modern badge-' . $statusClass . '">' . htmlspecialchars($status) . '</span></td>
                        <td style="text-align:right;">
                            <div class="action-btns-row">
                                <button type="button" class="btn-react btn-sm btn-track" onclick="openTrackingModal(' . $o['id'] . ')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> Track
                                </button>
                                <a href="index.php?page=order-view&id=' . $o['id'] . '&from=ready" class="btn-react btn-sm btn-secondary">
                                    View
                                </a>
                            </div>
                        </td>
                    </tr>';
                }
                if (empty($orders)) {
                    $content .= '<tr><td colspan="7" class="empty-state">No orders found matching your criteria.</td></tr>';
                }
$content .= '
                </tbody>
            </table>
        </div>';

if ($totalPages > 1) {
    $content .= '
    <div class="pagination-modern">
        <div class="pg-info">Showing ' . (($pageNum - 1) * $perPage + 1) . '-' . min($pageNum * $perPage, $totalCount) . ' of ' . $totalCount . '</div>
        <div class="pg-btns">';
        $queryParams = $_GET;
        if ($pageNum > 1) {
            $queryParams['p'] = $pageNum - 1;
            $content .= '<a href="index.php?' . http_build_query($queryParams) . '" class="pg-btn">Previous</a>';
        }
        for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++) {
            $queryParams['p'] = $i;
            $active = $i == $pageNum ? ' active' : '';
            $content .= '<a href="index.php?' . http_build_query($queryParams) . '" class="pg-btn' . $active . '">' . $i . '</a>';
        }
        if ($pageNum < $totalPages) {
            $queryParams['p'] = $pageNum + 1;
            $content .= '<a href="index.php?' . http_build_query($queryParams) . '" class="pg-btn">Next</a>';
        }
    $content .= '</div></div>';
}

$content .= '</div></div>';

// Tracking Modal
$content .= '
<div id="tracking-modal" class="modal-modern" style="display:none;">
    <div class="modal-content-modern glass" style="max-width:500px;">
        <div class="modal-header-modern">
            <h3 id="tracking-modal-title">Live Tracking</h3>
            <button type="button" class="close-modal-btn" onclick="closeTrackingModal()">&times;</button>
        </div>
        <div id="tracking-modal-content" class="modal-body-modern">
            <div class="loading-spinner">Loading tracking data...</div>
        </div>
    </div>
</div>';

$content .= '
<style>
:root { --react-blue: #0ea5e9; --react-green: #22c55e; --react-red: #ef4444; }
.ready-view-container { max-width: 1200px; margin: 0 auto; color: #1e293b; padding-bottom: 3rem; }
.view-header-modern { margin-bottom: 2rem; }
.view-header-modern h1 { font-size: 2rem; font-weight: 900; color: #0f172a; margin: 0 0 0.5rem 0; letter-spacing: -0.02em; }
.view-header-modern p { color: #64748b; font-weight: 500; }

.card-modern { border-radius: 1.25rem; border: 1px solid #e2e8f0; padding: 1.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
.glass { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }

.filters-form-modern { margin-bottom: 2rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 1.5rem; }
.filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; align-items: flex-end; }
.filter-group label { display: block; font-size: 0.75rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em; }
.select-modern, .input-modern { width: 100%; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; border-radius: 0.75rem; font-weight: 600; font-size: 0.95rem; background: #fff; transition: all 0.2s; }
.select-modern:focus, .input-modern:focus { border-color: var(--react-blue); outline: none; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1); }

.table-container-modern { overflow-x: auto; }
.table-modern { width: 100%; border-collapse: collapse; }
.table-modern th { text-align: left; padding: 1rem; font-size: 0.75rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #f1f5f9; }
.table-modern td { padding: 1.25rem 1rem; font-size: 0.95rem; border-bottom: 1px solid #f8fafc; color: #334155; }
.table-modern tr:hover { background: rgba(14, 165, 233, 0.02); }

.td-order-id .order-link { font-weight: 800; color: #0f172a; text-decoration: none; }
.td-order-id .order-link:hover { color: var(--react-blue); }
.shop-badge { background: #f1f5f9; color: #475569; padding: 0.25rem 0.75rem; border-radius: 2rem; font-size: 0.85rem; font-weight: 700; }
.td-date { font-size: 0.85rem; color: #64748b; font-weight: 500; }
.td-total { font-weight: 800; color: #0f172a; }
.td-total small { color: #94a3b8; }
.td-tracking code { background: #f8fafc; padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: 600; color: #334155; border: 1px solid #e2e8f0; }

.badge-modern { padding: 0.375rem 0.75rem; border-radius: 2rem; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
.badge-en-attente { background: #fee2e2; color: #ef4444; }
.badge-en-cours { background: #e0f2fe; color: #0ea5e9; }
.badge-livré { background: #dcfce7; color: #10b981; }

.action-btns-row { display: flex; gap: 0.5rem; justify-content: flex-end; }
.btn-react { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 0.75rem; font-weight: 700; font-size: 0.85rem; border: none; cursor: pointer; transition: all 0.2s; text-decoration: none; }
.btn-secondary { background: #f1f5f9; color: #475569; }
.btn-track { background: #0f172a; color: #fff; }
.btn-react:hover { transform: translateY(-1px); filter: brightness(1.1); }

.pagination-modern { display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; }
.pg-info { font-size: 0.85rem; color: #94a3b8; font-weight: 600; }
.pg-btns { display: flex; gap: 0.5rem; }
.pg-btn { padding: 0.5rem 0.875rem; border-radius: 0.75rem; background: #fff; border: 1px solid #e2e8f0; text-decoration: none; color: #64748b; font-weight: 700; font-size: 0.85rem; }
.pg-btn.active { background: #0f172a; color: #fff; border-color: #0f172a; }

/* Modal Styles */
.modal-modern { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 2000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
.modal-content-modern { width: 95%; max-height: 90vh; border-radius: 1.5rem; border: 1px solid rgba(255,255,255,0.2); overflow: hidden; display: flex; flex-direction: column; background: #fff; }
.modal-header-modern { padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
.modal-header-modern h3 { margin: 0; font-weight: 800; font-size: 1.1rem; }
.close-modal-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8; }
.modal-body-modern { overflow-y: auto; flex: 1; min-height: 200px; position: relative; }
.loading-spinner-container { 
    display: flex; flex-direction: column; align-items: center; justify-content: center; 
    padding: 4rem; gap: 1rem; color: #94a3b8; font-weight: 600; 
}
.spinner-ring {
    width: 40px; height: 40px; border: 4px solid #f1f5f9; border-top: 4px solid var(--react-blue);
    border-radius: 50%; animation: spin 0.8s linear infinite;
}
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>

<script>
function openTrackingModal(oid) {
    var modal = document.getElementById("tracking-modal");
    var content = document.getElementById("tracking-modal-content");
    modal.style.display = "flex";
    content.innerHTML = \'<div class="loading-spinner-container"><div class="spinner-ring"></div><span>Fetching live data...</span></div>\';
    
    fetch("index.php?page=tracking-ajax&id=" + oid)
        .then(r => r.text())
        .then(html => {
            content.innerHTML = html;
        })
        .catch(err => {
            content.innerHTML = \'<div style="padding:2rem;text-align:center;color:red;">Failed to load tracking data.</div>\';
        });
}

function closeTrackingModal() {
    document.getElementById("tracking-modal").style.display = "none";
}

window.onclick = function(event) {
    let modal = document.getElementById("tracking-modal");
    if (event.target == modal) closeTrackingModal();
}
</script>
';

if (empty($orders) && !$filterShop && !$filterDateFrom && !$filterDateTo) {
    $content = '
    <div class="ready-view-container">
        <div class="card card-modern" style="text-align:center; padding:4rem 2rem;">
            <div style="width:64px; height:64px; background:#f1f5f9; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 1.5rem; color:#64748b;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            </div>
            <h2 style="font-weight:800; margin-bottom:0.5rem;">No orders assigned yet</h2>
            <p style="color:#64748b; margin-bottom:2rem;">Go to Confirmed Orders to assign them to a shipping company.</p>
            <a href="index.php?page=orders-confirmed" class="btn-react btn-primary" style="background:#0f172a; color:#fff; padding: 1rem 2rem; font-size:1rem;">Go to Confirmed Orders</a>
        </div>
    </div>';
}

require $base . '/layouts/layout.php';
