<?php
$currentPage = $currentPage ?? 'dashboard';
$pageTitle = $pageTitle ?? 'Dashboard';

// Assets path that works in 3 setups:
// - localhost in a subfolder: /system/public/...
// - domain where docroot is /public: /assets/...
// - domain where project root is docroot: /public/assets/...
$assetPrefix = $appPublicPrefix ?? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($assetPrefix === '' || $assetPrefix === '.') {
    $assetPrefix = '';
}
$cssFile = ($base ?? dirname(__DIR__)) . '/public/assets/style.css';
$cssVer = is_file($cssFile) ? (string) filemtime($cssFile) : (string) time();

// Fetch user data for avatar/profile
$user_id = $_SESSION['user_id'] ?? 0;
$user_data = ['name' => $_SESSION['user_name'] ?? 'User', 'avatar_url' => null];
if ($user_id > 0) {
    $st = $app->pdo->prepare("SELECT name, email, avatar_url FROM users WHERE id = ?");
    $st->execute([$user_id]);
    $db_user = $st->fetch();
    if ($db_user) {
        $user_data = $db_user;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> – DROPILOU</title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix) ?>/assets/style.css?v=<?= htmlspecialchars($cssVer) ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix) ?>/assets/protection-banner.css?v=<?= htmlspecialchars($cssVer) ?>">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&family=Inter:wght@400;500;600;700;800&family=Outfit:wght@700;850&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="mobile-header">
  <div class="header-title-container">
    <h1 class="mobile-page-title"><?= htmlspecialchars($pageTitle) ?></h1>
    <?= $pageHeaderBadge ?? '' ?>
  </div>
  <button class="mobile-menu-btn" onclick="toggleMobileMenu()" aria-label="Toggle menu">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="6" x2="21" y2="6"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
</div>
<div class="mobile-overlay" onclick="closeMobileMenu()"></div>
<div class="app">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo">
        <div class="sidebar-logo-icon">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#00ff41" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
          </svg>
        </div>
        <span class="logo-text">Drop<span class="green">ilou</span></span>
      </div>
      <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle sidebar">
        <span></span><span></span><span></span>
      </button>
      <button class="mobile-close-btn" onclick="closeMobileMenu()" aria-label="Close menu" style="display: none;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>
    <nav class="sidebar-nav">

      <!-- Overview -->
      <div class="sidebar-section-label">Overview</div>
      <a href="index.php?page=dashboard" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>" title="Analytics">
        <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
        <span class="nav-text">Analytics</span>
      </a>
      <a href="index.php?page=leads-centre" class="nav-item <?= $currentPage === 'leads-centre' ? 'active' : '' ?>" title="Leads Centre">
        <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
        <span class="nav-text">Leads Centre</span>
      </a>

      <!-- Orders -->
      <div class="sidebar-section-label">Orders</div>
      <div class="nav-section">
        <button class="nav-section-toggle <?= in_array($currentPage, ['order-upload', 'orders', 'orders-confirmed', 'orders-followup', 'delivered-orders', 'returned-orders']) ? 'expanded' : '' ?>" onclick="toggleSection(this)">
          <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
          <span class="nav-text">Orders</span>
          <span class="nav-arrow">▼</span>
        </button>
        <div class="nav-submenu <?= in_array($currentPage, ['order-upload', 'orders', 'orders-confirmed', 'orders-followup', 'delivered-orders', 'returned-orders']) ? 'expanded' : '' ?>">
          <a href="index.php?page=order-upload" class="nav-item sub <?= $currentPage === 'order-upload' ? 'active' : '' ?>"><span class="nav-text">Order upload</span></a>
          <a href="index.php?page=orders" class="nav-item sub <?= $currentPage === 'orders' ? 'active' : '' ?>"><span class="nav-text">All orders</span></a>
          <a href="index.php?page=orders-confirmed" class="nav-item sub <?= $currentPage === 'orders-confirmed' ? 'active' : '' ?>"><span class="nav-text">Confirmed</span></a>
          <a href="index.php?page=orders-followup" class="nav-item sub <?= $currentPage === 'orders-followup' ? 'active' : '' ?>"><span class="nav-text">Follow Up</span></a>
          <a href="index.php?page=delivered-orders" class="nav-item sub <?= $currentPage === 'delivered-orders' ? 'active' : '' ?>"><span class="nav-text">Delivered</span></a>
          <a href="index.php?page=returned-orders" class="nav-item sub <?= $currentPage === 'returned-orders' ? 'active' : '' ?>"><span class="nav-text">Returned</span></a>
        </div>
      </div>

      <!-- Logistics -->
      <div class="sidebar-section-label">Logistics</div>
      <a href="index.php?page=shipping-status" class="nav-item <?= $currentPage === 'shipping-status' ? 'active' : '' ?>" title="Shipping">
        <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span>
        <span class="nav-text">Shipping</span>
      </a>

      <!-- Stores & Products section -->
      <div class="sidebar-section-label">Stores</div>
      <a href="index.php?page=shops" class="nav-item <?= $currentPage === 'shops' ? 'active' : '' ?>" title="My shops">
        <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
        <span class="nav-text">My Shops</span>
      </a>

      <div class="nav-section">
        <button class="nav-section-toggle <?= in_array($currentPage, ['product-import', 'products']) ? 'expanded' : '' ?>" onclick="toggleSection(this)" title="Products">
          <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
          <span class="nav-text">Products</span>
          <span class="nav-arrow">▼</span>
        </button>
        <div class="nav-submenu <?= in_array($currentPage, ['product-import', 'products']) ? 'expanded' : '' ?>">
          <a href="index.php?page=product-import" class="nav-item sub <?= $currentPage === 'product-import' ? 'active' : '' ?>"><span class="nav-text">Import product</span></a>
          <a href="index.php?page=products" class="nav-item sub <?= $currentPage === 'products' ? 'active' : '' ?>"><span class="nav-text">All products</span></a>
        </div>
      </div>

      <!-- Other -->
      <div class="sidebar-section-label">Account</div>
      <a href="index.php?page=integration" class="nav-item <?= $currentPage === 'integration' ? 'active' : '' ?>" title="Integration">
        <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></span>
        <span class="nav-text">Integration</span>
      </a>

      <a href="index.php?page=cart" class="nav-item <?= $currentPage === 'cart' ? 'active' : '' ?>" title="Cart">
        <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
        <span class="nav-text">Cart</span>
      </a>
      <a href="index.php?page=settings" class="nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>" title="Settings">
        <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
        <span class="nav-text">Settings</span>
      </a>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-user-row">
        <?php if (!empty($user_data['avatar_url'])): ?>
          <img src="<?= htmlspecialchars($user_data['avatar_url']) ?>" alt="Avatar" class="sidebar-avatar-img">
        <?php else: ?>
          <div class="sidebar-avatar-init"><?= strtoupper(substr($user_data['name'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="sidebar-user-info">
          <span class="sidebar-user-name"><?= htmlspecialchars($user_data['name']) ?></span>
          <span class="sidebar-user-status">● Online</span>
        </div>
      </div>
      <div class="logout-container">
        <a href="index.php?page=logout" class="nav-item logout" title="Logout">
          <span class="nav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
          <span class="nav-text">Logout</span>
        </a>
      </div>
    </div>

  </aside>
  <main class="main">
    <?= $content ?? '' ?>
  </main>
</div>
<script>
function toggleSidebar() {
  if (window.innerWidth <= 768) {
    toggleMobileMenu();
  } else {
    document.querySelector('.sidebar').classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', document.querySelector('.sidebar').classList.contains('collapsed'));
  }
}

function toggleMobileMenu() {
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.mobile-overlay');
  const isOpen = sidebar.classList.contains('mobile-open');
  
  if (isOpen) {
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('active');
    document.body.classList.remove('menu-open');
  } else {
    sidebar.classList.add('mobile-open');
    overlay.classList.add('active');
    document.body.classList.add('menu-open');
  }
}

function closeMobileMenu() {
  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.mobile-overlay');
  sidebar.classList.remove('mobile-open');
  overlay.classList.remove('active');
  document.body.classList.remove('menu-open');
}

function toggleSection(button) {
  const section = button.closest('.nav-section');
  const submenu = section.querySelector('.nav-submenu');
  const isExpanded = submenu.classList.contains('expanded');
  
  submenu.classList.toggle('expanded');
  button.classList.toggle('expanded');
  
  // Close other sections if needed (optional - remove if you want multiple open)
  // document.querySelectorAll('.nav-section').forEach(s => {
  //   if (s !== section) {
  //     s.querySelector('.nav-submenu').classList.remove('expanded');
  //     s.querySelector('.nav-section-toggle').classList.remove('expanded');
  //   }
  // });
}

// Restore sidebar state
document.addEventListener('DOMContentLoaded', function() {
  if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === 'true') {
    document.querySelector('.sidebar').classList.add('collapsed');
  }
  
  // Close mobile menu when clicking nav items
  document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', function() {
      if (window.innerWidth <= 768) {
        closeMobileMenu();
      }
    });
  });
  
  // Handle window resize
  window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
      // Close mobile menu if window is resized to desktop
      closeMobileMenu();
    }
  });
});
</script>
</body>
</html>
