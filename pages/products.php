<?php
$uid = (int) $_SESSION['user_id'];

// AJAX: Quick Cost Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_cost'])) {
    $productId = (int)($_POST['product_id'] ?? 0);
    $cost = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float) str_replace(',', '.', $_POST['cost']) : null;
    
    $st = $app->pdo->prepare('SELECT p.id FROM products p JOIN shops s ON p.shop_id = s.id WHERE p.id = ? AND s.user_id = ?');
    $st->execute([$productId, $uid]);
    if ($st->fetch()) {
        $app->pdo->prepare('UPDATE products SET cost = ? WHERE id = ?')->execute([$cost, $productId]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'cost' => $cost]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

// Product import
if (($page ?? '') === 'product-import') {
    $currentPage = 'product-import';
    $pageTitle = 'Import products';
    $message = '';
    $shopId = (int) ($_GET['shop_id'] ?? $_POST['shop_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK) {
        $shopId = (int) ($_POST['shop_id'] ?? 0);
        $st = $app->pdo->prepare('SELECT id FROM shops WHERE id = ? AND user_id = ?');
        $st->execute([$shopId, $uid]);
        if (!$st->fetch()) {
            $message = '<div class="alert alert-error">Invalid shop.</div>';
        } else {
            try {
                $path = $_FILES['csv']['tmp_name'];
                $productsArr = ProductImport::parse($path);
                $ins = $app->pdo->prepare('INSERT INTO products (shop_id, handle, title, body_html, vendor, type, variant_sku, variant_price, image_src, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $checkDup = $app->pdo->prepare('SELECT id FROM products WHERE shop_id = ? AND handle = ?');
                $imported = 0;
                $skipped = 0;
                foreach ($productsArr as $p) {
                    if (($p['title'] ?? '') === '') {
                        $skipped++;
                        continue;
                    }
                    $handle = trim($p['handle'] ?? '');
                    if ($handle === '') {
                        $skipped++;
                        continue; 
                    }
                    $checkDup->execute([$shopId, $handle]);
                    if ($checkDup->fetch()) {
                        $skipped++;
                        continue; 
                    }
                    $ins->execute([
                        $shopId,
                        $p['handle'] ?? null,
                        $p['title'],
                        $p['body_html'] ?? null,
                        $p['vendor'] ?? null,
                        $p['type'] ?? null,
                        $p['variant_sku'] ?? null,
                        $p['variant_price'],
                        $p['image_src'] ?? null,
                        $p['status'] ?? 'active',
                    ]);
                    $imported++;
                }
                $msgText = 'Imported ' . $imported . ' products.';
                if ($skipped > 0) {
                    $msgText .= ' Skipped ' . $skipped . ' duplicate(s).';
                }
                $message = '<div class="alert alert-success">' . htmlspecialchars($msgText) . '</div>';
            } catch (Throwable $e) {
                $message = '<div class="alert alert-error">' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
    
    // Handle fix product missing info
    $missingProducts = [];
    if ($shopId > 0) {
        $st = $app->pdo->prepare('SELECT id, title, image_src, variant_price, cost FROM products WHERE shop_id = ? AND (image_src IS NULL OR image_src = "" OR variant_price IS NULL OR cost IS NULL) ORDER BY title LIMIT 50');
        $st->execute([$shopId]);
        $missingProducts = $st->fetchAll();
    }
    
    // Handle fix product POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_product'])) {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $imageSrc = trim($_POST['image_src'] ?? '');
        $variantPrice = isset($_POST['variant_price']) && $_POST['variant_price'] !== '' ? (float) str_replace(',', '.', $_POST['variant_price']) : null;
        $cost = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float) str_replace(',', '.', $_POST['cost']) : null;
        $st = $app->pdo->prepare('SELECT p.id, p.image_src, p.variant_price, p.cost FROM products p JOIN shops s ON p.shop_id = s.id WHERE p.id = ? AND s.user_id = ?');
        $st->execute([$productId, $uid]);
        $existing = $st->fetch();
        if ($existing) {
            $imageSrc = $imageSrc !== '' ? $imageSrc : ($existing['image_src'] ?? null);
            if ($variantPrice === null && $existing['variant_price'] !== null) {
                $variantPrice = (float) $existing['variant_price'];
            }
            if ($cost === null && $existing['cost'] !== null) {
                $cost = (float) $existing['cost'];
            }
            $updateSt = $app->pdo->prepare('UPDATE products SET image_src = ?, variant_price = ?, cost = ? WHERE id = ?');
            $updateSt->execute([$imageSrc ?: null, $variantPrice, $cost, $productId]);
            $message = '<div class="alert alert-success">Product updated successfully.</div>';
            // Refresh missing products list
            $st = $app->pdo->prepare('SELECT id, title, image_src, variant_price, cost FROM products WHERE shop_id = ? AND (image_src IS NULL OR image_src = "" OR variant_price IS NULL OR cost IS NULL) ORDER BY title LIMIT 50');
            $st->execute([$shopId]);
            $missingProducts = $st->fetchAll();
        }
    }

    $shops = $app->pdo->prepare('SELECT id, name FROM shops WHERE user_id = ? ORDER BY name');
    $shops->execute([$uid]);
    $shops = $shops->fetchAll();
    $packageIconSvg = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>';
    $content = '
<div class="settings-header">
    <div class="header-main">
        <h1>Import products</h1>
        <div class="header-line"></div>
    </div>
    <p class="header-subtitle">Bulk import your products using a Shopify-formatted CSV file.</p>
</div>' . $message;
    $content .= '<div class="card"><p style="margin-bottom: 1.5rem; color: #666;">Upload a Shopify products export CSV to import products into your shop.</p>';
    $content .= '<form method="post" enctype="multipart/form-data" class="upload-form" id="upload-form">';
    $content .= '<div class="form-group"><label>Shop</label><select name="shop_id" id="shop-select" class="form-control" required>';
    foreach ($shops as $s) {
        $sel = $s['id'] == $shopId ? ' selected' : '';
        $content .= '<option value="' . $s['id'] . '"' . $sel . '>' . htmlspecialchars($s['name']) . '</option>';
    }
    $content .= '</select></div>';
    $content .= '<div class="form-group"><label>Shopify products CSV</label>';
    $content .= '<div class="dropzone" id="dropzone" style="min-height: 120px; padding: 1.5rem;" ondrop="handleDrop(event)" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)" onclick="document.getElementById(\'csv-input\').click()">';
    $content .= '<div class="dropzone-content">';
    $content .= '<div class="dropzone-icon" style="width: 48px; height: 48px; color: #666; margin: 0 auto 0.5rem;">' . $packageIconSvg . '</div>';
    $content .= '<p class="dropzone-text">Drag and drop your CSV file here</p>';
    $content .= '<input type="file" name="csv" id="csv-input" accept=".csv" style="display:none;" onchange="handleFileSelect(this)">';
    $content .= '</div>';
    $content .= '<div class="file-preview" id="file-preview" style="display:none;">';
    $content .= '<div class="file-info">';
    $content .= '<span class="file-icon" style="width: 24px; height: 24px; color: #666;">' . $packageIconSvg . '</span>';
    $content .= '<div class="file-details">';
    $content .= '<span class="file-name" id="file-name"></span>';
    $content .= '<span class="file-size" id="file-size"></span>';
    $content .= '</div>';
    $content .= '<button type="button" class="file-remove" onclick="removeFile()" title="Remove file">×</button>';
    $content .= '</div>';
    $content .= '</div></div>';
    $content .= '<div style="margin-top: 1rem; display: flex; gap: 0.75rem; justify-content: center;"><label for="csv-input" class="btn btn-browse-outside">Browse files</label>';
    $content .= '<button type="submit" class="btn" id="submit-btn" disabled>Import</button></div></form>';
    
    // Filter section
    if (count($missingProducts) > 0) {
        $content .= '<div class="filter-section"><div class="filter-label">Filter</div><div class="missing-items-container">';
        foreach ($missingProducts as $mp) {
            $missingFields = [];
            if (empty($mp['image_src'])) $missingFields[] = 'Photo';
            if ($mp['variant_price'] === null) $missingFields[] = 'Price';
            if ($mp['cost'] === null) $missingFields[] = 'Cost';
            $content .= '<div class="missing-item"><div class="missing-item-info"><div class="missing-item-title">' . htmlspecialchars($mp['title']) . '</div><div class="missing-item-badges">';
            foreach ($missingFields as $field) {
                $content .= '<span class="missing-badge">' . htmlspecialchars($field) . '</span>';
            }
            $content .= '</div></div><button type="button" class="btn btn-fix" data-product-id="' . $mp['id'] . '" data-product-title="' . htmlspecialchars($mp['title'], ENT_QUOTES) . '" data-product-image="' . htmlspecialchars($mp['image_src'] ?? '', ENT_QUOTES) . '" data-product-price="' . htmlspecialchars($mp['variant_price'] ?? '', ENT_QUOTES) . '" data-product-cost="' . htmlspecialchars($mp['cost'] ?? '', ENT_QUOTES) . '" onclick="openFixSidebarProduct(this)">Fix now</button></div>';
        }
        $content .= '</div></div>';
    }
    $content .= '</div>';
    // Fix sidebar
    $editIconSvg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    $content .= '<div class="sidebar-overlay" id="fix-overlay" onclick="closeFixSidebar()"></div><div class="fix-sidebar" id="fix-sidebar"><div class="sidebar-header-fix"><h3 style="display: flex; align-items: center; gap: 0.5rem;"><span style="width: 20px; height: 20px; color: #666;">' . $editIconSvg . '</span>Fix Product</h3><button type="button" class="sidebar-close" onclick="closeFixSidebar()">×</button></div><div class="sidebar-content"><form method="post" id="fix-product-form"><input type="hidden" name="fix_product" value="1"><input type="hidden" name="product_id" id="fix-product-id"><input type="hidden" name="shop_id" value="' . $shopId . '"><div class="form-group"><label>Product Name</label><input type="text" id="fix-product-title" readonly style="background: #f5f5f5;"></div><div class="form-group"><label>Photo URL</label><input type="url" name="image_src" id="fix-product-image" placeholder="https://..."></div><div class="form-group"><label>Price</label><input type="number" step="0.01" min="0" name="variant_price" id="fix-product-price" placeholder="0.00"></div><div class="form-group"><label>Cost</label><input type="number" step="0.01" min="0" name="cost" id="fix-product-cost" placeholder="0.00"></div><button type="submit" class="btn">Save</button></form></div></div>';
    
    $content .= '<div id="upload-progress" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center;flex-direction:column;gap:1rem;"><div style="background:white;padding:2rem;border-radius:8px;min-width:300px;text-align:center;"><div style="font-size:1.1rem;margin-bottom:1rem;font-weight:500;">Importing products...</div><div style="width:100%;height:8px;background:#e0e0e0;border-radius:4px;overflow:hidden;"><div id="progress-bar" style="height:100%;background:#4CAF50;width:0%;transition:width 0.3s ease;"></div></div><div id="progress-text" style="margin-top:0.5rem;color:#666;font-size:0.9rem;">0%</div></div></div>';
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
function openFixSidebarProduct(btn) {
  var id = btn.getAttribute("data-product-id");
  var title = btn.getAttribute("data-product-title") || "";
  var image = btn.getAttribute("data-product-image") || "";
  var price = btn.getAttribute("data-product-price") || "";
  var cost = btn.getAttribute("data-product-cost") || "";
  document.getElementById("fix-product-id").value = id;
  document.getElementById("fix-product-title").value = title;
  document.getElementById("fix-product-image").value = image;
  document.getElementById("fix-product-price").value = price;
  document.getElementById("fix-product-cost").value = cost;
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
    alert("Please select a CSV file to import.");
    return false;
  }
  showProgress();
  return true;
});
</script>';
    require $base . '/layouts/layout.php';
    return;
}

// Single product view (with cost update)
if (($page ?? '') === 'product-view') {
    $id = (int) ($_GET['id'] ?? 0);
    $st = $app->pdo->prepare('SELECT p.*, s.name AS shop_name FROM products p JOIN shops s ON p.shop_id = s.id WHERE p.id = ? AND s.user_id = ?');
    $st->execute([$id, $uid]);
    $p = $st->fetch();
    if (!$p) {
        header('Location: index.php?page=products');
        exit;
    }
    $costMessage = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cost'])) {
        $cost = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float) str_replace(',', '.', $_POST['cost']) : null;
        $app->pdo->prepare('UPDATE products SET cost = ? WHERE id = ?')->execute([$cost, $id]);
        $p['cost'] = $cost;
        $costMessage = '<div class="alert alert-success">Cost updated.</div>';
    }
    $currentPage = 'products';
    $pageTitle = $p['title'];
    $img = $p['image_src'] ? '<img src="' . htmlspecialchars($p['image_src']) . '" alt="" style="max-width:300px;">' : '';
    $content = '<h1>' . htmlspecialchars($p['title']) . '</h1><p>Shop: ' . htmlspecialchars($p['shop_name']) . '</p>' . $img;
    $content .= '<div class="card">' . $costMessage;
    $content .= '<p><strong>Price:</strong> ' . ($p['variant_price'] !== null ? number_format((float)$p['variant_price'], 2) : '—') . '</p>';
    $content .= '<p><strong>Vendor:</strong> ' . htmlspecialchars($p['vendor'] ?? '—') . '</p>';
    $content .= '<form method="post" style="margin-top:1rem;"><div class="form-group"><label for="cost">Cost per item</label><input type="number" step="0.01" min="0" id="cost" name="cost" value="' . ($p['cost'] !== null ? htmlspecialchars((string)$p['cost']) : '') . '" placeholder="0.00"></div><button type="submit" class="btn">Update cost</button></form>';
    $content .= '<p style="margin-top:1rem;"><a href="index.php?page=products">Back to products</a></p></div>';
    require $base . '/layouts/layout.php';
    return;
}

// All products list
$currentPage = 'products';
$pageTitle = 'All products';
$filterShop = (int) ($_GET['shop_id'] ?? 0);
$pageNum = max(1, (int)($_GET['p'] ?? 1));
$perPage = 12; // Slightly more for the grid
$offset = ($pageNum - 1) * $perPage;

OrderPricing::restoreProductPricesFromOrders($app->pdo, $uid);

// Count total
$countSql = 'SELECT COUNT(*) FROM products p JOIN shops s ON p.shop_id = s.id WHERE s.user_id = ?';
$countParams = [$uid];
if ($filterShop > 0) {
    $countSql .= ' AND p.shop_id = ?';
    $countParams[] = $filterShop;
}
$countSt = $app->pdo->prepare($countSql);
$countSt->execute($countParams);
$totalCount = (int) $countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

// Get products
$sql = 'SELECT p.*, s.name AS shop_name, s.id AS shop_id FROM products p JOIN shops s ON p.shop_id = s.id WHERE s.user_id = ?';
$params = [$uid];
if ($filterShop > 0) {
    $sql .= ' AND p.shop_id = ?';
    $params[] = $filterShop;
}
$sql .= ' ORDER BY p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$st = $app->pdo->prepare($sql);
$st->execute($params);
$products = $st->fetchAll();

$shops = $app->pdo->prepare('SELECT id, name FROM shops WHERE user_id = ? ORDER BY name');
$shops->execute([$uid]);
$shops = $shops->fetchAll();

$content = '
<style>
    @import url(\'https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Syne:wght@400..800&display=swap\');

    :root {
        --page-bg: #f5f5f4;
        --surface: #ffffff;
        --border-default: #e7e5e4;
        --border-hover: #d4d0cb;
        --accent-green: #00b37e;
        --text-primary: #1c1917;
        --text-secondary: #57534e;
        --text-muted: #a8a29e;
        
        --radius-lg: 14px;
        --radius-md: 8px;
        --radius-sm: 6px;
        
        --shadow-subtle: 0 1px 3px rgba(0,0,0,.07);
        --shadow-elevated: 0 8px 28px rgba(0,0,0,.10);
        
        --font-heading: \'Syne\', sans-serif;
        --font-body: \'DM Sans\', sans-serif;
        --font-mono: \'DM Mono\', monospace;
    }

    /* Core Layout */
    .inventory-page { 
        background-color: var(--page-bg);
        min-height: 100vh;
        font-family: var(--font-body);
        color: var(--text-primary);
    }

    /* Sticky Header */
    .inventory-header {
        position: sticky;
        top: 0;
        z-index: 100;
        background: rgba(245, 245, 244, 0.9);
        backdrop-filter: blur(8px);
        padding: 1.5rem 0;
        border-bottom: 1px solid var(--border-default);
        margin-bottom: 1.5rem;
    }
    .header-content {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .header-titles h1 {
        font-family: var(--font-heading);
        font-size: 2rem;
        font-weight: 800;
        margin: 0;
        letter-spacing: -0.02em;
    }
    .header-titles p {
        color: var(--text-muted);
        margin: 0.25rem 0 0;
        font-size: 0.95rem;
    }
    .header-actions {
        display: flex;
        gap: 0.75rem;
    }

    /* Buttons */
    .btn-ghost {
        background: transparent;
        border: 1px solid var(--border-default);
        color: var(--text-primary);
        font-weight: 600;
        padding: 0.6rem 1.25rem;
        border-radius: var(--radius-md);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 180ms ease;
    }
    .btn-ghost:hover {
        background: #fdfdfd;
        border-color: var(--border-hover);
        box-shadow: var(--shadow-subtle);
    }
    .btn-primary-green {
        background: var(--accent-green);
        color: #fff !important;
        font-weight: 600;
        padding: 0.6rem 1.25rem;
        border-radius: var(--radius-md);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 180ms ease;
        border: none;
        cursor: pointer;
    }
    .btn-primary-green:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 179, 126, 0.2);
        filter: brightness(1.05);
    }

    /* Toolbar */
    .inventory-toolbar {
        max-width: 1400px;
        margin: 0 auto 2rem;
        padding: 0 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 2rem;
    }
    .toolbar-left, .toolbar-right {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .search-wrapper {
        position: relative;
        flex: 1;
        min-width: 280px;
    }
    .search-input {
        width: 100%;
        background: #fff;
        border: 1px solid var(--border-default);
        padding: 0.6rem 1rem 0.6rem 2.5rem;
        border-radius: 99px;
        font-size: 0.9rem;
        transition: all 180ms ease;
    }
    .search-input:focus {
        outline: none;
        border-color: var(--accent-green);
        box-shadow: 0 0 0 3px rgba(0, 179, 126, 0.1);
    }
    .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
    }

    .toolbar-select {
        border: 1px solid var(--border-default);
        padding: 0.6rem 1rem;
        border-radius: var(--radius-md);
        background: #fff;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-secondary);
        cursor: pointer;
    }

    /* Filter Chips */
    .filter-chips {
        display: flex;
        gap: 0.5rem;
    }
    .chip {
        padding: 0.4rem 1rem;
        border-radius: 99px;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 180ms ease;
        border: 1px solid var(--border-default);
        background: transparent;
        color: var(--text-secondary);
    }
    .chip.active {
        background: var(--text-primary);
        color: #fff;
        border-color: var(--text-primary);
    }
    .chip:not(.active):hover {
        border-color: var(--border-hover);
        background: #fff;
    }

    /* View Toggle */
    .view-toggle {
        display: flex;
        background: #eeedec;
        padding: 3px;
        border-radius: var(--radius-md);
    }
    .toggle-btn {
        padding: 0.4rem;
        border: none;
        background: transparent;
        border-radius: 6px;
        cursor: pointer;
        color: var(--text-muted);
        display: flex;
        align-items: center;
        transition: all 180ms ease;
    }
    .toggle-btn.active {
        background: #fff;
        color: var(--text-primary);
        box-shadow: var(--shadow-subtle);
    }

    /* Product Grid */
    .product-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.5rem;
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
    }

    /* Responsive Grid */
    @media (max-width: 1200px) { .product-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 900px) { .product-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 600px) { .product-grid { grid-template-columns: 1fr; } }

    /* Card Styling */
    .product-card {
        background: var(--surface);
        border: 1px solid var(--border-default);
        border-radius: var(--radius-lg);
        overflow: hidden;
        transition: all 180ms ease;
        display: flex;
        flex-direction: column;
        box-shadow: var(--shadow-subtle);
        opacity: 0;
        transform: translateY(10px);
        animation: cardFadeIn 400ms ease forwards;
    }
    @keyframes cardFadeIn {
        to { opacity: 1; transform: translateY(0); }
    }

    .product-card:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow-elevated);
        border-color: var(--d4d0cb);
    }

    .card-image-area {
        position: relative;
        background: #f5f5f5;
        height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        overflow: hidden;
    }
    .card-image-area img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        transition: transform 180ms ease;
    }
    .product-card:hover .card-image-area img {
        transform: scale(1.05);
    }

    .badge-float {
        position: absolute;
        top: 0.75rem;
        left: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 4px;
        z-index: 2;
    }
    .shop-badge {
        background: #fff;
        border: 1px solid var(--accent-green);
        color: var(--accent-green);
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }
    .status-badge {
        font-size: 10px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 4px;
        text-transform: uppercase;
    }
    .status-badge.margin { background: #dcfce7; color: #166534; }
    .status-badge.no-cost { background: #fee2e2; color: #991b1b; }

    .card-actions-hover {
        position: absolute;
        bottom: 0.75rem;
        right: 0.75rem;
        display: flex;
        gap: 0.5rem;
        opacity: 0;
        transform: translateY(4px);
        transition: all 180ms ease;
        z-index: 2;
    }
    .product-card:hover .card-actions-hover {
        opacity: 1;
        transform: translateY(0);
    }
    .icon-btn {
        width: 32px;
        height: 32px;
        background: #fff;
        border: 1px solid var(--border-default);
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: var(--text-secondary);
        transition: all 180ms ease;
    }
    .icon-btn:hover {
        border-color: var(--text-primary);
        color: var(--text-primary);
    }

    .card-body {
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .card-shop-label {
        font-size: 10px;
        font-weight: 700;
        color: var(--accent-green);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
    }
    .card-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 1.25rem;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        height: 2.8em;
    }

    .pricing-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid #f2f1f0;
    }
    .pricing-item .label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        margin-bottom: 2px;
    }
    .price-val {
        font-family: var(--font-heading);
        font-size: 1.25rem;
        font-weight: 800;
        color: var(--text-primary);
    }
    .price-val span {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-left: 2px;
    }
    .margin-val {
        font-family: var(--font-mono);
        font-size: 1rem;
        font-weight: 600;
    }
    .margin-healthy { color: #007a54; }
    .margin-low { color: #f59e0b; }
    .margin-missing { color: var(--text-muted); }

    .quick-cost-area {
        margin-bottom: 1.5rem;
    }
    .quick-cost-label {
        display: block;
        font-size: 0.7rem;
        font-weight: 800;
        color: var(--text-muted);
        text-transform: uppercase;
        margin-bottom: 0.5rem;
    }
    .cost-input-group {
        display: flex;
        gap: 0.5rem;
    }
    .cost-input {
        flex: 1;
        background: #fafaf9;
        border: 1px solid var(--border-default);
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        font-family: var(--font-mono);
        font-size: 0.9rem;
        font-weight: 600;
        transition: all 180ms ease;
    }
    .cost-input:focus {
        outline: none;
        background: #fff;
        border-color: var(--accent-green);
        box-shadow: 0 0 0 3px rgba(0, 179, 126, 0.1);
    }
    .save-btn {
        width: 38px;
        height: 38px;
        background: #e6f7f3;
        border: none;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: var(--accent-green);
        transition: all 180ms ease;
    }
    .save-btn:hover {
        background: var(--accent-green);
        color: #fff;
    }

    .view-details-btn {
        width: 100%;
        background: var(--text-primary);
        color: #fff !important;
        text-decoration: none;
        padding: 0.75rem;
        border-radius: var(--radius-md);
        font-weight: 700;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        transition: all 180ms ease;
        margin-top: auto;
    }
    .view-details-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        background: #2a2623;
    }

    /* Table View */
    .inventory-table-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 0 1.5rem;
        display: none;
    }
    .inventory-table-container.active { display: block; }
    
    .inventory-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border-radius: var(--radius-lg);
        overflow: hidden;
        border: 1px solid var(--border-default);
    }
    .inventory-table th {
        text-align: left;
        padding: 1rem 1.25rem;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-muted);
        background: #fafaf9;
        border-bottom: 1px solid var(--border-default);
    }
    .inventory-table td {
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid #f2f1f0;
        vertical-align: middle;
    }
    .inventory-table tr:hover td {
        background: #fafaf9;
    }
    .table-img {
        width: 48px;
        height: 48px;
        border-radius: 8px;
        object-fit: cover;
        background: #f5f5f5;
    }
    .table-prod-name {
        font-weight: 600;
        color: var(--text-primary);
        margin-bottom: 2px;
    }
    .table-shop-sub {
        font-size: 0.75rem;
        color: var(--text-muted);
    }

    /* Pagination */
    .inventory-pagination {
        max-width: 1400px;
        margin: 3rem auto;
        padding: 0 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .pagination-info {
        font-size: 0.9rem;
        color: var(--text-secondary);
    }
    .pagination-nav {
        display: flex;
        gap: 0.4rem;
    }
    .page-btn {
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid var(--border-default);
        border-radius: 8px;
        background: #fff;
        color: var(--text-secondary);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 180ms ease;
    }
    .page-btn.active {
        background: var(--text-primary);
        color: #fff;
        border-color: var(--text-primary);
    }
    .page-btn:not(.active):hover {
        border-color: var(--border-hover);
        background: #fafaf9;
    }
    /* Table View Alignment Fixes */
    .inventory-table td:nth-child(1) { min-width: 300px; }
    .inventory-table td:nth-child(3) { min-width: 110px; }
    .inventory-table td:nth-child(4) { min-width: 160px; }
    .inventory-table td:nth-child(5) { min-width: 200px; }
    
    .price-col-mini {
        font-family: var(--font-mono);
        font-weight: 700;
        display: flex;
        flex-direction: column;
        line-height: 1;
        gap: 2px;
    }
    .price-col-mini span {
        font-size: 0.65rem;
        color: var(--text-muted);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .margin-badge-mini {
        display: inline-flex;
        flex-direction: column;
        padding: 0.4rem 0.75rem;
        background: #f8fafc;
        border: 1px solid var(--border-default);
        border-radius: 8px;
        min-width: 100px;
    }
    .margin-badge-mini.margin-healthy { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
    .margin-badge-mini.margin-low { background: #fffbeb; border-color: #fef3c7; color: #92400e; }
    .margin-badge-mini.margin-missing { background: #f1f5f9; border-color: #e2e8f0; color: #64748b; }
    
    .margin-badge-mini .val { font-family: var(--font-mono); font-weight: 800; font-size: 0.95rem; line-height: 1; }
    .margin-badge-mini .label { font-size: 10px; font-weight: 700; text-transform: uppercase; opacity: 0.7; margin-bottom: 2px; }
    
    /* Modal Layout & Visibility */
    .modal-backdrop-react {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.4);
        backdrop-filter: blur(4px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 2000;
        padding: 2rem;
    }
    .modal-backdrop-react.active {
        display: flex;
    }
</style>

<div class="inventory-page">
    <header class="inventory-header">
        <div class="header-content">
            <div class="header-titles">
                <h1>Inventory</h1>
                <p>Manage your product catalog and inventory costs</p>
            </div>
            <div class="header-actions">
                <a href="index.php?page=products&export=1" class="btn-ghost">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export
                </a>
                <a href="index.php?page=product-import" class="btn-primary-green">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Import Products
                </a>
            </div>
        </div>
    </header>

    <div class="inventory-toolbar">
        <div class="toolbar-left">
            <div class="search-wrapper">
                <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" class="search-input" placeholder="Search products..." id="productSearch">
            </div>
            <select class="toolbar-select" onchange="window.location.href=\'index.php?page=products&shop_id=\' + this.value">
                <option value="0">All shops</option>';
foreach ($shops as $s) {
    $sel = $s['id'] == $filterShop ? ' selected' : '';
    $content .= '<option value="' . $s['id'] . '"' . $sel . '>' . htmlspecialchars($s['name']) . '</option>';
}
$content .= '
            </select>
            <div class="filter-chips">
                <button class="chip active" data-filter="all">All</button>
                <button class="chip" data-filter="instock">In Stock</button>
                <button class="chip" data-filter="lowmargin">Low Margin</button>
                <button class="chip" data-filter="nocost">No Cost Set</button>
            </div>
        </div>
        <div class="toolbar-right">
            <span class="pagination-info" style="margin-right: 1rem;">Showing <strong>' . count($products) . '</strong> of ' . $totalCount . ' products</span>
            <div class="view-toggle">
                <button class="toggle-btn active" id="viewGridBtn" title="Grid View">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                </button>
                <button class="toggle-btn" id="viewListBtn" title="List View">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                </button>
            </div>
        </div>
    </div>

    <div class="product-grid" id="productGrid">';

foreach ($products as $index => $p) {
    $salePrice = $p['variant_price'] !== null ? (float)$p['variant_price'] : 0;
    $costPrice = $p['cost'] !== null ? (float)$p['cost'] : null;
    $salePriceLabel = $p['variant_price'] !== null ? number_format($salePrice, 2) : '—';
    $margin = ($costPrice !== null) ? $salePrice - $costPrice : null;
    
    $marginClass = 'margin-missing';
    $statusText = '';
    $statusClass = '';
    
    if ($costPrice === null) {
        $statusText = 'No Cost';
        $statusClass = 'no-cost';
    } else {
        if ($margin >= 15) {
            $marginClass = 'margin-healthy';
            $statusText = number_format($margin, 2) . ' TND margin';
            $statusClass = 'margin';
        } else {
            $marginClass = 'margin-low';
            $statusText = 'Low Margin (' . number_format($margin, 2) . ')';
            $statusClass = 'no-cost'; // Brief says red/amber for low margin
        }
    }

    $imgHtml = $p['image_src'] 
        ? '<img src="' . htmlspecialchars($p['image_src']) . '" alt="' . htmlspecialchars($p['title']) . '" loading="lazy">'
        : '<div class="prod-img-placeholder"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>';
    
    $delay = ($index % 12) * 50; // Staggered animation
    
    $content .= '
    <div class="product-card" style="animation-delay: ' . $delay . 'ms;" data-name="' . strtolower(htmlspecialchars($p['title'])) . '" data-status="' . $statusClass . '">
        <div class="card-image-area">
            <div class="badge-float">
                <span class="shop-badge">' . htmlspecialchars($p['shop_name']) . '</span>
                ' . ($statusText ? '<span class="status-badge ' . $statusClass . '">' . $statusText . '</span>' : '') . '
            </div>
            ' . $imgHtml . '
            <div class="card-actions-hover">
                <a href="index.php?page=product-edit&id=' . $p['id'] . '" class="icon-btn" title="Edit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </a>
                <button class="icon-btn" title="Duplicate" onclick="alert(\'Duplicate functionality coming soon\')">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="card-shop-label">' . htmlspecialchars($p['shop_name']) . '</div>
            <div class="card-title">' . htmlspecialchars($p['title']) . '</div>
            
            <div class="pricing-grid">
                <div class="pricing-item">
                    <span class="label">Sale Price</span>
                    <div class="price-val">' . $salePriceLabel . '<span>TND</span></div>
                </div>
                <div class="pricing-item">
                    <span class="label">Margin</span>
                    <div class="margin-val ' . $marginClass . '">' . ($margin !== null ? number_format($margin, 2) . ' TND' : '—') . '</div>
                </div>
            </div>

            <div class="quick-cost-area">
                <span class="quick-cost-label">Quick Update Cost (TND)</span>
                <div class="cost-input-group">
                    <input type="number" step="0.01" value="' . ($costPrice !== null ? $costPrice : '') . '" 
                           class="cost-input" id="cost-input-' . $p['id'] . '" 
                           placeholder="0.00">
                    <button class="save-btn" onclick="updateCost(' . $p['id'] . ', ' . $salePrice . ')" title="Save">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                    </button>
                </div>
            </div>

            <a href="javascript:void(0)" onclick="openProductInfo(' . htmlspecialchars(json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') . ')" class="view-details-btn">
                View Details
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </a>
        </div>
    </div>';
}

$content .= '</div>

<div class="inventory-table-container" id="productList">
    <table class="inventory-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Shop</th>
                <th>Sale Price</th>
                <th>Cost Input</th>
                <th>Margin</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>';

foreach ($products as $p) {
    $salePrice = $p['variant_price'] !== null ? (float)$p['variant_price'] : 0;
    $costPrice = $p['cost'] !== null ? (float)$p['cost'] : null;
    $salePriceLabel = $p['variant_price'] !== null ? number_format($salePrice, 2) : '—';
    $margin = ($costPrice !== null) ? $salePrice - $costPrice : null;
    $marginClass = 'margin-missing';
    if ($margin !== null) {
        $marginClass = ($margin >= 15) ? 'margin-healthy' : 'margin-low';
    }

    $content .= '
    <tr data-name="' . strtolower(htmlspecialchars($p['title'])) . '">
        <td>
            <div style="display:flex; align-items:center; gap:1rem;">
                <img src="' . htmlspecialchars($p['image_src'] ?: 'icones/package.png') . '" class="table-img">
                <div>
                    <div class="table-prod-name">' . htmlspecialchars($p['title']) . '</div>
                    <div class="table-shop-sub">' . htmlspecialchars($p['shop_name']) . '</div>
                </div>
            </div>
        </td>
        <td><span class="shop-badge">' . htmlspecialchars($p['shop_name']) . '</span></td>
        <td>
            <div class="price-col-mini">
                ' . $salePriceLabel . ' 
                <span>TND</span>
            </div>
        </td>
        <td>
            <div class="cost-input-group" style="width:160px;">
                <input type="number" step="0.01" value="' . ($costPrice !== null ? $costPrice : '') . '" class="cost-input" id="table-cost-input-' . $p['id'] . '" style="padding:0.45rem;">
                <button class="save-btn" onclick="updateCost(' . $p['id'] . ', ' . $salePrice . ', true)" style="width:34px; height:34px; flex-shrink:0;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline></svg>
                </button>
            </div>
        </td>
        <td>
            <div class="margin-badge-mini ' . $marginClass . '">
                <span class="label">Margin</span>
                <span class="val">' . ($margin !== null ? number_format($margin, 2) . ' TND' : '—') . '</span>
            </div>
        </td>
        <td style="text-align:right;">
             <button onclick="openProductInfo(' . htmlspecialchars(json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') . ')" class="btn-ghost" style="display:inline-flex; width:auto; padding:0.4rem 0.75rem; font-size:0.8rem; cursor:pointer;">View</button>
        </td>
    </tr>';
}

$content .= '</tbody></table></div>';

// Pagination
if ($totalPages > 1) {
    $content .= '
    <div class="inventory-pagination">
        <div class="pagination-info">
            Showing <strong>' . (($pageNum - 1) * $perPage + 1) . '–' . min($pageNum * $perPage, $totalCount) . '</strong> of ' . $totalCount . ' products
        </div>
        <div class="pagination-nav">';
        
    if ($pageNum > 1) {
        $prevUrl = 'index.php?page=products&p=' . ($pageNum - 1) . ($filterShop > 0 ? '&shop_id=' . $filterShop : '');
        $content .= '<a href="' . $prevUrl . '" class="page-btn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg></a>';
    }
    
    for ($i = max(1, $pageNum - 2); $i <= min($totalPages, $pageNum + 2); $i++) {
        $active = ($i == $pageNum) ? ' active' : '';
        $pageUrl = 'index.php?page=products&p=' . $i . ($filterShop > 0 ? '&shop_id=' . $filterShop : '');
        $content .= '<a href="' . $pageUrl . '" class="page-btn' . $active . '">' . $i . '</a>';
    }
    
    if ($pageNum < $totalPages) {
        $nextUrl = 'index.php?page=products&p=' . ($pageNum + 1) . ($filterShop > 0 ? '&shop_id=' . $filterShop : '');
        $content .= '<a href="' . $nextUrl . '" class="page-btn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg></a>';
    }
    
    $content .= '</div></div>';
}

$content .= '</div>'; // close inventory-page

$content .= '
<style>
    .modal-react {
        background: #fff;
        width: 100%;
        max-width: 800px;
        border-radius: var(--radius-lg);
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        overflow: hidden;
        animation: modalSlide 300ms ease-out;
    }
    @keyframes modalSlide {
        from { transform: translateY(20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-default);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .modal-header h2 {
        font-family: var(--font-heading);
        font-size: 1.25rem;
        font-weight: 800;
        margin: 0;
    }
    .close-btn {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: var(--text-muted);
        transition: color 180ms ease;
    }
    .close-btn:hover { color: var(--text-primary); }
    .modal-body { padding: 1.5rem; }
    .modal-grid {
        display: grid;
        grid-template-columns: 300px 1fr;
        gap: 2rem;
    }
    .modal-img {
        width: 100%;
        aspect-ratio: 1;
        object-fit: contain;
        background: #f5f5f5;
        border-radius: var(--radius-md);
    }
    .modal-info {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
    }
    .modal-tag {
        display: inline-block;
        padding: 4px 10px;
        background: #e6f7f3;
        color: var(--accent-green);
        border-radius: 99px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        margin-bottom: 0.5rem;
    }
    .modal-title {
        font-size: 1.5rem;
        font-weight: 800;
        line-height: 1.2;
    }
    .details-table {
        width: 100%;
        border-collapse: collapse;
    }
    .details-table td {
        padding: 0.75rem 0;
        border-bottom: 1px solid #f2f1f0;
    }
    .details-table .label {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        width: 100px;
    }
    .details-table .val {
        font-weight: 600;
        color: var(--text-primary);
    }
</style>

<div class="modal-backdrop-react" id="info-modal-backdrop" onclick="closeProductInfo()">
    <div class="modal-react" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h2 id="modal-title-head">Product Information</h2>
            <button class="close-btn" onclick="closeProductInfo()">&times;</button>
        </div>
        <div class="modal-body" id="modal-body-content">
            <!-- Content injected by JS -->
        </div>
    </div>
</div>

<script>
// View Toggling
const viewGridBtn = document.getElementById("viewGridBtn");
const viewListBtn = document.getElementById("viewListBtn");
const productGrid = document.getElementById("productGrid");
const productList = document.getElementById("productList");

function setView(view) {
    if (view === "grid") {
        productGrid.style.display = "grid";
        productList.style.display = "none";
        viewGridBtn.classList.add("active");
        viewListBtn.classList.remove("active");
    } else {
        productGrid.style.display = "none";
        productList.style.display = "block";
        viewGridBtn.classList.remove("active");
        viewListBtn.classList.add("active");
    }
    localStorage.setItem("inventoryView", view);
}

viewGridBtn.addEventListener("click", () => setView("grid"));
viewListBtn.addEventListener("click", () => setView("list"));

const savedView = localStorage.getItem("inventoryView") || "grid";
setView(savedView);

// Live Search
const searchInput = document.getElementById("productSearch");
searchInput.addEventListener("input", (e) => {
    const term = e.target.value.toLowerCase();
    
    // Filter Grid
    document.querySelectorAll(".product-card").forEach(card => {
        card.style.display = card.getAttribute("data-name").includes(term) ? "flex" : "none";
    });
    
    // Filter Table
    document.querySelectorAll(".inventory-table tbody tr").forEach(row => {
        row.style.display = row.getAttribute("data-name").includes(term) ? "" : "none";
    });
});

// Filter Chips
document.querySelectorAll(".chip").forEach(chip => {
    chip.addEventListener("click", function() {
        document.querySelectorAll(".chip").forEach(c => c.classList.remove("active"));
        this.classList.add("active");
        
        const filter = this.getAttribute("data-filter");
        
        document.querySelectorAll(".product-card").forEach(card => {
            const status = card.getAttribute("data-status");
            if (filter === "all") card.style.display = "flex";
            else if (filter === "nocost" && status === "no-cost") card.style.display = "flex";
            else if (filter === "lowmargin" && status === "no-cost" && card.textContent.includes("Low Margin")) card.style.display = "flex";
            else if (filter === "instock" && status === "margin") card.style.display = "flex";
            else card.style.display = "none";
        });
    });
});

function openProductInfo(p) {
    const body = document.getElementById("modal-body-content");
    const img = p.image_src ? `<img src="${p.image_src}" class="modal-img">` : `<div class="modal-img" style="display:flex;align-items:center;justify-content:center;color:#cbd5e1;"><svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg></div>`;
    
    body.innerHTML = `
        <div class="modal-grid">
            <div class="grid-col">
                ${img}
            </div>
            <div class="grid-col modal-info">
                <div>
                    <div class="modal-tag">${p.shop_name}</div>
                    <div class="modal-title">${p.title}</div>
                </div>
                
                <table class="details-table">
                    <tr><td class="label">Vendor</td><td class="val">${p.vendor || "—"}</td></tr>
                    <tr><td class="label">Type</td><td class="val">${p.type || "—"}</td></tr>
                    <tr><td class="label">SKU</td><td class="val"><code>${p.variant_sku || "—"}</code></td></tr>
                    <tr><td class="label">Handle</td><td class="val"><code>${p.handle || "—"}</code></td></tr>
                    <tr><td class="label">Status</td><td class="val" style="text-transform:capitalize;">${p.status || "active"}</td></tr>
                    <tr><td class="label">Sale Price</td><td class="val" style="color:#0f172a; font-weight:800;">${p.variant_price !== null && p.variant_price !== "" ? parseFloat(p.variant_price).toFixed(2) : "—"} TND</td></tr>
                </table>

                ${p.body_html ? `
                <div class="modal-desc">
                    <div class="meta-label" style="margin-bottom:0.75rem;">Description</div>
                    <div style="font-size:0.9rem;">${p.body_html}</div>
                </div>` : ""}
            </div>
        </div>
    `;
    
    document.getElementById("info-modal-backdrop").classList.add("active");
    document.body.style.overflow = "hidden";
}

function closeProductInfo() {
    document.getElementById("info-modal-backdrop").classList.remove("active");
    document.body.style.overflow = "";
}

function updateCost(pid, price, fromTable = false) {
    const inputId = fromTable ? "table-cost-input-" + pid : "cost-input-" + pid;
    const input = document.getElementById(inputId);
    const cost = input.value;
    const formData = new FormData();
    formData.append("ajax_update_cost", "1");
    formData.append("product_id", pid);
    formData.append("cost", cost);
    fetch("index.php?page=products", { method: "POST", body: formData })
    .then(r => r.json())
    .then(data => { if(data.success) window.location.reload(); else alert("Error: " + (data.error || "Unknown error")); })
    .catch(err => { console.error(err); alert("Failed to update cost."); });
}
</script>
';
require $base . '/layouts/layout.php';
