<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function db() {
    static $pdo = null;
    if ($pdo === null) {
        require_once __DIR__ . '/../config/database.php';
    }
    return $GLOBALS['pdo'] ?? null;
}

function e($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }

function require_login() {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function require_role(...$roles) {
    require_login();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        die('Access denied.');
    }
}

function require_admin() {
    require_role('admin');
}

function require_stock_manager() {
    require_role('admin', 'stock_manager');
}

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

function page_header($title) {
    $u = $_SESSION;
    $role = $u['user_role'] ?? '';

    // Full menu with roles allowed to see each item
    $nav = [
        ['index.php',       'fa-gauge-high',          'Dashboard',    ['admin','staff','stock_manager']],
        ['customers.php',   'fa-users',               'Customers',    ['admin','staff']],
        ['vehicles.php',    'fa-car',                 'Vehicles',     ['admin','staff']],
        ['services.php',    'fa-screwdriver-wrench',  'Services',     ['admin','staff']],
        ['parts.php',       'fa-gears',               'Spare Parts',  ['admin','staff','stock_manager']],
        ['invoices.php',    'fa-file-invoice',        'Invoices',     ['admin','staff']],
        ['suppliers.php',   'fa-truck-field',         'Suppliers',    ['admin','staff','stock_manager']],
        ['expenses.php',    'fa-money-bill-wave',     'Expenses',     ['admin','staff']],
        ['reports.php',     'fa-chart-line',          'Reports',      ['admin','staff']],
        ['users.php',       'fa-user-shield',         'Users',        ['admin']],
        ['technicians.php', 'fa-user-gear',           'Technicians',  ['admin','staff']],
        ['backup.php',      'fa-database',            'Backup',       ['admin']],
        ['shifts.php', 'fa-clock', 'Shifts', ['admin','staff','stock_manager']],
    ];

    // Keep only items this role is allowed to see
    $nav = array_values(array_filter($nav, fn($item) => in_array($role, $item[3], true)));

    $cur = basename($_SERVER['PHP_SELF']);
    $flash = flash();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - <?= COMPANY_NAME ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
  @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
  .flash-anim { animation: slideDown .3s ease-out; }
  ::-webkit-scrollbar { width: 8px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 8px; }
  ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
</style>
</head>
<body class="bg-gray-100 text-gray-800 font-sans">

<!-- Mobile backdrop -->
<div id="backdrop" onclick="toggleSidebar()" class="fixed inset-0 bg-black/50 z-30 hidden md:hidden"></div>

<div class="flex min-h-screen">

  <!-- ===== Sidebar ===== -->
  <aside id="sidebar"
         class="w-64 fixed inset-y-0 left-0 z-40 flex flex-col text-gray-300
                bg-gradient-to-b from-gray-900 via-slate-900 to-gray-900
                shadow-2xl transform -translate-x-full md:translate-x-0 transition-transform duration-300">

    <!-- Brand -->
    <div class="p-5 border-b border-white/10">
      <div class="flex items-center gap-3">
        <img src="assets/images/logo.png" alt="Logo" class="w-10 h-10 object-contain">
        <div class="leading-tight">
          <div class="text-white font-bold text-sm"><?= COMPANY_NAME ?></div>
          <div class="text-[10px] text-gray-400 tracking-widest uppercase">Service Center</div>
        </div>
      </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 p-3 space-y-1 overflow-y-auto">
      <?php foreach ($nav as [$href, $icon, $label]):
          $active = $cur === $href; ?>
      <a href="<?= $href ?>"
         class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-sm transition
                <?= $active
                    ? 'bg-gradient-to-r from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-900/40 font-semibold'
                    : 'hover:bg-white/10 hover:text-white' ?>">
        <i class="fa-solid <?= $icon ?> w-5 text-center <?= $active ? '' : 'text-gray-400' ?>"></i><?= $label ?>
        <?php if ($active): ?><i class="fa-solid fa-circle ml-auto text-[6px] text-white/70"></i><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <!-- User -->
    <div class="p-4 border-t border-white/10">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white font-bold text-sm">
          <?= e(strtoupper(substr($u['user_name'] ?? 'U', 0, 1))) ?>
        </div>
        <div class="min-w-0">
          <div class="text-white font-medium text-sm truncate"><?= e($u['user_name'] ?? '') ?></div>
          <div class="text-gray-400 capitalize text-xs"><i class="fa-solid fa-circle text-[6px] text-green-400 mr-1"></i><?= e(str_replace('_', ' ', $u['user_role'] ?? '')) ?></div>
        </div>
      </div>
      <a href="logout.php"
         class="mt-3 block text-center bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/30 hover:border-red-500/50 rounded-xl py-2 text-sm transition">
        <i class="fa-solid fa-right-from-bracket mr-1"></i> Logout
      </a>
    </div>
  </aside>

  <!-- ===== Main ===== -->
  <main class="flex-1 md:ml-64">

    <!-- Topbar -->
    <header class="sticky top-0 z-20 bg-white/80 backdrop-blur-md border-b border-gray-200 px-4 md:px-8 py-4 flex items-center gap-4 shadow-sm">
      <button onclick="toggleSidebar()" class="md:hidden text-gray-600 hover:text-gray-900 text-xl">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div>
        <h1 class="text-lg md:text-xl font-bold text-gray-800"><?= e($title) ?></h1>
        <p class="text-xs text-gray-400 hidden md:block"><?= e(COMPANY_NAME) ?> · <?= date('l, d M Y') ?></p>
      </div>
      <div class="ml-auto hidden md:flex items-center gap-4">
        <span class="text-sm text-gray-500">
          <i class="fa-solid fa-user text-blue-500 mr-1"></i>
          <?= e($u['user_name'] ?? '') ?>
        </span>
      </div>
    </header>

    <div class="p-4 md:p-8">

    <!-- Flash message -->
    <?php if ($flash): ?>
      <div class="flash-anim mb-5 flex items-center gap-3 px-4 py-3 rounded-xl text-sm shadow
        <?= $flash['type']==='success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
        <i class="fa-solid <?= $flash['type']==='success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> text-lg"></i>
        <?= e($flash['msg']) ?>
        <button onclick="this.parentElement.remove()" class="ml-auto text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark"></i></button>
      </div>
    <?php endif; ?>
<?php
}

function page_footer() {
    ?>
    <!-- Footer -->
    <footer class="mt-10 pt-6 border-t border-gray-200 flex flex-col items-center gap-2 text-center">
      <div class="text-gray-500 text-sm">Designed &amp; Developed by</div>
      <img src="assets/images/company.png" alt="Developer Logo" class="h-10 object-contain">
      <p class="text-xs text-gray-400">© <?= date('Y') ?> All rights reserved.</p>
    </footer>

</div>
</main>
</div>

<script>
  function toggleSidebar() {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('backdrop');
    sb.classList.toggle('-translate-x-full');
    bd.classList.toggle('hidden');
  }
</script>
</body>
</html>
<?php
}

function invoice_status_badge($s) {
    $map = [
        'Paid'    => 'bg-green-100 text-green-700 border border-green-200',
        'Partial' => 'bg-yellow-100 text-yellow-700 border border-yellow-200',
        'Unpaid'  => 'bg-red-100 text-red-700 border border-red-200',
    ];
    $c = $map[$s] ?? 'bg-gray-100 text-gray-700';
    return "<span class='px-2.5 py-1 rounded-full text-xs font-semibold $c'>$s</span>";
}
?>