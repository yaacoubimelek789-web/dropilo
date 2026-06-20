<?php
$uid = (int) $_SESSION['user_id'];

// Dropilo Export Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dropilo_export'])) {
    $orderIds = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $orderIds = array_filter($orderIds);
    if (!empty($orderIds)) {
        // Verify orders belong to user
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $st = $app->pdo->prepare("SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND o.id IN ($placeholders)");
        $st->execute(array_merge([$uid], $orderIds));
        $validIds = array_column($st->fetchAll(), 'id');
        $validIds = array_map('intval', $validIds);

        if (!empty($validIds)) {
            try {
                $tmpFile = DropiloExport::generate($app->pdo, $validIds);
                $filename = 'dropilo_export_' . date('Y-m-d_His') . '.xlsx';

                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($tmpFile));
                header('Cache-Control: no-cache, no-store, must-revalidate');
                readfile($tmpFile);

                // Cleanup temp files
                DropiloExport::cleanup($tmpFile);
                exit;
            } catch (\Throwable $e) {
                // Fall through and show error on page
                $exportError = '<div class="alert alert-error">Export failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
}

// Dropilo Import Handler
$dropiloImportMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dropilo_import'])) {
    $shopId = (int) ($_POST['shop_id'] ?? 0);
    $st = $app->pdo->prepare('SELECT id FROM shops WHERE id = ? AND user_id = ?');
    $st->execute([$shopId, $uid]);
    if (!$st->fetch()) {
        $dropiloImportMessage = '<div class="alert alert-error">Invalid shop selected.</div>';
    } else if (isset($_FILES['dropilo_file']) && $_FILES['dropilo_file']['error'] === UPLOAD_ERR_OK) {
        $uploadsDir = $base . '/public/uploads';
        $result = DropiloImport::import($app->pdo, $_FILES['dropilo_file']['tmp_name'], $shopId, $uploadsDir);

        $msgText = 'Imported ' . $result['imported'] . ' order(s) from Dropilo file.';
        if ($result['skipped'] > 0) {
            $msgText .= ' Skipped ' . $result['skipped'] . ' duplicate(s).';
        }
        if (!empty($result['errors'])) {
            $dropiloImportMessage = '<div class="alert alert-success">' . htmlspecialchars($msgText) . '</div>';
            $dropiloImportMessage .= '<div class="alert alert-error">' . implode('<br>', array_map('htmlspecialchars', $result['errors'])) . '</div>';
        } else {
            $dropiloImportMessage = '<div class="alert alert-success">' . htmlspecialchars($msgText) . '</div>';
        }

        $_SESSION['dropilo_import_message'] = $dropiloImportMessage;
        header('Location: index.php?page=orders&dropilo_imported=1');
        exit;
    } else {
        $dropiloImportMessage = '<div class="alert alert-error">Please select a valid .xlsx file.</div>';
    }
}

// Restore dropilo import message from session
if (isset($_SESSION['dropilo_import_message'])) {
    $dropiloImportMessage = $_SESSION['dropilo_import_message'];
    unset($_SESSION['dropilo_import_message']);
}

// Order upload
if (($page ?? '') === 'order-upload') {
    $currentPage = 'order-upload';
    $pageTitle = 'Order upload';
    $message = '';
    $shopId = (int) ($_POST['shop_id'] ?? $_GET['shop_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
        $shopId = (int) ($_POST['shop_id'] ?? 0);
        $st = $app->pdo->prepare('SELECT id FROM shops WHERE id = ? AND user_id = ?');
        $st->execute([$shopId, $uid]);
        if (!$st->fetch()) {
            $message = '<div class="alert alert-error">Invalid shop.</div>';
        } else {
            try {
                $path = $_FILES['csv']['tmp_name'];
                $orders = OrderImport::parse($path);
                $index = OrderProductMatch::buildProductIndex($app->pdo, $shopId);

                $orderIns = $app->pdo->prepare('
                    INSERT INTO orders (shop_id, name, order_created_at, financial_status, fulfillment_status, total, currency, shipping_method,
                    billing_name, billing_phone, billing_address, billing_city, billing_zip, billing_country,
                    shipping_name, shipping_address, shipping_city, shipping_zip, notes, phone)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $lineIns = $app->pdo->prepare('
                    INSERT INTO order_line_items (order_id, lineitem_name, lineitem_sku, lineitem_price, lineitem_quantity, vendor, fulfillment_status, product_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $checkDup = $app->pdo->prepare('SELECT id FROM orders WHERE shop_id = ? AND name = ?');
                $imported = 0;
                $skipped = 0;
                foreach ($orders as $o) {
                    // Check for duplicate: name (order ID) + shop_id - strict rejection, no modifications allowed
                    $orderName = trim($o['name'] ?? '');
                    if ($orderName === '') {
                        $skipped++;
                        continue; // Skip orders without name
                    }
                    $checkDup->execute([$shopId, $orderName]);
                    if ($checkDup->fetch()) {
                        $skipped++;
                        continue; // Reject duplicate immediately, no call to action
                    }
                    $orderIns->execute([
                        $shopId, $o['name'], $o['order_created_at'], $o['financial_status'], $o['fulfillment_status'],
                        $o['total'], $o['currency'], $o['shipping_method'],
                        $o['billing_name'], $o['billing_phone'], $o['billing_address'], $o['billing_city'], $o['billing_zip'], $o['billing_country'],
                        $o['shipping_name'], $o['shipping_address'], $o['shipping_city'], $o['shipping_zip'], $o['notes'], $o['phone'],
                    ]);
                    $orderId = (int) $app->pdo->lastInsertId();
                    foreach ($o['line_items'] as $li) {
                        $productId = OrderProductMatch::findProductId($index, $li['lineitem_name'], $li['lineitem_sku']);
                        $lineIns->execute([
                            $orderId, $li['lineitem_name'], $li['lineitem_sku'], $li['lineitem_price'], $li['lineitem_quantity'],
                            $li['vendor'], $li['fulfillment_status'], $productId,
                        ]);
                    }
                    $imported++;
                }
                $msgText = 'Imported ' . $imported . ' orders.';
                if ($skipped > 0) {
                    $msgText .= ' Skipped ' . $skipped . ' duplicate(s).';
                }
                $message = '<div class="alert alert-success">' . htmlspecialchars($msgText) . '</div>';
            } catch (Throwable $e) {
                $message = '<div class="alert alert-error">' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
    
    // Handle fix order missing info
    $missingOrders = [];
    if ($shopId > 0) {
        $st = $app->pdo->prepare('SELECT id, name, billing_name, billing_phone, shipping_address FROM orders WHERE shop_id = ? AND (billing_name IS NULL OR billing_name = "" OR billing_phone IS NULL OR billing_phone = "" OR shipping_address IS NULL OR shipping_address = "") ORDER BY name LIMIT 50');
        $st->execute([$shopId]);
        $missingOrders = $st->fetchAll();
    }
    
    // Handle fix order POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_order'])) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $billingName = trim($_POST['billing_name'] ?? '');
        $billingPhone = trim($_POST['billing_phone'] ?? '');
        $shippingAddress = trim($_POST['shipping_address'] ?? '');
        $st = $app->pdo->prepare('SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.id = ? AND s.user_id = ?');
        $st->execute([$orderId, $uid]);
        if ($st->fetch()) {
            $updateSt = $app->pdo->prepare('UPDATE orders SET billing_name = ?, billing_phone = ?, shipping_address = ? WHERE id = ?');
            $updateSt->execute([$billingName ?: null, $billingPhone ?: null, $shippingAddress ?: null, $orderId]);
            $message = '<div class="alert alert-success">Order updated successfully.</div>';
            // Refresh missing orders list
            $st = $app->pdo->prepare('SELECT id, name, billing_name, billing_phone, shipping_address FROM orders WHERE shop_id = ? AND (billing_name IS NULL OR billing_name = "" OR billing_phone IS NULL OR billing_phone = "" OR shipping_address IS NULL OR shipping_address = "") ORDER BY name LIMIT 50');
            $st->execute([$shopId]);
            $missingOrders = $st->fetchAll();
        }
    }

    $shops = $app->pdo->prepare('SELECT id, name FROM shops WHERE user_id = ? ORDER BY name');
    $shops->execute([$uid]);
    $shops = $shops->fetchAll();
    $fileIconSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>';
    $content = '<h1>Order upload</h1>' . $message;
    $content .= '<div class="card"><p style="margin-bottom: 1.5rem; color: #666;">Upload a Shopify orders export CSV. Line items will be matched to this shop\'s products by name/SKU.</p>';
    $content .= '<form method="post" enctype="multipart/form-data" class="upload-form" id="upload-form">';
    $content .= '<div class="form-group"><label>Shop</label><select name="shop_id" id="shop-select" class="form-control" required>';
    foreach ($shops as $s) {
        $sel = $s['id'] == $shopId ? ' selected' : '';
        $content .= '<option value="' . $s['id'] . '"' . $sel . '>' . htmlspecialchars($s['name']) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div class="form-group"><label>Shopify orders CSV</label>';
    $content .= '<div class="dropzone" id="dropzone" style="min-height: 120px; padding: 1.5rem;" ondrop="handleDrop(event)" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" onclick="document.getElementById(\'csv-input\').click()">';
    $content .= '<div class="dropzone-content">';
    $content .= '<div class="dropzone-icon" style="width: 48px; height: 48px; color: #666; margin: 0 auto 0.5rem;">' . $fileIconSvg . '</div>';
    $content .= '<p class="dropzone-text">Drag and drop your CSV file here</p>';
    $content .= '<input type="file" name="csv" id="csv-input" accept=".csv" style="display:none;" onchange="handleFileSelect(this)">';
    $content .= '</div>';
    $content .= '<div class="file-preview" id="file-preview" style="display:none;">';
    $content .= '<div class="file-info">';
    $content .= '<span class="file-icon" style="width: 24px; height: 24px; color: #666;">' . $fileIconSvg . '</span>';
    $content .= '<div class="file-details">';
    $content .= '<span class="file-name" id="file-name"></span>';
    $content .= '<span class="file-size" id="file-size"></span>';
    $content .= '</div>';
    $content .= '<button type="button" class="file-remove" onclick="removeFile()" title="Remove file">×</button>';
    $content .= '</div>';
    $content .= '</div></div>';
    $content .= '<div style="margin-top: 1rem; display: flex; gap: 0.75rem; justify-content: center;"><label for="csv-input" class="btn btn-browse-outside">Browse files</label>';
    $content .= '<button type="submit" class="btn" id="submit-btn" disabled>Upload orders</button></div></form>';
    
    // Filter section
    if (count($missingOrders) > 0) {
        $content .= '<div class="filter-section"><div class="filter-label">Filter</div><div class="missing-items-container">';
        foreach ($missingOrders as $mo) {
            $missingFields = [];
            if (empty($mo['billing_name'])) $missingFields[] = 'Billing Name';
            if (empty($mo['billing_phone'])) $missingFields[] = 'Phone';
            if (empty($mo['shipping_address'])) $missingFields[] = 'Shipping Address';
            $content .= '<div class="missing-item"><div class="missing-item-info"><div class="missing-item-title">' . htmlspecialchars($mo['name']) . '</div><div class="missing-item-badges">';
            foreach ($missingFields as $field) {
                $content .= '<span class="missing-badge">' . htmlspecialchars($field) . '</span>';
            }
            $content .= '</div></div><button type="button" class="btn btn-fix" data-order-id="' . $mo['id'] . '" data-order-name="' . htmlspecialchars($mo['name'], ENT_QUOTES) . '" data-billing-name="' . htmlspecialchars($mo['billing_name'] ?? '', ENT_QUOTES) . '" data-billing-phone="' . htmlspecialchars($mo['billing_phone'] ?? '', ENT_QUOTES) . '" data-shipping-address="' . htmlspecialchars($mo['shipping_address'] ?? '', ENT_QUOTES) . '" onclick="openFixSidebarOrder(this)">Fix now</button></div>';
        }
        $content .= '</div></div>';
    }
    $content .= '</div>';
    // Fix sidebar
    $editIconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    $content .= '<div class="sidebar-overlay" id="fix-overlay" onclick="closeFixSidebar()"></div><div class="fix-sidebar" id="fix-sidebar"><div class="sidebar-header-fix"><h3 style="display: flex; align-items: center; gap: 0.5rem;"><span style="width: 20px; height: 20px; color: #666;">' . $editIconSvg . '</span>Fix Order</h3><button type="button" class="sidebar-close" onclick="closeFixSidebar()">×</button></div><div class="sidebar-content"><form method="post" id="fix-order-form"><input type="hidden" name="fix_order" value="1"><input type="hidden" name="order_id" id="fix-order-id"><input type="hidden" name="shop_id" value="' . $shopId . '"><div class="form-group"><label>Order Name</label><input type="text" id="fix-order-name" readonly style="background: #f5f5f5;"></div><div class="form-group"><label>Billing Name</label><input type="text" name="billing_name" id="fix-order-billing-name" placeholder="Customer name"></div><div class="form-group"><label>Billing Phone</label><input type="tel" name="billing_phone" id="fix-order-billing-phone" placeholder="Phone number"></div><div class="form-group"><label>Shipping Address</label><input type="text" name="shipping_address" id="fix-order-shipping-address" placeholder="Street address"></div><button type="submit" class="btn">Save</button></form></div></div>';
    
    $content .= '<div id="upload-progress" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center;flex-direction:column;gap:1rem;"><div style="background:white;padding:2rem;border-radius:8px;min-width:300px;text-align:center;"><div style="font-size:1.1rem;margin-bottom:1rem;font-weight:500;">Uploading orders...</div><div style="width:100%;height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;"><div id="progress-bar" style="height:100%;background:#4CAF50;width:0%;transition:width 0.3s ease;"></div></div><div id="progress-text" style="margin-top:0.5rem;color:#666;font-size:0.9rem;">0%</div></div></div>';
    $content .= '<script>
function handleDragOver(e) { e.preventDefault(); e.stopPropagation(); e.currentTarget.classList.add("dragover"); }
function handleDragLeave(e) { e.preventDefault(); e.stopPropagation(); e.currentTarget.classList.remove("dragover"); }
function handleDrop(e) { e.preventDefault(); e.stopPropagation(); e.currentTarget.classList.remove("dragover"); var files = e.dataTransfer.files; if (files.length > 0) { handleFile(files[0]); } }
function handleFileSelect(input) { if (input.files.length > 0) { handleFile(input.files[0]); } }
function handleFile(file) {
  if (!file.name.toLowerCase().endsWith(".csv")) { alert("Please select a CSV file."); return; }
  var input = document.getElementById("csv-input");
  var dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  document.getElementById("file-name").textContent = file.name;
  document.getElementById("file-size").textContent = formatFileSize(file.size);
  document.getElementById("file-preview").style.display = "flex";
  document.querySelector(".dropzone-content").style.display = "none";
  document.getElementById("submit-btn").disabled = false;
}
function removeFile() {
  document.getElementById("csv-input").value = "";
  document.getElementById("file-preview").style.display = "none";
  document.querySelector(".dropzone-content").style.display = "flex";
  document.getElementById("submit-btn").disabled = true;
}
function formatFileSize(bytes) { if (bytes === 0) return "0 Bytes"; var k = 1024; var sizes = ["Bytes", "KB", "MB", "GB"]; var i = Math.floor(Math.log(bytes) / Math.log(k)); return Math.round(bytes / Math.pow(k, i) * 100) / 100 + " " + sizes[i]; }
function openFixSidebarOrder(btn) {
  var id = btn.getAttribute("data-order-id");
  var name = btn.getAttribute("data-order-name") || "";
  var billingName = btn.getAttribute("data-billing-name") || "";
  var billingPhone = btn.getAttribute("data-billing-phone") || "";
  var shippingAddress = btn.getAttribute("data-shipping-address") || "";
  document.getElementById("fix-order-id").value = id;
  document.getElementById("fix-order-name").value = name;
  document.getElementById("fix-order-billing-name").value = billingName;
  document.getElementById("fix-order-billing-phone").value = billingPhone;
  document.getElementById("fix-order-shipping-address").value = shippingAddress;
  document.getElementById("fix-overlay").classList.add("active");
  document.getElementById("fix-sidebar").classList.add("active");
}
function closeFixSidebar() {
  document.getElementById("fix-overlay").classList.remove("active");
  document.getElementById("fix-sidebar").classList.remove("active");
}
function showProgress() {
  var progress = document.getElementById("upload-progress");
  if (progress) {
    progress.style.display = "flex";
    var bar = document.getElementById("progress-bar");
    var text = document.getElementById("progress-text");
    var width = 0;
    var interval = setInterval(function() {
      if (width >= 90) {
        clearInterval(interval);
        return;
      }
      width += Math.random() * 15;
      if (width > 90) width = 90;
      bar.style.width = width + "%";
      text.textContent = Math.round(width) + "%";
    }, 200);
  }
}
document.getElementById("upload-form").addEventListener("submit", function(e) {
  var fileInput = document.getElementById("csv-input");
  if (!fileInput.files || fileInput.files.length === 0) {
    e.preventDefault();
    alert("Please select a CSV file to upload.");
    return false;
  }
  showProgress();
  return true;
});
</script>';
    require $base . '/layouts/layout.php';
    return;
}

// Single order view (with edit capabilities)
if (($page ?? '') === 'order-view') {
    $id = (int) ($_GET['id'] ?? 0);
    $st = $app->pdo->prepare('SELECT o.*, s.name AS shop_name, s.id AS shop_id FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.id = ? AND s.user_id = ?');
    $st->execute([$id, $uid]);
    $order = $st->fetch();
    if (!$order) {
        header('Location: index.php?page=orders');
        exit;
    }

    // Navigation Logic
    $navFrom = $_GET['from'] ?? 'orders';
    $navWhere = "WHERE s.user_id = $uid";
    if ($navFrom === 'orders-confirmed') {
        $navWhere .= " AND (o.status = 'confirmed' OR o.confirmed = 1)";
    } elseif ($navFrom === 'orders-followup') {
        $navWhere .= " AND (o.status = 'followup' OR o.follow_up = 1)";
    }

    // Previous Order (Newer - higher created_at or higher ID if same time)
    $stPrev = $app->pdo->prepare("
        SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id 
        $navWhere 
        AND (o.created_at > :created_at OR (o.created_at = :created_at AND o.id > :id))
        ORDER BY o.created_at ASC, o.id ASC LIMIT 1
    ");
    $stPrev->execute(['created_at' => $order['created_at'], 'id' => $id]);
    $prevId = $stPrev->fetchColumn();

    // Next Order (Older - lower created_at or lower ID if same time)
    $stNext = $app->pdo->prepare("
        SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id 
        $navWhere 
        AND (o.created_at < :created_at OR (o.created_at = :created_at AND o.id < :id))
        ORDER BY o.created_at DESC, o.id DESC LIMIT 1
    ");
    $stNext->execute(['created_at' => $order['created_at'], 'id' => $id]);
    $nextId = $stNext->fetchColumn();

    $message = '';
    $editMode = isset($_GET['edit']) && $_GET['edit'] === '1';
    $shopId = (int) $order['shop_id'];

    // Save customer info and total
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_customer'])) {
        $billingName = trim($_POST['billing_name'] ?? '');
        $billingPhone = trim($_POST['billing_phone'] ?? '');
        $billingAddress = trim($_POST['billing_address'] ?? '');
        $billingCity = trim($_POST['billing_city'] ?? '');
        $billingZip = trim($_POST['billing_zip'] ?? '');
        $shippingAddress = trim($_POST['shipping_address'] ?? '');
        $shippingCity = trim($_POST['shipping_city'] ?? '');
        $shippingZip = trim($_POST['shipping_zip'] ?? '');
        $total = isset($_POST['total']) && $_POST['total'] !== '' ? (float) str_replace(',', '.', $_POST['total']) : null;
        $app->pdo->prepare('UPDATE orders SET billing_name = ?, billing_phone = ?, billing_address = ?, billing_city = ?, billing_zip = ?, shipping_address = ?, shipping_city = ?, shipping_zip = ?, total = ? WHERE id = ?')
            ->execute([$billingName, $billingPhone, $billingAddress, $billingCity, $billingZip, $shippingAddress, $shippingCity, $shippingZip, $total, $id]);
        $message = '<div class="alert alert-success">Customer information and total updated.</div>';
        header('Location: index.php?page=order-view&id=' . $id . '&saved=1');
        exit;
    }

    // Save product modifications
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_products'])) {
        $removeIds = isset($_POST['remove_line_items']) && is_array($_POST['remove_line_items']) ? array_map('intval', $_POST['remove_line_items']) : [];
        $addProducts = isset($_POST['add_products']) && is_array($_POST['add_products']) ? array_map('intval', $_POST['add_products']) : [];
        $addProducts = array_filter($addProducts);
        if (!empty($removeIds)) {
            $app->pdo->prepare('DELETE FROM order_line_items WHERE id IN (' . implode(',', array_fill(0, count($removeIds), '?')) . ') AND order_id = ?')
                ->execute(array_merge($removeIds, [$id]));
        }
        if (!empty($addProducts)) {
            $existing = $app->pdo->query("SELECT product_id FROM order_line_items WHERE order_id = $id AND product_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $existing = array_map('intval', $existing);
            $addProducts = array_diff($addProducts, $existing);
            if (!empty($addProducts)) {
                $st = $app->pdo->prepare('SELECT id, title, variant_price FROM products WHERE id IN (' . implode(',', array_fill(0, count($addProducts), '?')) . ') AND shop_id = ?');
                $st->execute(array_merge(array_values($addProducts), [$shopId]));
                $prods = $st->fetchAll();
                $ins = $app->pdo->prepare('INSERT INTO order_line_items (order_id, lineitem_name, lineitem_price, lineitem_quantity, product_id) VALUES (?, ?, ?, 1, ?)');
                foreach ($prods as $p) {
                    $ins->execute([$id, $p['title'], $p['variant_price'], $p['id']]);
                }
            }
        }
        header('Location: index.php?page=order-view&id=' . $id);
        exit;
    }

    $lines = $app->pdo->prepare('SELECT li.*, p.image_src, p.title AS product_title FROM order_line_items li LEFT JOIN products p ON li.product_id = p.id WHERE li.order_id = ?');
    $lines->execute([$id]);
    $lines = $lines->fetchAll();

    // Get all products from shop for modal
    $allProducts = $app->pdo->prepare('SELECT id, title, variant_price, image_src FROM products WHERE shop_id = ? ORDER BY title');
    $allProducts->execute([$shopId]);
    $allProducts = $allProducts->fetchAll();

    // Fetch live tracking history if tracking code exists
    $trackingHistory = [];
    if (!empty($order['fiabilo_tracking_code'])) {
        $stFiab = $app->pdo->prepare('SELECT tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $stFiab->execute([$uid, 'fiabilo']);
        $intFiab = $stFiab->fetch();
        if ($intFiab && !empty($intFiab['tracking_token_encrypted'])) {
            $trackingToken = FiabiloHelper::decrypt($intFiab['tracking_token_encrypted'], $app->app['encryption_key'] ?? '');
            if ($trackingToken) {
                $statusRes = FiabiloHelper::getStatus($trackingToken, $order['fiabilo_tracking_code']);
                if (isset($statusRes['historique'])) {
                    $trackingHistory = $statusRes['historique'];
                }
            }
        }
    } elseif (!empty($order['intigo_tracking_code'])) {
        $st = $app->pdo->prepare('SELECT add_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$uid, 'intigo']);
        $int = $st->fetch();
        if ($int && !empty($int['add_token_encrypted'])) {
            $apiKey = IntigoHelper::decrypt($int['add_token_encrypted'], $app->app['encryption_key'] ?? '');
            if ($apiKey) {
                $statusRes = IntigoHelper::getTrackingStatus($order['intigo_tracking_code'], $apiKey);
                if (isset($statusRes['status'])) {
                    $trackingHistory = [['etat' => $statusRes['status'], 'date' => date('Y-m-d H:i:s')]];
                }
            }
        }
    }

    $currentPage = 'orders';
    $pageTitle = 'Order ' . htmlspecialchars($order['name']);
    
    // Read-only logic: If order has tracking code, it's with shipping and shouldn't be modified
    $isReadOnly = !empty($order['fiabilo_tracking_code']) || !empty($order['intigo_tracking_code']);

    $checkIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    $statusId = strtolower(str_replace(' ', '-', $order['status'] ?? 'new'));
    if ($statusId === 'confirmed') {
        $badgeHtml = '<div class="status-confirmed-new" style="font-size: 1rem; border: 1px solid #bbf7d0; background: #f0fdf4; padding: 0.4rem 0.8rem; border-radius: 9999px;">' . $checkIcon . ' Confirmed</div>';
    } else {
        $badgeHtml = '<div class="status-badge-header status-' . $statusId . '">' . htmlspecialchars($order['status'] ?? 'NEW') . '</div>';
    }
    if ($isReadOnly) {
        $badgeHtml .= '<div class="read-only-badge" style="margin-left:8px;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg> Read Only</div>';
    }
    $pageHeaderBadge = '<div style="display:flex; align-items:center;">' . $badgeHtml . '</div>';
    
    $backIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>';

    $content = $message;

    if ($editMode) {
        $content .= '
        <div class="order-details-grid">
        <div class="card glass order-form-card">
          <div class="card-header"><h3>Customer Information</h3></div>
          <form method="post" class="order-edit-form"><input type="hidden" name="save_customer" value="1">
            <div class="form-grid">
              <div class="form-group"><label>Billing name</label><input type="text" name="billing_name" value="' . htmlspecialchars($order['billing_name'] ?? '') . '"></div>
              <div class="form-group"><label>Billing phone</label><input type="text" name="billing_phone" value="' . htmlspecialchars($order['billing_phone'] ?? '') . '"></div>
              <div class="form-group"><label>Billing address</label><input type="text" name="billing_address" value="' . htmlspecialchars($order['billing_address'] ?? '') . '"></div>
              <div class="form-group"><label>Billing city</label><input type="text" name="billing_city" value="' . htmlspecialchars($order['billing_city'] ?? '') . '"></div>
              <div class="form-group"><label>Billing zip</label><input type="text" name="billing_zip" value="' . htmlspecialchars($order['billing_zip'] ?? '') . '"></div>
              <div class="form-group"><label>Shipping address</label><input type="text" name="shipping_address" value="' . htmlspecialchars($order['shipping_address'] ?? '') . '"></div>
              <div class="form-group"><label>Shipping city</label><input type="text" name="shipping_city" value="' . htmlspecialchars($order['shipping_city'] ?? '') . '"></div>
              <div class="form-group"><label>Shipping zip</label><input type="text" name="shipping_zip" value="' . htmlspecialchars($order['shipping_zip'] ?? '') . '"></div>
              <div class="form-group"><label>Total price</label><input type="number" step="0.01" name="total" value="' . ($order['total'] !== null ? htmlspecialchars((string)$order['total']) : '') . '" placeholder="0.00"></div>
            </div>
            <div class="order-actions-footer">
              <button type="submit" class="btn btn-save">Save Changes</button> 
              <a href="index.php?page=order-view&id=' . $id . '" class="btn btn-cancel">Cancel</a>
            </div>
          </form>
        </div></div>';
    } else {
        // Read-only check moved up

        // Back button logic
        $from = $_GET['from'] ?? 'orders';
        $backUrl = 'index.php?page=' . htmlspecialchars($from);
        $backText = $from === 'orders-confirmed' ? 'Confirmed orders' : ($from === 'orders-followup' ? 'Follow up' : 'All orders');

        // React-style Order View Redesign
        $content .= '
        <div class="order-view-container">
            <div class="order-header-modern">
                <div class="header-left">
                    <a href="' . $backUrl . '" class="btn-back-react" title="Back to ' . $backText . '"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg> Back</a>
                    
                    <div class="order-navigation-group">
                        ' . ($prevId ? '<a href="index.php?page=order-view&id=' . $prevId . '&from=' . $navFrom . '" class="nav-btn prev" title="Previous Order"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg></a>' : '<span class="nav-btn disabled"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg></span>') . '
                        
                        <div class="order-id-badge" onclick="openMagicIdModal(\'' . htmlspecialchars($order['name']) . '\')" style="cursor: pointer;" title="Click to reveal Magic ID">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg> 
                            ' . htmlspecialchars($order['name']) . '
                        </div>

                        ' . ($nextId ? '<a href="index.php?page=order-view&id=' . $nextId . '&from=' . $navFrom . '" class="nav-btn next" title="Next Order"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></a>' : '<span class="nav-btn disabled"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></span>') . '
                    </div>

                    <div class="status-badge-modern desktop-only-badge status-' . strtolower(str_replace(' ', '-', $order['status'] ?? '')) . '">' . htmlspecialchars($order['status'] ?? 'NEW') . '</div>
                    ' . ($isReadOnly ? '<div class="read-only-badge desktop-only-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg> Read Only</div>' : '') . '
                </div>
                <div class="header-right">
                    ' . (!$isReadOnly ? '
                    <button type="button" class="btn-react btn-primary" onclick="window.location.href=\'index.php?page=order-view&id=' . $id . '&edit=1\'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg> Edit Order</button>
                    <button type="button" class="btn-react btn-secondary" onclick="document.getElementById(\'product-modal\').style.display=\'flex\'"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg> Add Products</button>
                    ' : '<span style="color: #64748b; font-size: 0.85rem; font-weight: 500;">Modifications disabled for shipped order</span>') . '
                </div>
            </div>

            <div class="order-grid-modern">
                <!-- Left Column: Details -->
                <div class="grid-left">
                    <!-- Customer Card -->
                    <div class="card-modern glass">
                        <div class="card-label-row"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg> Customer Details</div>
                        <div class="customer-info-main">
                            <div class="customer-name-large">' . htmlspecialchars($order['billing_name'] ?? 'Guest Customer') . '</div>
                            <div class="customer-meta-row">
                                <a href="tel:' . htmlspecialchars($order['billing_phone'] ?? '') . '" class="meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg> ' . htmlspecialchars($order['billing_phone'] ?? 'No phone') . '</a>
                                <span class="meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> ' . date('M d, Y • H:i', strtotime($order['order_created_at'] ?? $order['created_at'])) . '</span>
                                <span class="meta-item"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg> ' . htmlspecialchars($order['shop_name']) . '</span>
                            </div>
                        </div>
                    </div>

                    <!-- Shipping Card -->
                    <div class="card-modern glass">
                        <div class="card-label-row"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg> Shipping Information</div>
                        <div class="address-box-modern">
                            <div class="address-type">Address</div>
                            <div class="address-content">' . htmlspecialchars($order['billing_address'] ?? 'No address provided') . ', ' . htmlspecialchars($order['billing_city'] ?? '') . ' ' . htmlspecialchars($order['billing_zip'] ?? '') . '</div>
                            ' . (!empty($order['shipping_address']) ? '
                            <div class="address-divider"></div>
                            <div class="address-type">Destination Address</div>
                            <div class="address-content">' . htmlspecialchars($order['shipping_address']) . ', ' . htmlspecialchars($order['shipping_city'] ?? '') . ' ' . htmlspecialchars($order['shipping_zip'] ?? '') . '</div>' : '') . '
                        </div>
                        ' . (!empty($order['notes']) ? '
                        <div class="notes-box-modern">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                            <span>' . nl2br(htmlspecialchars($order['notes'])) . '</span>
                        </div>' : '') . '
                    </div>

                    <!-- Products Card (Restored) -->
                    <div class="card-modern glass">
                        <div class="card-label-row"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg> Ordered Products</div>
                        <div class="products-list-modern">';
                        foreach ($lines as $li) {
                            $img = $li['image_src'] ? htmlspecialchars($li['image_src']) : 'https://placehold.co/40x40?text=P';
                            $content .= '
                            <div class="product-item-modern">
                                <img src="' . $img . '" class="product-thumb-modern" alt="">
                                <div class="product-details-modern">
                                    <div class="product-title-modern">' . htmlspecialchars($li['product_title'] ?? $li['lineitem_name']) . '</div>
                                    <div class="product-qty-modern">Qty: ' . (int)$li['lineitem_quantity'] . '</div>
                                </div>
                                <div class="product-price-modern">' . number_format((float)$li['lineitem_price'], 2) . ' <span class="price-curr">' . htmlspecialchars($order['currency'] ?? 'TND') . '</span></div>
                            </div>';
                        }
                        $content .= '
                        </div>
                    </div>
                </div>

                <!-- Right Column: Summary & Actions -->
                <div class="grid-right">
                    <div class="card-modern glass summary-card-modern">
                        <div class="card-label-row"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg> Order Summary</div>
                        <div class="total-section-modern">
                            <div class="total-label-modern">Total Amount</div>
                            <div class="total-value-container">
                                <input type="number" step="0.01" id="order-total" class="order-total-input-modern" value="' . ($order['total'] !== null ? htmlspecialchars((string)$order['total']) : '') . '" onchange="saveTotal()" ' . ($isReadOnly ? 'readonly disabled' : '') . '>
                                <span class="total-currency-modern">' . htmlspecialchars($order['currency'] ?? 'TND') . '</span>
                            </div>
                            ' . (!empty($order['fiabilo_tracking_code']) ? '
                            <div class="tracking-link-modern">
                                <span style="font-size:0.7rem; color:#64748b; margin-right:4px;">FIABILO:</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                                <span>' . htmlspecialchars($order['fiabilo_tracking_code']) . '</span>
                            </div>' : '') . '
                            ' . (!empty($order['intigo_tracking_code']) ? '
                            <div class="tracking-link-modern" style="color: #0ea5e9;">
                                <span style="font-size:0.7rem; color:#64748b; margin-right:4px;">INTIGO:</span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                                <span>' . htmlspecialchars($order['intigo_tracking_code']) . '</span>
                            </div>' : '') . '
                        </div>
                        <div class="actions-stack-modern">
                            ' . (!$isReadOnly ? '
                            <a href="index.php?page=orders-confirmed&mark=' . $id . '" class="btn-react-full btn-vibrant-success" ' . ( ($order['status'] === 'followup' || $order['follow_up'] == 1) ? 'onclick="return confirm(\'This order was previously marked as Follow Up. Marking it as Confirmed will categorize it as a Restored Order in your statistics. Do you want to proceed?\')"' : '') . '><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Mark as Confirmed</a>
                            <a href="index.php?page=orders-followup&mark=' . $id . '" class="btn-react-full btn-vibrant-danger"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg> Flag for Follow-up</a>
                            <button type="button" class="btn-react-full btn-vibrant-info" onclick="document.getElementById(\'product-modal\').style.display=\'flex\'"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v-6M9 17l3 3 3-3M12 4v6M15 7l-3-3-3 3"></path></svg> Modify Products</button>
                            
                            ' . (($order['status'] === 'confirmed' || $order['confirmed'] == 1) ? '
                            <div class="shipping-quick-actions" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.2);">
                                <div style="font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 0.75rem;">Send to Shipping</div>
                                <form method="post" style="display: flex; gap: 0.5rem; flex-direction: column;">
                                    <input type="hidden" name="order_ids[]" value="' . $id . '">
                                    <button type="submit" name="send_to_shipping" value="1" class="btn-react-full" style="background: #1e293b; color: white; border: none;"><input type="hidden" name="shipping" value="fiabilo">FIABILO Shipment</button>
                                    <button type="submit" name="send_to_shipping" value="1" class="btn-react-full" style="background: #0ea5e9; color: white; border: none;"><input type="hidden" name="shipping" value="intigo">INTIGO Shipment</button>
                                </form>
                            </div>' : '') . '
                            ' : '<div class="read-only-notice"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg> Order is being shipped. Interaction disabled.</div>') . '
                        </div>
                    </div>
                </div>
            </div>
            ' . (!$isReadOnly ? '
            <div class="view-footer-actions">
                <button type="button" class="btn-react-full btn-save-large" onclick="saveAllChanges()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> SAVE ORDER AMENDMENTS</button>
            </div>' : '') . '
        </div>';
    }


    // Tracking History Section
    if (!empty($trackingHistory)) {
        $content .= '
        <div class="card glass tracking-history-card">
            <div class="card-header"><h3>Tracking History</h3> <span class="badge badge-success">Live from DROPILOU</span></div>
            <div class="tracking-timeline">';
        foreach ($trackingHistory as $event) {
            $content .= '
                <div class="tracking-event">
                    <div class="event-dot"></div>
                    <div class="event-info">
                        <div class="event-status">' . htmlspecialchars($event['etat']) . '</div>
                        <div class="event-meta">
                            <span class="event-date">' . date('M d, Y - H:i', strtotime($event['date'])) . '</span>
                            ' . (!empty($event['livreur']) ? '<span class="event-sep">•</span> <span class="event-driver">Driver: ' . htmlspecialchars($event['livreur']) . '</span>' : '') . '
                        </div>
                    </div>
                </div>';
        }
        $content .= '</div></div>';
    }

    $content .= '
    <style>
    :root {
        --react-blue: #0ea5e9;
            --react-green: #22c55e;
            --react-red: #ef4444;
            --react-bg: #f8fafc;
            --card-glass: rgba(255, 255, 255, 0.8);
        }

        .order-view-container { max-width: 1200px; margin: 0 auto; color: #1e293b; }
        .order-header-modern { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
        .header-left { display: flex; align-items: center; gap: 1rem; }
        .btn-back-react { display: inline-flex; align-items: center; padding: 0.5rem 1rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 9999px; color: #64748b; font-weight: 700; font-size: 0.85rem; text-decoration: none; transition: all 0.2s; }
        .btn-back-react:hover { border-color: var(--react-blue); color: var(--react-blue); transform: translateX(-4px); }
        .order-navigation-group { display: flex; align-items: center; gap: 0.5rem; background: #fff; border: 1px solid #e2e8f0; padding: 0.25rem; border-radius: 9999px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .nav-btn { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 50%; color: #64748b; transition: all 0.2s; text-decoration: none; border: 1px solid transparent; }
        .nav-btn:hover:not(.disabled) { background: #f1f5f9; color: var(--react-blue); border-color: #e2e8f0; transform: scale(1.05); }
        .nav-btn.disabled { opacity: 0.3; cursor: not-allowed; }
        
        .order-id-badge { background: #334155; color: #fff; padding: 0.5rem 1.25rem; border-radius: 9999px; font-weight: 800; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1); }
        .read-only-badge { background: #e2e8f0; color: #475569; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 0.375rem; }
        .read-only-notice { padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 1rem; color: #64748b; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; text-align: center; }
        .status-badge-modern { padding: 0.5rem 1rem; border-radius: 9999px; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; }
        .status-confirmed, .status-confirm\33   { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-followup { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .status-pending { background: #fef9c3; color: #854d0e; border: 1px solid #fef08a; }
        .status-new { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

        @media (max-width: 768px) {
            .desktop-only-badge { display: none !important; }
        }

        .order-grid-modern { display: grid; grid-template-columns: 1.5fr 1fr; gap: 2rem; }
        @media (max-width: 992px) { .order-grid-modern { grid-template-columns: 1fr; } }

        .card-modern { border-radius: 1.25rem; border: 1px solid #e2e8f0; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); transition: transform 0.2s ease; }
        .card-modern:hover { transform: translateY(-2px); }
        .glass { background: var(--card-glass); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }

        .card-label-row { display: flex; align-items: center; gap: 0.5rem; font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1.25rem; }
        .customer-name-large { font-size: 1.75rem; font-weight: 900; color: #0f172a; margin-bottom: 0.75rem; letter-spacing: -0.02em; }
        .customer-meta-row { display: flex; flex-wrap: wrap; gap: 1rem; }
        .meta-item { display: flex; align-items: center; gap: 0.375rem; color: #64748b; font-size: 0.875rem; font-weight: 500; text-decoration: none; }
        .meta-item:hover { color: var(--react-blue); }

        .address-box-modern { background: #f1f5f9; border-radius: 0.75rem; padding: 1.25rem; }
        .address-type { font-size: 0.7rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin-bottom: 0.25rem; }
        .address-content { font-size: 0.95rem; font-weight: 600; color: #334155; line-height: 1.5; }
        .address-divider { height: 1px; background: #e2e8f0; margin: 1rem 0; }

        .notes-box-modern { display: flex; gap: 0.75rem; margin-top: 1.25rem; padding: 1rem; background: #fffbe2; border: 1px solid #fef08a; border-radius: 0.75rem; color: #854d0e; font-size: 0.9rem; font-weight: 500; }

        .products-list-modern { display: flex; flex-direction: column; gap: 0.75rem; }
        .product-item-modern { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: #fff; border: 1px solid #f1f5f9; border-radius: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .product-thumb-modern { width: 44px; height: 44px; border-radius: 0.5rem; object-fit: cover; background: #f8fafc; }
        .product-details-modern { flex: 1; }
        .product-title-modern { font-weight: 700; color: #1e293b; font-size: 0.95rem; margin-bottom: 0.125rem; }
        .product-qty-modern { font-size: 0.8rem; color: #94a3b8; font-weight: 600; }
        .product-price-modern { font-weight: 800; color: #0f172a; font-size: 1.1rem; }
        .price-curr { font-size: 0.7rem; color: #94a3b8; font-weight: 700; }

        .total-section-modern { background: #0f172a; color: #fff; border-radius: 1.25rem; padding: 1.75rem; margin-bottom: 1.5rem; }
        .total-label-modern { font-size: 0.8rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 0.5rem; }
        .total-value-container { display: flex; align-items: baseline; gap: 0.5rem; }
        .order-total-input-modern { background: transparent; border: none; font-size: 2.25rem; font-weight: 900; color: #fff; width: 100%; border-bottom: 2px solid #334155; transition: border-color 0.2s; }
        .order-total-input-modern:focus { border-color: var(--react-blue); outline: none; }
        .total-currency-modern { font-size: 1.25rem; font-weight: 700; color: #64748b; }
        .tracking-link-modern { display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem; color: var(--react-blue); font-weight: 700; font-size: 0.9rem; }

        .actions-stack-modern { display: flex; flex-direction: column; gap: 1rem; }
        .btn-react { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; border-radius: 0.75rem; font-weight: 700; transition: all 0.2s; cursor: pointer; border: none; font-size: 0.9rem; }
        .btn-react-full { display: flex; align-items: center; justify-content: center; gap: 0.75rem; padding: 1rem; border-radius: 1rem; font-weight: 800; transition: all 0.2s; cursor: pointer; text-decoration: none; border: none; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.02em; }
        
        .btn-primary { background: var(--react-blue); color: #fff; box-shadow: 0 4px 6px -1px rgba(14, 165, 233, 0.3); }
        .btn-secondary { background: #fff; color: #1e293b; border: 1px solid #e2e8f0; }
        .btn-vibrant-success { background: var(--react-green); color: #fff; box-shadow: 0 10px 15px -3px rgba(34, 197, 94, 0.4); }
        .btn-vibrant-danger { background: var(--react-red); color: #fff; box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.4); }
        .btn-vibrant-info { background: #334155; color: #fff; box-shadow: 0 10px 15px -3px rgba(51, 65, 85, 0.4); }

        .btn-react:hover, .btn-react-full:hover { filter: brightness(1.1); transform: translateY(-2px); }
        .btn-react:active, .btn-react-full:active { transform: scale(0.98); }

        .tracking-history-card { margin-top: 1rem; }
        .tracking-timeline { padding: 1.5rem; position: relative; }
        .tracking-timeline::before { content: ""; position: absolute; left: 1.875rem; top: 2rem; bottom: 2rem; width: 1px; background: #e2e8f0; }
        .tracking-event { display: flex; align-items: flex-start; gap: 1.5rem; margin-bottom: 2rem; position: relative; }
        .tracking-event:last-child { margin-bottom: 0; }
        .event-dot { width: 12px; height: 12px; border-radius: 50%; background: #cbd5e1; border: 3px solid #fff; box-shadow: 0 0 0 4px #f8fafc; z-index: 1; margin-top: 0.25rem; }
        .tracking-event:first-child .event-dot { background: var(--react-green); box-shadow: 0 0 0 4px #d1fae5; }
        .event-status { font-weight: 800; font-size: 1rem; color: #1e293b; margin-bottom: 0.125rem; }
        .event-meta { font-size: 0.8rem; color: #94a3b8; display: flex; align-items: center; gap: 0.5rem; font-weight: 600; }
        .event-driver { color: var(--react-blue); padding: 2px 8px; background: #e0f2fe; border-radius: 4px; }

        /* Snackbar Styles */
        .snackbar { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; padding: 1rem 1.5rem; border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); z-index: 2000; border: 1px solid rgba(255,255,255,0.1); animation: slideUp 0.3s ease-out; }
        .snackbar-content { display: flex; align-items: center; gap: 1.5rem; }
        .snackbar-content span { font-weight: 600; font-size: 0.95rem; }
        .snackbar-actions { display: flex; gap: 0.75rem; }
        .snackbar-actions .btn { padding: 0.5rem 1rem; border-radius: 0.5rem; font-weight: 700; cursor: pointer; border: none; font-size: 0.85rem; }
        .snackbar-actions .btn:first-child { background: var(--react-blue); color: #fff; }
        .snackbar-actions .btn-secondary { background: #334155; color: #fff; }
        
        @keyframes slideUp { from { bottom: -5rem; opacity: 0; } to { bottom: 2rem; opacity: 1; } }
        .shake { animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
        @keyframes shake { 10%, 90% { transform: translate3d(-50%, 0, 0) translateX(-1px); } 20%, 80% { transform: translate3d(-50%, 0, 0) translateX(2px); } 30%, 50%, 70% { transform: translate3d(-50%, 0, 0) translateX(-4px); } 40%, 60% { transform: translate3d(-50%, 0, 0) translateX(4px); } }
        
        .view-footer-actions { margin-top: 2rem; display: flex; justify-content: center; }
        .btn-save-large { max-width: 400px; background: #0f172a; color: #fff; padding: 1.25rem 2rem; border-radius: 1.25rem; font-size: 1.1rem; box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.3); }
        .btn-save-large:hover { background: #1e293b; }

        /* Smooth Success Toast */
        .toast-success { position: fixed; top: 2rem; right: 2rem; background: #10b981; color: #fff; padding: 1rem 1.5rem; border-radius: 1rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); z-index: 3000; display: flex; align-items: center; gap: 0.75rem; font-weight: 700; transform: translateX(120%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .toast-success.active { transform: translateX(0); }

    .magic-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.9); z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.3s; backdrop-filter: blur(8px); }
    .magic-modal.active { opacity: 1; pointer-events: auto; }
    .magic-content { display: flex; flex-direction: column; align-items: center; transform: scale(0.9); transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .magic-modal.active .magic-content { transform: scale(1); }
    
    .magic-icon-container { width: 80px; height: 80px; background: linear-gradient(135deg, #6366f1, #a855f7); border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 0 30px rgba(99, 102, 241, 0.5); margin-bottom: 1.5rem; animation: pulse-glow 2s infinite; }
    
    .magic-label { font-size: 1rem; font-weight: 800; color: #94a3b8; letter-spacing: 0.2em; text-transform: uppercase; margin-bottom: 1.5rem; }
    
    .magic-slots { display: flex; gap: 0.5rem; background: #1e293b; padding: 1.5rem 2rem; border-radius: 1rem; border: 1px solid #334155; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); }
    
    .slot-digit { width: 40px; height: 60px; background: #0f172a; border-radius: 0.5rem; overflow: hidden; position: relative; border: 1px solid #334155; box-shadow: inset 0 2px 4px rgba(0,0,0,0.5); }
    .slot-strip { position: absolute; top: 0; left: 0; width: 100%; display: flex; flex-direction: column; align-items: center; will-change: transform; }
    .slot-num { height: 60px; width: 100%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 900; color: #fff; font-family: \'Outfit\', sans-serif; text-shadow: 0 2px 10px rgba(255,255,255,0.2); }
    
    @keyframes pulse-glow { 0% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4); } 70% { box-shadow: 0 0 0 20px rgba(99, 102, 241, 0); } 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0); } }
    </style>
    ';
    
    $content .= '<script>
    function openMagicIdModal(orderId) {
        var modal = document.getElementById("magic-id-modal");
        var container = document.getElementById("magic-slots-container");
        container.innerHTML = "";
        modal.classList.add("active");
        
        // Remove "#" if present
        var cleanId = orderId.replace("#", "");
        var digits = cleanId.split("");
        
        // Create slots
        digits.forEach(function(d, i) {
            var slot = document.createElement("div");
            slot.className = "slot-digit";
            var strip = document.createElement("div");
            strip.className = "slot-strip";
            
            // Create strip content: 0-9 repeated
            var content = "";
            for(var r=0; r<3; r++) { 
                for(var n=0; n<=9; n++) {
                    content += "<div class=\"slot-num\">" + n + "</div>";
                }
            }
            // Add final target digit 
            content += "<div class=\"slot-num\">" + d + "</div>";
            
            strip.innerHTML = content;
            slot.appendChild(strip);
            container.appendChild(slot);
            
            // Animate
            setTimeout(function() {
                var laps = 2 + Math.floor(Math.random() * 2); 
                var targetIndex = (laps * 10) + parseInt(d); 
                
                // Re-construct strip for precise control
                var stripHtml = "";
                // Fill randoms
                for(var k=0; k<targetIndex; k++) {
                    stripHtml += "<div class=\"slot-num\">" + (k % 10) + "</div>";
                }
                stripHtml += "<div class=\"slot-num\">" + d + "</div>";
                strip.innerHTML = stripHtml;
                
                // Trigger reflow
                strip.offsetHeight; 
                
                strip.style.transition = "transform " + (1.5 + (i * 0.3)) + "s cubic-bezier(0.1, 0.9, 0.2, 1)";
                strip.style.transform = "translateY(-" + (targetIndex * 60) + "px)";
                
            }, 100);
        });
        
        // Close on click
        modal.onclick = function() {
            modal.classList.remove("active");
        };
    }
    </script>';

    if (isset($_GET['saved'])) {
        $content .= '<div id="success-toast" class="toast-success"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Order saved successfully!</div>
        <script>setTimeout(function() { document.getElementById("success-toast").classList.add("active"); }, 100); setTimeout(function() { document.getElementById("success-toast").classList.remove("active"); }, 4000);</script>';
    }

    $currentProductIds = array_filter(array_column($lines, 'product_id'));
    $content .= '
    <div id="product-modal" class="modal-modern" style="display:none;">
        <div class="modal-content-modern glass">
            <div class="modal-header-modern">
                <h3><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg> Modify order products</h3>
                <button type="button" class="close-modal-btn" onclick="closeProductModal()">&times;</button>
            </div>
            <form method="post" id="product-form" class="modal-form-modern">
                <input type="hidden" name="save_products" value="1">
                <div class="search-container-modern">
                    <label><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg> Search products</label>
                    <input type="text" id="product-search" placeholder="Search catalog..." onkeyup="filterProducts()">
                </div>
                
                <div class="modal-body-grid">
                    <div class="modal-grid-col">
                        <h4>Current products <small>(check to remove)</small></h4>
                        <div id="current-products" class="product-selection-list">';
                        $hasCurrent = false;
                        foreach ($lines as $li) {
                            if ($li['product_id']) {
                                $hasCurrent = true;
                                $img = $li['image_src'] ? '<img src="' . htmlspecialchars($li['image_src']) . '" alt="" class="modal-prod-img">' : '<div class="modal-prod-placeholder">P</div>';
                                $price = $li['lineitem_price'] !== null ? (float)$li['lineitem_price'] : 0;
                                $content .= '
                                <label class="selection-item">
                                    <input type="checkbox" name="remove_line_items[]" value="' . $li['id'] . '" class="remove-checkbox" data-price="' . $price . '" onchange="calculateTotal()">
                                    ' . $img . '
                                    <div class="selection-details">
                                        <div class="selection-title">' . htmlspecialchars($li['lineitem_name']) . '</div>
                                        <div class="selection-price">' . number_format($price, 2) . ' TND</div>
                                    </div>
                                </label>';
                            }
                        }
                        if (!$hasCurrent) {
                            $content .= '<p class="empty-selection">No products from catalog in this order.</p>';
                        }
                        $content .= '
                        </div>
                    </div>
                    
                    <div class="modal-grid-col">
                        <h4>All products <small>(select to add)</small></h4>
                        <div id="all-products" class="product-selection-list">';
                        foreach ($allProducts as $p) {
                            $checked = in_array((int)$p['id'], $currentProductIds, true) ? ' checked disabled' : '';
                            $img = $p['image_src'] ? '<img src="' . htmlspecialchars($p['image_src']) . '" alt="" class="modal-prod-img">' : '<div class="modal-prod-placeholder">P</div>';
                            $price = $p['variant_price'] !== null ? (float)$p['variant_price'] : 0;
                            $content .= '
                            <label class="selection-item' . ($checked ? ' is-disabled' : '') . '">
                                <input type="checkbox" name="add_products[]" value="' . $p['id'] . '" class="add-checkbox" data-price="' . $price . '"' . $checked . ($checked ? '' : ' onchange="calculateTotal()"') . '>
                                ' . $img . '
                                <div class="selection-details">
                                    <div class="selection-title">' . htmlspecialchars($p['title']) . '</div>
                                    <div class="selection-price">' . number_format($price, 2) . ' TND</div>
                                </div>
                            </label>';
                        }
                        $content .= '
                        </div>
                    </div>
                </div>

                <div class="modal-footer-modern">
                    <button type="button" onclick="closeProductModal()" class="btn-react btn-secondary">Discard</button>
                    <button type="submit" class="btn-react btn-primary"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg> Save changes</button>
                </div>
            </form>
        </div>
    </div>';

    // Magic ID Modal
    $content .= '<div id="magic-id-modal" class="magic-modal">
        <div class="magic-content">
            <div class="magic-icon-container">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            </div>
            <div class="magic-label">ORDER ID</div>
            <div id="magic-slots-container" class="magic-slots"></div>
        </div>
    </div>';

    $content .= '
    <style>
    .modal-modern { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); z-index: 1000; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .modal-content-modern { width: 90%; max-width: 900px; max-height: 90vh; border-radius: 1.5rem; overflow: hidden; display: flex; flex-direction: column; border: 1px solid rgba(255,255,255,0.2); }
    .modal-header-modern { padding: 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; background: #fff; }
    .modal-header-modern h3 { margin: 0; font-size: 1.25rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; }
    .close-modal-btn { background: none; border: none; font-size: 1.5rem; color: #64748b; cursor: pointer; padding: 0.5rem; line-height: 1; }
    
    .modal-form-modern { display: flex; flex-direction: column; height: 100%; overflow: hidden; background: #f8fafc; }
    .search-container-modern { padding: 1rem 1.5rem; background: #fff; border-bottom: 1px solid #e2e8f0; }
    .search-container-modern label { display: flex; align-items: center; gap: 0.5rem; font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 0.5rem; }
    .search-container-modern input { width: 100%; padding: 0.75rem 1rem; border-radius: 0.75rem; border: 1px solid #e2e8f0; font-size: 0.95rem; font-weight: 600; transition: all 0.2s; }
    .search-container-modern input:focus { outline: none; border-color: var(--react-blue); box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1); }
    
    .modal-body-grid { display: grid; grid-template-columns: 1fr 1.2fr; gap: 1.5rem; padding: 1.5rem; overflow-y: auto; flex: 1; }
    @media (max-width: 768px) { .modal-body-grid { grid-template-columns: 1fr; } }
    
    .modal-grid-col h4 { margin: 0 0 1rem 0; font-size: 0.85rem; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: baseline; gap: 0.5rem; }
    .modal-grid-col h4 small { color: #94a3b8; font-weight: 600; text-transform: none; }
    
    .product-selection-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .selection-item { display: flex; align-items: center; gap: 1rem; padding: 0.75rem; background: #fff; border: 1px solid #e2e8f0; border-radius: 0.75rem; cursor: pointer; transition: all 0.2s; }
    .selection-item:hover { border-color: var(--react-blue); background: #f0f9ff; }
    .selection-item.is-disabled { opacity: 0.6; cursor: not-allowed; background: #f1f5f9; }
    .selection-item input[type="checkbox"] { width: 18px; height: 18px; border-radius: 4px; border: 2px solid #cbd5e1; cursor: pointer; }
    
    .modal-prod-img { width: 40px; height: 40px; border-radius: 0.5rem; object-fit: cover; }
    .modal-prod-placeholder { width: 40px; height: 40px; border-radius: 0.5rem; background: #e2e8f0; color: #94a3b8; display: flex; align-items: center; justify-content: center; font-weight: 800; }
    .selection-details { flex: 1; }
    .selection-title { font-size: 0.85rem; font-weight: 700; color: #334155; line-height: 1.3; }
    .selection-price { font-size: 0.75rem; font-weight: 600; color: #64748b; margin-top: 0.125rem; }
    
    .empty-selection { font-size: 0.85rem; color: #94a3b8; font-style: italic; background: #fff; padding: 1rem; border-radius: 0.75rem; border: 1px dashed #cbd5e1; text-align: center; }
    
    .modal-footer-modern { padding: 1.25rem 1.5rem; background: #fff; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 1rem; }
    </style>
    ';

    // Total update form
    $content .= '<form id="total-form" method="post" style="display:none;"><input type="hidden" name="save_customer" value="1">';
    $content .= '<input type="hidden" name="billing_name" id="hf_billing_name" value="' . htmlspecialchars($order['billing_name'] ?? '') . '">';
    $content .= '<input type="hidden" name="billing_phone" id="hf_billing_phone" value="' . htmlspecialchars($order['billing_phone'] ?? '') . '">';
    $content .= '<input type="hidden" name="billing_address" id="hf_billing_address" value="' . htmlspecialchars($order['billing_address'] ?? '') . '">';
    $content .= '<input type="hidden" name="billing_city" id="hf_billing_city" value="' . htmlspecialchars($order['billing_city'] ?? '') . '">';
    $content .= '<input type="hidden" name="billing_zip" id="hf_billing_zip" value="' . htmlspecialchars($order['billing_zip'] ?? '') . '">';
    $content .= '<input type="hidden" name="shipping_address" id="hf_shipping_address" value="' . htmlspecialchars($order['shipping_address'] ?? '') . '">';
    $content .= '<input type="hidden" name="shipping_city" id="hf_shipping_city" value="' . htmlspecialchars($order['shipping_city'] ?? '') . '">';
    $content .= '<input type="hidden" name="shipping_zip" id="hf_shipping_zip" value="' . htmlspecialchars($order['shipping_zip'] ?? '') . '">';
    $content .= '<input type="hidden" name="total" id="hf_total"></form>';
    
    // Snackbar for unsaved changes
    $content .= '<div id="unsaved-snackbar" class="snackbar" style="display:none;"><div class="snackbar-content"><span>You have unsaved changes</span><div class="snackbar-actions"><button onclick="saveAllChanges()" class="btn">Save</button><button onclick="discardChanges()" class="btn btn-secondary">Discard</button></div></div></div>';
    
    // Calculate initial total from line items
    $initialTotal = 0;
    foreach ($lines as $li) {
        if ($li['lineitem_price'] !== null) {
            $initialTotal += (float)$li['lineitem_price'] * (int)$li['lineitem_quantity'];
        }
    }
    
    $content .= '<script>
var hasUnsavedChanges = false;
var manualTotalEdit = false;
var initialTotal = ' . ($order['total'] !== null ? (float)$order['total'] : $initialTotal) . ';
var currentLineItems = ' . json_encode(array_map(function($li) {
    return ['id' => (int)$li['id'], 'price' => $li['lineitem_price'] !== null ? (float)$li['lineitem_price'] : 0, 'qty' => (int)$li['lineitem_quantity']];
}, $lines)) . ';

function calculateTotal() {
    if (manualTotalEdit) return;
    var total = 0;
    // Subtract removed items
    document.querySelectorAll(".remove-checkbox:checked").forEach(function(cb) {
        var price = parseFloat(cb.getAttribute("data-price")) || 0;
        total -= price;
    });
    // Add new items
    document.querySelectorAll(".add-checkbox:checked:not([disabled])").forEach(function(cb) {
        var price = parseFloat(cb.getAttribute("data-price")) || 0;
        total += price;
    });
    // Add current line items (not being removed)
    currentLineItems.forEach(function(item) {
        var isRemoved = document.querySelector(\'input[name="remove_line_items[]"][value="\' + item.id + \'"]:checked\');
        if (!isRemoved) {
            total += item.price * item.qty;
        }
    });
    var totalInput = document.getElementById("order-total");
    if (totalInput) {
        totalInput.value = total.toFixed(2);
        markUnsaved();
    }
}

function markUnsaved() {
    hasUnsavedChanges = true;
    var btn = document.getElementById("manual-save-btn");
    if (btn) btn.style.display = "flex";
}

function saveAllChanges() {
    hasUnsavedChanges = false;
    document.getElementById("total-form").submit();
}

function discardChanges() {
    hasUnsavedChanges = false;
    window.location.href = window.location.href.split("&")[0].split("?")[0] + "?page=order-view&id=' . $id . '";
}

function saveTotal() {
    manualTotalEdit = true;
    var t = document.getElementById("order-total").value;
    document.getElementById("hf_total").value = t || "";
    markUnsaved();
}

function openProductModal() {
    document.getElementById("product-modal").style.display = "flex";
}

function closeProductModal() {
    document.getElementById("product-modal").style.display = "none";
}

function filterProducts() {
    var s = document.getElementById("product-search").value.toLowerCase();
    document.querySelectorAll(".selection-item").forEach(function(e) {
        var t = e.querySelector(".selection-title").textContent.toLowerCase();
        e.style.display = t.includes(s) ? "flex" : "none";
    });
}

function showUnsavedSnackbar() {
    var snackbar = document.getElementById("unsaved-snackbar");
    snackbar.style.display = "flex";
    snackbar.classList.add("shake");
    setTimeout(function() { snackbar.classList.remove("shake"); }, 500);
}

function checkUnsaved(e) {
    if (hasUnsavedChanges) {
        e.preventDefault();
        var targetHref = e.currentTarget.href;
        showUnsavedSnackbar();
        
        // Update snackbar actions to handle navigation
        var snackbar = document.getElementById("unsaved-snackbar");
        var discardBtn = snackbar.querySelector(".btn-secondary");
        discardBtn.onclick = function() {
            hasUnsavedChanges = false;
            window.location.href = targetHref;
        };
        return false;
    }
}

// Track changes
document.addEventListener("DOMContentLoaded", function() {
    var totalInput = document.getElementById("order-total");
    if (totalInput) {
        totalInput.addEventListener("input", function() {
            if (this.value !== initialTotal.toFixed(2)) {
                markUnsaved();
            }
        });
    }
    
    // Track edit mode form changes
    var editForm = document.querySelector("form[method=\'post\']");
    if (editForm && editForm.querySelector("input[name=\'billing_name\']")) {
        editForm.addEventListener("input", markUnsaved);
    }
    
    // Intercept navigation
    document.querySelectorAll("a").forEach(function(link) {
        if (link.href && !link.href.includes("#") && link.href !== "javascript:void(0)") {
            link.addEventListener("click", checkUnsaved);
        }
    });
    
    // Before unload listener removed to prevent intrusive browser alerts
});

window.onclick = function(e) {
    if (e.target.id === "product-modal") closeProductModal();
};
</script>';
    require $base . '/layouts/layout.php';
    return;
}

// Toggle confirmed / follow-up (e.g. ?page=orders-confirmed&mark=123)
$mark = (int) ($_GET['mark'] ?? 0);
if ($mark > 0 && (($page ?? '') === 'orders-confirmed' || ($page ?? '') === 'orders-followup')) {
    $st = $app->pdo->prepare('SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.id = ? AND s.user_id = ?');
    $st->execute([$mark, $uid]);
    if ($st->fetch()) {
        $col = ($page ?? '') === 'orders-confirmed' ? 'confirmed' : 'follow_up';
        $newStatus = ($page ?? '') === 'orders-confirmed' ? 'confirmed' : 'followup';
        // Update both new status column and legacy flags, and set confirmed_at
        if (($page ?? '') === 'orders-confirmed') {
            $app->pdo->prepare("UPDATE orders SET confirmed = 1, follow_up = 0, status = ?, confirmed_at = NOW() WHERE id = ?")->execute([$newStatus, $mark]);
        } else {
            $app->pdo->prepare("UPDATE orders SET follow_up = 1, confirmed = 0, status = ?, confirmed_at = NULL, followup_at = NOW() WHERE id = ?")->execute([$newStatus, $mark]);
        }
    }
    header('Location: index.php?page=order-view&id=' . $mark);
    exit;
}

// Bulk send to shipping (Triggered from either confirmed orders toolbar or general bulk bar)
$triggerBulkShipping = isset($_POST['send_to_shipping']) || (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'shipping');

if ($triggerBulkShipping && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderIds = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $orderIds = array_filter($orderIds);
    $shippingProvider = trim($_POST['shipping'] ?? 'fiabilo'); 
    
    if ($shippingProvider === 'fiabilo' && !empty($orderIds)) {
        $st = $app->pdo->prepare('SELECT add_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$uid, 'fiabilo']);
        $int = $st->fetch();
        if (!$int || empty($int['add_token_encrypted'])) {
            $sendMessage = '<div class="alert alert-error">Connect FIABILO in Integration first.</div>';
        } else {
            $addToken = FiabiloHelper::decrypt($int['add_token_encrypted'], $app->app['encryption_key'] ?? '');
            if ($addToken === '') {
                $sendMessage = '<div class="alert alert-error">Could not read FIABILO token.</div>';
            } else {
                $ok = 0;
                $errs = [];
                foreach ($orderIds as $oid) {
                    $st = $app->pdo->prepare('SELECT o.* FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.id = ? AND s.user_id = ?');
                    $st->execute([$oid, $uid]);
                    $order = $st->fetch();
                    if (!$order || !empty($order['fiabilo_tracking_code'])) {
                        if (!empty($order['fiabilo_tracking_code'])) $errs[] = $order['name'] . ' already sent';
                        continue;
                    }
                    $lines = $app->pdo->query("SELECT lineitem_name, lineitem_quantity FROM order_line_items WHERE order_id = " . (int)$oid)->fetchAll();
                    $designation = [];
                    $nb_article = 0;
                    foreach ($lines as $l) {
                        $q = (int) $l['lineitem_quantity'];
                        $nb_article += $q;
                        $designation[] = trim($l['lineitem_name']) . ' (x' . $q . ')';
                    }
                    $order['designation'] = implode(', ', $designation) ?: 'Order';
                    $order['nb_article'] = $nb_article ?: 1;
                    $order['ouvrir'] = isset($_POST['post_open_package_permission']) ? (int)$_POST['post_open_package_permission'] : 0;
                    $result = FiabiloHelper::sendOrder($addToken, $order);
                    if (isset($result['tracking_code'])) {
                        $app->pdo->prepare('UPDATE orders SET fiabilo_tracking_code = ?, fiabilo_status = ?, status = ?, fiabilo_sent_at = NOW(), shipped_at = NOW() WHERE id = ?')->execute([$result['tracking_code'], 'En attente', 'shipping', $oid]);
                        $ok++;
                    } else {
                        $errs[] = $order['name'] . ': ' . ($result['error'] ?? 'Failed');
                    }
                }
                $sendMessage = $ok > 0 ? '<div class="alert alert-success">Assigned ' . $ok . ' order(s) to FIABILO.</div>' : '';
                if (!empty($errs)) $sendMessage .= '<div class="alert alert-error">' . implode('<br>', array_map('htmlspecialchars', $errs)) . '</div>';
            }
        }
    }
    // Intigo Support
    elseif ($shippingProvider === 'intigo' && !empty($orderIds)) {
        $st = $app->pdo->prepare('SELECT add_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
        $st->execute([$uid, 'intigo']);
        $int = $st->fetch();
        if (!$int || empty($int['add_token_encrypted'])) {
            $sendMessage = '<div class="alert alert-error">Connect INTIGO in Integration first.</div>';
        } else {
            $apiKey = IntigoHelper::decrypt($int['add_token_encrypted'], $app->app['encryption_key'] ?? '');
            if ($apiKey === '') {
                $sendMessage = '<div class="alert alert-error">Could not read INTIGO API key.</div>';
            } else {
                $ok = 0;
                $errs = [];
                foreach ($orderIds as $oid) {
                    $st = $app->pdo->prepare('SELECT o.* FROM orders o JOIN shops s ON o.shop_id = s.id WHERE o.id = ? AND s.user_id = ?');
                    $st->execute([$oid, $uid]);
                    $order = $st->fetch();
                    if (!$order || !empty($order['intigo_tracking_code'])) {
                        if (!empty($order['intigo_tracking_code'])) $errs[] = $order['name'] . ' already sent to Intigo';
                        continue;
                    }
                    
                    // Ensure cid is between 5 and 20 characters
                    $cid = (string) $order['name'];
                    if (strlen($cid) < 5) $cid = 'ORD-' . str_pad($cid, 2, '0', STR_PAD_LEFT);
                    if (strlen($cid) > 20) $cid = substr($cid, 0, 20);

                    // Prepare Intigo payload
                    $payload = [
                        'cid' => $cid,
                        'name' => $order['billing_name'] ?? $order['shipping_name'] ?? 'Customer',
                        'phone' => substr(preg_replace('/\D/', '', $order['billing_phone'] ?? $order['phone'] ?? ''), -8), // Must be 8 digits
                        'amount' => (float) ($order['total'] ?? 0),
                        'city' => $order['billing_city'] ?? $order['shipping_city'] ?? '',
                        'subDivision' => $order['billing_zip'] ?? '', 
                        'address' => $order['billing_address'] ?? $order['shipping_address'] ?? '',
                        'pickUpAddress' => 'Default Shop Address', 
                        'pickUpCity' => 'Tunis', 
                        'pickUpSubDivision' => 'Tunis', 
                        'size' => 1, // Default Normal
                    ];
                    
                    $merchantId = IntigoHelper::decrypt($int['tracking_token_encrypted'], $encryptionKey);
                    $isSandbox = ($int['api_mode'] ?? 'prod') === 'sandbox';
                    $result = IntigoHelper::addOrder($payload, $apiKey, $merchantId, $isSandbox);
                    if (isset($result['nid'])) {
                        $defaultStatus = IntigoHelper::getStatusLabel(IntigoHelper::STATUS_ASSIGNED);
                        $app->pdo->prepare('UPDATE orders SET intigo_tracking_code = ?, intigo_status = ?, status = ?, intigo_sent_at = NOW(), shipped_at = NOW() WHERE id = ?')->execute([$result['nid'], $defaultStatus, 'shipping', $oid]);
                        $ok++;
                    } else {
                        $errs[] = $order['name'] . ': ' . ($result['error'] ?? ($result['message'] ?? 'Failed'));
                    }
                }
                $sendMessage = $ok > 0 ? '<div class="alert alert-success">Assigned ' . $ok . ' order(s) to INTIGO.</div>' : '';
                if (!empty($errs)) $sendMessage .= '<div class="alert alert-error">' . implode('<br>', array_map('htmlspecialchars', $errs)) . '</div>';
            }
        }
    }
}

// Handle bulk status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] !== 'shipping' && $_POST['bulk_action'] !== 'delete') {
    $orderIds = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $orderIds = array_filter($orderIds);
    $action = $_POST['bulk_action'] ?? '';
    $validActions = ['confirm' => 'confirmed', 'followup' => 'followup'];
    if (!empty($orderIds) && isset($validActions[$action])) {
        $newStatus = $validActions[$action];
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        // Verify orders belong to user
        $st = $app->pdo->prepare("SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND o.id IN ($placeholders)");
        $st->execute(array_merge([$uid], $orderIds));
        $validIds = array_column($st->fetchAll(), 'id');
        if (!empty($validIds)) {
            $updatePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
            // Update status column
            $app->pdo->prepare("UPDATE orders SET status = ? WHERE id IN ($updatePlaceholders)")->execute(array_merge([$newStatus], $validIds));
            // Also update legacy flags for backward compatibility
            if ($newStatus === 'confirmed') {
                $app->pdo->prepare("UPDATE orders SET confirmed = 1, follow_up = 0, confirmed_at = NOW() WHERE id IN ($updatePlaceholders)")->execute($validIds);
            } elseif ($newStatus === 'followup') {
                $app->pdo->prepare("UPDATE orders SET follow_up = 1, confirmed = 0, confirmed_at = NULL, followup_at = NOW() WHERE id IN ($updatePlaceholders)")->execute($validIds);
            }
            $bulkMessage = '<div class="alert alert-success">Updated ' . count($validIds) . ' order(s) to ' . ucfirst($newStatus) . '.</div>';
        }
    }
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete') {
    $orderIds = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
    $orderIds = array_filter($orderIds);
    if (!empty($orderIds)) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        // Verify orders belong to user
        $st = $app->pdo->prepare("SELECT o.id FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND o.id IN ($placeholders)");
        $st->execute(array_merge([$uid], $orderIds));
        $validIds = array_column($st->fetchAll(), 'id');
        if (!empty($validIds)) {
            $deletePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
            // Delete line items first (foreign key reference)
            $app->pdo->prepare("DELETE FROM order_line_items WHERE order_id IN ($deletePlaceholders)")->execute($validIds);
            // Delete orders
            $app->pdo->prepare("DELETE FROM orders WHERE id IN ($deletePlaceholders)")->execute($validIds);
            $bulkMessage = '<div class="alert alert-success">Permanently deleted ' . count($validIds) . ' order(s).</div>';
        }
    }
}

// Handle Modal Order Upload
$importMessage = '';
$uploadedOrderIds = [];
if (($page ?? '') === 'orders' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_orders'])) {
    $shopId = (int) ($_POST['shop_id'] ?? 0);
    $st = $app->pdo->prepare('SELECT id FROM shops WHERE id = ? AND user_id = ?');
    $st->execute([$shopId, $uid]);
    if (!$st->fetch()) {
        $importMessage = '<div class="alert alert-error">Invalid shop selected.</div>';
    } else if (isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
        try {
            $path = $_FILES['csv']['tmp_name'];
            $orders = OrderImport::parse($path);
            $index = OrderProductMatch::buildProductIndex($app->pdo, $shopId);

            $orderIns = $app->pdo->prepare('
                INSERT INTO orders (shop_id, name, order_created_at, financial_status, fulfillment_status, total, currency, shipping_method,
                billing_name, billing_phone, billing_address, billing_city, billing_zip, billing_country,
                shipping_name, shipping_address, shipping_city, shipping_zip, notes, phone, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "new")
            ');
            $lineIns = $app->pdo->prepare('
                INSERT INTO order_line_items (order_id, lineitem_name, lineitem_sku, lineitem_price, lineitem_quantity, vendor, fulfillment_status, product_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $checkDup = $app->pdo->prepare('SELECT id FROM orders WHERE shop_id = ? AND name = ?');
            $imported = 0; $skipped = 0;
            foreach ($orders as $o) {
                $orderName = trim($o['name'] ?? '');
                if ($orderName === '') { $skipped++; continue; }
                $checkDup->execute([$shopId, $orderName]);
                if ($checkDup->fetch()) { $skipped++; continue; }
                
                $orderIns->execute([
                    $shopId, $o['name'], $o['order_created_at'], $o['financial_status'], $o['fulfillment_status'],
                    $o['total'], $o['currency'], $o['shipping_method'],
                    $o['billing_name'], $o['billing_phone'], $o['billing_address'], $o['billing_city'], $o['billing_zip'], $o['billing_country'],
                    $o['shipping_name'], $o['shipping_address'], $o['shipping_city'], $o['shipping_zip'], $o['notes'], $o['phone'],
                ]);
                $orderId = (int) $app->pdo->lastInsertId();
                $uploadedOrderIds[] = ['id' => $orderId, 'name' => $o['name']];
                
                foreach ($o['line_items'] as $li) {
                    $productId = OrderProductMatch::findProductId($index, $li['lineitem_name'], $li['lineitem_sku']);
                    $lineIns->execute([
                        $orderId, $li['lineitem_name'], $li['lineitem_sku'], $li['lineitem_price'], $li['lineitem_quantity'],
                        $li['vendor'], $li['fulfillment_status'], $productId,
                    ]);
                }
                $imported++;
            }
            $msgText = 'Imported ' . $imported . ' orders.';
            if ($skipped > 0) $msgText .= ' Skipped ' . $skipped . ' duplicate(s).';
            $importMessage = '<div class="alert alert-success">' . htmlspecialchars($msgText) . '</div>';
            $_SESSION['import_results'] = ['message' => $importMessage, 'orders' => $uploadedOrderIds, 'shop_id' => $shopId];
            header('Location: index.php?page=orders&import_open=1');
            exit;
        } catch (Throwable $e) {
            $importMessage = '<div class="alert alert-error">' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Persist import results session to variable for the modal
$importResults = $_SESSION['import_results'] ?? null;
unset($_SESSION['import_results']);

// Orders list (all / confirmed / follow up)
$currentPage = $page ?? 'orders';
$pageTitle = $currentPage === 'orders-confirmed' ? 'Confirmed orders' : ($currentPage === 'orders-followup' ? 'Follow up' : 'All orders');

// Get filter values
$filterShop = (int) ($_GET['shop_id'] ?? $_POST['shop_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');
$filterSearch = trim($_GET['search'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$filterPriceMin = trim($_GET['price_min'] ?? '');
$filterPriceMax = trim($_GET['price_max'] ?? '');
$pageNum = max(1, (int)($_GET['p'] ?? 1));

// Customizable items per page (default 10, max 100, valid options: 10, 20, 50, 100)
$perPageOpt = (int)($_GET['per_page'] ?? $_POST['per_page'] ?? 10);
if (!in_array($perPageOpt, [10, 20, 50, 100])) $perPageOpt = 10;
$perPage = $perPageOpt;

$offset = ($pageNum - 1) * $perPage;

// Build WHERE clause
$whereClauses = ['s.user_id = ?'];
$whereParams = [$uid];

// Page-specific status filters (for legacy sidebar links)
if ($currentPage === 'orders-confirmed') {
    $whereClauses[] = "(o.status = 'confirmed' OR o.confirmed = 1)";
}
if ($currentPage === 'orders-followup') {
    $whereClauses[] = "(o.status = 'followup' OR o.follow_up = 1)";
}

// Status filter
if ($filterStatus !== '' && in_array($filterStatus, ['new', 'confirmed', 'followup', 'shipping'], true)) {
    $whereClauses[] = 'o.status = ?';
    $whereParams[] = $filterStatus;
}

// Shop filter
if ($filterShop > 0) {
    $whereClauses[] = 'o.shop_id = ?';
    $whereParams[] = $filterShop;
}

// Search filter (order name, customer name, phone, tracking)
if ($filterSearch !== '') {
    $searchTerm = '%' . $filterSearch . '%';
    $whereClauses[] = '(o.name LIKE ? OR o.billing_name LIKE ? OR o.billing_phone LIKE ? OR o.phone LIKE ? OR o.fiabilo_tracking_code LIKE ?)';
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
    $whereParams[] = $searchTerm;
}

// Date range filter
if ($filterDateFrom !== '') {
    $whereClauses[] = 'DATE(o.created_at) >= ?';
    $whereParams[] = $filterDateFrom;
}
if ($filterDateTo !== '') {
    $whereClauses[] = 'DATE(o.created_at) <= ?';
    $whereParams[] = $filterDateTo;
}

// Price range filter
if ($filterPriceMin !== '' && is_numeric($filterPriceMin)) {
    $whereClauses[] = 'o.total >= ?';
    $whereParams[] = (float) $filterPriceMin;
}
if ($filterPriceMax !== '' && is_numeric($filterPriceMax)) {
    $whereClauses[] = 'o.total <= ?';
    $whereParams[] = (float) $filterPriceMax;
}

$whereString = implode(' AND ', $whereClauses);

// Summary stats handling
if ($currentPage === 'orders-followup') {
    // Follow-up specific stats (Lifetime as requested)
    $stFollow = $app->pdo->prepare("
        SELECT 
            COUNT(*) as follow_qty,
            COALESCE(SUM(total), 0) as follow_val
        FROM orders o JOIN shops s ON o.shop_id = s.id 
        WHERE s.user_id = ? AND (o.status = 'followup' OR o.follow_up = 1)
    ");
    $stFollow->execute([$uid]);
    $followStats = $stFollow->fetch();
    
    $stRestored = $app->pdo->prepare("
        SELECT COUNT(*) as restored_qty, COALESCE(SUM(total), 0) as restored_val 
        FROM orders o JOIN shops s ON o.shop_id = s.id 
        WHERE s.user_id = ? AND o.confirmed = 1 AND o.followup_at IS NOT NULL
    ");
    $stRestored->execute([$uid]);
    $restoredStats = $stRestored->fetch();
    
    $stat1Label = "Lifetime Follow-ups";
    $stat1Value = (int) $followStats['follow_qty'];
    $stat1Sub = "Total orders currently flagged";
    $stat1Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
    
    $stat2Label = "Follow-up Value";
    $stat2Value = number_format($followStats['follow_val'], 2) . ' <small>TND</small>';
    $stat2Sub = "Potential revenue in follow-up";
    $stat2Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>';
    
    $stat3Label = "Restored Count";
    $stat3Value = (int) $restoredStats['restored_qty'];
    $stat3Sub = "Orders recovered from follow-up";
    $stat3Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';

    $stat4Label = "Restored Revenue";
    $stat4Value = number_format($restoredStats['restored_val'], 2) . ' <small>TND</small>';
    $stat4Sub = "Total value of recovered orders";
    $stat4Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>';

} elseif ($currentPage === 'orders-confirmed') {
    // Confirmed Orders specific metrics
    $stConf = $app->pdo->prepare("
        SELECT 
            COUNT(*) as conf_qty,
            COALESCE(SUM(total), 0) as conf_val
        FROM orders o JOIN shops s ON o.shop_id = s.id 
        WHERE s.user_id = ? AND (o.status = 'confirmed' OR o.confirmed = 1)
    ");
    $stConf->execute([$uid]);
    $confStats = $stConf->fetch();
    
    $stAll = $app->pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ?");
    $stAll->execute([$uid]);
    $totalAll = (int) $stAll->fetchColumn();
    $rate = $totalAll > 0 ? ($confStats['conf_qty'] / $totalAll) * 100 : 0;

    $stat1Label = "Total Confirmed";
    $stat1Value = (int) $confStats['conf_qty'];
    $stat1Sub = "Lifetime confirmed orders";
    $stat1Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';

    $stat2Label = "Total Revenue";
    $stat2Value = number_format($confStats['conf_val'], 2) . ' <small>TND</small>';
    $stat2Sub = "Value of all confirmed orders";
    $stat2Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>';

    $stat3Label = "Confirmation Rate";
    $stat3Value = number_format($rate, 1) . '%';
    $stat3Sub = "Conversion from all orders";
    $stat3Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="16 12 12 8 8 12"></polyline><line x1="12" y1="16" x2="12" y2="8"></line></svg>';

} else {
    // Summary stats for All Orders page - 6 cards total
    $today = date('Y-m-d');
    
    // Today stats (Orders Today, Confirmed Value Today, Pending)
    $stToday = $app->pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM orders o2 JOIN shops s2 ON o2.shop_id = s2.id WHERE s2.user_id = ? AND DATE(o2.created_at) = ?) as cnt,
            (SELECT COALESCE(SUM(total), 0) FROM orders o3 JOIN shops s3 ON o3.shop_id = s3.id WHERE s3.user_id = ? AND o3.confirmed = 1 AND DATE(o3.confirmed_at) = ?) as revenue
    ");
    $stToday->execute([$uid, $today, $uid, $today]);
    $todayStats = $stToday->fetch();
    $ordersToday = (int) $todayStats['cnt'];
    $revenueToday = (float) $todayStats['revenue'];

    $stPending = $app->pdo->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE s.user_id = ? AND (o.status = 'new' OR (o.status IS NULL AND o.confirmed = 0 AND o.follow_up = 0))");
    $stPending->execute([$uid]);
    $pendingCount = (int) $stPending->fetchColumn();

    // Filtered aggregate stats (Confirmed, Follow-ups, Conversion Rate)
    $statsWhereClauses = ['s.user_id = ?'];
    $statsWhereParams = [$uid];
    
    if ($filterShop > 0) {
        $statsWhereClauses[] = 'o.shop_id = ?';
        $statsWhereParams[] = $filterShop;
    }
    if ($filterSearch !== '') {
        $searchTerm = '%' . $filterSearch . '%';
        $statsWhereClauses[] = '(o.name LIKE ? OR o.billing_name LIKE ? OR o.billing_phone LIKE ? OR o.phone LIKE ? OR o.fiabilo_tracking_code LIKE ?)';
        array_push($statsWhereParams, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }
    if ($filterDateFrom !== '') {
        $statsWhereClauses[] = 'DATE(o.created_at) >= ?';
        $statsWhereParams[] = $filterDateFrom;
    }
    if ($filterDateTo !== '') {
        $statsWhereClauses[] = 'DATE(o.created_at) <= ?';
        $statsWhereParams[] = $filterDateTo;
    }
    if ($filterPriceMin !== '' && is_numeric($filterPriceMin)) {
        $statsWhereClauses[] = 'o.total >= ?';
        $statsWhereParams[] = (float) $filterPriceMin;
    }
    if ($filterPriceMax !== '' && is_numeric($filterPriceMax)) {
        $statsWhereClauses[] = 'o.total <= ?';
        $statsWhereParams[] = (float) $filterPriceMax;
    }
    
    $statsWhereString = implode(' AND ', $statsWhereClauses);
    $stStats = $app->pdo->prepare("
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN o.status = 'confirmed' OR o.confirmed = 1 THEN 1 ELSE 0 END) as confirmed_count,
            SUM(CASE WHEN o.status = 'followup' OR o.follow_up = 1 THEN 1 ELSE 0 END) as followup_count
        FROM orders o JOIN shops s ON o.shop_id = s.id 
        WHERE $statsWhereString
    ");
    $stStats->execute($statsWhereParams);
    $allStats = $stStats->fetch();
    
    $totalOrders = (int) $allStats['total_count'];
    $confirmedOrders = (int) $allStats['confirmed_count'];
    $followupOrders = (int) $allStats['followup_count'];
    $convRate = $totalOrders > 0 ? ($confirmedOrders / $totalOrders) * 100 : 0;

    // Row 1: Today stats
    $stat1Label = "Orders Today";
    $stat1Value = $ordersToday;
    $stat1Sub = "Orders created since midnight";
    $stat1Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';

    $stat2Label = "Confirmed orders value";
    $stat2Value = number_format($revenueToday, 2) . ' <small>TND</small>';
    $stat2Sub = "Total from confirmed orders today";
    $stat2Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>';

    $stat3Label = "Pending Confirmations";
    $stat3Value = $pendingCount;
    $stat3Sub = "Orders waiting for your action";
    $stat3Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';

    // Row 2: Filtered aggregate stats
    $stat4Label = "Total Confirmed";
    $stat4Value = $confirmedOrders;
    $stat4Sub = "Confirmed orders in filtered view";
    $stat4Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>';

    $stat5Label = "Total Follow-ups";
    $stat5Value = $followupOrders;
    $stat5Sub = "Orders flagged for follow-up";
    $stat5Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';

    $stat6Label = "Conversion Rate";
    $stat6Value = number_format($convRate, 1) . '%';
    $stat6Sub = "Confirmed vs Total Orders";
    $stat6Icon = '<svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="16 12 12 8 8 12"></polyline><line x1="12" y1="16" x2="12" y2="8"></line></svg>';
}

// Count total with filters
$countSql = "SELECT COUNT(*) FROM orders o JOIN shops s ON o.shop_id = s.id WHERE $whereString";
$countSt = $app->pdo->prepare($countSql);
$countSt->execute($whereParams);
$totalCount = (int) $countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Get orders with pagination
$sql = "SELECT o.id, o.name, o.order_created_at, o.total, o.currency, o.status, o.confirmed, o.follow_up, o.fiabilo_tracking_code, o.fiabilo_status, o.billing_name, o.billing_phone, o.phone, o.shipping_method, s.name AS shop_name,
        (SELECT SUM(lineitem_quantity) FROM order_line_items WHERE order_id = o.id) AS item_count
        FROM orders o JOIN shops s ON o.shop_id = s.id 
        WHERE $whereString 
        ORDER BY o.created_at DESC, o.id DESC 
        LIMIT $perPage OFFSET $offset";
$st = $app->pdo->prepare($sql);
$st->execute($whereParams);
$orders = $st->fetchAll();

$shops = $app->pdo->prepare('SELECT id, name FROM shops WHERE user_id = ? ORDER BY name');
$shops->execute([$uid]);
$shops = $shops->fetchAll();

// Build filter query string for pagination
$filterParams = [
    'page' => $currentPage,
    'shop_id' => $filterShop,
    'status' => $filterStatus,
    'search' => $filterSearch,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
    'price_min' => $filterPriceMin,
    'price_max' => $filterPriceMax,
    'per_page' => $perPageOpt,
];
$filterParams = array_filter($filterParams, function($v) { return $v !== '' && $v !== 0 && $v !== '0'; });
if ($filterShop > 0) $filterParams['shop_id'] = $filterShop; // keep 0 out but keep shop if set
if ($perPageOpt !== 10) $filterParams['per_page'] = $perPageOpt;

// Status Badge for mobile header
$pageHeaderBadge = '';
if ($currentPage === 'orders-confirmed') {
    $pageHeaderBadge = '<div class="status-badge-header status-confirmed">Confirmed</div>';
} elseif ($currentPage === 'orders-followup') {
    $pageHeaderBadge = '<div class="status-badge-header status-followup">Follow Up</div>';
}

$content = '
<div class="settings-header">
    <div class="header-main">
        <h1>' . $pageTitle . '</h1>
        <div class="header-line"></div>
        ' . ($currentPage === 'orders' ? '
        <div style="display:flex; gap:0.5rem; margin-left: 1rem;">
            <button type="button" class="btn btn-import" onclick="openImportModal()" style="border-radius: 12px; height: 42px; padding: 0 1.5rem; display: flex; align-items: center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                Import Order
            </button>
            <button type="button" class="btn btn-primary" onclick="window.location.href=\'index.php?page=manual-order\'" style="border-radius: 12px; height: 42px; padding: 0 1.5rem; background: #0f172a; color: #fff; border: none; display: flex; align-items: center; cursor: pointer; transition: background 0.2s;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Add Manual Order
            </button>
            <button type="button" class="btn" onclick="alert(\'This is a paid feature\')" style="border-radius: 12px; height: 42px; padding: 0 1.5rem; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; display: flex; align-items: center; cursor: pointer; transition: all 0.2s; font-weight: 600; font-size: 0.85rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><path d="M1 4h6a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2H1z"></path><path d="M23 4h-6a2 2 0 0 0-2 2v14a2 2 0 0 1 2-2h6z"></path></svg>
                Receive abandoned checkouts
            </button>
        </div>' : '') . '
    </div>
    <p class="header-subtitle">Manage, track, and process your orders with ease.</p>
</div>';
if (isset($sendMessage)) $content .= $sendMessage;
if (isset($bulkMessage)) $content .= $bulkMessage;
if (!empty($dropiloImportMessage)) $content .= $dropiloImportMessage;
if (isset($exportError)) $content .= $exportError;

// Summary stats cards (Premium UI) - 6 cards for All Orders, 3 for other pages
if ($currentPage === 'orders') {
    $content .= '
<div class="stats-grid" style="margin-bottom: 2rem;">
  <div class="stats-card primary">
    <span class="label">' . $stat1Label . '</span>
    <span class="value">' . $stat1Value . '</span>
    <span class="sub-label">' . $stat1Sub . '</span>
    <div class="icon-bg">
        ' . $stat1Icon . '
    </div>
  </div>
  <div class="stats-card highlight">
    <span class="label">' . $stat2Label . '</span>
    <span class="value">' . $stat2Value . '</span>
    <span class="sub-label">' . $stat2Sub . '</span>
    <div class="icon-bg">
        ' . $stat2Icon . '
    </div>
  </div>
  <div class="stats-card highlight">
    <span class="label">' . $stat3Label . '</span>
    <span class="value">' . $stat3Value . '</span>
    <span class="sub-label">' . $stat3Sub . '</span>
    <div class="icon-bg">
        ' . $stat3Icon . '
    </div>
  </div>
  <div class="stats-card primary">
    <span class="label">' . $stat4Label . '</span>
    <span class="value">' . $stat4Value . '</span>
    <span class="sub-label">' . $stat4Sub . '</span>
    <div class="icon-bg">
        ' . $stat4Icon . '
    </div>
  </div>
  <div class="stats-card highlight">
    <span class="label">' . $stat5Label . '</span>
    <span class="value">' . $stat5Value . '</span>
    <span class="sub-label">' . $stat5Sub . '</span>
    <div class="icon-bg">
        ' . $stat5Icon . '
    </div>
  </div>
  <div class="stats-card highlight">
    <span class="label">' . $stat6Label . '</span>
    <span class="value">' . $stat6Value . '</span>
    <span class="sub-label">' . $stat6Sub . '</span>
    <div class="icon-bg">
        ' . $stat6Icon . '
    </div>
  </div>
</div>';
} else {
    $content .= '
<div class="stats-grid" style="margin-bottom: 2rem;">
  <div class="stats-card primary">
    <span class="label">' . $stat1Label . '</span>
    <span class="value">' . $stat1Value . '</span>
    <span class="sub-label">' . $stat1Sub . '</span>
    <div class="icon-bg">
        ' . $stat1Icon . '
    </div>
  </div>
  <div class="stats-card highlight">
    <span class="label">' . $stat2Label . '</span>
    <span class="value">' . $stat2Value . '</span>
    <span class="sub-label">' . $stat2Sub . '</span>
    <div class="icon-bg">
        ' . $stat2Icon . '
    </div>
  </div>
  <div class="stats-card highlight">
    <span class="label">' . $stat3Label . '</span>
    <span class="value">' . $stat3Value . '</span>
    <span class="sub-label">' . $stat3Sub . '</span>
    <div class="icon-bg">
        ' . $stat3Icon . '
    </div>
  </div>
  ' . (isset($stat4Label) ? '
  <div class="stats-card primary">
    <span class="label">' . $stat4Label . '</span>
    <span class="value">' . $stat4Value . '</span>
    <span class="sub-label">' . $stat4Sub . '</span>
    <div class="icon-bg">
        ' . $stat4Icon . '
    </div>
  </div>' : '') . '
</div>';
}

$content .= '<div class="card">';

// Search and filters form
$content .= '<form method="get" id="orders-filter-form" class="orders-filters-form">';
$content .= '<input type="hidden" name="page" value="' . htmlspecialchars($currentPage) . '">';

// Search row
$content .= '  <div class="search-box">
    <input type="text" name="search" placeholder="Search order #, customer, phone..." value="' . htmlspecialchars($filterSearch) . '" class="search-input">
    <button type="submit" class="btn btn-search-blue">Search</button>
  </div>
</div>';

// Filters row
$content .= '<div class="filters-row">';

// Shop filter
$content .= '<select name="shop_id" class="filter-select"><option value="0">All shops</option>';
foreach ($shops as $s) {
    $sel = $s['id'] == $filterShop ? ' selected' : '';
    $content .= '<option value="' . $s['id'] . '"' . $sel . '>' . htmlspecialchars($s['name']) . '</option>';
}
$content .= '</select>';

// Status filter
$content .= '<select name="status" class="filter-select"><option value="">All statuses</option>';
$statusOptions = ['new' => 'New', 'confirmed' => 'Confirmed', 'followup' => 'Follow Up', 'shipping' => 'Assigned to Shipping'];
foreach ($statusOptions as $val => $label) {
    $sel = $filterStatus === $val ? ' selected' : '';
    $content .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
$content .= '</select>';

// Date filters
$content .= '<input type="date" name="date_from" placeholder="From date" value="' . htmlspecialchars($filterDateFrom) . '" class="filter-date" title="From date">';
$content .= '<input type="date" name="date_to" placeholder="To date" value="' . htmlspecialchars($filterDateTo) . '" class="filter-date" title="To date">';

// Price filters
$content .= '<input type="number" name="price_min" placeholder="Min price" value="' . htmlspecialchars($filterPriceMin) . '" class="filter-price" step="0.01" min="0">';
$content .= '<input type="number" name="price_max" placeholder="Max price" value="' . htmlspecialchars($filterPriceMax) . '" class="filter-price" step="0.01" min="0">';

// Items per page filter
$content .= '<select name="per_page" class="filter-select" title="Items per page">';
$perPageOptions = [10, 20, 50, 100];
foreach ($perPageOptions as $opt) {
    $sel = $perPageOpt === $opt ? ' selected' : '';
    $content .= '<option value="' . $opt . '"' . $sel . '>' . $opt . ' per page</option>';
}
$content .= '</select>';

$filterIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>';
$content .= '<button type="submit" class="btn btn-filter-outline" title="Apply Filters">' . $filterIcon . '</button>';

// Clear filters link
$hasFilters = $filterShop || $filterStatus || $filterSearch || $filterDateFrom || $filterDateTo || $filterPriceMin || $filterPriceMax;
if ($hasFilters) {
    $content .= '<a href="index.php?page=' . htmlspecialchars($currentPage) . '" class="btn btn-secondary">Clear</a>';
}

$content .= '</div></form>';

// Bulk actions form
$content .= '<form method="post" id="orders-bulk-form">';
$content .= '<input type="hidden" name="page" value="' . htmlspecialchars($currentPage) . '">';

// Shipping toolbar for confirmed orders page
if ($currentPage === 'orders-confirmed') {
    $content .= '<div class="orders-toolbar">
      <div class="orders-toolbar-left"><h3 class="orders-toolbar-title">Send to shipping company</h3><p class="orders-toolbar-subtitle">Select orders below, choose FIABILO, then press Send order.</p></div>
      <div class="orders-toolbar-actions"><select name="shipping" class="select" aria-label="Shipping company"><option value="fiabilo">FIABILO</option><option value="intigo">INTIGO</option></select> <button type="submit" name="send_to_shipping" value="1" class="btn btn-warning">Send order(s)</button></div>
    </div>';
}

// Table
$truckSvg = '<svg class="ship-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 7h11v10H3V7Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M14 10h4l3 3v4h-7v-7Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M7 17a2 2 0 1 0 0 4a2 2 0 0 0 0-4Z" stroke="currentColor" stroke-width="2"/><path d="M17 17a2 2 0 1 0 0 4a2 2 0 0 0 0-4Z" stroke="currentColor" stroke-width="2"/><path d="M5 21h2M15 21h2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

$content .= '<table class="orders-table"><thead><tr>';
$content .= '<th><input type="checkbox" id="select-all" title="Select all"></th>';
$content .= '<th>Order</th><th>Customer</th><th>Phone</th><th>Shop</th><th>Date</th>';
if ($currentPage === 'orders-confirmed') {
    $content .= '<th>Tracking</th>';
}
$content .= '<th>Total</th><th>Status</th>';
$content .= '<th></th></tr></thead><tbody>';

foreach ($orders as $o) {
    // Determine status - use new status column or fall back to legacy flags
    $orderStatus = $o['status'] ?? null;
    if ($orderStatus === null || $orderStatus === '') {
        // Fallback to legacy flags
        if (!empty($o['fiabilo_tracking_code'])) {
            $orderStatus = 'shipping';
        } elseif ($o['confirmed']) {
            $orderStatus = 'confirmed';
        } elseif ($o['follow_up']) {
            $orderStatus = 'followup';
        } else {
            $orderStatus = 'new';
        }
    }
    
    $checkIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    $statusBadges = [
        'new' => '<span class="badge badge-new">New</span>',
        'confirmed' => '<div class="status-confirmed-new">' . $checkIcon . ' Confirmed</div>',
        'followup' => '<span class="badge badge-followup">Follow Up</span>',
        'shipping' => '<span class="badge badge-shipping">Assigned to Shipping</span>',
    ];
    
    // For orders with fiabilo_status, show the actual shipping status
    $deliveredList = ['Livré', 'Livrés', 'Livrer', 'Delivered', 'Reçu'];
    $returnedList = ['Rtn definitif', 'Retour', 'Returned', 'Annulé'];
    $inProgressList = ['En attente', 'En cours', 'Au depot', 'Au dépôt', 'En livraison'];
    
    if (!empty($o['fiabilo_status'])) {
        $fiabiloStatus = $o['fiabilo_status'];
        if (in_array($fiabiloStatus, $deliveredList)) {
            $statusBadge = '<div class="status-confirmed-new">' . $checkIcon . ' ' . htmlspecialchars($fiabiloStatus) . '</div>';
        } elseif (in_array($fiabiloStatus, $returnedList)) {
            $statusBadge = '<span class="badge badge-danger" style="background:#fee2e2; color:#dc2626; border:1px solid #fecaca;">' . htmlspecialchars($fiabiloStatus) . '</span>';
        } elseif (in_array($fiabiloStatus, $inProgressList)) {
            $statusBadge = '<span class="badge badge-info" style="background:#dbeafe; color:#2563eb; border:1px solid #bfdbfe;">' . htmlspecialchars($fiabiloStatus) . '</span>';
        } else {
            // Show actual status from shipping company
            $statusBadge = '<span class="badge badge-shipping">' . htmlspecialchars($fiabiloStatus) . '</span>';
        }
    } else {
        $statusBadge = $statusBadges[$orderStatus] ?? '<span class="badge badge-new">New</span>';
    }
    
    // Customer info
    $customerName = htmlspecialchars($o['billing_name'] ?? '—');
    $customerPhone = htmlspecialchars($o['billing_phone'] ?? $o['phone'] ?? '—');
    $itemCount = (int)($o['item_count'] ?? 0);
    $shippingMethod = htmlspecialchars($o['shipping_method'] ?? 'Standard');
    $orderDate = $o['order_created_at'] ? date('H:i A', strtotime($o['order_created_at'])) : '—';
    $shopName = htmlspecialchars($o['shop_name'] ?? '—');
    
    $content .= '<tr class="order-row">';
    $content .= '<td class="col-checkbox"><input type="checkbox" name="order_ids[]" value="' . $o['id'] . '" class="order-cb" data-items="' . $itemCount . '"></td>';
    $content .= '<td class="col-name" data-label="Order">
        <div class="order-main-info">
            <a href="index.php?page=order-view&id=' . $o['id'] . '&from=' . $currentPage . '" class="order-link">' . htmlspecialchars($o['name']) . '</a>';
            
            if (!empty($o['fiabilo_tracking_code'])) {
                $content .= '
                <div class="mobile-barcode-wrap" title="Tracking Code: ' . htmlspecialchars($o['fiabilo_tracking_code']) . '">
                    <div class="barcode-badge">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5v14c0 1.1.9 2 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2z"></path><path d="M7 7v10"></path><path d="M10 7v10"></path><path d="M13 7v10"></path><path d="M17 7v10"></path></svg>
                        <span class="barcode-text">' . htmlspecialchars($o['fiabilo_tracking_code']) . '</span>
                    </div>
                    <button type="button" class="btn-map-track" onclick="event.preventDefault(); event.stopPropagation(); openTrackingModal(' . $o['id'] . ')" title="Track on Map">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </button>
                </div>';
            }

    $content .= '
        </div>
        <div class="mobile-only-detail">
            <span class="mobile-customer">' . $customerName . '</span> • 
            <span class="mobile-items">' . $itemCount . ' item' . ($itemCount != 1 ? 's' : '') . '</span> • 
            <span class="mobile-time">' . $orderDate . '</span>
        </div>
        <div class="mobile-only-shop">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            ' . $shopName . '
        </div>
        <div class="mobile-only-shipping">' . $shippingMethod . '</div>
    </td>';
    $content .= '<td class="col-customer" data-label="Customer">' . $customerName . '</td>';
    $content .= '<td class="col-phone" data-label="Phone">' . $customerPhone . '</td>';
    $content .= '<td class="col-shop" data-label="Shop">' . htmlspecialchars($o['shop_name']) . '</td>';
    $content .= '<td class="col-date" data-label="Date">' . htmlspecialchars($o['order_created_at'] ?? '') . '</td>';
    
    if ($currentPage === 'orders-confirmed') {
        $shipCell = '—';
        if (!empty($o['fiabilo_tracking_code'])) {
            $shipCell = '
            <div class="tracking-cell-wrap">
                <code class="tracking-code-pill">' . htmlspecialchars($o['fiabilo_tracking_code']) . '</code>
                <button type="button" class="btn-map-track-desktop" onclick="openTrackingModal(' . $o['id'] . ')" title="Track Order">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                </button>
            </div>';
        }
        $content .= '<td class="col-shipping" data-label="Tracking">' . $shipCell . '</td>';
    }

    $content .= '<td class="col-total" data-label="Total">' . ($o['total'] !== null ? number_format((float)$o['total'], 2) . ' ' . htmlspecialchars($o['currency'] ?? '') : '—') . '</td>';
    $content .= '<td class="col-status" data-label="Status">' . $statusBadge . '</td>';
    
    // Action buttons - show Track button for orders with tracking code
    $content .= '<td class="col-actions"><div style="display:flex; gap:0.5rem;">';
    if (!empty($o['fiabilo_tracking_code'])) {
        $content .= '<button type="button" class="btn btn-sm" style="background:#f1f5f9; color:#475569; padding:0.4rem 0.75rem;" onclick="quickTrack(' . (int)$o['id'] . ')">Track</button>';
    }
    $content .= '<a href="index.php?page=order-view&id=' . $o['id'] . '&from=' . $currentPage . '" class="btn btn-sm btn-view-eye" title="View Order"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> View</a>';
    $content .= '</div></td>';
    $content .= '</tr>';
}

$content .= '</tbody></table>';

// Floating bulk action bar
$content .= '<div class="bulk-action-bar" id="bulk-action-bar" style="display: none;">
  <span class="bulk-count"><span id="selected-count">0</span> order(s) selected</span>
  <div class="bulk-actions">
    <button type="button" class="btn" id="bulk-items-btn" style="background: #334155; color: #fff;">Items Ordered</button>
    <button type="button" class="btn btn-dropilo-export" id="bulk-export-btn" style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; border: none;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Export Dropilo</button>
    <button type="submit" name="bulk_action" value="confirm" class="btn btn-success" id="bulk-confirm-btn">Confirm Selected</button>
    <button type="submit" name="bulk_action" value="followup" class="btn btn-warning">Follow Up</button>
    <button type="submit" name="bulk_action" value="shipping" class="btn btn-info">Assign to Shipping</button>
    <button type="button" class="btn btn-danger" id="bulk-delete-btn" style="background: #dc3545; border-color: #dc3545;">Delete</button>
  </div>
</div>';

// Hidden inputs
$content .= '<input type="hidden" name="bulk_action" id="hidden-bulk-action" value="" disabled>';
$content .= '<input type="hidden" name="post_open_package_permission" id="post_open_package_permission" value="">';

$content .= '</form>';

// Pagination
if ($totalPages > 1) {
    $content .= '<div class="pagination"><div class="pagination-info">Showing ' . (($pageNum - 1) * $perPage + 1) . '-' . min($pageNum * $perPage, $totalCount) . ' of ' . $totalCount . '</div><div class="pagination-controls">';
    
    $paginationParams = $filterParams;
    
    if ($pageNum > 1) {
        $paginationParams['p'] = $pageNum - 1;
        $content .= '<a href="index.php?' . http_build_query($paginationParams) . '">Previous</a>';
    } else {
        $content .= '<span>Previous</span>';
    }
    
    for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++) {
        if ($i == $pageNum) {
            $content .= '<span class="current">' . $i . '</span>';
        } else {
            $paginationParams['p'] = $i;
            $content .= '<a href="index.php?' . http_build_query($paginationParams) . '">' . $i . '</a>';
        }
    }
    
    if ($pageNum < $totalPages) {
        $paginationParams['p'] = $pageNum + 1;
        $content .= '<a href="index.php?' . http_build_query($paginationParams) . '">Next</a>';
    } else {
        $content .= '<span>Next</span>';
    }
    
    $content .= '</div></div>';
}

$content .= '</div>';

// JavaScript for bulk actions and Modal Import
$content .= '
<script>
window.openTrackingModal = function(oid) {
    var modal = document.getElementById("tracking-modal");
    var content = document.getElementById("tracking-modal-content") || document.getElementById("tracking-content");
    if (modal) {
        modal.style.display = "flex";
        if (content) {
            content.innerHTML = \'<div class="loading-spinner-container" style="padding: 3rem; text-align: center; color: #64748b;"><div class="spinner-ring" style="width: 32px; height: 32px; border: 3px solid #f3f3f3; border-top: 3px solid #10b981; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 1rem;"></div><span>Fetching live data...</span></div>\';
            
            fetch("index.php?page=tracking-ajax&id=" + oid)
                .then(r => r.text())
                .then(html => {
                    content.innerHTML = html;
                })
                .catch(err => {
                    content.innerHTML = \'<div style="padding:2rem;text-align:center;color:red;">Failed to load tracking data.</div>\';
                });
        }
    }
};

window.closeTrackingModal = function(event) {
    if (!event || event.target.id === "tracking-modal" || event.target.className === "tracking-modal-close") {
        var modal = document.getElementById("tracking-modal");
        if (modal) modal.style.display = "none";
    }
};

document.addEventListener("DOMContentLoaded", function() {
    var selectAll = document.getElementById("select-all");
    var checkboxes = document.querySelectorAll(".order-cb");
    var bulkBar = document.getElementById("bulk-action-bar");
    var countSpan = document.getElementById("selected-count");
    
    function updateBulkBar() {
        var checked = document.querySelectorAll(".order-cb:checked").length;
        if (countSpan) countSpan.textContent = checked;
        if (bulkBar) bulkBar.style.display = checked > 0 ? "flex" : "none";
    }
    
    if (selectAll) {
        selectAll.addEventListener("change", function() {
            checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
            updateBulkBar();
        });
        
        selectAll.addEventListener("keydown", function(e) {
            if (e.key === "Tab" && !e.shiftKey && checkboxes.length > 0) {
                e.preventDefault();
                checkboxes[0].focus();
            }
        });
    }
    
    checkboxes.forEach(function(cb, index) {
        cb.addEventListener("change", updateBulkBar);
        
        cb.addEventListener("keydown", function(e) {
            if (e.key === "Tab") {
                if (e.shiftKey) {
                    if (index > 0) {
                        e.preventDefault();
                        checkboxes[index - 1].focus();
                    } else if (selectAll) {
                        e.preventDefault();
                        selectAll.focus();
                    }
                } else {
                    if (index < checkboxes.length - 1) {
                        e.preventDefault();
                        checkboxes[index + 1].focus();
                    }
                }
            }
        });
    });

    var bulkForm = document.getElementById("orders-bulk-form");
    var bulkConfirmBtn = document.getElementById("bulk-confirm-btn");
    var bulkDeleteBtn = document.getElementById("bulk-delete-btn");
    
    if (bulkForm && bulkConfirmBtn) {
        bulkConfirmBtn.addEventListener("click", function(e) {
            // Only show popup on follow-up page or if we detect follow up orders (simplified: always show on follow-up page)
            if (window.location.search.includes("page=orders-followup")) {
                if (!confirm("One or more selected orders were previously marked as Follow Up. Marking them as Confirmed will categorize them as Restored Orders in your analytics. Proceed?")) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }
    
    // Delete confirmation - show custom modal
    if (bulkForm && bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener("click", function(e) {
            var selectedCount = document.querySelectorAll(".order-cb:checked").length;
            if (selectedCount === 0) return;
            
            // Update modal count and show
            document.getElementById("delete-count").textContent = selectedCount;
            document.getElementById("delete-modal").style.display = "flex";
        });
    }

    // Items Ordered feature
    var bulkItemsBtn = document.getElementById("bulk-items-btn");
    if (bulkItemsBtn) {
        bulkItemsBtn.addEventListener("click", function(e) {
            e.preventDefault();
            var selectedCount = document.querySelectorAll(".order-cb:checked").length;
            if (selectedCount === 0) return;
            
            var totalItems = 0;
            document.querySelectorAll(".order-cb:checked").forEach(function(cb) {
                totalItems += parseInt(cb.getAttribute("data-items") || "0", 10);
            });
            
            document.getElementById("items-order-count").textContent = selectedCount;
            document.getElementById("items-total-count").textContent = totalItems;
            document.getElementById("items-modal").style.display = "flex";
        });
    }

    // Initialize initial state
    updateBulkBar();
});

// Modal Import Functions
function openImportModal() {
    document.getElementById("import-modal").style.display = "flex";
}

function closeImportModal() {
    document.getElementById("import-modal").style.display = "none";
    // Clear URL without reload if possible
    if (window.location.search.includes("import_open=1")) {
        window.history.replaceState({}, document.title, "index.php?page=orders");
    }
}

function handleImportDragOver(e) { e.preventDefault(); e.currentTarget.classList.add("dragover"); }
function handleImportDragLeave(e) { e.preventDefault(); e.currentTarget.classList.remove("dragover"); }
function handleImportDrop(e) { e.preventDefault(); e.currentTarget.classList.remove("dragover"); if (e.dataTransfer.files.length) handleImportFile(e.dataTransfer.files[0]); }
function handleImportFileSelect(input) { if (input.files.length) handleImportFile(input.files[0]); }

function handleImportFile(file) {
    if (!file.name.toLowerCase().endsWith(".csv")) { alert("CSV only please."); return; }
    document.getElementById("import-file-name").textContent = file.name;
    document.getElementById("import-file-preview").style.display = "flex";
    document.querySelector("#import-dropzone .dropzone-content").style.display = "none";
    document.getElementById("import-submit-btn").disabled = false;
    
    var dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById("import-csv").files = dt.files;
}

function removeImportFile(e) {
    if (e) e.stopPropagation();
    document.getElementById("import-csv").value = "";
    document.getElementById("import-file-preview").style.display = "none";
    document.querySelector("#import-dropzone .dropzone-content").style.display = "flex";
    document.getElementById("import-submit-btn").disabled = true;
}

document.getElementById("import-form")?.addEventListener("submit", function() {
    document.getElementById("import-progress").style.display = "block";
    var bar = document.getElementById("import-bar");
    var txt = document.getElementById("import-progress-text");
    var w = 0;
    var inv = setInterval(function() {
        if (w >= 90) { clearInterval(inv); return; }
        w += Math.random() * 20;
        if (w > 90) w = 90;
        bar.style.width = w + "%";
        txt.textContent = Math.round(w) + "%";
    }, 200);
});

// Sidebar Fix Functions
function openFixSidebarOrder(btn) {
    document.getElementById("fix-order-id").value = btn.dataset.orderId;
    document.getElementById("fix-order-name").value = btn.dataset.orderName;
    document.getElementById("fix-order-billing-name").value = btn.dataset.billingName;
    document.getElementById("fix-order-billing-phone").value = btn.dataset.billingPhone;
    document.getElementById("fix-order-shipping-address").value = btn.dataset.shippingAddress;
    document.getElementById("fix-overlay").classList.add("active");
    document.getElementById("fix-sidebar").classList.add("active");
}

function closeFixSidebar() {
    document.getElementById("fix-overlay").classList.remove("active");
    document.getElementById("fix-sidebar").classList.remove("active");
}

// Custom Delete Modal Functions
function closeDeleteModal() {
    document.getElementById("delete-modal").style.display = "none";
}

function closeItemsModal() {
    document.getElementById("items-modal").style.display = "none";
}

// ===== Dropilo Export/Import Functions =====

// Export: create a hidden form and submit with selected order IDs
var bulkExportBtn = document.getElementById("bulk-export-btn");
if (bulkExportBtn) {
    bulkExportBtn.addEventListener("click", function(e) {
        e.preventDefault();
        var checked = document.querySelectorAll(".order-cb:checked");
        if (checked.length === 0) return;
        
        // Create a temporary form for the export POST
        var form = document.createElement("form");
        form.method = "POST";
        form.action = "index.php?page=orders";
        form.style.display = "none";
        
        var hiddenExport = document.createElement("input");
        hiddenExport.type = "hidden";
        hiddenExport.name = "dropilo_export";
        hiddenExport.value = "1";
        form.appendChild(hiddenExport);
        
        checked.forEach(function(cb) {
            var input = document.createElement("input");
            input.type = "hidden";
            input.name = "order_ids[]";
            input.value = cb.value;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
        
        // Remove the temp form after a short delay
        setTimeout(function() { form.remove(); }, 1000);
    });
}

// Tab switching for Import Modal
function switchImportTab(mode) {
    var tabShopify = document.getElementById("tab-shopify");
    var tabDropilo = document.getElementById("tab-dropilo");
    var panelShopify = document.getElementById("panel-shopify");
    var panelDropilo = document.getElementById("panel-dropilo");
    
    if (mode === "shopify") {
        tabShopify.style.background = "#fff";
        tabShopify.style.color = "#1e293b";
        tabShopify.style.boxShadow = "0 1px 3px rgba(0,0,0,0.1)";
        tabDropilo.style.background = "transparent";
        tabDropilo.style.color = "#64748b";
        tabDropilo.style.boxShadow = "none";
        if (panelShopify) panelShopify.style.display = "block";
        if (panelDropilo) panelDropilo.style.display = "none";
    } else {
        tabDropilo.style.background = "linear-gradient(135deg, #6366f1, #8b5cf6)";
        tabDropilo.style.color = "#fff";
        tabDropilo.style.boxShadow = "0 1px 3px rgba(99,102,241,0.3)";
        tabShopify.style.background = "transparent";
        tabShopify.style.color = "#64748b";
        tabShopify.style.boxShadow = "none";
        if (panelShopify) panelShopify.style.display = "none";
        if (panelDropilo) panelDropilo.style.display = "block";
    }
}

// Dropilo file drag & drop handlers
function handleDropiloDragOver(e) { e.preventDefault(); e.stopPropagation(); e.currentTarget.style.borderColor = "#6366f1"; e.currentTarget.style.background = "rgba(99,102,241,0.12)"; }
function handleDropiloDragLeave(e) { e.preventDefault(); e.stopPropagation(); e.currentTarget.style.borderColor = "rgba(99,102,241,0.3)"; e.currentTarget.style.background = "linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.08))"; }
function handleDropiloDrop(e) {
    e.preventDefault(); e.stopPropagation();
    e.currentTarget.style.borderColor = "rgba(99,102,241,0.3)";
    e.currentTarget.style.background = "linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.08))";
    if (e.dataTransfer.files.length) handleDropiloFile(e.dataTransfer.files[0]);
}

function handleDropiloFileSelect(input) {
    if (input.files.length) handleDropiloFile(input.files[0]);
}

function handleDropiloFile(file) {
    if (!file.name.toLowerCase().endsWith(".xlsx")) {
        alert("Please select an .xlsx file.");
        return;
    }
    document.getElementById("dropilo-file-name").textContent = file.name;
    document.getElementById("dropilo-file-preview").style.display = "flex";
    document.getElementById("dropilo-dz-content").style.display = "none";
    document.getElementById("dropilo-submit-btn").disabled = false;
    
    var dt = new DataTransfer();
    dt.items.add(file);
    document.getElementById("dropilo-file-input").files = dt.files;
}

function removeDropiloFile(e) {
    if (e) { e.stopPropagation(); e.preventDefault(); }
    document.getElementById("dropilo-file-input").value = "";
    document.getElementById("dropilo-file-preview").style.display = "none";
    document.getElementById("dropilo-dz-content").style.display = "block";
    document.getElementById("dropilo-submit-btn").disabled = true;
}

// Auto-open Dropilo tab if we just imported
if (window.location.search.includes("dropilo_imported=1")) {
    setTimeout(function() { switchImportTab("dropilo"); }, 100);
}

function confirmDeleteOrders() {
    var bulkForm = document.getElementById("orders-bulk-form");
    var hiddenInput = document.createElement("input");
    hiddenInput.type = "hidden";
    hiddenInput.name = "bulk_action";
    hiddenInput.value = "delete";
    bulkForm.appendChild(hiddenInput);
    bulkForm.submit();
}

// Close modal on escape key
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        closeDeleteModal();
        closeTrackingModal();
        closeItemsModal();
    }
});

// Quick Track Modal Functions
function quickTrack(orderId) {
    var modal = document.getElementById(\'tracking-modal\');
    var content = document.getElementById(\'tracking-content\');
    modal.classList.add(\'active\');
    content.innerHTML = \'<div style="padding:3rem; text-align:center;"><div class="spinner"></div><p style="margin-top:1rem; color:#64748b;">Loading tracking info...</p></div>\';
    fetch(\'index.php?page=tracking-ajax&id=\' + orderId)
        .then(function(res) { return res.text(); })
        .then(function(html) { content.innerHTML = html; });
}

function closeTrackingModal(e) {
    if (e && e.target !== e.currentTarget) return;
    document.getElementById(\'tracking-modal\').classList.remove(\'active\');
}
</script>';

// Items Ordered Modal
$content .= '
<div id="items-modal" class="delete-modal-overlay" style="display:none;" onclick="closeItemsModal()">
    <div class="delete-modal-box" onclick="event.stopPropagation()">
        <div class="delete-modal-header" style="background: linear-gradient(135deg, #334155 0%, #1e293b 100%);">
            <div class="delete-warning-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="8" y1="6" x2="21" y2="6"></line>
                    <line x1="8" y1="12" x2="21" y2="12"></line>
                    <line x1="8" y1="18" x2="21" y2="18"></line>
                    <line x1="3" y1="6" x2="3.01" y2="6"></line>
                    <line x1="3" y1="12" x2="3.01" y2="12"></line>
                    <line x1="3" y1="18" x2="3.01" y2="18"></line>
                </svg>
            </div>
            <h3>Items Ordered Summary</h3>
        </div>
        <div class="delete-modal-body">
            <div style="background: rgba(255,255,255,0.05); border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 20px;">
                <div style="font-size: 0.9rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 8px;">Orders Selected</div>
                <div style="font-size: 2.5rem; font-weight: 800; color: #fff; line-height: 1;"><span id="items-order-count">0</span></div>
            </div>
            
            <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 12px; padding: 20px; text-align: center;">
                <div style="font-size: 0.9rem; color: #10b981; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; margin-bottom: 8px;">Total Items Ordered</div>
                <div style="font-size: 3rem; font-weight: 800; color: #10b981; line-height: 1;"><span id="items-total-count">0</span></div>
            </div>
        </div>
        <div class="delete-modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeItemsModal()" style="width: 100%;">Close</button>
        </div>
    </div>
</div>
';

// Custom Delete Confirmation Modal
$content .= '
<div id="delete-modal" class="delete-modal-overlay" style="display:none;">
    <div class="delete-modal-box">
        <div class="delete-modal-header">
            <div class="delete-warning-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h3>Delete Orders</h3>
        </div>
        <div class="delete-modal-body">
            <p class="delete-warning-text">You are about to permanently delete <strong><span id="delete-count">0</span> order(s)</strong>.</p>
            <div class="delete-warning-box">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span>This action <strong>CANNOT</strong> be undone. All associated line items and data will be permanently removed from the system.</span>
            </div>
        </div>
        <div class="delete-modal-footer">
            <button type="button" class="btn btn-cancel" onclick="closeDeleteModal()">Cancel</button>
            <button type="button" class="btn btn-delete" onclick="confirmDeleteOrders()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6l-2 14H7L5 6"></path>
                    <path d="M10 11v6"></path>
                    <path d="M14 11v6"></path>
                    <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"></path>
                </svg>
                Delete Permanently
            </button>
        </div>
    </div>
</div>

<style>
.delete-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from { transform: scale(0.9) translateY(-20px); opacity: 0; }
    to { transform: scale(1) translateY(0); opacity: 1; }
}

.delete-modal-box {
    background: #1a1a2e;
    border-radius: 16px;
    width: 90%;
    max-width: 440px;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.1);
    animation: slideIn 0.3s ease;
    overflow: hidden;
}

.delete-modal-header {
    background: linear-gradient(135deg, #dc3545 0%, #a71d2a 100%);
    padding: 24px;
    text-align: center;
    color: #fff;
}

.delete-warning-icon {
    margin-bottom: 12px;
}

.delete-warning-icon svg {
    stroke: #fff;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
}

.delete-modal-header h3 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 600;
    letter-spacing: -0.5px;
}

.delete-modal-body {
    padding: 24px;
    color: #ccc;
}

.delete-warning-text {
    font-size: 1.1rem;
    margin: 0 0 16px 0;
    text-align: center;
    color: #fff;
}

.delete-warning-text strong {
    color: #ff6b6b;
}

.delete-warning-box {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    background: rgba(220, 53, 69, 0.15);
    border: 1px solid rgba(220, 53, 69, 0.3);
    border-radius: 10px;
    padding: 14px 16px;
    font-size: 0.9rem;
    line-height: 1.5;
}

.delete-warning-box svg {
    flex-shrink: 0;
    stroke: #ff6b6b;
    margin-top: 2px;
}

.delete-warning-box strong {
    color: #ff6b6b;
}

.delete-modal-footer {
    display: flex;
    gap: 12px;
    padding: 20px 24px 24px;
    background: #16162a;
    border-top: 1px solid rgba(255,255,255,0.05);
}

.delete-modal-footer .btn {
    flex: 1;
    padding: 14px 20px;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.delete-modal-footer .btn-cancel {
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    color: #ccc;
}

.delete-modal-footer .btn-cancel:hover {
    background: rgba(255,255,255,0.15);
    color: #fff;
}

.delete-modal-footer .btn-delete {
    background: linear-gradient(135deg, #dc3545 0%, #c41c2d 100%);
    border: none;
    color: #fff;
    box-shadow: 0 4px 15px rgba(220, 53, 69, 0.4);
}

.delete-modal-footer .btn-delete:hover {
    background: linear-gradient(135deg, #e74c5a 0%, #dc3545 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(220, 53, 69, 0.5);
}

.delete-modal-footer .btn-delete:active {
    transform: translateY(0);
}

/* Tracking Modal Styles */
.tracking-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.tracking-modal-overlay.active {
    display: flex;
}

.tracking-modal-container {
    background: white;
    width: 90%;
    max-width: 500px;
    border-radius: 16px;
    position: relative;
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.3);
    max-height: 80vh;
    overflow-y: auto;
}

.tracking-modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    border: none;
    background: rgba(0,0,0,0.1);
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 1.25rem;
    cursor: pointer;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.tracking-modal-close:hover {
    background: rgba(0,0,0,0.15);
    color: #1e293b;
}

.spinner {
    border: 3px solid #f3f3f3;
    border-top: 3px solid #10b981;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    animation: spin 1s linear infinite;
    margin: auto;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>';

// Tracking Modal HTML
$content .= '
<div id="tracking-modal" class="tracking-modal-overlay" onclick="closeTrackingModal(event)">
    <div class="tracking-modal-container" onclick="event.stopPropagation()">
        <button class="tracking-modal-close" onclick="closeTrackingModal()">&times;</button>
        <div id="tracking-content"></div>
    </div>
</div>';

if ($currentPage === 'orders') {
    $editIconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    $fileIconSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>';

    $content .= '
    <!-- Import Modal -->
    <div id="import-modal" class="modal" style="display:' . (isset($_GET['import_open']) || isset($_GET['dropilo_imported']) ? 'flex' : 'none') . ';">
        <div class="modal-content">
            <div class="card-header">
                <h3>' . $fileIconSvg . ' Import Orders</h3>
                <button type="button" class="sidebar-close" onclick="closeImportModal()">×</button>
            </div>
            <div class="modal-body">
                ' . ($importResults['message'] ?? '') . '
                
                <!-- Import Mode Tabs -->
                <div class="import-tabs" style="display: flex; gap: 0; margin-bottom: 1.5rem; background: #f1f5f9; border-radius: 12px; padding: 4px;">
                    <button type="button" class="import-tab active" id="tab-shopify" onclick="switchImportTab(\x27shopify\x27)" style="flex: 1; padding: 0.65rem 1rem; border: none; border-radius: 10px; font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: all 0.2s; background: #fff; color: #1e293b; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: -3px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>Shopify CSV
                    </button>
                    <button type="button" class="import-tab" id="tab-dropilo" onclick="switchImportTab(\x27dropilo\x27)" style="flex: 1; padding: 0.65rem 1rem; border: none; border-radius: 10px; font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: all 0.2s; background: transparent; color: #64748b;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: -3px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>Dropilo File
                    </button>
                </div>

                <!-- Shopify CSV Import Panel -->
                <div id="panel-shopify" style="display: block;">
                <form method="post" enctype="multipart/form-data" id="import-form">
                    <input type="hidden" name="import_orders" value="1">
                    <div class="form-group">
                        <label>Target Shop</label>
                        <select name="shop_id" class="select" style="width:100%;" required>';
    foreach ($shops as $s) {
        $sel = ($importResults['shop_id'] ?? 0) == $s['id'] ? ' selected' : '';
        $content .= '<option value="' . $s['id'] . '"' . $sel . '>' . htmlspecialchars($s['name']) . '</option>';
    }
    $content .= '       </select>
                    </div>
                    <div class="dropzone" id="import-dropzone" onclick="document.getElementById(\'import-csv\').click()" ondrop="handleImportDrop(event)" ondragover="handleImportDragOver(event)" ondragleave="handleImportDragLeave(event)">
                        <div class="dropzone-content">
                            <div class="dropzone-icon" style="font-size:3rem; opacity:0.4;">📤</div>
                            <p class="dropzone-text">Drag & drop Shopify CSV</p>
                            <p class="dropzone-subtext">or click to browse files</p>
                        </div>
                        <input type="file" name="csv" id="import-csv" accept=".csv" style="display:none;" onchange="handleImportFileSelect(this)">
                        <div id="import-file-preview" style="display:none;" class="file-preview">
                            <div class="file-info">
                                <span class="file-name" id="import-file-name"></span>
                                <button type="button" class="file-remove" onclick="removeImportFile(event)">×</button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="import-progress" style="display:none; margin-top:1.5rem;">
                        <div style="height:8px; background:#eee; border-radius:10px; overflow:hidden;">
                            <div id="import-bar" style="height:100%; background:var(--cart-green); width:0%; transition:width 0.3s;"></div>
                        </div>
                        <p id="import-progress-text" style="text-align:center; font-size:0.85rem; margin-top:0.5rem; color:#666;">0%</p>
                    </div>

                    <div style="margin-top:2rem; display:flex; gap:1rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-cancel" onclick="closeImportModal()">Cancel</button>
                        <button type="submit" class="btn btn-save" id="import-submit-btn" disabled>Start Import</button>
                    </div>
                </form>';

    if (!empty($importResults['orders'])) {
        $content .= '<div class="import-results-summary" style="margin-top:2rem; padding-top:1.5rem; border-top:1px solid #eee;">
            <h4 style="margin-bottom:1rem;">Imported Orders (' . count($importResults['orders']) . ')</h4>
            <select class="select" style="width:100%;" onchange="if(this.value) window.location.href=\'index.php?page=order-view&id=\'+this.value">
                <option value="">Choose an order to view detail...</option>';
        foreach ($importResults['orders'] as $io) {
            $content .= '<option value="' . $io['id'] . '">' . htmlspecialchars($io['name']) . '</option>';
        }
        $content .= '</select>';
        
        // Errors (Missing Data) for this batch
        $ids = array_column($importResults['orders'], 'id');
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $st = $app->pdo->prepare("SELECT id, name, billing_name, billing_phone, shipping_address FROM orders WHERE id IN ($placeholders) AND (billing_name IS NULL OR billing_name = '' OR billing_phone IS NULL OR billing_phone = '' OR shipping_address IS NULL OR shipping_address = '')");
            $st->execute($ids);
            $errors = $st->fetchAll();
            if (!empty($errors)) {
                $content .= '<div class="import-errors" style="margin-top:1.5rem;">
                    <div style="color:var(--cart-red); font-weight:700; font-size:0.9rem; margin-bottom:0.75rem; text-transform:uppercase; letter-spacing:0.5px;">⚠️ Needs fixing</div>
                    <div class="missing-items-container" style="max-height:180px; overflow-y:auto; border:1px solid #f8d7da; background:#fff8f8;">';
                foreach ($errors as $err) {
                    $content .= '<div class="missing-item" style="padding:0.75rem; border-bottom:1px solid #fceef0; display:flex; justify-content:space-between; align-items:center;">
                        <div class="missing-item-info"><span style="font-weight:700;">' . htmlspecialchars($err['name']) . '</span></div>
                        <button type="button" class="btn btn-sm btn-modify" onclick="openFixSidebarOrder(this)" 
                            data-order-id="' . $err['id'] . '" 
                            data-order-name="' . htmlspecialchars($err['name'], ENT_QUOTES) . '" 
                            data-billing-name="' . htmlspecialchars($err['billing_name'] ?? '', ENT_QUOTES) . '" 
                            data-billing-phone="' . htmlspecialchars($err['billing_phone'] ?? '', ENT_QUOTES) . '" 
                            data-shipping-address="' . htmlspecialchars($err['shipping_address'] ?? '', ENT_QUOTES) . '">Fix Now</button>
                    </div>';
                }
                $content .= '</div></div>';
            }
        }
        $content .= '</div>';
    }

    $content .= '</div>

                <!-- Dropilo File Import Panel -->
                <div id="panel-dropilo" style="display: none;">
                <form method="post" enctype="multipart/form-data" id="dropilo-import-form">
                    <input type="hidden" name="dropilo_import" value="1">
                    <div class="form-group">
                        <label>Target Shop</label>
                        <select name="shop_id" class="select" style="width:100%;" required>';
    foreach ($shops as $s) {
        $content .= '<option value="' . $s['id'] . '">' . htmlspecialchars($s['name']) . '</option>';
    }
    $content .= '       </select>
                    </div>

                    <div style="background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(139,92,246,0.08)); border: 2px dashed rgba(99,102,241,0.3); border-radius: 16px; padding: 2rem; text-align: center; cursor: pointer; transition: all 0.2s;" id="dropilo-dropzone" onclick="document.getElementById(\x27dropilo-file-input\x27).click()" ondrop="handleDropiloDrop(event)" ondragover="handleDropiloDragOver(event)" ondragleave="handleDropiloDragLeave(event)">
                        <div class="dropzone-content" id="dropilo-dz-content">
                            <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">📦</div>
                            <p style="font-weight: 700; color: #6366f1; margin: 0 0 0.25rem;">Drag & drop an .xlsx file</p>
                            <p style="font-size: 0.85rem; color: #94a3b8; margin: 0;">Exported from another Dropilo user</p>
                        </div>
                        <input type="file" name="dropilo_file" id="dropilo-file-input" accept=".xlsx" style="display:none;" onchange="handleDropiloFileSelect(this)">
                        <div id="dropilo-file-preview" style="display:none;">
                            <div style="display: flex; align-items: center; gap: 1rem; justify-content: center;">
                                <div style="width:48px; height:48px; background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 12px; display:flex; align-items:center; justify-content:center;">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                                </div>
                                <div style="text-align: left;">
                                    <div style="font-weight: 700; color: #1e293b;" id="dropilo-file-name"></div>
                                    <div style="font-size: 0.8rem; color: #94a3b8; font-weight: 600;">Dropilo Export File</div>
                                </div>
                                <button type="button" style="background: #fee2e2; border: none; color: #ef4444; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; display: flex; align-items: center; justify-content: center;" onclick="removeDropiloFile(event)">×</button>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:2rem; display:flex; gap:1rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-cancel" onclick="closeImportModal()">Cancel</button>
                        <button type="submit" class="btn" id="dropilo-submit-btn" disabled style="background: linear-gradient(135deg, #6366f1, #8b5cf6); color:#fff; border:none;">Import Dropilo File</button>
                    </div>
                </form>
                </div>

            </div>
        </div>
    </div>
    
    <!-- Fix Sidebar (Universal) -->
    <div class="sidebar-overlay" id="fix-overlay" onclick="closeFixSidebar()"></div>
    <div class="fix-sidebar" id="fix-sidebar">
        <div class="sidebar-header-fix">
            <h3 style="display:flex; align-items:center; gap:0.5rem;">' . $editIconSvg . ' Review Order Data</h3>
            <button type="button" class="sidebar-close" onclick="closeFixSidebar()">×</button>
        </div>
        <div class="sidebar-content">
            <form method="post" id="fix-order-form">
                <input type="hidden" name="fix_order" value="1">
                <input type="hidden" name="order_id" id="fix-order-id">
                <div class="form-group"><label>Reference</label><input type="text" id="fix-order-name" readonly style="background:#f9f9f9; color:#888;"></div>
                <div class="form-group"><label>Customer Name</label><input type="text" name="billing_name" id="fix-order-billing-name" class="form-control" placeholder="Full name"></div>
                <div class="form-group"><label>Phone Number</label><input type="text" name="billing_phone" id="fix-order-billing-phone" class="form-control" placeholder="+216 ..."></div>
                <div class="form-group"><label>Full Address</label><input type="text" name="shipping_address" id="fix-order-shipping-address" class="form-control" placeholder="City, Street ..."></div>
                <div style="margin-top:2rem;">
                    <button type="submit" class="btn btn-save btn-full">Save & Validate</button>
                </div>
            </form>
        </div>
    </div>';
}

// Add the Open Package Permission Modal for Fiabilo
$content .= '
<div id="open-package-modal" class="delete-modal-overlay" style="display:none; z-index: 10000;">
    <div class="delete-modal-box" style="max-width: 400px; padding-bottom: 0;">
        <div class="delete-modal-header" style="background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); padding: 20px;">
            <div class="delete-warning-icon" style="margin-bottom: 8px;">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                </svg>
            </div>
            <h3 style="font-size: 1.25rem;">Package Permission</h3>
        </div>
        <div class="delete-modal-body" style="padding: 24px 24px 16px;">
            <p class="delete-warning-text" style="color: #fff; font-size: 1.05rem; margin-bottom: 0; font-weight: 500;">
                Can the customer open this package before paying?
            </p>
        </div>
        <div class="delete-modal-footer" style="background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; padding: 20px 24px;">
            <button type="button" class="btn" style="flex: 1; background: #fff; border: 1px solid #cbd5e1; color: #475569; padding: 12px; font-weight: 600; border-radius: 8px; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background=\'#f1f5f9\'" onmouseout="this.style.background=\'#fff\'" onclick="submitFiabiloOrder(0)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: 8px;">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
                No
            </button>
            <button type="button" class="btn" style="flex: 1; background: #0ea5e9; border: none; color: #fff; padding: 12px; font-weight: 600; border-radius: 8px; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; box-shadow: 0 4px 12px rgba(14, 165, 233, 0.3);" onmouseover="this.style.background=\'#0284c7\'; this.style.transform=\'translateY(-1px)\'" onmouseout="this.style.background=\'#0ea5e9\'; this.style.transform=\'none\'" onclick="submitFiabiloOrder(1)">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right: 8px;">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                Yes
            </button>
        </div>
        <div style="background: #f8fafc; padding: 0 24px 24px; text-align: center; border-radius: 0 0 16px 16px;">
            <button type="button" style="background: none; border: none; color: #94a3b8; cursor: pointer; text-decoration: underline; font-size: 0.9rem; padding: 5px;" onmouseover="this.style.color=\'#64748b\'" onmouseout="this.style.color=\'#94a3b8\'" onclick="closeOpenPackageModal()">Cancel Action</button>
        </div>
    </div>
</div>

<script>
let pendingFiabiloForm = null;
let pendingFiabiloAction = null;

function closeOpenPackageModal() {
    document.getElementById("open-package-modal").style.display = "none";
    document.body.style.overflow = ""; // restore scrolling
    pendingFiabiloForm = null;
    pendingFiabiloAction = null;
}

function submitFiabiloOrder(permission) {
    document.getElementById("post_open_package_permission").value = permission;
    
    if (pendingFiabiloAction === "bulk") {
        var hiddenInput = document.createElement("input");
        hiddenInput.type = "hidden";
        hiddenInput.name = "bulk_action";
        hiddenInput.value = "shipping";
        pendingFiabiloForm.appendChild(hiddenInput);
    }
    
    document.body.style.pointerEvents = "none"; // prevent double clicks
    pendingFiabiloForm.submit();
}

document.addEventListener("DOMContentLoaded", function() {
    // Intercept general bulk assign to shipping button (when fiabilo is the default/only choice here or assuming we want to ask for all)
    // We only prompt if it\'s a bulk action without a specific provider select, or if a provider select exists and it\'s fiabilo.
    var bulkShippingBtn = document.querySelector(\'button[name="bulk_action"][value="shipping"]\');
    var ordersBulkForm = document.getElementById("orders-bulk-form");
    
    if (bulkShippingBtn && ordersBulkForm) {
        bulkShippingBtn.addEventListener("click", function(e) {
            var selectedCount = document.querySelectorAll(".order-cb:checked").length;
            if (selectedCount === 0) return;
            
            e.preventDefault();
            pendingFiabiloForm = ordersBulkForm;
            pendingFiabiloAction = "bulk";
            document.body.style.overflow = "hidden"; // Prevent scrolling while modal is open
            document.getElementById("open-package-modal").style.display = "flex";
        });
    }

    // Intercept toolbar send order button (on confirmed orders page where they choose provider)
    var toolbarShippingBtn = document.querySelector(\'button[name="send_to_shipping"]\');
    var toolbarProviderSelect = document.querySelector(\'select[name="shipping"]\');
    
    if (toolbarShippingBtn && ordersBulkForm && toolbarProviderSelect) {
        toolbarShippingBtn.addEventListener("click", function(e) {
            var selectedCount = document.querySelectorAll(".order-cb:checked").length;
            if (selectedCount === 0) return;

            // Only trigger popup for Fiabilo
            if (toolbarProviderSelect.value === "fiabilo") {
                e.preventDefault();
                pendingFiabiloForm = ordersBulkForm;
                pendingFiabiloAction = "toolbar";
                
                // Add the hidden input that this button would have sent
                var hiddenSend = document.createElement("input");
                hiddenSend.type = "hidden";
                hiddenSend.name = "send_to_shipping";
                hiddenSend.value = "1";
                pendingFiabiloForm.appendChild(hiddenSend);

                document.body.style.overflow = "hidden";
                document.getElementById("open-package-modal").style.display = "flex";
            }
        });
    }
});
</script>
';

require $base . '/layouts/layout.php';

