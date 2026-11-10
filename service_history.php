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
page_header('Service History - ' . $vehicle['reg_no']);
?>
<div class="bg-white rounded-2xl shadow p-5 mb-6 text-sm grid md:grid-cols-3 gap-3">
  <div><span class="text-gray-500">Owner:</span> <strong><?= e($vehicle['customer']) ?></strong> (<?= e($vehicle['phone']) ?>)</div>
  <div><span class="text-gray-500">Vehicle:</span> <strong><?= e($vehicle['make']) ?> <?= e($vehicle['model']) ?> <?= e($vehicle['year']) ?></strong></div>
  <div><span class="text-gray-500">Mileage:</span> <strong><?= number_format((int)$vehicle['mileage']) ?> km</strong></div>
</div>
<div class="bg-white rounded-2xl shadow p-5">
  <table class="w-full text-sm">
    <thead><tr class="text-left text-gray-500 border-b">
      <th class="py-2">Date</th><th>Mileage</th><th>Description</th><th>Labor</th><th>Status</th><th>Next Service</th><th class="text-right">Invoice</th>
    </tr></thead>
    <tbody>
    <?php foreach ($services as $s): ?>
      <tr class="border-b hover:bg-gray-50 align-top">
        <td class="py-2"><?= e($s['service_date']) ?></td>
        <td><?= number_format((int)$s['mileage']) ?> km</td>
        <td class="max-w-xs"><?= nl2br(e($s['description'])) ?><?= $s['notes'] ? '<div class="text-gray-400 text-xs mt-1">Note: ' . e($s['notes']) . '</div>' : '' ?></td>
        <td><?= money($s['labor_charge']) ?></td>
        <td><span class="px-2 py-0.5 rounded-full text-xs <?= $s['status']==='Completed'?'bg-green-100 text-green-700':($s['status']==='In Progress'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-600') ?>"><?= $s['status'] ?></span></td>
        <td class="text-xs"><?= $s['next_service_date'] ? e($s['next_service_date']) : '-' ?><?= $s['next_service_mileage'] ? '<br>' . number_format((int)$s['next_service_mileage']) . ' km' : '' ?></td>
        <td class="text-right"><?= $s['has_invoice'] ? '<span class="text-gray-400 text-xs">Created</span>' : '<a class="text-blue-600 hover:underline" href="invoice_form.php?service_id=' . $s['id'] . '">Create</a>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$services): ?><tr><td colspan="7" class="py-6 text-center text-gray-400">No service records for this vehicle.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <a href="services.php?vehicle_id=<?= $vid ?>" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-2 rounded-lg"><i class="fa-solid fa-plus mr-1"></i> New Service Job</a>
  <a href="vehicle_history_print.php?vehicle_id=<?= $vid ?>" target="_blank" class="inline-block bg-gray-700 hover:bg-gray-800 text-white text-sm font-medium px-4 py-2 rounded-lg">
  <i class="fa-solid fa-file-pdf mr-1"></i> Download PDF
</a>
</div>
<?php page_footer(); ?>
