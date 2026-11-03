<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$today = date('Y-m-d');
$stats = [
    'customers' => $pdo->query('SELECT COUNT(*) c FROM customers')->fetch()['c'],
    'vehicles'  => $pdo->query('SELECT COUNT(*) c FROM vehicles')->fetch()['c'],
    'today_services' => $pdo->prepare('SELECT COUNT(*) c FROM services WHERE service_date = ?'),
    'pending_invoices' => $pdo->query("SELECT COUNT(*) c FROM invoices WHERE status != 'Paid'")->fetch()['c'],
];
$stats['today_services']->execute([$today]);
$stats['today_services'] = $stats['today_services']->fetch()['c'];

$income_today = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE payment_date = ?");
$income_today->execute([$today]);
$income_today = $income_today->fetch()['s'];

$expense_month = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ?");
$expense_month->execute([date('Y-m')]);
$expense_month = $expense_month->fetch()['s'];

$low_stock = $pdo->query('SELECT * FROM parts WHERE stock_qty <= min_stock ORDER BY stock_qty ASC LIMIT 10')->fetchAll();
$recent_invoices = $pdo->query('SELECT i.*, v.reg_no FROM invoices i JOIN vehicles v ON v.id = i.vehicle_id ORDER BY i.id DESC LIMIT 8')->fetchAll();
$upcoming = $pdo->query('SELECT s.*, v.reg_no, v.make, v.model FROM services s JOIN vehicles v ON v.id = s.vehicle_id WHERE s.next_service_date IS NOT NULL AND s.next_service_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND s.next_service_date >= CURDATE() ORDER BY s.next_service_date')->fetchAll();

page_header('Dashboard');

$cards = [
    [
        'label'    => 'Total Customers',
        'val'      => number_format($stats['customers']),
        'icon'     => 'fa-users',
        'gradient' => 'from-blue-500 to-blue-600',
        'shadow'   => 'shadow-blue-500/25',
        'bg_glow'  => 'bg-blue-50',
        'href'     => 'customers.php'
    ],
    [
        'label'    => 'Registered Vehicles',
        'val'      => number_format($stats['vehicles']),
        'icon'     => 'fa-car-side',
        'gradient' => 'from-indigo-500 to-indigo-600',
        'shadow'   => 'shadow-indigo-500/25',
        'bg_glow'  => 'bg-indigo-50',
        'href'     => 'vehicles.php'
    ],
    [
        'label'    => 'Services Today',
        'val'      => number_format($stats['today_services']),
        'icon'     => 'fa-screwdriver-wrench',
        'gradient' => 'from-emerald-500 to-emerald-600',
        'shadow'   => 'shadow-emerald-500/25',
        'bg_glow'  => 'bg-emerald-50',
        'href'     => 'services.php'
    ],
    [
        'label'    => 'Pending Invoices',
        'val'      => number_format($stats['pending_invoices']),
        'icon'     => 'fa-file-invoice',
        'gradient' => 'from-amber-500 to-amber-600',
        'shadow'   => 'shadow-amber-500/25',
        'bg_glow'  => 'bg-amber-50',
        'href'     => 'invoices.php'
    ],
    [
        'label'    => "Today's Income",
        'val'      => money($income_today),
        'icon'     => 'fa-sack-dollar',
        'gradient' => 'from-green-500 to-teal-600',
        'shadow'   => 'shadow-green-500/25',
        'bg_glow'  => 'bg-green-50',
        'href'     => 'reports.php'
    ],
    [
        'label'    => 'Monthly Expenses',
        'val'      => money($expense_month),
        'icon'     => 'fa-money-bill-trend-up',
        'gradient' => 'from-rose-500 to-rose-600',
        'shadow'   => 'shadow-rose-500/25',
        'bg_glow'  => 'bg-rose-50',
        'href'     => 'expenses.php'
    ],
];
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
    <a href="customers.php" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-700 text-xs font-semibold px-3.5 py-2 rounded-xl shadow-sm transition">
      <i class="fa-solid fa-user-plus text-blue-500"></i> Add Customer
    </a>
    <a href="services.php" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-700 text-xs font-semibold px-3.5 py-2 rounded-xl shadow-sm transition">
      <i class="fa-solid fa-wrench text-emerald-500"></i> New Service
    </a>
    <a href="invoices.php" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-4 py-2 rounded-xl shadow-sm shadow-indigo-500/20 transition">
      <i class="fa-solid fa-plus"></i> Create Invoice
    </a>
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

<!-- Recent Invoices Table -->
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

<?php page_footer(); ?>