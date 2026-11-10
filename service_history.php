<?php
require_once 'includes/functions.php';
require_once 'config/database.php';
require_login();

$vid = (int)($_GET['vehicle_id'] ?? 0);
$st = $pdo->prepare('SELECT v.*, c.name AS customer, c.phone FROM vehicles v JOIN customers c ON c.id=v.customer_id WHERE v.id=?');
$st->execute([$vid]);
$vehicle = $st->fetch();
if (!$vehicle) { flash('Vehicle not found.', 'error'); redirect('vehicles.php'); }

$st = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM invoices i WHERE i.service_id = s.id) AS has_invoice FROM services s WHERE s.vehicle_id=? ORDER BY s.service_date DESC');
$st->execute([$vid]);
$services = $st->fetchAll();

$totalSpent = 0;
foreach ($services as $s) { $totalSpent += (float)$s['labor_charge']; }
$completed = array_filter($services, fn($s) => $s['status'] === 'Completed');

$statusStyles = [
    'Completed'   => 'bg-emerald-100 text-emerald-700',
    'In Progress' => 'bg-amber-100 text-amber-700',
];
page_header('Service History - ' . $vehicle['reg_no']);
?>

<style>
  .sh-card { background:#fff; border-radius:1.25rem;
    box-shadow:0 1px 3px rgba(15,23,42,.07), 0 8px 24px -12px rgba(15,23,42,.12); }
  .sh-row { transition:background .15s; }
  .sh-row:hover { background:#f8fafc; }
  .sh-action { display:inline-flex; align-items:center; gap:.4rem; padding:.5rem 1rem; border-radius:.75rem;
               font-size:.85rem; font-weight:600; transition:all .18s; }
  .sh-action:hover { transform:translateY(-2px); box-shadow:0 4px 12px -4px rgba(15,23,42,.25); }
  .sh-iconbtn { display:inline-flex; align-items:center; justify-content:center; width:2rem; height:2rem;
                border-radius:.55rem; transition:all .18s; }
  .sh-iconbtn:hover { transform:translateY(-2px); }
</style>

<!-- Page hero -->
<div class="rounded-2xl bg-gradient-to-r from-indigo-700 via-violet-700 to-purple-700 text-white px-6 py-5 mb-6 flex flex-wrap items-center justify-between gap-4 shadow-lg">
  <div>
    <h1 class="text-xl font-bold flex items-center gap-2">
      <i class="fa-solid fa-clock-rotate-left"></i> Service History
      <span class="ml-1 px-2.5 py-0.5 rounded-full bg-white/20 text-sm font-bold tracking-wide"><?= e($vehicle['reg_no']) ?></span>
    </h1>
    <p class="text-indigo-100 text-sm mt-1">
      <?= e($vehicle['make']) ?> <?= e($vehicle['model']) ?> <?= e($vehicle['year']) ?>
      &middot; owned by <strong><?= e($vehicle['customer']) ?></strong>
    </p>
  </div>
  <div class="flex gap-3 text-center">
    <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-2">
      <div class="text-lg font-bold leading-tight"><?= count($services) ?></div>
      <div class="text-[11px] text-indigo-100 uppercase tracking-wide">Services</div>
    </div>
    <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-2">
      <div class="text-lg font-bold leading-tight"><?= count($completed) ?></div>
      <div class="text-[11px] text-indigo-100 uppercase tracking-wide">Completed</div>
    </div>
    <div class="bg-white/15 backdrop-blur rounded-xl px-4 py-2">
      <div class="text-lg font-bold leading-tight"><?= money($totalSpent) ?></div>
      <div class="text-[11px] text-indigo-100 uppercase tracking-wide">Labor cost</div>
    </div>
  </div>
</div>

<!-- Vehicle detail card -->
<div class="sh-card p-5 mb-6">
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-xl bg-blue-100 text-blue-700 inline-flex items-center justify-center"><i class="fa-solid fa-user"></i></span>
      <div><div class="text-[11px] text-gray-400 uppercase tracking-wide">Owner</div>
        <div class="font-semibold text-gray-800"><?= e($vehicle['customer']) ?></div>
        <div class="text-xs text-gray-500"><?= e($vehicle['phone']) ?></div></div>
    </div>
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-700 inline-flex items-center justify-center"><i class="fa-solid fa-car-side"></i></span>
      <div><div class="text-[11px] text-gray-400 uppercase tracking-wide">Vehicle</div>
        <div class="font-semibold text-gray-800"><?= e($vehicle['make']) ?> <?= e($vehicle['model']) ?></div>
        <div class="text-xs text-gray-500"><?= e($vehicle['year']) ?></div></div>
    </div>
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-xl bg-emerald-100 text-emerald-700 inline-flex items-center justify-center"><i class="fa-solid fa-gauge-high"></i></span>
      <div><div class="text-[11px] text-gray-400 uppercase tracking-wide">Mileage</div>
        <div class="font-semibold text-gray-800"><?= number_format((int)$vehicle['mileage']) ?> km</div></div>
    </div>
    <div class="flex items-center gap-3">
      <span class="w-10 h-10 rounded-xl bg-amber-100 text-amber-700 inline-flex items-center justify-center"><i class="fa-solid fa-phone"></i></span>
      <div><div class="text-[11px] text-gray-400 uppercase tracking-wide">Contact</div>
        <div class="font-semibold text-gray-800"><?= e($vehicle['phone']) ?></div></div>
    </div>
  </div>
</div>

<!-- Services table -->
<div class="sh-card overflow-hidden">
  <div class="px-5 py-4 border-b flex items-center justify-between">
    <h2 class="font-bold text-gray-800 flex items-center gap-2">
      <span class="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-700 inline-flex items-center justify-center"><i class="fa-solid fa-screwdriver-wrench"></i></span>
      Service Records
    </h2>
    <span class="text-xs text-gray-400"><?= count($services) ?> record(s) &middot; newest first</span>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-gray-500 border-b bg-gray-50/80">
          <th class="py-3 px-5 font-semibold uppercase text-[11px] tracking-wider">Date</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Mileage</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Description</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Labor</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Status</th>
          <th class="py-3 px-3 font-semibold uppercase text-[11px] tracking-wider">Next Service</th>
          <th class="py-3 px-5 font-semibold uppercase text-[11px] tracking-wider text-right">Invoice</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($services as $s): ?>
        <tr class="sh-row border-b last:border-0 align-top">
          <td class="py-3 px-5 whitespace-nowrap">
            <div class="font-semibold text-gray-800"><i class="fa-regular fa-calendar mr-1 text-gray-400"></i><?= e($s['service_date']) ?></div>
          </td>
          <td class="py-3 px-3 text-gray-600"><?= number_format((int)$s['mileage']) ?> km</td>
          <td class="py-3 px-3 max-w-xs">
            <div class="text-gray-700"><?= nl2br(e($s['description'])) ?></div>
            <?php if ($s['notes']): ?>
            <div class="mt-1.5 text-xs text-gray-500 bg-gray-50 border-l-2 border-amber-300 rounded-r px-2 py-1 italic">
              <i class="fa-regular fa-note-sticky mr-1"></i><?= e($s['notes']) ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="py-3 px-3 font-semibold text-gray-800"><?= money($s['labor_charge']) ?></td>
          <td class="py-3 px-3">
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?= $statusStyles[$s['status']] ?? 'bg-gray-100 text-gray-600' ?>">
              <i class="fa-solid <?= $s['status']==='Completed' ? 'fa-check' : ($s['status']==='In Progress' ? 'fa-spinner' : 'fa-clock') ?> mr-1"></i><?= e($s['status']) ?>
            </span>
          </td>
          <td class="py-3 px-3 text-xs text-gray-600">
            <?php if ($s['next_service_date']): ?><div><i class="fa-regular fa-calendar-check mr-1 text-gray-400"></i><?= e($s['next_service_date']) ?></div><?php endif; ?>
            <?php if ($s['next_service_mileage']): ?><div><i class="fa-solid fa-gauge mr-1 text-gray-400"></i><?= number_format((int)$s['next_service_mileage']) ?> km</div><?php endif; ?>
            <?= (!$s['next_service_date'] && !$s['next_service_mileage']) ? '<span class="text-gray-300">-</span>' : '' ?>
          </td>
          <td class="py-3 px-5 text-right whitespace-nowrap">
            <?php if ($s['has_invoice']): ?>
              <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-600">
                <i class="fa-solid fa-file-invoice"></i> Created
              </span>
            <?php else: ?>
              <a class="sh-iconbtn text-blue-600 bg-blue-50 hover:bg-blue-100" title="Create invoice" href="invoice_form.php?service_id=<?= $s['id'] ?>">
                <i class="fa-solid fa-file-invoice-dollar"></i>
              </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$services): ?>
        <tr><td colspan="7" class="py-10 text-center">
          <i class="fa-solid fa-screwdriver-wrench text-3xl text-gray-200 mb-2"></i>
          <div class="text-gray-400">No service records for this vehicle yet.</div>
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="px-5 py-4 border-t bg-gray-50/60 flex flex-wrap gap-3">
    <a href="services.php?vehicle_id=<?= $vid ?>" class="sh-action bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white shadow-md">
      <i class="fa-solid fa-plus"></i> New Service Job
    </a>
    <a href="vehicle_history_print.php?vehicle_id=<?= $vid ?>" target="_blank" class="sh-action bg-gray-800 hover:bg-gray-900 text-white">
      <i class="fa-solid fa-file-pdf"></i> Download PDF
    </a>
    <a href="vehicles.php" class="sh-action bg-gray-100 hover:bg-gray-200 text-gray-700">
      <i class="fa-solid fa-arrow-left"></i> Back to Vehicles
    </a>
  </div>
</div>

<?php page_footer(); ?>