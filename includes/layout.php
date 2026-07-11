<?php
/**
 * Shared layout renderer — all pages call renderHeader() / renderFooter()
 */
function renderHeader(string $title, ?array $user = null, string $activeNav = ''): void
{
    $notifCount = $user ? getUnreadNotificationCount($user['id']) : 0;
    $alertCount = $user ? getUnreadAlertCount($user['id']) : 0;
    $msgCount   = $user ? getUnreadMessageCount($user['id']) : 0;
    $roleLabel  = $user ? strtoupper($user['role']) : 'GUEST';
    $cssVer = defined('APP_VERSION') ? APP_VERSION : '2.2';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — <?= e(APP_NAME) ?></title>
<script>
(function(){
  try{
    var t=localStorage.getItem('ach-theme');
    if(t==='light'||t==='dark'){document.documentElement.setAttribute('data-theme',t);document.documentElement.style.colorScheme=t;}
    if(localStorage.getItem('ach-sidebar')==='collapsed'){document.documentElement.classList.add('sidebar-collapsed');}
  }catch(e){}
})();
</script>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="dns-prefetch" href="https://fonts.googleapis.com">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{navy:{DEFAULT:'#0f172a',light:'#1e293b',card:'#1a2332'},accent:{DEFAULT:'#f97316',dark:'#ea580c'}}}}}</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= baseUrl('assets/css/styles.css') ?>?v=<?= e($cssVer) ?>">
</head>
<body class="app-body font-sans antialiased">
<div id="sidebar-overlay" class="app-overlay fixed inset-0 z-30 hidden lg:hidden"></div>

<aside id="sidebar" class="app-sidebar fixed top-0 left-0 h-full z-40 flex flex-col">
  <div class="app-sidebar-section p-4 flex items-center justify-between gap-2">
    <a href="<?= baseUrl('index.php') ?>" class="sidebar-brand block flex-1 min-w-0">
      <img src="<?= baseUrl('assets/images/logo.png') ?>" alt="AutoCare Hub" class="h-11 w-auto max-w-full object-contain mx-auto">
    </a>
    <button type="button" id="sidebar-collapse" class="app-icon-btn hidden lg:inline-flex p-2 rounded-lg shrink-0" title="Collapse sidebar" aria-label="Collapse sidebar">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
    </button>
  </div>
  <nav class="flex-1 p-3 space-y-1 overflow-y-auto sidebar-nav">
    <?php renderSidebarNav($user, $activeNav); ?>
  </nav>
  <?php if ($user): ?>
  <div class="app-sidebar-section p-4 sidebar-user">
    <p class="text-xs app-muted truncate"><?= e($user['full_name']) ?></p>
    <p class="text-[10px] text-accent uppercase"><?= e($roleLabel) ?></p>
  </div>
  <?php endif; ?>
</aside>

<div id="main-wrap" class="main-wrap flex flex-col min-h-screen">
<header class="app-header sticky top-0 z-20 backdrop-blur px-4 lg:px-6 py-3 flex items-center justify-between">
  <div class="flex items-center gap-3">
    <button id="menu-toggle" class="app-icon-btn lg:hidden p-2 rounded-lg" aria-label="Menu">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <button type="button" id="sidebar-expand-mobile" class="app-icon-btn hidden p-2 rounded-lg" aria-label="Expand sidebar" title="Expand sidebar">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <span class="text-sm font-semibold app-title hidden sm:inline"><?= e($title) ?></span>
  </div>
  <div class="flex items-center gap-2 sm:gap-3">
    <button id="theme-toggle" type="button" class="theme-toggle-btn" aria-label="Toggle dark or light mode" title="Toggle theme">
      <svg id="theme-icon-sun" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="display:none"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
      <svg id="theme-icon-moon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
    </button>
    <?php if ($user): ?>
    <a href="<?= notificationsPageForRole($user['role']) ?>" class="app-icon-btn relative p-2 rounded-lg" title="<?= $alertCount ? 'Alerts & Notifications' : 'Notifications' ?>">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
      <?php if ($notifCount > 0): ?>
        <span class="absolute -top-0.5 -right-0.5 notif-dot <?= $alertCount > 0 ? 'is-alert' : 'is-info' ?>"><?= min(99, $notifCount) ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= baseUrl($user['role'] === 'admin' ? 'admin/messages.php' : ($user['role'] === 'mechanic' ? 'mechanic/messages.php' : 'user/messages.php')) ?>" class="app-icon-btn relative p-2 rounded-lg" title="Messages">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
      <?php if ($msgCount > 0): ?><span class="absolute -top-0.5 -right-0.5 notif-dot is-msg"><?= min(99, $msgCount) ?></span><?php endif; ?>
    </a>
    <a href="<?= baseUrl('auth/logout.php') ?>" class="text-xs app-muted hover:text-accent hidden sm:inline">Logout</a>
    <?php else: ?>
    <a href="<?= baseUrl('auth/login.php') ?>" class="btn-primary" style="width:auto;padding:.4rem 1rem;font-size:.8rem">Login</a>
    <?php endif; ?>
    <div class="app-role-badge hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full">
      <span class="w-2 h-2 rounded-full bg-emerald-400 pulse-dot"></span>
      <span class="text-xs app-muted"><?= $user ? e(strtoupper($user['role'])) : 'GUEST' ?></span>
    </div>
  </div>
</header>

<main class="flex-1 p-4 lg:p-6 page-enter">
<?php
    $flash = getFlash();
    if ($flash):
?>
<div class="mb-4 p-3 rounded-lg text-sm flash-banner <?= $flash['type'] === 'error' ? 'flash-error' : ($flash['type'] === 'success' ? 'flash-success' : 'flash-info') ?>">
  <?= e($flash['message']) ?>
</div>
<?php endif; ?>
<?php
}

function renderSidebarNav(?array $user, string $active): void
{
    $role = $user['role'] ?? 'guest';
    $items = [];

    if ($role === 'admin') {
        $items = [
            ['admin/dashboard.php',    'Dashboard',           'dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4'],
            ['admin/appointments.php', 'Appointments',        'appointments', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['admin/customers.php',    'Customers & Cars',    'customers', 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            ['admin/catalog.php',      'Service Catalog',     'catalog', 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
            ['admin/inventory.php',    'Parts & Inventory',   'inventory', 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
            ['admin/history.php',      'Service History',     'history', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['admin/users.php',        'User Management',     'users', 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
            ['admin/reports.php',      'PDF Reports',         'reports', 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['admin/notifications.php','Notifications',       'notifications', 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
            ['admin/messages.php',     'Messages',            'messages', 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
            ['admin/contact.php',      'Contact Inquiries',   'contact', 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z'],
        ];
    } elseif ($role === 'mechanic') {
        $items = [
            ['mechanic/dashboard.php',    'My Dashboard',    'dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4'],
            ['mechanic/appointments.php', 'Workshop Jobs',   'appointments', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
            ['mechanic/commission.php',   'Commission',      'commission', 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['mechanic/history.php',      'Completed Jobs',  'history', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['mechanic/notifications.php','Notifications', 'notifications', 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
            ['mechanic/messages.php',     'Messages',        'messages', 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
        ];
    } elseif ($role === 'customer') {
        $items = [
            ['user/dashboard.php',     'Dashboard',      'dashboard', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4'],
            ['user/booking.php',       'Book Service',   'booking', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['user/appointments.php',  'Appointments',   'appointments', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
            ['user/vehicles.php',      'My Vehicles',    'vehicles', 'M8 17h.01M12 17h.01M16 17h.01M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z'],
            ['user/receipt.php',       'Receipts',       'receipt', 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
            ['user/history.php',       'Service History','history', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['user/notifications.php', 'Notifications',  'notifications', 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
            ['user/messages.php',      'Messages',       'messages', 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
        ];
    } else {
        $items = [
            ['index.php',    'Home',     'home', 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4'],
            ['services.php', 'Services', 'services', 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
            ['contact.php',  'Contact',  'contact', 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z'],
            ['auth/login.php',   'Login',    'login', 'M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1'],
            ['auth/register.php','Register', 'register', 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z'],
        ];
    }

    foreach ($items as $item) {
        [$href, $label, $key] = $item;
        $path = $item[3] ?? 'M4 6h16M4 12h16M4 18h16';
        $cls = $active === $key ? 'nav-item active' : 'nav-item';
        $icon = '<svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $path . '"/></svg>';
        echo '<a href="' . baseUrl($href) . '" class="' . $cls . '" title="' . e($label) . '">' . $icon . '<span class="nav-label">' . e($label) . '</span></a>';
    }
}

function renderFooter(bool $withCharts = false): void
{
    $jsVer = defined('APP_VERSION') ? APP_VERSION : '2.2';
?>
</main>
<footer class="app-footer px-6 py-3 text-center text-xs">
  <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &copy; <?= date('Y') ?>
</footer>
</div>

<div id="modal-root" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
  <div class="absolute inset-0 bg-black/70 modal-backdrop" onclick="closeModal()"></div>
  <div id="modal-box" class="app-modal relative rounded-xl shadow-2xl max-w-lg w-full p-6"></div>
</div>
<div id="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm"></div>

<script src="<?= baseUrl('assets/js/theme.js') ?>?v=<?= e($jsVer) ?>"></script>
<script src="<?= baseUrl('assets/js/app.js') ?>?v=<?= e($jsVer) ?>"></script>
<?php if ($withCharts): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= baseUrl('assets/js/charts.js') ?>?v=<?= e($jsVer) ?>"></script>
<?php endif; ?>
</body></html>
<?php
}
