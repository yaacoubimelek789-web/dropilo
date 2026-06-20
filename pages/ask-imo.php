<?php
$uid         = (int) $_SESSION['user_id'];
$currentPage = 'ask-imo';
$pageTitle   = 'IMO Bot';

$content = '
<!-- ── IMO Search Sidebar ──────────────────────────────────────────────── -->
<div class="imo-sidebar" id="imo-sidebar">
  <div class="imo-sidebar-inner">
    <div class="imo-sidebar-avatar">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#00b37e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="10" rx="2"/>
        <path d="M9 11V7a3 3 0 0 1 6 0v4"/>
        <circle cx="9" cy="16" r="1" fill="#00b37e"/>
        <circle cx="15" cy="16" r="1" fill="#00b37e"/>
        <path d="M12 3v2"/>
      </svg>
    </div>
    <p class="imo-sidebar-title" id="imo-sidebar-title">IMO is fetching your data…</p>
    <div class="imo-sidebar-steps" id="imo-sidebar-steps">
      <div class="imo-step" id="imo-step-1"><span class="imo-step-dot"></span> Connecting to your account</div>
      <div class="imo-step" id="imo-step-2"><span class="imo-step-dot"></span> Querying orders database</div>
      <div class="imo-step" id="imo-step-3"><span class="imo-step-dot"></span> Crunching the numbers</div>
      <div class="imo-step" id="imo-step-4"><span class="imo-step-dot"></span> Building your report</div>
    </div>
    <div class="imo-sidebar-loader">
      <div class="imo-loader-bar"><div class="imo-loader-fill" id="imo-loader-fill"></div></div>
    </div>
  </div>
</div>
<div class="imo-sidebar-overlay" id="imo-sidebar-overlay"></div>

<!-- ── Main Page ─────────────────────────────────────────────────────────── -->
<div class="askimo-page-root">
  <div class="askimo-shell">
    <header class="askimo-header">
      <span class="askimo-plan-pill">⚡ Bot</span>
      <h1 class="askimo-greeting">Hey, ' . htmlspecialchars($_SESSION['user_name'] ?? 'Boss') . ' 👋</h1>
      <p class="askimo-subtitle">IMO bot has full access to your data. Use a command to pull a report instantly.</p>
    </header>

    <main class="askimo-main-card">
      <div class="askimo-main-top">
        <div class="imo-cmd-hint">Type <code>/orders</code> to start a report</div>
        <button type="button" class="askimo-history-toggle" id="askimo-history-toggle">
          <span class="askimo-history-dot"></span>
          <span>History</span>
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6 9 12 15 18 9"></polyline>
          </svg>
        </button>
        <div class="askimo-history-popover" id="askimo-history-popover">
          <button type="button" class="askimo-history-clear" id="askimo-history-clear">Clear history</button>
        </div>
      </div>

      <div class="askimo-chat-card">
        <div class="askimo-chat-scroll" id="askimo-messages">
          <div class="askimo-message askimo-message-assistant">
            <div class="askimo-avatar-wrap">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="10" rx="2"/>
                <path d="M9 11V7a3 3 0 0 1 6 0v4"/>
                <circle cx="9" cy="16" r="1" fill="currentColor"/>
                <circle cx="15" cy="16" r="1" fill="currentColor"/>
                <path d="M12 3v2"/>
              </svg>
            </div>
            <div class="askimo-bubble">
              <p class="imo-welcome-line">🤖 <strong>IMO bot online.</strong> I have full access to your Dropilou data.</p>
              <p class="imo-welcome-hint">Start with a slash command:</p>
              <div class="imo-cmd-list">
                <div class="imo-cmd-item"><code>/orders</code><span>Orders report for any date range</span></div>
                <div class="imo-cmd-item"><code>/shipping</code><span>Shipping & carrier breakdown</span></div>
                <div class="imo-cmd-item"><code>/revenue</code><span>Revenue & margin analysis</span></div>
                <div class="imo-cmd-item"><code>/customers</code><span>Search customer profiles & history</span></div>
              </div>
            </div>
          </div>
        </div>

        <form class="askimo-input-bar" id="askimo-form">
          <textarea
            id="askimo-input"
            class="askimo-input"
            rows="1"
            placeholder="Type /orders or ask anything…"
          ></textarea>
          <button type="submit" class="askimo-send-btn" id="askimo-send">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <path d="M5 12h14"/><path d="M13 5l7 7-7 7"/>
            </svg>
          </button>
        </form>
      </div>

      <div class="askimo-suggestions">
        <button type="button" class="askimo-chip" data-cmd="/orders">📦 Orders report</button>
        <button type="button" class="askimo-chip" data-cmd="/shipping">🚚 Shipping</button>
        <button type="button" class="askimo-chip" data-cmd="/revenue">💰 Revenue</button>
        <button type="button" class="askimo-chip" data-cmd="/customers">👤 Customer search</button>
        <button type="button" class="askimo-chip" data-cmd="Give me a quick summary of my business">🧠 Quick summary</button>
      </div>
    </main>
  </div>
</div>

<!-- ─────────────────────────────────────────────────────────────────────── -->
<script>
(function () {
  document.body.classList.add("askimo-page");

  const form         = document.getElementById("askimo-form");
  const input        = document.getElementById("askimo-input");
  const messages     = document.getElementById("askimo-messages");
  const histToggle   = document.getElementById("askimo-history-toggle");
  const histPopover  = document.getElementById("askimo-history-popover");
  const histClear    = document.getElementById("askimo-history-clear");
  const sidebar      = document.getElementById("imo-sidebar");
  const sidebarOver  = document.getElementById("imo-sidebar-overlay");
  const loaderFill   = document.getElementById("imo-loader-fill");
  const chips        = document.querySelectorAll(".askimo-chip");

  let currentConvId = null;
  let isLoading     = false;
  let awaitingDateRange = false; // true while we wait for user to pick dates

  // ── Textarea auto-resize ─────────────────────────────────────────────────
  function autoResize(el) {
    el.style.height = "auto";
    el.style.height = Math.min(el.scrollHeight, 120) + "px";
  }
  input.addEventListener("input", () => autoResize(input));

  // ── Scroll to bottom ─────────────────────────────────────────────────────
  function scrollBottom() {
    window.scrollTo({ top: document.body.scrollHeight, behavior: "smooth" });
    messages.scrollTop = messages.scrollHeight;
  }

  // ── Append a basic text/HTML message bubble ──────────────────────────────
  function appendMessage(role, html, isHTML = false) {
    const wrap = document.createElement("div");
    wrap.className = "askimo-message " + (role === "user" ? "askimo-message-user" : "askimo-message-assistant");

    if (role !== "user") {
      const av = document.createElement("div");
      av.className = "askimo-avatar-wrap";
      av.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/><circle cx="9" cy="16" r="1" fill="currentColor"/><circle cx="15" cy="16" r="1" fill="currentColor"/><path d="M12 3v2"/></svg>`;
      wrap.appendChild(av);
    }

    const bubble = document.createElement("div");
    bubble.className = "askimo-bubble";
    if (isHTML) bubble.innerHTML = html; else bubble.textContent = html;
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  // ── Loading dots bubble ──────────────────────────────────────────────────
  function showLoading() {
    const wrap = document.createElement("div");
    wrap.className = "askimo-message askimo-message-assistant";
    wrap.id = "askimo-loading";
    const av = document.createElement("div");
    av.className = "askimo-avatar-wrap";
    av.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/><circle cx="9" cy="16" r="1" fill="currentColor"/><circle cx="15" cy="16" r="1" fill="currentColor"/><path d="M12 3v2"/></svg>`;
    wrap.appendChild(av);
    const bubble = document.createElement("div");
    bubble.className = "askimo-bubble askimo-loading-bubble";
    bubble.innerHTML = `<span class="imo-dot"></span><span class="imo-dot"></span><span class="imo-dot"></span>`;
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    scrollBottom();
  }
  function hideLoading() {
    const el = document.getElementById("askimo-loading");
    if (el) el.remove();
  }

  // ── Sidebar search animation ─────────────────────────────────────────────
  let sidebarTimer = null;
  function showSidebar(title) {
    document.getElementById("imo-sidebar-title").textContent = title || "IMO is fetching your data…";
    ["imo-step-1","imo-step-2","imo-step-3","imo-step-4"].forEach(id => {
      document.getElementById(id).classList.remove("imo-step-done","imo-step-active");
    });
    loaderFill.style.width = "0%";
    sidebar.classList.add("open");
    sidebarOver.classList.add("open");

    // Animate steps
    const steps = ["imo-step-1","imo-step-2","imo-step-3","imo-step-4"];
    const pct   = [20, 45, 70, 90];
    let i = 0;
    function nextStep() {
      if (i > 0) document.getElementById(steps[i-1]).classList.replace("imo-step-active","imo-step-done");
      if (i < steps.length) {
        document.getElementById(steps[i]).classList.add("imo-step-active");
        loaderFill.style.width = pct[i] + "%";
        i++;
        sidebarTimer = setTimeout(nextStep, 550);
      }
    }
    nextStep();
  }
  function hideSidebar() {
    if (sidebarTimer) clearTimeout(sidebarTimer);
    loaderFill.style.width = "100%";
    setTimeout(() => {
      sidebar.classList.remove("open");
      sidebarOver.classList.remove("open");
      loaderFill.style.width = "0%";
    }, 400);
  }

  // ── Date picker bubble ───────────────────────────────────────────────────
  function buildDatePickerBubble(cmd = "orders") {
    const today     = new Date();
    const fmt       = d => d.toISOString().split("T")[0];
    const todayStr  = fmt(today);

    const labels = {
      orders:   "orders report",
      shipping: "shipping breakdown",
      revenue:  "revenue analysis",
      summary:  "business summary"
    };
    const currentLabel = labels[cmd] || "report";

    const last7 = new Date(today); last7.setDate(today.getDate() - 6);
    const mStart = new Date(today.getFullYear(), today.getMonth(), 1);
    const lmS    = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const lmE    = new Date(today.getFullYear(), today.getMonth(), 0);

    const wrap = document.createElement("div");
    wrap.className = "askimo-message askimo-message-assistant";
    const av = document.createElement("div");
    av.className = "askimo-avatar-wrap";
    av.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/><circle cx="9" cy="16" r="1" fill="currentColor"/><circle cx="15" cy="16" r="1" fill="currentColor"/><path d="M12 3v2"/></svg>`;
    wrap.appendChild(av);

    const bubble = document.createElement("div");
    bubble.className = "askimo-bubble imo-date-bubble";
    bubble.innerHTML = `
      <p class="imo-dp-title">📅 Pick a date range for your ${currentLabel}:</p>
      <div class="imo-shortcut-pills">
        <button type="button" class="imo-shortcut" data-from="${todayStr}" data-to="${todayStr}">Today</button>
        <button type="button" class="imo-shortcut" data-from="${fmt(last7)}" data-to="${todayStr}">Last 7 days</button>
        <button type="button" class="imo-shortcut" data-from="${fmt(mStart)}" data-to="${todayStr}">This month</button>
        <button type="button" class="imo-shortcut" data-from="${fmt(lmS)}" data-to="${fmt(lmE)}">Last month</button>
      </div>
      <div class="imo-date-inputs">
        <div class="imo-date-field">
          <label>From</label>
          <input type="date" class="imo-date-from" value="${fmt(last7)}" max="${todayStr}">
        </div>
        <span class="imo-date-sep">→</span>
        <div class="imo-date-field">
          <label>To</label>
          <input type="date" class="imo-date-to" value="${todayStr}" max="${todayStr}">
        </div>
      </div>
      <button type="button" class="imo-run-btn">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        Run ${currentLabel.charAt(0).toUpperCase() + currentLabel.slice(1)}
      </button>
    `;
    wrap.appendChild(bubble);
    messages.appendChild(wrap);

    // Shortcut pills — scoped to THIS bubble
    const dpFrom = bubble.querySelector(".imo-date-from");
    const dpTo   = bubble.querySelector(".imo-date-to");
    const runBtn = bubble.querySelector(".imo-run-btn");

    bubble.querySelectorAll(".imo-shortcut").forEach(btn => {
      btn.addEventListener("click", () => {
        bubble.querySelectorAll(".imo-shortcut").forEach(b => b.classList.remove("active"));
        btn.classList.add("active");
        dpFrom.value = btn.dataset.from;
        dpTo.value   = btn.dataset.to;
      });
    });

    // Run report — scoped to THIS bubble
    runBtn.addEventListener("click", () => {
      const from = dpFrom.value;
      const to   = dpTo.value;
      if (!from || !to) { alert("Please select a date range."); return; }

      // Disable only THIS picker so others remain usable
      bubble.querySelectorAll("button, input").forEach(el => el.disabled = true);
      runBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Running…`;

      runBotCommand(cmd, from, to);
    });

    scrollBottom();
  }

  // ── Render Orders Result ────────────────────────────────────────────────
  function renderOrdersResult(data) {
    const s    = data.stats;
    const curr = s.currency || "MAD";
    const fmtN = n => Number(n).toLocaleString("fr-MA", { minimumFractionDigits: 2 });

    const inTransit = Math.max(0, s.confirmed - s.delivered - s.returned);
    const tiles = [
      { icon: "📦", label: "Total Orders",    value: s.total,     color: "#3b82f6" },
      { icon: "✅", label: "Confirmed",        value: s.confirmed, color: "#10b981" },
      { icon: "🚚", label: "Delivered",        value: s.delivered, color: "#00b37e" },
      { icon: "🔄", label: "Returned",         value: s.returned,  color: "#f59e0b" },
      { icon: "📬", label: "In Transit",       value: inTransit,   color: "#6366f1" },
      { icon: "⚠️", label: "Follow-up",        value: s.followup,  color: "#ef4444" },
      { icon: "💰", label: "Revenue",          value: fmtN(s.revenue) + " " + curr, color: "#8b5cf6", big: true },
    ];

    let tilesHTML = `<div class="imo-stat-grid">` + tiles.map(t => `
      <div class="imo-stat-tile" style="--tc:${t.color}">
        <span class="imo-stat-icon">${t.icon}</span>
        <span class="imo-stat-val${t.big ? " imo-stat-big" : ""}">${t.value}</span>
        <span class="imo-stat-lbl">${t.label}</span>
      </div>`).join("") + `</div>`;

    let ratesHTML = "";
    if (s.total > 0) {
      ratesHTML = `
        <div class="imo-rates-row">
          <div class="imo-rate-bar-wrap">
            <span class="imo-rate-label">Delivery rate</span>
            <div class="imo-rate-track"><div class="imo-rate-fill" style="width:${s.delivery_rate}%;background:#10b981"></div></div>
            <span class="imo-rate-pct">${s.delivery_rate}%</span>
          </div>
          <div class="imo-rate-bar-wrap">
            <span class="imo-rate-label">Return rate</span>
            <div class="imo-rate-track"><div class="imo-rate-fill" style="width:${s.return_rate}%;background:#f59e0b"></div></div>
            <span class="imo-rate-pct">${s.return_rate}%</span>
          </div>
        </div>`;
    }

    let shopsHTML = "";
    if (data.shops && data.shops.length > 0 && data.shops.some(sh => sh.total_orders > 0)) {
      const rows = data.shops.filter(sh => sh.total_orders > 0).map(sh => `
        <tr>
          <td>${sh.shop_name}</td>
          <td>${sh.total_orders}</td>
          <td>${sh.delivered}</td>
          <td>${sh.returned}</td>
          <td>${fmtN(sh.revenue)} ${curr}</td>
        </tr>`).join("");
      shopsHTML = `
        <div class="imo-shop-table-wrap">
          <p class="imo-section-label">Per shop breakdown</p>
          <table class="imo-shop-table">
            <thead><tr><th>Shop</th><th>Orders</th><th>Delivered</th><th>Returned</th><th>Revenue</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>`;
    }

    const summaryHTML = `<div class="imo-summary-para"><p>${data.summary}</p></div>`;
    const period = data.period || "";
    const headerHTML = `<div class="imo-result-header"><span class="imo-result-badge">📊 Orders Report</span><span class="imo-result-period">${period}</span></div>`;

    renderInChat(`<div class="imo-result-card">${headerHTML}${tilesHTML}${ratesHTML}${shopsHTML}${summaryHTML}</div>`);
  }

  // ── Render Shipping Result ──────────────────────────────────────────────
  function renderShippingResult(data) {
    const s = data.stats;
    const period = data.period || "";
    
    const tiles = [
      { icon: "📦", label: "Total",      value: s.total,        color: "#3b82f6" },
      { icon: "🏎️", label: "Shipped",    value: s.shipped,      color: "#6366f1" },
      { icon: "✅", label: "Delivered",  value: s.delivered,    color: "#10b981" },
      { icon: "🔄", label: "Returned",   value: s.returned,     color: "#f59e0b" },
      { icon: "🚚", label: "In Transit", value: s.in_transit,   color: "#fbbf24" },
      { icon: "🏭", label: "At Depot",   value: s.ready_pickup, color: "#94a3b8" },
    ];

    const tilesHTML = `<div class="imo-stat-grid">` + tiles.map(t => `
      <div class="imo-stat-tile" style="--tc:${t.color}">
        <span class="imo-stat-icon">${t.icon}</span>
        <span class="imo-stat-val">${t.value}</span>
        <span class="imo-stat-lbl">${t.label}</span>
      </div>`).join("") + `</div>`;

    const delRate = s.total > 0 ? Math.round((s.delivered / s.total) * 100) : 0;
    const retRate = s.total > 0 ? Math.round((s.returned / s.total) * 100) : 0;

    const ratesHTML = `
      <div class="imo-rates-row">
        <div class="imo-rate-bar-wrap">
          <span class="imo-rate-label">Delivery rate (overall)</span>
          <div class="imo-rate-track"><div class="imo-rate-fill" style="width:${delRate}%;background:#10b981"></div></div>
          <span class="imo-rate-pct">${delRate}%</span>
        </div>
        <div class="imo-rate-bar-wrap">
          <span class="imo-rate-label">Return rate (overall)</span>
          <div class="imo-rate-track"><div class="imo-rate-fill" style="width:${retRate}%;background:#f59e0b"></div></div>
          <span class="imo-rate-pct">${retRate}%</span>
        </div>
      </div>`;

    const headerHTML = `<div class="imo-result-header"><span class="imo-result-badge" style="background:var(--blue-light);color:var(--blue)">🚚 Shipping Breakdown</span><span class="imo-result-period">${period}</span></div>`;
    renderInChat(`<div class="imo-result-card">${headerHTML}${tilesHTML}${ratesHTML}</div>`);
  }

  // ── Render Revenue Result ───────────────────────────────────────────────
  function renderRevenueResult(data) {
    const s = data.stats;
    const curr = s.currency || "MAD";
    const fmtN = n => Number(n).toLocaleString("fr-MA", { minimumFractionDigits: 2 });
    
    const tilesHTML = `
      <div class="imo-stat-grid" style="grid-template-columns: repeat(2, 1fr);">
        <div class="imo-stat-tile" style="--tc:#3b82f6; grid-column: span 2;">
          <span class="imo-stat-lbl">Total Sales Revenue</span>
          <span class="imo-stat-val imo-stat-big">${fmtN(s.total_revenue)} ${curr}</span>
          <span class="imo-stat-desc" style="font-size:0.65rem;color:#94a3b8">${s.total_orders} orders processed</span>
        </div>
        <div class="imo-stat-tile" style="--tc:#10b981">
          <span class="imo-stat-lbl">Confirmed Rev</span>
          <span class="imo-stat-val" style="font-size:1.1rem">${fmtN(s.confirmed_revenue)}</span>
        </div>
        <div class="imo-stat-tile" style="--tc:#8b5cf6">
          <span class="imo-stat-lbl">Cash Collected</span>
          <span class="imo-stat-val" style="font-size:1.1rem">${fmtN(s.delivered_revenue)}</span>
        </div>
      </div>`;

    const daily = data.daily || [];
    const chartId = "chart-" + Math.random().toString(36).substr(2, 9);
    const canvasHTML = daily.length > 0 ? `<div class="imo-chart-box"><canvas id="${chartId}"></canvas></div>` : "";

    const headerHTML = `<div class="imo-result-header"><span class="imo-result-badge" style="background:#f5f3ff;color:#7c3aed">💰 Revenue Analysis</span></div>`;
    renderInChat(`<div class="imo-result-card">${headerHTML}${tilesHTML}${canvasHTML}</div>`);

    if (daily.length > 0) {
      setTimeout(() => {
        const canvas = document.getElementById(chartId);
        if(!canvas) return;
        const ctx = canvas.getContext("2d");
        new Chart(ctx, {
          type: "line",
          data: {
            labels: daily.map(d => d.d.split("-").slice(1).join("/")),
            datasets: [{
              label: "Revenue",
              data: daily.map(d => d.rev),
              borderColor: "#8b5cf6",
              backgroundColor: "rgba(139, 92, 246, 0.1)",
              borderWidth: 2,
              fill: true,
              tension: 0.4,
              pointRadius: 0
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
              x: { display: false },
              y: { display: false }
            }
          }
        });
      }, 100);
    }
  }

  // ── Render Summary Result ───────────────────────────────────────────────
  function renderSummaryResult(data) {
    const s = data.stats;
    const curr = s.currency || "MAD";
    const fmtN = n => Number(n).toLocaleString("fr-MA", { minimumFractionDigits: 2 });
    
    const confRate = s.total_orders > 0 ? Math.round((s.confirmed / s.total_orders) * 100) : 0;
    
    let blocks = [];
    if (data.top_shop) {
      blocks.push(`<div class="imo-summary-block">
        <span class="imo-summary-icon">🏛️</span>
        <div><strong>Top Shop:</strong> ${data.top_shop.name} (${data.top_shop.total} orders)</div>
      </div>`);
    }
    if (data.top_product) {
      blocks.push(`<div class="imo-summary-block">
        <span class="imo-summary-icon">📦</span>
        <div><strong>Top Product:</strong> ${data.top_product.name} (${data.top_product.qty} units)</div>
      </div>`);
    }

    const html = `
      <div class="imo-result-card summary-card">
        <div class="imo-result-header"><span class="imo-result-badge" style="background:#fff7ed;color:#ea580c">✨ Business Summary</span></div>
        <div class="imo-summary-hero">
          <div class="imo-hero-stat">
            <span class="imo-hero-val">${s.total_orders}</span>
            <span class="imo-hero-lbl">Orders</span>
          </div>
          <div class="imo-hero-stat">
            <span class="imo-hero-val">${fmtN(s.total_revenue).split(",")[0]}</span>
            <span class="imo-hero-lbl">${curr} Rev</span>
          </div>
          <div class="imo-hero-stat">
            <span class="imo-hero-val">${confRate}%</span>
            <span class="imo-hero-lbl">Conf. Rate</span>
          </div>
        </div>
        <div class="imo-summary-blocks">${blocks.join("")}</div>
        <div class="imo-summary-para" style="margin-top:10px; font-style:italic">
          🚀 Your business is performing well! You have ${s.delivered} delivered orders in this period.
        </div>
      </div>`;
    renderInChat(html);
  }

  // ── Render Customer Profile Result ──────────────────────────────────────
  function renderCustomerProfile(data) {
    const c = data.customer;
    const s = c.stats;
    const l = c.last_order;
    const fmtN = n => Number(n).toLocaleString("fr-MA", { minimumFractionDigits: 2 });

    const itemsHTML = l.items.map(item => {
      const imgHTML = item.image ? `<div class="imo-cust-img-wrap"><img src="${item.image}" class="imo-cust-img"></div>` : `<div class="imo-cust-img-wrap" style="display:flex;align-items:center;justify-content:center;font-size:10px;color:#94a3b8;font-weight:700">NO IMG</div>`;
      return `
        <div class="imo-cust-order-box" style="margin-bottom:0.5rem">
          ${imgHTML}
          <div class="imo-cust-order-info">
            <div class="imo-cust-order-prod" style="font-weight:700;color:#0f172a">${item.name}</div>
            <div class="imo-cust-order-addr" style="margin-top:0">Quantity: ${item.qty}</div>
          </div>
        </div>
      `;
    }).join("");
    
    const html = `
      <div class="imo-result-card customer-card">
        <div class="imo-result-header">
           <span class="imo-result-badge" style="background:#f0f9ff;color:#0369a1">👤 Customer Profile</span>
           <span class="imo-cust-status-badge" style="margin-left:auto">${l.status}</span>
        </div>
        <div class="imo-cust-header">
          <div class="imo-cust-basic">
            <span class="imo-cust-name">${c.name}</span>
            <span class="imo-cust-phone">${c.phone}</span>
          </div>
          <div class="imo-cust-metrics">
            <div class="imo-cust-met">
              <span class="imo-cust-met-val">${s.count}</span>
              <span class="imo-cust-met-lbl">Orders</span>
            </div>
            <div class="imo-cust-met">
              <span class="imo-cust-met-val">${fmtN(s.ltv).split(",")[0]}</span>
              <span class="imo-cust-met-lbl">${s.curr} Total</span>
            </div>
          </div>
        </div>
        <div class="imo-cust-latest">
          <p class="imo-section-label" style="display:flex;justify-content:space-between">
            <span>Latest Order — ${l.name}</span>
            <span>${l.date}</span>
          </p>
          <div class="imo-cust-items-list" style="max-height:240px;overflow-y:auto;padding-right:4px">
            ${itemsHTML}
          </div>
          <p class="imo-cust-order-addr" style="margin-top:0.8rem;border-top:1px solid #f1f5f9;padding-top:0.5rem">📍 ${l.address}</p>
        </div>
      </div>`;
    renderInChat(html);
  }

  // ── Build Customer Search Bubble ─────────────────────────────────────────
  function buildCustomerSearchBubble() {
    const wrap = document.createElement("div");
    wrap.className = "askimo-message askimo-message-assistant";
    const av = document.createElement("div");
    av.className = "askimo-avatar-wrap";
    av.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/><circle cx="9" cy="16" r="1" fill="currentColor"/><circle cx="15" cy="16" r="1" fill="currentColor"/><path d="M12 3v2"/></svg>`;
    wrap.appendChild(av);

    const bubble = document.createElement("div");
    bubble.className = "askimo-bubble imo-search-bubble";
    bubble.innerHTML = `
      <p class="imo-search-title">🔍 Search for a customer:</p>
      <div class="imo-search-input-wrap">
        <input type="text" class="imo-search-input" placeholder="Type name or phone number…">
        <div class="imo-search-suggestions" style="display:none"></div>
      </div>
    `;
    wrap.appendChild(bubble);
    messages.appendChild(wrap);

    const sInput = bubble.querySelector(".imo-search-input");
    const sList  = bubble.querySelector(".imo-search-suggestions");
    let searchTimer = null;

    sInput.addEventListener("input", (e) => {
      clearTimeout(searchTimer);
      const query = e.target.value.trim();
      if (query.length < 2) {
        sList.style.display = "none";
        return;
      }

      searchTimer = setTimeout(async () => {
        try {
          const res = await fetch("index.php?page=bot-command", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ command: "customer-search", query: query }),
          });
          const data = await res.json();
          if (data.results && data.results.length > 0) {
            sList.innerHTML = data.results.map(r => `
              <div class="imo-search-item" data-phone="${r.billing_phone}" data-name="${r.billing_name}">
                <div class="imo-search-item-name">${r.billing_name}</div>
                <div class="imo-search-item-phone">${r.billing_phone}</div>
              </div>
            `).join("");
            sList.style.display = "block";
          } else {
            sList.innerHTML = `<div class="imo-search-empty">No customers found</div>`;
            sList.style.display = "block";
          }
        } catch (err) { console.error(err); }
      }, 300);
    });

    sList.addEventListener("click", ev => {
      const item = ev.target.closest(".imo-search-item");
      if (!item) return;
      const phone = item.dataset.phone;
      const name  = item.dataset.name;
      sInput.value = name;
      sList.style.display = "none";
      sInput.disabled = true;
      lookupCustomer(phone);
    });

    async function lookupCustomer(phone) {
      showSidebar(`IMO is looking up customer record…`);
      try {
        const res = await fetch("index.php?page=bot-command", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ command: "customer-lookup", phone: phone }),
        });
        const data = await res.json();
        await new Promise(r => setTimeout(r, 800));
        hideSidebar();
        if (data.success) {
          renderCustomerProfile(data);
        } else {
          appendMessage("assistant", "⚠️ " + (data.error || "Could not find details."));
        }
      } catch (err) {
        hideSidebar();
        appendMessage("assistant", "⚠️ Connection failed.");
      }
    }

    scrollBottom();
    sInput.focus();
  }

  // ── Generic helper to append a result ────────────────────────────────────
  function renderInChat(html) {
    const wrap = document.createElement("div");
    wrap.className = "askimo-message askimo-message-assistant";
    const av = document.createElement("div");
    av.className = "askimo-avatar-wrap";
    av.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/><circle cx="9" cy="16" r="1" fill="currentColor"/><circle cx="15" cy="16" r="1" fill="currentColor"/><path d="M12 3v2"/></svg>`;
    wrap.appendChild(av);
    const bubble = document.createElement("div");
    bubble.className = "askimo-bubble imo-result-bubble";
    bubble.innerHTML = html;
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    scrollBottom();
  }

  // ── Run /orders command ──────────────────────────────────────────────────
  // ── Run Bot Command ──────────────────────────────────────────────────────
  async function runBotCommand(cmd, from, to) {
    awaitingDateRange = false; 
    showSidebar(`IMO is fetching your ${cmd} data…`);

    try {
      const res = await fetch("index.php?page=bot-command", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ command: cmd, date_from: from, date_to: to }),
      });
      const data = await res.json();
      await new Promise(r => setTimeout(r, 1000)); 
      hideSidebar();
      await new Promise(r => setTimeout(r, 300));

      if (data.success) {
        if (cmd === "orders") renderOrdersResult(data);
        else if (cmd === "shipping") renderShippingResult(data);
        else if (cmd === "revenue") renderRevenueResult(data);
        else if (cmd === "summary") renderSummaryResult(data);
      } else {
        appendMessage("assistant", "⚠️ " + (data.error || `Could not fetch ${cmd}.`));
      }
    } catch (err) {
      hideSidebar();
      appendMessage("assistant", "⚠️ Connection failed. Please try again.");
      console.error(err);
    }
  }

  // ── Detect slash commands ────────────────────────────────────────────────
  function handleSlashCommand(text) {
    const cmd = text.trim().toLowerCase().split(/\s+/)[0];

    if (cmd === "/orders" || cmd === "/shipping" || cmd === "/revenue" || cmd === "/summary" || cmd === "/customers") {
      const cname = cmd.replace("/", "");
      
      if (cmd === "/customers") {
        appendMessage("user", text);
        appendMessage("assistant", "Alright! I can find any customer for you. Just start typing a name or phone number below 👇", false);
        buildCustomerSearchBubble();
        return true;
      }

      awaitingDateRange = true;
      appendMessage("user", text);
      
      let promptTitle = "Sure! Let me pull your " + cname + " report.";
      if (cmd === "/summary") promptTitle = "Okay! Generating your business summary.";
      
      appendMessage("assistant", promptTitle + " First, pick a date range 👇", false);
      buildDatePickerBubble(cname);
      return true;
    }

    return false; // not a slash command
  }

  // ── Submit ────────────────────────────────────────────────────────────────
  async function sendMessage(text) {
    if (isLoading || !text.trim()) return;

    // Slash command check
    if (text.trim().startsWith("/")) {
      if (handleSlashCommand(text)) {
        input.value = "";
        autoResize(input);
        return;
      }
    }

    // Built-in bot reply — no external AI needed
    appendMessage("user", text);
    input.value = "";
    autoResize(input);

    const lower = text.toLowerCase();
    let reply = "";

    // Simple keyword matching
    if (lower.includes("order")) {
      reply = "📦 To get your orders data, use the <code>/orders</code> command — I\'ll show you a date picker and pull the full report!";
    } else if (lower.includes("revenue") || lower.includes("money") || lower.includes("revenue")) {
      reply = "💰 Try the <code>/revenue</code> command to see your financial breakdown and daily trends!";
    } else if (lower.includes("ship") || lower.includes("deliver") || lower.includes("carrier")) {
      reply = "🚚 Detailed shipping data is available with <code>/shipping</code>. It shows everything from depot to delivered.";
    } else if (lower.includes("return")) {
      reply = "🔄 Return stats are included in the <code>/orders</code> and <code>/shipping</code> reports. Run them to see the full picture.";
    } else if (lower.includes("hello") || lower.includes("hi") || lower.includes("hey") || lower.includes("salut") || lower.includes("bonjour")) {
      reply = "👋 Hey! I\'m IMO bot — I run reports directly from your data. Try <code>/orders</code> or <code>/summary</code> to see what\'s happening.";
    } else if (lower.includes("help") || lower.includes("aide") || lower.includes("what can")) {
      reply = `🤖 Here\'s what I can do for you:<br><br>
        <code>/orders</code> — Totals, revenue & confirmation rates<br>
        <code>/shipping</code> — Shipping status breakdown & in-transit counts<br>
        <code>/revenue</code> — Financial analysis & daily revenue trends<br>
        <code>/summary</code> — Executive overview with top shop & product<br>
        <code>/customers</code> — Search customer profiles & history`;
    } else if (lower.includes("customer") || lower.includes("client") || lower.includes("buyer")) {
      reply = "🕵️‍♂️ You can search for a specific customer using <code>/customers</code> — I\'ll find their history and lifetime value!";
    } else if (lower.includes("summary") || lower.includes("overview")) {
      reply = "📊 Use the <code>/summary</code> command to get an executive overview of your business for any period.";
    } else {
      reply = "⚡ I\'m a command-based bot. Try <code>/summary</code> to get an instant report on your latest performance!";
    }

    // Short delay for a natural feel
    setTimeout(() => {
      appendMessage("assistant", reply, true);
    }, 320);
  }


  // ── Conversations (history popover) ──────────────────────────────────────
  async function loadConversations() {
    try {
      const res  = await fetch("index.php?page=chat-ajax&action=conversations");
      const data = await res.json();
      if (data.success && data.conversations) {
        histPopover.querySelectorAll(".askimo-history-row").forEach(r => r.remove());
        data.conversations.forEach(conv => {
          const row = document.createElement("button");
          row.type = "button";
          row.className = "askimo-history-row";
          if (conv.id === currentConvId) row.classList.add("askimo-history-row-active");
          row.dataset.conversationId = conv.id;
          row.innerHTML = `<span class="askimo-history-title">${conv.title || "New conversation"}</span>
                           <span class="askimo-history-desc">${conv.message_count} msgs · ${new Date(conv.updated_at).toLocaleDateString()}</span>`;
          row.addEventListener("click", () => loadConversation(conv.id));
          histPopover.insertBefore(row, histClear);
        });
      }
    } catch (e) { /* silent */ }
  }

  async function loadConversation(id) {
    currentConvId = id;
    messages.innerHTML = "";
    try {
      const res  = await fetch("index.php?page=chat-ajax&action=messages&conversation_id=" + id);
      const data = await res.json();
      if (data.success && data.messages) {
        data.messages.forEach(m => appendMessage(m.role, m.content));
        loadConversations();
      }
    } catch (e) {
      appendMessage("assistant", "⚠️ Failed to load history.");
    }
  }

  // ── Event bindings ────────────────────────────────────────────────────────
  form.addEventListener("submit", e => {
    e.preventDefault();
    const val = input.value.trim();
    if (!val || isLoading) return;
    sendMessage(val);
  });

  input.addEventListener("keydown", e => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      form.dispatchEvent(new Event("submit"));
    }
  });

  histToggle.addEventListener("click", () => {
    histPopover.classList.toggle("open");
    if (histPopover.classList.contains("open")) loadConversations();
  });
  document.addEventListener("click", e => {
    if (!histPopover.contains(e.target) && !histToggle.contains(e.target))
      histPopover.classList.remove("open");
  });

  histClear.addEventListener("click", () => {
    if (confirm("Clear all chat history? This can\'t be undone.")) {
      currentConvId = null;
      messages.innerHTML = "";
      // re-add welcome bubble
      messages.innerHTML = `<div class="askimo-message askimo-message-assistant">
        <div class="askimo-avatar-wrap"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/><circle cx="9" cy="16" r="1" fill="currentColor"/><circle cx="15" cy="16" r="1" fill="currentColor"/><path d="M12 3v2"/></svg></div>
        <div class="askimo-bubble"><p>🤖 Chat cleared. Ready for new commands!</p></div></div>`;
      loadConversations();
    }
  });

  chips.forEach(chip => {
    chip.addEventListener("click", () => {
      if (chip.classList.contains("imo-chip-soon")) {
        appendMessage("assistant", "⚙️ This command is coming soon! Try /orders for now.");
        return;
      }
      const cmd = chip.dataset.cmd || chip.textContent.trim();
      input.value = cmd;
      autoResize(input);
      input.focus();
      // Auto-submit if it\'s a slash command
      if (cmd.startsWith("/")) {
        sendMessage(cmd);
        input.value = "";
        autoResize(input);
      }
    });
  });

  loadConversations();
})();
</script>

<style>
/* ── Page base ────────────────────────────────────────────────────────────── */
body.askimo-page { background: #f0f2f5; }

.askimo-page-root {
  min-height: calc(100vh - 40px);
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 2rem 1rem 3rem;
}
.askimo-shell { max-width: 740px; width: 100%; }

/* ── Header ─────────────────────────────────────────────────────────────── */
.askimo-header { text-align: center; margin-bottom: 1.6rem; }
.askimo-plan-pill {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .2rem .75rem; border-radius: 999px;
  font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  background: #ecfdf3; color: #059669; border: 1px solid #a7f3d0;
  margin-bottom: .5rem;
}
.askimo-greeting { margin: 0; font-size: 2rem; font-weight: 800; letter-spacing: -.04em; color: #0f172a; }
.askimo-subtitle { margin: .35rem 0 0; font-size: .9rem; color: #64748b; }

/* ── Main card ───────────────────────────────────────────────────────────── */
.askimo-main-card {
  background: #fff; border-radius: 18px; border: 1px solid #e2e8f0;
  box-shadow: 0 20px 50px rgba(15,23,42,.07);
  padding: 1rem 1.1rem .85rem;
  display: flex; flex-direction: column; gap: .7rem; position: relative;
}
.askimo-main-top {
  display: flex; align-items: center; justify-content: space-between;
}
.imo-cmd-hint {
  font-size: .73rem; color: #94a3b8;
}
.imo-cmd-hint code {
  background: #f1f5f9; border-radius: 4px; padding: .1rem .35rem;
  font-size: .72rem; color: #475569; border: 1px solid #e2e8f0;
}

/* ── History popover ─────────────────────────────────────────────────────── */
.askimo-history-toggle {
  border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 999px;
  padding: .22rem .65rem; font-size: .72rem; color: #64748b;
  display: inline-flex; align-items: center; gap: .3rem; cursor: pointer;
}
.askimo-history-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; }
.askimo-history-popover {
  position: absolute; top: 2.8rem; right: 1.1rem;
  width: 260px; background: #fff; border-radius: 14px;
  border: 1px solid #e2e8f0; box-shadow: 0 16px 40px rgba(15,23,42,.14);
  padding: .3rem; display: none; z-index: 20;
}
.askimo-history-popover.open { display: block; }
.askimo-history-row {
  width: 100%; border: none; background: transparent; border-radius: 8px;
  padding: .45rem .5rem; text-align: left; display: flex; flex-direction: column;
  gap: .06rem; cursor: pointer;
}
.askimo-history-row:hover { background: #f8fafc; }
.askimo-history-row-active { background: #ecfdf3; border: 1px solid #bbf7d0; }
.askimo-history-title { font-size: .78rem; font-weight: 600; color: #0f172a; }
.askimo-history-desc { font-size: .7rem; color: #94a3b8; }
.askimo-history-clear {
  width: 100%; border: none; background: #fef2f2; border-radius: 8px;
  padding: .35rem .5rem; font-size: .72rem; color: #ef4444; cursor: pointer; margin-top: .2rem;
}

/* ── Chat area ───────────────────────────────────────────────────────────── */
.askimo-chat-card {
  border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc;
  display: flex; flex-direction: column; overflow: hidden;
}
.askimo-chat-scroll {
  flex: 1; padding: .9rem .85rem .4rem;
  display: flex; flex-direction: column; gap: .7rem;
  max-height: 60vh; overflow-y: auto;
}
.askimo-chat-scroll::-webkit-scrollbar { width: 4px; }
.askimo-chat-scroll::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }

.askimo-message { display: flex; align-items: flex-start; gap: .45rem; max-width: 92%; }
.askimo-message-user { align-self: flex-end; flex-direction: row-reverse; }
.askimo-avatar-wrap {
  width: 26px; height: 26px; border-radius: 50%; background: #ecfdf3;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; border: 1px solid #bbf7d0; color: #059669;
}
.askimo-bubble {
  border-radius: 14px; padding: .6rem .8rem;
  font-size: .84rem; line-height: 1.5;
  background: #fff; border: 1px solid #e2e8f0; color: #0f172a;
}
.askimo-message-user .askimo-bubble {
  background: #0f172a; color: #e2e8f0; border-color: #0f172a;
}
.askimo-message-user .askimo-bubble p { color: #e2e8f0; }

/* ── Welcome bubble content ──────────────────────────────────────────────── */
.imo-welcome-line { margin: 0 0 .4rem; }
.imo-welcome-hint { margin: 0 0 .5rem; font-size: .78rem; color: #94a3b8; }
.imo-cmd-list { display: flex; flex-direction: column; gap: .35rem; }
.imo-cmd-item {
  display: flex; align-items: center; gap: .5rem;
  font-size: .8rem; color: #475569;
}
.imo-cmd-item code {
  background: #0f172a; color: #00ff41; padding: .15rem .45rem;
  border-radius: 6px; font-size: .75rem; font-weight: 600; white-space: nowrap;
}
.imo-cmd-soon code { opacity: .6; }
.imo-cmd-soon em { font-size: .65rem; background: #fef3c7; color: #d97706; border-radius: 4px; padding: .1rem .3rem; font-style: normal; }
.imo-cmd-item span { color: #64748b; }

/* ── Loading dots ────────────────────────────────────────────────────────── */
.askimo-loading-bubble { display: flex; align-items: center; gap: .35rem; padding: .65rem .9rem; }
.imo-dot {
  width: 7px; height: 7px; border-radius: 50%; background: #00b37e;
  display: inline-block; animation: imo-bounce .9s infinite ease-in-out;
}
.imo-dot:nth-child(2) { animation-delay: .18s; }
.imo-dot:nth-child(3) { animation-delay: .36s; }
@keyframes imo-bounce {
  0%,80%,100% { transform: scale(.7); opacity: .5; }
  40% { transform: scale(1); opacity: 1; }
}

/* ── Input bar ───────────────────────────────────────────────────────────── */
.askimo-input-bar {
  display: flex; align-items: flex-end; gap: .5rem;
  padding: .45rem .5rem .5rem; border-top: 1px solid #e2e8f0; background: #fff;
}
.askimo-input {
  flex: 1; border-radius: 10px; border: 1px solid #e2e8f0;
  padding: .5rem .8rem; font-size: .84rem; resize: none; overflow: hidden;
  background: #f8fafc; color: #0f172a; line-height: 1.45;
  font-family: inherit;
}
.askimo-input:focus { outline: none; border-color: #00b37e; background: #fff; box-shadow: 0 0 0 2px rgba(0,179,126,.12); }
.askimo-send-btn {
  border: none; border-radius: 10px; width: 36px; height: 36px;
  background: #00b37e; color: #fff;
  display: flex; align-items: center; justify-content: center; cursor: pointer;
  flex-shrink: 0; transition: background .15s;
}
.askimo-send-btn:hover { background: #009766; }

/* ── Chips ───────────────────────────────────────────────────────────────── */
.askimo-suggestions { display: flex; flex-wrap: wrap; gap: .35rem; }
.askimo-chip {
  border-radius: 999px; border: 1px solid #e2e8f0; background: #f8fafc;
  padding: .28rem .75rem; font-size: .75rem; color: #475569;
  cursor: pointer; transition: all .15s; font-family: inherit;
}
.askimo-chip:hover { border-color: #00b37e; color: #059669; background: #ecfdf3; }
.imo-chip-soon { opacity: .55; }
.imo-chip-soon:hover { border-color: #e2e8f0; color: #475569; background: #f8fafc; }

/* ── Date picker bubble ──────────────────────────────────────────────────── */
.imo-date-bubble { padding: .75rem .9rem !important; min-width: 280px; max-width: 420px; }
.imo-dp-title { margin: 0 0 .65rem; font-size: .85rem; font-weight: 600; color: #0f172a; }
.imo-shortcut-pills { display: flex; flex-wrap: wrap; gap: .3rem; margin-bottom: .65rem; }
.imo-shortcut {
  border-radius: 999px; border: 1px solid #e2e8f0; background: #f8fafc;
  padding: .22rem .65rem; font-size: .72rem; color: #475569; cursor: pointer; font-family: inherit;
  transition: all .15s;
}
.imo-shortcut:hover, .imo-shortcut.active { background: #0f172a; color: #00ff41; border-color: #0f172a; }
.imo-date-inputs { display: flex; align-items: center; gap: .5rem; margin-bottom: .7rem; }
.imo-date-field { display: flex; flex-direction: column; gap: .2rem; flex: 1; }
.imo-date-field label { font-size: .68rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; }
.imo-date-field input[type=date] {
  border: 1px solid #e2e8f0; border-radius: 8px; padding: .4rem .55rem;
  font-size: .8rem; color: #0f172a; background: #f8fafc; font-family: inherit; width: 100%;
}
.imo-date-field input[type=date]:focus { outline: none; border-color: #00b37e; box-shadow: 0 0 0 2px rgba(0,179,126,.12); }
.imo-date-sep { color: #94a3b8; font-size: .9rem; padding-top: 1rem; }
.imo-run-btn {
  display: inline-flex; align-items: center; gap: .4rem;
  background: #00b37e; color: #fff; border: none; border-radius: 8px;
  padding: .5rem 1rem; font-size: .8rem; font-weight: 600; cursor: pointer;
  font-family: inherit; transition: background .15s; width: 100%; justify-content: center;
}
.imo-run-btn:hover { background: #009766; }
.imo-run-btn:disabled { opacity: .6; cursor: not-allowed; }

/* ── Result card wrapper ─────────────────────────────────────────────────── */
.imo-result-bubble { padding: .6rem !important; background: #f8fafc !important; max-width: 640px; }
.imo-result-card { display: flex; flex-direction: column; gap: .8rem; }

.imo-result-header {
  display: flex; align-items: center; gap: .6rem; padding: .4rem .2rem;
}
.imo-result-badge {
  background: #0f172a; color: #00ff41; border-radius: 6px;
  font-size: .7rem; font-weight: 700; padding: .2rem .55rem; letter-spacing: .05em;
}
.imo-result-period { font-size: .78rem; color: #64748b; }

/* ── Stat grid ───────────────────────────────────────────────────────────── */
.imo-stat-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: .45rem;
}
.imo-stat-tile {
  background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;
  padding: .7rem .6rem; display: flex; flex-direction: column; align-items: center;
  gap: .15rem; text-align: center; transition: transform .1s;
  border-top: 3px solid var(--tc, #e2e8f0);
}
.imo-stat-tile:hover { transform: translateY(-2px); }
.imo-stat-icon { font-size: 1.1rem; }
.imo-stat-val { font-size: 1.35rem; font-weight: 800; color: #0f172a; line-height: 1; }
.imo-stat-big { font-size: 1rem; }
.imo-stat-lbl { font-size: .67rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }

/* ── Rate bars ───────────────────────────────────────────────────────────── */
.imo-rates-row { display: flex; flex-direction: column; gap: .45rem; padding: .2rem 0; }
.imo-rate-bar-wrap { display: flex; align-items: center; gap: .5rem; }
.imo-rate-label { font-size: .72rem; color: #64748b; width: 90px; flex-shrink: 0; }
.imo-rate-track {
  flex: 1; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden;
}
.imo-rate-fill { height: 100%; border-radius: 999px; transition: width .8s ease; }
.imo-rate-pct { font-size: .72rem; font-weight: 700; color: #0f172a; width: 36px; text-align: right; }

/* ── Shop table ──────────────────────────────────────────────────────────── */
.imo-section-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: #94a3b8; font-weight: 700; margin: 0 0 .35rem; }
.imo-shop-table-wrap { overflow-x: auto; }
.imo-shop-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
.imo-shop-table th {
  text-align: left; padding: .3rem .5rem; background: #f1f5f9;
  color: #64748b; font-size: .68rem; text-transform: uppercase; letter-spacing: .06em;
  border-bottom: 1px solid #e2e8f0;
}
.imo-shop-table td { padding: .4rem .5rem; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
.imo-shop-table tbody tr:last-child td { border-bottom: none; }
.imo-shop-table tbody tr:hover td { background: #f8fafc; }

/* ── Summary paragraph ───────────────────────────────────────────────────── */
.imo-summary-para {
  background: #fff; border-left: 3px solid #00b37e;
  border-radius: 0 8px 8px 0; padding: .6rem .75rem;
  font-size: .82rem; line-height: 1.6; color: #374151;
}
.imo-summary-para strong { color: #059669; }

/* ── New Summary/Revenue UI ──────────────────────────────────────────────── */
.imo-summary-hero {
  display: flex; justify-content: space-around; background: #fff;
  border-radius: 12px; padding: 1rem .5rem; border: 1px solid #e2e8f0;
}
.imo-hero-stat { display: flex; flex-direction: column; align-items: center; }
.imo-hero-val { font-size: 1.25rem; font-weight: 800; color: #0f172a; }
.imo-hero-lbl { font-size: .65rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; }

.imo-summary-blocks { display: flex; flex-direction: column; gap: .5rem; margin-top: .8rem; }
.imo-summary-block {
  display: flex; align-items: center; gap: .7rem; background: #fff;
  border-radius: 10px; padding: .6rem .8rem; border: 1px solid #e2e8f0; font-size: .8rem;
}
.imo-summary-icon { font-size: 1.2rem; }

.imo-chart-box { height: 120px; width: 100%; margin-top: .5rem; position: relative; }

/* ── Search sidebar ──────────────────────────────────────────────────────── */
.imo-sidebar {
  position: fixed; top: 0; right: -340px; width: 320px; height: 100vh;
  background: #fff; border-left: 1px solid #e2e8f0;
  box-shadow: -10px 0 40px rgba(15,23,42,.12);
  z-index: 1000; transition: right .35s cubic-bezier(.4,0,.2,1);
  display: flex; align-items: center; justify-content: center;
}
.imo-sidebar.open { right: 0; }
.imo-sidebar-overlay {
  position: fixed; inset: 0; background: rgba(15,23,42,.3);
  z-index: 999; opacity: 0; pointer-events: none;
  transition: opacity .3s;
}
.imo-sidebar-overlay.open { opacity: 1; pointer-events: auto; }

.imo-sidebar-inner { display: flex; flex-direction: column; align-items: center; gap: 1.2rem; padding: 2rem 1.5rem; width: 100%; }
.imo-sidebar-avatar {
  width: 60px; height: 60px; border-radius: 50%;
  background: #ecfdf3; border: 2px solid #a7f3d0;
  display: flex; align-items: center; justify-content: center;
  animation: imo-pulse 1.8s infinite;
}
@keyframes imo-pulse {
  0%,100% { box-shadow: 0 0 0 0 rgba(0,179,126,.3); }
  50% { box-shadow: 0 0 0 12px rgba(0,179,126,.0); }
}
.imo-sidebar-title { font-size: .9rem; font-weight: 700; color: #0f172a; text-align: center; }
.imo-sidebar-steps { width: 100%; display: flex; flex-direction: column; gap: .5rem; }
.imo-step {
  display: flex; align-items: center; gap: .55rem;
  font-size: .78rem; color: #94a3b8; padding: .3rem .4rem;
  border-radius: 8px; transition: all .25s;
}
.imo-step-dot {
  width: 8px; height: 8px; border-radius: 50%; background: #e2e8f0;
  flex-shrink: 0; transition: background .25s;
}
.imo-step.imo-step-active { color: #059669; font-weight: 600; }
.imo-step.imo-step-active .imo-step-dot { background: #00b37e; box-shadow: 0 0 0 3px rgba(0,179,126,.2); animation: imo-bounce .7s infinite; }
.imo-step.imo-step-done { color: #64748b; }
.imo-step.imo-step-done .imo-step-dot { background: #bbf7d0; }

.imo-sidebar-loader { width: 100%; }
.imo-loader-bar { width: 100%; height: 4px; background: #f1f5f9; border-radius: 999px; overflow: hidden; }
.imo-loader-fill { height: 100%; background: linear-gradient(90deg, #00b37e, #10b981); border-radius: 999px; width: 0%; transition: width .5s ease; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 640px) {
  .askimo-shell { max-width: 100%; }
  .askimo-main-card { padding: .85rem .8rem .7rem; }
  .imo-stat-grid { grid-template-columns: repeat(2, 1fr); }
  .imo-sidebar { width: 100%; right: -100%; }
  .askimo-greeting { font-size: 1.5rem; }
  .imo-date-inputs { flex-direction: column; }
  .imo-date-sep { display: none; }
}

/* ── Search bubble ──────────────────────────────────────────────────────── */
.imo-search-bubble { padding: .75rem .9rem !important; min-width: 280px; max-width: 420px; }
.imo-search-title { margin: 0 0 .65rem; font-size: .85rem; font-weight: 600; color: #0f172a; }
.imo-search-input-wrap { position: relative; }
.imo-search-input {
  width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: .6rem .8rem;
  font-size: .84rem; background: #fff; color: #0f172a; transition: all .2s; outline: none;
}
.imo-search-input:focus { border-color: #00b37e; box-shadow: 0 0 0 3px rgba(0,179,126,.1); }

.imo-search-suggestions {
  position: absolute; top: 100%; left: 0; right: 0; background: #fff;
  border: 1px solid #e2e8f0; border-radius: 10px; margin-top: .4rem;
  box-shadow: 0 10px 25px rgba(15,23,42,.15); z-index: 50; max-height: 200px; overflow-y: auto;
}
.imo-search-item {
  padding: .6rem .8rem; cursor: pointer; border-bottom: 1px solid #f1f5f9;
  display: flex; flex-direction: column; gap: .05rem;
}
.imo-search-item:last-child { border-bottom: none; }
.imo-search-item:hover { background: #f8fafc; }
.imo-search-item-name { font-size: .82rem; font-weight: 700; color: #0f172a; }
.imo-search-item-phone { font-size: .7rem; color: #64748b; }
.imo-search-empty { padding: .8rem; text-align: center; font-size: .78rem; color: #94a3b8; font-style: italic; }

/* ── Customer Profile Card ───────────────────────────────────────────────── */
.customer-card { padding: .6rem 0 .2rem !important; }
.imo-cust-header {
  padding: .2rem .8rem .8rem; border-bottom: 1px solid #f1f5f9;
  display: flex; align-items: flex-end; justify-content: space-between; gap: .6rem;
}
.imo-cust-basic { display: flex; flex-direction: column; gap: .1rem; }
.imo-cust-name { font-size: 1.25rem; font-weight: 900; color: #0f172a; letter-spacing: -.02em; }
.imo-cust-phone { font-size: .8rem; color: #64748b; font-weight: 600; }

.imo-cust-metrics { display: flex; gap: 1rem; }
.imo-cust-met { display: flex; flex-direction: column; align-items: flex-end; }
.imo-cust-met-val { font-size: 1rem; font-weight: 800; color: #0f172a; }
.imo-cust-met-lbl { font-size: .6rem; color: #94a3b8; text-transform: uppercase; font-weight: 700; }

.imo-cust-latest { padding: .8rem .8rem .4rem; }
.imo-cust-order-box {
  background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
  padding: .5rem; display: flex; gap: .7rem; align-items: center;
}
.imo-cust-img-wrap {
  width: 42px; height: 42px; border-radius: 8px; overflow: hidden;
  background: #fff; flex-shrink: 0; border: 1px solid #e2e8f0;
}
.imo-cust-img { width: 100%; height: 100%; object-fit: cover; }
.imo-cust-order-info { flex: 1; display: flex; flex-direction: column; gap: .05rem; }
.imo-cust-order-name { font-size: .82rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; justify-content: space-between; }
.imo-cust-status-badge {
  font-size: .65rem; padding: .2rem .5rem; border-radius: 6px;
  background: #ecfdf3; color: #059669; border: 1px solid #a7f3d0; text-transform: uppercase; font-weight: 800;
}
.imo-cust-order-prod { font-size: .78rem; color: #475569; font-weight: 500; line-height: 1.2; }
.imo-cust-order-addr { font-size: .75rem; color: #64748b; margin-top: .1rem; font-weight: 500; }
</style>
';

require $base . '/layouts/layout.php';
?>
