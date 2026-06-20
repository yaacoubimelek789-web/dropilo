<?php
$uid = (int) $_SESSION['user_id'];

if (($page ?? '') === 'shop-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['shop_name'] ?? '');
    if ($name !== '') {
        $app->pdo->prepare('INSERT INTO shops (user_id, name) VALUES (?, ?)')->execute([$uid, $name]);
        header('Location: index.php?page=shops');
        exit;
    }
}

$currentPage = in_array($page ?? '', ['shop-view', 'shop-create'], true) ? 'shops' : 'shops';
$pageTitle = 'My shops';

$shops = $app->pdo->prepare('SELECT id, name, created_at FROM shops WHERE user_id = ? ORDER BY created_at DESC');
$shops->execute([$uid]);
$shops = $shops->fetchAll();

if (($page ?? '') === 'shop-create') {
    $content = '
    <h1>Create a shop</h1>
    <div class="card">
      <form method="post">
        <div class="form-group">
          <label for="shop_name">Shop name</label>
          <input type="text" id="shop_name" name="shop_name" required placeholder="My Store">
        </div>
        <button type="submit" class="btn">Create shop</button>
        <a href="index.php?page=shops" class="btn btn-secondary">Cancel</a>
      </form>
    </div>';
    require $base . '/layouts/layout.php';
    return;
}

if (($page ?? '') === 'shop-view') {
    $shopId = (int) ($_GET['id'] ?? 0);
    // Generate/Refresh Webhook Token
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['generate_token'])) {
            $newToken = bin2hex(random_bytes(16));
            $app->pdo->prepare('UPDATE shops SET webhook_token = ? WHERE id = ? AND user_id = ?')->execute([$newToken, $shopId, $uid]);
            header('Location: index.php?page=shop-view&id=' . $shopId);
            exit;
        } elseif (isset($_POST['disconnect_shopify'])) {
            $app->pdo->prepare('UPDATE shops SET webhook_token = NULL WHERE id = ? AND user_id = ?')->execute([$shopId, $uid]);
            header('Location: index.php?page=shop-view&id=' . $shopId);
            exit;
        } elseif (isset($_POST['delete_shop'])) {
            $app->pdo->prepare('DELETE FROM shops WHERE id = ? AND user_id = ?')->execute([$shopId, $uid]);
            header('Location: index.php?page=shops');
            exit;
        }
    }

    $st = $app->pdo->prepare('SELECT id, name, webhook_token FROM shops WHERE id = ? AND user_id = ?');
    $st->execute([$shopId, $uid]);
    $shop = $st->fetch();
    if (!$shop) {
        header('Location: index.php?page=shops');
        exit;
    }
    $products = $app->pdo->prepare('SELECT id, title, variant_price, image_src FROM products WHERE shop_id = ? ORDER BY title');
    $products->execute([$shopId]);
    $products = $products->fetchAll();

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $webhookUrl = $shop['webhook_token'] ? $protocol . $host . '/public/webhook-shopify.php?sid=' . $shop['id'] . '&token=' . $shop['webhook_token'] : null;

    $content = '<h1>' . htmlspecialchars($shop['name']) . '</h1>';
    
    // Shopify Integration Section
    $content .= '<div class="card integration-card" style="border-top: 4px solid #95bf47; margin-bottom: 2.5rem; padding: 1.5rem;">
        <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:1.5rem;">
            <div style="display:flex; align-items:center; gap:1rem;">
                <div style="width:56px; height:56px; background:#f4f9f1; border-radius:14px; display:flex; align-items:center; justify-content:center; padding:10px; border:1px solid #e9f5e3;">
                    <img src="logo/shopify-logo.png" alt="Shopify" style="width:100%; height:100%; object-fit:contain;">
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.25rem; color:#1e293b;">Shopify Integration</h3>
                    <p style="margin:0.25rem 0 0; color:#64748b; font-size:0.9rem;">Automate your order workflow with webhooks.</p>
                </div>
            </div>';
            
    if ($webhookUrl) {
        $content .= '<span style="background:#f0fdf4; color:#16a34a; padding:6px 14px; border-radius:30px; font-size:0.8rem; font-weight:700; border:1px solid #dcfce7; display:flex; align-items:center; gap:0.5rem;"><span style="width:8px; height:8px; background:#16a34a; border-radius:50%;"></span> Connected</span>';
    } else {
        $content .= '<span style="background:#f8fafc; color:#64748b; padding:6px 14px; border-radius:30px; font-size:0.8rem; font-weight:700; border:1px solid #e2e8f0;">Disconnected</span>';
    }
    
    $content .= '</div>';
    
    if ($webhookUrl) {
        $content .= '
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem;">
            <label style="display:block; font-size:0.85rem; font-weight:600; color:#475569; margin-bottom:0.75rem;">Webhook Endpoint URL</label>
            <div style="display:flex; gap:0.75rem;">
                <input type="text" value="' . htmlspecialchars($webhookUrl) . '" readonly style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; flex:1; font-family:Menlo, Monaco, Consolas, monospace; font-size:0.85rem; padding:10px 12px; color:#334155;" id="webhook_url">
                <button class="btn btn-secondary" style="padding:0 1.25rem; font-weight:600;" onclick="copyWebhook()">Copy</button>
            </div>
            
            <div style="margin-top:1.5rem; padding-top:1.25rem; border-top:1px solid #e2e8f0;">
                <h4 style="margin:0 0 0.75rem; font-size:0.95rem; color:#1e293b;">How to complete setup:</h4>
                <ol style="margin:0; padding-left:1.25rem; color:#475569; font-size:0.85rem; line-height:1.6;">
                    <li>Copy the URL above.</li>
                    <li>In Shopify Admin, go to <strong>Settings > Notifications > Webhooks</strong>.</li>
                    <li>Click <strong>Create webhook</strong>, select <strong>Order creation</strong> as the event.</li>
                    <li>Paste the URL and save. All new orders will now appear in GLORAS instantly.</li>
                </ol>
            </div>
        </div>
        
        <div style="margin-top:1.5rem; display:flex; justify-content:flex-end; gap:10px;">
            <form method="post" onsubmit="return confirm(\'Disconnecting Shopify will stop all automatic order imports. Continue?\')">
                <button type="submit" name="disconnect_shopify" value="1" class="btn btn-sm" style="background:#fff7ed; color:#c2410c; border:1px solid #ffedd5; padding:6px 12px; font-size:0.8rem;">Disconnect Shopify</button>
            </form>
            <form method="post" onsubmit="return confirm(\'Generating a new token will break your existing Shopify webhook. Continue?\')">
                <button type="submit" name="generate_token" value="1" class="btn btn-sm" style="background:transparent; color:#64748b; border:1px solid #e2e8f0; padding:6px 12px; font-size:0.8rem;">Refresh Webhook Token</button>
            </form>
        </div>
        
        <div style="margin-top:3rem; padding-top:1.5rem; border-top:1px solid #fee2e2;">
            <h4 style="margin:0 0 0.5rem; font-size:0.95rem; color:#b91c1c;">Danger Zone</h4>
            <p style="margin:0 0 1rem; font-size:0.85rem; color:#ef4444;">Deleting this shop will permanently remove all associated products and settings.</p>
            <form method="post" onsubmit="return confirm(\'ARE YOU SURE? This action cannot be undone and will delete ALL data for this shop.\')">
                <button type="submit" name="delete_shop" value="1" class="btn btn-sm" style="background:#fef2f2; color:#b91c1c; border:1px solid #fee2e2; padding:8px 16px; font-weight:700;">Delete Shop Permanently</button>
            </form>
        </div>';
    } else {
        $content .= '
        <div style="text-align:center; padding: 2.5rem; background:#f8fafc; border-radius:12px; border:1px dashed #cbd5e1;">
            <div style="max-width:400px; margin:0 auto;">
                <p style="color:#475569; margin-bottom:1.5rem; font-weight:500;">Ready to automate? Generate your secure webhook URL to start receiving Shopify orders in real-time.</p>
                <form method="post">
                    <button type="submit" name="generate_token" value="1" class="btn" style="width:100%; padding:12px; font-weight:600; letter-spacing:0.3px;">Enable Shopify Webhooks</button>
                </form>
            </div>
        </div>';
    }
    $content .= '</div>';

    $content .= '<p><a href="index.php?page=product-import&shop_id=' . $shopId . '" class="btn">Import products (CSV)</a></p>';
    $content .= '<div class="card"><h3>Products in this shop</h3>';
    if (count($products) === 0) {
        $content .= '<p>No products yet. Import a Shopify products CSV above.</p>';
    } else {
        $content .= '<table><thead><tr><th>Image</th><th>Title</th><th>Price</th><th></th></tr></thead><tbody>';
        foreach ($products as $p) {
            $img = $p['image_src'] ? '<img src="' . htmlspecialchars($p['image_src']) . '" alt="" style="width:40px;height:40px;object-fit:cover;">' : '—';
            $content .= '<tr><td>' . $img . '</td><td>' . htmlspecialchars($p['title']) . '</td><td>' . ($p['variant_price'] !== null ? number_format((float)$p['variant_price'], 2) : '') . '</td><td><a href="index.php?page=product-view&id=' . $p['id'] . '">View</a></td></tr>';
        }
        $content .= '</tbody></table></div>';
    }
    $content .= '<p><a href="index.php?page=shops">Back to shops</a></p>
    <script>
    function copyWebhook() {
        var copyText = document.getElementById("webhook_url");
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        alert("Webhook URL copied to clipboard");
    }
    </script>';
    require $base . '/layouts/layout.php';
    return;
}

$content = '<h1>My shops</h1><p><a href="index.php?page=shop-create" class="btn">Create a shop</a></p>';
$content .= '<div class="card"><table><thead><tr><th>Shop</th><th>Created</th><th></th></tr></thead><tbody>';
foreach ($shops as $s) {
    $content .= '<tr><td>' . htmlspecialchars($s['name']) . '</td><td>' . htmlspecialchars($s['created_at']) . '</td><td><a href="index.php?page=shop-view&id=' . $s['id'] . '" class="btn">Open</a></td></tr>';
}
$content .= '</tbody></table></div>';
// Floating Action Button for Integration Flow
if (($_GET['return'] ?? '') === 'integration') {
    $content .= '
    <a href="index.php?page=integration" style="
        position: fixed;
        bottom: 30px;
        right: 30px;
        background: #0F0F0F;
        color: #fff;
        padding: 12px 24px;
        border-radius: 50px;
        text-decoration: none;
        font-weight: 700;
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 1000;
        transition: transform 0.2s;
    " onmouseover="this.style.transform=\'translateY(-5px)\'" onmouseout="this.style.transform=\'translateY(0)\'">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
        Back to Integration
    </a>';
}

require $base . '/layouts/layout.php';
