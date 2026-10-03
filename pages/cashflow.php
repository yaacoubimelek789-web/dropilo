<?php
$uid = (int) $_SESSION['user_id'];
$currentPage = 'cashflow';
$pageTitle = 'Cash Flow';
$encKey = $app->app['encryption_key'] ?? '';

CashflowHelper::ensureSchema($app->pdo);

$message = '';
$settings = CashflowHelper::getSettings($app->pdo, $uid);

// Date range
$dateRange = $_GET['range'] ?? '7';
$customFrom = $_GET['from'] ?? '';
$customTo = $_GET['to'] ?? '';
$startDate = date('Y-m-d');
$endDate = date('Y-m-d');
if ($dateRange === 'custom' && $customFrom && $customTo) {
    $startDate = date('Y-m-d', strtotime($customFrom));
    $endDate = date('Y-m-d', strtotime($customTo));
} elseif ($dateRange === 'yesterday') {
    $startDate = $endDate = date('Y-m-d', strtotime('-1 day'));
} elseif ($dateRange === '30') {
    $startDate = date('Y-m-d', strtotime('-29 days'));
} elseif ($dateRange === 'this_month') {
    $startDate = date('Y-m-01');
} elseif ($dateRange === 'last_month') {
    $startDate = date('Y-m-d', strtotime('first day of last month'));
    $endDate = date('Y-m-d', strtotime('last day of last month'));
} elseif ($dateRange === '90') {
    $startDate = date('Y-m-d', strtotime('-89 days'));
} elseif ($dateRange === 'today') {
    $startDate = $endDate = date('Y-m-d');
} else {
    $dateRange = '7';
    $startDate = date('Y-m-d', strtotime('-6 days'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_finance_settings'])) {
        CashflowHelper::saveSettings($app->pdo, $uid, [
            'courier_fee_delivered' => $_POST['courier_fee_delivered'] ?? 8,
            'courier_fee_return' => $_POST['courier_fee_return'] ?? 8,
            'handling_fee_pct' => $_POST['handling_fee_pct'] ?? 3,
            'currency' => $_POST['currency'] ?? 'TND',
            'meta_ad_account_id' => $_POST['meta_ad_account_id'] ?? $settings['meta_ad_account_id'],
        ]);
        header('Location: index.php?page=cashflow&range=' . urlencode($dateRange) . '&from=' . urlencode($customFrom) . '&to=' . urlencode($customTo) . '&saved=1');
        exit;
    }
    if (isset($_POST['add_finance_entry'])) {
        $type = trim($_POST['entry_type'] ?? 'charge');
        $amount = (float) ($_POST['amount'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
        $notes = trim($_POST['notes'] ?? '');
        if ($amount > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate)) {
            CashflowHelper::addEntry($app->pdo, $uid, $entryDate, $type, $amount, $label, $notes);
            header('Location: index.php?page=cashflow&range=' . urlencode($dateRange) . '&saved=1');
            exit;
        }
        $message = '<div class="alert alert-error">Enter a valid amount and date.</div>';
    }
    if (isset($_POST['delete_finance_entry'])) {
        CashflowHelper::deleteEntry($app->pdo, $uid, (int) ($_POST['entry_id'] ?? 0));
        header('Location: index.php?page=cashflow&range=' . urlencode($dateRange) . '&saved=1');
        exit;
    }
    if (isset($_POST['sync_meta_ads'])) {
        $res = FacebookAdsHelper::syncInsights($app->pdo, $uid, $encKey, $startDate, $endDate);
        if (isset($res['error'])) {
            $message = '<div class="alert alert-error">Meta sync: ' . htmlspecialchars($res['error']) . '</div>';
        } else {
            header('Location: index.php?page=cashflow&range=' . urlencode($dateRange) . '&synced=' . (int) ($res['days'] ?? 0));
            exit;
        }
    }
}

if (isset($_GET['saved'])) {
    $message = '<div class="alert alert-success">Saved.</div>';
}
if (isset($_GET['synced'])) {
    $message = '<div class="alert alert-success">Synced ' . (int) $_GET['synced'] . ' day(s) from Meta Ads.</div>';
}

$settings = CashflowHelper::getSettings($app->pdo, $uid);
$stats = CashflowHelper::compute($app->pdo, $uid, $startDate, $endDate, $settings);
$series = CashflowHelper::dailySeries($app->pdo, $uid, $startDate, $endDate);
$entries = CashflowHelper::listEntries($app->pdo, $uid, $startDate, $endDate, 40);
$hasMeta = FacebookAdsHelper::hasCredentials($app->pdo, $uid, $encKey);
$currency = htmlspecialchars($stats['currency']);

$fmt = static function ($n, int $dec = 2): string {
    if ($n === null) {
        return '—';
    }
    return number_format((float) $n, $dec);
};
$money = static function ($n) use ($fmt, $currency): string {
    if ($n === null) {
        return '—';
    }
    return $fmt($n) . ' <small>' . $currency . '</small>';
};

$p = $stats['pipeline'];
$ads = $stats['ads'];
$res = $stats['result'];
$rev = $stats['revenue'];
$costs = $stats['costs'];
$income = $stats['income'];

$content = $message;
$content .= '
<style>
.cf-wrap { max-width: 1280px; margin: 0 auto; padding: 0 0 3rem; }
.cf-header { display:flex; flex-wrap:wrap; justify-content:space-between; gap:1rem; align-items:flex-end; margin-bottom:1.25rem; }
.cf-header h1 { margin:0; font-size:1.75rem; font-weight:800; letter-spacing:-0.03em; color:#0f172a; }
.cf-header p { margin:0.35rem 0 0; color:#64748b; font-size:0.95rem; }
.cf-pills { display:flex; flex-wrap:wrap; gap:0.4rem; }
.cf-pills a, .cf-pills button.pill {
  appearance:none; border:1px solid #e2e8f0; background:#fff; color:#475569; border-radius:999px;
  padding:0.45rem 0.85rem; font-size:0.8rem; font-weight:700; text-decoration:none; cursor:pointer;
}
.cf-pills a.active, .cf-pills button.pill.active { background:#0f172a; color:#fff; border-color:#0f172a; }
.cf-grid-8 {
  display:grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap:0.85rem; margin-bottom:1.25rem;
}
@media (max-width: 1100px) { .cf-grid-8 { grid-template-columns: repeat(2, minmax(0,1fr)); } }
@media (max-width: 560px) { .cf-grid-8 { grid-template-columns: 1fr; } }
.cf-card {
  background:#fff; border:1px solid #e8edf3; border-radius:16px; padding:1.05rem 1.1rem;
  position:relative; overflow:hidden; box-shadow:0 1px 2px rgba(15,23,42,0.04);
}
.cf-card .label { font-size:0.72rem; font-weight:800; letter-spacing:0.06em; text-transform:uppercase; color:#64748b; display:flex; align-items:center; gap:0.4rem; }
.cf-card .value { font-size:1.55rem; font-weight:800; color:#0f172a; margin-top:0.45rem; letter-spacing:-0.02em; line-height:1.15; }
.cf-card .value small { font-size:0.75rem; font-weight:600; color:#94a3b8; }
.cf-card .sub { margin-top:0.4rem; font-size:0.8rem; color:#94a3b8; font-weight:500; }
.cf-card.pos .value { color:#059669; }
.cf-card.neg .value { color:#dc2626; }
.cf-card.warn .value { color:#d97706; }
.cf-section-title { font-size:0.95rem; font-weight:800; color:#0f172a; margin:1.5rem 0 0.75rem; display:flex; align-items:center; justify-content:space-between; gap:0.75rem; }
.cf-two { display:grid; grid-template-columns: 1.4fr 1fr; gap:1rem; }
@media (max-width: 960px) { .cf-two { grid-template-columns: 1fr; } }
.cf-panel { background:#fff; border:1px solid #e8edf3; border-radius:16px; padding:1.15rem; }
.cf-panel h3 { margin:0 0 0.85rem; font-size:0.95rem; font-weight:800; }
.cf-flow { display:flex; flex-direction:column; gap:0.55rem; }
.cf-flow-row { display:flex; justify-content:space-between; gap:1rem; font-size:0.9rem; padding:0.55rem 0.65rem; border-radius:10px; background:#f8fafc; }
.cf-flow-row strong { font-weight:700; color:#0f172a; }
.cf-flow-row.total { background:#0f172a; color:#fff; }
.cf-flow-row.total strong { color:#fff; }
.cf-flow-row .minus { color:#dc2626; }
.cf-flow-row .plus { color:#059669; }
.cf-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; }
@media (max-width: 640px) { .cf-form-grid { grid-template-columns:1fr; } }
.cf-form-grid label { display:block; font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#64748b; margin-bottom:0.3rem; }
.cf-form-grid input, .cf-form-grid select, .cf-form-grid textarea {
  width:100%; padding:0.65rem 0.75rem; border:1px solid #e2e8f0; border-radius:10px; font-weight:600; font-size:0.9rem;
}
.cf-actions { display:flex; flex-wrap:wrap; gap:0.5rem; margin-top:0.85rem; }
.cf-actions .btn { min-height:42px; }
.cf-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
.cf-table th { text-align:left; font-size:0.7rem; text-transform:uppercase; color:#94a3b8; padding:0.55rem 0.4rem; border-bottom:1px solid #e2e8f0; }
.cf-table td { padding:0.65rem 0.4rem; border-bottom:1px solid #f1f5f9; color:#334155; }
.cf-badge { display:inline-block; padding:0.2rem 0.55rem; border-radius:999px; font-size:0.7rem; font-weight:800; }
.cf-badge.charge { background:#fee2e2; color:#b91c1c; }
.cf-badge.cashback { background:#dcfce7; color:#166534; }
.cf-badge.ad_spend { background:#dbeafe; color:#1d4ed8; }
.cf-badge.other_income { background:#dcfce7; color:#166534; }
.cf-badge.other_expense { background:#ffedd5; color:#c2410c; }
.cf-hint { font-size:0.8rem; color:#64748b; line-height:1.45; margin:0 0 0.75rem; }
.cf-meta-bar { display:flex; flex-wrap:wrap; gap:0.6rem; align-items:center; margin-bottom:1rem; padding:0.85rem 1rem; border-radius:14px; background:linear-gradient(135deg,#eff6ff,#f8fafc); border:1px solid #dbeafe; }
.cf-meta-bar strong { color:#1e3a8a; }
</style>

<div class="cf-wrap">
  <div class="cf-header">
    <div>
      <h1>Cash Flow Hub</h1>
      <p>Real COD economics: delivered revenue − COGS − courier − ads ± charges/cashback</p>
    </div>
    <div class="cf-pills">
      <a class="' . ($dateRange === 'today' ? 'active' : '') . '" href="index.php?page=cashflow&range=today">Today</a>
      <a class="' . ($dateRange === 'yesterday' ? 'active' : '') . '" href="index.php?page=cashflow&range=yesterday">Yesterday</a>
      <a class="' . ($dateRange === '7' ? 'active' : '') . '" href="index.php?page=cashflow&range=7">7D</a>
      <a class="' . ($dateRange === '30' ? 'active' : '') . '" href="index.php?page=cashflow&range=30">30D</a>
      <a class="' . ($dateRange === 'this_month' ? 'active' : '') . '" href="index.php?page=cashflow&range=this_month">Month</a>
      <a class="' . ($dateRange === 'last_month' ? 'active' : '') . '" href="index.php?page=cashflow&range=last_month">Last month</a>
    </div>
  </div>

  <div class="cf-meta-bar">
    <strong>' . ($hasMeta ? 'Meta Ads connected' : 'Meta Ads not connected') . '</strong>
    <span style="color:#64748b;font-size:0.85rem;">' . ($hasMeta
        ? 'Account act_' . htmlspecialchars($settings['meta_ad_account_id'] ?: '—') . ' · Sync pulls spend, CPM, CTR, CPC'
        : 'Connect in Integration, then sync spend into this hub') . '</span>
    <form method="post" style="margin-left:auto;display:flex;gap:0.4rem;">
      <button type="submit" name="sync_meta_ads" value="1" class="btn" ' . ($hasMeta ? '' : 'disabled title="Connect Meta Ads first"') . '>Sync Meta Ads</button>
      <a class="btn btn-secondary" href="index.php?page=integration">Integration</a>
    </form>
  </div>

  <div class="cf-section-title">Ads & acquisition <span style="font-weight:600;color:#94a3b8;font-size:0.8rem;">' . htmlspecialchars($startDate) . ' → ' . htmlspecialchars($endDate) . '</span></div>
  <div class="cf-grid-8">
    <div class="cf-card"><div class="label">Total Spend</div><div class="value">' . $money($ads['spend']) . '</div><div class="sub">Meta + manual ad spend</div></div>
    <div class="cf-card pos"><div class="label">Revenue (Delivered)</div><div class="value">' . $money($rev['delivered']) . '</div><div class="sub">Real COD cash collected</div></div>
    <div class="cf-card"><div class="label">Real ROAS</div><div class="value">' . ($ads['real_roas'] === null ? '—' : $fmt($ads['real_roas'], 2) . 'x') . '</div><div class="sub">Delivered revenue ÷ ad spend</div></div>
    <div class="cf-card"><div class="label">Purchases</div><div class="value">' . number_format($p['delivered']) . '</div><div class="sub">Delivered orders (not FB pixel)</div></div>
    <div class="cf-card"><div class="label">CPP</div><div class="value">' . $money($ads['cpp']) . '</div><div class="sub">Cost per delivered purchase</div></div>
    <div class="cf-card"><div class="label">CPM</div><div class="value">' . $money($ads['cpm']) . '</div><div class="sub">Cost per 1,000 impressions</div></div>
    <div class="cf-card"><div class="label">CTR</div><div class="value">' . ($ads['ctr'] === null ? '—' : $fmt($ads['ctr'], 2) . '%') . '</div><div class="sub">Click-through rate</div></div>
    <div class="cf-card"><div class="label">CPC</div><div class="value">' . $money($ads['cpc']) . '</div><div class="sub">Cost per click</div></div>
  </div>

  <div class="cf-section-title">Order pipeline (truth)</div>
  <div class="cf-grid-8">
    <div class="cf-card"><div class="label">Orders created</div><div class="value">' . number_format($p['orders_created']) . '</div><div class="sub">' . $money($p['orders_created_value']) . ' booked</div></div>
    <div class="cf-card"><div class="label">Confirmed</div><div class="value">' . number_format($p['confirmed']) . '</div><div class="sub">Marked confirmed in range</div></div>
    <div class="cf-card warn"><div class="label">Follow up</div><div class="value">' . number_format($p['followup']) . '</div><div class="sub">Need action</div></div>
    <div class="cf-card pos"><div class="label">Delivered</div><div class="value">' . number_format($p['delivered']) . '</div><div class="sub">Fiabilo livré</div></div>
    <div class="cf-card neg"><div class="label">Retour / refused</div><div class="value">' . number_format($p['returned']) . '</div><div class="sub">' . $money($rev['returned_value']) . ' face value</div></div>
    <div class="cf-card"><div class="label">Delivery rate</div><div class="value">' . $fmt($p['delivery_rate'], 1) . '%</div><div class="sub">Delivered ÷ (delivered+retour)</div></div>
    <div class="cf-card"><div class="label">AOV</div><div class="value">' . $money($rev['aov']) . '</div><div class="sub">Avg delivered order value</div></div>
    <div class="cf-card"><div class="label">Profit / order</div><div class="value">' . $money($res['profit_per_order']) . '</div><div class="sub">Net cash ÷ delivered</div></div>
  </div>

  <div class="cf-two">
    <div class="cf-panel">
      <h3>Cash flow result</h3>
      <p class="cf-hint">Uses Dropilo delivered orders + product cost + your courier/handling settings + Meta spend + manual charges/cashback. This is the number that matters for COD.</p>
      <div class="cf-flow">
        <div class="cf-flow-row"><span>Delivered revenue</span><strong class="plus">+' . $fmt($rev['delivered']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>− Product COGS</span><strong class="minus">−' . $fmt($costs['cogs']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>− Courier fees (delivered)</span><strong class="minus">−' . $fmt($costs['courier_delivered']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>− Handling (' . $fmt($settings['handling_fee_pct'], 1) . '%)</span><strong class="minus">−' . $fmt($costs['handling_fees']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>− Courier fees (retour)</span><strong class="minus">−' . $fmt($costs['courier_return']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>− Return product cost</span><strong class="minus">−' . $fmt($costs['return_cogs']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>− Ad spend</span><strong class="minus">−' . $fmt($costs['ad_spend_total']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>− Extra charges</span><strong class="minus">−' . $fmt($costs['charges']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row"><span>+ Cashback / other income</span><strong class="plus">+' . $fmt($income['cashback']) . ' ' . $currency . '</strong></div>
        <div class="cf-flow-row total"><span>Net cash flow</span><strong>' . ($res['cash_flow'] >= 0 ? '+' : '') . $fmt($res['cash_flow']) . ' ' . $currency . '</strong></div>
      </div>
      <div style="margin-top:0.85rem;display:grid;grid-template-columns:1fr 1fr;gap:0.6rem;">
        <div class="cf-card ' . ($res['net_profit'] >= 0 ? 'pos' : 'neg') . '" style="margin:0;box-shadow:none;"><div class="label">Net profit</div><div class="value" style="font-size:1.25rem;">' . $money($res['net_profit']) . '</div></div>
        <div class="cf-card" style="margin:0;box-shadow:none;"><div class="label">Profit ROAS</div><div class="value" style="font-size:1.25rem;">' . ($ads['profit_roas'] === null ? '—' : $fmt($ads['profit_roas'], 2) . 'x') . '</div></div>
      </div>
      <canvas id="cfChart" height="120" style="margin-top:1rem;"></canvas>
    </div>

    <div>
      <div class="cf-panel" style="margin-bottom:1rem;">
        <h3>Charges & cashback settings</h3>
        <p class="cf-hint">Edit courier fees and handling % used in the cash-flow formula (matches Fiabilo-style deductions).</p>
        <form method="post">
          <div class="cf-form-grid">
            <div><label>Courier fee / delivered</label><input type="number" step="0.01" min="0" name="courier_fee_delivered" value="' . htmlspecialchars((string) $settings['courier_fee_delivered']) . '"></div>
            <div><label>Courier fee / retour</label><input type="number" step="0.01" min="0" name="courier_fee_return" value="' . htmlspecialchars((string) $settings['courier_fee_return']) . '"></div>
            <div><label>Handling fee %</label><input type="number" step="0.1" min="0" max="50" name="handling_fee_pct" value="' . htmlspecialchars((string) $settings['handling_fee_pct']) . '"></div>
            <div><label>Currency</label><input type="text" name="currency" value="' . htmlspecialchars($settings['currency']) . '"></div>
          </div>
          <div class="cf-actions">
            <button type="submit" name="save_finance_settings" value="1" class="btn">Save formula</button>
          </div>
        </form>
      </div>

      <div class="cf-panel">
        <h3>Add charge / cashback / ad spend</h3>
        <form method="post">
          <div class="cf-form-grid">
            <div>
              <label>Type</label>
              <select name="entry_type">
                <option value="charge">Charge / fee</option>
                <option value="cashback">Cashback</option>
                <option value="ad_spend">Manual ad spend</option>
                <option value="other_income">Other income</option>
                <option value="other_expense">Other expense</option>
              </select>
            </div>
            <div><label>Amount</label><input type="number" step="0.01" min="0.01" name="amount" required></div>
            <div><label>Date</label><input type="date" name="entry_date" value="' . date('Y-m-d') . '" required></div>
            <div><label>Label</label><input type="text" name="label" placeholder="e.g. FB top-up / agency fee"></div>
            <div style="grid-column:1/-1;"><label>Notes</label><textarea name="notes" rows="2" placeholder="Optional"></textarea></div>
          </div>
          <div class="cf-actions">
            <button type="submit" name="add_finance_entry" value="1" class="btn">Add to cash flow</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="cf-section-title">Entries in range</div>
  <div class="cf-panel">
    <table class="cf-table">
      <thead><tr><th>Date</th><th>Type</th><th>Label</th><th>Amount</th><th></th></tr></thead>
      <tbody>';

if (empty($entries)) {
    $content .= '<tr><td colspan="5" style="color:#94a3b8;">No manual entries in this range yet.</td></tr>';
} else {
    foreach ($entries as $e) {
        $content .= '<tr>
          <td>' . htmlspecialchars($e['entry_date']) . '</td>
          <td><span class="cf-badge ' . htmlspecialchars($e['entry_type']) . '">' . htmlspecialchars(str_replace('_', ' ', $e['entry_type'])) . '</span></td>
          <td>' . htmlspecialchars($e['label'] ?: '—') . '</td>
          <td><strong>' . number_format((float) $e['amount'], 2) . ' ' . $currency . '</strong></td>
          <td>
            <form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this entry?\')">
              <input type="hidden" name="entry_id" value="' . (int) $e['id'] . '">
              <button type="submit" name="delete_finance_entry" value="1" class="btn btn-secondary" style="padding:0.3rem 0.6rem;font-size:0.75rem;">Delete</button>
            </form>
          </td>
        </tr>';
    }
}

$content .= '</tbody></table>
  </div>

  <div class="cf-section-title">How to read this</div>
  <div class="cf-panel">
    <p class="cf-hint" style="margin:0;">
      <strong>Strategy:</strong> Facebook ROAS lies for COD — pixels count “purchase” before delivery.
      Dropilo uses <em>delivered</em> as purchases, Fiabilo retour as losses, product <em>cost</em> as COGS,
      and your editable courier/handling + charges/cashback to produce <strong>net cash flow</strong>.
      Keep product costs filled on Products, sync Meta Ads daily, and log top-ups / cashback here.
    </p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  var ctx = document.getElementById("cfChart");
  if (!ctx || typeof Chart === "undefined") return;
  new Chart(ctx, {
    type: "line",
    data: {
      labels: ' . json_encode($series['labels']) . ',
      datasets: [
        { label: "Delivered revenue", data: ' . json_encode($series['revenue']) . ', borderColor: "#059669", backgroundColor: "rgba(5,150,105,0.12)", tension: 0.3, fill: true },
        { label: "Ad spend", data: ' . json_encode($series['spend']) . ', borderColor: "#2563eb", backgroundColor: "rgba(37,99,235,0.08)", tension: 0.3, fill: true },
        { label: "Delivered #", data: ' . json_encode($series['delivered']) . ', borderColor: "#0f172a", tension: 0.3, yAxisID: "y1" },
        { label: "Retour #", data: ' . json_encode($series['returned']) . ', borderColor: "#dc2626", tension: 0.3, yAxisID: "y1" }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: "index", intersect: false },
      plugins: { legend: { position: "bottom", labels: { boxWidth: 12, font: { weight: "600" } } } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: function(v){ return v + " ' . $currency . '"; } } },
        y1: { beginAtZero: true, position: "right", grid: { drawOnChartArea: false } }
      }
    }
  });
})();
</script>
';

require dirname(__DIR__) . '/layouts/layout.php';
