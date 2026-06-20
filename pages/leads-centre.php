<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'leads-centre';
$pageTitle = 'Leads Centre';

// Fetch all orders with shop info for the logged-in user
$st = $app->pdo->prepare("
    SELECT o.id, o.name, o.billing_name, o.billing_phone, o.phone, o.confirmed, o.follow_up, o.fiabilo_status, o.intigo_status, s.name as shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE s.user_id = ?
    ORDER BY o.created_at DESC
");
$st->execute([$uid]);
$orders = $st->fetchAll();

$intake = [];
$unqualified = [];
$qualified = [];
$customer = [];

$deliveredStatuses = ['livré', 'livrés', 'livrer', 'delivered', 'reçu', 'livree'];

foreach ($orders as $o) {
    $fStatus = mb_strtolower($o['fiabilo_status'] ?? '');
    $iStatus = mb_strtolower($o['intigo_status'] ?? '');
    
    $isDelivered = in_array($fStatus, $deliveredStatuses) || in_array($iStatus, $deliveredStatuses);
    
    $name = $o['billing_name'] ?: 'Guest';
    $phone = $o['billing_phone'] ?: $o['phone'] ?: 'No phone';
    $item = [
        'id' => $o['id'],
        'name' => $name,
        'phone' => $phone,
        'shop' => $o['shop_name'],
        'orderNum' => $o['name']
    ];

    if ($isDelivered) {
        $customer[] = $item;
    } elseif ($o['confirmed'] == 1) {
        $qualified[] = $item;
    } elseif ($o['follow_up'] == 1) {
        $unqualified[] = $item;
    } else {
        $intake[] = $item;
    }
}

// Asset prefix for images and styles
$assetPrefix = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

$content = '
<div class="leads-centre-container">
    <div class="pixel-promo-card">
        <div class="pixel-promo-content">
            <div class="pixel-promo-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
            </div>
            <div class="pixel-promo-text">
                <h2>Take your leads to your pixel</h2>
                <p>Scale your business by feeding your lead data back to your advertising platforms.</p>
            </div>
        </div>
        <button class="pixel-promo-btn" onclick="openPixelModal()">Do it</button>
    </div>

    <div class="leads-header">
        <div class="header-text">
            <h1>Leads Centre</h1>
            <p>Track your customer journey from intake to successful delivery</p>
        </div>
        <div class="leads-stats">
            <div class="stat-pill">Total Leads: <strong>' . count($orders) . '</strong></div>
        </div>
    </div>

    <div class="leads-board">
        <!-- Intake Column -->
        <div class="leads-column">
            <div class="column-header intake">
                <div class="header-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg></div>
                <div class="header-info">
                    <h3>Intake</h3>
                    <span>' . count($intake) . ' orders</span>
                </div>
            </div>
            <div class="leads-list">
                ' . renderLeadItems($intake) . '
            </div>
        </div>

        <!-- Unqualified Column -->
        <div class="leads-column">
            <div class="column-header unqualified">
                <div class="header-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div>
                <div class="header-info">
                    <h3>Unqualified</h3>
                    <span>' . count($unqualified) . ' orders</span>
                </div>
            </div>
            <div class="leads-list">
                ' . renderLeadItems($unqualified) . '
            </div>
        </div>

        <!-- Qualified Column -->
        <div class="leads-column">
            <div class="column-header qualified">
                <div class="header-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg></div>
                <div class="header-info">
                    <h3>Qualified</h3>
                    <span>' . count($qualified) . ' orders</span>
                </div>
            </div>
            <div class="leads-list">
                ' . renderLeadItems($qualified) . '
            </div>
        </div>

        <!-- Customer Column -->
        <div class="leads-column">
            <div class="column-header customer">
                <div class="header-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                <div class="header-info">
                    <h3>Customer</h3>
                    <span>' . count($customer) . ' orders</span>
                </div>
            </div>
            <div class="leads-list">
                ' . renderLeadItems($customer) . '
            </div>
        </div>
    </div>
</div>

<!-- Pixel Modal -->
<div id="pixelModal" class="modal-overlay">
    <div class="modal-card">
        <button class="modal-close" onclick="closePixelModal()">&times;</button>
        
        <!-- Step 1: Intro -->
        <div id="pixelStep1" class="modal-step active">
            <div class="modal-split">
                <div class="modal-left">
                    <img src="' . $assetPrefix . '/logo/Online%20shopping,%20buying%20clothes%20in%20internet%20vector.jpeg" alt="Shopping Vector">
                </div>
                <div class="modal-right">
                    <h2>Boost your sales with Pixel Data</h2>
                    <p>Uploading your leads to your Pixel allows you to create highly targeted Lookalike Audiences and optimize your retargeting campaigns.</p>
                    <p>By feeding conversion data back into the platform, you help the AI find more customers like your existing ones, significantly increasing your conversion rate and lowering your acquisition costs.</p>
                    <button class="modal-next-btn" onclick="nextStep()">Next &rarr;</button>
                </div>
            </div>
        </div>

        <!-- Step 2: Selection -->
        <div id="pixelStep2" class="modal-step">
            <div class="modal-selection-header">
                <h2>Export your data</h2>
                <p>Select which leads you want to export for your pixel.</p>
            </div>
            <div class="export-options">
                <label class="export-option">
                    <input type="checkbox" id="checkQualified" checked>
                    <div class="option-content">
                        <span class="option-title">Qualified Leads</span>
                        <span class="option-desc">Confirmed orders waiting for delivery</span>
                    </div>
                </label>
                <label class="export-option">
                    <input type="checkbox" id="checkCustomer" checked>
                    <div class="option-content">
                        <span class="option-title">Customers</span>
                        <span class="option-desc">Orders successfully delivered</span>
                    </div>
                </label>
            </div>
            <div class="modal-footer">
                <button class="modal-export-btn" onclick="exportLeads()">Export Excel</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Pixel Promo Card */
.pixel-promo-card { 
    background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%); 
    border-radius: 1.5rem; 
    padding: 2.5rem; 
    display: flex; 
    align-items: center; 
    margin-bottom: 2rem; 
    color: #fff; 
    box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.2); 
    border: 1px solid rgba(255,255,255,0.1); 
    position: relative;
    overflow: hidden;
}
.pixel-promo-content { display: flex; align-items: center; gap: 1.5rem; max-width: 70%; }
.pixel-promo-icon { width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 12px; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
.pixel-promo-text h2 { margin: 0; font-size: 1.25rem; font-weight: 850; font-family: var(--font-syne); }
.pixel-promo-text p { margin: 0.25rem 0 0 0; font-size: 0.95rem; opacity: 0.9; }

.pixel-promo-btn { 
    position: absolute;
    top: 1.5rem;
    right: 2rem;
    background: #fff; 
    color: #2563eb; 
    border: none; 
    padding: 0.75rem 1.75rem; 
    border-radius: 12px; 
    font-weight: 800; 
    cursor: pointer; 
    transition: all 0.2s; 
    font-size: 0.95rem; 
    z-index: 10;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.pixel-promo-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(255,255,255,0.3); }

/* Modal Styles */
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 9999; }
.modal-overlay.active { display: flex; }
.modal-card { background: #fff; width: 90%; max-width: 800px; border-radius: 2rem; position: relative; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); animation: modalIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes modalIn { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }

.modal-close { position: absolute; top: 1.5rem; right: 1.5rem; background: #f1f5f9; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; z-index: 10; transition: all 0.2s; }
.modal-close:hover { background: #e2e8f0; transform: rotate(90deg); }

.modal-step { display: none; padding: 3.5rem; }
.modal-step.active { display: block; }

.modal-split { display: grid; grid-template-columns: 1fr 1.2fr; gap: 3rem; align-items: center; }
.modal-left img { width: 100%; height: auto; border-radius: 1.5rem; border: 1px solid #f1f5f9; }
.modal-right h2 { font-family: var(--font-syne); font-size: 1.75rem; font-weight: 850; margin-bottom: 1.25rem; color: #0f172a; }
.modal-right p { font-size: 1rem; color: #475569; line-height: 1.6; margin-bottom: 1.25rem; }
.modal-next-btn { background: #2563eb; color: #fff; border: none; padding: 1rem 2rem; border-radius: 12px; font-weight: 750; cursor: pointer; transition: all 0.2s; }
.modal-next-btn:hover { background: #1d4ed8; transform: translateX(5px); }

.modal-selection-header { text-align: center; margin-bottom: 2.5rem; }
.modal-selection-header h2 { font-family: var(--font-syne); font-size: 1.75rem; font-weight: 850; margin-bottom: 0.5rem; }
.modal-selection-header p { color: #64748b; }

.export-options { display: grid; gap: 1rem; margin-bottom: 2.5rem; }
.export-option { display: flex; align-items: flex-start; gap: 1rem; padding: 1.5rem; border: 2px solid #f1f5f9; border-radius: 1.25rem; cursor: pointer; transition: all 0.2s; }
.export-option:hover { border-color: #2563eb; background: #f8faff; }
.export-option input[type="checkbox"] { width: 22px; height: 22px; margin-top: 2px; cursor: pointer; accent-color: #2563eb; }
.option-title { display: block; font-weight: 800; font-size: 1.05rem; color: #0f172a; margin-bottom: 0.15rem; }
.option-desc { font-size: 0.85rem; color: #64748b; }

.modal-footer { display: flex; justify-content: center; }
.modal-export-btn { background: #059669; color: #fff; border: none; padding: 1.25rem 4rem; border-radius: 12px; font-weight: 750; cursor: pointer; transition: all 0.2s; font-size: 1.1rem; }
.modal-export-btn:hover { background: #047857; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3); }

.leads-centre-container { max-width: 1400px; margin: 0 auto; padding: 1rem 0; }
.leads-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1.5rem; }
.leads-header h1 { font-family: var(--font-syne); font-size: 2.25rem; font-weight: 850; margin: 0; color: #0f172a; letter-spacing: -0.02em; }
.leads-header p { color: #64748b; margin: 0.25rem 0 0 0; font-weight: 500; }
.stat-pill { background: #f1f5f9; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.85rem; font-weight: 600; color: #475569; border: 1px solid #e2e8f0; }

.leads-board { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; align-items: start; }
@media(max-width: 1100px) { .leads-board { grid-template-columns: repeat(2, 1fr); } }
@media(max-width: 600px) { .leads-board { grid-template-columns: 1fr; } }

.leads-column { background: #f8fafc; border-radius: 1.25rem; border: 1px solid #e2e8f0; display: flex; flex-direction: column; min-height: 400px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }
.column-header { padding: 1.25rem; border-radius: 1.25rem 1.25rem 0 0; display: flex; align-items: center; gap: 1rem; border-bottom: 2px solid rgba(0,0,0,0.05); }

.header-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #fff; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.header-info h3 { margin: 0; font-size: 1rem; font-weight: 800; font-family: var(--font-syne); }
.header-info span { font-size: 0.75rem; font-weight: 600; opacity: 0.7; }

.column-header.intake { background: #eff6ff; color: #1d4ed8; }
.column-header.intake .header-icon { color: #1d4ed8; }

.column-header.unqualified { background: #fff1f2; color: #be123c; }
.column-header.unqualified .header-icon { color: #be123c; }

.column-header.qualified { background: #ecfdf5; color: #047857; }
.column-header.qualified .header-icon { color: #047857; }

.column-header.customer { background: #f0f9ff; color: #0369a1; }
.column-header.customer .header-icon { color: #0369a1; }

.leads-list { padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; max-height: 70vh; overflow-y: auto; }
.leads-list::-webkit-scrollbar { width: 4px; }
.leads-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

.lead-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 1rem; padding: 1.25rem; transition: all 0.2s; position: relative; box-shadow: 0 1px 2px rgba(0,0,0,0.05); text-decoration: none; display: block; color: inherit; }
.lead-card:hover { transform: translateY(-2px); border-color: #0ea5e9; box-shadow: 0 10px 15px -3px rgba(14, 165, 233, 0.1); }

.lead-name { font-weight: 800; font-size: 0.95rem; color: #1e293b; margin-bottom: 0.25rem; display: block; }
.lead-phone { font-family: var(--font-mono); font-size: 0.85rem; color: #0ea5e9; font-weight: 700; display: block; }
.lead-shop { font-size: 0.7rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-top: 0.75rem; display: flex; align-items: center; gap: 4px; }
.lead-shop svg { opacity: 0.5; }

.empty-leads { text-align: center; padding: 3rem 1rem; color: #94a3b8; font-weight: 600; font-size: 0.85rem; }
</style>

<script>
function openPixelModal() {
    console.log("Opening Modal...");
    document.getElementById(\'pixelModal\').classList.add(\'active\');
    document.body.style.overflow = \'hidden\';
}

function closePixelModal() {
    document.getElementById(\'pixelModal\').classList.remove(\'active\');
    document.body.style.overflow = \'\';
}

function nextStep() {
    document.getElementById(\'pixelStep1\').classList.remove(\'active\');
    document.getElementById(\'pixelStep2\').classList.add(\'active\');
}

function exportLeads() {
    const qualified = document.getElementById(\'checkQualified\').checked;
    const customer = document.getElementById(\'checkCustomer\').checked;
    
    if (!qualified && !customer) {
        alert(\'Please select at least one lead type to export.\');
        return;
    }
    
    const types = [];
    if (qualified) types.push(\'qualified\');
    if (customer) types.push(\'customer\');
    
    window.location.href = \'index.php?page=leads-export-ajax&types=\' + types.join(\',\');
}

// Close modal on click outside
document.getElementById(\'pixelModal\').addEventListener(\'click\', function(e) {
    if (e.target === this) closePixelModal();
});
</script>
';

function renderLeadItems($list) {
    if (empty($list)) {
        return '<div class="empty-leads">No orders found</div>';
    }
    $html = '';
    foreach ($list as $item) {
        $html .= '
        <a href="index.php?page=order-view&id=' . $item['id'] . '" class="lead-card">
            <span class="lead-name">' . htmlspecialchars($item['name']) . '</span>
            <span class="lead-phone">' . htmlspecialchars($item['phone']) . '</span>
            <div class="lead-shop">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                ' . htmlspecialchars($item['shop']) . '
            </div>
        </a>';
    }
    return $html;
}

require $base . '/layouts/layout.php';
