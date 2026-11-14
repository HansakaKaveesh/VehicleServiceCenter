<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function db() {
    static $pdo = null;
    if ($pdo === null) {
        require_once __DIR__ . '/../config/database.php';
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    return $pdo;
}

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function require_login() {
    if (empty($_SESSION['user_id'])) {
        flash('Please log in to continue.', 'error');
        redirect('login.php');
    }
}

function require_role(...$roles) {
    require_login();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function require_admin() { require_role('admin'); }
function require_stock_manager() { require_role('admin', 'stock_manager'); }

function flash($msg = null, $type = 'success') {
    if ($msg === null) {
        if (!empty($_SESSION['flash'])) {
            $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f;
        }
        return null;
    }
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function redirect($url) { header("Location: $url"); exit; }
function money($n) { return CURRENCY . ' ' . number_format((float)$n, 2); }

function current_page() {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ($_SERVER['PHP_SELF'] ?? '');
    return basename($path);
}

function is_active($href) { return current_page() === basename($href); }

function initials($name) {
    $name = trim((string)$name);
    if ($name === '') return 'U';
    $parts = preg_split('/\s+/', $name);
    return strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr(end($parts), 0, 1) : ''));
}

function invoice_status_badge($s) {
    $map = [
        'Paid'    => 'bg-green-100 text-green-700 border border-green-200',
        'Partial' => 'bg-yellow-100 text-yellow-700 border border-yellow-200',
        'Unpaid'  => 'bg-red-100 text-red-700 border border-red-200',
    ];
    $c = $map[$s] ?? 'bg-gray-100 text-gray-700 border border-gray-200';
    return "<span class='px-2.5 py-1 rounded-full text-xs font-semibold $c'>" . e($s) . "</span>";
}

function page_header($title) {
    $u = $_SESSION;
    $role = $u['user_role'] ?? '';

    $standalone = [
        ['index.php',  'fa-gauge-high', 'Dashboard', ['admin','staff','stock_manager']],
        ['shifts.php', 'fa-clock',      'Shifts',    ['admin','staff','stock_manager']],
    ];

    $menu = [
        ['fa-cart-shopping', 'Sales', [
            ['customers.php',   'fa-users',              'Customers',   ['admin','staff']],
            ['vehicles.php',    'fa-car',                'Vehicles',    ['admin','staff']],
            ['invoices.php',    'fa-file-invoice',       'Invoices',    ['admin','staff']],
            ['services.php',    'fa-screwdriver-wrench', 'Services',    ['admin','staff']],
        ]],
        ['fa-boxes-stacked', 'Inventory', [
            ['parts.php',       'fa-gears',              'Spare Parts', ['admin','staff','stock_manager']],
            ['suppliers.php',   'fa-truck-field',        'Suppliers',   ['admin','staff','stock_manager']],
        ]],
        ['fa-money-bill-wave', 'Finance', [
            ['expenses.php',    'fa-money-bill-wave',    'Expenses',    ['admin','staff']],
            ['reports.php',     'fa-chart-line',         'Reports',     ['admin','staff']],
        ]],
        ['fa-gears', 'Operations', [
            ['technicians.php', 'fa-user-gear',          'Technicians', ['admin','staff']],
        ]],
        ['fa-user-shield', 'Administration', [
            ['users.php',       'fa-user-shield',        'Users',       ['admin']],
            ['backup.php',      'fa-database',           'Backup',      ['admin']],
        ]],
    ];

    $cur = current_page();

    $standalone = array_values(array_filter($standalone, fn($item) => in_array($role, $item[3], true)));

    $menu = array_values(array_filter(array_map(function ($group) use ($role) {
        $group[2] = array_values(array_filter($group[2], fn($item) => in_array($role, $item[3], true)));
        return $group;
    }, $menu), fn($group) => count($group[2]) > 0));

    $openGroup = null;
    foreach ($menu as $i => $group) {
        foreach ($group[2] as $item) {
            if ($cur === $item[0]) { $openGroup = $i; break 2; }
        }
    }

    $flash = flash();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - <?= e(COMPANY_NAME) ?></title>
<?php $favicon = defined('FAVICON') ? FAVICON : 'assets/images/logo.png'; ?>
<link rel="icon" type="image/png" href="<?= e($favicon) ?>">
<link rel="apple-touch-icon" href="<?= e($favicon) ?>">
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
  .flash-anim { animation: slideDown .3s ease-out; }
  ::-webkit-scrollbar { width: 8px; height: 8px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 8px; }
  ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
  .nav-group .nav-items { max-height: 0; overflow: hidden; transition: max-height .25s ease; }
  .nav-group.open .nav-items { max-height: 600px; }
  .nav-group .chevron { transition: transform .25s ease; }
  .nav-group.open .chevron { transform: rotate(90deg); }
  .nav-link-active { background: linear-gradient(90deg,#2563eb,#4f46e5); color:#fff; box-shadow:0 10px 15px -3px rgb(30 58 138 / .35); font-weight:600; }
  .nav-hidden { display:none !important; }
  .nav-highlight { color:#fff; }
  button:focus-visible, a:focus-visible, input:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
  #backToTop { transition: opacity .2s ease, transform .2s ease; opacity:0; pointer-events:none; transform: translateY(8px); }
  #backToTop.show { opacity:1; pointer-events:auto; transform: translateY(0); }
  @media (prefers-reduced-motion: reduce) { * { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }
</style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans antialiased">

<a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-[60] focus:m-3 focus:rounded-lg focus:bg-blue-600 focus:px-3 focus:py-2 focus:text-white">Skip to content</a>

<div id="backdrop" onclick="toggleSidebar(false)" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden"></div>

<div class="flex min-h-screen">

  <aside id="sidebar" aria-label="Primary navigation"
         class="w-72 fixed inset-y-0 left-0 z-40 flex flex-col text-gray-300 bg-gradient-to-b from-gray-900 via-slate-900 to-gray-900 shadow-2xl -translate-x-full md:translate-x-0 transition-transform duration-300">

    <div class="p-4 border-b border-white/10 flex items-center gap-3">
      <img src="assets/images/logo.png" alt="<?= e(COMPANY_NAME) ?> logo" class="w-10 h-10 object-contain">
      <div class="leading-tight min-w-0">
        <div class="text-white font-bold text-sm truncate"><?= e(COMPANY_NAME) ?></div>
        <div class="text-[10px] text-gray-400 tracking-widest uppercase">Service Center</div>
      </div>
      <button onclick="toggleSidebar(false)" class="md:hidden ml-auto text-gray-400 hover:text-white" aria-label="Close menu">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>

    <div class="p-3">
      <label for="navSearch" class="sr-only">Search menu</label>
      <div class="relative">
        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
        <input id="navSearch" type="search" placeholder="Search menu..." autocomplete="off"
               class="w-full bg-white/5 border border-white/10 rounded-xl pl-9 pr-8 py-2 text-sm text-gray-200 placeholder:text-gray-500 focus:bg-white/10 focus:border-blue-400/60">
        <button id="clearNavSearch" class="hidden absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white" aria-label="Clear search">
          <i class="fa-solid fa-circle-xmark"></i>
        </button>
      </div>
    </div>

    <nav class="flex-1 p-3 pt-0 space-y-1 overflow-y-auto" aria-label="Menu">
      <?php foreach ($standalone as [$href, $icon, $label]):
          $active = is_active($href); ?>
      <a href="<?= e($href) ?>" data-nav-text="<?= e(strtolower($label)) ?>"
         class="nav-item flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm transition hover:bg-white/10 hover:text-white <?= $active ? 'nav-link-active' : '' ?>">
        <i class="fa-solid <?= $icon ?> w-5 text-center <?= $active ? '' : 'text-gray-400' ?>" aria-hidden="true"></i>
        <span><?= e($label) ?></span>
        <?php if ($active): ?><i class="fa-solid fa-circle ml-auto text-[6px] text-white/70" aria-hidden="true"></i><?php endif; ?>
      </a>
      <?php endforeach; ?>

      <?php foreach ($menu as $i => [$groupIcon, $groupLabel, $items]):
          $isOpen = ($openGroup === $i); ?>
      <div class="nav-group <?= $isOpen ? 'open' : '' ?>" data-group="<?= $i ?>" data-nav-text="<?= e(strtolower($groupLabel)) ?>">
        <button type="button" onclick="toggleGroup(this, <?= $i ?>)"
                class="nav-group-btn w-full flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm transition hover:bg-white/10 hover:text-white"
                aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="nav-group-<?= $i ?>">
          <i class="fa-solid <?= $groupIcon ?> w-5 text-center text-gray-400" aria-hidden="true"></i>
          <span class="font-semibold tracking-wide uppercase text-xs"><?= e($groupLabel) ?></span>
          <i class="fa-solid fa-chevron-right chevron ml-auto text-[10px] text-gray-500" aria-hidden="true"></i>
        </button>
        <div id="nav-group-<?= $i ?>" class="nav-items" role="group" aria-label="<?= e($groupLabel) ?>">
          <div class="pt-1 pb-1 space-y-1">
            <?php foreach ($items as [$href, $icon, $label]):
                $active = is_active($href); ?>
            <a href="<?= e($href) ?>" data-nav-text="<?= e(strtolower($label)) ?>"
               class="nav-item flex items-center gap-3 px-4 py-2 ml-4 rounded-xl text-sm transition hover:bg-white/10 hover:text-white <?= $active ? 'nav-link-active' : '' ?>">
              <i class="fa-solid <?= $icon ?> w-5 text-center <?= $active ? '' : 'text-gray-400' ?>" aria-hidden="true"></i>
              <span><?= e($label) ?></span>
              <?php if ($active): ?><i class="fa-solid fa-circle ml-auto text-[6px] text-white/70" aria-hidden="true"></i><?php endif; ?>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <div id="noNavResults" class="hidden px-4 py-6 text-sm text-gray-400 text-center">No menu items found.</div>
    </nav>

    <div class="p-4 border-t border-white/10">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-bold text-sm" aria-hidden="true">
          <?= e(initials($u['user_name'] ?? 'User')) ?>
        </div>
        <div class="min-w-0">
          <div class="text-white font-medium text-sm truncate"><?= e($u['user_name'] ?? '') ?></div>
          <div class="text-gray-400 capitalize text-xs"><i class="fa-solid fa-circle text-[6px] text-green-400 mr-1" aria-hidden="true"></i><?= e(str_replace('_', ' ', $u['user_role'] ?? '')) ?></div>
        </div>
      </div>
      <a href="logout.php" onclick="return confirm('Log out of your account?')"
         class="mt-3 block text-center bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 hover:border-red-500/50 rounded-xl py-2 text-sm transition">
        <i class="fa-solid fa-right-from-bracket mr-1" aria-hidden="true"></i> Logout
      </a>
    </div>
  </aside>

  <main id="main-content" class="flex-1 md:ml-72 min-w-0">

    <header class="sticky top-0 z-20 bg-white/85 backdrop-blur-md border-b border-gray-200 px-4 md:px-8 py-4 flex items-center gap-4 shadow-sm">
      <button onclick="toggleSidebar()" class="md:hidden text-gray-600 hover:text-gray-900 text-xl" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div class="min-w-0">
        <h1 class="text-lg md:text-xl font-bold text-gray-800 truncate"><?= e($title) ?></h1>
        <p class="text-xs text-gray-400 hidden md:block"><?= e(COMPANY_NAME) ?> · <?= date('l, d M Y') ?></p>
      </div>
      <div class="ml-auto hidden md:flex items-center gap-4">
        <span class="text-sm text-gray-500"><i class="fa-solid fa-user text-blue-500 mr-1" aria-hidden="true"></i><?= e($u['user_name'] ?? '') ?></span>
      </div>
    </header>

    <div class="p-4 md:p-8">

    <?php if ($flash): ?>
      <div data-flash-timeout="6000" role="status"
        class="flash-anim mb-5 flex items-center gap-3 px-4 py-3 rounded-xl text-sm shadow <?= $flash['type']==='success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
        <i class="fa-solid <?= $flash['type']==='success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> text-lg" aria-hidden="true"></i>
        <span><?= e($flash['msg']) ?></span>
        <button onclick="this.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600" aria-label="Dismiss message"><i class="fa-solid fa-xmark"></i></button>
      </div>
    <?php endif; ?>
<?php
}

function page_footer() {
    ?>
    <footer class="mt-10 pt-6 border-t border-gray-200 flex flex-col items-center gap-2 text-center">
      <div class="text-gray-500 text-sm">Designed &amp; Developed by</div>
      <img src="assets/images/company.png" alt="Developer company logo" class="h-10 object-contain">
      <p class="text-xs text-gray-400">© <?= date('Y') ?> <?= e(COMPANY_NAME) ?>. All rights reserved.</p>
    </footer>

    </div>
  </main>
</div>

<button id="backToTop" onclick="window.scrollTo({top:0,behavior:'smooth'})" class="fixed bottom-5 right-5 z-40 w-11 h-11 rounded-full bg-blue-600 text-white shadow-lg hover:bg-blue-700" aria-label="Back to top">
  <i class="fa-solid fa-arrow-up" aria-hidden="true"></i>
</button>

<script>
  const sidebar = document.getElementById('sidebar');
  const backdrop = document.getElementById('backdrop');
  const navSearch = document.getElementById('navSearch');
  const clearNavSearch = document.getElementById('clearNavSearch');
  const noNavResults = document.getElementById('noNavResults');
  const mq = window.matchMedia('(min-width: 768px)');

  function toggleSidebar(force) {
    const open = typeof force === 'boolean' ? force : sidebar.classList.contains('-translate-x-full');
    sidebar.classList.toggle('-translate-x-full', !open);
    backdrop.classList.toggle('hidden', !open || mq.matches);
    document.querySelector('[aria-controls="sidebar"]')?.setAttribute('aria-expanded', String(open));
  }

  function toggleGroup(btn, i) {
    const group = btn.closest('.nav-group');
    const willOpen = !group.classList.contains('open');
    group.classList.toggle('open', willOpen);
    btn.setAttribute('aria-expanded', String(willOpen));
    const saved = JSON.parse(localStorage.getItem('navOpenGroups') || '[]');
    const next = saved.filter(n => n !== i);
    if (willOpen) next.push(i);
    localStorage.setItem('navOpenGroups', JSON.stringify(next));
  }

  document.querySelectorAll('.nav-group').forEach((group, i) => {
    const saved = JSON.parse(localStorage.getItem('navOpenGroups') || '[]');
    const hasActive = group.querySelector('.nav-link-active');
    if (saved.includes(i) || hasActive) {
      group.classList.add('open');
      group.querySelector('.nav-group-btn')?.setAttribute('aria-expanded', 'true');
    }
  });

  sidebar.querySelectorAll('a[href$=".php"]').forEach(a => {
    a.addEventListener('click', () => { if (!mq.matches) toggleSidebar(false); });
  });

  function filterNav() {
    const q = (navSearch.value || '').trim().toLowerCase();
    clearNavSearch.classList.toggle('hidden', q.length === 0);
    let visible = 0;
    document.querySelectorAll('[data-nav-text]').forEach(el => {
      const text = (el.dataset.navText || '').toLowerCase();
      const show = text.includes(q);
      el.classList.toggle('nav-hidden', !show);
      if (show) {
        visible++;
        if (el.classList.contains('nav-group')) el.classList.add('open');
        if (q && el.closest('.nav-group')) el.closest('.nav-group').classList.remove('nav-hidden');
      }
    });
    document.querySelectorAll('.nav-group').forEach(group => {
      if (!q) return;
      const any = [...group.querySelectorAll('.nav-item')].some(a => !a.classList.contains('nav-hidden'));
      group.classList.toggle('nav-hidden', !any && !(group.dataset.navText || '').includes(q));
    });
    noNavResults.classList.toggle('hidden', visible > 0);
  }
  navSearch.addEventListener('input', filterNav);
  clearNavSearch.addEventListener('click', () => { navSearch.value = ''; filterNav(); navSearch.focus(); });
  document.addEventListener('keydown', e => { if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) { e.preventDefault(); navSearch.focus(); } });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') toggleSidebar(false); });

  const backToTop = document.getElementById('backToTop');
  window.addEventListener('scroll', () => backToTop.classList.toggle('show', window.scrollY > 500), { passive:true });

  document.querySelectorAll('[data-flash-timeout]').forEach(el => setTimeout(() => el.remove(), Number(el.dataset.flashTimeout || 6000)));
</script>
</body>
</html>
<?php
}