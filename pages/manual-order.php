<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'manual-order';
$pageTitle = 'Add Manual Order';
$message = '';

// Handle POST request to save the manual order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_manual_order'])) {
    $shopId = (int) ($_POST['shop_id'] ?? 0);
    $billingName = trim($_POST['billing_name'] ?? '');
    $billingPhone = trim($_POST['billing_phone'] ?? '');
    $shippingAddress = trim($_POST['shipping_address'] ?? '');
    $selectedProductIds = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : [];

    // Verify shop belongs to user
    $st = $app->pdo->prepare('SELECT id FROM shops WHERE id = ? AND user_id = ?');
    $st->execute([$shopId, $uid]);
    if (!$st->fetch()) {
        $message = '<div class="alert alert-error">Invalid shop selected.</div>';
    } elseif (empty($billingName) || empty($billingPhone) || empty($shippingAddress)) {
        $message = '<div class="alert alert-error">Please fill in all customer details.</div>';
    } elseif (empty($selectedProductIds)) {
        $message = '<div class="alert alert-error">Please select at least one product.</div>';
    } else {
        try {
            $app->pdo->beginTransaction();

            // Generate an order name like '#1001-M'
            $orderName = '#' . rand(10000, 99999) . '-M';

            // Calculate total price accurately
            $totalPrice = 0;
            $productsFetched = [];
            if (!empty($selectedProductIds)) {
                $placeholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
                $prodSt = $app->pdo->prepare("SELECT id, title, variant_price, variant_sku FROM products WHERE shop_id = ? AND id IN ($placeholders)");
                $prodSt->execute(array_merge([$shopId], $selectedProductIds));
                while ($row = $prodSt->fetch()) {
                    // Count how many times this product ID was selected
                    $qty = array_count_values($selectedProductIds)[$row['id']] ?? 1;
                    $row['qty'] = $qty;
                    $productsFetched[] = $row;
                    $totalPrice += (float)$row['variant_price'] * $qty;
                }
            }

            $now = date('Y-m-d\TH:i:sP'); // ISO 8601

            $orderIns = $app->pdo->prepare('
                INSERT INTO orders (shop_id, name, order_created_at, financial_status, fulfillment_status, total, currency, shipping_method,
                billing_name, billing_phone, billing_address, phone, shipping_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $orderIns->execute([
                $shopId, $orderName, $now, 'pending', 'unfulfilled',
                $totalPrice, 'TND', 'Manual',
                $billingName, $billingPhone, $shippingAddress, $billingPhone, $shippingAddress
            ]);
            $orderId = (int) $app->pdo->lastInsertId();

            $lineIns = $app->pdo->prepare('
                INSERT INTO order_line_items (order_id, lineitem_name, lineitem_sku, lineitem_price, lineitem_quantity, fulfillment_status, product_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($productsFetched as $p) {
                $lineIns->execute([
                    $orderId, $p['title'], $p['variant_sku'], $p['variant_price'], $p['qty'], 'unfulfilled', $p['id']
                ]);
            }

            $app->pdo->commit();
            header('Location: index.php?page=orders&msg=' . urlencode('Manual order created successfully'));
            exit;

        } catch (Throwable $e) {
            $app->pdo->rollBack();
            $message = '<div class="alert alert-error">Error saving order: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Fetch shops
$shops = $app->pdo->prepare('SELECT id, name FROM shops WHERE user_id = ? ORDER BY name');
$shops->execute([$uid]);
$shops = $shops->fetchAll();

// Fetch all products for the user\'s shops, to pass to JS
$prods = $app->pdo->prepare('SELECT p.id, p.shop_id, p.title, p.variant_price, p.image_src FROM products p JOIN shops s ON p.shop_id = s.id WHERE s.user_id = ? ORDER BY p.title');
$prods->execute([$uid]);
$allProducts = $prods->fetchAll(PDO::FETCH_ASSOC);

// Pass to JS securely
$productsJson = json_encode($allProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$content = '
<div class="manual-order-container">
    <div class="mo-header">
        <a href="index.php?page=orders" class="btn-back">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back
        </a>
        <h1>Create Manual Order</h1>
        <p>Enter customer details and assign products</p>
    </div>
    
    ' . $message . '

    <!-- Progress Tracker -->
    <div class="mo-progress-wrapper">
        <div class="mo-progress-info">
            <span class="step-text" id="mo-step-text">Information (0%)</span>
            <span class="step-completion" id="mo-completion">0 / 4 completed</span>
        </div>
        <div class="mo-progress-track">
            <div class="mo-progress-bar" id="mo-progress-bar"></div>
        </div>
    </div>

    <form method="post" id="manual-order-form">
        <div class="mo-layout">
            <!-- Left Column: Form -->
            <div class="mo-left">
                <div class="mo-card">
                    <h3>Customer Details</h3>
                    
                    <div class="form-group">
                        <label>Target Shop</label>
                        <select name="shop_id" id="mo-shop" class="mo-input mo-field" required onchange="updateProgress(); filterProductsByShop();">
                            <option value="">-- Select a Shop --</option>';
                            foreach ($shops as $s) {
                                $content .= '<option value="' . $s['id'] . '">' . htmlspecialchars($s['name']) . '</option>';
                            }
$content .= '
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="billing_name" id="mo-name" class="mo-input mo-field" placeholder="e.g. John Doe" required oninput="updateProgress()">
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="billing_phone" id="mo-phone" class="mo-input mo-field" placeholder="e.g. 55 123 456" required oninput="updateProgress()">
                    </div>

                    <div class="form-group">
                        <label>Shipping Address</label>
                        <input type="text" name="shipping_address" id="mo-address" class="mo-input mo-field" placeholder="e.g. 123 Main Street, Ariana" required oninput="updateProgress()">
                    </div>
                </div>
            </div>

            <!-- Right Column: Products & Summary -->
            <div class="mo-right">
                <div class="mo-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
                        <h3 style="margin:0;">Products</h3>
                        <button type="button" class="btn btn-primary" onclick="openProductModal()" id="btn-select-product">+ Select Products</button>
                    </div>

                    <div id="mo-selected-products" class="mo-selected-list">
                        <div class="empty-state">No products selected</div>
                    </div>
                </div>

                <div class="mo-card summary-card">
                    <h3>Order Summary</h3>
                    <div class="summary-row">
                        <span>Items Count</span>
                        <span id="summary-qty">0</span>
                    </div>
                    <div class="summary-line"></div>
                    <div class="summary-row total-row">
                        <span>Total Price</span>
                        <div><span id="summary-total">0.00</span> <small>TND</small></div>
                    </div>
                    
                    <input type="hidden" name="submit_manual_order" value="1">
                    <button type="submit" class="btn btn-save-large" id="submit-btn" disabled>CREATE ORDER</button>
                </div>
            </div>
        </div>
        <div id="hidden-inputs-container"></div>
    </form>
</div>

<!-- Product Selection Modal -->
<div class="mo-modal-overlay" id="product-modal" onclick="closeProductModal(event)">
    <div class="mo-modal-card" onclick="event.stopPropagation()">
        <div class="mo-modal-header">
            <h3>Select Products</h3>
            <div class="search-box" style="margin: 0;">
                <input type="text" id="modal-search" placeholder="Search by name..." class="search-input" onkeyup="renderModalProducts()" style="padding:0.5rem 1rem;">
            </div>
            <button type="button" class="mo-close" onclick="closeProductModal()">&times;</button>
        </div>
        <div class="mo-modal-body" id="modal-product-list">
            <!-- Products injected by JS -->
        </div>
        <div class="mo-modal-footer">
            <button type="button" class="btn btn-save-large" style="padding: 0.75rem 2rem; border-radius: 8px; width: 100%;" onclick="closeProductModal()">Done</button>
        </div>
    </div>
</div>

<style>
.manual-order-container { max-width: 1100px; margin: 0 auto; color: #1e293b; padding-bottom: 4rem; }
.mo-header { margin-bottom: 2rem; }
.mo-header h1 { font-family: var(--font-syne); font-size: 2rem; margin: 0.5rem 0 0.25rem 0; font-weight: 800; }
.mo-header p { color: #64748b; margin: 0; }
.btn-back { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; font-weight: 700; font-size: 0.9rem; transition: color 0.2s; }
.btn-back:hover { color: #0ea5e9; }

.mo-progress-wrapper { background: #fff; border-radius: 12px; padding: 1.5rem; border: 1px solid #e2e8f0; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
.mo-progress-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; font-weight: 700; font-size: 0.9rem; }
.step-text { color: #0ea5e9; }
.step-completion { color: #94a3b8; }
.mo-progress-track { height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
.mo-progress-bar { height: 100%; background: linear-gradient(90deg, #0ea5e9, #6366f1); width: 0%; transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1); }

.mo-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; }
@media(max-width: 800px) { .mo-layout { grid-template-columns: 1fr; } }
.mo-card { background: #fff; border-radius: 16px; padding: 2rem; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
.mo-card h3 { margin: 0 0 1.5rem 0; font-family: var(--font-syne); font-weight: 800; font-size: 1.25rem; }

.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; font-weight: 700; font-size: 0.85rem; color: #475569; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; }
.mo-input { width: 100%; border: 2px solid #e2e8f0; border-radius: 10px; padding: 0.8rem 1rem; font-size: 0.95rem; font-weight: 500; transition: border-color 0.2s; outline: none; background: #f8fafc; }
.mo-input:focus { border-color: #0ea5e9; background: #fff; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1); }

.empty-state { text-align: center; padding: 2rem; color: #94a3b8; border: 2px dashed #cbd5e1; border-radius: 8px; font-weight: 600; font-size: 0.9rem; }
.summary-card { background: #0f172a; color: #fff; border: none; }
.summary-card h3 { color: #f8fafc; }
.summary-row { display: flex; justify-content: space-between; font-weight: 600; font-size: 0.95rem; margin-bottom: 0.75rem; color: #cbd5e1; }
.summary-line { height: 1px; background: rgba(255,255,255,0.1); margin: 1rem 0; }
.total-row { font-size: 1.5rem; font-weight: 800; color: #fff; margin-bottom: 1.5rem; align-items: baseline; }
.total-row small { font-size: 0.9rem; color: #94a3b8; font-weight: 700; }

.btn-save-large { width: 100%; background: #0ea5e9; color: white; padding: 1rem; border-radius: 12px; font-weight: 800; font-size: 1rem; border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 6px -1px rgba(14, 165, 233, 0.3); }
.btn-save-large:hover:not(:disabled) { background: #0284c7; transform: translateY(-2px); }
.btn-save-large:disabled { background: #334155; opacity: 0.5; cursor: not-allowed; box-shadow: none; }

.btn-primary { background: #0f172a; color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 700; cursor: pointer; transition: background 0.2s; }
.btn-primary:hover:not(:disabled) { background: #1e293b; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

/* Selected list items */
.selected-p-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 0.5rem; background: #f8fafc; }
.selected-p-img { width: 32px; height: 32px; border-radius: 4px; object-fit: cover; margin-right: 12px; flex-shrink: 0; background: #e2e8f0; }
.selected-p-info { flex: 1; min-width: 0; }
.selected-p-title { font-weight: 700; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.selected-p-price { font-size: 0.8rem; color: #64748b; font-weight: 600; }
.selected-p-actions { display: flex; align-items: center; gap: 0.5rem; }
.qty-btn { width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; border-radius: 4px; border: none; cursor: pointer; font-weight: 700; color: #475569; }
.qty-btn:hover { background: #cbd5e1; }
.qty-display { font-weight: 800; font-size: 0.9rem; min-width: 16px; text-align: center; }

/* Modal */
.mo-modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: none; align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(4px); }
.mo-modal-overlay.active { display: flex; }
.mo-modal-card { background: white; width: 90%; max-width: 600px; border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); display: flex; flex-direction: column; max-height: 80vh; }
.mo-modal-header { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
.mo-modal-header h3 { margin: 0; font-family: var(--font-syne); font-size: 1.25rem; white-space: nowrap; }
.mo-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #94a3b8; transition: color 0.2s; }
.mo-close:hover { color: #0ea5e9; }
.mo-modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
.mo-modal-footer { padding: 1.5rem; border-top: 1px solid #e2e8f0; }

.modal-p-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 0.75rem; transition: border-color 0.2s; }
.modal-p-item:hover { border-color: #0ea5e9; }
.modal-p-add { background: #f8fafc; border: 1px solid #cbd5e1; color: #475569; border-radius: 8px; padding: 0.5rem 1rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.modal-p-add.added { background: #0ea5e9; border-color: #0ea5e9; color: white; }
</style>

<script>
const allProducts = ' . $productsJson . ';
let cart = {}; // product_id => qty

function updateProgress() {
    let filled = 0;
    const fields = document.querySelectorAll(".mo-field");
    const totalFields = fields.length + 1; // +1 for product selection
    
    fields.forEach(f => {
        if(f.value.trim() !== "") filled++;
    });
    
    const shopId = document.getElementById("mo-shop").value;
    if(!shopId && Object.keys(cart).length > 0) {
        cart = {};
        renderCart();
    }
    
    let hasProducts = Object.keys(cart).length > 0;
    if(hasProducts) filled++;
    
    let pct = (filled / totalFields) * 100;
    document.getElementById("mo-progress-bar").style.width = pct + "%";
    document.getElementById("mo-completion").innerText = filled + " / " + totalFields + " completed";
    
    if(pct === 100) {
        document.getElementById("mo-step-text").innerText = "Ready to create! (100%)";
        document.getElementById("mo-step-text").style.color = "#10b981";
        document.getElementById("mo-progress-bar").style.background = "linear-gradient(90deg, #10b981, #34d399)";
        document.getElementById("submit-btn").disabled = false;
    } else {
        document.getElementById("mo-step-text").innerText = "Information (" + Math.round(pct) + "%)";
        document.getElementById("mo-step-text").style.color = "#0ea5e9";
        document.getElementById("mo-progress-bar").style.background = "linear-gradient(90deg, #0ea5e9, #6366f1)";
        document.getElementById("submit-btn").disabled = true;
    }
}

function filterProductsByShop() {
    // Empty the cart when shop changes
    cart = {};
    renderCart();
}

function openProductModal() {
    document.getElementById("product-modal").classList.add("active");
    renderModalProducts();
}

function closeProductModal(e) {
    if(e && e.target !== document.getElementById("product-modal")) return;
    document.getElementById("product-modal").classList.remove("active");
}

function renderModalProducts() {
    const list = document.getElementById("modal-product-list");
    const shopId = document.getElementById("mo-shop").value;
    const q = document.getElementById("modal-search").value.toLowerCase();
    
    let html = "";
    let count = 0;
    allProducts.forEach(p => {
        if((!shopId || p.shop_id == shopId) && (p.title.toLowerCase().includes(q) || p.id == q)) {
            let inCart = cart[p.id] ? true : false;
            let img = p.image_src ? p.image_src : "https://placehold.co/40x40?text=P";
            html += `
            <div class="modal-p-item">
                <div style="display:flex; align-items:center;">
                    <img src="${img}" class="selected-p-img" alt="">
                    <div class="selected-p-info">
                        <div class="selected-p-title">${p.title}</div>
                        <div class="selected-p-price">${parseFloat(p.variant_price).toFixed(2)} TND</div>
                    </div>
                </div>
                <button type="button" class="modal-p-add ${inCart ? \'added\' : \'\'}" onclick="toggleProduct(${p.id})">
                    ${inCart ? \'Added\' : \'Add\'}
                </button>
            </div>
            `;
            count++;
        }
    });
    
    if(count === 0) {
        html = "<div class=\'empty-state\'>No products found matching your search.</div>";
    }
    list.innerHTML = html;
}

function toggleProduct(pid) {
    let p = allProducts.find(x => x.id == pid);
    if(p) {
        const shopSel = document.getElementById("mo-shop");
        if(!shopSel.value) {
            shopSel.value = p.shop_id;
            updateProgress();
        }
    }
    if(cart[pid]) {
        delete cart[pid];
    } else {
        cart[pid] = 1;
    }
    renderModalProducts();
    renderCart();
}

function updateQty(pid, delta) {
    if(cart[pid]) {
        cart[pid] += delta;
        if(cart[pid] <= 0) {
            delete cart[pid];
        }
        renderCart();
    }
}

function renderCart() {
    const container = document.getElementById("mo-selected-products");
    const hiddenInputs = document.getElementById("hidden-inputs-container");
    
    let html = "";
    let inputsHtml = "";
    let itemTotal = 0;
    let priceTotal = 0;
    
    // We need object keys
    const pids = Object.keys(cart);
    
    if(pids.length === 0) {
        container.innerHTML = "<div class=\'empty-state\'>No products selected</div>";
    } else {
        pids.forEach(pid => {
            let p = allProducts.find(x => x.id == pid);
            if(p) {
                let qty = cart[pid];
                itemTotal += qty;
                priceTotal += (parseFloat(p.variant_price) * qty);
                
                let img = p.image_src ? p.image_src : "https://placehold.co/40x40?text=P";
                html += `
                <div class="selected-p-item">
                    <img src="${img}" class="selected-p-img" alt="">
                    <div class="selected-p-info">
                        <div class="selected-p-title">${p.title}</div>
                        <div class="selected-p-price">${parseFloat(p.variant_price).toFixed(2)} TND</div>
                    </div>
                    <div class="selected-p-actions">
                        <button type="button" class="qty-btn" onclick="updateQty(${p.id}, -1)">-</button>
                        <div class="qty-display">${qty}</div>
                        <button type="button" class="qty-btn" onclick="updateQty(${p.id}, 1)">+</button>
                    </div>
                </div>
                `;
                
                // Add hidden inputs for form submission
                for(let i=0; i<qty; i++) {
                    inputsHtml += `<input type="hidden" name="product_ids[]" value="${p.id}">`;
                }
            }
        });
        container.innerHTML = html;
    }
    
    hiddenInputs.innerHTML = inputsHtml;
    document.getElementById("summary-qty").innerText = itemTotal;
    document.getElementById("summary-total").innerText = priceTotal.toFixed(2);
    
    updateProgress();
}

</script>
';

require $base . '/layouts/layout.php';
