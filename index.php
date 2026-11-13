<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$role = $_SESSION['user_role'] ?? 'staff';
$today = date('Y-m-d');

// ---- Default values so every card has something to show ----
$stats = [
    'customers'        => 0,
    'vehicles'         => 0,
    'today_services'   => 0,
    'pending_invoices' => 0,
    'parts'            => 0,
    'suppliers'        => 0,
    'low_count'        => 0,
];
$income_today  = 0;
$expense_month = 0;

// ---- Queries needed by all roles ----
$low_stock = $pdo->query('SELECT * FROM parts WHERE stock_qty <= min_stock ORDER BY stock_qty ASC LIMIT 10')->fetchAll();
$upcoming = $pdo->query('SELECT s.*, v.reg_no, v.make, v.model FROM services s JOIN vehicles v ON v.id = s.vehicle_id WHERE s.next_service_date IS NOT NULL AND s.next_service_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND s.next_service_date >= CURDATE() ORDER BY s.next_service_date')->fetchAll();

// ---- Extra queries only for admin/staff ----
$recent_invoices = [];
if (in_array($role, ['admin', 'staff'], true)) {
    $stats['customers']        = $pdo->query('SELECT COUNT(*) c FROM customers')->fetch()['c'];
    $stats['vehicles']         = $pdo->query('SELECT COUNT(*) c FROM vehicles')->fetch()['c'];
    $stats['pending_invoices'] = $pdo->query("SELECT COUNT(*) c FROM invoices WHERE status != 'Paid'")->fetch()['c'];

    $st = $pdo->prepare('SELECT COUNT(*) c FROM services WHERE service_date = ?');
    $st->execute([$today]);
    $stats['today_services'] = $st->fetch()['c'];

    $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE payment_date = ?");
    $q->execute([$today]);
    $income_today = $q->fetch()['s'];

    $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ?");
    $q->execute([date('Y-m')]);
    $expense_month = $q->fetch()['s'];

    $recent_invoices = $pdo->query('SELECT i.*, v.reg_no FROM invoices i JOIN vehicles v ON v.id = i.vehicle_id ORDER BY i.id DESC LIMIT 8')->fetchAll();
}

// ---- Extra queries for stock manager ----
if ($role === 'stock_manager') {
    $stats['parts']     = $pdo->query('SELECT COUNT(*) c FROM parts')->fetch()['c'];
    $stats['vehicles']  = $pdo->query('SELECT COUNT(*) c FROM vehicles')->fetch()['c'];
    $stats['suppliers'] = $pdo->query('SELECT COUNT(*) c FROM suppliers')->fetch()['c'];
    $stats['low_count'] = $pdo->query('SELECT COUNT(*) c FROM parts WHERE stock_qty <= min_stock')->fetch()['c'];
}

// ============================================================
// CHART DATA
// ============================================================

// -- Cash flow: income vs expenses, last 7 days (admin/staff) --
$cf_labels  = [];
$cf_income  = [];
$cf_expense = [];

// -- Service volume: services per month, last 6 months (admin/staff) --
$sv_labels = [];
$sv_counts = [];

// -- Invoice status split (admin/staff) --
$inv_status_labels = [];
$inv_status_counts = [];

if (in_array($role, ['admin', 'staff'], true)) {
    $inc = $pdo->query("SELECT payment_date d, COALESCE(SUM(amount),0) s FROM payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY payment_date")->fetchAll(PDO::FETCH_KEY_PAIR);
    $exp = $pdo->query("SELECT expense_date d, COALESCE(SUM(amount),0) s FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY expense_date")->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $cf_labels[]  = date('D j', strtotime($d));
        $cf_income[]  = (float)($inc[$d] ?? 0);
        $cf_expense[] = (float)($exp[$d] ?? 0);
    }

    $svc = $pdo->query("SELECT DATE_FORMAT(service_date, '%Y-%m') m, COUNT(*) c FROM services WHERE service_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) GROUP BY m")->fetchAll(PDO::FETCH_KEY_PAIR);
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("first day of -{$i} months"));
        $sv_labels[] = date('M Y', strtotime($m));
        $sv_counts[] = (int)($svc[$m] ?? 0);
    }

    $st = $pdo->query('SELECT status, COUNT(*) c FROM invoices GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $inv_status_labels = array_keys($st);
    $inv_status_counts = array_map('intval', array_values($st));
}

// -- Parts by category + top stocked parts (stock manager) --
$pc_labels = [];
$pc_counts = [];
$ps_names  = [];
$ps_qty    = [];

if ($role === 'stock_manager') {
    $pc = $pdo->query("SELECT COALESCE(NULLIF(category,''), 'Uncategorized') cat, COUNT(*) c FROM parts GROUP BY cat ORDER BY c DESC LIMIT 8")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pc_labels = array_keys($pc);
    $pc_counts = array_map('intval', array_values($pc));

    $ps = $pdo->query('SELECT name, stock_qty FROM parts ORDER BY stock_qty DESC LIMIT 10')->fetchAll();
    foreach ($ps as $r) {
        $ps_names[] = $r['name'];
        $ps_qty[]   = (int)$r['stock_qty'];
    }
}

// Helper: safely embed PHP arrays into JavaScript
function js_array(array $arr): string {
    return json_encode($arr, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

// Map invoice status -> consistent brand color
function status_color(string $status): string {
    return match (strtolower($status)) {
        'paid'            => '#10b981',
        'partially paid'  => '#3b82f6',
        'pending'         => '#f59e0b',
        'overdue'         => '#ef4444',
        'cancelled'       => '#6b7280',
        default           => '#8b5cf6',
    };
}
$inv_status_colors = array_map('status_color', $inv_status_labels);

page_header('Dashboard');

// ---- KPI cards, each with the roles allowed to see it ----
$cards = [
    [
        'label'    => 'Total Customers',
        'val'      => number_format($stats['customers']),
        'icon'     => 'fa-users',
        'gradient' => 'from-blue-500 to-blue-600',
        'shadow'   => 'shadow-blue-500/25',
        'bg_glow'  => 'bg-blue-50',
        'href'     => 'customers.php',
        'roles'    => ['admin','staff'],
    ],
    [
        'label'    => 'Registered Vehicles',
        'val'      => number_format($stats['vehicles']),
        'icon'     => 'fa-car-side',
        'gradient' => 'from-indigo-500 to-indigo-600',
        'shadow'   => 'shadow-indigo-500/25',
        'bg_glow'  => 'bg-indigo-50',
        'href'     => 'vehicles.php',
        'roles'    => ['admin','staff','stock_manager'],
    ],
    [
        'label'    => 'Services Today',
        'val'      => number_format($stats['today_services']),
        'icon'     => 'fa-screwdriver-wrench',
        'gradient' => 'from-emerald-500 to-emerald-600',
        'shadow'   => 'shadow-emerald-500/25',
        'bg_glow'  => 'bg-emerald-50',
        'href'     => 'services.php',
        'roles'    => ['admin','staff'],
    ],
    [
        'label'    => 'Pending Invoices',
        'val'      => number_format($stats['pending_invoices']),
        'icon'     => 'fa-file-invoice',
        'gradient' => 'from-amber-500 to-amber-600',
        'shadow'   => 'shadow-amber-500/25',
        'bg_glow'  => 'bg-amber-50',
        'href'     => 'invoices.php',
        'roles'    => ['admin','staff'],
    ],
    [
        'label'    => "Today's Income",
        'val'      => money($income_today),
        'icon'     => 'fa-sack-dollar',
        'gradient' => 'from-green-500 to-teal-600',
        'shadow'   => 'shadow-green-500/25',
        'bg_glow'  => 'bg-green-50',
        'href'     => 'reports.php',
        'roles'    => ['admin','staff'],
    ],
    [
        'label'    => 'Monthly Expenses',
        'val'      => money($expense_month),
        'icon'     => 'fa-money-bill-trend-up',
        'gradient' => 'from-rose-500 to-rose-600',
        'shadow'   => 'shadow-rose-500/25',
        'bg_glow'  => 'bg-rose-50',
        'href'     => 'expenses.php',
        'roles'    => ['admin'],
    ],
    [
        'label'    => 'Spare Parts',
        'val'      => number_format($stats['parts'] ?? 0),
        'icon'     => 'fa-gears',
        'gradient' => 'from-cyan-500 to-cyan-600',
        'shadow'   => 'shadow-cyan-500/25',
        'bg_glow'  => 'bg-cyan-50',
        'href'     => 'parts.php',
        'roles'    => ['stock_manager'],
    ],
    [
        'label'    => 'Low Stock Items',
        'val'      => number_format($stats['low_count'] ?? 0),
        'icon'     => 'fa-triangle-exclamation',
        'gradient' => 'from-amber-500 to-orange-600',
        'shadow'   => 'shadow-amber-500/25',
        'bg_glow'  => 'bg-amber-50',
        'href'     => 'parts.php',
        'roles'    => ['stock_manager'],
    ],
    [
        'label'    => 'Suppliers',
        'val'      => number_format($stats['suppliers'] ?? 0),
        'icon'     => 'fa-truck-field',
        'gradient' => 'from-violet-500 to-violet-600',
        'shadow'   => 'shadow-violet-500/25',
        'bg_glow'  => 'bg-violet-50',
        'href'     => 'suppliers.php',
        'roles'    => ['stock_manager'],
    ],
];

// Keep only cards this role is allowed to see
$cards = array_values(array_filter($cards, fn($c) => in_array($role, $c['roles'], true)));
?>

<!-- Top Sub-Header & Quick Actions -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
  <div>
    <p class="text-sm text-gray-500 flex items-center gap-2">
      <i class="fa-regular fa-calendar text-indigo-500"></i>
      <span>Overview for <strong class="text-gray-700 font-semibold"><?= date('l, F j, Y') ?></strong></span>
    </p>
  </div>
  <div class="flex flex-wrap items-center gap-2.5">
    <?php if (in_array($role, ['admin','staff'], true)): ?>
    <a href="customers.php" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-700 text-xs font-semibold px-3.5 py-2 rounded-xl shadow-sm transition">
      <i class="fa-solid fa-user-plus text-blue-500"></i> Add Customer
    </a>
    <a href="services.php" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-700 text-xs font-semibold px-3.5 py-2 rounded-xl shadow-sm transition">
      <i class="fa-solid fa-wrench text-emerald-500"></i> New Service
    </a>
    <a href="invoices.php" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-4 py-2 rounded-xl shadow-sm shadow-indigo-500/20 transition">
      <i class="fa-solid fa-plus"></i> Create Invoice
    </a>
    <?php else: ?>
    <a href="parts.php" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-700 text-xs font-semibold px-3.5 py-2 rounded-xl shadow-sm transition">
      <i class="fa-solid fa-gears text-cyan-500"></i> Add Part
    </a>
    <a href="suppliers.php" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-700 text-xs font-semibold px-3.5 py-2 rounded-xl shadow-sm transition">
      <i class="fa-solid fa-truck-field text-violet-500"></i> Add Supplier
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- KPI Stat Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-8">
  <?php foreach ($cards as $c): ?>
  <a href="<?= $c['href'] ?>" class="group relative overflow-hidden bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md hover:border-gray-200 hover:-translate-y-0.5 transition-all duration-200">
    <!-- Decorative corner glow -->
    <div class="absolute -right-6 -top-6 w-24 h-24 rounded-full <?= $c['bg_glow'] ?> opacity-60 group-hover:scale-125 transition-transform duration-300 pointer-events-none"></div>

    <div class="relative flex items-center justify-between mb-4">
      <div class="w-11 h-11 rounded-xl bg-gradient-to-br <?= $c['gradient'] ?> text-white shadow-lg <?= $c['shadow'] ?> flex items-center justify-center">
        <i class="fa-solid <?= $c['icon'] ?> text-lg"></i>
      </div>
      <span class="w-7 h-7 rounded-full bg-gray-50 group-hover:bg-gray-100 flex items-center justify-center text-gray-400 group-hover:text-gray-600 transition">
        <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
      </span>
    </div>

    <div class="relative">
      <div class="text-2xl font-extrabold text-gray-800 tracking-tight truncate"><?= $c['val'] ?></div>
      <div class="text-xs font-medium text-gray-400 mt-0.5"><?= $c['label'] ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- ===================== CHARTS ===================== -->
<?php if (in_array($role, ['admin','staff'], true)): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

  <!-- Cash Flow: Income vs Expenses (last 7 days) -->
  <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg bg-green-50 text-green-600 flex items-center justify-center">
          <i class="fa-solid fa-chart-line"></i>
        </div>
        <div>
          <h2 class="font-bold text-gray-800 text-sm">Cash Flow</h2>
          <p class="text-xs text-gray-400">Income vs expenses — last 7 days</p>
        </div>
      </div>
      <div class="flex items-center gap-4 text-xs font-medium text-gray-500">
        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>Income</span>
        <span class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-rose-500"></span>Expenses</span>
      </div>
    </div>
    <div class="p-6 flex-1"><div class="h-64"><canvas id="cashFlowChart"></canvas></div></div>
  </div>

  <!-- Invoice Status split -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg bg-violet-50 text-violet-600 flex items-center justify-center">
          <i class="fa-solid fa-chart-pie"></i>
        </div>
        <div>
          <h2 class="font-bold text-gray-800 text-sm">Invoice Status</h2>
          <p class="text-xs text-gray-400">All-time split by status</p>
        </div>
      </div>
    </div>
    <div class="p-6 flex-1 flex items-center justify-center"><div class="h-64 w-full"><canvas id="invoiceStatusChart"></canvas></div></div>
  </div>

  <!-- Service volume: last 6 months -->
  <div class="lg:col-span-3 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
          <i class="fa-solid fa-chart-column"></i>
        </div>
        <div>
          <h2 class="font-bold text-gray-800 text-sm">Service Volume</h2>
          <p class="text-xs text-gray-400">Completed services per month — last 6 months</p>
        </div>
      </div>
    </div>
    <div class="p-6"><div class="h-56"><canvas id="serviceVolumeChart"></canvas></div></div>
  </div>
</div>
<?php endif; ?>

<?php if ($role === 'stock_manager'): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

  <!-- Parts by category -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
      <div class="w-9 h-9 rounded-lg bg-cyan-50 text-cyan-600 flex items-center justify-center">
        <i class="fa-solid fa-chart-pie"></i>
      </div>
      <div>
        <h2 class="font-bold text-gray-800 text-sm">Parts by Category</h2>
        <p class="text-xs text-gray-400">Inventory distribution</p>
      </div>
    </div>
    <div class="p-6 flex-1 flex items-center justify-center"><div class="h-64 w-full"><canvas id="partsCategoryChart"></canvas></div></div>
  </div>

  <!-- Top 10 stocked parts -->
  <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden flex flex-col">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3">
      <div class="w-9 h-9 rounded-lg bg-violet-50 text-violet-600 flex items-center justify-center">
        <i class="fa-solid fa-chart-bar"></i>
      </div>
      <div>
        <h2 class="font-bold text-gray-800 text-sm">Top Stocked Parts</h2>
        <p class="text-xs text-gray-400">10 parts with the highest quantity on hand</p>
      </div>
    </div>
    <div class="p-6 flex-1"><div class="h-64"><canvas id="partsStockChart"></canvas></div></div>
  </div>
</div>
<?php endif; ?>
<!-- =================== END CHARTS =================== -->

<!-- Alerts & Reminders Grid -->
<div class="grid lg:grid-cols-2 gap-6 mb-8">

  <!-- Low Stock Alerts -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
          <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div>
          <h2 class="font-bold text-gray-800 text-sm">Low Stock Alerts</h2>
          <p class="text-xs text-gray-400">Parts at or below minimum threshold</p>
        </div>
      </div>
      <?php if ($low_stock): ?>
        <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-600 border border-red-100">
          <?= count($low_stock) ?> Alert<?= count($low_stock) > 1 ? 's' : '' ?>
        </span>
      <?php endif; ?>
    </div>

    <div class="p-6 flex-1">
      <?php if (!$low_stock): ?>
        <div class="h-full flex flex-col items-center justify-center text-center py-8">
          <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-500 flex items-center justify-center mb-3">
            <i class="fa-solid fa-check text-xl"></i>
          </div>
          <p class="text-sm font-medium text-gray-700">Inventory looks great!</p>
          <p class="text-xs text-gray-400 mt-1">All parts are sufficiently stocked right now.</p>
        </div>
      <?php else: ?>
        <ul class="divide-y divide-gray-100 text-sm -my-2">
          <?php foreach ($low_stock as $p): 
            $is_out = (int)$p['stock_qty'] <= 0;
          ?>
          <li class="py-3 flex items-center justify-between gap-4 hover:bg-gray-50/60 px-2 -mx-2 rounded-lg transition">
            <div class="min-w-0">
              <p class="font-semibold text-gray-800 truncate"><?= e($p['name']) ?></p>
              <p class="text-xs text-gray-400 flex items-center gap-2 mt-0.5">
                <span class="font-mono bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-[11px]"><?= e($p['part_no']) ?></span>
                <span>Min required: <?= (int)$p['min_stock'] ?></span>
              </p>
            </div>
            <div class="shrink-0 text-right">
              <?php if ($is_out): ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700">
                  <span class="w-1.5 h-1.5 rounded-full bg-red-600 animate-pulse"></span>
                  Out of stock
                </span>
              <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200/60">
                  <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                  <?= (int)$p['stock_qty'] ?> left
                </span>
              <?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <!-- Upcoming Service Reminders -->
  <div class="bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
          <i class="fa-solid fa-bell"></i>
        </div>
        <div>
          <h2 class="font-bold text-gray-800 text-sm">Upcoming Service Reminders</h2>
          <p class="text-xs text-gray-400">Scheduled within the next 14 days</p>
        </div>
      </div>
      <?php if ($upcoming): ?>
        <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-600 border border-blue-100">
          <?= count($upcoming) ?> Due
        </span>
      <?php endif; ?>
    </div>

    <div class="p-6 flex-1">
      <?php if (!$upcoming): ?>
        <div class="h-full flex flex-col items-center justify-center text-center py-8">
          <div class="w-12 h-12 rounded-full bg-gray-50 text-gray-400 flex items-center justify-center mb-3">
            <i class="fa-regular fa-calendar-check text-xl"></i>
          </div>
          <p class="text-sm font-medium text-gray-700">No upcoming reminders</p>
          <p class="text-xs text-gray-400 mt-1">No vehicles scheduled for follow-up in the next 14 days.</p>
        </div>
      <?php else: ?>
        <ul class="divide-y divide-gray-100 text-sm -my-2">
          <?php foreach ($upcoming as $s): 
            $days_left = (int)round((strtotime($s['next_service_date']) - strtotime($today)) / 86400);
            if ($days_left === 0) {
                $due_badge = '<span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-600 border border-red-100">Due Today</span>';
            } elseif ($days_left === 1) {
                $due_badge = '<span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-600 border border-amber-100">Tomorrow</span>';
            } else {
                $due_badge = '<span class="px-2 py-0.5 rounded text-[11px] font-medium bg-blue-50 text-blue-600">In ' . $days_left . ' days</span>';
            }
          ?>
          <li class="py-3 flex items-center justify-between gap-4 hover:bg-gray-50/60 px-2 -mx-2 rounded-lg transition">
            <div class="flex items-center gap-3 min-w-0">
              <span class="font-mono text-xs font-bold bg-slate-100 border border-slate-300 text-slate-700 px-2 py-1 rounded shadow-2xs shrink-0">
                <?= e($s['reg_no']) ?>
              </span>
              <div class="min-w-0">
                <p class="font-semibold text-gray-800 truncate"><?= e($s['make']) ?> <?= e($s['model']) ?></p>
                <p class="text-xs text-gray-400">
                  <i class="fa-regular fa-calendar mr-1"></i><?= e($s['next_service_date']) ?>
                  <?php if ($s['next_service_mileage']): ?>
                    <span class="mx-1">•</span>
                    <i class="fa-solid fa-gauge-high mr-1"></i><?= number_format((int)$s['next_service_mileage']) ?> km
                  <?php endif; ?>
                </p>
              </div>
            </div>
            <div class="shrink-0">
              <?= $due_badge ?>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Recent Invoices Table (admin & staff only) -->
<?php if (in_array($role, ['admin','staff'], true)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
  <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
        <i class="fa-solid fa-clock-rotate-left"></i>
      </div>
      <div>
        <h2 class="font-bold text-gray-800 text-sm">Recent Invoices</h2>
        <p class="text-xs text-gray-400">Latest billing activity across the workshop</p>
      </div>
    </div>
    <a href="invoices.php" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center gap-1">
      View All <i class="fa-solid fa-arrow-right text-[10px]"></i>
    </a>
  </div>

  <?php if (!$recent_invoices): ?>
    <div class="p-10 text-center">
      <p class="text-sm text-gray-400">No invoices have been generated yet.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-gray-50/70 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-100">
            <th class="py-3.5 px-6">Invoice #</th>
            <th class="py-3.5 px-4">Vehicle</th>
            <th class="py-3.5 px-4">Date</th>
            <th class="py-3.5 px-4 text-right">Grand Total</th>
            <th class="py-3.5 px-4 text-center">Status</th>
            <th class="py-3.5 px-6 text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php foreach ($recent_invoices as $inv): ?>
          <tr class="hover:bg-gray-50/80 transition">
            <td class="py-3.5 px-6 font-semibold">
              <a class="text-indigo-600 hover:text-indigo-800 flex items-center gap-1.5" href="invoice_print.php?id=<?= $inv['id'] ?>">
                <i class="fa-regular fa-file-lines text-gray-400"></i>
                <?= e($inv['invoice_no']) ?>
              </a>
            </td>
            <td class="py-3.5 px-4">
              <span class="font-mono text-xs font-semibold bg-slate-100 border border-slate-200 text-slate-700 px-2 py-0.5 rounded">
                <?= e($inv['reg_no']) ?>
              </span>
            </td>
            <td class="py-3.5 px-4 text-gray-500">
              <?= e($inv['invoice_date']) ?>
            </td>
            <td class="py-3.5 px-4 text-right font-bold text-gray-800">
              <?= money($inv['grand_total']) ?>
            </td>
            <td class="py-3.5 px-4 text-center">
              <?= invoice_status_badge($inv['status']) ?>
            </td>
            <td class="py-3.5 px-6 text-right">
              <a href="invoice_print.php?id=<?= $inv['id'] ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-gray-400 hover:text-indigo-600 hover:bg-indigo-50 transition" title="Print / View Invoice">
                <i class="fa-solid fa-print"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ===================== CHART SCRIPTS ===================== -->
<?php if (in_array($role, ['admin','staff'], true) || $role === 'stock_manager'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // Currency symbol taken from your existing money() function (assumes prefix, e.g. "Rs 1,234.56")
  const currencyPrefix = '<?= trim(preg_replace('/[0-9.,\s]/u', '', money(0))) ?>';
  const moneyFmt = v => currencyPrefix + ' ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  Chart.defaults.font.family = 'inherit';
  Chart.defaults.color = '#9ca3af';
  Chart.defaults.borderColor = 'rgba(229, 231, 235, 0.7)';

  const tooltipBase = {
    backgroundColor: '#1f2937',
    titleFont: { weight: '700' },
    padding: 10,
    cornerRadius: 8,
    boxPadding: 4,
  };

  // ---------- Cash flow: income vs expenses (last 7 days) ----------
  const cfEl = document.getElementById('cashFlowChart');
  if (cfEl) {
    const ctx = cfEl.getContext('2d');
    const grad = (hex, alphaTop) => {
      const g = ctx.createLinearGradient(0, 0, 0, 260);
      g.addColorStop(0, hex + alphaTop);
      g.addColorStop(1, hex + '00');
      return g;
    };
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?= js_array($cf_labels) ?>,
        datasets: [
          {
            label: 'Income',
            data: <?= js_array($cf_income) ?>,
            borderColor: '#10b981',
            backgroundColor: grad('#10b981', '33'),
            fill: true,
            tension: 0.4,
            borderWidth: 2.5,
            pointRadius: 3,
            pointBackgroundColor: '#10b981',
            pointBorderColor: '#fff',
            pointBorderWidth: 1.5,
          },
          {
            label: 'Expenses',
            data: <?= js_array($cf_expense) ?>,
            borderColor: '#f43f5e',
            backgroundColor: grad('#f43f5e', '26'),
            fill: true,
            tension: 0.4,
            borderWidth: 2.5,
            pointRadius: 3,
            pointBackgroundColor: '#f43f5e',
            pointBorderColor: '#fff',
            pointBorderWidth: 1.5,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: { ...tooltipBase, callbacks: { label: c => ` ${c.dataset.label}: ${moneyFmt(c.parsed.y)}` } },
        },
        scales: {
          y: { beginAtZero: true, ticks: { callback: v => currencyPrefix + ' ' + Number(v).toLocaleString() }, grid: { drawBorder: false } },
          x: { grid: { display: false } },
        },
      },
    });
  }

  // ---------- Invoice status doughnut ----------
  const stEl = document.getElementById('invoiceStatusChart');
  if (stEl) {
    new Chart(stEl, {
      type: 'doughnut',
      data: {
        labels: <?= js_array($inv_status_labels) ?>,
        datasets: [{
          data: <?= js_array($inv_status_counts) ?>,
          backgroundColor: <?= js_array($inv_status_colors) ?>,
          borderWidth: 2,
          borderColor: '#ffffff',
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 14, font: { size: 11 } } },
          tooltip: { ...tooltipBase, callbacks: { label: c => ` ${c.label}: ${c.parsed} invoice${c.parsed === 1 ? '' : 's'}` } },
        },
      },
    });
  }

  // ---------- Service volume: services per month (last 6 months) ----------
  const svEl = document.getElementById('serviceVolumeChart');
  if (svEl) {
    new Chart(svEl, {
      type: 'bar',
      data: {
        labels: <?= js_array($sv_labels) ?>,
        datasets: [{
          label: 'Services',
          data: <?= js_array($sv_counts) ?>,
          backgroundColor: 'rgba(99, 102, 241, 0.85)',
          hoverBackgroundColor: '#4f46e5',
          borderRadius: 8,
          borderSkipped: false,
          maxBarThickness: 56,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { ...tooltipBase, callbacks: { label: c => ` ${c.parsed.y} service${c.parsed.y === 1 ? '' : 's'}` } },
        },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 }, grid: { drawBorder: false } },
          x: { grid: { display: false } },
        },
      },
    });
  }

  // ---------- Stock manager: parts by category ----------
  const pcEl = document.getElementById('partsCategoryChart');
  if (pcEl) {
    new Chart(pcEl, {
      type: 'doughnut',
      data: {
        labels: <?= js_array($pc_labels) ?>,
        datasets: [{
          data: <?= js_array($pc_counts) ?>,
          backgroundColor: ['#06b6d4', '#8b5cf6', '#f59e0b', '#10b981', '#f43f5e', '#3b82f6', '#ec4899', '#64748b'],
          borderWidth: 2,
          borderColor: '#ffffff',
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '55%',
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 12, font: { size: 11 } } },
          tooltip: { ...tooltipBase, callbacks: { label: c => ` ${c.label}: ${c.parsed} part${c.parsed === 1 ? '' : 's'}` } },
        },
      },
    });
  }

  // ---------- Stock manager: top 10 stocked parts ----------
  const psEl = document.getElementById('partsStockChart');
  if (psEl) {
    new Chart(psEl, {
      type: 'bar',
      data: {
        labels: <?= js_array($ps_names) ?>,
        datasets: [{
          label: 'Qty in stock',
          data: <?= js_array($ps_qty) ?>,
          backgroundColor: 'rgba(139, 92, 246, 0.8)',
          hoverBackgroundColor: '#7c3aed',
          borderRadius: 6,
          borderSkipped: false,
          maxBarThickness: 22,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: tooltipBase,
        },
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 }, grid: { drawBorder: false } },
          y: { grid: { display: false }, ticks: { font: { size: 11 } } },
        },
      },
    });
  }
});
</script>
<?php endif; ?>

<?php page_footer(); ?>