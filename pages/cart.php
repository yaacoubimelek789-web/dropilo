<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'cart';
$pageTitle = 'Cart';

$search = trim($_GET['search'] ?? '');

// Orders list (user's shops, latest first, optional search) - paginated to 10
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;
$offset = ($pageNum - 1) * $perPage;

// Count total
$countSql = 'SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ?';
$countParams = [$uid];
if ($search !== '') {
    $countSql .= ' AND (o.name LIKE ? OR o.billing_name LIKE ?)';
    $term = '%' . $search . '%';
    $countParams[] = $term;
    $countParams[] = $term;
}
$countSt = $app->pdo->prepare($countSql);
$countSt->execute($countParams);
$totalCount = (int) $countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Get orders with pagination
$sql = 'SELECT o.id, o.name, o.order_created_at, o.total, o.currency, o.billing_name, s.name AS shop_name
        FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ?';
$params = [$uid];
if ($search !== '') {
    $sql .= ' AND (o.name LIKE ? OR o.billing_name LIKE ?)';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
}
$sql .= ' ORDER BY o.order_created_at DESC, o.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$st = $app->pdo->prepare($sql);
$st->execute($params);
$orders = $st->fetchAll();

// Build pagination base URL (keep search only)
$paginationBase = 'index.php?page=cart' . ($search ? '&search=' . urlencode($search) : '');

$content = '<div class="cart-container">
<div class="cart-header">
    <h1>Cart</h1>
    <div class="cart-selection-counter" id="selection-counter">
        <span class="counter-number" id="counter-number">0</span>
        <span class="counter-label">orders selected</span>
        <button type="button" class="btn-clear-cart" onclick="clearCartSelection()" title="Clear selection">×</button>
    </div>
</div>';

// Main cart layout
$content .= '<div class="cart-layout">';

// Left side: search + order list
$content .= '<div class="cart-orders-panel">';
$content .= '<div class="cart-search">
    <form method="get" id="cart-search-form">
        <input type="hidden" name="page" value="cart">
        <input type="text" name="search" value="' . htmlspecialchars($search) . '" placeholder="Search order # or customer..." class="search-input">
        <button type="submit" class="btn btn-search">Search</button>
    </form>
</div>';

$content .= '<div class="cart-order-list" id="order-list">';
foreach ($orders as $o) {
    $content .= '<label class="cart-order-item" data-order-id="' . (int)$o['id'] . '">
        <input type="checkbox" class="order-checkbox" value="' . (int)$o['id'] . '">
        <div class="order-item-content">
            <span class="order-name">' . htmlspecialchars($o['name']) . '</span>
            <span class="order-meta">' . htmlspecialchars($o['billing_name'] ?? '—') . ' · ' . ($o['total'] !== null ? number_format((float)$o['total'], 2) . ' ' . htmlspecialchars($o['currency'] ?? '') : '—') . '</span>
        </div>
        <span class="order-check-icon">✓</span>
    </label>';
}
$content .= '</div>';

// Pagination
$content .= '<div class="cart-pagination">';
$content .= '<span class="pagination-info">Showing ' . (($pageNum - 1) * $perPage + 1) . '-' . min($pageNum * $perPage, $totalCount) . ' of ' . $totalCount . '</span>';
$content .= '<div class="pagination-controls">';
if ($pageNum > 1) {
    $content .= '<a href="' . $paginationBase . '&p=' . ($pageNum - 1) . '" class="page-btn">← Prev</a>';
}
for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++) {
    $activeClass = $i == $pageNum ? ' active' : '';
    $content .= '<a href="' . $paginationBase . '&p=' . $i . '" class="page-btn' . $activeClass . '">' . $i . '</a>';
}
if ($pageNum < $totalPages) {
    $content .= '<a href="' . $paginationBase . '&p=' . ($pageNum + 1) . '" class="page-btn">Next →</a>';
}
$content .= '</div></div>';
$content .= '</div>'; // end cart-orders-panel

// Right side: tabs + content panel
$content .= '<div class="cart-results-panel">';
$content .= '<div class="cart-tabs">
    <button class="cart-tab active" data-view="products_needed">Products needed</button>
    <button class="cart-tab" data-view="total_sales">Total sales</button>
    <button class="cart-tab" data-view="cost">Cost</button>
</div>';

$content .= '<div class="cart-result-content" id="cart-result-content">
    <div class="cart-empty-state" id="cart-empty-state">
        <div class="empty-icon">📦</div>
        <p>Select one or more orders to see results</p>
    </div>
    <div class="cart-loading" id="cart-loading" style="display:none;">
        <div class="loading-spinner"></div>
        <p class="loading-text">Calculating...</p>
        <div class="progress-bar">
            <div class="progress-fill" id="progress-fill"></div>
        </div>
    </div>
    <div class="cart-data" id="cart-data" style="display:none;"></div>
</div>';

$content .= '</div>'; // end cart-results-panel
$content .= '</div>'; // end cart-layout

$content .= '<div class="cart-confirmed-section">
    <div class="cart-confirmed-header">
        <h2>
            Confirmed Orders
            <span class="confirmed-badge-count" id="confirmed-count">0</span>
        </h2>
        <div class="cart-search">
            <input type="text" id="confirmed-search-input" placeholder="Search confirmed order..." class="search-input" onkeyup="debounceConfirmedSearch(this.value)">
        </div>
    </div>
    <div class="confirmed-orders-grid" id="confirmed-orders-grid">
        <div class="cart-loading">
            <div class="loading-spinner"></div>
            <p>Loading confirmed orders...</p>
        </div>
    </div>
</div>';

$content .= '</div>'; // end cart-container

// Cart JavaScript - handles persistent selection and live updates
$content .= '<script>
(function() {
    const STORAGE_KEY = "cartSelectedOrders";
    let selectedOrders = new Set();
    let currentView = "products_needed";
    let isLoading = false;
    
    // Load saved selection from localStorage
    function loadSelection() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const ids = JSON.parse(saved);
                if (Array.isArray(ids)) {
                    selectedOrders = new Set(ids.map(id => parseInt(id, 10)));
                }
            }
        } catch (e) {
            console.error("Failed to load cart selection:", e);
        }
        syncCheckboxes();
        updateCounter();
        if (selectedOrders.size > 0) {
            fetchResults();
        }
    }
    
    // Save selection to localStorage
    function saveSelection() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify([...selectedOrders]));
        } catch (e) {
            console.error("Failed to save cart selection:", e);
        }
    }
    
    // Sync checkboxes with current selection
    function syncCheckboxes() {
        document.querySelectorAll(".order-checkbox").forEach(cb => {
            const id = parseInt(cb.value, 10);
            cb.checked = selectedOrders.has(id);
            cb.closest(".cart-order-item").classList.toggle("selected", cb.checked);
        });
        
        // Sync confirmed orders select buttons
        document.querySelectorAll(".btn-select-confirmed").forEach(btn => {
            const id = parseInt(btn.closest(".confirmed-order-card").dataset.orderId, 10);
            const isSelected = selectedOrders.has(id);
            btn.classList.toggle("selected", isSelected);
            btn.textContent = isSelected ? "Selected" : "Select";
        });
    }
    
    // Update selection counter
    function updateCounter() {
        const count = selectedOrders.size;
        document.getElementById("counter-number").textContent = count;
        document.getElementById("selection-counter").classList.toggle("has-selection", count > 0);
    }
    
    // Toggle order selection
    function toggleOrder(id, isSelected) {
        if (isSelected) {
            selectedOrders.add(id);
        } else {
            selectedOrders.delete(id);
        }
        saveSelection();
        updateCounter();
        fetchResults();
    }
    
    // Show loading state
    function showLoading() {
        isLoading = true;
        document.getElementById("cart-empty-state").style.display = "none";
        document.getElementById("cart-loading").style.display = "flex";
        document.getElementById("cart-data").style.display = "none";
        
        // Animate progress bar
        const fill = document.getElementById("progress-fill");
        fill.style.width = "0%";
        setTimeout(() => fill.style.width = "70%", 100);
    }
    
    // Hide loading, show results
    function showResults(html) {
        isLoading = false;
        const fill = document.getElementById("progress-fill");
        fill.style.width = "100%";
        
        setTimeout(() => {
            document.getElementById("cart-loading").style.display = "none";
            if (selectedOrders.size === 0) {
                document.getElementById("cart-empty-state").style.display = "flex";
                document.getElementById("cart-data").style.display = "none";
            } else {
                document.getElementById("cart-empty-state").style.display = "none";
                document.getElementById("cart-data").style.display = "block";
                document.getElementById("cart-data").innerHTML = html;
            }
        }, 300);
    }
    
    // Fetch results via AJAX
    function fetchResults() {
        if (selectedOrders.size === 0) {
            document.getElementById("cart-empty-state").style.display = "flex";
            document.getElementById("cart-loading").style.display = "none";
            document.getElementById("cart-data").style.display = "none";
            return;
        }
        
        showLoading();
        
        const params = new URLSearchParams();
        params.append("page", "cart-ajax");
        params.append("view", currentView);
        selectedOrders.forEach(id => params.append("order_ids[]", id));
        
        fetch("index.php?" + params.toString())
            .then(res => res.text())
            .then(html => showResults(html))
            .catch(err => {
                console.error("Cart fetch error:", err);
                showResults("<p class=\"error\">Failed to load data. Please try again.</p>");
            });
    }
    
    // Switch tab
    function switchTab(view) {
        currentView = view;
        document.querySelectorAll(".cart-tab").forEach(tab => {
            tab.classList.toggle("active", tab.dataset.view === view);
        });
        if (selectedOrders.size > 0) {
            fetchResults();
        }
    }
    
    // Confirmed orders logic
    let confirmedSearchTimeout;
    window.debounceConfirmedSearch = function(val) {
        clearTimeout(confirmedSearchTimeout);
        confirmedSearchTimeout = setTimeout(() => fetchConfirmedOrders(val), 300);
    };
    
    function fetchConfirmedOrders(query = "") {
        const grid = document.getElementById("confirmed-orders-grid");
        const countSpan = document.getElementById("confirmed-count");
        
        const params = new URLSearchParams();
        params.append("page", "cart-ajax");
        params.append("view", "confirmed_orders");
        if (query) params.append("q", query);
        
        fetch("index.php?" + params.toString())
            .then(res => res.text())
            .then(html => {
                grid.innerHTML = html;
                const items = grid.querySelectorAll(".confirmed-order-card");
                countSpan.textContent = items.length;
                syncCheckboxes();
            })
            .catch(err => {
                console.error("Failed to fetch confirmed orders:", err);
                grid.innerHTML = "<p class=\"cart-message\">Failed to load confirmed orders.</p>";
            });
    }
    
    window.selectFromConfirmed = function(id, btn) {
        const isSelected = !selectedOrders.has(id);
        toggleOrder(id, isSelected);
        syncCheckboxes();
    };
    
    // Clear all selections
    window.clearCartSelection = function() {
        selectedOrders.clear();
        saveSelection();
        syncCheckboxes();
        updateCounter();
        document.getElementById("cart-empty-state").style.display = "flex";
        document.getElementById("cart-data").style.display = "none";
    };
    
    // Event listeners
    document.addEventListener("DOMContentLoaded", function() {
        // Load saved selection
        loadSelection();
        
        // Checkbox changes
        document.querySelectorAll(".order-checkbox").forEach(cb => {
            cb.addEventListener("change", function() {
                const id = parseInt(this.value, 10);
                this.closest(".cart-order-item").classList.toggle("selected", this.checked);
                toggleOrder(id, this.checked);
            });
        });
        
        // Tab clicks
        document.querySelectorAll(".cart-tab").forEach(tab => {
            tab.addEventListener("click", function() {
                switchTab(this.dataset.view);
            });
        });
        
        // Initial fetch of confirmed orders
        fetchConfirmedOrders();
    });
})();
</script>';

require $base . '/layouts/layout.php';
