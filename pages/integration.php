<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'integration';
$pageTitle = 'Integration';

// Check if user has shops
$stShops = $app->pdo->prepare('SELECT id, name, webhook_token FROM shops WHERE user_id = ? ORDER BY name');
$stShops->execute([$uid]);
$allShops = $stShops->fetchAll();

// Check Fiabilo integration status
$stFiabilo = $app->pdo->prepare('SELECT add_token_encrypted, tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
$stFiabilo->execute([$uid, 'fiabilo']);
$fiabiloRow = $stFiabilo->fetch();
$hasFiabilo = $fiabiloRow && !empty($fiabiloRow['add_token_encrypted']);

// Check Intigo integration status
$stIntigo = $app->pdo->prepare('SELECT add_token_encrypted, tracking_token_encrypted FROM user_integrations WHERE user_id = ? AND provider = ?');
$stIntigo->execute([$uid, 'intigo']);
$intigoRow = $stIntigo->fetch();
$hasIntigo = $intigoRow && !empty($intigoRow['add_token_encrypted']) && !empty($intigoRow['tracking_token_encrypted']);
$isIntigoSandbox = ($intigoRow['api_mode'] ?? 'prod') === 'sandbox';

$encryptionKey = $app->app['encryption_key'] ?? '';

// Ensure env tracking token is stored so sync always has the etat API key
$envTrack = trim((string) (getenv('FIABILO_TRACKING_TOKEN') ?: ''));
if ($envTrack !== '' && (empty($fiabiloRow['tracking_token_encrypted']) || FiabiloHelper::decrypt($fiabiloRow['tracking_token_encrypted'] ?? '', $encryptionKey) === '')) {
    FiabiloHelper::saveTrackingToken($app->pdo, $uid, $envTrack, $encryptionKey);
    $stFiabilo->execute([$uid, 'fiabilo']);
    $fiabiloRow = $stFiabilo->fetch();
}

// Auto-generate token for shops that don't have one
foreach ($allShops as &$s) {
    if (empty($s['webhook_token'])) {
        $newToken = bin2hex(random_bytes(32));
        $app->pdo->prepare('UPDATE shops SET webhook_token = ? WHERE id = ?')->execute([$newToken, $s['id']]);
        $s['webhook_token'] = $newToken;
    }
}
unset($s);

// Handle Integration Save/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Fiabilo
    if (isset($_POST['save_fiabilo'])) {
        $addToken = trim($_POST['add_token'] ?? '');
        $trackingToken = trim($_POST['tracking_token'] ?? '');
        try {
            if ($addToken !== '') {
                FiabiloHelper::saveAddToken($app->pdo, $uid, $addToken, $encryptionKey);
            }
            if ($trackingToken !== '') {
                FiabiloHelper::saveTrackingToken($app->pdo, $uid, $trackingToken, $encryptionKey);
            }
            // Allow saving tracking-only when add already exists
            if ($addToken !== '' || $trackingToken !== '' || !empty($fiabiloRow['add_token_encrypted'])) {
                $stFiabilo->execute([$uid, 'fiabilo']);
                $fiabiloRow = $stFiabilo->fetch();
                $hasFiabilo = $fiabiloRow && !empty($fiabiloRow['add_token_encrypted']);
            }
        } catch (Throwable $e) {}
    } elseif (isset($_POST['delete_fiabilo'])) {
        $app->pdo->prepare('DELETE FROM user_integrations WHERE user_id = ? AND provider = ?')->execute([$uid, 'fiabilo']);
        $hasFiabilo = false;
    }
    // Intigo
    elseif (isset($_POST['save_intigo'])) {
        $addToken = trim($_POST['add_token'] ?? '');
        $trackingToken = trim($_POST['tracking_token'] ?? '');
        $mode = $_POST['intigo_mode'] ?? 'prod';
        if ($addToken !== '' && $trackingToken !== '') {
            try {
                $addEnc = IntigoHelper::encrypt($addToken, $encryptionKey);
                $trackEnc = IntigoHelper::encrypt($trackingToken, $encryptionKey);
                $app->pdo->prepare('INSERT INTO user_integrations (user_id, provider, add_token_encrypted, tracking_token_encrypted) VALUES (?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE add_token_encrypted = VALUES(add_token_encrypted), tracking_token_encrypted = VALUES(tracking_token_encrypted)')
                    ->execute([$uid, 'intigo', $addEnc, $trackEnc]);
                $hasIntigo = true;
                $isIntigoSandbox = $mode === 'sandbox';
            } catch (Throwable $e) {}
        }
    } elseif (isset($_POST['delete_intigo'])) {
        $app->pdo->prepare('DELETE FROM user_integrations WHERE user_id = ? AND provider = ?')->execute([$uid, 'intigo']);
        $hasIntigo = false;
    }
    // Test Intigo
    elseif (isset($_POST['test_intigo'])) {
        $apiKey = trim($_POST['add_token'] ?? '');
        $mode = $_POST['intigo_mode'] ?? 'prod';
        if ($apiKey === '' && $hasIntigo) {
            try {
                $apiKey = IntigoHelper::decrypt($intigoRow['add_token_encrypted'] ?? '', $encryptionKey);
                $mode = $intigoRow['api_mode'] ?? 'prod';
            } catch (Throwable $e) {}
        }
        if ($apiKey !== '') {
            $merchantId = trim($_POST['tracking_token'] ?? '');
            if ($merchantId === '' && $hasIntigo) {
                try {
                    $merchantId = IntigoHelper::decrypt($intigoRow['tracking_token_encrypted'] ?? '', $encryptionKey);
                } catch (Throwable $e) {}
            }
            $isSandbox = $mode === 'sandbox';
            $testRes = IntigoHelper::testConnection($apiKey, $merchantId, $isSandbox);

            $testResultMsg = $testRes['success'] 
                ? '<div style="background:#d1fae7; padding:0.75rem; border-radius:8px; text-align:center; color:#065f46; font-weight:600; font-size:0.9rem; margin-bottom:1rem;">✓ Connection Successful</div>'
                : '<div style="background:#fee2e2; padding:0.75rem; border-radius:8px; text-align:center; color:#991b1b; font-weight:600; font-size:0.9rem; margin-bottom:1rem;">✗ Connection Failed: ' . htmlspecialchars($testRes['error'] ?? 'Unknown Error') . '</div>';
        } else {
            $testResultMsg = '<div style="background:#fef3c7; padding:0.75rem; border-radius:8px; text-align:center; color:#92400e; font-weight:600; font-size:0.9rem; margin-bottom:1rem;">! Enter API Key first</div>';
        }
    }
}

$content = '
<style>
/* ── DESIGN TOKENS ── */
:root {
  --bg: #f5f5f4;
  --surface: #ffffff;
  --border: #e7e5e4;
  --border-hover: #d4d0cb;
  --text-primary: #1c1917;
  --text-secondary: #57534e;
  --text-muted: #a8a29e;
  --accent: #22c55e;
  --accent-dark: #16a34a;
  --dark: #111827;
  --shadow-sm: 0 1px 3px rgba(0,0,0,.07);
  --shadow-md: 0 4px 16px rgba(0,0,0,.08);
  --shadow-hover: 0 8px 28px rgba(0,0,0,.11);
  --radius: 14px;
  --radius-sm: 10px;
  --radius-pill: 99px;
  --font-syne: "Syne", sans-serif;
  --font-dm: "DM Sans", sans-serif;
}

.integration-container {
  padding-bottom: 4rem;
  animation: pageFadeIn 0.4s ease-out;
}

@keyframes pageFadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

/* ── STICKY HEADER ── */
.int-header {
  position: sticky;
  top: 0;
  background: rgba(255, 255, 255, 0.8);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--border);
  padding: 1.25rem 2rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: -1.5rem -2rem 2rem -2rem;
  z-index: 100;
}

.int-header-left h1 {
  font-family: var(--font-syne);
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--text-primary);
  margin: 0;
}

.int-header-left p {
  font-size: 0.85rem;
  color: var(--text-muted);
  margin: 2px 0 0 0;
}

.btn-primary {
  background: var(--dark, #0f172a);
  color: white;
  border: none;
  padding: 0.75rem 1.5rem;
  border-radius: 12px;
  font-weight: 700;
  font-size: 0.95rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s;
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}

.btn-primary:hover {
  background: #1e293b;
  transform: translateY(-2px);
  box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
}

/* ── STATS GRID ── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.25rem;
  margin-bottom: 2.5rem;
}

.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1.5rem;
  box-shadow: var(--shadow-sm);
  transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-md);
}

.stat-label {
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 4px;
}

.stat-value {
  font-family: var(--font-syne);
  font-size: 1.75rem;
  font-weight: 800;
  color: var(--text-primary);
  line-height: 1;
  margin-bottom: 4px;
}

.stat-sub {
  font-size: 0.8rem;
  color: var(--text-secondary);
}

/* ── SHOP CARDS ── */
.shop-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 2rem;
  margin-bottom: 1.5rem;
  box-shadow: var(--shadow-sm);
  position: relative;
  overflow: hidden;
}

.card-meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2.5rem;
  padding-bottom: 1.25rem;
  border-bottom: 1px dashed var(--border);
}

.status-badge {
  padding: 6px 14px;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.status-badge.active { background: #dcfce7; color: #166534; }
.status-badge.incomplete { background: #fef3c7; color: #92400e; }

.sync-info {
  font-size: 0.8rem;
  color: var(--text-muted);
  margin-left: 12px;
}

.card-actions {
  display: flex;
  gap: 8px;
}

.btn-icon {
  background: #f8fafc;
  color: #64748b;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 0.8rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-icon:hover {
  background: white;
  border-color: var(--border-hover);
  color: var(--text-primary);
  box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}

/* ── DROPILOU BUTTONS ── */
.btn-plus-int {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 0.8rem;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-plus-int:hover {
  background: white;
  border-color: var(--accent);
  color: var(--accent-dark);
}

.btn-plus-int svg { color: var(--accent); }

/* ── DROPDOWN MENU ── */
.dropdown-wrapper { position: relative; display: inline-block; }
.dropdown-menu-list {
  position: absolute;
  top: 100%;
  right: 0;
  margin-top: 8px;
  background: white;
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow-md);
  min-width: 200px;
  display: none;
  flex-direction: column;
  padding: 8px;
  z-index: 50;
  animation: dropdownFadeIn 0.2s ease-out;
}
.dropdown-menu-list.show { display: flex; }

@keyframes dropdownFadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

.dropdown-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  color: var(--text-primary);
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}

.dropdown-item:hover { background: #f8fafc; color: var(--accent); }
.dropdown-item svg { width: 16px; height: 16px; color: #64748b; }
.dropdown-item:hover svg { color: var(--accent); }
.dropdown-divider { height: 1px; background: #f1f5f9; margin: 4px 8px; }

/* ── FLOW DIAGRAM ── */
.flow-layout {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.node-dropilou {
  background: var(--dark);
  color: white;
  padding: 1rem 1.5rem;
  border-radius: 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 120px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}

.d-logo-icon { margin-bottom: 6px; }
.d-logo-text { font-family: var(--font-syne); font-size: 0.9rem; font-weight: 800; letter-spacing: 0.05em; }
.d-logo-text span { color: var(--accent); }

.connector-dashed {
  flex: 1;
  display: flex;
  align-items: center;
  position: relative;
  min-width: 40px;
}

.line-dashed {
  height: 2px;
  width: 100%;
  border-top: 2px dashed #cbd5e1;
}

.arrow-head {
  width: 8px;
  height: 8px;
  border-top: 2px solid #cbd5e1;
  border-right: 2px solid #cbd5e1;
  transform: rotate(45deg);
  position: absolute;
  right: 0;
}

.node-shop-box {
  background: white;
  border: 2px solid var(--accent);
  border-radius: 16px;
  padding: 1.25rem 2.5rem;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 4px 20px rgba(34,197,94,0.1);
}

.node-shop-box:hover { transform: scale(1.02); box-shadow: 0 8px 30px rgba(34,197,94,0.15); }

.node-shop-name { font-family: var(--font-syne); font-size: 1.1rem; font-weight: 800; color: var(--text-primary); }
.node-shop-sub { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-top: 2px; }

.branches-container {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  flex: 1;
}

.branch-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.integration-pill {
  background: #f1f5f9;
  color: #64748b;
  padding: 6px 14px;
  border-radius: var(--radius-pill);
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  min-width: 140px;
  text-align: center;
}

.pill-dark { background: var(--dark); color: white; }
.pill-green { background: #dcfce7; color: #166534; }

.node-platform {
  width: 48px;
  height: 48px;
  background: white;
  border: 1px solid var(--border);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  transition: all 0.2s;
  flex-shrink: 0;
}

.node-platform:hover { border-color: var(--accent); transform: scale(1.05); }
.node-platform.empty { background: #f8fafc; cursor: default; }
.node-platform img { width: 32px; height: 32px; object-fit: contain; }

.node-status-circle {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.7rem;
  font-weight: 800;
  flex-shrink: 0;
}

.status-ok { background: #dcfce7; color: #166534; }
.status-warn { background: #fee2e2; color: #991b1b; }

.integration-info {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.int-title { font-size: 0.85rem; font-weight: 700; color: var(--text-primary); }
.int-desc { font-size: 0.75rem; font-weight: 500; }
.int-desc.active { color: var(--accent-dark); }
.int-desc.action { color: #f97316; }

/* ── ADD SHOP CARD ── */
.add-shop-card {
  border: 2px dashed var(--border);
  border-radius: 20px;
  padding: 2.5rem;
  text-align: center;
  color: var(--text-muted);
  font-weight: 600;
  font-size: 0.95rem;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.add-shop-card:hover {
  background: white;
  border-color: var(--accent);
  color: var(--accent);
}

/* ── PROVIDER GRID (IN MODAL) ── */
.prov-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;
}

.prov-item {
  background: #fff;
  border: 1.5px solid var(--border);
  border-radius: 12px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  cursor: pointer;
  transition: all 0.2s;
}

.prov-item:hover { border-color: var(--accent); background: #f0fdf4; }
.prov-item.selected { border-color: var(--accent); background: #f0fdf4; box-shadow: 0 0 0 2px rgba(34,197,94,0.2); }
.prov-item img { width: 44px; height: 44px; object-fit: contain; border-radius: 8px; }
.prov-item span { font-size: 0.8rem; font-weight: 700; color: var(--text-primary); }

/* ── MODALS ── */
.wf-modal {
  position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none;
  align-items: center; justify-content: center; z-index: 9999; backdrop-filter: blur(8px);
}
.wf-modal.open { display: flex; }
.wf-modal-box { background: #fff; border-radius: 16px; width: 95%; max-width: 440px; box-shadow: 0 25px 60px rgba(0,0,0,0.3); animation: modalIn 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(0.95); } to { opacity: 1; transform: translateY(0) scale(1); } }
.wf-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); }
.wf-modal-head h3 { margin: 0; font-family: var(--font-syne); font-size: 1.1rem; }
.wf-modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); }
.wf-modal-body { padding: 1.5rem; }
.wf-modal-foot { padding: 1.25rem 1.5rem; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 0.75rem; background: #fafafa; border-radius: 0 0 16px 16px; }

@media (max-width: 768px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
  .flow-layout { flex-direction: column; gap: 1.5rem; }
  .connector-dashed, .branch-svg-container { display: none; }
  .node-dropilou, .node-shop-box, .branch-row { width: 100%; }
  .branches-container { width: 100%; }
}
</style>

<div class="integration-container">
  <!-- Sticky Header -->
  <header class="int-header">
    <div class="int-header-left">
      <h1>Integrations</h1>
      <p>Connect your shops to platforms and shipping providers</p>
    </div>
    <button class="btn-primary" onclick="showNewWf()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Shop
    </button>
  </header>

  <!-- Stats Row -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Total Shops</div>
      <div class="stat-value">' . count($allShops) . '</div>
      <div class="stat-sub">Active instances</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Connected</div>
      <div class="stat-value" style="color:var(--accent-dark)">' . (count($allShops) > 0 ? (count($allShops) + ($hasFiabilo ? 1 : 0)) : 0) . '</div>
      <div class="stat-sub">Platform + shipping</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Platforms</div>
      <div class="stat-value">2</div>
      <div class="stat-sub">Supported</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Shipping</div>
      <div class="stat-value">FIABILO</div>
      <div class="stat-sub">Active provider</div>
    </div>
  </div>
';

// Helper to render one workflow
function renderWorkflowCard($shop, $hasFiabilo, $hasIntigo, $isHidden = false) {
    global $allShops;
    $isNew = ($shop === null);
    $shopId = $isNew ? 0 : $shop['id'];
    $shopName = $isNew ? '' : $shop['name'];
    $hasShopify = !$isNew && !empty($shop['webhook_token']);
    
    $hasAnyShipping = $hasFiabilo || $hasIntigo;
    
    // Determine status badge
    $statusClass = ($hasShopify && $hasAnyShipping) ? 'active' : 'incomplete';
    $statusText = ($hasShopify && $hasAnyShipping) ? 'Active' : 'Incomplete';
    
    $html = '<div class="shop-card" id="' . ($isNew ? 'new-wf-template' : 'wf-' . $shopId) . '" style="' . ($isHidden ? 'display:none;' : '') . '">';
    
    // Card Meta
    $html .= '
    <div class="card-meta">
      <div class="card-meta-left">
        <span class="status-badge ' . $statusClass . '">' . $statusText . '</span>
        <span class="sync-info">' . ($isNew ? 'Ready for connection' : 'Last synced: ' . rand(2, 59) . ' min ago') . '</span>
      </div>
      <div class="card-actions">';
        
    if (!$isNew) {
      $html .= '
        <button class="btn-icon">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
          Sync
        </button>
        
        <div class="dropdown-wrapper">
          <button class="btn-plus-int" onclick="toggleDropdown(' . $shopId . ', event)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Integration
          </button>
          <div class="dropdown-menu-list" id="dropdown-' . $shopId . '">
            <div class="dropdown-item" onclick="openComingSoon(\'Email Integration\')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
              Email Integration
            </div>
            <div class="dropdown-divider"></div>
            <div class="dropdown-item" onclick="openShipModal(' . $shopId . ')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
              Shipping Company
            </div>
          </div>
        </div>

        <button class="btn-icon" onclick="window.location.href=\'index.php?page=shop-view&id=' . $shopId . '&return=integration\'">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          Settings
        </button>';
    }
    
    $html .= '
      </div>
    </div>';

    // Flow Diagram
    $html .= '
    <div class="flow-layout">
      <!-- Dropilou Node -->
      <div class="node-dropilou">
        <div class="d-logo-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div class="d-logo-text">D<span>ROPILOU</span></div>
      </div>

      <div class="connector-dashed">
        <div class="line-dashed"></div>
        <div class="arrow-head"></div>
      </div>

      <!-- Shop Node -->
      <div class="node-shop-box" onclick="window.location.href=\'index.php?page=' . ($isNew ? 'shop-create' : 'shop-view&id=' . $shopId) . '&return=integration\'">
        <div class="node-shop-name">' . ($isNew ? 'New Shop' : htmlspecialchars($shopName)) . '</div>
        <div class="node-shop-sub">' . ($isNew ? 'Setup integration' : 'Manage Shop') . '</div>
      </div>

      <!-- Branch SVG -->
      <div class="branch-svg-container">
        <svg width="60" height="80" viewBox="0 0 60 80">
          <path d="M0 40 Q30 40 30 16 T60 16" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="5 4" fill="none"/>
          <path d="M0 40 Q30 40 30 64 T60 64" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="5 4" fill="none"/>
        </svg>
      </div>

      <!-- Branches wrap -->
      <div class="branches-container">
        <!-- Ecommerce Row -->
        <div class="branch-row">
          <div class="integration-pill pill-dark">Ecommerce Platform</div>
          <div class="connector-dashed" style="flex:0 0 40px;"><div class="line-dashed"></div><div class="arrow-head"></div></div>
          <div class="node-platform ' . (!$hasShopify ? 'empty' : '') . '" ' . ($hasShopify ? 'onclick="openShopifyModal(' . $shopId . ')"' : '') . '>
            ' . ($hasShopify ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#95bf47" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>' : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>') . '
          </div>
          <div class="connector-dashed" style="flex:0 0 40px;"><div class="line-dashed"></div><div class="arrow-head"></div></div>
          <div class="node-status-circle ' . ($hasShopify ? 'status-ok' : 'status-warn') . '">
            ' . ($hasShopify ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : '!') . '
          </div>
          <div class="integration-info">
            <span class="int-title">' . ($hasShopify ? 'Shopify' : 'Not Set') . '</span>
            <span class="int-desc ' . ($hasShopify ? 'active' : 'action') . '">' . ($hasShopify ? 'Connected' : 'Action required') . '</span>
          </div>
        </div>

        <!-- Shipping Row -->
        <div class="branch-row">
          <div class="integration-pill ' . ($hasAnyShipping ? 'pill-green' : 'pill-empty') . '">Shipping Company</div>
          <div class="connector-dashed" style="flex:0 0 40px;"><div class="line-dashed"></div><div class="arrow-head"></div></div>
          <div class="node-platform ' . (!$hasAnyShipping ? 'empty' : '') . '" ' . ($hasAnyShipping ? 'onclick="openShipModal(' . $shopId . ')"' : 'onclick="openShipModal(' . $shopId . ')"') . '>
            ' . ($hasFiabilo ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2" ry="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>' : ($hasIntigo ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2" ry="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>' : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>')) . '
          </div>
          <div class="connector-dashed" style="flex:0 0 40px;"><div class="line-dashed"></div><div class="arrow-head"></div></div>
          <div class="node-status-circle ' . ($hasAnyShipping ? 'status-ok' : 'status-warn') . '">
            ' . ($hasAnyShipping ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : '!') . '
          </div>
          <div class="integration-info">
            <span class="int-title">' . ($hasFiabilo ? 'FIABILO' : ($hasIntigo ? 'INTIGO' : 'Not Set')) . '</span>
            <span class="int-desc ' . ($hasAnyShipping ? 'active' : 'action') . '">' . ($hasAnyShipping ? 'Connected' : 'Action required') . '</span>
          </div>
        </div>
      </div>
    </div>
    </div>';
    return $html;
}

// Render existing shops
if (!empty($allShops)) {
    $delay = 0;
    foreach ($allShops as $s) {
        $content .= '<div style="animation-delay: ' . $delay . 's">' . renderWorkflowCard($s, $hasFiabilo, $hasIntigo, false) . '</div>';
        $delay += 0.1;
    }
} else {
    // If no shops at all, render one visible new template
    $content .= renderWorkflowCard(null, $hasFiabilo, $hasIntigo, false);
}

// Render hidden template for new shops (if we already have some)
if (!empty($allShops)) {
    $content .= renderWorkflowCard(null, $hasFiabilo, $hasIntigo, true);
}

$content .= '
  <div class="add-shop-card" onclick="showNewWf()">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
    Add a new shop
  </div>
</div>

<!-- MODALS -->
<div class="wf-modal" id="ecom-modal">
  <div class="wf-modal-box">
    <div class="wf-modal-head">
      <h3>Choose Platform</h3>
      <button class="wf-modal-close" onclick="closeWfModal(\'ecom-modal\')">&times;</button>
    </div>
    <div class="wf-modal-body">
      <div class="prov-grid">
        <div class="prov-item" onclick="selectPlatform(this)">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#95bf47" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
          <span>Shopify</span>
        </div>
      </div>
    </div>
    <div class="wf-modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closeWfModal(\'ecom-modal\')">Cancel</button>
      <button type="button" class="btn" id="ecom-continue" onclick="continueEcom()" disabled>Continue</button>
    </div>
  </div>
</div>

<div class="wf-modal" id="shopify-modal">
  <div class="wf-modal-box">
    <div class="wf-modal-head">
      <h3>Shopify Webhook</h3>
      <button class="wf-modal-close" onclick="closeWfModal(\'shopify-modal\')">&times;</button>
    </div>
    <div class="wf-modal-body">
      <div style="text-align:center; padding:1rem;">
        <p style="color:#64748b; margin-bottom:1.5rem; line-height:1.5;">To connect Shopify, you need to copy your unique Webhook URL from your Shop Settings page.</p>
        <a href="#" id="shop-settings-link" class="btn" style="width:100%; display:block; text-align:center; padding:12px;">Go to Shop Settings</a>
      </div>
    </div>
  </div>
</div>

<div class="wf-modal" id="ship-modal">
  <div class="wf-modal-box">
    <div class="wf-modal-head">
      <h3>Choose Shipping</h3>
      <button class="wf-modal-close" onclick="closeWfModal(\'ship-modal\')">&times;</button>
    </div>
    <div class="wf-modal-body">
      <div class="prov-grid">
        <div class="prov-item ' . ($hasFiabilo ? 'selected' : '') . '" onclick="selectShip(\'fiabilo\', this)">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2" ry="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          <span>FIABILO</span>
        </div>
        <div class="prov-item ' . ($hasIntigo ? 'selected' : '') . '" onclick="selectShip(\'intigo\', this)">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13" rx="2" ry="2"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
          <span>INTIGO</span>
        </div>
      </div>
    </div>
    <div class="wf-modal-foot">
      <button type="button" class="btn btn-secondary" onclick="closeWfModal(\'ship-modal\')">Cancel</button>
      <button type="button" class="btn" id="ship-continue" onclick="continueShip()" ' . ($hasFiabilo || $hasIntigo ? '' : 'disabled') . '>Continue</button>
    </div>
  </div>
</div>

<div class="wf-modal" id="fiabilo-modal">
  <div class="wf-modal-box">
    <div class="wf-modal-head">
      <h3>FIABILO API</h3>
      <button class="wf-modal-close" onclick="closeWfModal(\'fiabilo-modal\')">&times;</button>
    </div>
    <form method="post">
      <div class="wf-modal-body">
        <div class="form-group">
          <label class="form-label">Add Token</label>
          <input type="password" name="add_token" class="form-input" placeholder="' . ($hasFiabilo ? 'Leave blank to keep current' : 'Enter token') . '">
        </div>
        <div class="form-group">
          <label class="form-label">Tracking Token</label>
          <input type="password" name="tracking_token" class="form-input" placeholder="' . ($hasFiabilo ? 'Leave blank to keep current' : 'Enter token') . '">
        </div>
        ' . ($hasFiabilo ? '<div style="background:#d1fae5; padding:0.75rem; border-radius:8px; text-align:center; color:#065f46; font-weight:600; font-size:0.9rem; margin-top:1rem;">✓ Connected</div>' : '') . '
      </div>
      <div class="wf-modal-foot">
        <button type="button" class="btn btn-secondary" onclick="closeWfModal(\'fiabilo-modal\')">Cancel</button>
        ' . ($hasFiabilo ? '<button type="submit" name="delete_fiabilo" value="1" class="btn" style="background:#fecaca; color:#b91c1c; border:1px solid #fca5a5" onclick="return confirm(\'Disconnect FIABILO?\')">Disconnect</button>' : '') . '
        <button type="submit" name="save_fiabilo" value="1" class="btn">' . ($hasFiabilo ? 'Update' : 'Connect') . '</button>
      </div>
    </form>
  </div>
</div>

<div class="wf-modal" id="intigo-modal">
  <div class="wf-modal-box">
    <div class="wf-modal-head">
      <h3>INTIGO API</h3>
      <button class="wf-modal-close" onclick="closeWfModal(\'intigo-modal\')">&times;</button>
    </div>
    <form method="post">
      <div class="wf-modal-body">
        <div class="form-group">
          <label class="form-label">API Key</label>
          <input type="password" name="add_token" class="form-input" placeholder="' . ($hasIntigo ? 'Leave blank to keep current' : 'Enter API Key') . '">
        </div>
        <div class="form-group">
          <label class="form-label">Merchant ID (cid)</label>
          <input type="text" name="tracking_token" class="form-input" placeholder="' . ($hasIntigo ? 'Leave blank to keep current' : 'Enter Merchant ID') . '" value="' . ($hasIntigo ? '' : '') . '">
        </div>
        <div class="form-group" style="margin-top: 1rem;">
          <label class="form-label">Environment</label>
          <div style="display: flex; gap: 1rem;">
            <label style="display: flex; align-items: center; gap: 4px; cursor: pointer;">
              <input type="radio" name="intigo_mode" value="prod" ' . ($isIntigoSandbox ? '' : 'checked') . '> Production
            </label>
            <label style="display: flex; align-items: center; gap: 4px; cursor: pointer;">
              <input type="radio" name="intigo_mode" value="sandbox" ' . ($isIntigoSandbox ? 'checked' : '') . '> Sandbox / Dev
            </label>
          </div>
        </div>
        ' . ($testResultMsg ?? '') . '
        ' . ($hasIntigo ? '<div style="background:#d1fae5; padding:0.75rem; border-radius:8px; text-align:center; color:#065f46; font-weight:600; font-size:0.9rem; margin-top:1rem;">✓ Connected</div>' : '') . '
      </div>
      <div class="wf-modal-foot">
        <button type="button" class="btn btn-secondary" onclick="closeWfModal(\'intigo-modal\')">Cancel</button>
        ' . ($hasIntigo ? '<button type="submit" name="delete_intigo" value="1" class="btn" style="background:#fecaca; color:#b91c1c; border:1px solid #fca5a5" onclick="return confirm(\'Disconnect INTIGO?\')">Disconnect</button>' : '') . '
        <button type="submit" name="test_intigo" value="1" class="btn btn-secondary">Test Connection</button>
        <button type="submit" name="save_intigo" value="1" class="btn">' . ($hasIntigo ? 'Update' : 'Connect') . '</button>
      </div>
    </form>
  </div>
</div>

<!-- COMING SOON MODAL -->
<div class="wf-modal" id="coming-soon-modal">
  <div class="wf-modal-box">
    <div class="wf-modal-head">
      <h3 id="cs-title">Coming Soon</h3>
      <button class="wf-modal-close" onclick="closeWfModal(\'coming-soon-modal\')">&times;</button>
    </div>
    <div class="wf-modal-body" style="text-align:center; padding: 2rem;">
      <div style="width: 64px; height: 64px; background: #fef3c7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; color: #d97706;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:32px; height:32px;"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <p style="color: #475569; font-weight: 500; line-height: 1.5;">This feature is currently under development. Stay tuned for updates!</p>
    </div>
    <div class="wf-modal-foot">
      <button class="btn" onclick="closeWfModal(\'coming-soon-modal\')">Got it</button>
    </div>
  </div>
</div>

<script>
let currentShopId = 0;
let selectedShipProvider = "' . ($hasFiabilo ? 'fiabilo' : ($hasIntigo ? 'intigo' : '')) . '";

function openWfModal(id) { document.getElementById(id).classList.add("open"); }
function closeWfModal(id) { document.getElementById(id).classList.remove("open"); }

function toggleDropdown(id, event) {
  event.stopPropagation();
  // Close others
  document.querySelectorAll(".dropdown-menu-list").forEach(m => {
    if(m.id !== "dropdown-" + id) m.classList.remove("show");
  });
  document.getElementById("dropdown-" + id).classList.toggle("show");
}

window.onclick = function(event) {
  if (!event.target.matches(".btn-plus-int") && !event.target.closest(".btn-plus-int")) {
    document.querySelectorAll(".dropdown-menu-list").forEach(m => m.classList.remove("show"));
  }
}

function openComingSoon(title) {
  document.getElementById("cs-title").innerText = title;
  openWfModal("coming-soon-modal");
}

function openEcomModal(shopId) {
    currentShopId = shopId;
    openWfModal("ecom-modal");
}

function openShopifyModal(shopId) {
    let link = document.getElementById("shop-settings-link");
    link.href = "index.php?page=shop-view&id=" + shopId + "&return=integration";
    openWfModal("shopify-modal");
}

function openShipModal(shopId) {
    currentShopId = shopId;
    openWfModal("ship-modal");
}

function showNewWf() {
    let tpl = document.getElementById("new-wf-template");
    if(tpl) tpl.style.display = "flex";
}
function hideNewWf() {
    let tpl = document.getElementById("new-wf-template");
    if(tpl) tpl.style.display = "none";
}

function selectPlatform(el) {
  document.querySelectorAll("#ecom-modal .prov-item").forEach(e => e.classList.remove("selected"));
  el.classList.add("selected");
  document.getElementById("ecom-continue").disabled = false;
}

function continueEcom() {
  closeWfModal("ecom-modal");
  openShopifyModal(currentShopId);
}

function selectShip(provider, el) {
  selectedShipProvider = provider;
  document.querySelectorAll("#ship-modal .prov-item").forEach(e => e.classList.remove("selected"));
  el.classList.add("selected");
  document.getElementById("ship-continue").disabled = false;
}

function continueShip() {
  closeWfModal("ship-modal");
  if(selectedShipProvider === "fiabilo") openWfModal("fiabilo-modal");
  else if(selectedShipProvider === "intigo") openWfModal("intigo-modal");
}

document.querySelectorAll(".wf-modal").forEach(m => {
  m.addEventListener("click", e => { if (e.target === m) m.classList.remove("open"); });
});

' . (isset($testResultMsg) ? 'openWfModal("intigo-modal");' : '') . '
</script>
';

require $base . '/layouts/layout.php';
